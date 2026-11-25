<?php

namespace Tests\Feature\Api\V1;

use Tests\TestCase;

class HealthTest extends TestCase
{
    public function test_health_uses_standard_format(): void
    {
        $this->getJson('/api/v1/health')
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonStructure(['success', 'message', 'data' => ['app', 'php', 'laravel', 'database'], 'meta']);
    }

    public function test_unknown_route_returns_standard_404(): void
    {
        $this->getJson('/api/v1/no-existe')
            ->assertNotFound()
            ->assertExactJson([
                'success' => false,
                'message' => 'Recurso no encontrado.',
                'data' => null,
                'meta' => [],
            ]);
    }
}
