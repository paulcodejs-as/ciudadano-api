<?php

namespace Tests\Feature\Api\V1;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AuthTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_login_and_get_profile_and_logout(): void
    {
        User::factory()->admin()->create(['email' => 'admin@test.pe', 'password' => 'secreto123']);

        $token = $this->postJson('/api/v1/auth/login', ['email' => 'admin@test.pe', 'password' => 'secreto123'])
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.user.is_admin', true)
            ->json('data.token');

        $this->withToken($token)->getJson('/api/v1/auth/me')
            ->assertOk()
            ->assertJsonPath('data.email', 'admin@test.pe');

        $this->withToken($token)->postJson('/api/v1/auth/logout')->assertOk();
        $this->assertDatabaseCount('personal_access_tokens', 0);
    }

    public function test_non_admin_cannot_get_token(): void
    {
        User::factory()->create(['email' => 'vecino@test.pe', 'password' => 'secreto123']);

        $this->postJson('/api/v1/auth/login', ['email' => 'vecino@test.pe', 'password' => 'secreto123'])
            ->assertUnauthorized()
            ->assertJsonPath('success', false);
    }

    public function test_wrong_password_is_rejected(): void
    {
        User::factory()->admin()->create(['email' => 'admin@test.pe', 'password' => 'secreto123']);

        $this->postJson('/api/v1/auth/login', ['email' => 'admin@test.pe', 'password' => 'otra'])
            ->assertUnauthorized();
    }

    public function test_validation_errors_use_standard_format(): void
    {
        $this->postJson('/api/v1/auth/login', ['email' => 'no-es-email'])
            ->assertStatus(422)
            ->assertJsonPath('success', false)
            ->assertJsonStructure(['errors' => ['email', 'password']]);
    }

    public function test_me_requires_token(): void
    {
        $this->getJson('/api/v1/auth/me')
            ->assertUnauthorized()
            ->assertJsonPath('message', 'No autenticado.');
    }
}
