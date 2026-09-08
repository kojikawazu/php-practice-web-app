# 07. API 仕様書（API Specification）

API のエンドポイント・入出力・エラー仕様を定義する。対象は `apps/laravel-api`（JSON API）。

## 認証（Sanctum トークン）

| メソッド | パス | 概要 |
| --------- | ------ | ------ |
| POST | `/api/register` | ユーザー登録 → `{user, token}` を返す |
| POST | `/api/login` | ログイン → `{user, token}` を返す |
| POST | `/api/logout` | 現在のトークンを失効（要トークン） |

取得した token を `Authorization: Bearer <token>` ヘッダで送ると保護エンドポイントにアクセスできる。

## トークン管理（要 Bearer）

| メソッド | パス | 概要 |
| --------- | ------ | ------ |
| GET | `/api/tokens` | 自分のトークン一覧（`id`/`name`/`abilities`/`last_used_at`/`expires_at`/`created_at`。ハッシュ値は非公開） |
| POST | `/api/tokens` | 名前付きトークン発行。平文は発行時のみ返却。`expires_in_days`(1〜365) で個別有効期限 |
| DELETE | `/api/tokens/{id}` | 自分のトークンを失効（他人/不在は 404） |

- 有効期限切れのトークンは Sanctum ガードが自動的に拒否（401）する。

## エンドポイント一覧

ベース URL: `http://localhost:8002`（nginx 経由）。`apiResource('tasks')` による標準 CRUD。**すべて `auth:sanctum` で保護**され、ログインユーザー本人のタスクのみ操作可能。

| メソッド | パス | 概要 | 認証 |
| --------- | ------ | ------ | ------ |
| GET | `/api/tasks` | 自分のタスク一覧（ページネーション） | Bearer |
| POST | `/api/tasks` | タスク作成（`title` 必須、`start_date`/`end_date` 任意） | Bearer |
| GET | `/api/tasks/{id}` | タスク取得（他人のは404） | Bearer |
| PUT/PATCH | `/api/tasks/{id}` | タスク更新（他人のは404） | Bearer |
| DELETE | `/api/tasks/{id}` | タスク削除（他人のは404） | Bearer |
| POST | `/api/tasks/{id}/duplicate` | タスク複製（「（コピー）」付き・201・他人のは404） | Bearer |
| GET | `/api/tasks/{id}/image` | 添付画像の取得（所有者のみ・他人/不在は404） | Bearer |

画像付きの作成・更新は `multipart/form-data` で `image`（jpeg/png/webp/gif・最大2MB）を送る。画像は公開ディレクトリ外（名前付きボリューム）に保存され、`image_url` から所有者のみ取得できる。

## 一覧のクエリパラメータ（GET /api/tasks）

| パラメータ | 既定 | 説明 |
| ----------- | ------ | ------ |
| `q` | （なし） | タイトル部分一致検索 |
| `per_page` | 5 | 1ページ件数（1〜50 にクランプ） |
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
| ----------- | -------- |
| title | required / string / max:255 |
| done | sometimes / boolean |
| start_date | nullable / date（`Y-m-d`） |
| end_date | nullable / date / 開始日以降（下記「タスク期間の整合性」） |
| image | nullable / image / mimes:jpeg,png,webp,gif / max:2048（KB = 2 MiB） |

### アップロードサイズの境界

画像の上限 2MB は **アプリのバリデーションを最終判定**にする。前段（nginx / PHP）で弾くと、統一エラー形式（`{"message": ..., "errors": {...}}`）を返せないため。

| リクエスト body | 応答 | 判定するのは |
| --- | --- | --- |
| 画像 ≤ 2 MiB | 201 / 200 | — |
| 画像 > 2 MiB かつ body ≤ 5 MiB | **422**（`errors.image`） | **Laravel**（`max:2048`） |
| body > 5 MiB | **413**（nginx の HTML。JSON ではない） | nginx（外側のハードガード） |

そのために各層の上限を **nginx < PHP** の順に並べ、nginx を通ったリクエストは必ず Laravel が判定するようにしている。

| 層 | 設定 | 値 |
| --- | --- | --- |
| nginx | `client_max_body_size`（`docker/nginx/default.conf`） | 5m |
| PHP | `upload_max_filesize` / `post_max_size`（`docker/php/uploads.ini`） | 5M / 6M |
| Laravel | `max:2048`（`TaskController`） | 2 MiB |

- **既定値のままだと壊れる。** nginx の既定は 1m、PHP の `upload_max_filesize` の既定は 2M。前者は 1MB 超の正当な画像を 413 で弾き、後者は 2 MiB ちょうどが境界に張り付く。
- クライアントは **413 では JSON が返らない**ことを前提にする（本文は nginx の HTML）。

### タスク期間の整合性（部分更新の扱い）

「終了日 ≥ 開始日」は、**更新後に確定する開始日・終了日の組**に対して検証する。リクエストに含まれない側は保存済みの値で補う。

| リクエスト | 判定に使う開始日 | 判定に使う終了日 |
| --- | --- | --- |
| 両方を送る | 送った値 | 送った値 |
| 片方だけ送る | 送った側は送った値 / 送らない側は**保存済みの値** | 同左 |
| どちらも送らない | 期間は変わらないため検証しない | 同左 |
| `null` を送る | `null`（＝日付なし）。相手側との比較は行わない | 同左 |

- **`null` 化は常に許可する。** 片方が日付なしになれば前後関係は生じないため（作成時に片方だけ指定できるのと同じ）。
- **`{"start_date": "2026-06-25", "end_date": null}` のように同時に送れば、旧終了日より後の開始日にできる。** 判定は更新後の組に対して行うため。
- **エラーはクライアントが送ったフィールドに載せる。** `end_date` を送っていれば `end_date`、`start_date` だけを送っていれば `start_date`。送っていないフィールドにエラーを返しても直しようがないため。
- 日付として解釈できない値を送った場合は、そのフィールドの `date` ルールが 422 にする（前後関係のエラーは重ねて返さない）。

> **実装上の注意**: Laravel の `after_or_equal:start_date` は比較対象を**リクエストの中から**探すため、片側だけの PATCH では比較対象が消えて素通りする。`store()` は必ず全項目を受け取るのでこの書き方でよいが、`update()` は保存済みの値とマージしてから検証する（issue #83）。

## エラーハンドリング

| ステータス | 条件 |
| ----------- | ------ |
| 201 | 作成成功（register もトークン付きで 201） |
| 204 | 削除・ログアウト成功 |
| 401 | 未認証（トークンなし/無効） |
| 404 | 該当 id のタスクが存在しない、または他人のタスク |
| 422 | バリデーションエラー（`errors` に詳細） |
| 429 | レートリミット超過（`Retry-After` / `X-RateLimit-*` ヘッダー付き。対象は `/api/login`・`/api/register`・`POST /api/tokens`） |
