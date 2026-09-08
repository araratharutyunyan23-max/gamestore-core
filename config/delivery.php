<?php

declare(strict_types=1);

return [
    'lease_seconds' => (int) env('DELIVERY_LEASE_SECONDS', 120),
    'stuck_after_minutes' => (int) env('DELIVERY_STUCK_AFTER_MINUTES', 15),

    // Сколько система терпит позицию в тупике, прежде чем вернуть за неё деньги.
    //
    // Заметно больше окна повторов: возврат необратим, и торопиться с ним
    // нельзя — товар мог появиться на складе через минуту после отказа.
    // Но и тянуть бесконечно нельзя: заказ обязан завершиться, а деньги
    // за невыданное — вернуться (ТЗ 1.2).
    'refund_after_minutes' => (int) env('DELIVERY_REFUND_AFTER_MINUTES', 60),
];
