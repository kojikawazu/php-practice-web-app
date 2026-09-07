<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class TokenApiTest extends TestCase
{
    use RefreshDatabase;

    // ---- 正常系 ----

    public function test_index_lists_own_tokens_without_leaking_hash(): void
    {
        $user = User::factory()->create();
        $user->createToken('cli');
        Sanctum::actingAs($user);

        $response = $this->getJson('/api/tokens');

        $response->assertOk();
        $response->assertJsonFragment(['name' => 'cli']);
        // ハッシュ値（token カラム）は出さない
        $response->assertJsonMissingPath('0.token');
    }

    public function test_store_issues_named_token(): void
    {
        Sanctum::actingAs(User::factory()->create());

        $response = $this->postJson('/api/tokens', ['name' => 'デプロイ用']);

        $response->assertCreated();
        $response->assertJsonStructure(['name', 'token', 'expires_at']);
        $response->assertJsonFragment(['name' => 'デプロイ用']);
    }

    public function test_store_with_expiry_sets_expires_at(): void
    {
        Sanctum::actingAs($user = User::factory()->create());

        $response = $this->postJson('/api/tokens', ['name' => '期限付き', 'expires_in_days' => 7]);

        $response->assertCreated();
        $this->assertNotNull($response->json('expires_at'));
        $this->assertNotNull($user->tokens()->where('name', '期限付き')->value('expires_at'));
    }

    public function test_destroy_revokes_own_token(): void
    {
        $user = User::factory()->create();
        $token = $user->createToken('使い捨て')->accessToken;
        Sanctum::actingAs($user);

        $response = $this->deleteJson("/api/tokens/{$token->id}");

        $response->assertNoContent();
        $this->assertDatabaseMissing('personal_access_tokens', ['id' => $token->id]);
    }

    // ---- 準正常系・異常系 ----

    public function test_store_without_name_returns_422(): void
    {
        Sanctum::actingAs(User::factory()->create());

        $response = $this->postJson('/api/tokens', []);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors('name');
    }

    public function test_store_with_invalid_expiry_returns_422(): void
    {
        Sanctum::actingAs(User::factory()->create());

        $response = $this->postJson('/api/tokens', ['name' => 'x', 'expires_in_days' => 0]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors('expires_in_days');
    }

    public function test_cannot_revoke_other_users_token(): void
    {
        $othersToken = User::factory()->create()->createToken('他人の')->accessToken;
        Sanctum::actingAs(User::factory()->create());

        $response = $this->deleteJson("/api/tokens/{$othersToken->id}");

        $response->assertNotFound();
        $this->assertDatabaseHas('personal_access_tokens', ['id' => $othersToken->id]);
    }

    public function test_guest_cannot_list_tokens(): void
    {
        $response = $this->getJson('/api/tokens');

        $response->assertUnauthorized();
    }

    public function test_expired_token_is_rejected(): void
    {
        $user = User::factory()->create();
        $plain = $user->createToken('期限切れ', ['*'], now()->subDay())->plainTextToken;

        $response = $this->withHeader('Authorization', "Bearer {$plain}")->getJson('/api/tasks');

        $response->assertUnauthorized();
    }
}
