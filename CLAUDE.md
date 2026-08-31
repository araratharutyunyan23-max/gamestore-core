# gamestore-core — инженерный стандарт проекта

Ядро магазина цифровых товаров: заказы, платёжные вебхуки, автовыдача кодов через
поставщиков-заглушек, сверка и денежный журнал.

Этот файл — **обязательные правила**, а не пожелания. Нарушение любого правила = дефект,
даже если тесты зелёные. Если правило мешает — правило меняется здесь, в отдельном коммите,
с обоснованием. Молча обойти нельзя.

---

## 0. Стек и границы

| Слой | Выбор | Почему именно так |
|---|---|---|
| Язык | PHP 8.3 (`declare(strict_types=1)` в каждом файле) | на машине 8.3.33; Pest 5 и PHPUnit 13 требуют 8.4 — не берём |
| Фреймворк | Laravel 12.x | требует PHP ^8.2, зрелая связка с larastan 3 |
| БД | PostgreSQL 16 | `SKIP LOCKED`, partial unique, `pg_advisory_xact_lock`, отложенные constraint-триггеры |
| Очереди | Redis + `queue:work` | `after_commit => true` обязателен |
| Статанализ | PHPStan **level 9** + larastan 3 + phpstan-strict-rules | «PHPStan 9» из ТЗ = **уровень 9**; версии 9 не существует, последняя 2.2.x |
| Тесты | PHPUnit 11 (штатный для Laravel 12) | level 9 чисто выводит типы в типизированных классах; race-тесты проще как обычные классы |
| Стиль | Laravel Pint (пресет `laravel`) | |
| Порты | app `8000`, postgres `5434`, redis `6381` | 5432/6379/8080 заняты другими проектами на этой машине |

Что **не** делаем (по ТЗ): фронтенд, реальный эквайринг, реальных поставщиков, проверку
подписи вебхука, пересчёт валют.

---

## 1. Слои и поток управления

Единственный разрешённый маршрут запроса:

```
Route → FormRequest (валидация) → Controller (3–5 строк) → Action/Service (вся логика)
      → Repository (весь SQL/Eloquent) → Model → JsonResource (весь вывод)
```

### 1.1. Контроллер вызывает только сервис

Контроллер имеет право ровно на три вещи: собрать DTO из запроса, вызвать один сервис,
завернуть результат в Resource. Всё остальное — дефект.

```php
// ✅ так
final class CreateOrderController
{
    public function __construct(private readonly CreateOrder $action) {}

    public function __invoke(CreateOrderRequest $request): JsonResponse
    {
        $order = $this->action->execute(CreateOrderCommand::fromRequest($request));

        return OrderResource::make($order)->response()->setStatusCode(201);
    }
}
```

В контроллере **запрещено**: `if`/`match` по бизнес-условию, `DB::`, `Model::`,
`try/catch` вокруг доменных исключений, `response()->json([...])` с ручной сборкой массива,
вычисление HTTP-кода из бизнес-состояния. Если код зависит от исхода — исход возвращает
сервис объектом-результатом (`$result->httpStatus`), а не контроллер вычисляет.

Arch-тест `ControllerAltitudeTest` падает, если тело метода контроллера длиннее 5 строк
или содержит `DB::`/`Model::`/`Cache::`/`Http::`.

### 1.2. Структура `app/`

```
app/
├── Domain/
│   ├── Catalog/       {Actions,Repositories,DTO,Enums}
│   ├── Ordering/      {Actions,Repositories,DTO,Enums,Exceptions,StateMachine}
│   ├── Payments/      {Actions,Repositories,DTO,Enums,MoneyParser}
│   ├── Delivery/      {Actions,Repositories,DTO,Enums,Suppliers,Exceptions}
│   ├── Ledger/        {Actions,Repositories,DTO,Enums}
│   └── Reconciliation/{Actions,Repositories,DTO,Enums}
├── Http/
│   ├── Controllers/   тонкие, по одному `__invoke` на маршрут
│   ├── Requests/      вся валидация
│   └── Resources/     весь вывод
├── Jobs/              оркестрация, ноль бизнес-правил внутри
├── Console/Commands/  тонкие обёртки над Action
└── Support/           Clock, TraceId, Logging, Metrics
```

