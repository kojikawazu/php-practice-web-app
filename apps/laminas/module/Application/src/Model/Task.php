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
    public ?string $start_date = null;
    public ?string $end_date = null;

    /** @param array<string, mixed> $data DB 行（連想配列） */
    public function exchangeArray(array $data): void
    {
        $this->id         = isset($data['id']) ? (int) $data['id'] : null;
        $this->title      = isset($data['title']) ? (string) $data['title'] : '';
        $this->done       = isset($data['done']) ? (bool) $data['done'] : false;
        $this->user_id    = isset($data['user_id']) ? (int) $data['user_id'] : null;
        $this->start_date = ! empty($data['start_date']) ? (string) $data['start_date'] : null;
        $this->end_date   = ! empty($data['end_date']) ? (string) $data['end_date'] : null;
    }

    /** @return array<string, mixed> DB 保存用の連想配列 */
    public function getArrayCopy(): array
    {
        return [
            'id'         => $this->id,
            'title'      => $this->title,
            'done'       => $this->done ? 1 : 0,
            'user_id'    => $this->user_id,
            'start_date' => $this->start_date,
            'end_date'   => $this->end_date,
        ];
    }
}
