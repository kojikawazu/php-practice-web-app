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
 * 読み比べ（`docs/12-code-reading-guide.md`）: laravel-api は同じリミッタ定義で 429 の JSON を
 * 返すのに対し、fullstack は web ルートのためログイン失敗が 302（入力エラーで戻る）になり、
 * 上限超過だけが 429 になる。**同じ設定でも表に出る形が違う**のがスタック差分として読める。
 */
class RateLimitTest extends TestCase
{
    use RefreshDatabase;

    private const LOGIN_PER_EMAIL = 5;

    private const REGISTER_PER_IP = 120;

    /** 誤ったパスワードでログインを試みる（web なので失敗は 302 で戻る） */
    private function attemptLogin(string $email): TestResponse
    {
        return $this->post('/login', ['email' => $email, 'password' => 'wrong-password']);
    }

    // ---- 正常系（制限内は素通りする） ----

    public function test_login_is_not_throttled_within_the_limit(): void
    {
        User::factory()->create(['email' => 'victim@example.com', 'password' => Hash::make('correct-password')]);

        for ($i = 0; $i < self::LOGIN_PER_EMAIL - 1; $i++) {
            $this->attemptLogin('victim@example.com')->assertStatus(302);
        }

        $this->post('/login', ['email' => 'victim@example.com', 'password' => 'correct-password'])
            ->assertRedirect(route('tasks.index'));
        $this->assertAuthenticated();
    }

    // ---- 準正常系（上限を超えたら止まる） ----

    public function test_login_is_throttled_after_exceeding_the_per_email_limit(): void
    {
        for ($i = 0; $i < self::LOGIN_PER_EMAIL; $i++) {
            $this->attemptLogin('victim@example.com')->assertStatus(302);
        }

        $this->attemptLogin('victim@example.com')->assertStatus(429);
    }

    public function test_login_throttle_is_scoped_to_the_email_not_the_whole_ip(): void
    {
        for ($i = 0; $i < self::LOGIN_PER_EMAIL; $i++) {
            $this->attemptLogin('victim@example.com')->assertStatus(302);
        }

        $this->attemptLogin('victim@example.com')->assertStatus(429);

        // 同じ IP でも別アカウントは巻き込まれない
        $this->attemptLogin('someone-else@example.com')->assertStatus(302);
    }

    public function test_login_throttle_ignores_email_case(): void
    {
        for ($i = 0; $i < self::LOGIN_PER_EMAIL; $i++) {
            $this->attemptLogin('victim@example.com')->assertStatus(302);
        }

        $this->attemptLogin('VICTIM@example.com')->assertStatus(429);
    }

    public function test_throttled_login_does_not_authenticate_even_with_correct_password(): void
    {
        User::factory()->create(['email' => 'victim@example.com', 'password' => Hash::make('correct-password')]);

        for ($i = 0; $i < self::LOGIN_PER_EMAIL; $i++) {
            $this->attemptLogin('victim@example.com');
        }

        // 上限に達したら、正しいパスワードでも認証まで到達しない（ここが総当たり耐性の実体）
        $this->post('/login', ['email' => 'victim@example.com', 'password' => 'correct-password'])
            ->assertStatus(429);
        $this->assertGuest();
    }

    public function test_register_is_throttled_per_ip(): void
    {
        // 入力不備（302 で戻る）でも試行として数えられる。ユーザーを 60 件作らずに上限へ到達させる
        for ($i = 0; $i < self::REGISTER_PER_IP; $i++) {
            $this->post('/register', [])->assertStatus(302);
        }

        $this->post('/register', [])->assertStatus(429);
    }

    // ---- 異常系（不在ユーザーでも制限は効く） ----

    public function test_login_is_throttled_even_for_unknown_accounts(): void
    {
        for ($i = 0; $i < self::LOGIN_PER_EMAIL; $i++) {
            $this->attemptLogin('does-not-exist@example.com')->assertStatus(302);
        }

        $this->attemptLogin('does-not-exist@example.com')->assertStatus(429);
    }
}
