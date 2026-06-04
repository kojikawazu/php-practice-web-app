# 07. API 仕様書（API Specification）

API のエンドポイント・入出力・エラー仕様を定義する。対象は `apps/laravel-api`（JSON API）。

## エンドポイント一覧

ベース URL: `http://localhost:8002`（nginx 経由）。`apiResource('tasks')` による標準 CRUD。

| メソッド | パス | 概要 | 認証 |
|---------|------|------|------|
| GET | `/api/tasks` | タスク一覧 | なし（学習用） |
| POST | `/api/tasks` | タスク作成 | なし |
| GET | `/api/tasks/{id}` | タスク取得 | なし |
| PUT/PATCH | `/api/tasks/{id}` | タスク更新 | なし |
| DELETE | `/api/tasks/{id}` | タスク削除 | なし |

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
| 201 | 作成成功 |
| 204 | 削除成功 |
| 404 | 該当 id のタスクが存在しない |
| 422 | バリデーションエラー（`errors` に詳細） |
