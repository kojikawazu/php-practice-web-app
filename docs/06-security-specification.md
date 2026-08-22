# 06. セキュリティ仕様書（Security Specification）

認証・認可・データ保護など、セキュリティ要件を定義する。

## 認証

| アプリ | 方式 | 実装 |
|--------|------|------|
| laravel-fullstack | セッション認証 | 自前 `AuthController`（`Auth::attempt` / `Auth::login`）、`auth`/`guest` ミドルウェア |
| laravel-api | トークン認証 | Laravel Sanctum（personal access token、`auth:sanctum`）|
| laminas | セッション認証 | `laminas-authentication`（identity を Session Storage に保持）+ bcrypt 照合 |

- パスワードは全アプリ bcrypt でハッシュ化（Laravel は `'password' => 'hashed'` キャスト、Laminas は `PasswordHasher`）。
- 登録時のパスワードは 8 文字以上を必須とする。

## 認可

- タスクは作成ユーザーに紐付き（`tasks.user_id`）、**ログインユーザー本人のタスクのみ**閲覧・更新・削除できる。
- 他人のタスクへの操作は存在を伏せて 404（Laravel）/ 一覧・削除対象から除外（Laminas）とする。
- ロールベースの権限制御は本サンプルの範囲外（学習課題として拡張可能）。

## 暗号化・データ保護

- パスワードは bcrypt でハッシュ化して保存（平文は保持しない）。
- API トークンは Sanctum がハッシュ化して保存し、平文は発行時のみ返却（一覧では非公開）。
- 通信の TLS 化・保存時暗号化は本番化時の対応事項（ローカル学習環境では未適用）。

## 脆弱性対策

- **XSS**: 出力エスケープ（Blade `{{ }}` / Laminas `escapeHtml`）。
- **CSRF**: Laravel web フォームは `@csrf` トークン。API は Cookie を使わないトークン認証で対象外。
- **SQL インジェクション**: Eloquent / Laminas TableGateway のバインドパラメータ経由でクエリを構築（生 SQL の文字列結合をしない）。
- **認可**: タスクは `user_id` でスコープし、他人のリソースは 404 / 対象外。
- **アップロード画像**: 公開ディレクトリ外（名前付きボリューム）に保存し、アプリ経由の所有者チェック付きルートでのみ配信（URL を知っても他人は閲覧不可）。`image`/`mimes`/`max:2048` で種別・サイズを検証。
- **CSP（Content-Security-Policy）**: 全レスポンスにヘッダーを付け、XSS が混入したときの被害をブラウザ側で抑える（下記「CSP の方針」）。
- **SSRF 対策（URL プレビュー）**: ユーザー登録 URL をサーバーが取得する機能（`LinkPreviewService`）は次で防御する:
  - スキームを `http`/`https` に限定（`url:http,https` ＋ サービス側で再確認）
  - ホストを DNS 解決し、public IP 以外（private/loopback/link-local/予約：例 `127.0.0.1`・`10/8`・`192.168/16`・`169.254.169.254`）は拒否
  - 検証した IP に接続をピン留め（`CURLOPT_RESOLVE`）して DNS リバインディングを防止
  - リダイレクトは自動追従せず、各ホップを再検証
  - 接続/読み込みタイムアウト・本文サイズ上限
  - 取得 HTML はそのまま出さず `title`/`og:image` だけ抽出し、表示時にエスケープ（`og:image` は http(s) のみ採用）
  - セキュリティの核 `isPublicIp()` は単体テストで担保

## CSP の方針

XSS 対策の出力エスケープが 1 箇所漏れても即被害にならないよう、多層防御としてすべての HTML/JSON 応答に `Content-Security-Policy` を付与する。

### 適用箇所（アプリごとに実装層が違う）

| アプリ | 実装 | 理由 |
|---|---|---|
| laravel-fullstack | `App\Http\Middleware\ContentSecurityPolicy`（グローバルミドルウェア）| リクエストごとに nonce を発行し `View::share` で Blade へ渡す |
| laravel-api | `App\Http\Middleware\ContentSecurityPolicy`（グローバルミドルウェア）| JSON のみのため nonce を持たない |
| laminas | `Application\Service\ContentSecurityPolicy` + `Module::onBootstrap` の `MvcEvent::EVENT_FINISH` リスナー | ミドルウェア層が無いため MVC ライフサイクル終端で付与する。nonce は ServiceManager の共有インスタンスで、ヘッダーと PHTML の値を一致させる |

**nginx では設定しない。** nonce はリクエストごとにアプリが生成して HTML へ埋め込む必要があり、nginx 側で同じ値を作れない。両方で設定するとヘッダーが重複し、ブラウザは全ポリシーの積を適用するため意図が読めなくなる。

### ポリシー

fullstack / laminas（HTML）:

