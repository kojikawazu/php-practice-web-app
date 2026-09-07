<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class AuthTest extends TestCase
{
    use RefreshDatabase;

    // ---- 正常系 ----

    public function test_user_can_register_and_is_logged_in(): void
    {
        $response = $this->post(route('register'), [
            'name' => '山田太郎',
            'email' => 'taro@example.com',
            'password' => 'password123',
            'password_confirmation' => 'password123',
        ]);

        $response->assertRedirect(route('tasks.index'));
        $this->assertDatabaseHas('users', ['email' => 'taro@example.com']);
        $this->assertAuthenticated();
    }

    public function test_user_can_login_with_correct_credentials(): void
    {
        $user = User::factory()->create(['password' => Hash::make('password123')]);

        $response = $this->post(route('login'), [
            'email' => $user->email,
            'password' => 'password123',
        ]);

        $response->assertRedirect(route('tasks.index'));
        $this->assertAuthenticatedAs($user);
    }

    public function test_user_can_logout(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->post(route('logout'));

        $response->assertRedirect(route('login'));
        $this->assertGuest();
    }

    // ---- 準正常系・異常系 ----

    public function test_login_fails_with_wrong_password(): void
    {
        $user = User::factory()->create(['password' => Hash::make('password123')]);

        $response = $this->from(route('login'))->post(route('login'), [
            'email' => $user->email,
            'password' => 'wrong-password',
        ]);

        $response->assertSessionHasErrors('email');
        $this->assertGuest();
    }

    public function test_register_rejects_duplicate_email(): void
    {
        User::factory()->create(['email' => 'dup@example.com']);

        $response = $this->from(route('register'))->post(route('register'), [
            'name' => '別人',
            'email' => 'dup@example.com',
            'password' => 'password123',
            'password_confirmation' => 'password123',
        ]);

        $response->assertSessionHasErrors('email');
    }

    public function test_register_rejects_unconfirmed_password(): void
    {
        $response = $this->from(route('register'))->post(route('register'), [
            'name' => '太郎',
            'email' => 'taro2@example.com',
            'password' => 'password123',
            'password_confirmation' => 'different',
        ]);

        $response->assertSessionHasErrors('password');
        $this->assertDatabaseMissing('users', ['email' => 'taro2@example.com']);
    }

    public function test_register_rejects_short_password(): void
    {
        $response = $this->from(route('register'))->post(route('register'), [
            'name' => '太郎',
            'email' => 'taro3@example.com',
            'password' => 'short',
            'password_confirmation' => 'short',
        ]);

        $response->assertSessionHasErrors('password');
    }
}
