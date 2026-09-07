<?php

declare(strict_types=1);

namespace App\Services;

use GuzzleHttp\Psr7\StreamDecoratorTrait;
use GuzzleHttp\Psr7\Utils;
use Psr\Http\Message\StreamInterface;

/**
 * 受け取ったバイト数が上限に達したら、それ以上書き込ませないストリーム。
 *
 * HTTP クライアントの `sink`（応答本文の書き込み先）として渡して使う。cURL は
 * 書き込み関数が渡したバイト数より少ない値を返すと**転送そのものを中断する**
 * （`CURLE_WRITE_ERROR`）。これが「上限を超えて受信しない」ことの実体になる。
 *
 * この方式を採る理由は、SSRF 対策と両立させるため。本文を遅延受信させる
 * `stream => true` オプションを付けると、Guzzle はリクエストを cURL ではなく
 * `StreamHandler`（PHP のストリームラッパー）へ回すため（`GuzzleHttp\Handler\Proxy::wrapStreaming()`）、
 * `CURLOPT_RESOLVE` による接続先 IP のピン留めが**黙って無効になる**。
 * sink であれば cURL のまま上限を効かせられる（`docs/06-security-specification.md`）。
 *
 * 上限に達したかどうかは `capReached()` で判別する。中断は失敗ではなく「切り詰め」として
 * 扱うため、呼び出し側は集めたぶんで処理を続けられる。
 */
final class SizeCappedSink implements StreamInterface
{
    use StreamDecoratorTrait;

    /**
     * 実体のストリーム。
     *
     * トレイト側はプロパティを宣言しておらず（`@property` の DocBlock と `__get` のみ）、
     * 宣言せずに代入すると PHP 8.2 の「動的プロパティの作成は非推奨」に当たる。
     * ここで明示的に宣言して避ける。
     */
    private StreamInterface $stream;

    private int $written = 0;

    private bool $capReached = false;

    public function __construct(private readonly int $limit)
    {
        $this->stream = Utils::streamFor(Utils::tryFopen('php://temp', 'w+'));
    }

    /** 上限に達して書き込みを打ち切ったか（＝転送を中断させたか） */
    public function capReached(): bool
    {
        return $this->capReached;
    }

    public function write(string $string): int
    {
        $room = $this->limit - $this->written;

        if ($room <= 0) {
            $this->capReached = true;

            // 0 は「1 バイトも書けなかった」の意味になり、cURL は転送を中断する
            return 0;
        }

        $wrote = $this->stream->write(substr($string, 0, $room));
        $this->written += $wrote;

        if ($wrote < strlen($string)) {
            $this->capReached = true;
        }

        return $wrote;
    }
}
