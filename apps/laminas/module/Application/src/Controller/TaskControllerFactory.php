<?php

declare(strict_types=1);

namespace Application\Controller;

use Application\Model\TaskTable;
use Laminas\Authentication\AuthenticationService;
use Laminas\ServiceManager\Factory\FactoryInterface;
use Psr\Container\ContainerInterface;

class TaskControllerFactory implements FactoryInterface
{
    public function __invoke(ContainerInterface $container, $requestedName, ?array $options = null): TaskController
    {
        return new TaskController(
            $container->get(TaskTable::class),
            $container->get(AuthenticationService::class)
        );
    }
}
