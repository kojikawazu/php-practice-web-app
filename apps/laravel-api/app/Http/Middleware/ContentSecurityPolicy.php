<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * 全レスポンスへ Content-Security-Policy を付与する。
 *
 * 本アプリが返すのは JSON と画像バイナリだけで、ブラウザに解釈させる文書を持たない（`docs/07`）。
 * そのため許可元を持たせる理由がなく `default-src 'none'` に振り切る。
 * 新しい許可元が必要になったときは、まず「本当に HTML を返すべきか」を疑う。
 *
 * 読み比べ（docs/12-code-reading-guide.md Step 6）:
 * - laravel-fullstack: 同名ミドルウェア。HTML を返すため nonce を発行し CDN を明示的に許可する。
 * - laminas: Application\Service\ContentSecurityPolicy + Module::onBootstrap の EVENT_FINISH リスナー。
 */
class ContentSecurityPolicy
{
    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);
        $response->headers->set('Content-Security-Policy', self::policy());

        return $response;
    }

    /**
     * JSON / 画像バイナリ専用の最小ポリシー。
     *
     * フレームワーク雛形の `/`（Laravel 既定のランディングページ）もこの対象になる。
     * 本アプリの成果物ではないため、そのページを飾るために API のポリシーを緩めない（`docs/06`）。
     */
    public static function policy(): string
    {
        return implode('; ', [
            "default-src 'none'",
            "base-uri 'none'",
            "form-action 'none'",
            "frame-ancestors 'none'",
        ]);
    }
}
