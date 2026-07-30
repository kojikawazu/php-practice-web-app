<?php

declare(strict_types=1);

namespace Application\Controller;

use Application\Model\TaskTable;
use Laminas\Authentication\AuthenticationService;
use Laminas\ServiceManager\Factory\FactoryInterface;
use Psr\Container\ContainerInterface;

/**
 * TaskController の依存を組み立てる DI ファクトリ（module.config.php の controllers.factories から呼ばれる）。
 *
 * 読み比べ（docs/12-code-reading-guide.md Step 1）: Laravel 2 アプリにこのクラスの対応物はない。
 * あちらはコンテナが型宣言から依存を自動解決するため、配線がコードに現れない。Laminas は
 * 「何を注入するか」をここに書くぶん記述量は増えるが、依存関係が一望できる。
 */
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
