<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * 認証系エンドポイントのレートリミット（issue #141・`.claude/rules/security.md`）。
 *
 * 守りたいのは「1 アカウントへの総当たりを止める」ことなので、**キーが何で区切られているか**を
 * 明示的に検証する。回数だけを見て「落ちた＝OK」にすると、IP 単位で丸ごと止めてしまう実装でも
 * テストが通ってしまい、E2E や同一 NAT 配下の利用者を巻き込む設計を見逃す。
 *
 * リミッタの定義は `AppServiceProvider::boot()`。キャッシュは phpunit.xml で array のため、
 * テストごとにカウントが持ち越されない。
 */
class RateLimitTest extends TestCase
{
    use RefreshDatabase;

    private const LOGIN_PER_EMAIL = 5;

    private const REGISTER_PER_IP = 60;

    private const TOKENS_PER_USER = 30;

    /** 誤ったパスワードでログインを試みる（422 が返る＝まだ絞られていない） */
    private function attemptLogin(string $email): TestResponse
    {
        return $this->postJson('/api/login', ['email' => $email, 'password' => 'wrong-password']);
    }

    // ---- 正常系（制限内は素通りする） ----

    public function test_login_is_not_throttled_within_the_limit(): void
    {
        User::factory()->create(['email' => 'victim@example.com', 'password' => Hash::make('correct-password')]);

        // 上限手前まで失敗させてから、正しい認証情報でログインできることを見る
        for ($i = 0; $i < self::LOGIN_PER_EMAIL - 1; $i++) {
            $this->attemptLogin('victim@example.com')->assertStatus(422);
        }

        $this->postJson('/api/login', ['email' => 'victim@example.com', 'password' => 'correct-password'])
            ->assertStatus(200)
            ->assertJsonStructure(['user', 'token']);
    }

    // ---- 準正常系（上限を超えたら止まる） ----

    public function test_login_is_throttled_after_exceeding_the_per_email_limit(): void
    {
        for ($i = 0; $i < self::LOGIN_PER_EMAIL; $i++) {
            $this->attemptLogin('victim@example.com')->assertStatus(422);
        }

        $this->attemptLogin('victim@example.com')->assertStatus(429);
    }

    public function test_login_throttle_is_scoped_to_the_email_not_the_whole_ip(): void
    {
        for ($i = 0; $i < self::LOGIN_PER_EMAIL; $i++) {
            $this->attemptLogin('victim@example.com')->assertStatus(422);
        }

        $this->attemptLogin('victim@example.com')->assertStatus(429);

        // 同じ IP でも別アカウントは巻き込まれない（E2E がユニークな email を使う前提の根拠）
        $this->attemptLogin('someone-else@example.com')->assertStatus(422);
    }

    public function test_login_throttle_ignores_email_case(): void
    {
        for ($i = 0; $i < self::LOGIN_PER_EMAIL; $i++) {
            $this->attemptLogin('victim@example.com')->assertStatus(422);
        }

        // 大文字にしても同じアカウントへの試行として数える（小文字化しないと素通りする）
        $this->attemptLogin('VICTIM@example.com')->assertStatus(429);
    }

    public function test_throttled_response_tells_when_to_retry(): void
    {
        for ($i = 0; $i < self::LOGIN_PER_EMAIL; $i++) {
            $this->attemptLogin('victim@example.com');
        }

        $response = $this->attemptLogin('victim@example.com');

        $response->assertStatus(429)
            ->assertHeader('Retry-After')
            ->assertHeader('X-RateLimit-Limit');
    }

    public function test_register_is_throttled_per_ip(): void
    {
        // 入力不備（422）でも試行として数えられる。ユーザーを 60 件作らずに上限へ到達させる
        for ($i = 0; $i < self::REGISTER_PER_IP; $i++) {
            $this->postJson('/api/register', [])->assertStatus(422);
        }

        $this->postJson('/api/register', [])->assertStatus(429);
    }

    public function test_token_issuance_is_throttled_per_user(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user, 'sanctum');

        for ($i = 0; $i < self::TOKENS_PER_USER; $i++) {
            $this->postJson('/api/tokens', [])->assertStatus(422);
        }

        $this->postJson('/api/tokens', [])->assertStatus(429);
    }

    public function test_token_issuance_throttle_is_scoped_to_the_user(): void
    {
        $victim = User::factory()->create();
        $this->actingAs($victim, 'sanctum');

        for ($i = 0; $i < self::TOKENS_PER_USER; $i++) {
            $this->postJson('/api/tokens', []);
        }

        $this->postJson('/api/tokens', [])->assertStatus(429);

        // 同一 IP の別ユーザーは影響を受けない
        $this->actingAs(User::factory()->create(), 'sanctum');
        $this->postJson('/api/tokens', [])->assertStatus(422);
    }

    // ---- 異常系（未認証・不在ユーザーでも制限は効く） ----

    public function test_unauthenticated_token_request_is_rejected_before_the_limiter_runs(): void
    {
        // tokens リミッタは $request->user() を参照する。auth:sanctum より先に評価されると
        // null 参照で 500 になるため、未認証は 401 で止まることを固定する。
        $this->postJson('/api/tokens', [])->assertStatus(401);
    }

    public function test_login_is_throttled_even_for_unknown_accounts(): void
    {
        // 存在しないアカウントでも数える。数えないと「存在しない前提で総当たり」が素通りする
        for ($i = 0; $i < self::LOGIN_PER_EMAIL; $i++) {
            $this->attemptLogin('does-not-exist@example.com')->assertStatus(422);
        }

        $this->attemptLogin('does-not-exist@example.com')->assertStatus(429);
    }
}
