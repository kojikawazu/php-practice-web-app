<?php

declare(strict_types=1);

namespace Application\Model;

use Laminas\Db\Sql\Select;
use Laminas\Db\Sql\Where;
use Laminas\Db\TableGateway\TableGatewayInterface;

/**
 * lam_login_attempts テーブルへのアクセス（レートリミットの保存層）。
 *
 * **保存だけを担当し、上限値やキーの作り方は持たない**。「何回までか」「何で区切るか」は
 * ポリシーであり、`Application\Service\AuthThrottle` に置いている。Laravel が
 * `RateLimiter`（ポリシー）とキャッシュ（保存）に分かれているのと同じ切り方で、
 * 保存先を差し替えても方針が動かないようにするため。
 *
 * 読み比べ（docs/12-code-reading-guide.md）:
 * - laravel-fullstack / laravel-api: 保存はキャッシュ（`CACHE_STORE`）で、TTL があるため
 *   期限切れの掃除が要らない。laminas は TTL が無いので、判定のたびに期間外の行を削除する
 *   （掃除用のバッチを持たないための設計）。
 */
class LoginAttemptTable
{
    use FormatsTimestamps;

    public function __construct(private TableGatewayInterface $tableGateway)
    {
    }

    /**
     * 指定キーの試行が上限に達しているか。
     *
     * 判定のついでに期間外の行を捨てる。掃除を別経路（cron 等）に持たせると、
     * それが止まったときに気づけないまま行が増え続ける。
     */
    public function tooManyAttempts(string $key, int $maxAttempts, int $windowSeconds): bool
    {
        $this->purgeOlderThan($windowSeconds);

        return $this->countRecent($key, $windowSeconds) >= $maxAttempts;
    }

    /** 試行を 1 件記録する */
    public function record(string $key): void
    {
        $this->tableGateway->insert([
            'attempt_key'  => $key,
            'attempted_at' => $this->now(),
        ]);
    }

    /** 認証に成功したので、そのキーの試行履歴を捨てる */
    public function clear(string $key): void
    {
        $this->tableGateway->delete(['attempt_key' => $key]);
    }

    /** 期間内の試行回数 */
    public function countRecent(string $key, int $windowSeconds): int
    {
        $since = $this->secondsAgo($windowSeconds);

        $rows = $this->tableGateway->select(function (Select $select) use ($key, $since): void {
            $select->where->equalTo('attempt_key', $key);
            $select->where->greaterThanOrEqualTo('attempted_at', $since);
        });

        return count($rows);
    }

    /**
     * 期間外の行をまとめて削除する。
     *
     * 条件付きの DELETE であり、全削除ではない（`.claude/rules/production-data.md` が
     * 禁じているのは条件なしの DELETE）。対象は laminas 専用テーブルのみ。
     */
    private function purgeOlderThan(int $windowSeconds): void
    {
        $since = $this->secondsAgo($windowSeconds);

        $where = new Where();
        $where->lessThan('attempted_at', $since);

        $this->tableGateway->delete($where);
    }
}
