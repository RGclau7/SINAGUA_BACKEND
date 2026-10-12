<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ErrorFormatTest extends TestCase
{
    use RefreshDatabase;

    public function test_unknown_route_returns_uniform_404(): void
    {
        $this->getJson('/api/v1/no-existe')
            ->assertNotFound()
            ->assertExactJson(['error' => ['code' => 'not_found', 'message' => 'Recurso no encontrado.']]);
    }

    public function test_protected_route_requires_token(): void
    {
        $this->postJson('/api/v1/sync/push', [])
            ->assertUnauthorized()
            ->assertJsonPath('error.code', 'unauthenticated');
    }

    public function test_protected_route_without_json_accept_header_still_returns_401(): void
    {
        $this->get('/api/v1/sync/pull')
            ->assertUnauthorized()
            ->assertJsonPath('error.code', 'unauthenticated');
    }

    public function test_validation_errors_include_details(): void
    {
        $this->actingAsMemberOf();

        $this->postJson('/api/v1/devices', ['id' => 'no-es-uuid'])
            ->assertUnprocessable()
            ->assertJsonPath('error.code', 'validation_error')
            ->assertJsonStructure(['error' => ['code', 'message', 'details' => ['id', 'name']]]);
    }

    public function test_wrong_method_returns_405(): void
    {
        $this->deleteJson('/api/v1/health')
            ->assertStatus(405)
            ->assertJsonPath('error.code', 'method_not_allowed');
    }
}