Модель Eloquent — только отображение таблицы: `$fillable`, `$casts`, связи, скоупы.
**Ни одного бизнес-метода в модели.** Ни одного `static::creating()` с логикой.

### 1.3. Job — оркестратор, не место логики

Job содержит: получить id → вызвать Action → обработать исход (`release`/`fail`).
Все Job'ы идемпотентны по построению: повторный прогон того же Job'а не меняет результат.
У каждого Job'а обязателен `failed()`, иначе заказ навсегда виснет в `delivering`.

---

## 2. PHPStan level 9 — без baseline

`phpstan.neon`:

```neon
includes:
    - vendor/larastan/larastan/extension.neon
    - vendor/phpstan/phpstan-strict-rules/rules.neon

parameters:
    level: 9
    paths: [app, config, database, routes, tests]
    reportUnmatchedIgnoredErrors: true
    treatPhpDocTypesAsCertain: false
    ignoreErrors: []            # пустой. Каждая запись сюда требует обоснования в ревью
    noEnvCallsOutsideOfConfig: true
    checkOctaneCompatibility: false
    exceptions:
        implicitThrows: false
        reportUncheckedExceptionDeadCatch: false   # иначе catch(UniqueConstraintViolationException) = ошибка
        check:
            missingCheckedExceptionInThrows: true
            tooWideThrowType: true
        uncheckedExceptionClasses:
            - LogicException
            - Illuminate\Validation\ValidationException
            - Illuminate\Database\Eloquent\ModelNotFoundException
```

### 2.1. Правила, а не подавление

- `ignoreErrors` и baseline **запрещены**. Ошибка уровня 9 чинится типом, а не глушится.
- Единственное отключённое правило — `strictRules.dynamicCallOnStaticMethod`, и отключено
  оно не ради нашего кода. Larastan объявляет методы Eloquent-билдера (`where`, `count`,
  `orderBy` и **каждый** локальный скоуп) как `@method static`, поэтому любая цепочка
  `Order::query()->where(...)->count()` выглядит для правила как вызов статического метода
  на объекте. Правило несовместимо с fluent-API фреймворка по построению. Альтернатива —
  сотни записей в `ignoreErrors` — хуже: она заодно скрыла бы настоящие находки.
- `mixed` не пересекает границу метода. Пришёл `mixed` (JSON, `DB::select`, `getHandlerContext()`) —
  сузили через `is_int`/`is_string`/`is_array` **в том же методе** и дальше отдали точный тип.
- `Model::find()` возвращает `mixed`/`?Model`. Поэтому **из репозитория наружу типы точные**:
  `findOrFail(): Order`, `findByPublicId(): ?Order`. Контроллеры и Action никогда не видят `mixed`.
- Дженерики обязательны: `/** @return Collection<int, Order> */`, `/** @param list<string> $codes */`,
  `@param \Closure(): ShowcasePage $resolve` (не `callable` — level 9 на нём вернёт `mixed`).
- Модели аннотируются `@property` через `php artisan ide-helper:models` в PHPDoc-блок класса.
- Аннотация над сырым запросом **не является защитой**: `@var list<object{id:int}>` над `DB::select`
  PHPStan примет на веру, а PDO вернёт строки. Поэтому — см. §6.4 про `::bigint` и явный каст.
- `@throws` ставится там, где PHPStan действительно видит бросок. Нарушение уникального
  индекса бросает не метод, а драйвер БД, поэтому `@throws UniqueConstraintViolationException`
  на методе репозитория — ложь, и `tooWideThrowType` её ловит. Ожидание 23505 документируется
  комментарием в месте обработки, а обработка живёт **снаружи** транзакции.
- `match` по enum пишем **без `default`** — тогда level 9 сам поймает незакрытую ветку
  при добавлении кейса.

### 2.2. Гейт

`composer stan` зелёный — обязательное условие любого коммита и любого утверждения в README.
Фраза «level 9 без ошибок» допустима только после зелёного прогона.

---

## 3. DRY — где он реально нужен здесь

Дублирование в этом проекте возникает ровно в пяти местах. Абстракции ставим только туда:

