<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Http\Client\Pool;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/**
 * Эмулятор платёжной системы.
 *
 * Тот же инструмент используется и для демонстрации, и для проверки гонок —
 * как требует задание. Отправка идёт настоящим HTTP к работающему приложению,
 * а не вызовом сервиса: гонка, которой нет на уровне HTTP, не считается
 * доказанной.
 *
 * Параллельность — через Http::pool. Под ним тот же curl_multi, но без
 * ручного boilerplate: все запросы стартуют до того, как начинается ожидание
 * ответов, поэтому они действительно уходят одновременно.
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
        $count = max(1, (int) ($this->stringOption('count') ?? '1'));

        $amount = $this->stringOption('amount');
        $amountValue = $amount !== null ? (int) $amount : $this->resolveAmount($base, $order);

        $payloads = $this->buildPayloads($order, $amountValue, $count);
        $url = $base.'/api/v1/webhooks/payment';

        $statuses = $this->option('parallel') === true
            ? $this->sendParallel($url, $payloads)
            : $this->sendSequential($url, $payloads);

        $summary = array_count_values($statuses);
        ksort($summary);

        foreach ($summary as $status => $times) {
            $this->line(sprintf('  HTTP %s × %d', $status, $times));
        }

        // Любой ответ, кроме 200, означал бы повторную доставку со стороны
        // платёжной системы — то есть новую волну конкурентов.
        return array_keys($summary) === [200] ? self::SUCCESS : self::FAILURE;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function buildPayloads(string $order, int $amount, int $count): array
    {
        $sharedEventId = $this->eventId();
        $payloads = [];

        for ($i = 0; $i < $count; $i++) {
            $payloads[] = [
                'event_id' => $this->option('same-event-id') === true ? $sharedEventId : $this->eventId(),
                'order_id' => $order,
                'status' => $this->stringOption('status') ?? 'paid',
                'amount' => $amount,
                'currency' => 'RUB',
                'created_at' => now()->toIso8601String(),
            ];
        }

        return $payloads;
    }

    /**
     * @param  list<array<string, mixed>>  $payloads
     * @return list<int>
     */
    private function sendSequential(string $url, array $payloads): array
    {
        return array_map(
            static fn (array $payload): int => Http::acceptJson()->post($url, $payload)->status(),
            $payloads,
        );
    }

    /**
     * @param  list<array<string, mixed>>  $payloads
     * @return list<int>
     */
    private function sendParallel(string $url, array $payloads): array
    {
        /** @var array<int, Response> $responses */
        $responses = Http::pool(static fn (Pool $pool): array => array_map(
            static fn (array $payload) => $pool->acceptJson()->post($url, $payload),
            $payloads,
        ));

        return array_map(
            static fn (Response $response): int => $response->status(),
            array_values($responses),
        );
    }

    private function resolveAmount(string $base, string $order): int
    {
        $response = Http::acceptJson()->get($base.'/api/v1/orders/'.$order);
        $minor = $response->json('data.amount_minor');

        if (! is_int($minor)) {
            $this->error("Не удалось получить сумму заказа {$order}: HTTP {$response->status()}");

            return 0;
        }

        // Контракт вебхука оперирует мажорными единицами (CLAUDE.md §10.2).
        return intdiv($minor, 100);
    }

    private function eventId(): string
    {
        return 'evt_'.Str::lower((string) Str::ulid());
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
