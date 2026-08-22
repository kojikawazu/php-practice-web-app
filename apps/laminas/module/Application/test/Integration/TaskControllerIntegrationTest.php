<?php

declare(strict_types=1);

namespace ApplicationTest\Integration;

use Application\Controller\TaskController;

/**
 * TaskController の統合テスト（IT）。
 *
 * ルーティング → TaskController → AuthenticationService（認可分岐）→ TaskTable → DB を
 * 通しで検証する。SQL レベルの所有者スコープ自体は TaskTableTest（単体）が担保するため、
 * ここでは「コントローラの認可分岐・リダイレクト・DB への反映」という配線を主眼に置く。
 */
class TaskControllerIntegrationTest extends AbstractIntegrationTestCase
{
    private const USER_A = 1;
    private const USER_B = 2;

    // ---- 正常系（認証済み・本人操作）----

    public function testAuthenticatedIndexShowsOwnTasksOnly(): void
    {
        $this->seedTask(self::USER_A, 'A-1');
        $this->seedTask(self::USER_B, 'B-1');

        $this->prepareServices($this->identity(self::USER_A));
        $this->dispatch('/tasks', 'GET');

        $this->assertResponseStatusCode(200);
        $this->assertModuleName('application');
        $this->assertControllerName(TaskController::class);
        $this->assertQueryContentContains('ul li span', 'A-1');
        $this->assertNotQueryContentContains('ul li span', 'B-1');
    }

    public function testAuthenticatedPostCreatesTaskScopedToUser(): void
    {
        $this->prepareServices($this->identity(self::USER_A));
        $this->dispatch('/tasks', 'POST', $this->withCsrf(['title' => '牛乳を買う']));

        $this->assertResponseStatusCode(302);
        $this->assertRedirectTo('/tasks');

        $this->assertSame(1, $this->taskTable->countByUser(self::USER_A, ''));
        $this->assertSame(0, $this->taskTable->countByUser(self::USER_B, ''));
    }

    public function testAuthenticatedPostEditUpdatesOwnTask(): void
    {
        $id = $this->seedTask(self::USER_A, '変更前');

        $this->prepareServices($this->identity(self::USER_A));
        $this->dispatch('/tasks/edit/' . $id, 'POST', $this->withCsrf(['title' => '変更後']));

        $this->assertResponseStatusCode(302);
        $this->assertRedirectTo('/tasks');

        $task = $this->taskTable->getForUser($id, self::USER_A);
        $this->assertNotNull($task);
        $this->assertSame('変更後', $task->title);
    }

    public function testAuthenticatedDuplicateCreatesCopyForOwner(): void
    {
        $this->seedTask(self::USER_A, '牛乳を買う');
        $id = $this->seedTask(self::USER_A, '牛乳を買う');

        $this->prepareServices($this->identity(self::USER_A));
        $this->dispatch('/tasks/duplicate/' . $id, 'POST', $this->withCsrf());

        $this->assertResponseStatusCode(302);
        $this->assertRedirectTo('/tasks');

        $this->assertSame(3, $this->taskTable->countByUser(self::USER_A, ''));
        $this->assertSame(1, $this->taskTable->countByUser(self::USER_A, '（コピー）'));
    }

    public function testAuthenticatedDeleteRemovesOwnTask(): void
    {
        $id = $this->seedTask(self::USER_A, '削除対象');

        $this->prepareServices($this->identity(self::USER_A));
        $this->dispatch('/tasks/delete/' . $id, 'POST', $this->withCsrf());

        $this->assertResponseStatusCode(302);
        $this->assertRedirectTo('/tasks');
        $this->assertNull($this->taskTable->getForUser($id, self::USER_A));
    }

    // ---- 異常系（未認証：ゲストは保護ページへアクセスできない）----

    public function testGuestIndexRedirectsToLogin(): void
    {
        $this->prepareServices(null);
        $this->dispatch('/tasks', 'GET');

        $this->assertResponseStatusCode(302);
        $this->assertRedirectTo('/login');
    }

    public function testGuestPostCreateRedirectsToLoginAndCreatesNothing(): void
    {
        $this->prepareServices(null);
        $this->dispatch('/tasks', 'POST', $this->withCsrf(['title' => 'ゲストの作成']));

        $this->assertRedirectTo('/login');
        $this->assertSame(0, $this->taskTable->countByUser(self::USER_A, ''));
    }

    public function testGuestDeleteRedirectsToLogin(): void
    {
        $id = $this->seedTask(self::USER_A, '守られるべきタスク');

        $this->prepareServices(null);
        $this->dispatch('/tasks/delete/' . $id, 'POST', $this->withCsrf());

        $this->assertRedirectTo('/login');
        $this->assertNotNull($this->taskTable->getForUser($id, self::USER_A));
    }

    // ---- 異常系（認可：他人のタスクは操作できない）----

    public function testEditingOthersTaskRedirectsWithoutChange(): void
    {
        $id = $this->seedTask(self::USER_B, '他人のタスク');

        // USER_A として USER_B のタスクを編集しようとする
        $this->prepareServices($this->identity(self::USER_A));
        $this->dispatch('/tasks/edit/' . $id, 'POST', $this->withCsrf(['title' => '乗っ取り']));

        $this->assertResponseStatusCode(302);
        $this->assertRedirectTo('/tasks');

        $original = $this->taskTable->getForUser($id, self::USER_B);
        $this->assertNotNull($original);
        $this->assertSame('他人のタスク', $original->title);
    }

    public function testDeletingOthersTaskHasNoEffect(): void
    {
        $id = $this->seedTask(self::USER_B, '他人のタスク');

        $this->prepareServices($this->identity(self::USER_A));
        $this->dispatch('/tasks/delete/' . $id, 'POST', $this->withCsrf());

        $this->assertRedirectTo('/tasks');
        $this->assertNotNull($this->taskTable->getForUser($id, self::USER_B));
    }

    public function testDuplicatingOthersTaskCreatesNothing(): void
    {
        $id = $this->seedTask(self::USER_B, '他人のタスク');

        $this->prepareServices($this->identity(self::USER_A));
        $this->dispatch('/tasks/duplicate/' . $id, 'POST', $this->withCsrf());

        $this->assertRedirectTo('/tasks');
        $this->assertSame(0, $this->taskTable->countByUser(self::USER_A, ''));
        $this->assertSame(1, $this->taskTable->countByUser(self::USER_B, ''));
    }

    // ---- 準正常系（不正入力・境界）----

    public function testEmptyTitleDoesNotCreateTask(): void
    {
        $this->prepareServices($this->identity(self::USER_A));
        $this->dispatch('/tasks', 'POST', $this->withCsrf(['title' => '']));

        // バリデーション不合格：リダイレクトせず一覧を再描画し、タスクは作られない
        $this->assertNotRedirect();
        $this->assertResponseStatusCode(200);
        $this->assertSame(0, $this->taskTable->countByUser(self::USER_A, ''));
    }

    public function testEditingNonexistentTaskRedirectsToList(): void
    {
        $this->prepareServices($this->identity(self::USER_A));
        $this->dispatch('/tasks/edit/999', 'GET');

        $this->assertResponseStatusCode(302);
        $this->assertRedirectTo('/tasks');
    }
}
