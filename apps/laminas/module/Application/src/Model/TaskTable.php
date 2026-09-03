<?php

declare(strict_types=1);

namespace Application\Model;

use DateTimeImmutable;
use Laminas\Db\TableGateway\TableGatewayInterface;

/**
 * lam_tasks テーブルへのアクセス（Table Data Gateway パターン）。
 * 認証導入後は user_id でスコープする。
 *
 * 読み比べ（docs/12-code-reading-guide.md Step 3）:
 * Laravel 2 アプリにこのクラスの対応物はない。あちらでは Eloquent が同じ役割を担い、クエリは
 * コントローラ側に Task::where(...) / $user->tasks() として現れる。結果として所有者スコープの置き場所が
 * 異なる（本クラス = 全メソッドが userId を要求する形で強制 / Laravel = リレーション経由 + abort_if 404）。
 */
class TaskTable
{
    public function __construct(private TableGatewayInterface $tableGateway)
    {
    }

    /** @return iterable<Task> 指定ユーザーのタスクのみ */
    public function fetchAllByUser(int $userId): iterable
    {
        return $this->tableGateway->select(function ($select) use ($userId) {
            $select->where(['user_id' => $userId])->order('id DESC');
        });
    }

    /** 所有者本人のタスクを1件取得。他人/不在なら null */
    public function getForUser(int $id, int $userId): ?Task
    {
        $row = $this->tableGateway->select(['id' => $id, 'user_id' => $userId])->current();

        return $row ?: null;
    }

    /**
     * ページ単位で取得（任意のタイトル検索付き）。
     *
     * @return iterable<Task>
     */
    public function fetchPageByUser(int $userId, int $perPage, int $offset, string $search = ''): iterable
    {
        return $this->tableGateway->select(function ($select) use ($userId, $perPage, $offset, $search) {
            $select->where(['user_id' => $userId]);
            if ($search !== '') {
                $select->where->like('title', '%' . $search . '%');
            }
            $select->order('id DESC')->limit($perPage)->offset($offset);
        });
    }

    /** 検索条件に一致する件数（ページ数計算用） */
    public function countByUser(int $userId, string $search = ''): int
    {
        $result = $this->tableGateway->select(function ($select) use ($userId, $search) {
            $select->where(['user_id' => $userId]);
            if ($search !== '') {
                $select->where->like('title', '%' . $search . '%');
            }
        });

        return $result->count();
    }

    /**
     * 新規（id が null）は insert、既存は所有者スコープ付きで update する。
     *
     * 監査列（created_at / updated_at）はこのメソッドだけで設定する。TableGateway には
     * Eloquent の自動タイムスタンプに相当する機構がないため、集約先を作らないと
     * コントローラ・InputFilter の各所で日時を詰めることになり、書き漏れが即データの
     * 不整合になる（.claude/rules/php.md「監査列」/ docs/05「監査列の設定責務」）。
     */
    public function saveTask(Task $task): void
    {
        $data = [
            'title'      => $task->title,
            'done'       => $task->done ? 1 : 0,
            'user_id'    => $task->user_id,
            'start_date' => $task->start_date,
            'end_date'   => $task->end_date,
        ];

        $now = $this->now();

        if ($task->id === null) {
            $data['created_at'] = $now;
            $data['updated_at'] = $now;
            $this->tableGateway->insert($data);
            return;
        }

        // 更新では created_at を $data に含めない（作成日時は不変）。
        // 所有者条件は update 側に残す。ここを外して監査列だけ別の UPDATE で進めると、
        // 他人のタスクの updated_at を動かせてしまう。
        $data['updated_at'] = $now;
        $this->tableGateway->update($data, ['id' => $task->id, 'user_id' => $task->user_id]);
    }

    /**
     * 監査列に書く現在時刻。
     *
     * 形式は MySQL の TIMESTAMP と、テストで使う SQLite の TEXT の双方が解釈できる
     * 'Y-m-d H:i:s' に固定する。値の生成元を「アプリの時計」にしているのは、Laravel 2 アプリの
     * Eloquent と同じ経路にするため（DB 側の CURRENT_TIMESTAMP は MySQL サーバの時計という
     * 別の源になり、同一題材の 3 アプリで値の作られ方が非対称になる）。
     */
    private function now(): string
    {
        return (new DateTimeImmutable('now'))->format('Y-m-d H:i:s');
    }

    /** 所有者本人のタスクのみ削除（他人の id を指定しても何も起きない） */
    public function deleteForUser(int $id, int $userId): void
    {
        $this->tableGateway->delete(['id' => $id, 'user_id' => $userId]);
    }
}
