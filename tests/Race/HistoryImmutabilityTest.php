<?php

declare(strict_types=1);

namespace Tests\Race;

use App\Domain\Delivery\Actions\DeliverOrder;
use App\Domain\Payments\Actions\ApplyPaymentEvent;
use App\Models\Order;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\OrderFixtures;
use Tests\TestCase;
use Throwable;

/**
 * ТЗ 4.2: «история только дополняется, задним числом ничего не переписывается».
 *
 * Проверяется, что это ЗАПРЕТ, а не соглашение. Восстановление состояния на
 * момент собирается из журналов переходов, и правка одной строки в них меняет
 * прошлое — молча, потому что проверить его больше нечем.
 *
 * Долг найден при планировании второго этапа: план сначала утверждал, что
 * append-only уже есть, а триггер неизменяемости висел только на журнале
 * проводок. Здесь долг проверяется закрытым.
 */
final class HistoryImmutabilityTest extends TestCase
{
    use DatabaseTruncation;
    use OrderFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
    }

    #[Test]
    public function a_recorded_order_transition_cannot_be_rewritten(): void
    {
        $order = $this->livedOrder();

        $failure = $this->attempt(static function () use ($order): void {
            DB::table('order_status_transitions')
                ->where('order_id', $order->id)
                ->limit(1)
                ->update(['to_status' => 'cancelled']);
        });

        self::assertNotNull($failure, 'Историю заказа удалось переписать.');
        self::assertStringContainsString('append-only', $failure->getMessage());
    }

    #[Test]
    public function a_recorded_order_transition_cannot_be_deleted(): void
    {
        // Удаление опаснее правки: переписанный статус хотя бы виден,
        // а исчезнувший переход делает прошлое НЕПОЛНЫМ, и заметить это
        // по самой истории невозможно.
        $order = $this->livedOrder();

        $failure = $this->attempt(static function () use ($order): void {
            DB::table('order_status_transitions')->where('order_id', $order->id)->delete();
        });

        self::assertNotNull($failure, 'Переход удалось стереть из истории.');
        self::assertStringContainsString('append-only', $failure->getMessage());
    }

    #[Test]
    public function the_item_journal_is_protected_too(): void
    {
        // Со второго этапа состояние заказа выводится ИЗ ПОЗИЦИЙ, поэтому
        // защищать только заказный журнал бессмысленно: правда о том, что
        // происходило, живёт в позиционном.
        $order = $this->livedOrder();

        $failure = $this->attempt(static function () use ($order): void {
            DB::table('order_item_status_transitions')
                ->where('order_id', $order->id)
                ->limit(1)
                ->update(['to_status' => 'refunded']);
        });

        self::assertNotNull($failure, 'Историю позиций удалось переписать.');
        self::assertStringContainsString('append-only', $failure->getMessage());
    }

    #[Test]
    public function history_can_still_be_appended(): void
    {
        // Обратная сторона: запрет обязан мешать только правке. Если он мешает
        // и записи, система просто перестаёт работать, а тест этого не заметит.
        $order = $this->livedOrder();

        $before = DB::table('order_status_transitions')->where('order_id', $order->id)->count();

        self::assertGreaterThan(0, $before, 'Переходы не записались вовсе — проверять нечего.');

        app(DeliverOrder::class)->execute($order->public_id);

        self::assertGreaterThanOrEqual(
            $before,
            DB::table('order_status_transitions')->where('order_id', $order->id)->count(),
        );
    }

    private function attempt(callable $work): ?Throwable
    {
        try {
            $work();

            return null;
        } catch (Throwable $e) {
            return $e;
        }
    }

    /**
     * Заказ, у которого уже есть история: создан, оплачен, выдан.
     */
    private function livedOrder(): Order
    {
        $order = $this->makeOrder();
        $eventId = 'evt_history_'.$order->public_id;

        $this->postWebhook($this->webhookPayload($order, ['event_id' => $eventId]))->assertOk();
        app(ApplyPaymentEvent::class)->execute($eventId);
        app(DeliverOrder::class)->execute($order->public_id);

        return $order->refresh();
    }
}
