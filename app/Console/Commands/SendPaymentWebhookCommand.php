<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Эмулятор платёжной системы.
 *
 * Тот же самый инструмент используется и для демонстрации, и для проверки
 * гонок — как и требует задание. Отправка идёт настоящим HTTP к работающему
 * приложению, а не вызовом сервиса: гонку, которой нет на уровне HTTP, нельзя
 * считать доказанной.
 *
 * Параллельность сделана через curl_multi, потому что расширения pcntl
 * в стандартном php:8.3-cli нет, а поднимать 50 процессов PHP ради 50
 * HTTP-запросов — это измерять скорость бутстрапа Laravel, а не поведение
 * системы под гонкой.
 */
final class SendPaymentWebhookCommand extends Command
{
    protected $signature = 'shop:webhook
        {order : Внешний идентификатор заказа, например ord_00001}
        {--status=paid : paid или failed}
        {--amount= : Сумма в мажорных единицах; по умолчанию берётся из заказа}
        {--count=1 : Сколько раз отправить событие}
        {--same-event-id : Слать один и тот же event_id (честный повтор at-least-once)}
        {--parallel : Отправить все запросы одновременно}
        {--url= : Базовый URL приложения}';

    protected $description = 'Отправить вебхук оплаты по контракту задания (он же инструмент проверки гонок)';

    public function handle(): int
    {
        $order = $this->stringArgument('order');
        $base = rtrim($this->stringOption('url') ?? config()->string('app.url'), '/');
        $count = max(1, (int) $this->stringOption('count'));

        $amount = $this->stringOption('amount');
        $amountValue = $amount !== null ? (int) $amount : $this->resolveAmount($base, $order);

        $sharedEventId = 'evt_'.Str::lower((string) Str::ulid());

        $payloads = [];

        for ($i = 0; $i < $count; $i++) {
            $payloads[] = json_encode([
                'event_id' => $this->option('same-event-id') === true
                    ? $sharedEventId
                    : 'evt_'.Str::lower((string) Str::ulid()),
                'order_id' => $order,
                'status' => $this->stringOption('status') ?? 'paid',
                'amount' => $amountValue,
                'currency' => 'RUB',
                'created_at' => now()->toIso8601String(),
            ], JSON_THROW_ON_ERROR);
        }

        $url = $base.'/api/v1/webhooks/payment';
        $codes = $this->option('parallel') === true
            ? $this->sendParallel($url, $payloads)
            : $this->sendSequential($url, $payloads);

        $summary = array_count_values($codes);
        ksort($summary);

        foreach ($summary as $code => $times) {
            $this->line(sprintf('  HTTP %s × %d', $code, $times));
        }

        // Любой ответ, кроме 200, означал бы повторную доставку со стороны
        // платёжной системы — то есть новую волну конкурентов.
        return array_keys($summary) === [200] ? self::SUCCESS : self::FAILURE;
    }

    /**
     * @param  list<string>  $payloads
     * @return list<int>
     */
    private function sendSequential(string $url, array $payloads): array
    {
        $codes = [];

        foreach ($payloads as $payload) {
            $handle = $this->makeHandle($url, $payload);
            curl_exec($handle);
            $codes[] = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
            curl_close($handle);
        }

        return $codes;
    }

    /**
     * @param  list<string>  $payloads
     * @return list<int>
     */
    private function sendParallel(string $url, array $payloads): array
    {
        $multi = curl_multi_init();
        $handles = [];

        foreach ($payloads as $payload) {
            $handle = $this->makeHandle($url, $payload);
            curl_multi_add_handle($multi, $handle);
            $handles[] = $handle;
        }

        do {
            $status = curl_multi_exec($multi, $running);

            if ($running > 0) {
                curl_multi_select($multi, 1.0);
            }
        } while ($running > 0 && $status === CURLM_OK);

        $codes = [];

        foreach ($handles as $handle) {
            $codes[] = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
            curl_multi_remove_handle($multi, $handle);
            curl_close($handle);
        }

        curl_multi_close($multi);

        return $codes;
    }

    private function makeHandle(string $url, string $payload): \CurlHandle
    {
        $handle = curl_init($url);

        if ($handle === false) {
            throw new RuntimeException('Не удалось инициализировать curl');
        }

        curl_setopt_array($handle, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $payload,
            CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'Accept: application/json'],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 30,
        ]);

        return $handle;
    }

    private function resolveAmount(string $base, string $order): int
    {
        $handle = curl_init($base.'/api/v1/orders/'.$order);

        if ($handle === false) {
            throw new RuntimeException('Не удалось инициализировать curl');
        }

        curl_setopt_array($handle, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => ['Accept: application/json'],
            CURLOPT_TIMEOUT => 10,
        ]);

        $body = curl_exec($handle);
        curl_close($handle);

        if (! is_string($body)) {
            throw new RuntimeException("Заказ {$order} недоступен по HTTP");
        }

        /** @var array{data?: array{amount_minor?: int}} $decoded */
        $decoded = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
        $minor = $decoded['data']['amount_minor'] ?? null;

        if (! is_int($minor)) {
            throw new RuntimeException("В ответе по заказу {$order} нет суммы");
        }

        // Контракт вебхука оперирует мажорными единицами (см. CLAUDE.md §10.2).
        return intdiv($minor, 100);
    }

    private function stringArgument(string $name): string
    {
        $value = $this->argument($name);

        return is_string($value) ? $value : '';
    }

    private function stringOption(string $name): ?string
    {
        $value = $this->option($name);

        return is_string($value) && $value !== '' ? $value : null;
    }
}
