<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\View;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

/**
 * 全レスポンスへ Content-Security-Policy を付与する。
 *
 * XSS が混入したときの被害をブラウザ側で抑えるための多層防御（`docs/06`）。
 * インライン script は毎リクエスト発行する nonce でのみ許可し、`'unsafe-inline'` は使わない。
 * nonce はビューへ共有し、Blade 側で `nonce="{{ $cspNonce }}"` として出力する。
 *
 * 読み比べ（docs/12-code-reading-guide.md Step 6）:
 * - laravel-api: 同名ミドルウェア。JSON しか返さないため nonce を持たず `default-src 'none'` に振り切る。
 * - laminas: Application\Service\ContentSecurityPolicy + Module::onBootstrap の EVENT_FINISH リスナー。
 *   ミドルウェアの層が無いため、イベントでレスポンスにヘッダーを足す。
 */
class ContentSecurityPolicy
{
    /** Blade から参照する nonce の変数名 */
    public const NONCE_VIEW_KEY = 'cspNonce';

    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $nonce = Str::random(24);
        View::share(self::NONCE_VIEW_KEY, $nonce);

        $response = $next($request);
        $response->headers->set('Content-Security-Policy', self::policy($nonce));

        return $response;
    }

    /**
     * 許可元は「実際に読み込んでいるもの」だけに絞る（`docs/06`）。
     *
     * - script-src: nonce + Tailwind Play CDN + flatpickr（jsDelivr）。`'unsafe-inline'` は付けない。
     * - style-src: Tailwind Play CDN が実行時に <style> を DOM へ注入するため `'unsafe-inline'` が必要。
     *   nonce/hash を書くと `'unsafe-inline'` が無視される仕様のため、style だけは併用できない（既知の妥協・`docs/06`）。
     * - img-src: OGP プレビュー画像は任意の外部ホストから来るため https: を許可する。
     */
    public static function policy(string $nonce): string
    {
        return implode('; ', [
            "default-src 'self'",
            "script-src 'self' 'nonce-{$nonce}' https://cdn.tailwindcss.com https://cdn.jsdelivr.net",
            "style-src 'self' 'unsafe-inline' https://cdn.jsdelivr.net",
            "img-src 'self' data: https:",
            "font-src 'self' data:",
            "connect-src 'self'",
            "object-src 'none'",
            "base-uri 'self'",
            "form-action 'self'",
            "frame-ancestors 'none'",
        ]);
    }
}
