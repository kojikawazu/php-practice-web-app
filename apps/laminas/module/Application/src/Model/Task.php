<?php

declare(strict_types=1);

namespace Application\Model;

/**
 * タスクのエンティティ。
 * TableGateway の ResultSet プロトタイプとして使うため exchangeArray を持つ。
 */
class Task
{
    public ?int $id = null;
    public string $title = '';
    public bool $done = false;
    public ?int $user_id = null;

    public function exchangeArray(array $data): void
    {
        $this->id      = isset($data['id']) ? (int) $data['id'] : null;
        $this->title   = isset($data['title']) ? (string) $data['title'] : '';
        $this->done    = isset($data['done']) ? (bool) $data['done'] : false;
        $this->user_id = isset($data['user_id']) ? (int) $data['user_id'] : null;
    }

    public function getArrayCopy(): array
    {
        return [
            'id'      => $this->id,
            'title'   => $this->title,
            'done'    => $this->done ? 1 : 0,
            'user_id' => $this->user_id,
        ];
    }
}
