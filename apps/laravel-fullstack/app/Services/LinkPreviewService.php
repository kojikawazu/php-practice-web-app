<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;

/**
 * URL の OGP プレビュー（title / og:image）をサーバー側で安全に取得する。
 *
 * SSRF 対策:
 *  - スキームは http/https のみ
 *  - ホストを DNS 解決し、public IP 以外（private/loopback/link-local/予約）は拒否
 *  - 検証した IP に接続をピン留め（CURLOPT_RESOLVE）して DNS リバインディングを封じる
 *  - リダイレクトは自動追従せず、各ホップを再検証
 *  - 接続/読み込みタイムアウトと本文サイズ上限
 *  - 取得 HTML はそのまま出さず title / og:image だけ抽出（表示側でエスケープ）
 */
class LinkPreviewService
{
    private const MAX_REDIRECTS = 3;
    private const MAX_BYTES = 524288; // 512KB

    /**
     * @return array{title: ?string, image: ?string}|null  取得失敗時は null
     * @throws BlockedUrlException 取得を禁止すべき URL の場合
     */
    public function fetch(string $url, int $depth = 0): ?array
    {
        if ($depth > self::MAX_REDIRECTS) {
            return null;
        }

        $parts = parse_url($url);
        $scheme = strtolower($parts['scheme'] ?? '');
        $host = $parts['host'] ?? '';
        if (! in_array($scheme, ['http', 'https'], true) || $host === '') {
            throw new BlockedUrlException('http / https の URL のみ対応しています。');
        }

        $ip = $this->resolveSafeIp($host);
        $port = $parts['port'] ?? ($scheme === 'https' ? 443 : 80);

        try {
            $response = Http::timeout(5)
                ->connectTimeout(3)
                ->withoutRedirecting()
                ->withHeaders(['User-Agent' => 'TaskPreviewBot/1.0', 'Accept' => 'text/html'])
                ->withOptions(['curl' => [CURLOPT_RESOLVE => ["{$host}:{$port}:{$ip}"]]])
                ->get($url);
        } catch (\Throwable $e) {
            return null; // ネットワークエラー等はプレビュー無しで継続
        }

        // リダイレクトは追従せず、Location を再検証して手動で辿る
        if ($response->redirect()) {
            $location = $response->header('Location');
            if (! $location) {
                return null;
            }

            return $this->fetch($this->absolutize($location, $scheme, $host), $depth + 1);
        }

        if (! $response->successful()) {
            return null;
        }

        $html = substr((string) $response->body(), 0, self::MAX_BYTES);

        return [
            'title' => $this->extractTitle($html),
            'image' => $this->extractOgImage($html),
        ];
    }

    /** ホスト（ドメイン or IP リテラル）を解決し、最初の public IP を返す。無ければ拒否。 */
    public function resolveSafeIp(string $host): string
    {
        $ips = filter_var($host, FILTER_VALIDATE_IP) ? [$host] : (gethostbynamel($host) ?: []);
        foreach ($ips as $ip) {
            if ($this->isPublicIp($ip)) {
                return $ip;
            }
        }

        throw new BlockedUrlException('内部・プライベートアドレスへのアクセスは許可されていません。');
    }

    /** public（グローバル）IP のみ true。private/loopback/link-local/予約レンジは false。 */
    public function isPublicIp(string $ip): bool
    {
        $flags = FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE;

        return filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4 | $flags) !== false
            || filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6 | $flags) !== false;
    }

    /** <title> または og:title を抽出 */
    public function extractTitle(string $html): ?string
    {
        if (preg_match('/<meta[^>]+property=["\']og:title["\'][^>]+content=["\']([^"\']*)["\']/i', $html, $m)) {
            return $this->clean($m[1]);
        }
        if (preg_match('/<title[^>]*>(.*?)<\/title>/is', $html, $m)) {
            return $this->clean($m[1]);
        }

        return null;
    }

    /** og:image を抽出（絶対 http/https のみ採用）*/
    public function extractOgImage(string $html): ?string
    {
        if (preg_match('/<meta[^>]+property=["\']og:image["\'][^>]+content=["\']([^"\']*)["\']/i', $html, $m)) {
            $img = trim(html_entity_decode($m[1], ENT_QUOTES));
            if (preg_match('#^https?://#i', $img)) {
                return $img;
            }
        }

        return null;
    }

    private function clean(string $value): string
    {
        return mb_substr(trim(html_entity_decode($value, ENT_QUOTES)), 0, 255);
    }

    /** 相対 Location を絶対 URL 化（絶対/ルート相対のみ対応）*/
    private function absolutize(string $location, string $scheme, string $host): string
    {
        if (preg_match('#^https?://#i', $location)) {
            return $location;
        }
        if (str_starts_with($location, '/')) {
            return "{$scheme}://{$host}{$location}";
        }

        return "{$scheme}://{$host}/" . ltrim($location, '/');
    }
}
