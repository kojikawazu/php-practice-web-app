<?php

namespace App\Services;

use GuzzleHttp\Exception\RequestException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Psr\Http\Message\StreamInterface;

/**
 * URL の OGP プレビュー（title / og:image）をサーバー側で安全に取得する。
 *
 * SSRF 対策:
 *  - スキームは http/https のみ
 *  - ホストを DNS 解決し、public IP 以外（private/loopback/link-local/予約）は拒否
 *  - 検証した IP に接続をピン留め（CURLOPT_RESOLVE）して DNS リバインディングを封じる
 *  - リダイレクトは自動追従せず、各ホップを再検証
 *  - 接続/読み込みタイムアウトと、受信そのものを打ち切る本文サイズ上限（512KB）
 *  - 取得 HTML はそのまま出さず title / og:image だけ抽出（表示側でエスケープ）
 *
 * 読み比べ（docs/12-code-reading-guide.md Step 6）: 本アプリ固有で、他 2 アプリに対応物はない。
 * 対策の設計判断は docs/06-security-specification.md、テスト（何を許可し何を拒否するか）は
 * tests/Unit/LinkPreviewServiceTest.php が仕様書として読める。取得に失敗したときに何を
 * ログへ残すかは tests/Feature/LinkPreviewLoggingTest.php が固定している。
 */
class LinkPreviewService
{
    private const MAX_REDIRECTS = 3;

    private const MAX_BYTES = 524288; // 512KB

    /**
     * @return array{title: ?string, image: ?string}|null 取得失敗時は null
     *
     * @throws BlockedUrlException 取得を禁止すべき URL の場合
     */
    public function fetch(string $url, int $depth = 0): ?array
    {
        if ($depth > self::MAX_REDIRECTS) {
            return $this->giveUp('too_many_redirects', (string) parse_url($url, PHP_URL_HOST), ['depth' => $depth]);
        }

        $parts = parse_url($url);
        $scheme = strtolower($parts['scheme'] ?? '');
        $host = $parts['host'] ?? '';
        if (! in_array($scheme, ['http', 'https'], true) || $host === '') {
            throw new BlockedUrlException('http / https の URL のみ対応しています。');
        }

        $ip = $this->resolveSafeIp($host);
        $port = $parts['port'] ?? ($scheme === 'https' ? 443 : 80);

        // 応答本文の書き込み先を上限付きにして、上限を超えた時点で転送を中断させる。
        // ここが「512KB を超えて受信しない」ことの実体（SizeCappedSink の DocBlock 参照）。
        $sink = new SizeCappedSink(self::MAX_BYTES);

        try {
            $response = Http::timeout(5)
                ->connectTimeout(3)
                ->withoutRedirecting()
                ->withHeaders(['User-Agent' => 'TaskPreviewBot/1.0', 'Accept' => 'text/html'])
                ->withOptions([
                    'sink' => $sink,
                    'curl' => [CURLOPT_RESOLVE => ["{$host}:{$port}:{$ip}"]],
                ])
                ->get($url);
        } catch (\Exception $e) {
            // 上限で中断させた場合、cURL は転送エラーとして例外を投げるが、これは失敗ではない。
            // 応答ヘッダーは受信済みで、Guzzle が組み立てた応答（本文 = ここまでに書けた分）が
            // 例外にぶら下がっているので、それを通常の応答として扱って解析を続ける。
            $partial = $e instanceof RequestException ? $e->getResponse() : null;

            if (! $sink->capReached() || $partial === null) {
                // ネットワークエラー等はプレビュー無しで継続する（ユーザー操作は止めない）。
                // \Throwable ではなく \Exception を捕まえるのは、Error（TypeError 等の
                // プログラミングバグ）まで飲み込むと自分たちのバグが「プレビューが付かない」
                // という無害な見た目に化けて、誰も気づけなくなるため。Error は伝播させる。
                return $this->giveUp('request_failed', $host, ['exception' => $e]);
            }

            $response = new Response($partial);
        }

        // リダイレクトは追従せず、Location を再検証して手動で辿る
        if ($response->redirect()) {
            $location = $response->header('Location');
            if (! $location) {
                return $this->giveUp('redirect_without_location', $host, ['status' => $response->status()]);
            }

            return $this->fetch($this->absolutize($location, $scheme, $host), $depth + 1);
        }

        if (! $response->successful()) {
            return $this->giveUp('http_error', $host, ['status' => $response->status()]);
        }

        // 2 層で上限を守る。sink は「転送を止める」担当（本命）、readCapped は「解析に使う
        // 長さを保証する」担当。sink を通らない経路（テストのフェイク等）でも後者は成立する。
        [$html, $readTruncated] = $this->readCapped($response->toPsrResponse()->getBody());
        $truncated = $sink->capReached() || $readTruncated;

        if ($truncated) {
            // 失敗ではない（title / og:image は <head> にあるため先頭 512KB で足りるのが通常）。
            // ただし「プレビューが変」と言われたときに切り詰めを疑えるよう記録は残す。
            Log::info('URL preview body truncated', ['host' => $host, 'limit' => self::MAX_BYTES]);
        }

        return [
            'title' => $this->extractTitle($html),
            'image' => $this->extractOgImage($html),
        ];
    }

