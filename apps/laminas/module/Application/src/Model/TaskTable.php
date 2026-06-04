<?php

declare(strict_types=1);

namespace Application\Model;

use Laminas\Db\TableGateway\TableGatewayInterface;
use RuntimeException;

/**
 * lam_tasks テーブルへのアクセス（Table Data Gateway パターン）。
 */
class TaskTable
{
    public function __construct(private TableGatewayInterface $tableGateway)
    {
    }

    /** @return iterable<Task> */
    public function fetchAll(): iterable
    {
        return $this->tableGateway->select(function ($select) {
            $select->order('id DESC');
        });
    }

    public function getTask(int $id): Task
    {
        $row = $this->tableGateway->select(['id' => $id])->current();
        if (! $row) {
            throw new RuntimeException(sprintf('id %d のタスクは存在しません', $id));
        }

        return $row;
    }

    public function saveTask(Task $task): void
    {
        $data = [
            'title' => $task->title,
            'done'  => $task->done ? 1 : 0,
        ];

        if ($task->id === null) {
            $this->tableGateway->insert($data);
            return;
        }

        if ($this->tableGateway->select(['id' => $task->id])->current()) {
            $this->tableGateway->update($data, ['id' => $task->id]);
            return;
        }

        throw new RuntimeException(sprintf('id %d のタスクは存在しません', $task->id));
    }

    public function deleteTask(int $id): void
    {
        $this->tableGateway->delete(['id' => $id]);
    }
}
