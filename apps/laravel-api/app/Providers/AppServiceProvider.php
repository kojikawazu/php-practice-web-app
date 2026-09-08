<?php

declare(strict_types=1);

namespace App\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->configureRateLimiting();
    }

    /**
     * 認証系エンドポイントのレートリミットを定義する（`.claude/rules/security.md`）。
     *
     * Laravel 11 以降は `RouteServiceProvider` が無くなったため、名前付きリミッタの
     * 定義先はここになる。ルート側は `throttle:<name>` で参照する。
     *
     * ログインを 2 本立てにしているのが設計の要点。
     *
     * - **email + IP** で厳しく: 1 アカウントへの総当たり（ブルートフォース）を止める。
     *   守りたいのはこちらで、`security.md` が「認証系は特に厳しく」と言う対象。
     * - **IP のみ** で緩く: 大量試行の頭打ち。E2E はケースごとに**ユニークな email**を使うため
     *   厳しい方には当たらず、こちらの緩い上限だけを共有する。
     *
     * IP 側を厳しくできないのは、E2E がフル実行で 1 つの Runner IP から数十件の
     * 登録・ログインを投げるため（実測: api は 1 実行あたり登録 19 件・ログイン 2 件）。ここを絞ると
     * **アプリの欠陥ではない理由で E2E が落ちる**。数値は実測から決めている。
     *
     * 120 にしているのは、3 アプリで最も試行の多い laminas が **15 秒で 33 件**を投げるため
     * （分換算で約 132 件/分。issue #142 の実測）。60 だと単発では通るが**連続実行で落ちる**。
     * 3 アプリで同じ方針・同じ値にすることで、スタック差分だけが読み比べの対象になる。
     */
    private function configureRateLimiting(): void
    {
        RateLimiter::for('login', fn (Request $request) => [
            Limit::perMinute(5)->by($request->string('email')->lower()->toString().'|'.$request->ip()),
            Limit::perMinute(120)->by($request->ip() ?? 'unknown'),
        ]);

        RateLimiter::for('register', fn (Request $request) => Limit::perMinute(120)->by($request->ip() ?? 'unknown'));

        // トークン発行は認証済みのため、IP ではなくユーザー単位で数える
        // （同一 IP の別ユーザーを巻き込まない）。
        // auth:sanctum を通った後に評価されるため user() は存在する（未認証は throttle まで
        // 届かず 401。RateLimitTest で固定している）。?? を付けているのは万一そこが崩れたときに
        // null 参照で 500 にせず、guest としてまとめて数えるため。
        // `?->` ではなく `->` なのは、?? がプロパティアクセスの null を吸収するため
        // （`?->` だと Larastan が nullsafe.neverNull を出す）。
        RateLimiter::for('tokens', fn (Request $request) => Limit::perMinute(30)->by('user:'.($request->user()->id ?? 'guest')));
    }
}
