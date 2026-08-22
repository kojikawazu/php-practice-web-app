<?php

declare(strict_types=1);

namespace Application\Controller;

use Laminas\Http\Request as HttpRequest;
use Laminas\Http\Response as HttpResponse;

/**
 * 状態を変えるアクションを POST に限定する。
 *
 * GET で状態が変わると、認証済みユーザーに `<img src="...">` を踏ませるだけで
 * 操作が成立してしまう（CSRF トークン以前の問題）。POST に限定したうえで、
 * トークン検証は Module::onBootstrap の一括リスナーが担当する（`docs/06`）。
 *
 * 拒否はリダイレクトではなく 405 を返す。リダイレクトだと「実行されたのか
 * 拒否されたのか」がテストでも運用ログでも区別できないため。
 */
trait RequiresPostTrait
{
    /** POST 以外なら 405 レスポンスを返す（POST なら null） */
    private function rejectUnlessPost(): ?HttpResponse
    {
        $request = $this->getRequest();
        if ($request instanceof HttpRequest && $request->isPost()) {
            return null;
        }

        $response = $this->getResponse();
        if (! $response instanceof HttpResponse) {
            return null;
        }
        $response->setStatusCode(HttpResponse::STATUS_CODE_405);
        $response->getHeaders()->addHeaderLine('Allow', 'POST');
        $response->setContent('Method Not Allowed.');

        return $response;
    }
}
