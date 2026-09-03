<?php

declare(strict_types=1);

namespace ApplicationTest\Model;

use Application\Model\UserTable;
use Laminas\Db\Adapter\Adapter;
use Laminas\Db\TableGateway\TableGateway;
use PHPUnit\Framework\TestCase;

/**
 * UserTable を SQLite in-memory で検証する。
 *
 * 主眼は監査列（created_at）の設定責務が Table 層に集約されていること。TableGateway には
 * Eloquent の自動タイムスタンプ相当が無いため、設定漏れは「登録はできるが日時だけ NULL」
 * という静かな形で現れる（issue #126。lam_tasks 側の同じ欠陥は #74）。
 *
 * lam_users は updated_at を持たない（更新機能が存在しないため）。この差分は
 * docs/05「監査列の設定責務」に 3 アプリ差分として記載してある。
 */
class UserTableTest extends TestCase
{
    private UserTable $table;

    /** 監査列は findByUsername の戻り値からも読めるが、列の実体を確かめるため raw SQL で読む */
    private Adapter $adapter;

    protected function setUp(): void
    {
        $adapter = new Adapter([
            'driver'   => 'Pdo_Sqlite',
            'database' => ':memory:',
        ]);

        // lam_users の SQLite 相当スキーマ（MySQL 版と同じ列構成。updated_at は持たない）
        $adapter->query(
            'CREATE TABLE lam_users (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                username TEXT NOT NULL UNIQUE,
                password TEXT NOT NULL,
                created_at TEXT NULL
            )',
            Adapter::QUERY_MODE_EXECUTE
        );

        $this->table   = new UserTable(new TableGateway('lam_users', $adapter));
        $this->adapter = $adapter;
    }

    /**
     * 監査列を raw SQL で読む。
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

    // ---- 正常系 ----

    public function testCreateStoresUsernameAndHash(): void
    {
        $this->table->create('alice', 'hashed-password');

        $user = $this->table->findByUsername('alice');

        $this->assertNotNull($user);
        $this->assertSame('alice', $user->username);
        $this->assertSame('hashed-password', $user->password);
    }

    public function testCreateSetsCreatedAt(): void
    {
        $this->table->create('alice', 'hashed-password');

        // 設定漏れは NULL という静かな形で現れるため、値の存在そのものを固定する
        $this->assertNotNull($this->createdAtOf('alice'));
    }

    public function testCreatedAtUsesMysqlTimestampFormat(): void
    {
        $this->table->create('alice', 'hashed-password');

        // 'Y-m-d H:i:s' を外すと、TEXT 列の SQLite では通るのに本番の MySQL
        // （TIMESTAMP）で落ちる。テストが本番と食い違う典型なので形式を固定する。
        $this->assertMatchesRegularExpression(
            '/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/',
            (string) $this->createdAtOf('alice')
        );
    }

    public function testEachUserGetsItsOwnCreatedAt(): void
    {
        $past = '2020-01-01 00:00:00';
        $this->table->create('alice', 'hashed-password');
        $this->adapter->query(
            'UPDATE lam_users SET created_at = ? WHERE username = ?',
            [$past, 'alice']
        );

        $this->table->create('bob', 'hashed-password');

        // 後から作ったユーザーが、先に作られた行の値を引き継がない
        $this->assertSame($past, $this->createdAtOf('alice'));
        $this->assertNotSame($past, $this->createdAtOf('bob'));
        $this->assertNotNull($this->createdAtOf('bob'));
    }

    // ---- 準正常系・異常系 ----

    public function testFindByUsernameReturnsNullForUnknownUser(): void
    {
        $this->table->create('alice', 'hashed-password');

        $this->assertNull($this->table->findByUsername('carol'));
    }
}
