<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Domain\Ledger\Enums\LedgerAccount;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * План счетов. Счета — справочник, а не пользовательские данные, поэтому они
 * создаются сидером и живут вместе со схемой.
 *
 * Валюта одна (RUB): по условию задания переключатель валют в макете — только
 * отображение, пересчёт делать не нужно. Но счёт всё равно заведён в разрезе
 * валюты, иначе при появлении второй валюты сальдо схлопнулись бы в одно число.
 */
final class LedgerAccountSeeder extends Seeder
{
    private const CURRENCY = 'RUB';

    public function run(): void
    {
        foreach (LedgerAccount::cases() as $account) {
            DB::table('ledger_accounts')->updateOrInsert(
                ['code' => $account->value, 'currency' => self::CURRENCY],
                ['kind' => $account->kind()->value],
            );
        }
    }
}