| Дублирование | Абстракция |
|---|---|
| Клиенты поставщиков A и B | `SupplierGateway` (интерфейс) + один `HttpSupplierClient`, различия — в конфиге |
| Ретраи/бэкофф/классификация исхода | декораторы `RetryingSupplier`, `FailoverSupplier` — не копипаста в каждом клиенте |
| Идемпотентность (вебхук, выдача, создание заказа) | один `IdempotencyGuard` с жизненным циклом (§5.4) |
| Форма ответа API | `JsonResource` + единый `Handler` для доменных исключений |
| Переходы статусов | один `OrderStateMachine` + enum, ни одного `if ($order->status === ...)` вне него |

**Против преждевременной абстракции:** интерфейс заводится, когда есть вторая реализация
*или* её требует тест (fake). `CatalogRepositoryInterface` при единственной реализации — не DRY,
а лишний слой. Правило: сначала два раза напиши, на третий — вынеси.

**Обратная сторона DRY:** миграция — замороженный текст. **Не** интерполировать
`OrderStatus::values()` в `CHECK` внутри миграции: добавление кейса задним числом изменит
старую миграцию, и `migrate:fresh` в CI даст схему, отличную от прода. Согласованность
обеспечивает тест-страж, сравнивающий `OrderStatus::values()` с `pg_get_constraintdef(...)`.

---

## 4. Никаких N+1

- Каждый запрос списка обязан быть константным по числу SQL-запросов. Витрина = ровно 2 запроса
  на страницу (товары + остатки по `WHERE product_id = ANY(:ids)`), а не 1 + N.
- Агрегаты — `withCount()`/join-агрегация/CTE, никогда не `->each(fn($o) => $o->items->count())`.
- Пакетная обработка (сверка, sweeper) — `chunkById()` + `with([...])`, инварианты считаются
  одним `LEFT JOIN`, а не циклом по строкам.
- В `AppServiceProvider::boot()` для `local`/`testing`:
  ```php
  Model::preventLazyLoading();
  Model::preventSilentlyDiscardingAttributes();
  Model::preventAccessingMissingAttributes();
  ```
  Ленивая загрузка в тестах = падение, а не медленный ответ.
- Каждый список покрыт тестом `assertQueryCount()` (обёртка над `DB::listen`), который фиксирует
  ожидаемое число запросов числом. Тест на витрину проверяет ещё и план: `Node Type === 'Index Only Scan'`.
- **Витрина не джойнит `product_stock`.** Замерено: join внутри LIMIT-запроса = 106 буферов
  вместо 5, потому что `product_stock` — самая перезаписываемая таблица.

---

## 5. Надёжность: правила, нарушение которых ломает приёмку

Это ядро задания. Каждое правило ниже закрывает конкретный состязательный сценарий.

### 5.1. Гарантия — в БД, а не в PHP

**Любая формулировка «ровно один раз» в коде или README обязана указывать имя индекса,
который это обеспечивает.** Утверждение без индекса — не гарантия, а надежда.

Реализовано в Ш1, каждая строка проверяется тестом `SchemaContractTest`:

| Гарантия | Индекс |
|---|---|
| повтор создания заказа не создаёт второй | `orders_idempotency_key_uq` |
| внешний id заказа уникален | `orders_public_id_uq` |
| повторный вебхук ничего не меняет | `payment_events_event_id_uq` |
| 50 вебхуков с **разными** `event_id` дают одну проводку | `payment_events_one_applied_paid_uq` |
| товар выдаётся ровно один раз | `deliveries_order_uq` |
| один код не уйдёт в два заказа | `deliveries_code_hash_uq` |
| ключ из пула не уйдёт в два заказа | `deliveries_license_key_uq`, `license_keys_delivery_uq` |
| один код существует в пуле один раз | `license_keys_code_hash_uq` |
| **таймаут не открывает путь ко второму поставщику** | `delivery_attempts_one_open_uq` |
| повтор после таймаута не создаёт вторую выдачу | `delivery_attempts_one_success_uq` |
| один вызов учитывается один раз | `delivery_attempts_request_uq` |
| одна попытка на (заказ, поставщик, эпоха) | `delivery_attempts_epoch_uq` |
| купленный код не теряется и не задваивается | `supplier_issued_codes_request_uq`, `supplier_issued_codes_hash_uq` |
| деньги не проводятся дважды | `ledger_transactions_idempotency_uq` |
| повторная проводка не удваивает остатки | `ledger_entries_pair_uq` |
| одна открытая находка на (вид, субъект) | `reconciliation_findings_open_uq` |

