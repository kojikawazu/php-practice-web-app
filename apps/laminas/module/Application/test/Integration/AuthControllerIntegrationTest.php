<?php

declare(strict_types=1);

namespace ApplicationTest\Integration;

use Application\Controller\AuthController;

/**
 * AuthController の統合テスト（IT）。
 *
 * ルーティング → AuthController → InputFilter（検証）→ UserTable → DB → PasswordHasher（bcrypt）
 * を通しで検証する。laminas のセッション認証フローは従来ライブ smoke 中心だったが、
 * ここで register / login の「配線と bcrypt 照合」を PHPUnit で担保する
 * （実セッション永続は NonPersistent ストレージで代替し、対象外とする）。
 *
 * セッション固定攻撃対策（docs/06「セッション管理」）については、この層で
 * 「認証に成功した経路でだけ・identity を書く前に再生成が走る」ことを検証する。
 * ID が実際に変わることは ext/session の挙動なので E2E 側で担保する。
 */
class AuthControllerIntegrationTest extends AbstractIntegrationTestCase
{
    // ---- 正常系 ----

    public function testRegisterCreatesUserAndRedirects(): void
    {
        $this->prepareServices(null);
        $this->dispatch('/register', 'POST', $this->withCsrf([
            'username' => 'bob',
            'password' => 'password123',
        ]));

        $this->assertResponseStatusCode(302);
        $this->assertControllerName(AuthController::class);
        $this->assertRedirectTo('/tasks');

        $user = $this->userTable->findByUsername('bob');
        $this->assertNotNull($user);
        // パスワードは平文で保存されない（bcrypt ハッシュで検証できる）
        $this->assertNotSame('password123', $user->password);
        $this->assertTrue($this->hasher->verify('password123', (string) $user->password));
    }

    public function testLoginWithCorrectCredentialsRedirectsToTasks(): void
    {
        $this->userTable->create('alice', $this->hasher->hash('password123'));

        $this->prepareServices(null);
        $this->dispatch('/login', 'POST', $this->withCsrf([
            'username' => 'alice',
            'password' => 'password123',
        ]));

        $this->assertResponseStatusCode(302);
        $this->assertRedirectTo('/tasks');
    }

    public function testLogoutRedirectsToLogin(): void
    {
        $this->prepareServices($this->identity(1, 'alice'));
        $this->dispatch('/logout', 'POST', $this->withCsrf());

        $this->assertResponseStatusCode(302);
        $this->assertRedirectTo('/login');
    }

    // ---- 準正常系・異常系 ----

    public function testRegisterWithDuplicateUsernameIsRejected(): void
    {
        $this->userTable->create('alice', $this->hasher->hash('password123'));

        $this->prepareServices(null);
        $this->dispatch('/register', 'POST', $this->withCsrf([
            'username' => 'alice',
            'password' => 'anotherpass',
        ]));

        // 重複はエラー表示で一覧再描画（リダイレクトしない）
        $this->assertNotRedirect();
        $this->assertResponseStatusCode(200);
        // 既存ユーザーのパスワードは上書きされていない
        $user = $this->userTable->findByUsername('alice');
        $this->assertNotNull($user);
        $this->assertTrue($this->hasher->verify('password123', (string) $user->password));
    }

    public function testRegisterWithShortPasswordCreatesNoUser(): void
    {
        $this->prepareServices(null);
        $this->dispatch('/register', 'POST', $this->withCsrf([
            'username' => 'charlie',
            'password' => 'short',
        ]));

        $this->assertNotRedirect();
        $this->assertResponseStatusCode(200);
        $this->assertNull($this->userTable->findByUsername('charlie'));
    }

    public function testLoginWithWrongPasswordIsRejected(): void
    {
        $this->userTable->create('alice', $this->hasher->hash('password123'));

        $this->prepareServices(null);
        $this->dispatch('/login', 'POST', $this->withCsrf([
            'username' => 'alice',
            'password' => 'wrongpass',
        ]));

        $this->assertNotRedirect();
        $this->assertResponseStatusCode(200);
    }

    public function testLoginWithUnknownUserIsRejected(): void
    {
        $this->prepareServices(null);
        $this->dispatch('/login', 'POST', $this->withCsrf([
            'username' => 'nobody',
            'password' => 'password123',
        ]));

        $this->assertNotRedirect();
        $this->assertResponseStatusCode(200);
    }

    // ---- セッション固定攻撃対策: 認証成功時にセッション ID を再生成する ----

    public function testLoginRegeneratesSessionIdBeforeWritingIdentity(): void
    {
        $this->userTable->create('alice', $this->hasher->hash('password123'));

        $this->prepareServices(null);
        $this->dispatch('/login', 'POST', $this->withCsrf([
            'username' => 'alice',
            'password' => 'password123',
        ]));

        $this->assertSame(['regenerate'], $this->authSession->calls());
        // 再生成の時点ではまだ identity が無い＝認証済み状態は新しい ID の下でしか存在しない
        $this->assertSame([false], $this->authSession->identityPresentOnRegenerate());
    }

