<?php

declare(strict_types=1);

namespace Application\Service;

/**
 * 認証状態が変わる瞬間のセッション操作。
 *
 * ext/session のグローバルな副作用（session_regenerate_id / $_SESSION）への依存を
 * この 1 点に閉じ込める。IT は実セッションを張らない方針のため、記録用の実装へ
 * 差し替えて「どの経路で呼ばれ、どの経路で呼ばれないか」を検証する。
 *
 * 読み比べ（docs/12-code-reading-guide.md Step 2）:
 * - laravel-fullstack / laravel-api: 対応物が存在しない。fullstack は Auth::login() /
 *   Auth::attempt() の内部で SessionGuard::updateSession() が session()->migrate(true) を
 *   呼ぶため、認証とセッション再生成をフレームワークが一体で面倒を見る。
 * - laminas: laminas-authentication（認証）と laminas-session（セッション）が別コンポーネント
 *   なので、その繋ぎ目はアプリ側で明示的に書く。
 */
interface AuthSessionInterface
{
    /**
     * セッション ID だけを振り直す（中のデータは引き継ぐ）。
     *
     * 認証成功時に呼び、「未認証時に配られた ID のまま認証済みになる」瞬間を作らない。
     * これがセッション固定攻撃（docs/06）への対策の中心。
     */
    public function regenerate(): void;

    /**
     * セッションの中身を破棄し、ID も新しくする。
     *
     * ログアウト時に呼ぶ。CSRF トークンも一緒に失効するため、次のログイン画面では
     * 新しいトークンが発行される。
     */
    public function invalidate(): void;
}