Плюс семь триггеров: `orders_no_final_downgrade` (из `delivered`, `payment_failed`,
`cancelled` выхода нет ни из кода, ни из psql, ни из миграции), `products_ensure_stock_row`,
`product_stock_flag_ins` + `product_stock_flag_upd`, `license_keys_no_reassign`,
`ledger_entries_balanced` (отложенный, на COMMIT), `ledger_entries_immutable`.

### 5.2. Границы транзакций

- **HTTP-вызов никогда не внутри открытой транзакции БД.** Ни к поставщику, ни куда-либо ещё.
- Порядок для выдачи, нарушение = дефект:
  1. CAS-захват аренды на заказе (`affected = 1` — только тогда работаем);
  2. проверка проекции платежа (§5.5);
  3. **коммит строки `delivery_attempts(state='in_flight')` с детерминированным `request_id` ДО HTTP**;
  4. HTTP вне транзакций;
  5. `supplier_issued_codes` — микротранзакция, `ON CONFLICT DO NOTHING`, сразу после `200 ok`;
  6. бизнес-транзакция: привязка кода, захват ключа, проводки, CAS `delivering → delivered`, снятие аренды.

  Шаг 5 отделён от шага 6 намеренно: любое исключение в бизнес-транзакции (включая `23505`)
  откатит шаг 6, но **не потеряет купленный код**. Повторный прогон Job'а доведёт заказ.
- Единый порядок блокировок во всём коде: `orders → order_items → deliveries → license_keys → product_stock`.
  Другой порядок = дедлок.

### 5.3. Таймаут ≠ отказ

Исход классифицируется **по паре (фаза транспорта, разобранный конверт поставщика)**,
а не по HTTP-коду:

| Исход | Класс | Можно на B? | `epoch` |
|---|---|---|---|
| `200 {status:ok}` | `Issued` | нет | — |
| `4xx` с конвертом (`out_of_stock`, `invalid_sku`) | `NotIssuedCertain` | да | +1 |
| ECONNREFUSED / DNS / TLS-handshake fail (`connect_time == 0`) | `NotIssuedCertain` | да | +1 |
| read/total timeout | **`Unknown`** | **нет** | тот же |
| любой `5xx`, включая 502/503/504 от прокси | **`Unknown`** | **нет** | тот же |
| обрыв после отправки, невалидное тело | **`Unknown`** | **нет** | тот же |

Правила:

- `request_id = req_{order_public_id}-{SUPPLIER}-{epoch}`, детерминированный. Все сетевые ретраи
  внутри эпохи идут **с тем же `request_id`** — только так повтор после таймаута не создаёт второй код.
- `epoch` растёт **только** после авторитетного «не выдано». Никогда после `Unknown`/5xx/таймаута.
- **`probe` 404 сам по себе НЕ разрешает fallback** — он доказывает «ещё не выдано», а не «не будет».
  Заглушка поэтому трёхсостояточная (`claim`-before-work) и умеет `POST /issue/{rid}/seal`
  (CAS `not_found → sealed`). Fallback на B открывает **только** `NotIssuedCertain` или успешный `seal`.
- `probe`/`seal` не раньше `first_sent_at + issue_timeout + supplier_max_processing`.
- Circuit breaker закрывает новые `POST /issue` и `replay`, но **никогда** не закрывает `probe`/`seal`:
  выяснение судьбы обязательства — снижение риска, а не нагрузка.
- Единственное место, где вычисляется право на fallback:
  ```php
  public static function unblocksFallback(CallOutcome $o): bool
  {
      return $o === CallOutcome::NotIssuedCertain;   // и точка
  }
  ```

### 5.4. Идемпотентность

- Признание оплаты идемпотентно **по заказу**, а не по `event_id`:
  `idempotency_key = 'payment_captured:{order_id}'`. Иначе 50 вебхуков с *разными* `event_id`
  дадут 50 проводок — и приёмочный сценарий №1 сломает журнал, оставаясь «сбалансированным».
- Диспатч Job'а — **по состоянию, а не по факту вставки**:
  ```php
  $this->events->insertIgnore($data);                    // ON CONFLICT (event_id) DO NOTHING
  if ($this->events->isUnapplied($data->eventId)) {      // ← читаем состояние
      ApplyPaymentEventJob::dispatch($data->eventId)->afterCommit();
  }
  ```
  `if ($inserted) dispatch(...)` — **запрещено**: падение между COMMIT и dispatch навсегда теряет
  платёж, потому что все повторы уйдут в `DO NOTHING`.
