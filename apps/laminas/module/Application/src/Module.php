<?php

declare(strict_types=1);

namespace Application;

use Laminas\Http\Response as HttpResponse;
use Laminas\Mvc\MvcEvent;

class Module
{
    public function getConfig(): array
    {
        /** @var array $config */
        $config = include __DIR__ . '/../config/module.config.php';
        return $config;
    }

    /**
     * 全レスポンスへ Content-Security-Policy を付与する。
     *
     * Laravel のようなミドルウェア層が無いため、MVC のライフサイクル終端
     * （EVENT_FINISH）でレスポンスヘッダーを足す。ポリシーと nonce の実体は
     * Service\ContentSecurityPolicy が持つ（`docs/06`）。
     *
     * @psalm-suppress PossiblyUnusedMethod Laminas MVC がモジュール規約で呼び出すため、
     *                 静的解析からは呼び出し元が見えない（dead-code.md の「フレームワーク規約」例外）。
     */
    public function onBootstrap(MvcEvent $e): void
    {
        $application = $e->getApplication();
        $csp = $application->getServiceManager()->get(Service\ContentSecurityPolicy::class);

        $application->getEventManager()->attach(
            MvcEvent::EVENT_FINISH,
            static function (MvcEvent $event) use ($csp): void {
                $response = $event->getResponse();
                if (! $response instanceof HttpResponse) {
                    return;
                }
                $response->getHeaders()->addHeaderLine('Content-Security-Policy', $csp->header());
            },
            -1000
        );
    }
}
