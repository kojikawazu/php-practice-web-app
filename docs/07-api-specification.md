# 07. API 仕様書（API Specification）

API のエンドポイント・入出力・エラー仕様を定義する。対象は `apps/laravel-api`（JSON API）。

## 認証（Sanctum トークン）

| メソッド | パス | 概要 |
|---------|------|------|
| POST | `/api/register` | ユーザー登録 → `{user, token}` を返す |
| POST | `/api/login` | ログイン → `{user, token}` を返す |
| POST | `/api/logout` | 現在のトークンを失効（要トークン）|

取得した token を `Authorization: Bearer <token>` ヘッダで送ると保護エンドポイントにアクセスできる。

## トークン管理（要 Bearer）

| メソッド | パス | 概要 |
|---------|------|------|
| GET | `/api/tokens` | 自分のトークン一覧（`id`/`name`/`abilities`/`last_used_at`/`expires_at`/`created_at`。ハッシュ値は非公開）|
| POST | `/api/tokens` | 名前付きトークン発行。平文は発行時のみ返却。`expires_in_days`(1〜365) で個別有効期限 |
| DELETE | `/api/tokens/{id}` | 自分のトークンを失効（他人/不在は 404）|

- 有効期限切れのトークンは Sanctum ガードが自動的に拒否（401）する。

## エンドポイント一覧

ベース URL: `http://localhost:8002`（nginx 経由）。`apiResource('tasks')` による標準 CRUD。**すべて `auth:sanctum` で保護**され、ログインユーザー本人のタスクのみ操作可能。

| メソッド | パス | 概要 | 認証 |
|---------|------|------|------|
| GET | `/api/tasks` | 自分のタスク一覧（ページネーション）| Bearer |
| POST | `/api/tasks` | タスク作成（`title` 必須、`start_date`/`end_date` 任意）| Bearer |
| GET | `/api/tasks/{id}` | タスク取得（他人のは404）| Bearer |
| PUT/PATCH | `/api/tasks/{id}` | タスク更新（他人のは404）| Bearer |
| DELETE | `/api/tasks/{id}` | タスク削除（他人のは404）| Bearer |
| POST | `/api/tasks/{id}/duplicate` | タスク複製（「（コピー）」付き・201・他人のは404）| Bearer |
| GET | `/api/tasks/{id}/image` | 添付画像の取得（所有者のみ・他人/不在は404）| Bearer |

画像付きの作成・更新は `multipart/form-data` で `image`（jpeg/png/webp/gif・最大2MB）を送る。画像は公開ディレクトリ外（名前付きボリューム）に保存され、`image_url` から所有者のみ取得できる。

## 一覧のクエリパラメータ（GET /api/tasks）

| パラメータ | 既定 | 説明 |
|-----------|------|------|
| `q` | （なし）| タイトル部分一致検索 |
| `per_page` | 5 | 1ページ件数（1〜50 にクランプ）|
| `page` | 1 | ページ番号 |

レスポンスは Laravel の LengthAwarePaginator 形式（`data` 配列 + `total` / `per_page` / `current_page` / `last_page` 等のメタ）。

## リクエスト / レスポンス形式

- リクエスト: `Content-Type: application/json`、`Accept: application/json`
- Task オブジェクト: `{ "id": int, "title": string, "done": bool, "start_date": "Y-m-d"|null, "end_date": "Y-m-d"|null, "image_url": string|null, "created_at": ..., "updated_at": ... }`（`image_path` 生値は非公開）

作成例:

```http
POST /api/tasks  {"title": "牛乳を買う"}
→ 201  {"id":1,"title":"牛乳を買う","done":false, ...}
```

## バリデーション

| フィールド | ルール |
|-----------|--------|
| title | required / string / max:255 |
| done | sometimes / boolean |
| start_date | nullable / date（`Y-m-d`）|
| end_date | nullable / date / `after_or_equal:start_date` |
| image | nullable / image / mimes:jpeg,png,webp,gif / max:2048（KB）|

## エラーハンドリング

| ステータス | 条件 |
|-----------|------|
| 201 | 作成成功（register もトークン付きで 201）|
| 204 | 削除・ログアウト成功 |
| 401 | 未認証（トークンなし/無効）|
| 404 | 該当 id のタスクが存在しない、または他人のタスク |
| 422 | バリデーションエラー（`errors` に詳細） |
