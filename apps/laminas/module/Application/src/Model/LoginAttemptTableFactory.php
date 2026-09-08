<?php

declare(strict_types=1);

namespace Application\Model;

use Laminas\Db\Adapter\AdapterInterface;
use Laminas\Db\TableGateway\TableGateway;
use Laminas\ServiceManager\Factory\FactoryInterface;
use Psr\Container\ContainerInterface;

class LoginAttemptTableFactory implements FactoryInterface
{
    public function __invoke(ContainerInterface $container, $requestedName, ?array $options = null): LoginAttemptTable
    {
        $adapter = $container->get(AdapterInterface::class);

        // 実テーブル名に prefix を直接付与（lam_）
        $tableGateway = new TableGateway('lam_login_attempts', $adapter);

        return new LoginAttemptTable($tableGateway);
    }
}
