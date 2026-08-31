<?php

declare(strict_types=1);

return [
    /*
     * Таймауты разделены намеренно. Неудача на фазе соединения ДОКАЗЫВАЕТ,
     * что запрос не ушёл, и разрешает уход ко второму поставщику. Таймаут
     * чтения не доказывает ничего: код мог быть выдан.
     */
    'connect_timeout' => (float) env('SUPPLIER_CONNECT_TIMEOUT', 1.0),
    'issue_timeout' => (float) env('SUPPLIER_ISSUE_TIMEOUT', 3.0),

    /*
     * Сколько поставщику нужно, чтобы доработать уже принятый запрос.
     * Раньше этого срока probe спрашивать бессмысленно: он обгонит живую
     * обработку и ответит «не знаю такого» за миллисекунды до выдачи кода.
     */
    'max_processing' => (float) env('SUPPLIER_MAX_PROCESSING', 5.0),

    'retries' => (int) env('SUPPLIER_RETRIES', 2),
    'retry_base_ms' => (int) env('SUPPLIER_RETRY_BASE_MS', 200),

    /*
     * Единственный механизм, способный породить второй код: уход ко второму
     * поставщику из НЕРАЗРЕШЁННОЙ неизвестности. По умолчанию выключен, и ни
     * один критерий приёмки его не требует — успешная печать даёт и
     * безопасность, и продвижение вперёд.
     */
    'allow_compensated_fallback' => (bool) env('SUPPLIER_ALLOW_COMPENSATED_FALLBACK', false),

    'a' => ['url' => env('SUPPLIER_A_URL', 'http://supplier-a:8080')],
    'b' => ['url' => env('SUPPLIER_B_URL', 'http://supplier-b:8080')],
];
