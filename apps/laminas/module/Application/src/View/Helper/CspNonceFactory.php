<?php

declare(strict_types=1);

namespace Application\View\Helper;

use Application\Service\ContentSecurityPolicy;
use Laminas\ServiceManager\Factory\FactoryInterface;
use Psr\Container\ContainerInterface;

/**
 * CspNonce ビューヘルパーの生成（自動解決に頼らず明示的に注入する）。
 *
 * ビューヘルパーのファクトリには HelperPluginManager ではなく親の ServiceManager が
 * 渡される（creationContext）ため、ヘッダー側と同じ共有インスタンスを取得できる。
 */
class CspNonceFactory implements FactoryInterface
{
    public function __invoke(ContainerInterface $container, $requestedName, ?array $options = null): CspNonce
    {
        return new CspNonce($container->get(ContentSecurityPolicy::class));
    }
}
