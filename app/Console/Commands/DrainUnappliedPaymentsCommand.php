<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Payments\Actions\DrainUnappliedPayments;
use Illuminate\Console\Command;

final class DrainUnappliedPaymentsCommand extends Command
{
    protected $signature = 'payments:drain-unapplied';

    protected $description = 'Переставить в очередь платёжные события, которые остались неприменёнными';

    public function handle(DrainUnappliedPayments $action): int
    {
        $count = $action->execute();

        $this->components->info($count === 0
            ? 'Неприменённых событий нет.'
            : "Переставлено в очередь: {$count}.");

        return self::SUCCESS;
    }
}
