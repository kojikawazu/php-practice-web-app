# 10. その他仕様書（Miscellaneous）

他の仕様書に属さない補足情報をまとめる。

## 用語集

このプロジェクトで使う用語を、実装例と結び付けて説明する。用語を見つけたら、まずここで「何か」と「このアプリではどこで使うか」を確認する。

### プロジェクト構成

| 用語 | 意味 | このプロジェクトでの例 |
|---|---|---|
| モノレポ | 複数のアプリケーションを 1 リポジトリで管理する構成。依存関係や実装はアプリごとに分け、共通の仕様書・開発環境を共有する。 | `apps/laravel-fullstack`、`apps/laravel-api`、`apps/laminas` と、共通の [`compose.yaml`](../compose.yaml)。 |
| fullstack / api / laminas | このリポジトリにある 3 アプリの略称。fullstack は Blade UI、api は JSON API、laminas は Laminas MVC の実装を学ぶための比較対象。 | [`docs/12-code-reading-guide.md`](12-code-reading-guide.md) の実装比較。 |
| Docker Compose | 複数コンテナをまとめて定義・起動する仕組み。PHP-FPM、nginx、MySQL を同じネットワークで動かす。 | [`compose.yaml`](../compose.yaml) と [`docker/nginx/default.conf`](../docker/nginx/default.conf)。 |
| テーブルプレフィックス | 共有 DB 内でアプリごとのテーブル名を分離する接頭辞。異なるフレームワークの学習用データが衝突しないようにする。 | `fs_` / `api_` / `lam_` の方針は [`docs/05-data-specification.md`](05-data-specification.md)。 |

### Laravel

| 用語 | 意味 | このプロジェクトでの例 |
|---|---|---|
| Controller | HTTP リクエストを受け、入力を検証してモデルやサービスへ処理を委譲するクラス。ビジネスロジックを集めすぎない入口になる。 | Blade 側の [`TaskController.php`](../apps/laravel-fullstack/app/Http/Controllers/TaskController.php)、API 側の [`TaskController.php`](../apps/laravel-api/app/Http/Controllers/TaskController.php)。 |
| Eloquent Model | DB テーブルのレコードを PHP オブジェクトとして扱う Laravel の ORM。リレーション、代入可能な属性、型変換を定義する。 | fullstack の [`Task.php`](../apps/laravel-fullstack/app/Models/Task.php) と API の [`Task.php`](../apps/laravel-api/app/Models/Task.php)。 |
| Migration | DB スキーマの変更履歴をコードとして管理し、環境ごとに同じ構造を再現する仕組み。 | [`create_tasks_table`](../apps/laravel-api/database/migrations/2026_06_04_000001_create_tasks_table.php) とユーザー紐付けの migration。 |
| Blade | Laravel のサーバーサイドテンプレート。`{{ }}` による HTML エスケープと `@csrf` による CSRF 対策を使う。 | [`tasks/index.blade.php`](../apps/laravel-fullstack/resources/views/tasks/index.blade.php)。 |
| Sanctum | Laravel の API トークン認証パッケージ。このアプリでは API 用に Bearer トークンを発行・検証する。 | [`TokenController.php`](../apps/laravel-api/app/Http/Controllers/TokenController.php) と [`sanctum.php`](../apps/laravel-api/config/sanctum.php)。 |

### Laminas

| 用語 | 意味 | このプロジェクトでの例 |
|---|---|---|
| Module | Controller、ルーティング、ビューなどをまとめる Laminas MVC の機能単位。 | [`Application` module](../apps/laminas/module/Application)。 |
| TableGateway | テーブル単位で SQL 実行を集約する Table Data Gateway パターン。コントローラへ SQL を散らさない。 | [`TaskTable.php`](../apps/laminas/module/Application/src/Model/TaskTable.php)。 |
| InputFilter | 入力値をフィルタリング・検証する仕組み。HTTP 入力の検証をコントローラから分離する。 | [`TaskInputFilter.php`](../apps/laminas/module/Application/src/InputFilter/TaskInputFilter.php)。 |
| Factory / DI | Factory が依存オブジェクトを組み立て、Controller などへ注入する仕組み。生成方法を利用側から分離する。 | [`TaskControllerFactory.php`](../apps/laminas/module/Application/src/Controller/TaskControllerFactory.php)。 |
| PHTML | PHP を埋め込める Laminas のビュー形式。表示値は `escapeHtml()` でエスケープする。 | [`task/index.phtml`](../apps/laminas/module/Application/view/application/task/index.phtml)。 |

