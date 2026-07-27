# PHP スタックルール（Laravel / Laminas）

3 アプリ（laravel-fullstack / laravel-api / laminas）を同一題材で実装する際の作法を統一する。読み比べの詳細は `docs/12-code-reading-guide.md` を参照。

## 共通方針

- **所有者スコープ必須**: タスクは `tasks.user_id` に紐付け、ログインユーザー本人のみ操作可能にする。一覧はリレーション/クエリで本人に限定し、単発取得は他人のものを弾く（Laravel=404 / Laminas=対象外）。
- **共有 DB とプレフィックス**: 全アプリが 1 つの MySQL DB（`php_practice`）を共有し、テーブルプレフィックス（`fs_` / `api_` / `lam_`）で名前空間を分離する（`docs/05`）。新テーブル追加時もプレフィックス方針を守る。
- **SQL は必ずバインド**: Eloquent / TableGateway のバインドパラメータ経由でクエリを組む。生 SQL の文字列結合は禁止（`docs/06`）。

## 監査列（created_at / updated_at）

監査列は**単一の層で自動設定し、業務ロジックから手で書かない**。コントローラ・サービスの各所で日時を詰めると、書き漏れ・書き分けが即データの不整合になる。

- **手動代入を禁止**する。`$task->updated_at = now();` のように業務コードで監査列へ値を代入しない。
- **カラム名を変えない**（`created_at` / `updated_at`）。3 アプリが同一 DB をプレフィックスで共有するため、名前を揃える（`docs/05`）。
- `created_at` は**更新しない**。更新処理で `created_at` を含めない。
- 作成者・更新者（`created_by` / `updated_by`）は**本アプリの要件外**とし、追加しない（監査要件が発生した場合のみ、同じく単一の層で自動注入する）。
- **例外**: シード・テストで日時を固定する場合のみ明示指定を許容する（Laravel は `Carbon::setTestNow()`）。本番コードパスに持ち込まない。

### 監査列: Laravel（fullstack / api）

- マイグレーションで `$table->timestamps()` を使い、**Eloquent の自動タイムスタンプに委ねる**。
- 監査列を `$fillable` に**入れない**（マスアサインメントで外部入力から上書きされる）。
- 更新時刻だけ進めたい場合は `$task->touch()` を使う。`$timestamps = false` / `saveQuietly()` による自動更新の停止は、理由をコメントに書ける場合に限る。

### 監査列: Laminas

- TableGateway には自動タイムスタンプがない。**`TaskTable` の save/insert/update に設定を集約**し、コントローラ・InputFilter では触らない（Table 層に寄せる方針は上記「DB アクセス」と同じ）。
- 集約先を 1 箇所にできない場合は、DB 側の `DEFAULT CURRENT_TIMESTAMP` / `ON UPDATE CURRENT_TIMESTAMP`（`docker/mysql/init/*.sql`）で担保する。**アプリと DB の二重設定にしない**（どちらが正か分からなくなる）。

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
