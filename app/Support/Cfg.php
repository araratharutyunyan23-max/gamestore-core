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

    public static function supplierConnectTimeout(): float
    {
        return config()->float('suppliers.connect_timeout');
    }

    public static function supplierIssueTimeout(): float
    {
        return config()->float('suppliers.issue_timeout');
    }

    /**
     * Раньше этого срока probe спрашивать бессмысленно: он обгонит живую
     * обработку и ответит «не знаю такого» за миллисекунды до выдачи кода.
     */
    public static function supplierMaxProcessing(): float
    {
        return config()->float('suppliers.max_processing');
    }

    public static function supplierRetries(): int
    {
        return config()->integer('suppliers.retries');
    }

    public static function supplierRetryBaseMs(): int
    {
        return config()->integer('suppliers.retry_base_ms');
    }

    public static function allowCompensatedFallback(): bool
    {
        return config()->boolean('suppliers.allow_compensated_fallback');
    }

    public static function supplierUrl(string $name): string
    {
        return config()->string('suppliers.'.strtolower($name).'.url');
    }
}
