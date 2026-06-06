<?php

declare(strict_types=1);

namespace Application\Model;

use Laminas\Db\Adapter\AdapterInterface;
use Laminas\Db\TableGateway\TableGateway;
use Laminas\ServiceManager\Factory\FactoryInterface;
use Psr\Container\ContainerInterface;

class UserTableFactory implements FactoryInterface
{
    public function __invoke(ContainerInterface $container, $requestedName, ?array $options = null): UserTable
    {
        $adapter = $container->get(AdapterInterface::class);

        // 既定 ResultSet（ArrayObject 行）。実テーブル名に prefix を直接付与（lam_）
        $tableGateway = new TableGateway('lam_users', $adapter);

        return new UserTable($tableGateway);
    }
}
