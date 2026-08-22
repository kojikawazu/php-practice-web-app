<?php

declare(strict_types=1);

namespace Application\Service;

use Laminas\ServiceManager\Factory\FactoryInterface;
use Laminas\Session\Container as SessionContainer;
use Psr\Container\ContainerInterface;

/**
 * CsrfGuard の生成（自動解決に頼らず明示的に組み立てる）。
 * トークンの保存先は laminas-session のコンテナ（認証の identity と同じセッション）。
 */
class CsrfGuardFactory implements FactoryInterface
{
    public function __invoke(ContainerInterface $container, $requestedName, ?array $options = null): CsrfGuard
    {
        return new CsrfGuard(new SessionContainer('csrf'));
    }
}
