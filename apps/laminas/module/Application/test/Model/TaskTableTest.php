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

    /** 監査列は Task モデルに載らないため、検証時に raw SQL で読む */
    private Adapter $adapter;

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

        $this->table   = new TaskTable($tableGateway);
        $this->adapter = $adapter;
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
     * 監査列を任意の日時で埋めたタスクを作る。
     *
     * saveTask が書く現在時刻は秒単位のため、同一テスト内で 2 回保存しても値が変わらず
     * 「更新で updated_at が進む」を確認できない。過去の日時を明示的に置いてから更新を掛ける
     * （.claude/rules/php.md の例外「シード・テストで日時を固定する場合のみ明示指定を許容する」）。
     */
    private function seedWithAudit(int $userId, string $title, string $at): int
    {
        $id = $this->seed($userId, $title);

        $this->adapter->query(
            'UPDATE lam_tasks SET created_at = ?, updated_at = ? WHERE id = ?',
            [$at, $at, $id]
        );

        return $id;
    }

    /**
     * 監査列を raw SQL で読む。
     *
     * Task モデルは created_at / updated_at を持たない（表示需要がなく、業務コードから
     * 代入する経路を作らないため）。そのためテーブルから直接読んで検証する。
     *
     * @return array{created_at: ?string, updated_at: ?string}
     */
    private function auditColumns(int $id): array
    {
        /** @var iterable<\ArrayAccess<string, mixed>> $rows */
        $rows = $this->adapter->query(
            'SELECT created_at, updated_at FROM lam_tasks WHERE id = ?',
            [$id]
        );

        foreach ($rows as $row) {
            return [
                'created_at' => isset($row['created_at']) ? (string) $row['created_at'] : null,
                'updated_at' => isset($row['updated_at']) ? (string) $row['updated_at'] : null,
            ];
        }

        self::fail("id={$id} の行が見つからない");
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

    // ---- 監査列（created_at / updated_at）----
    //
    // TableGateway に Eloquent の自動タイムスタンプ相当が無いため、設定漏れは
    // 「保存はできるが日時だけ NULL」という静かな形で現れる（issue #74）。
    // 設定責務は TaskTable に集約されている（.claude/rules/php.md / docs/05）。

    public function testSaveTaskSetsBothAuditColumnsOnInsert(): void
    {
        $id = $this->seed(self::USER_A, '新規作成');

        $audit = $this->auditColumns($id);

        $this->assertNotNull($audit['created_at']);
        $this->assertNotNull($audit['updated_at']);
        // 作成時は同一の値を両列へ入れる（別々に now() を呼ぶと秒をまたいでずれ得る）
        $this->assertSame($audit['created_at'], $audit['updated_at']);
    }

    public function testAuditColumnsUseMysqlTimestampFormat(): void
    {
        $id = $this->seed(self::USER_A, '形式確認');

        $audit = $this->auditColumns($id);

        // 'Y-m-d H:i:s' を外すと、TEXT 列の SQLite では通るのに本番の MySQL
        // （TIMESTAMP）で落ちる。テストが本番と食い違う典型なので形式を固定する。
        $pattern = '/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/';
        $this->assertMatchesRegularExpression($pattern, (string) $audit['created_at']);
        $this->assertMatchesRegularExpression($pattern, (string) $audit['updated_at']);
    }

    public function testSaveTaskUpdateAdvancesUpdatedAtButKeepsCreatedAt(): void
    {
        $past = '2020-01-01 00:00:00';
        $id   = $this->seedWithAudit(self::USER_A, '変更前', $past);

        $task = $this->table->getForUser($id, self::USER_A);
        $this->assertNotNull($task);
        $task->title = '変更後';
        $this->table->saveTask($task);

        $audit = $this->auditColumns($id);
        $this->assertSame($past, $audit['created_at'], 'created_at は更新処理で変えてはならない');
        $this->assertNotSame($past, $audit['updated_at'], 'updated_at は更新されなければならない');
    }

    public function testSaveTaskUpdateDoesNotTouchOtherUsersAuditColumns(): void
    {
        $past = '2020-01-01 00:00:00';
        $id   = $this->seedWithAudit(self::USER_B, '他人のタスク', $past);

        // USER_B のタスクを、user_id を偽装して USER_A として更新しようとする
        $forged          = new Task();
        $forged->id      = $id;
        $forged->title   = '乗っ取り';
        $forged->user_id = self::USER_A;
        $this->table->saveTask($forged);

        // 監査列を「別の UPDATE で後から進める」実装にすると user_id 条件が抜けて
        // ここが落ちる。認可と監査列の交差を守るためのケース。
        $audit = $this->auditColumns($id);
        $this->assertSame($past, $audit['created_at']);
        $this->assertSame($past, $audit['updated_at']);
    }

    public function testDuplicatedTaskGetsItsOwnCreatedAt(): void
    {
        $past     = '2020-01-01 00:00:00';
        $sourceId = $this->seedWithAudit(self::USER_A, '複製元', $past);

        $source = $this->table->getForUser($sourceId, self::USER_A);
        $this->assertNotNull($source);

        // TaskController::duplicateAction と同じ形（id を持たない Task を保存 = insert）
        $copy             = new Task();
        $copy->title      = mb_substr($source->title . '（コピー）', 0, 255);
        $copy->done       = false;
        $copy->user_id    = $source->user_id;
        $copy->start_date = $source->start_date;
        $copy->end_date   = $source->end_date;
        $this->table->saveTask($copy);

        // fetchAllByUser は id DESC のため先頭が複製されたタスク
        $copyId = (int) $this->collect($this->table->fetchAllByUser(self::USER_A))[0]->id;
        $audit  = $this->auditColumns($copyId);

        // assertNotSame だけだと「両方 NULL」でも通ってしまう（NULL は過去日時と一致しない）。
        // 監査列が設定されていること自体を先に固定する。
        $this->assertNotNull($audit['created_at']);
        $this->assertNotNull($audit['updated_at']);
        $this->assertNotSame($past, $audit['created_at'], '複製元の created_at を引き継がない');
        $this->assertSame($audit['created_at'], $audit['updated_at']);

        // 複製元の監査列は変わっていない
        $sourceAudit = $this->auditColumns($sourceId);
        $this->assertSame($past, $sourceAudit['created_at']);
        $this->assertSame($past, $sourceAudit['updated_at']);
    }
}
