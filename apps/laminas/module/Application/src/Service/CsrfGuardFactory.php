<?php

declare(strict_types=1);

namespace Application\Service;

use Laminas\ServiceManager\Factory\FactoryInterface;
use Laminas\Session\Container as SessionContainer;
use Laminas\Session\SessionManager;
use Psr\Container\ContainerInterface;

/**
 * CsrfGuard の生成（自動解決に頼らず明示的に組み立てる）。
 * トークンの保存先は laminas-session のコンテナ（認証の identity と同じセッション）。
 *
 * SessionManager を明示的に渡すのは、session_config が適用された単一インスタンスを
 * 認証ストレージ・AuthSession と共有するため。既定の getDefaultManager() に任せると、
 * 先に生成された側が設定なしの暗黙インスタンスを掴んでしまう。
 */
class CsrfGuardFactory implements FactoryInterface
{
    public function __invoke(ContainerInterface $container, $requestedName, ?array $options = null): CsrfGuard
    {
        return new CsrfGuard(new SessionContainer('csrf', $container->get(SessionManager::class)));
    }
}
