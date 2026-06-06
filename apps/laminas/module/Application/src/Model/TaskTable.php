<?php

declare(strict_types=1);

namespace Application\Model;

use Laminas\Db\TableGateway\TableGatewayInterface;

/**
 * lam_tasks テーブルへのアクセス（Table Data Gateway パターン）。
 * 認証導入後は user_id でスコープする。
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

    /** ページ単位で取得（任意のタイトル検索付き） */
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

    public function saveTask(Task $task): void
    {
        $data = [
            'title'   => $task->title,
            'done'    => $task->done ? 1 : 0,
            'user_id' => $task->user_id,
        ];

        if ($task->id === null) {
            $this->tableGateway->insert($data);
            return;
        }

        $this->tableGateway->update($data, ['id' => $task->id, 'user_id' => $task->user_id]);
    }

    /** 所有者本人のタスクのみ削除（他人の id を指定しても何も起きない） */
    public function deleteForUser(int $id, int $userId): void
    {
        $this->tableGateway->delete(['id' => $id, 'user_id' => $userId]);
    }
}
