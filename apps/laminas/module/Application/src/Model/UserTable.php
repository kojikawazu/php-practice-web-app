<?php

declare(strict_types=1);

namespace Application\Model;

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

    public function create(string $username, string $passwordHash): void
    {
        $this->tableGateway->insert([
            'username' => $username,
            'password' => $passwordHash,
        ]);
    }
}
