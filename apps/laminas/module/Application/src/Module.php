<?php

declare(strict_types=1);

namespace Application;

use Laminas\Http\Request as HttpRequest;
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
     * アプリ横断の関心事（CSP ヘッダー・CSRF 検証）を MVC のイベントへ差し込む。
     *
     * Laravel のようなミドルウェア層が無いため、ライフサイクルのイベントを購読する。
     *
     * @psalm-suppress PossiblyUnusedMethod Laminas MVC がモジュール規約で呼び出すため、
     *                 静的解析からは呼び出し元が見えない（dead-code.md の「フレームワーク規約」例外）。
     */
    public function onBootstrap(MvcEvent $e): void
    {
        $application = $e->getApplication();
        $services = $application->getServiceManager();
        $events = $application->getEventManager();

        // サービスはリスナー内で遅延解決する。onBootstrap 時点で解決すると、
        // 後から差し替えられたサービス（IT の setService）が反映されない。
        $events->attach(
            MvcEvent::EVENT_FINISH,
            static function (MvcEvent $event) use ($services): void {
                $csp = $services->get(Service\ContentSecurityPolicy::class);
                $response = $event->getResponse();
                if (! $response instanceof HttpResponse) {
                    return;
                }
                $response->getHeaders()->addHeaderLine('Content-Security-Policy', $csp->header());
            },
            -1000
        );

        // CSRF はここで一括検証する。各アクションで個別に呼ぶ形にすると、
        // 新しい POST を足したときに「書き忘れ = 無防備」になる（security.md）。
        // ルーティング後に走らせ、不正なら 403 を返してディスパッチへ進ませない。
        $events->attach(MvcEvent::EVENT_ROUTE, static function (MvcEvent $event) use ($services): ?HttpResponse {
            $request = $event->getRequest();
            if (! $request instanceof HttpRequest || ! $request->isPost()) {
                return null;
            }

            $guard = $services->get(Service\CsrfGuard::class);
            /** @var mixed $token */
            $token = $request->getPost(Service\CsrfGuard::FIELD);
            if ($guard->isValid(is_string($token) ? $token : null)) {
                return null;
            }

            $response = $event->getResponse();
            if (! $response instanceof HttpResponse) {
                return null;
            }
            // 攻撃の失敗はリダイレクトで隠さず 403 で明示する（成功と区別できるようにする）
            $response->setStatusCode(HttpResponse::STATUS_CODE_403);
            $response->setContent('Invalid CSRF token.');

            return $response;
        }, -100);
    }
}
