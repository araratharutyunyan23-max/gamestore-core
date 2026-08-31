<?php

declare(strict_types=1);

namespace Tests\Feature\Docs;

use App\Domain\Ordering\Enums\OrderStatus;
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
        $spec = $this->spec();

        $components = $spec['components'] ?? null;
        $schemas = is_array($components) ? ($components['schemas'] ?? null) : null;
        $status = is_array($schemas) ? ($schemas['OrderStatus'] ?? null) : null;
        $documented = is_array($status) ? ($status['enum'] ?? null) : null;

        self::assertIsArray($documented, 'В спецификации нет перечисления OrderStatus.');

        // Статусы описаны в трёх местах: энум, CHECK в базе и спецификация.
        // Первые два уже сверены между собой; здесь замыкается третье.
        self::assertSame(OrderStatus::values(), $documented);
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
