<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Delivery\Actions\DrainDeliveryQueue;
use Illuminate\Console\Command;

final class DrainDeliveryQueueCommand extends Command
{
    protected $signature = 'delivery:drain-queue';

    protected $description = 'Обслужить очередь выдачи: заказы, дождавшиеся своей квоты у поставщика';

    public function handle(DrainDeliveryQueue $action): int
    {
        $count = $action->execute();

        $this->components->info($count === 0
            ? 'Очередь пуста.'
            : "Отправлено на выдачу: {$count}.");

        return self::SUCCESS;
    }
}