- Страховка: команда `payments:drain-unapplied` в шедулере раз в минуту переставляет всё
  с `applied_at IS NULL AND received_at < now() - 30s`. Она же чинит «вебхук раньше заказа».
- `IdempotencyGuard` имеет **жизненный цикл**, а не факт существования: `state ∈ {claimed, completed}`,
  протухший `claimed` перехватывается. «Захват без завершения» = вечная блокировка повтора.
- `Idempotency-Key` на `POST /orders` обязателен; отсутствие → 422. Никаких `Str::uuid()` по умолчанию.

### 5.5. Порядок событий и поздний `failed`

- Платёжная проекция (`order_payment_states`) отделена от FSM выполнения заказа.
  Проекция монотонна по кортежу `(occurred_at, received_at, id)`; устаревшее событие
  помечается `stale` и журнал не трогает.
- **Доставка обязана читать проекцию платежа сразу после захвата аренды.** Если проекция
  не `paid` — доставка не выполняется, поднимается `reconciliation_findings('payment_revoked')`.
  Без этой проверки поздний `failed` в окне между `paid` и стартом доставки отдаёт товар бесплатно
  и молча (воспроизведено на живом PostgreSQL).
- Поздний `failed`:
  - доставка ещё не начата → заказ `cancelled`, сторно проводки;
  - `delivering`/`delivered` → выдачу **не трогаем**, `needs_review = true` + `late_payment_failure`.
- Вебхук **никогда** не отвечает 4xx/5xx на осмысленный JSON: неизвестное/битое тело пишем
  с `process_state='malformed'` и отвечаем 200. 5xx = вечные ретраи шлюза.
- Повторный `event_id` с **другим телом** → 200 + `event_id_reuse` + карантин. Молча терять нельзя.

### 5.6. Ошибки, которые не ошибки

- `23505` по `deliveries_order_uq` — это **«уже выдано»**, а не сбой: перечитать и вернуть
  существующий код. Никогда не переводить заказ в `delivery_failed` по этому коду.
- `catch (UniqueConstraintViolationException)` — **только снаружи** `DB::transaction`.
  После `23505` транзакция PostgreSQL в состоянии abort, любой следующий запрос вернёт `25P02`.
- 0 строк при захвате ключа — это `out_of_stock` (восстановимо), а не исключение.
- Декремент счётчика остатка — `GREATEST(available_count - 1, 0)`. `CHECK (>= 0)` остаётся
  ассертом целостности, но не имеет права уронить продажу: занижённый счётчик после импорта
  ключей иначе даёт `23514` на каждой попытке доводки и валит приёмочный сценарий №6.
- Пополнение пула — **только** через `restock()`, одной транзакцией с бампом счётчика.
  Прямой `INSERT INTO license_keys` запрещён (тест-страж).

### 5.7. Деньги

- Только целые копейки, `bigint`. `float` в денежном пути запрещён на уровне типа.
- Двойная запись: `ledger_entries` append-only + отложенный `CONSTRAINT TRIGGER` на нулевую
  сумму транзакции. Несбалансированная проводка невозможна, а не «не должна случаться».
- `sum(amount_signed) = 0` **ничего не доказывает** (две ошибочные проводки тоже дают ноль).
  Сверять **per-account остатки**.
- `sum(bigint)` в PostgreSQL возвращает `numeric`, а PDO отдаёт `numeric` строкой. Поэтому
  в сырых запросах всегда `SUM(...)::bigint`, `EXTRACT(EPOCH FROM ...)::int`, и явный `(int)`
  в мапперe. Тест-страж: `assertIsInt($report->summary->ledgerVsOrdersDeltaMinor)`.
- Расхождение `amount` вебхука с ценой заказа — **не** автовыдача: `amount_mismatch` + аномалия.
- Трактовка `amount` (рубли или копейки) не определена в ТЗ. Допущение изолировано в одном
  классе `MoneyParser`, покрыто тестом и явно записано в README.

---

## 6. Тесты

### 6.1. Обязательный минимум — по критериям приёмки

