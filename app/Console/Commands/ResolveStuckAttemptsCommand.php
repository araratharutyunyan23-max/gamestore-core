<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Delivery\Actions\ResolveStuckAttempts;
use Illuminate\Console\Command;

final class ResolveStuckAttemptsCommand extends Command
{
    protected $signature = 'delivery:resolve-unknown';

    protected $description = 'Выяснить судьбу обращений к поставщику, застрявших в неизвестности';

    public function handle(ResolveStuckAttempts $action): int
    {
        $count = $action->execute();

        $this->components->info($count === 0
            ? 'Неразрешённых обращений нет.'
            : "Разрешено обращений: {$count}.");

        return self::SUCCESS;
    }
}
