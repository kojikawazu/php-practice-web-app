<?php

declare(strict_types=1);

namespace Application\Service;

use Application\Model\LoginAttemptTable;
use Laminas\ServiceManager\Factory\FactoryInterface;
use Psr\Container\ContainerInterface;

class AuthThrottleFactory implements FactoryInterface
{
    public function __invoke(ContainerInterface $container, $requestedName, ?array $options = null): AuthThrottle
    {
        return new AuthThrottle($container->get(LoginAttemptTable::class));
    }
}
