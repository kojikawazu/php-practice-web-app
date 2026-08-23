<?php

declare(strict_types=1);

namespace Application\Service;

use Laminas\Session\SessionManager;

/**
 * AuthSessionInterface の本番実装。laminas-session の SessionManager へ委譲する。
 *
 * SessionManager は module.config.php の session_config が適用された共有インスタンスで、
 * CsrfGuard・認証ストレージと同じものを見る（別インスタンスだと、再生成した ID と
 * データを持つセッションがずれる）。
 */
final class AuthSession implements AuthSessionInterface
{
    public function __construct(private readonly SessionManager $sessions)
    {
    }

    public function regenerate(): void
    {
        // 引数 true = 旧 ID のデータを削除する。残すと、仕込まれた ID が
        // 認証済みのデータを持ったまま生き続け、再生成の意味がなくなる。
        $this->sessions->regenerateId(true);
    }

    public function invalidate(): void
    {
        // 中身を空にしてから ID を振り直す（Laravel の session()->invalidate() と同じ順序）。
        // destroy() ではなくこの形にするのは、破棄後もリクエスト内でセッションを触れる
        // 状態に保ち、「空の新しいセッション」で応答を返すため。
        $this->sessions->getStorage()->clear();
        $this->sessions->regenerateId(true);
    }
}
