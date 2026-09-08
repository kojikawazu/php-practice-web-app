<?php

declare(strict_types=1);

namespace ApplicationTest\Model;

use Application\Model\LoginAttemptTable;
use Laminas\Db\Adapter\Adapter;
use Laminas\Db\TableGateway\TableGateway;
use PHPUnit\Framework\TestCase;

/**
 * LoginAttemptTable を SQLite in-memory で検証する（issue #142）。
 *
 * 保存層の契約は 3 つ。**期間で区切って数える** / **成功したら捨てる** /
 * **期間外を掃除する**。Laravel はこれをキャッシュの TTL に任せられるが、laminas は
 * TTL が無いので自前で持つ。掃除が壊れると「行は増え続けるがテストは緑」という
 * 気づけない形で劣化するため、削除まで明示的に確かめる。
 */
class LoginAttemptTableTest extends TestCase
{
    private LoginAttemptTable $table;

    private Adapter $adapter;

    protected function setUp(): void
    {
        $adapter = new Adapter([
            'driver'   => 'Pdo_Sqlite',
            'database' => ':memory:',
        ]);

        // lam_login_attempts の SQLite 相当スキーマ（MySQL 版と同じ列構成）
        $adapter->query(
            'CREATE TABLE lam_login_attempts (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                attempt_key TEXT NOT NULL,
                attempted_at TEXT NOT NULL
            )',
            Adapter::QUERY_MODE_EXECUTE
        );

        $this->table   = new LoginAttemptTable(new TableGateway('lam_login_attempts', $adapter));
        $this->adapter = $adapter;
    }

    /** 期間の外側にある試行を、テーブルへ直接入れる */
    private function insertOldAttempt(string $key, int $secondsAgo): void
    {
        $moment = (new \DateTimeImmutable("-{$secondsAgo} seconds"))->format('Y-m-d H:i:s');

        $this->adapter->query(
            'INSERT INTO lam_login_attempts (attempt_key, attempted_at) VALUES (?, ?)',
            [$key, $moment]
        );
    }

    private function totalRows(): int
    {
        /** @var iterable<\ArrayAccess<string, mixed>> $rows */
        $rows = $this->adapter->query('SELECT COUNT(*) AS c FROM lam_login_attempts', Adapter::QUERY_MODE_EXECUTE);

        foreach ($rows as $row) {
            return (int) $row['c'];
        }

        self::fail('COUNT(*) が 1 行も返らなかった');
    }

    // ---- 正常系 ----

    public function testCountsAttemptsWithinTheWindow(): void
    {
        $this->table->record('login|alice|10.0.0.1');
        $this->table->record('login|alice|10.0.0.1');

        $this->assertSame(2, $this->table->countRecent('login|alice|10.0.0.1', 60));
    }

    public function testDoesNotBlockBelowTheLimit(): void
    {
        $this->table->record('login|alice|10.0.0.1');
        $this->table->record('login|alice|10.0.0.1');

        $this->assertFalse($this->table->tooManyAttempts('login|alice|10.0.0.1', 3, 60));
    }

    public function testBlocksOnceTheLimitIsReached(): void
    {
        for ($i = 0; $i < 3; $i++) {
            $this->table->record('login|alice|10.0.0.1');
        }

        $this->assertTrue($this->table->tooManyAttempts('login|alice|10.0.0.1', 3, 60));
    }

    // ---- 準正常系（区切りと期間） ----

    public function testCountsAreScopedToTheKey(): void
    {
        for ($i = 0; $i < 5; $i++) {
            $this->table->record('login|alice|10.0.0.1');
        }

        // 別アカウントは巻き込まれない（キーで区切れていないと総当たり対策が過剰防御になる）
        $this->assertSame(0, $this->table->countRecent('login|bob|10.0.0.1', 60));
        $this->assertFalse($this->table->tooManyAttempts('login|bob|10.0.0.1', 3, 60));
    }

    public function testAttemptsOutsideTheWindowAreNotCounted(): void
    {
        $this->insertOldAttempt('login|alice|10.0.0.1', 120);
        $this->table->record('login|alice|10.0.0.1');

        // 60 秒窓では、120 秒前の試行は数えない
        $this->assertSame(1, $this->table->countRecent('login|alice|10.0.0.1', 60));
    }

    public function testClearRemovesOnlyThatKey(): void
    {
        $this->table->record('login|alice|10.0.0.1');
        $this->table->record('login|bob|10.0.0.1');

        $this->table->clear('login|alice|10.0.0.1');

        $this->assertSame(0, $this->table->countRecent('login|alice|10.0.0.1', 60));
        $this->assertSame(1, $this->table->countRecent('login|bob|10.0.0.1', 60));
    }

    // ---- 異常系（掃除が止まると静かに壊れる） ----

    public function testExpiredRowsArePurgedOnEvaluation(): void
    {
        $this->insertOldAttempt('login|alice|10.0.0.1', 120);
        $this->insertOldAttempt('login|bob|10.0.0.1', 3600);
        $this->table->record('login|alice|10.0.0.1');

        $this->assertSame(3, $this->totalRows());

        // 判定のついでに期間外を捨てる（掃除用のバッチを持たないための設計）
        $this->table->tooManyAttempts('login|alice|10.0.0.1', 5, 60);

        $this->assertSame(1, $this->totalRows(), '期間外の行が残っている（掃除が効いていない）');
    }

    public function testEmptyKeyIsCountedIndependently(): void
    {
        // IP が取れない場合は 'unknown' 等へ寄せる想定。空文字が来ても他キーを汚さない
        $this->table->record('');
        $this->table->record('login|alice|10.0.0.1');

        $this->assertSame(1, $this->table->countRecent('', 60));
        $this->assertSame(1, $this->table->countRecent('login|alice|10.0.0.1', 60));
    }
}
