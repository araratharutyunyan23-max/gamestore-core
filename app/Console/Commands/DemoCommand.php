<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Ordering\Repositories\OrderRepository;
use App\Models\Order;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Сквозной сценарий: заказ → вебхук оплаты → выдача кода.
 *
 * Всё идёт настоящим HTTP к работающему приложению и через настоящую очередь.
 * Демонстрация, обходящая HTTP и очередь, не показала бы главного — что путь
 * асинхронный и что клиент получает 200 задолго до выдачи.
 */
final class DemoCommand extends Command
{
    protected $signature = 'shop:demo
        {--sku=KEY-CS2-PRIME : SKU из каталога задания}
        {--url= : Базовый URL приложения}';

    protected $description = 'Сквозной сценарий: создать заказ, оплатить вебхуком, дождаться выдачи кода';

    private const POLL_INTERVAL_MICROSECONDS = 250_000;

    public function handle(OrderRepository $orders): int
    {
        $base = rtrim($this->stringOption('url') ?? config()->string('app.url'), '/');
        $sku = $this->stringOption('sku') ?? 'KEY-CS2-PRIME';

        $this->components->info("1. Создаём заказ по SKU {$sku}");
        $publicId = $this->createOrder($base, $sku);
        $this->line("   заказ: <options=bold>{$publicId}</> — статус created");

        $this->components->info('2. Платёжная система шлёт вебхук paid');
        $this->call('shop:webhook', ['order' => $publicId, '--url' => $base]);

        $this->components->info('3. Ждём асинхронную выдачу');
        $order = $this->waitForFinalState($orders, $publicId);

        if ($order === null) {
            $this->components->error('Заказ не дошёл до финального состояния за отведённое время.');
            $this->line('   Проверьте, что воркеры подняты: docker compose ps worker-payments worker-delivery');

            return self::FAILURE;
        }

        $this->newLine();
        $this->components->twoColumnDetail('Статус заказа', $order->status->value);
        $this->components->twoColumnDetail('Состояние оплаты', $order->paymentState?->state->value ?? '—');
        $this->components->twoColumnDetail(
            'Выданный код',
            $order->delivery === null ? '— (нет выдачи)' : $order->delivery->code_encrypted,
        );

        return $order->delivery !== null ? self::SUCCESS : self::FAILURE;
    }

    private function createOrder(string $base, string $sku): string
    {
        $response = Http::acceptJson()
            ->withHeaders([
                // Заголовок обязателен: без него API отвечает 422.
                'Idempotency-Key' => 'demo-'.Str::lower((string) Str::ulid()),
            ])
            ->post($base.'/api/v1/orders', ['sku' => $sku]);

        $publicId = $response->json('data.id');

        if (! $response->created() || ! is_string($publicId)) {
            throw new RuntimeException("Создание заказа вернуло HTTP {$response->status()}");
        }

        return $publicId;
    }

    private function waitForFinalState(OrderRepository $orders, string $publicId): ?Order
    {
        for ($attempt = 0; $attempt < 40; $attempt++) {
            $order = $orders->findByPublicId($publicId);

            if ($order !== null && ($order->delivery !== null || $order->status->isFinal())) {
                return $order;
            }

            Sleep::usleep(self::POLL_INTERVAL_MICROSECONDS);
        }

        return null;
    }

    private function stringOption(string $name): ?string
    {
        $value = $this->option($name);

        return is_string($value) && $value !== '' ? $value : null;
    }
}
