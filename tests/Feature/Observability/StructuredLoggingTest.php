<?php

declare(strict_types=1);

namespace Tests\Feature\Observability;

use App\Domain\Delivery\Actions\DeliverOrder;
use App\Domain\Payments\Actions\ApplyPaymentEvent;
use App\Jobs\ApplyPaymentEventJob;
use App\Models\Order;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Monolog\Formatter\JsonFormatter;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Tests\Support\OrderFixtures;
use Tests\TestCase;

/**
 * Логи платёжного и выдачного пути.
 *
 * Главная проверка — не то, что события пишутся, а то, что в них НЕ попадает
 * выданный код. Это правило записано в CLAUDE.md §7 со словами «проверяется
 * тестом, а не дисциплиной», и без теста оно было бы просто обещанием.
 *
 * Код ключа в логе — это утечка товара: логи читают, пересылают, складывают
 * в системы сбора, и права доступа там совсем не те, что у базы.
 */
final class StructuredLoggingTest extends TestCase
{
    use OrderFixtures;
    use RefreshDatabase;

    /** @var list<array{message: string, context: array<string, mixed>}> */
    private array $captured = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
        Queue::fake();

        $this->captured = [];

        Log::listen(function (MessageLogged $event): void {
            $this->captured[] = ['message' => $event->message, 'context' => $event->context];
        });
    }

    #[Test]
    public function the_issued_code_never_appears_in_any_log_record(): void
    {
        $order = $this->paidOrder();
        app(DeliverOrder::class)->execute($order->public_id);

        $order->refresh()->load('delivery');
        $code = $order->delivery?->code_encrypted;

        self::assertNotNull($code, 'Заказ не выдан — проверять нечего.');
        self::assertNotSame([], $this->captured, 'Не записано ни одного события.');

        $dump = json_encode($this->captured, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);

        self::assertStringNotContainsString($code, $dump, 'Код ключа попал в лог.');

        // Последние четыре символа — можно: этого хватает поддержке для
        // сверки с клиентом и недостаточно для использования кода.
        $last4 = mb_substr($code, -4);
        self::assertStringContainsString($last4, $dump, 'В логе нет даже хвоста кода — искать заказ будет нечем.');
    }

    #[Test]
    public function every_record_carries_the_fields_needed_to_follow_an_order(): void
    {
        $order = $this->paidOrder();
        app(DeliverOrder::class)->execute($order->public_id);

        foreach ($this->captured as $record) {
            self::assertArrayHasKey('trace_id', $record['context'], "Событие {$record['message']} без trace_id.");
            self::assertArrayHasKey('event', $record['context'], "Событие {$record['message']} без имени события.");
        }
    }

    #[Test]
    public function the_whole_path_of_one_order_shares_a_single_trace_id(): void
    {
        $order = $this->paidOrder();
        app(DeliverOrder::class)->execute($order->public_id);

        /** @var list<string> $traces */
        $traces = [];

        foreach ($this->captured as $record) {
            $value = $record['context']['trace_id'] ?? null;

            if (is_string($value)) {
                $traces[] = $value;
            }
        }

        // Один идентификатор на весь путь — иначе по логам нельзя восстановить
        // историю заказа, ради чего логи и пишутся.
        self::assertSame(1, count(array_unique($traces)), 'Путь заказа разъехался по разным trace_id.');
    }

    #[Test]
    public function the_key_events_of_the_money_path_are_recorded(): void
    {
        $order = $this->paidOrder();
        app(DeliverOrder::class)->execute($order->public_id);

        $events = array_map(static fn (array $r): string => $r['message'], $this->captured);

        // Задание требует логов ПО ПЛАТЕЖАМ И ВЫДАЧЕ — значит по каждому
        // должен быть след, а не только по ошибкам.
        foreach (['webhook_received', 'payment_applied', 'order_delivered'] as $expected) {
            self::assertContains($expected, $events, "В логе нет события {$expected}.");
        }
    }

    #[Test]
    public function a_failure_reason_goes_into_reason_and_never_into_the_order_field(): void
    {
        // Регрессия. Текст исключения передавался третьим аргументом, а третий
        // аргумент — это order_id. Поиск по order_id — основной способ поднять
        // историю заказа, и такая запись ломала его дважды: упавшее событие
        // не находилось по своему заказу, а в поле заказа лежал текст ошибки.
        $order = $this->paidOrder();

        (new ApplyPaymentEventJob('evt_log_'.$order->public_id))
            ->failed(new RuntimeException('поставщик недоступен'));

        $record = null;

        foreach ($this->captured as $candidate) {
            if ($candidate['message'] === 'payment_apply_failed') {
                $record = $candidate;
            }
        }

        self::assertNotNull($record, 'Падение задачи не оставило следа в логе.');
        self::assertSame('поставщик недоступен', $record['context']['reason'] ?? null);
        self::assertArrayNotHasKey('order_id', $record['context']);
    }

    #[Test]
    public function the_default_log_channel_is_machine_readable(): void
    {
        // Текстовая строка с JSON на хвосте структурированной не является:
        // по ней нельзя отфильтровать по полю, не написав парсер.
        self::assertSame(
            JsonFormatter::class,
            config()->string('logging.channels.json.formatter'),
        );
    }

    private function paidOrder(string $sku = 'KEY-CS2-PRIME'): Order
    {
        $order = $this->makeOrder($sku);
        $eventId = 'evt_log_'.$order->public_id;

        $this->postWebhook($this->webhookPayload($order, ['event_id' => $eventId]))->assertOk();
        app(ApplyPaymentEvent::class)->execute($eventId);

        return $order->refresh();
    }
}