### 認証・セキュリティ

| 用語 | 意味 | このプロジェクトでの例 |
|---|---|---|
| 認証 (Authentication) | 操作する利用者が誰かを確認すること。fullstack / laminas はセッション、api はトークンを使う。 | 認証方式と比較は [`docs/06-security-specification.md`](06-security-specification.md)。 |
| 認可 (Authorization) / 所有者スコープ | 認証済み利用者に、そのデータを操作する権限があるかを確認すること。タスクは作成者本人に限定する。 | Laravel の [`TaskController.php`](../apps/laravel-api/app/Http/Controllers/TaskController.php)、Laminas の [`TaskTable.php`](../apps/laminas/module/Application/src/Model/TaskTable.php)。 |
| CSRF | 利用者のログイン状態を悪用して、別サイトから意図しないリクエストを送らせる攻撃。セッション認証のフォームでは対策が必要。 | Blade フォームの `@csrf` と [`docs/06-security-specification.md`](06-security-specification.md)。 |
| SSRF | サーバーに外部 URL を取得させ、内部ネットワーク等へアクセスさせる攻撃。URL プレビュー機能では取得先を検証する。 | fullstack の [`LinkPreviewServiceTest.php`](../apps/laravel-fullstack/tests/Unit/LinkPreviewServiceTest.php)。 |

### テスト

| 用語 | 意味 | このプロジェクトでの例 |
|---|---|---|
| Unit Test | DB や HTTP を使わず、クラスや関数の小さな単位を検証するテスト。 | Laravel の [`TaskModelTest.php`](../apps/laravel-api/tests/Unit/TaskModelTest.php)、Laminas の [`TaskTest.php`](../apps/laminas/module/Application/test/Model/TaskTest.php)。 |
| Feature Test | Laravel の HTTP 層・ミドルウェア・DB を含め、機能単位で振る舞いを確認するテスト。 | [`TaskTest.php`](../apps/laravel-fullstack/tests/Feature/TaskTest.php) と [`TaskApiTest.php`](../apps/laravel-api/tests/Feature/TaskApiTest.php)。 |
| Integration Test | 複数の層や実コンポーネントの連携を検証するテスト。Laminas ではアプリケーションを起動して Controller を確認する。 | [`TaskControllerIntegrationTest.php`](../apps/laminas/module/Application/test/Integration/TaskControllerIntegrationTest.php)。 |
| E2E (End-to-End) Test | ブラウザや HTTP クライアントから、利用者の操作シナリオを通して検証するテスト。 | [`e2e/tests/fullstack/task-crud.spec.ts`](../e2e/tests/fullstack/task-crud.spec.ts) などの Playwright テスト。 |
| ライブ smoke | 起動中のアプリへ実際にリクエストし、最低限の動作を手動確認する検証。自動テストを補完する。 | 実行方法は [`README.md`](../README.md) の起動・使い方を参照。 |

## 参照資料

- ルート: `README.md`（起動・使い方）、`LICENSE`（MIT）、`CLAUDE.md`（Claude Code 向け指示）、`AGENTS.md`（Codex 向け指示）、`.claude/rules/`（開発ルールの正本）
- 仕様書: `docs/01`〜`docs/11`
- 外部: Laravel / Laminas / Sanctum / flatpickr の各公式ドキュメント

## 付録・注記（主要な決定事項）

- **Laravel 11 → 12 へ移行**: CVE-2026-48019 が 11 系に修正版なしのため（`docs/06`）。
- **テストは SQLite in-memory**: 共有 MySQL を `RefreshDatabase` の全 DROP から守るため。prefix 動作は実 DB マイグレーションで確認。
- **Laminas 認証は自前 bcrypt 照合 + AuthenticationService 保存**: DbTable アダプタより bcrypt と相性が良いため。
- **flatpickr は CDN 読み込み（SRI 未設定）**: 学習用トレードオフ（`docs/06`）。
- **アプリ別 ExampleTest 残置**: スケルトン由来。整理は任意。
- **ライセンスは MIT（ルート `LICENSE`）**: 学習用に公開するため利用条件を明示する。`apps/laminas` のみスケルトン由来の BSD-3-Clause（`apps/laminas/LICENSE.md`）が併存し、Laminas の著作権表示はそのまま残す。
