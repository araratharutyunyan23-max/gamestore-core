<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Ordering\Actions\SettleAbandonedItems;
use Illuminate\Console\Command;

final class SettleAbandonedItemsCommand extends Command
{
    protected $signature = 'orders:settle-abandoned';

    protected $description = 'Вернуть деньги за позиции, которые выдать не удалось, и закрыть заказы';

    public function handle(SettleAbandonedItems $action): int
    {
        $count = $action->execute();

        $this->components->info($count === 0
            ? 'Позиций к возврату нет.'
            : "Закрыто возвратом позиций: {$count}.");

        return self::SUCCESS;
    }
}
