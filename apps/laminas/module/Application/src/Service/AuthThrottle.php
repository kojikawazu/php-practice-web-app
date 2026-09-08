<?php

declare(strict_types=1);

namespace Application\Service;

use Application\Model\LoginAttemptTable;

/**
 * 認証系の試行回数ポリシー（レートリミット。issue #142・`.claude/rules/security.md`）。
 *
 * **キーを 2 本立てにする**のが設計の要点。
 *
 * - **ユーザー名 + IP** で厳しく（{@see LOGIN_PER_USER} 回/分）: 1 アカウントへの総当たりを止める。
 *   守りたいのはこちらで、`security.md` が「認証系は特に厳しく」と言う対象。
 * - **IP のみ** で緩く（{@see PER_IP} 回/分）: 大量試行の頭打ち。IP だけで厳しく絞ると、
 *   同一 NAT 配下の利用者や CI を巻き込む（E2E はフル実行で 1 つの Runner IP から
 *   数十件の登録・ログインを投げる）。
 *
 * 保存は {@see LoginAttemptTable} に委ねる。ここは「何回までか」「何で区切るか」だけを持ち、
 * 保存先を差し替えても方針が動かないようにしている。
 *
 * 読み比べ（docs/12-code-reading-guide.md）:
 * - laravel-fullstack / laravel-api: 同じ 2 本立てを `AppServiceProvider::boot()` の
 *   `RateLimiter::for()` で宣言し、ルートに `throttle:<name>` を付けるだけで済む。
 *   キーの組み立て・回数の記録・期限切れの掃除はフレームワークが持っている。
 *   本クラスと {@see LoginAttemptTable} は、**その内訳が展開された形**にあたる。
 */
class AuthThrottle
{
    /** 同一ユーザー名 + IP の組で許す試行回数（1 分あたり） */
    public const LOGIN_PER_USER = 5;

    /**
     * 同一 IP で許す試行回数（1 分あたり）。ログイン・登録で共通。
     *
     * E2E の実測から決めている。laminas はフル実行 1 回で **15 秒に 33 件**を 1 つの Runner IP
     * から投げる（分換算で約 132 件/分）。60 にすると単発では通るが、**連続実行すると
     * 33 + 33 = 66 で落ちる**。上限が正当なピーク流量より低い状態は、アプリの欠陥ではない
     * 理由で E2E を落とすため、実測の 3 倍以上を確保する。
     *
     * 守りの主役は {@see LOGIN_PER_USER}（1 アカウントへの総当たり）であり、こちらは
     * 暴走した自動試行の頭打ちという位置づけ。両 Laravel も同じ値にしている（docs/06）。
     */
    public const PER_IP = 120;

    /** 数える期間（秒） */
    public const WINDOW_SECONDS = 60;

    public function __construct(private LoginAttemptTable $attempts)
    {
    }

    /** 特定アカウントへの試行が上限に達しているか */
    public function isUserBlocked(string $username, string $ip): bool
    {
        return $this->attempts->tooManyAttempts(
            $this->loginKey($username, $ip),
            self::LOGIN_PER_USER,
            self::WINDOW_SECONDS
        );
    }

    /** IP 単位の上限に達しているか（登録・入力不備の試行もここで数える） */
    public function isIpBlocked(string $ip): bool
    {
        return $this->attempts->tooManyAttempts($this->ipKey($ip), self::PER_IP, self::WINDOW_SECONDS);
    }

    /** ログイン失敗を記録する（ユーザー名単位と IP 単位の両方） */
    public function recordLoginFailure(string $username, string $ip): void
    {
        $this->attempts->record($this->loginKey($username, $ip));
        $this->attempts->record($this->ipKey($ip));
    }

    /** 認証を伴わない試行（入力不備・登録）を IP 単位でだけ記録する */
    public function recordIpAttempt(string $ip): void
    {
        $this->attempts->record($this->ipKey($ip));
    }

    /** ログイン成功時に、そのユーザー名の試行履歴を捨てる */
    public function clearLoginAttempts(string $username, string $ip): void
    {
        $this->attempts->clear($this->loginKey($username, $ip));
    }

    /**
     * ユーザー名は小文字に揃える。揃えないと大文字にするだけで別キー扱いになり、
     * 総当たりが素通りする（両 Laravel 側も同じ理由で email を小文字化している）。
     */
    private function loginKey(string $username, string $ip): string
    {
        return 'login|' . mb_strtolower($username) . '|' . $ip;
    }

    private function ipKey(string $ip): string
    {
        return 'ip|' . $ip;
    }
}
