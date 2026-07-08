# PHP スタックルール（Laravel / Laminas）

3 アプリ（laravel-fullstack / laravel-api / laminas）を同一題材で実装する際の作法を統一する。読み比べの詳細は `docs/12-code-reading-guide.md` を参照。

## 共通方針

- **所有者スコープ必須**: タスクは `tasks.user_id` に紐付け、ログインユーザー本人のみ操作可能にする。一覧はリレーション/クエリで本人に限定し、単発取得は他人のものを弾く（Laravel=404 / Laminas=対象外）。
- **共有 DB とプレフィックス**: 全アプリが 1 つの MySQL DB（`php_practice`）を共有し、テーブルプレフィックス（`fs_` / `api_` / `lam_`）で名前空間を分離する（`docs/05`）。新テーブル追加時もプレフィックス方針を守る。
- **SQL は必ずバインド**: Eloquent / TableGateway のバインドパラメータ経由でクエリを組む。生 SQL の文字列結合は禁止（`docs/06`）。

## Laravel（fullstack / api）

- **DB アクセス**: Eloquent ORM。`$fillable` で代入許可カラムを、`$casts` で型変換（`done`→bool、`start_date`→date）を明示する。
- **所有者スコープ**: `Auth::user()->tasks()` / `$request->user()->tasks()` のリレーション経由でスコープし、単発取得は `abort_if($task->user_id !== $userId, 404)` で他人のを 404 にする。
- **バリデーション**: コントローラ内で `$request->validate([...])`。日付は `'end_date' => ['nullable','date','after_or_equal:start_date']` のようにルール配列で宣言。
- **fullstack**: セッション認証（`Auth::attempt` → `session()->regenerate()`）、Blade で出力（`{{ }}` エスケープ）、web フォームは `@csrf`。
- **api**: Sanctum トークン認証（`auth:sanctum`）。ルートは `apiResource()` で定義。レスポンスは JSON のみ。`image_path` 等の生値は `$hidden`/アクセサで隠蔽し `image_url` を返す（`docs/07`）。

## Laminas

- **DB アクセス**: TableGateway（明示 SQL）。所有者条件は `getForUser($id, $userId)` / `fetchPageByUser()` のように **Table 層のメソッド**へ寄せ、コントローラは薄く保つ。
- **モデル**: `Task::exchangeArray()` で連想配列→オブジェクトの型整形を行う（`'0'`→false 等。`TaskTest` が仕様）。
- **DI**: コントローラ・サービスは `*Factory` 経由で依存を注入する（自動解決に頼らない）。
- **認証**: `laminas-authentication`（Session Storage に identity 保持）+ 自前 bcrypt（`PasswordHasher`）。各アクション冒頭で `hasIdentity()` を判定し、未認証は login へリダイレクト。
- **バリデーション**: `src/InputFilter/*.php`（フィルタ + バリデータ）。メッセージは `ErrorFormatter::flatten()` で一覧化。
- **出力**: PHTML で `escapeHtml()` を通す。

## 仕様変更時

- 仕様を変えるときは 3 アプリ（fullstack / api / laminas）への影響を確認し、アプリ差分は各仕様書に明記する（`.claude/rules/documentation.md` の影響マップに従う）。
