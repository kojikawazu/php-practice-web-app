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
}
