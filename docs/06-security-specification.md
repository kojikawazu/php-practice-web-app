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

## 既知の注意点（学習用の妥協）

- **Laravel の依存にセキュリティアドバイザリ（解消済み）**: 初期構築時は Laravel 11 系全バージョンが advisory（CVE-2026-48019: デフォルト email ルールの CRLF インジェクション）該当で、`--no-security-blocking` で暫定導入していた。本 CVE は Laravel 11 系に修正版が存在しない（修正は 12.60.0+ / 13.10.0+）ため、**Laravel 12.61.1 へアップグレードして解消**した。両 Laravel アプリで `composer audit` がクリーンであることを確認済み。今後も依存更新時は `composer audit` を実行すること。
- **DB 認証情報の平文**: 学習用のため `.env` / Laminas `global.php` に開発用認証情報（app/secret）を記載。公開・本番では秘密情報をリポジトリ管理外（local.php・シークレットストア）へ移すこと。
- **CSRF / セッション**: Laravel web・Laminas はセッション認証（フォームは CSRF 前提）。API は Sanctum のステートレストークン。
- ~~API 認証なし~~（解消済み）: `laravel-api` に Sanctum トークン認証を導入済み。
- **flatpickr を CDN 読み込み（SRI 未設定）**: 日付ピッカーを jsDelivr の固定バージョン（@4.6.13）から読み込んでいる。学習用のため Subresource Integrity（`integrity`）は付けていない。本番化時は SRI ハッシュ付与、または npm 取得して self-host することが望ましい。
