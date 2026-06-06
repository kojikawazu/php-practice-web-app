# 07. API 仕様書（API Specification）

API のエンドポイント・入出力・エラー仕様を定義する。対象は `apps/laravel-api`（JSON API）。

## 認証（Sanctum トークン）

| メソッド | パス | 概要 |
|---------|------|------|
| POST | `/api/register` | ユーザー登録 → `{user, token}` を返す |
| POST | `/api/login` | ログイン → `{user, token}` を返す |
| POST | `/api/logout` | 現在のトークンを失効（要トークン）|

取得した token を `Authorization: Bearer <token>` ヘッダで送ると保護エンドポイントにアクセスできる。

## エンドポイント一覧

ベース URL: `http://localhost:8002`（nginx 経由）。`apiResource('tasks')` による標準 CRUD。**すべて `auth:sanctum` で保護**され、ログインユーザー本人のタスクのみ操作可能。

| メソッド | パス | 概要 | 認証 |
|---------|------|------|------|
| GET | `/api/tasks` | 自分のタスク一覧 | Bearer |
| POST | `/api/tasks` | タスク作成 | Bearer |
| GET | `/api/tasks/{id}` | タスク取得（他人のは404）| Bearer |
| PUT/PATCH | `/api/tasks/{id}` | タスク更新（他人のは404）| Bearer |
| DELETE | `/api/tasks/{id}` | タスク削除（他人のは404）| Bearer |

## リクエスト / レスポンス形式

- リクエスト: `Content-Type: application/json`、`Accept: application/json`
- Task オブジェクト: `{ "id": int, "title": string, "done": bool, "created_at": ..., "updated_at": ... }`

作成例:
```
POST /api/tasks  {"title": "牛乳を買う"}
→ 201  {"id":1,"title":"牛乳を買う","done":false, ...}
```

## バリデーション

| フィールド | ルール |
|-----------|--------|
| title | required / string / max:255 |
| done | sometimes / boolean |

## エラーハンドリング

| ステータス | 条件 |
|-----------|------|
| 201 | 作成成功（register もトークン付きで 201）|
| 204 | 削除・ログアウト成功 |
| 401 | 未認証（トークンなし/無効）|
| 404 | 該当 id のタスクが存在しない、または他人のタスク |
| 422 | バリデーションエラー（`errors` に詳細） |
