<?php

declare(strict_types=1);

namespace Application\Controller;

use Application\Model\UserTable;
use Application\Service\AuthSessionInterface;
use Application\Service\PasswordHasher;
use Laminas\Authentication\AuthenticationService;
use Laminas\ServiceManager\Factory\FactoryInterface;
use Psr\Container\ContainerInterface;

class AuthControllerFactory implements FactoryInterface
{
    public function __invoke(ContainerInterface $container, $requestedName, ?array $options = null): AuthController
    {
        return new AuthController(
            $container->get(AuthenticationService::class),
            $container->get(UserTable::class),
            $container->get(PasswordHasher::class),
            $container->get(AuthSessionInterface::class)
        );
    }
}