    /**
     * 本文を最大 MAX_BYTES バイトまで読み、超過したかどうかと併せて返す。
     *
     * 上限は Content-Length を見て判断しない。**あのヘッダーは取得先が自己申告する値**で、
     * 偽ればいくらでも送り込めるため、上限の担保は「読む側が読むのをやめる」ことでしか
     * 成立しない（chunked 応答のようにヘッダーが無い場合も同じ理屈で守れる）。
     *
     * 超過を検出するために上限より 1 バイト多く読む。読めた長さが MAX_BYTES を超えていれば
     * 「まだ続きがある」と判定できる（eof フラグの立ち方に依存せず判定できる）。
     *
     * @return array{0: string, 1: bool} 読み取った本文と、上限で切り詰めたかどうか
     */
    private function readCapped(StreamInterface $body): array
    {
        $limit = self::MAX_BYTES + 1;
        $html = '';

        // sink へ書き込んだ直後は位置が末尾にあるため巻き戻す（Laravel の body() も同じことをする）
        if ($body->isSeekable()) {
            $body->rewind();
        }

        try {
            while (strlen($html) < $limit && ! $body->eof()) {
                $chunk = $body->read($limit - strlen($html));
                if ($chunk === '') {
                    break; // eof が立たないストリームで無限ループにしない
                }
                $html .= $chunk;
            }
        } finally {
            // 残りを受信しきる前に接続を捨てる（打ち切りを転送量に反映させる）
            $body->close();
        }

        if (strlen($html) > self::MAX_BYTES) {
            return [substr($html, 0, self::MAX_BYTES), true];
        }

        return [$html, false];
    }

    /**
     * 取得できなかった理由をログに残し、null を返す。
     *
     * URL 全体をログに出さないのは、ユーザー入力の URL には認証情報（`user:pass@`）や
     * クエリ文字列中のトークンが含まれ得るため（`.claude/rules/error-handling.md`
     * 「パスワード・トークン等のセンシティブ情報はログに含めない」）。原因の切り分けには
     * ホストと理由で足りる。
     *
     * 失敗の種類で level を分けず warning に揃えているのは、「プレビューが付かなかった」
     * という 1 つの事象を 1 回の検索で拾えるようにするため（理由は reason で判別する）。
     *
     * 戻り値の型を `null` にしているのは、呼び出し側が `return $this->giveUp(...)` と
     * 書けるようにするため。`?array` にすると、fetch() の戻り値型
     * `array{title: ?string, image: ?string}|null` に対して要素型不明の array を
     * 返すことになり、静的解析（Larastan level: max）が全呼び出し箇所で落ちる。
     *
     * @param  array<string, mixed>  $context
     */
    private function giveUp(string $reason, string $host, array $context = []): null
    {
        Log::warning('URL preview failed', ['reason' => $reason, 'host' => $host] + $context);

        return null;
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

        return "{$scheme}://{$host}/".ltrim($location, '/');
    }
}
