<?php

declare(strict_types=1);

namespace Application\Service;

use Laminas\Authentication\AuthenticationService;
use Laminas\Authentication\Storage\Session as SessionStorage;
use Laminas\ServiceManager\Factory\FactoryInterface;
use Laminas\Session\SessionManager;
use Psr\Container\ContainerInterface;

/**
 * AuthenticationService の生成。identity の保存先を明示する。
 *
 * new AuthenticationService() のまま既定に任せると、Storage\Session が内部で作る
 * Container が Container::getDefaultManager()（誰も設定していない暗黙のインスタンス）を
 * 掴む。それだと session_config が効かず、AuthSession が再生成する SessionManager とも
 * 別物になるため、共有インスタンスを明示的に渡す。
 */
class AuthenticationServiceFactory implements FactoryInterface
{
    public function __invoke(
        ContainerInterface $container,
        $requestedName,
        ?array $options = null
    ): AuthenticationService {
        $sessions = $container->get(SessionManager::class);

        return new AuthenticationService(new SessionStorage(null, null, $sessions));
    }
}