    public function testRegisterRegeneratesSessionIdBeforeWritingIdentity(): void
    {
        $this->prepareServices(null);
        $this->dispatch('/register', 'POST', $this->withCsrf([
            'username' => 'bob',
            'password' => 'password123',
        ]));

        $this->assertSame(['regenerate'], $this->authSession->calls());
        $this->assertSame([false], $this->authSession->identityPresentOnRegenerate());
    }

    public function testLogoutInvalidatesSession(): void
    {
        $this->prepareServices($this->identity(1, 'alice'));
        $this->dispatch('/logout', 'POST', $this->withCsrf());

        // identity を消すだけでなくセッションごと作り直す（CSRF トークンも失効させる）
        $this->assertSame(['invalidate'], $this->authSession->calls());
    }

    public function testLoginWithWrongPasswordDoesNotRegenerate(): void
    {
        $this->userTable->create('alice', $this->hasher->hash('password123'));

        $this->prepareServices(null);
        $this->dispatch('/login', 'POST', $this->withCsrf([
            'username' => 'alice',
            'password' => 'wrongpass',
        ]));

        $this->assertSame([], $this->authSession->calls());
    }

    public function testLoginWithUnknownUserDoesNotRegenerate(): void
    {
        $this->prepareServices(null);
        $this->dispatch('/login', 'POST', $this->withCsrf([
            'username' => 'nobody',
            'password' => 'password123',
        ]));

        $this->assertSame([], $this->authSession->calls());
    }

    public function testRegisterWithShortPasswordDoesNotRegenerate(): void
    {
        $this->prepareServices(null);
        $this->dispatch('/register', 'POST', $this->withCsrf([
            'username' => 'charlie',
            'password' => 'short',
        ]));

        $this->assertSame([], $this->authSession->calls());
    }

    public function testRegisterWithDuplicateUsernameDoesNotRegenerate(): void
    {
        $this->userTable->create('alice', $this->hasher->hash('password123'));

        $this->prepareServices(null);
        $this->dispatch('/register', 'POST', $this->withCsrf([
            'username' => 'alice',
            'password' => 'anotherpass',
        ]));

        $this->assertSame([], $this->authSession->calls());
    }

    public function testLoginRejectedByCsrfDoesNotRegenerate(): void
    {
        $this->userTable->create('alice', $this->hasher->hash('password123'));

        $this->prepareServices(null);
        // CSRF で 403 になる経路は dispatch まで進まない。再生成も起きない
        $this->dispatch('/login', 'POST', [
            'username' => 'alice',
            'password' => 'password123',
        ]);

        $this->assertResponseStatusCode(403);
        $this->assertSame([], $this->authSession->calls());
    }

    public function testLogoutRejectedByCsrfDoesNotInvalidate(): void
    {
        $this->prepareServices($this->identity(1, 'alice'));
        $this->dispatch('/logout', 'POST', []);

        $this->assertResponseStatusCode(403);
        $this->assertSame([], $this->authSession->calls());
    }

    public function testGetLogoutDoesNotInvalidate(): void
    {
        $this->prepareServices($this->identity(1, 'alice'));
        $this->dispatch('/logout', 'GET');

        $this->assertResponseStatusCode(405);
        $this->assertSame([], $this->authSession->calls());
    }

    public function testAlreadyAuthenticatedLoginPageDoesNotRegenerate(): void
    {
        $this->prepareServices($this->identity(1, 'alice'));
        $this->dispatch('/login', 'GET');

        $this->assertRedirectTo('/tasks');
        $this->assertSame([], $this->authSession->calls());
    }

    // ---- 監査列（created_at）----
    //
    // 単体（UserTableTest）は UserTable を直接叩くため「集約先が正しく書く」ことしか見ない。
    // ここでは実際のリクエスト経路（ルーティング → AuthController → InputFilter → UserTable）を
    // 通して、コントローラが何も詰めていなくても監査列が入ることを確認する
    // （.claude/rules/php.md「監査列は単一の層で自動設定し、業務ロジックから手で書かない」）。

    public function testRegisterActionSetsCreatedAt(): void
    {
        $this->prepareServices(null);
        $this->dispatch('/register', 'POST', $this->withCsrf([
            'username' => 'bob',
            'password' => 'password123',
        ]));

        $this->assertResponseStatusCode(302);

        $createdAt = $this->createdAtOf('bob');
        $this->assertNotNull($createdAt);
        // 'Y-m-d H:i:s' でないと本番の MySQL（TIMESTAMP）で落ちる
        $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $createdAt);
    }

    /**
     * 監査列を raw SQL で読む（UserTable の戻り値ではなく列の実体を見る）。
     *
     * @return ?string 行が無ければ fail する
     */
    private function createdAtOf(string $username): ?string
    {
        /** @var iterable<\ArrayAccess<string, mixed>> $rows */
        $rows = $this->adapter->query(
            'SELECT created_at FROM lam_users WHERE username = ?',
            [$username]
        );

        foreach ($rows as $row) {
            return isset($row['created_at']) ? (string) $row['created_at'] : null;
        }

        self::fail("username={$username} の行が見つからない");
    }
}
