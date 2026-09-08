<?php

declare(strict_types=1);

namespace Tests\Feature\Docs;

use App\Domain\Ordering\Enums\OrderItemStatus;
use App\Domain\Ordering\Enums\OrderStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Yaml\Yaml;
use Tests\TestCase;

/**
 * Документация обязана совпадать с кодом.
 *
 * Расхождение здесь дороже отсутствия документации: по неверному описанию
 * интегрируются, а потом выясняют правду отладкой. Поэтому спецификация
 * сверяется с фактическим списком маршрутов, а не проверяется глазами.
 */
final class OpenApiSpecTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Маршруты, которых в спецификации быть не должно.
     *
     * @var list<string>
     */
    private const NOT_PUBLIC_API = ['/', 'up', 'docs', 'docs/openapi.yaml', 'storage/{path}'];

    #[Test]
    public function the_spec_is_valid_yaml_and_declares_openapi_3(): void
    {
        $spec = $this->spec();

        self::assertArrayHasKey('openapi', $spec);
        self::assertIsString($spec['openapi']);
        self::assertStringStartsWith('3.', $spec['openapi']);
        self::assertArrayHasKey('paths', $spec);
    }

    #[Test]
    public function every_public_route_is_documented(): void
    {
        $spec = $this->spec();
        /** @var array<string, mixed> $paths */
        $paths = is_array($spec['paths'] ?? null) ? $spec['paths'] : [];

        $undocumented = [];

        foreach (Route::getRoutes()->getRoutes() as $route) {
            $uri = $route->uri();

            if (in_array($uri, self::NOT_PUBLIC_API, true)) {
                continue;
            }

            if (! array_key_exists('/'.$uri, $paths)) {
                $undocumented[] = $uri;
            }
        }

        self::assertSame(
            [],
            $undocumented,
            "Маршруты есть в коде, но не описаны в docs/openapi.yaml:\n".implode("\n", $undocumented),
        );
    }

    #[Test]
    public function the_spec_does_not_describe_routes_that_do_not_exist(): void
    {
        $spec = $this->spec();
        /** @var array<string, mixed> $paths */
        $paths = is_array($spec['paths'] ?? null) ? $spec['paths'] : [];

        $real = [];

        foreach (Route::getRoutes()->getRoutes() as $route) {
            $real[] = '/'.$route->uri();
        }

        $phantom = array_values(array_diff(array_keys($paths), $real));

        // Описанный, но несуществующий эндпоинт хуже неописанного: по нему
        // напишут интеграцию и получат 404 в бою.
        self::assertSame([], $phantom, "Описаны несуществующие маршруты:\n".implode("\n", $phantom));
    }

    #[Test]
    public function the_documented_order_statuses_match_the_enum(): void
    {
        // Статусы описаны в трёх местах: энум, CHECK в базе и спецификация.
        // Первые два уже сверены между собой; здесь замыкается третье.
        self::assertSame(OrderStatus::values(), $this->documentedEnum('OrderStatus'));
    }

    /**
     * Значения перечисления из спецификации.
     *
     * @return list<mixed>
     */
    private function documentedEnum(string $schema): array
    {
        $values = $this->schema($schema)['enum'] ?? null;

        self::assertIsArray($values, "В спецификации нет перечисления {$schema}.");

        return array_values($values);
    }

    /**
     * Отсортированный список полей схемы.
     *
     * @return list<string>
     */
    private function documentedFields(string $schema): array
    {
        $properties = $this->schema($schema)['properties'] ?? null;

        self::assertIsArray($properties, "У схемы {$schema} не описаны поля.");

        return $this->sortedKeys($properties);
    }

    /**
     * @return array<string, mixed>
     */
    private function schema(string $name): array
    {
        $spec = $this->spec();
        $components = $spec['components'] ?? null;
        $schemas = is_array($components) ? ($components['schemas'] ?? null) : null;
        $schema = is_array($schemas) ? ($schemas[$name] ?? null) : null;

        self::assertIsArray($schema, "В спецификации нет схемы {$name}.");

        /** @var array<string, mixed> $schema */
        return $schema;
    }

    /**
     * @param  array<mixed>  $value
     * @return list<string>
     */
    private function sortedKeys(array $value): array
    {
        $keys = array_map(static fn (int|string $key): string => (string) $key, array_keys($value));
        sort($keys);

        return $keys;
    }

    #[Test]
    public function the_documented_item_statuses_match_the_enum(): void
    {
        self::assertSame(OrderItemStatus::values(), $this->documentedEnum('OrderItemStatus'));
    }

    #[Test]
    public function the_documented_order_fields_match_a_real_response(): void
    {
        // Повод конкретный. Когда заказ стал многопозиционным, контракт
        // изменился — в теле запроса появился items, в ответе появились
        // позиции. Спецификация осталась прежней, и все пять тестов выше
        // остались ЗЕЛЁНЫМИ: они проверяют наличие маршрутов, а маршруты
        // не менялись.
        //
        // То есть документация врала, а сторож это пропускал. Расхождение
        // здесь дороже отсутствия документации: по неверному описанию
        // интегрируются, а правду выясняют отладкой.
        //
        // Поэтому сверка идёт с НАСТОЯЩИМ ответом, а не с намерением автора.
        $this->seed();

        $order = $this->withHeader('Idempotency-Key', 'spec-contract')
            ->postJson('/api/v1/orders', [
                'items' => [['sku' => 'KEY-CS2-PRIME'], ['sku' => 'STEAM-TOPUP-500']],
            ])
            ->assertCreated()
            ->json('data');

        self::assertIsArray($order);

        self::assertSame(
            $this->documentedFields('Order'),
            $this->sortedKeys($order),
            'Поля заказа в ответе разошлись с описанными в docs/openapi.yaml.',
        );

        $items = $order['items'] ?? null;
        self::assertIsArray($items);
        self::assertNotSame([], $items, 'Ответ без позиций — проверять нечего.');

        foreach ($items as $item) {
            self::assertIsArray($item);
            self::assertSame(
                $this->documentedFields('OrderItem'),
                $this->sortedKeys($item),
                'Поля позиции в ответе разошлись с описанными в docs/openapi.yaml.',
            );
        }
    }

    #[Test]
    public function the_documented_request_body_mentions_both_accepted_forms(): void
    {
        // Тело запроса ответом не проверишь, поэтому здесь — прямая сверка
        // с тем, что принимает FormRequest.
        $body = $this->spec();

        // Спуск по одному уровню за раз: цепочка обращений к mixed уровень 9
        // не пропускает, и правильно — «ключ, которого нет» в спецификации
        // должен читаться как внятный отказ, а не как загадочный null.
        foreach (['paths', '/api/v1/orders', 'post', 'requestBody', 'content', 'application/json', 'schema', 'properties'] as $key) {
            self::assertIsArray($body, "В спецификации оборвался путь на ключе {$key}.");
            self::assertArrayHasKey($key, $body, "В спецификации нет ключа {$key}.");

            $body = $body[$key];
        }

        self::assertIsArray($body);
        self::assertArrayHasKey('sku', $body, 'Форма первого этапа не описана.');
        self::assertArrayHasKey('items', $body, 'Заказ из нескольких товаров не описан.');
    }

    #[Test]
    public function the_spec_is_served_by_the_application(): void
    {
        // Спецификация отдаётся тем же приложением, поэтому не может случиться,
        // что развёрнута одна версия, а описана другая.
        $this->get('/docs/openapi.yaml')
            ->assertOk()
            ->assertHeader('Content-Type', 'application/yaml; charset=utf-8');

        $this->get('/docs')->assertOk()->assertSee('redoc', false);
    }

    /**
     * @return array<string, mixed>
     */
    private function spec(): array
    {
        $parsed = Yaml::parseFile(base_path('docs/openapi.yaml'));

        self::assertIsArray($parsed, 'docs/openapi.yaml не разбирается как YAML.');

        /** @var array<string, mixed> $parsed */
        return $parsed;
    }
}
