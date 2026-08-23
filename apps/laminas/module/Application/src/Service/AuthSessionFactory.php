<?php

declare(strict_types=1);

namespace Application\Service;

use Laminas\ServiceManager\Factory\FactoryInterface;
use Laminas\Session\SessionManager;
use Psr\Container\ContainerInterface;

/**
 * AuthSession の生成（自動解決に頼らず明示的に組み立てる）。
 *
 * SessionManager をコンテナから取ることが重要で、これにより session_config
 * （use_strict_mode / Cookie 属性）が適用された単一インスタンスを共有できる。
 * new SessionManager() だと設定の効いていない別物になる。
 */
class AuthSessionFactory implements FactoryInterface
{
    public function __invoke(ContainerInterface $container, $requestedName, ?array $options = null): AuthSession
    {
        return new AuthSession($container->get(SessionManager::class));
    }
}
