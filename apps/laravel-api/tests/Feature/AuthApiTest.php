<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AuthApiTest extends TestCase
{
    use RefreshDatabase;

    // ---- 正常系 ----

    public function test_register_returns_token(): void
    {
        $response = $this->postJson('/api/register', [
            'name' => '山田太郎',
            'email' => 'taro@example.com',
            'password' => 'password123',
        ]);

        $response->assertCreated();
        $response->assertJsonStructure(['user' => ['id', 'name', 'email'], 'token']);
        $this->assertDatabaseHas('users', ['email' => 'taro@example.com']);
    }

    public function test_login_returns_token(): void
    {
        $user = User::factory()->create(['password' => Hash::make('password123')]);

        $response = $this->postJson('/api/login', [
            'email' => $user->email,
            'password' => 'password123',
        ]);

        $response->assertOk();
        $response->assertJsonStructure(['token']);
    }

    public function test_logout_revokes_current_token(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $response = $this->postJson('/api/logout');

        $response->assertNoContent();
    }

    // ---- 準正常系・異常系 ----

    public function test_login_with_wrong_password_returns_422(): void
    {
        $user = User::factory()->create(['password' => Hash::make('password123')]);

        $response = $this->postJson('/api/login', [
            'email' => $user->email,
            'password' => 'wrong-password',
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors('email');
    }

    public function test_register_with_duplicate_email_returns_422(): void
    {
        User::factory()->create(['email' => 'dup@example.com']);

        $response = $this->postJson('/api/register', [
            'name' => '別人',
            'email' => 'dup@example.com',
            'password' => 'password123',
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors('email');
    }

    public function test_register_with_short_password_returns_422(): void
    {
        $response = $this->postJson('/api/register', [
            'name' => '太郎',
            'email' => 'taro2@example.com',
            'password' => 'short',
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors('password');
    }

    public function test_logout_without_token_returns_401(): void
    {
        $response = $this->postJson('/api/logout');

        $response->assertUnauthorized();
    }
}
