<?php

declare(strict_types=1);

namespace Tests\Feature;

use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class ApplicationSmokeTest extends TestCase
{
    #[Test]
    public function root_returns_the_api_map(): void
    {
        $this->getJson('/')
            ->assertOk()
            ->assertJsonPath('service', 'gamestore-core')
            ->assertJsonStructure(['service', 'endpoints' => ['health', 'create_order', 'payment_webhook']]);
    }

    #[Test]
    public function health_endpoint_responds(): void
    {
        $this->get('/up')->assertOk();
    }
}