| ディレクティブ | 値 | 意図 |
|---|---|---|
| `default-src` | `'self'` | 既定は自ホストのみ |
| `script-src` | `'self' 'nonce-<リクエストごと>' https://cdn.tailwindcss.com https://cdn.jsdelivr.net` | インライン script は nonce でのみ許可。`'unsafe-inline'`・`'unsafe-eval'` は付けない |
| `style-src` | `'self' 'unsafe-inline' https://cdn.jsdelivr.net` | Tailwind Play CDN が実行時に `<style>` を注入するため（下記の妥協） |
| `img-src` | fullstack=`'self' data: https:` / laminas=`'self' data:` | fullstack のみ OGP プレビュー画像が任意の外部ホストから来る |
| `object-src` / `frame-ancestors` | `'none'` | プラグイン埋め込みとクリックジャッキングを禁止 |
| `base-uri` / `form-action` | `'self'` | ベース URL 書き換えと送信先すり替えを禁止 |

api（JSON・画像バイナリのみ）: `default-src 'none'; base-uri 'none'; form-action 'none'; frame-ancestors 'none'`。

フレームワーク雛形の `/`（Laravel 既定のランディングページ）もこの対象になるが、本アプリの成果物ではないため、そのページの見た目のために API のポリシーを緩めない。

### nonce の生成

- Laravel: `Str::random(24)`（英数字）
- Laminas: `bin2hex(random_bytes(16))`（16 進数）。base64 だと `+` `/` `=` を含み、PHTML の `escapeHtmlAttr()` が実体参照へ変換するため、生 HTML と値が一致しなくなる（ブラウザは復号するので動作はする）

### 検証

- ヘッダー内容: `CspHeaderTest`（Laravel ×2）/ `CspHeaderIntegrationTest`（laminas）。`script-src` に `'unsafe-inline'` が混入する退行を検出する（CSP は nonce/hash があると `'unsafe-inline'` を無視するため、混入しても静かに壊れる）
- 実挙動: `e2e/tests/*/csp.spec.ts` で、実ブラウザでの CSP 違反ゼロ・Tailwind 適用・flatpickr 初期化・nonce 無しインライン script が実行されないことを確認する

### 開発環境と本番の差

現状は HTTP（compose）で動かすため `upgrade-insecure-requests` や `Strict-Transport-Security` は設定していない。本番化時は HTTPS 必須化（本書「通信」）とあわせてこれらを追加する。また `report-uri` / `report-to` による違反レポート収集も未導入で、CSP の破れは E2E でのみ検出している。

## 既知の注意点（学習用の妥協）

- **Laravel の依存にセキュリティアドバイザリ（解消済み）**: 初期構築時は Laravel 11 系全バージョンが advisory（CVE-2026-48019: デフォルト email ルールの CRLF インジェクション）該当で、`--no-security-blocking` で暫定導入していた。本 CVE は Laravel 11 系に修正版が存在しない（修正は 12.60.0+ / 13.10.0+）ため、**Laravel 12.61.1 へアップグレードして解消**した。両 Laravel アプリで `composer audit` がクリーンであることを確認済み。今後も依存更新時は `composer audit` を実行すること。
- **guzzle 依存の CVE（解消済み）**: Larastan 導入（依存更新）時の `composer audit` で `guzzlehttp/guzzle`（CVE-2026-55767 / CVE-2026-55568）・`guzzlehttp/psr7`（CVE-2026-55766）が検出されたため、両 Laravel アプリで guzzle 7.14+ / psr7 2.12+ へ更新して解消した（`composer audit` クリーンを確認）。guzzle は `laravel/framework` の依存。
- **DB 認証情報の平文**: 学習用のため `.env` / Laminas `global.php` に開発用認証情報（app/secret）を記載。公開・本番では秘密情報をリポジトリ管理外（local.php・シークレットストア）へ移すこと。
- **CSRF / セッション**: Laravel web・Laminas はセッション認証（フォームは CSRF 前提）。API は Sanctum のステートレストークン。
- ~~API 認証なし~~（解消済み）: `laravel-api` に Sanctum トークン認証を導入済み。
- **CSP の `style-src` に `'unsafe-inline'` が必要**: Tailwind CSS（Play CDN）は実行時に `<style>` 要素を DOM へ注入するため、nonce が付かない。CSP は `style-src` に nonce/hash があると `'unsafe-inline'` を無視する仕様のため、両立できない（実ブラウザで確認済み: `style-src 'self'` のみだと画面が完全に無スタイルになる）。`script-src` は nonce のみで運用し `'unsafe-inline'` を付けていない。Tailwind を CLI/Vite でビルドして self-host すれば `style-src` からも外せる。
- **フロントを CDN 読み込み（SRI 未設定）**: 日付ピッカー flatpickr（jsDelivr 固定版 @4.6.13）と Tailwind CSS（Play CDN）をブラウザから読み込んでいる。学習用のため Subresource Integrity（`integrity`）は付けていない（Tailwind Play CDN は動的スクリプトのため SRI 非対応）。本番化時は flatpickr に SRI 付与、Tailwind は CLI/Vite でビルドして self-host することが望ましい。
