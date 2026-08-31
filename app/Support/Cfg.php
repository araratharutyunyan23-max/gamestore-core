<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Типизированный доступ к настройкам.
 *
 * config() возвращает mixed, и без сужения он расползается по домену.
 * Один класс вместо десятка приведений типа на месте использования, и
 * заодно единственное место, где перечислены магические числа проекта.
 */
final class Cfg
{
    /**
     * Срок аренды на выдачу. Обязан быть заметно больше бюджета одной попытки
     * выдачи, иначе аренда протухнет прямо во время работы и заказ подхватит
     * второй воркер.
     */
    public static function leaseSeconds(): int
    {
        return config()->integer('delivery.lease_seconds');
    }

    /** Сколько заказ считается «зависшим» до вмешательства восстановления. */
    public static function stuckAfterMinutes(): int
    {
        return config()->integer('delivery.stuck_after_minutes');
    }

    /** Возраст неприменённого события, после которого его переставляет доводка. */
    public static function drainAfterSeconds(): int
    {
        return config()->integer('payments.drain_after_seconds');
    }

    /** Размер пакета для фоновых проходов: без него доводка читает всю таблицу. */
    public static function drainBatchSize(): int
    {
        return config()->integer('payments.drain_batch_size');
    }
}
