<?php

declare(strict_types=1);

namespace ApplicationTest\Integration;

use Application\Service\CsrfGuard;

/**
 * CSRF 対策の統合テスト（IT）。
 *
 * セッション認証のアプリは、外部サイトからの偽装リクエストで状態を変えられてはならない。
 * 本アプリは 2 段で防ぐ（`docs/06`）:
 *
 * 1. 状態を変えるアクションを POST に限定する（GET は 405）。`<img src>` を踏ませるだけでは動かない
 * 2. 全 POST を Module::onBootstrap の一括リスナーで検証する（トークン不正は 403）
 *
 * 2 の「一括」が肝で、各アクションで個別に呼ぶ形にすると、新しい POST を足したときに
 * 書き忘れがそのまま無防備になる。ここではその一括適用も検証する。
 */
class CsrfProtectionIntegrationTest extends AbstractIntegrationTestCase
{
    private const USER_ID = 1;

    // ---- 異常系: GET では状態が変わらない（405）----

    public function testGetCannotDeleteTask(): void
    {
        $id = $this->seedTask(self::USER_ID, '消されたくない');
        $this->prepareServices($this->identity(self::USER_ID));

        $this->dispatch('/tasks/delete/' . $id, 'GET');

        $this->assertResponseStatusCode(405);
        $this->assertNotNull($this->taskTable->getForUser($id, self::USER_ID), 'GET で削除されてはならない');
    }

    public function testGetCannotDuplicateTask(): void
    {
        $id = $this->seedTask(self::USER_ID, '原本');
        $this->prepareServices($this->identity(self::USER_ID));

        $this->dispatch('/tasks/duplicate/' . $id, 'GET');

        $this->assertResponseStatusCode(405);
        $this->assertSame(1, $this->taskTable->countByUser(self::USER_ID, ''), 'GET で複製されてはならない');
    }

    public function testGetCannotToggleTask(): void
    {
        $id = $this->seedTask(self::USER_ID, '勝手に完了にされない');
        $this->prepareServices($this->identity(self::USER_ID));

        $this->dispatch('/tasks/toggle/' . $id, 'GET');

        $this->assertResponseStatusCode(405);
        $this->assertFalse($this->taskTable->getForUser($id, self::USER_ID)?->done, 'GET で切り替わってはならない');
    }

    public function testToggleWithoutTokenIsRejected(): void
    {
        $id = $this->seedTask(self::USER_ID, '勝手に完了にされない');
        $this->prepareServices($this->identity(self::USER_ID));

        $this->dispatch('/tasks/toggle/' . $id, 'POST', []);

        $this->assertResponseStatusCode(403);
        $this->assertFalse($this->taskTable->getForUser($id, self::USER_ID)?->done);
    }

    public function testGetCannotLogout(): void
    {
        $this->prepareServices($this->identity(self::USER_ID));

        $this->dispatch('/logout', 'GET');

        $this->assertResponseStatusCode(405);
    }

    public function testMethodNotAllowedResponseAdvertisesPost(): void
    {
        $this->prepareServices($this->identity(self::USER_ID));

        $this->dispatch('/logout', 'GET');

        $allow = $this->getResponse()->getHeaders()->get('Allow');
        $this->assertNotFalse($allow, '405 は Allow ヘッダーで許可メソッドを示す');
    }

    // ---- 異常系: トークンが無い / 不正な POST は拒否（403）----

    public function testPostWithoutTokenIsRejected(): void
    {
        $this->prepareServices($this->identity(self::USER_ID));

        $this->dispatch('/tasks', 'POST', ['title' => 'トークン無し']);

        $this->assertResponseStatusCode(403);
        $this->assertSame(0, $this->taskTable->countByUser(self::USER_ID, ''), 'タスクは作成されない');
    }

    public function testPostWithWrongTokenIsRejected(): void
    {
        $this->prepareServices($this->identity(self::USER_ID));

        $this->dispatch('/tasks', 'POST', ['title' => '偽トークン', CsrfGuard::FIELD => 'not-a-valid-token']);

        $this->assertResponseStatusCode(403);
        $this->assertSame(0, $this->taskTable->countByUser(self::USER_ID, ''));
    }

    public function testDeleteWithoutTokenIsRejected(): void
    {
        $id = $this->seedTask(self::USER_ID, '消されたくない');
        $this->prepareServices($this->identity(self::USER_ID));

        $this->dispatch('/tasks/delete/' . $id, 'POST', []);

        $this->assertResponseStatusCode(403);
        $this->assertNotNull($this->taskTable->getForUser($id, self::USER_ID));
    }

    public function testLoginWithoutTokenIsRejected(): void
    {
        $this->userTable->create('alice', $this->hasher->hash('password123'));
        $this->prepareServices(null);

        $this->dispatch('/login', 'POST', ['username' => 'alice', 'password' => 'password123']);

        $this->assertResponseStatusCode(403);
    }

    public function testRegisterWithoutTokenIsRejected(): void
    {
        $this->prepareServices(null);

        $this->dispatch('/register', 'POST', ['username' => 'bob', 'password' => 'password123']);

        $this->assertResponseStatusCode(403);
        $this->assertNull($this->userTable->findByUsername('bob'), 'ユーザーは作成されない');
    }

    // ---- 正常系: 正しいトークンなら通る ----

    public function testPostWithValidTokenSucceeds(): void
    {
        $this->prepareServices($this->identity(self::USER_ID));

        $this->dispatch('/tasks', 'POST', $this->withCsrf(['title' => '正しいトークン']));

        $this->assertResponseStatusCode(302);
        $this->assertSame(1, $this->taskTable->countByUser(self::USER_ID, ''));
    }

    public function testDeleteWithValidTokenSucceeds(): void
    {
        $id = $this->seedTask(self::USER_ID, '消える');
        $this->prepareServices($this->identity(self::USER_ID));

        $this->dispatch('/tasks/delete/' . $id, 'POST', $this->withCsrf());

        $this->assertResponseStatusCode(302);
        $this->assertNull($this->taskTable->getForUser($id, self::USER_ID));
    }

    public function testLogoutWithValidTokenSucceeds(): void
    {
        $this->prepareServices($this->identity(self::USER_ID));

        $this->dispatch('/logout', 'POST', $this->withCsrf());

        $this->assertResponseStatusCode(302);
        $this->assertRedirectTo('/login');
    }

    // ---- 画面にトークンが埋め込まれること ----

    public function testFormsRenderCsrfToken(): void
    {
        $this->prepareServices(null);
        $this->dispatch('/login');

        $this->assertResponseStatusCode(200);
        $this->assertStringContainsString(
            'name="' . CsrfGuard::FIELD . '"',
            (string) $this->getResponse()->getContent(),
            'フォームに CSRF の hidden が無い'
        );
    }

    public function testTaskListRendersPostFormsForStateChanges(): void
    {
        $this->seedTask(self::USER_ID, 'A-1');
        $this->prepareServices($this->identity(self::USER_ID));
        $this->dispatch('/tasks');

        $html = (string) $this->getResponse()->getContent();

        // 状態を変える導線は GET リンクではなく POST フォームであること
        $this->assertStringNotContainsString('<a href="/tasks/delete/', $html);
        $this->assertStringNotContainsString('<a href="/tasks/duplicate/', $html);
        $this->assertStringNotContainsString('<a href="/logout"', $html);
        $this->assertStringContainsString('action="/tasks/delete/', $html);
        $this->assertStringContainsString('action="/logout"', $html);
    }
}
