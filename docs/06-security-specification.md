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
- **SSRF 対策（URL プレビュー）**: ユーザー登録 URL をサーバーが取得する機能（`LinkPreviewService`）は次で防御する:
  - スキームを `http`/`https` に限定（`url:http,https` ＋ サービス側で再確認）
  - ホストを DNS 解決し、public IP 以外（private/loopback/link-local/予約：例 `127.0.0.1`・`10/8`・`192.168/16`・`169.254.169.254`）は拒否
  - 検証した IP に接続をピン留め（`CURLOPT_RESOLVE`）して DNS リバインディングを防止
  - リダイレクトは自動追従せず、各ホップを再検証
  - 接続/読み込みタイムアウト・本文サイズ上限
  - 取得 HTML はそのまま出さず `title`/`og:image` だけ抽出し、表示時にエスケープ（`og:image` は http(s) のみ採用）
  - セキュリティの核 `isPublicIp()` は単体テストで担保

## 既知の注意点（学習用の妥協）

- **Laravel の依存にセキュリティアドバイザリ（解消済み）**: 初期構築時は Laravel 11 系全バージョンが advisory（CVE-2026-48019: デフォルト email ルールの CRLF インジェクション）該当で、`--no-security-blocking` で暫定導入していた。本 CVE は Laravel 11 系に修正版が存在しない（修正は 12.60.0+ / 13.10.0+）ため、**Laravel 12.61.1 へアップグレードして解消**した。両 Laravel アプリで `composer audit` がクリーンであることを確認済み。今後も依存更新時は `composer audit` を実行すること。
- **guzzle 依存の CVE（解消済み）**: Larastan 導入（依存更新）時の `composer audit` で `guzzlehttp/guzzle`（CVE-2026-55767 / CVE-2026-55568）・`guzzlehttp/psr7`（CVE-2026-55766）が検出されたため、両 Laravel アプリで guzzle 7.14+ / psr7 2.12+ へ更新して解消した（`composer audit` クリーンを確認）。guzzle は `laravel/framework` の依存。
- **DB 認証情報の平文**: 学習用のため `.env` / Laminas `global.php` に開発用認証情報（app/secret）を記載。公開・本番では秘密情報をリポジトリ管理外（local.php・シークレットストア）へ移すこと。
- **CSRF / セッション**: Laravel web・Laminas はセッション認証（フォームは CSRF 前提）。API は Sanctum のステートレストークン。
- ~~API 認証なし~~（解消済み）: `laravel-api` に Sanctum トークン認証を導入済み。
- **フロントを CDN 読み込み（SRI 未設定）**: 日付ピッカー flatpickr（jsDelivr 固定版 @4.6.13）と Tailwind CSS（Play CDN）をブラウザから読み込んでいる。学習用のため Subresource Integrity（`integrity`）は付けていない（Tailwind Play CDN は動的スクリプトのため SRI 非対応）。本番化時は flatpickr に SRI 付与、Tailwind は CLI/Vite でビルドして self-host することが望ましい。