| № | Критерий из ТЗ | Тест |
|---|---|---|
| 1 | 50 параллельных вебхуков → одна выдача | `RaceWebhookTest` — **в двух вариантах: один `event_id` и 50 разных** |
| 2 | Повтор того же `event_id` ничего не меняет | `WebhookIdempotencyTest` |
| 3 | Вебхук вне порядка / раньше заказа | `WebhookOrderingTest` (+ поздний `failed` до старта доставки) |
| 4 | Таймаут, который на самом деле выдал код | `TimeoutTrapTest` (`X-Force-Behavior: timeout_after_issue`) |
| 5 | A недоступен → fallback на B → ровно один раз | `SupplierFailoverTest` |
| 6 | Пустой остаток → восстановимо, без падения | `OutOfStockRecoveryTest` (в т.ч. при **занижённом** счётчике) |

### 6.2. Сверх критериев — обязательны

1. Убийство воркера между `POST /issue` и записью результата → повтор не создаёт второй код.
2. `probe` вернул `in_flight` → на B **не** уходим.
3. Исключение внутри бизнес-транзакции → код уцелел в `supplier_issued_codes`, повтор довёл заказ.
4. Вставка события есть, dispatch не произошёл → `payments:drain-unapplied` довёл до `delivered`.
5. Три цикла `out_of_stock → restock` → на четвёртом `delivered`, а не `delivery_failed`.
6. Протухший резерв ключа → sweeper → следующий заказ по этому SKU выдаётся.
7. Код ключа **никогда** не попадает в логи.
8. Дрейф `product_stock` → доставка проходит, поднято `stock_drift`, падения нет.

### 6.3. Как тестировать гонки (это не обычный feature-тест)

`php artisan test` однопоточный, а `RefreshDatabase` оборачивает тест в транзакцию —
параллельные соединения не увидят фикстур. Поэтому:

- Race-тесты используют **`DatabaseTruncation`**, не `RefreshDatabase`.
- Два уровня доказательства:
  1. **детерминированный** — два реальных соединения PostgreSQL, оба делают CAS-захват аренды,
     ровно одно получает `affected = 1`. Быстро, не зависит от планировщика ОС;
  2. **реальный HTTP** — `curl --parallel` / `Http::pool` по 50 запросов против поднятого приложения.
- Ассерты **скоупятся по `order_id`**. `Delivery::count() === 1` глобально развалится от соседнего теста.
- `Delivery::where(...)->count()`, а не `groupBy()->count()`: Eloquent на группированном запросе
  вернёт размер первой группы, а не число групп.
- Заглушка поставщика управляется детерминированно (`PUT /admin/behavior`), вероятности из env —
  только дефолт для демо. Тест на вероятности — не тест.
- Одной командой: `make race`.

### 6.4. Прочее

- `Illuminate\Support\Sleep` инъекцией, `Sleep::fake()` в тестах. Arch-тест запрещает
  `sleep()`/`usleep()` вне `RetryingSupplier`.
- Отдельный нетранзакционный тест, который реально коммитит и ловит отложенный триггер журнала:
  под `RefreshDatabase` `RELEASE SAVEPOINT` его **не** запускает, и тест не проверяет ничего.
- Тест-страж соответствия `OrderStatus::values()` и `CHECK`-ограничения в БД.

---

## 7. Логи, метрики, наблюдаемость

Структурированный JSON, обязательные поля:
`trace_id, order_id, event_id, request_id, supplier, attempt, outcome, latency_ms, status_from, status_to`.

Обязательные события: `webhook_received`, `webhook_deduped`, `webhook_stale`, `payment_applied`,
`delivery_lease_acquired`, `supplier_call`, `supplier_timeout`, `supplier_unknown_resolved`,
`key_reserved`, `order_delivered`, `order_recovered`, `reconciliation_finding`.

**Секреты в логи не попадают.** Код ключа — только `code_last4` и `code_hash`.
Это проверяется тестом, а не дисциплиной.

`/health` (pg, redis, возраст последней сверки) и `/metrics` (плоский текстовый экспортер
из уже посчитанных таблиц-проекций, без отдельной библиотеки).

Оба списка выше — не пожелание, а договор: `ObservabilityContractTest` читает эту
секцию и падает, если код не пишет обещанное событие или поле. Повод конкретный:
список событий прожил несколько этапов вдвое длиннее реальности, а половины
обязательных полей не существовало вовсе. Документ, который никто не исполняет,
расходится с кодом всегда — вопрос лишь в том, когда это заметят.

