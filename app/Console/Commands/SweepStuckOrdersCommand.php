<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Delivery\Actions\SweepStuckOrders;
use Illuminate\Console\Command;

final class SweepStuckOrdersCommand extends Command
{
    protected $signature = 'orders:sweep-stuck';

    protected $description = 'Поставить на повторную выдачу заказы, застрявшие в ожидании';

    public function handle(SweepStuckOrders $action): int
    {
        $count = $action->execute();

        $this->components->info($count === 0
            ? 'Зависших заказов нет.'
            : "Отправлено на повторную выдачу: {$count}.");

        return self::SUCCESS;
    }
}
