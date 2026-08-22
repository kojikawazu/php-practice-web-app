<?php

declare(strict_types=1);

namespace Application\Service;

/**
 * リクエストごとの CSP nonce と Content-Security-Policy ヘッダー値を提供する。
 *
 * ServiceManager の共有インスタンス（既定で shared）として解決されるため、
 * ヘッダーへ書く nonce と PHTML が出力する nonce が必ず一致する。
 * 生成をコンストラクタに置いているのはそのため（呼ぶたびに変わってはならない）。
 *
 * 読み比べ（docs/12-code-reading-guide.md Step 6）:
 * - laravel-fullstack / laravel-api: App\Http\Middleware\ContentSecurityPolicy。
 *   Laravel はミドルウェアでレスポンスを加工できるが、本アプリは Module::onBootstrap で
 *   MvcEvent::EVENT_FINISH を購読してヘッダーを足す。
 */
final class ContentSecurityPolicy
{
    private string $nonce;

    /**
     * @psalm-suppress PossiblyUnusedMethod InvokableFactory（module.config.php の service_manager）が
     *                 生成するため、静的解析からは呼び出し元が見えない（dead-code.md の「DI 経由」例外）。
     */
    public function __construct()
    {
        // 16 進数（英数字のみ）にする。base64 だと + / = を含み、PHTML 側の
        // escapeHtmlAttr() が実体参照へ変換して生 HTML と一致しなくなる
        // （ブラウザは復号するので動作はするが、値の突合がしづらい）。
        $this->nonce = bin2hex(random_bytes(16));
    }

    public function nonce(): string
    {
        return $this->nonce;
    }

    /**
     * 許可元は「実際に読み込んでいるもの」だけに絞る（`docs/06`）。
     *
     * - script-src: nonce + Tailwind Play CDN + flatpickr（jsDelivr）。`'unsafe-inline'` は付けない。
     * - style-src: Tailwind Play CDN が実行時に <style> を DOM へ注入するため `'unsafe-inline'` が必要。
     *   nonce/hash を書くと `'unsafe-inline'` が無視される仕様のため、style だけは併用できない。
     * - 本アプリは OGP プレビューを持たないため img-src に外部ホストを含めない（fullstack との差分）。
     */
    public function header(): string
    {
        return implode('; ', [
            "default-src 'self'",
            "script-src 'self' 'nonce-" . $this->nonce . "' https://cdn.tailwindcss.com https://cdn.jsdelivr.net",
            "style-src 'self' 'unsafe-inline' https://cdn.jsdelivr.net",
            "img-src 'self' data:",
            "font-src 'self' data:",
            "connect-src 'self'",
            "object-src 'none'",
            "base-uri 'self'",
            "form-action 'self'",
            "frame-ancestors 'none'",
        ]);
    }
}