Поле `reason` — свободный текст и только он. Идентификатор запроса, поставщик,
номер попытки, исход и латентность имеют собственные поля (`StructuredLog::supplier`).
Сваливать их в `reason` означает лог, по которому нельзя ни сгруппировать, ни
посчитать — то есть нельзя ответить ни на один вопрос, ради которого он пишется.

---

## 8. Чистый код — конкретно

- `final` по умолчанию у всех классов; `readonly` у всех DTO.
- Конструкторная инъекция, `private readonly`. Фасады Laravel — только в `Support/`,
  в домене запрещены (мешают level 9 и тестам).
- Именование: Action — глагол (`CreateOrder`, `ApplyPaymentEvent`, `DeliverOrder`);
  Repository — существительное (`OrderRepository`); DTO — `...Command`/`...Result`/`...Data`.
- Ранний возврат вместо вложенности. Максимум 2 уровня вложенности в методе.
- Никаких «магических» строк и чисел: статусы, исходы, счета журнала — `enum`.
- Комментарий объясняет **почему**, а не что. Комментарии в этом проекте нужны ровно там,
  где код выглядит избыточным, но таковым не является (порядок шагов выдачи, `GREATEST(...,0)`,
  разделение захвата и применения кода) — там они **обязательны**.
- Никаких `env()` в рантайме — только `config()`. Это ещё и правило PHPStan.
- **Исходящий HTTP — только через Http-клиент Laravel** (`Http::get/post/pool`), который
  работает поверх Guzzle. Сырой `curl_*` и прямой `new GuzzleHttp\Client` запрещены:
  первый — это ручной boilerplate вокруг того же `curl_multi`, второй обходит общую
  конфигурацию таймаутов и `Http::fake()` в тестах. Проверяется тестом-стражем
  `http_calls_go_through_the_framework_client`.

---

## 9. Команды

```bash
make up          # docker compose up: app, pg(5434), redis(6381), worker, scheduler, supplier-a, supplier-b
make migrate     # миграции + сид (12 SKU + 50 ключей из ТЗ)
make test        # весь набор
make race        # только состязательные сценарии, одной командой
make qa          # pint --test && stan && test  — это же гоняет CI
make demo        # сквозной сценарий: заказ → вебхук → выдача, с выводом логов
```

`composer qa` обязан быть зелёным перед коммитом.

---

## 10. Зафиксированные допущения

Каждое повторяется в README, потому что проверяющий имеет право не согласиться:

1. **Заказ = один SKU, `quantity = 1`.** ТЗ говорит «создание заказа по SKU»; контракт
   поставщика принимает один `sku`. Это убирает целый класс ошибок (проводка суммы заказа
   вместо суммы позиции) и не теряет ни одного критерия приёмки. Расширение до мультипозиции
   описано в README отдельным разделом.
2. `amount` в вебхуке — мажорные единицы (рубли), как в примере ТЗ. Изолировано в `MoneyParser`.
3. Контракт заглушки расширен: `GET /issue/{request_id}` (probe), `POST /issue/{request_id}/seal`,
   `GET /issues?order_id=`, `PUT /admin/behavior`. Заглушки пишем мы — расширять контракт
   разрешено, и без `seal` критерий №5 недостижим безопасно.
4. `payment_failed` считается финальным (по букве ТЗ). Легитимный повторный платёж после
   чарджбэка не поддерживается; механика (`orders.payment_attempt`) описана, но не включена.
5. Компенсируемый fallback из неразрешённого `Unknown` — **выключен флагом**
   (`allow_compensated_fallback = false`). Это единственный механизм, способный породить
   второй код, и ни один критерий приёмки он не закрывает.

---

## 11. Чего в этом проекте делать нельзя

- Логика в контроллере, модели или миграции.
- `ignoreErrors` / baseline в PHPStan.
- HTTP-вызов внутри транзакции БД.
- Новый `request_id` после таймаута.
- Fallback на B из неразрешённого `Unknown`.
- `dispatch` по факту вставки вместо чтения состояния.
- Трактовка `23505` как ошибки доставки.
- Утверждение «ровно один раз» без имени индекса рядом.
- Код ключа в логе.
- Заявление о производительности без `EXPLAIN (ANALYZE, BUFFERS)` под ним.
