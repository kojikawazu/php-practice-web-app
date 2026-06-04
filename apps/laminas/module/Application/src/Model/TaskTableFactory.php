<?php

declare(strict_types=1);

namespace Application\Model;

use Laminas\Db\Adapter\AdapterInterface;
use Laminas\Db\ResultSet\ResultSet;
use Laminas\Db\TableGateway\TableGateway;
use Laminas\ServiceManager\Factory\FactoryInterface;
use Psr\Container\ContainerInterface;

class TaskTableFactory implements FactoryInterface
{
    public function __invoke(ContainerInterface $container, $requestedName, ?array $options = null): TaskTable
    {
        $adapter = $container->get(AdapterInterface::class);

        $resultSetPrototype = new ResultSet();
        $resultSetPrototype->setArrayObjectPrototype(new Task());

        // 実テーブル名に prefix を直接付与（lam_）
        $tableGateway = new TableGateway('lam_tasks', $adapter, null, $resultSetPrototype);

        return new TaskTable($tableGateway);
    }
}
