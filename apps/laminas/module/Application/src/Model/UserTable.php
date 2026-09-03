<?php

declare(strict_types=1);

namespace Application\Model;

use DateTimeImmutable;
use Laminas\Db\TableGateway\TableGatewayInterface;

/**
 * lam_users テーブルへのアクセス。
 */
class UserTable
{
    public function __construct(private TableGatewayInterface $tableGateway)
    {
    }

    /** 見つからなければ null。行は ArrayObject(ARRAY_AS_PROPS) で ->username 等でアクセス可能 */
    public function findByUsername(string $username): ?object
    {
        $row = $this->tableGateway->select(['username' => $username])->current();

        return $row ?: null;
    }

    /**
     * ユーザーを 1 件作成する。
     *
     * 監査列（created_at）はこのメソッドだけで設定する。TableGateway には Eloquent の
     * 自動タイムスタンプに相当する機構がないため、集約先を作らないとコントローラ側で
     * 日時を詰めることになり、書き漏れが即データの不整合になる
     * （.claude/rules/php.md「監査列」/ docs/05「監査列の設定責務」）。
     *
     * lam_users は updated_at を持たない。プロフィール編集・パスワード変更の機能が無く、
     * 更新される契機そのものが存在しないため（使わない列を「将来のため」に持たない方針。
     * .claude/rules/dead-code.md）。3 アプリ差分として docs/05 に明記してある。
     */
    public function create(string $username, string $passwordHash): void
    {
        $this->tableGateway->insert([
            'username'   => $username,
            'password'   => $passwordHash,
            'created_at' => $this->now(),
        ]);
    }

    /**
     * 監査列に書く現在時刻。
     *
     * 形式・生成元の判断は TaskTable::now() と同じ（MySQL の TIMESTAMP とテストで使う
     * SQLite の TEXT の双方が解釈できる 'Y-m-d H:i:s' を、アプリの時計で作る）。
     * 2 箇所目のため共通化しない（.claude/rules/duplication.md「2 回目までは重複を許容し、
     * 3 回目で共通化する」）。3 箇所目が出たら Table 層の共通トレイト等へ抽出する。
     */
    private function now(): string
    {
        return (new DateTimeImmutable('now'))->format('Y-m-d H:i:s');
    }
}
