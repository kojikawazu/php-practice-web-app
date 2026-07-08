<?php

declare(strict_types=1);

namespace ApplicationTest\Model;

use Application\Model\Task;
use Application\Model\TaskTable;
use Laminas\Db\Adapter\Adapter;
use Laminas\Db\ResultSet\ResultSet;
use Laminas\Db\TableGateway\TableGateway;
use PHPUnit\Framework\TestCase;

/**
 * TaskTable の所有者スコープ（認可）を SQLite in-memory で検証する。
 *
 * 認可の実体は「TaskTable の各メソッドが SQL レベルで user_id を条件に含めること」にある。
 * ここでは MySQL コンテナを使わず、TableGateway に SQLite アダプタを差して
 * 「他人のタスクは取得・更新・削除できない」ことを実データで確認する（docs/11 #8 の PHPUnit 化）。
 */
class TaskTableTest extends TestCase
{
    private const USER_A = 1;
    private const USER_B = 2;

    private TaskTable $table;

    protected function setUp(): void
    {
        $adapter = new Adapter([
            'driver'   => 'Pdo_Sqlite',
            'database' => ':memory:',
        ]);

        // lam_tasks の SQLite 相当スキーマ（MySQL 版と同じ列構成）
        $adapter->query(
            'CREATE TABLE lam_tasks (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                user_id INTEGER NOT NULL,
                title TEXT NOT NULL,
                done INTEGER NOT NULL DEFAULT 0,
                start_date TEXT NULL,
                end_date TEXT NULL,
                created_at TEXT NULL,
                updated_at TEXT NULL
            )',
            Adapter::QUERY_MODE_EXECUTE
        );

        $resultSetPrototype = new ResultSet();
        // Task は ArrayObject 派生ではないが exchangeArray を持ち、Laminas の ResultSet
        // プロトタイプとして機能する（本番 TaskTableFactory と同じ扱い）。
        /** @psalm-suppress InvalidArgument */
        $resultSetPrototype->setArrayObjectPrototype(new Task());
        $tableGateway = new TableGateway('lam_tasks', $adapter, null, $resultSetPrototype);

        $this->table = new TaskTable($tableGateway);
    }

    /** テスト用にタスクを1件作成して id を返す */
    private function seed(int $userId, string $title): int
    {
        $task = new Task();
        $task->title = $title;
        $task->user_id = $userId;
        $this->table->saveTask($task);

        // 直近に挿入した本人タスクの最大 id を返す（fetchAllByUser は id DESC）
        return (int) $this->collect($this->table->fetchAllByUser($userId))[0]->id;
    }

    /**
     * iterable<Task> を要素型を保ったまま配列化する（Psalm 向けに Task 型を維持）。
     *
     * @param  iterable<Task> $rows
     * @return list<Task>
     */
    private function collect(iterable $rows): array
    {
        $out = [];
        foreach ($rows as $row) {
            $out[] = $row;
        }

        return $out;
    }

    // ---- 正常系 ----

    public function testSaveTaskInsertsNewTask(): void
    {
        $id = $this->seed(self::USER_A, '牛乳を買う');

        $task = $this->table->getForUser($id, self::USER_A);
        $this->assertNotNull($task);
        $this->assertSame('牛乳を買う', $task->title);
        $this->assertSame(self::USER_A, $task->user_id);
    }

    public function testGetForUserReturnsOwnTask(): void
    {
        $id = $this->seed(self::USER_A, '自分のタスク');

        $this->assertNotNull($this->table->getForUser($id, self::USER_A));
    }

    public function testFetchAllByUserReturnsOnlyOwnTasks(): void
    {
        $this->seed(self::USER_A, 'A-1');
        $this->seed(self::USER_A, 'A-2');
        $this->seed(self::USER_B, 'B-1');

        $titles = array_map(
            static fn (Task $t): string => $t->title,
            $this->collect($this->table->fetchAllByUser(self::USER_A))
        );

        $this->assertCount(2, $titles);
        $this->assertContains('A-1', $titles);
        $this->assertContains('A-2', $titles);
        $this->assertNotContains('B-1', $titles);
    }

    public function testSaveTaskUpdatesOwnTask(): void
    {
        $id = $this->seed(self::USER_A, '変更前');

        $task = $this->table->getForUser($id, self::USER_A);
        $this->assertNotNull($task);
        $task->title = '変更後';
        $this->table->saveTask($task);

        $updated = $this->table->getForUser($id, self::USER_A);
        $this->assertNotNull($updated);
        $this->assertSame('変更後', $updated->title);
    }

    public function testCountByUserSearchFiltersByTitle(): void
    {
        $this->seed(self::USER_A, '買い物リスト');
        $this->seed(self::USER_A, '掃除');

        $this->assertSame(1, $this->table->countByUser(self::USER_A, '買い物'));
        $this->assertSame(2, $this->table->countByUser(self::USER_A, ''));
    }

    public function testDeleteForUserRemovesOwnTask(): void
    {
        $id = $this->seed(self::USER_A, '削除対象');

        $this->table->deleteForUser($id, self::USER_A);

        $this->assertNull($this->table->getForUser($id, self::USER_A));
    }

    // ---- 準正常系・異常系（他人のタスクは操作できない）----

    public function testGetForUserReturnsNullForOtherUsersTask(): void
    {
        $id = $this->seed(self::USER_B, '他人のタスク');

        // USER_A から USER_B のタスク id を指定しても取得できない
        $this->assertNull($this->table->getForUser($id, self::USER_A));
    }

    public function testDeleteForUserDoesNotDeleteOtherUsersTask(): void
    {
        $id = $this->seed(self::USER_B, '他人のタスク');

        // USER_A が USER_B のタスク id を削除しようとしても消えない
        $this->table->deleteForUser($id, self::USER_A);

        $this->assertNotNull($this->table->getForUser($id, self::USER_B));
    }

    public function testSaveTaskUpdateDoesNotAffectOtherUsersTask(): void
    {
        $id = $this->seed(self::USER_B, '他人のタスク');

        // USER_B のタスクを、user_id を偽装して USER_A として更新しようとする
        $forged = new Task();
        $forged->id = $id;
        $forged->title = '乗っ取り';
        $forged->user_id = self::USER_A;
        $this->table->saveTask($forged);

        // 元の USER_B のタスクは書き換わっていない（update は id AND user_id でスコープ）
        $original = $this->table->getForUser($id, self::USER_B);
        $this->assertNotNull($original);
        $this->assertSame('他人のタスク', $original->title);
    }

    public function testCountByUserExcludesOtherUsersTasks(): void
    {
        $this->seed(self::USER_A, 'A-1');
        $this->seed(self::USER_B, 'B-1');
        $this->seed(self::USER_B, 'B-2');

        $this->assertSame(1, $this->table->countByUser(self::USER_A, ''));
    }

    public function testFetchPageByUserExcludesOtherUsersTasks(): void
    {
        $this->seed(self::USER_A, 'A-1');
        $this->seed(self::USER_B, 'B-1');

        $rows = $this->collect($this->table->fetchPageByUser(self::USER_A, 5, 0, ''));

        $this->assertCount(1, $rows);
        $this->assertSame('A-1', $rows[0]->title);
    }

    public function testFetchPageByUserSearchWithNoMatchReturnsEmpty(): void
    {
        $this->seed(self::USER_A, '掃除');

        $rows = $this->collect($this->table->fetchPageByUser(self::USER_A, 5, 0, '存在しない語'));

        $this->assertCount(0, $rows);
    }
}
