<?php

declare(strict_types=1);

namespace Application\View\Helper;

use Application\Service\CsrfGuard;
use Laminas\Escaper\Escaper;
use Laminas\ServiceManager\Factory\FactoryInterface;
use Psr\Container\ContainerInterface;

/**
 * CsrfInput ビューヘルパーの生成（自動解決に頼らず明示的に注入する）。
 *
 * ビューヘルパーのファクトリには HelperPluginManager ではなく親の ServiceManager が
 * 渡される（creationContext）ため、検証側と同じ共有 CsrfGuard を取得できる。
 */
class CsrfInputFactory implements FactoryInterface
{
    public function __invoke(ContainerInterface $container, $requestedName, ?array $options = null): CsrfInput
    {
        return new CsrfInput($container->get(CsrfGuard::class), new Escaper());
    }
}
