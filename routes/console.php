<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Schedule;

/*
| Восстановление ведётся от ИНБОКСА, а не от статуса заказа: заказ, застрявший
| из-за потерянной задачи, не виден ни одному статусному фильтру, а событию
| с applied_at IS NULL — виден.
|
| withoutOverlapping обязателен: два одновременных прохода поставили бы каждое
| событие дважды. Джобы идемпотентны, но лишняя работа под нагрузкой не нужна.
*/
Schedule::command('payments:drain-unapplied')
    ->everyMinute()
    ->withoutOverlapping();

/*
| Судьба обращения, закончившегося неизвестностью, выясняется только повтором
| probe по ТОМУ ЖЕ request_id. Новый идентификатор здесь не создаётся никогда —
| он означал бы покупку второго кода.
*/
Schedule::command('delivery:resolve-unknown')
    ->everyMinute()
    ->withoutOverlapping();
