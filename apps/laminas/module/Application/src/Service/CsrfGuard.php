<?php

declare(strict_types=1);

namespace Application\Service;

use Laminas\Session\Container as SessionContainer;

use function bin2hex;
use function hash_equals;
use function is_string;
use function random_bytes;

/**
 * CSRF トークンの発行と検証（同期トークンパターン）。
 *
 * セッションごとに 1 つのトークンを持ち、POST のたびに定数時間比較する。
 * 仕組みは Laravel の `_token`（`@csrf` が埋め、VerifyCsrfToken が検証）と同じで、
 * 「セッションを持つ本人しか知り得ない値」をフォームに要求することで、
 * 外部サイトからの偽装リクエストを弾く（`docs/06`）。
 *
 * laminas-validator / laminas-session の Csrf バリデータを使わないのは、
 * 双方とも 3.0 で削除予定の非推奨 API だから（`getHash()` を含む）。
 * 実体は「CSPRNG の乱数 + 定数時間比較」だけなので、抑制コメントを重ねるより
 * 自前で持つ方が読みやすく、テストもしやすい。
 *
 * ServiceManager の共有インスタンスとして解決されるため、フォームへ埋める値と
 * 検証に使う値が同じセッションを見る。
 *
 * 読み比べ（docs/12-code-reading-guide.md Step 6.5）:
 * - laravel-fullstack: Blade の `@csrf` と `VerifyCsrfToken` ミドルウェア（雛形で有効）。
 * - laravel-api: Bearer トークン認証で Cookie を使わないため CSRF 対策は不要。
 */
final class CsrfGuard
{
    /** フォームの hidden 名・POST のキー */
    public const FIELD = 'csrf';

    /** セッションに保存するキー */
    private const SESSION_KEY = 'token';

    public function __construct(private readonly SessionContainer $session)
    {
    }

    /**
     * フォームへ埋めるトークン。
     * セッション内で不変（ページを開くたびに変えると、複数タブ・戻る操作で壊れる）。
     */
    public function token(): string
    {
        /** @var mixed $token */
        $token = $this->session->offsetGet(self::SESSION_KEY);
        if (! is_string($token) || $token === '') {
            $token = bin2hex(random_bytes(32));
            $this->session->offsetSet(self::SESSION_KEY, $token);
        }

        return $token;
    }

    /**
     * 送信されたトークンを検証する。
     * 比較は hash_equals（定数時間）で行い、一致状況をタイミングから推測されないようにする。
     */
    public function isValid(?string $token): bool
    {
        if ($token === null || $token === '') {
            return false;
        }

        /** @var mixed $expected */
        $expected = $this->session->offsetGet(self::SESSION_KEY);
        if (! is_string($expected) || $expected === '') {
            // 未発行のセッションからの POST は常に拒否する
            return false;
        }

        return hash_equals($expected, $token);
    }
}
