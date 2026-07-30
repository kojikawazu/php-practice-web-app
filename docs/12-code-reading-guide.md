# 12. コードリーディングガイド（Code Reading Guide）

このドキュメントは、3 アプリ（**laravel-fullstack** / **laravel-api** / **laminas**）を比較しながらコードを読む際のナビゲーションガイドです。同じ題材「タスク CRUD + 認証」を 3 フレームワークでどう実装しているかを、同じ観点で並べて読めるようにしています。

仕様の「何を作るか」は `docs/01`〜`docs/11` を参照。本書は「**どのファイルをどの順で読むか**」に特化します。

---

## コード側の導線（`読み比べ:` コメント）

主要な対比軸となるクラス（13 箇所）には、クラス冒頭の DocBlock に **`読み比べ（docs/12-code-reading-guide.md Step N）:`** で始まる導線を置いています。他 2 アプリの対応ファイルと、その差分の要点を数行で示します。

```bash
grep -rn "読み比べ" apps/ --include="*.php"   # 対比マップを一覧する
```

コードを先に開いた場合はここから本書へ、本書を先に読んだ場合は各 Step のファイル一覧からコードへ、どちらの向きでも辿れます。

なお、**PHP やフレームワークの文法解説はコードに書きません**（実装が変わったときに嘘になりやすく、`.claude/rules/coding-standards.md` の PHPDoc 方針にも反するため）。解説は本書と用語集（`docs/10-miscellaneous-specification.md`）に置き、コード内のコメントは「なぜこう書いたか」と「どこと比べるか」に限定します。

---

## プロジェクト構成の対比

| 観点 | laravel-fullstack | laravel-api | laminas |
|---|---|---|---|
| ディレクトリ | `apps/laravel-fullstack/` | `apps/laravel-api/` | `apps/laminas/` |
| フレームワーク | Laravel 12 + Blade | Laravel 12（JSON API）| Laminas MVC |
| レスポンス | HTML（Blade）| JSON | HTML（PHTML）|
| 認証方式 | セッション（`Auth::attempt`）| Sanctum トークン（Bearer）| `laminas-authentication`（Session Storage）|
| DB アクセス | Eloquent ORM | Eloquent ORM | TableGateway（Table Data Gateway）|
| バリデーション | `$request->validate()` | `$request->validate()` | InputFilter クラス |
| ルーティング | `routes/web.php` | `routes/api.php` | `config/module.config.php`（配列）|
| テーブル prefix | `fs_` | `api_` | `lam_` |
| URL | http://localhost:8001 | http://localhost:8002 | http://localhost:8003 |

---

## 読む順番（推奨）

### Step 1: ルーティングから全体像を把握する

まずどの URL がどのコントローラー/アクションに繋がっているかを確認する。3 アプリで「ルートの書き方」が大きく異なるのが最初の見どころ。

**laravel-fullstack** — `routes/web.php`

```php
Route::middleware('guest')->group(function () {
    Route::get('/login',  [AuthController::class, 'showLogin'])->name('login');
    Route::post('/login', [AuthController::class, 'login']);
});
Route::middleware('auth')->group(function () {
    Route::get('/tasks', [TaskController::class, 'index'])->name('tasks.index');
    Route::post('/tasks/confirm', [TaskController::class, 'storeConfirm']); // 2ステップ確認
    Route::post('/tasks', [TaskController::class, 'store']);
    // ... edit / update / duplicate / toggle / destroy / image
});
```

**laravel-api** — `routes/api.php`

```php
Route::post('/register', [AuthController::class, 'register']); // 公開
Route::post('/login',    [AuthController::class, 'login']);    // 公開
Route::middleware('auth:sanctum')->group(function () {
    Route::apiResource('tasks', TaskController::class);        // CRUD を一括定義
    Route::post('/tasks/{task}/duplicate', [TaskController::class, 'duplicate']);
    Route::get('/tokens', [TokenController::class, 'index']);  // トークン管理
});
```

**laminas** — `module/Application/config/module.config.php` の `router.routes`

```php
'tasks' => [
    'type' => Segment::class,
    'options' => [
        'route'    => '/tasks[/:action[/:id]]',   // /tasks/edit/5 のような形
        'defaults' => ['controller' => TaskController::class, 'action' => 'index'],
    ],
],
'controllers' => [ 'factories' => [
    TaskController::class => TaskControllerFactory::class,   // DI はファクトリ経由
] ],
```

> **差分ポイント**:
>
> - fullstack は名前付きルート + `middleware('guest'/'auth')` グループで認証境界を表現。
> - api は `apiResource()` 1 行で 7 つの REST ルートを生成し、`auth:sanctum` ミドルウェアで一括保護。
> - laminas はルートを **PHP 配列**で宣言し、`action` をクエリ的に切り替える（`/tasks/edit/:id`）。コントローラーは `*Factory` 経由で DI される点も Laravel と対照的。

---

### Step 2: 認証の仕組みを読む

認証はアプリ全体の根幹。ここを押さえると他のコードが読みやすくなる。3 方式の違いが本リポジトリの学習価値の中心。

**laravel-fullstack（セッション認証）**

| ファイル | 役割 |
|---|---|
| `app/Http/Controllers/AuthController.php` | 登録・ログイン・ログアウト |
| `routes/web.php` の `middleware('auth'/'guest')` | 認証境界 |

読むポイント:

- `Auth::attempt($credentials)` で照合 → `$request->session()->regenerate()` でセッション固定攻撃対策
- 登録は `User::create()` 後に `Auth::login($user)` で即ログイン
- ログアウトは `Auth::logout()` + `session()->invalidate()`

**laravel-api（Sanctum トークン認証）**

| ファイル | 役割 |
|---|---|
| `app/Http/Controllers/AuthController.php` | 登録/ログイン → トークン発行 |
| `app/Http/Controllers/TokenController.php` | トークンの一覧・発行・失効 |
| `app/Models/User.php`（`HasApiTokens`）| `createToken()` を提供 |

読むポイント:

- `$user->createToken('api')->plainTextToken` で平文トークンを生成（**平文は発行時のみ返却**）
- ログインは `Hash::check($password, $user->password)` で手動照合（セッションを使わない）
- ログアウトは `$request->user()->currentAccessToken()->delete()` で **そのトークンだけ**失効
- 保護ルートは `auth:sanctum` が `Authorization: Bearer <token>` を検証

**laminas（laminas-authentication + 自前 bcrypt）**

| ファイル | 役割 |
|---|---|
| `module/Application/src/Controller/AuthController.php` | ログイン・登録・ログアウト |
| `module/Application/src/Service/PasswordHasher.php` | bcrypt ハッシュ/照合 |
| `module/Application/src/Model/UserTable.php` | `findByUsername` / `create` |

読むポイント:

- DI: `AuthControllerFactory` が `AuthenticationService` / `UserTable` / `PasswordHasher` を注入
- ログイン成功時は `$this->auth->getStorage()->write((object)['id'=>..., 'username'=>...])` で identity をセッションに保存
- 照合は `$this->hasher->verify($password, $user->password)`（DbTable アダプタではなく自前 bcrypt）
- ログアウトは `$this->auth->clearIdentity()`

> **差分ポイント**: 「ログイン状態をどこに持つか」が三者三様 — fullstack=サーバーセッション / api=DB のトークン（ステートレス）/ laminas=AuthenticationService の Session Storage。

---

### Step 3: モデル / データアクセス層を読む

「同じ Task テーブルを、ORM と TableGateway でどう扱うか」の対比。

**laravel-fullstack / laravel-api（Eloquent ORM）**

```text
app/Models/Task.php   # $fillable, $casts, user() belongsTo
app/Models/User.php   # tasks() hasMany（api は HasApiTokens も）
```

読むポイント:

- `Task::$fillable` に許可カラム、`$casts` で `done`→bool・`start_date`→date を型変換
- リレーション: `User hasMany Task` / `Task belongsTo User`
- api 版 Task は `image_url` アクセサで `image_path` 生値を隠蔽（`$hidden`/`$appends` を確認）

**laminas（Model + TableGateway）**

```text
module/Application/src/Model/Task.php          # exchangeArray / getArrayCopy
module/Application/src/Model/TaskTable.php      # SQL を組み立てる DB アクセス
module/Application/src/Model/UserTable.php
```

読むポイント:

- `Task::exchangeArray()` が連想配列 → オブジェクトの詰め替え（`'0'`→false 等の型整形をここで実施。`TaskTest` が仕様）
- `TaskTable` の `getForUser($id, $userId)` / `fetchPageByUser()` / `countByUser()` / `deleteForUser()` が **所有者スコープを SQL レベル**で表現
- Eloquent のような「マジック」はなく、`TableGateway` に対し明示的にクエリを書く

**モデルの関連図（3 アプリ共通の論理構造）**

```text
User (fs_users / api_users / lam_users)
 └── has many  Task (fs_tasks / api_tasks / lam_tasks)   ※ tasks.user_id で所有
```

---

### Step 4: コントローラーと「所有者スコープ」を読む

3 アプリ最大の比較ポイント。「他人のタスクを操作させない」をどう書くか。

**laravel-fullstack** — `app/Http/Controllers/TaskController.php`

```php
$tasks = Task::where('user_id', Auth::id())->...        // 一覧を本人に限定
Auth::user()->tasks()->create($data);                   // 作成時に user_id を自動付与
abort_if($task->user_id !== Auth::id(), 404);           // 他人のは存在を伏せて 404
```

**laravel-api** — `app/Http/Controllers/TaskController.php`

```php
$request->user()->tasks()->...                          // リレーション経由でスコープ
abort_if($task->user_id !== $request->user()->id, 404);
return response()->json($task, 201);                    // 返却は JSON のみ
```

**laminas** — `module/Application/src/Controller/TaskController.php`

```php
if (! $this->auth->hasIdentity()) { return $this->redirect()->toRoute('login'); }
$user = $this->auth->getIdentity();
$task = $this->table->getForUser($id, (int) $user->id); // スコープは Table メソッド側
```

> **差分ポイント**:
>
> - Laravel 2 アプリは `Auth::user()->tasks()` / `$request->user()->tasks()` のリレーション経由で自然にスコープされ、単発取得は `abort_if(... 404)` で他人のリソースを 404 にする。
> - laminas は認証チェックを各アクション冒頭で明示し、所有者条件は `getForUser($id, $userId)` のように **Table 層へ寄せる**（コントローラーは薄い）。
> - レスポンスも対照的: fullstack=`redirect()->route(...)` / api=`response()->json(...)` / laminas=`ViewModel` or `redirect()->toRoute(...)`。

---

### Step 5: バリデーションを読む

| アプリ | 方式 | 場所 |
|---|---|---|
| laravel-fullstack | `$request->validate([...])` | 各コントローラーメソッド内 |
| laravel-api | `$request->validate([...])` | 同上（失敗時 422 JSON）|
| laminas | InputFilter クラス | `src/InputFilter/*.php` |

読むポイント:

- Laravel は `'end_date' => ['nullable','date','after_or_equal:start_date']` のようにルールを配列で宣言。失敗時、web は `$errors` でフォーム再表示、api は 422 + `errors`。
- laminas は `TaskInputFilter` / `RegisterInputFilter` / `LoginInputFilter` がフィルタ（`StringTrim` 等）＋バリデータ（必須・長さ・日付）を担う。`ErrorFormatter::flatten()` でメッセージを一覧化。
- これら InputFilter は単体テスト（`test/InputFilter/*Test.php`）が仕様書を兼ねる。

---

### Step 6: 固有機能を読む（差がつくところ）

各アプリにしかない機能。フレームワークの「らしさ」が出る。

**laravel-fullstack 固有**

| ファイル | 内容 |
|---|---|
| `app/Services/LinkPreviewService.php` | URL の OGP プレビュー。**SSRF 対策の核**（`isPublicIp()` / `CURLOPT_RESOLVE` ピン留め）|
| `app/Services/BlockedUrlException.php` | 拒否時の例外 |
| `TaskController` の `storeConfirm` / `updateConfirm` / `duplicateConfirm` | 2 ステップ確認画面 |

読むポイント: `LinkPreviewService` はスキーム限定 → DNS 解決 → public IP 判定 → IP ピン留め → リダイレクト各ホップ再検証、の多段防御（`docs/06` と対応）。

**laravel-api 固有**

| ファイル | 内容 |
|---|---|
| `app/Http/Controllers/TokenController.php` | トークンの一覧/発行/失効、`expires_in_days` |

**laminas 固有**

| ファイル | 内容 |
|---|---|
| `src/Model/TaskTable.php` / `UserTable.php` | TableGateway による明示的 DB アクセス |
| `src/Controller/*Factory.php` | コントローラーの DI ファクトリ |
| `src/Service/PasswordHasher.php` | bcrypt ラッパ |

---

### Step 7: テストを読む（コードの仕様書）

テストは実装意図の最良のドキュメント。実装を読む前にテストを読むと早い。

**laravel-fullstack** — `tests/Feature/`, `tests/Unit/`

| ファイル | 内容 |
|---|---|
| `tests/Feature/AuthTest.php` | 登録&自動ログイン / 誤パスワード / メール重複 |
| `tests/Feature/`（Task 系）| 一覧/作成/トグル/編集/複製/画像/2ステップ確認/検索/ページネーション、他人タスク 404 |
| `tests/Unit/LinkPreviewServiceTest.php` | SSRF: public 許可 / private・loopback・link-local 拒否 |

**laravel-api** — `tests/Feature/`

| ファイル | 内容 |
|---|---|
| `tests/Feature/AuthApiTest.php` | register/login のトークン返却、token 無し 401 |
| `tests/Feature/`（Task / Token 系）| JSON CRUD、他人タスク 404、トークン一覧（ハッシュ非公開）|

**laminas** — `module/Application/test/`

| ファイル | 内容 |
|---|---|
| `test/Model/TaskTest.php` | `exchangeArray` の型整形 |
| `test/Service/PasswordHasherTest.php` | hash→verify、bcrypt 形式、ソルト差異 |
| `test/InputFilter/*Test.php` | Task/Register/Login の有効/無効入力 |

読むポイント:

- Laravel 2 アプリは **SQLite in-memory**（`RefreshDatabase`）で隔離実行。共有 MySQL を守る設計（`docs/08`）。
- laminas は認証/CRUD フローを PHPUnit 化せず **ライブ smoke**（curl + cookie）で検証。認証の核 bcrypt のみ `PasswordHasherTest` で単体保証（未了フォロー: `docs/11 #8`）。

---

## 重要な差分まとめ

| 観点 | laravel-fullstack | laravel-api | laminas |
|---|---|---|---|
| **ルート定義** | `web.php`（名前付き + middleware グループ）| `api.php`（`apiResource` + `auth:sanctum`）| `module.config.php`（配列 Segment）|
| **認証の維持** | サーバーセッション | DB のトークン（ステートレス）| Session Storage（identity）|
| **未認証時** | `guest`/`auth` ミドルウェアでリダイレクト | `auth:sanctum` → 401 | 各アクションで `hasIdentity()` 判定 → login へ |
| **DB アクセス** | Eloquent ORM | Eloquent ORM | TableGateway（明示 SQL）|
| **所有者スコープ** | `Auth::user()->tasks()` + `abort_if 404` | `$request->user()->tasks()` + `abort_if 404` | `getForUser($id, $userId)`（Table 層）|
| **バリデーション** | `$request->validate()` | `$request->validate()`（422 JSON）| InputFilter クラス |
| **レスポンス** | Blade + `redirect()->route()` | `response()->json()` | PHTML / `redirect()->toRoute()` |
| **DI** | コンテナ自動解決 | コンテナ自動解決 | `*Factory` を明示 |
| **固有機能** | 2 ステップ確認 / OGP プレビュー(SSRF) / 画像 | トークン管理 / Sanctum | TableGateway / InputFilter |

---

## 動作確認コマンド

### 起動（共通・リポジトリルート）

```bash
cp .env.example .env
make up          # docker compose up -d --build
make migrate     # Laravel 2 アプリのマイグレーション
```

### laravel-fullstack

```bash
# ブラウザで http://localhost:8001/tasks を開く（/register から登録）
```

### laravel-api

```bash
# 登録 → token を取得
curl -X POST http://localhost:8002/api/register \
  -H "Content-Type: application/json" -H "Accept: application/json" \
  -d '{"name":"テスト","email":"test@example.com","password":"password123"}'

# ログイン → token を取得
curl -X POST http://localhost:8002/api/login \
  -H "Content-Type: application/json" -H "Accept: application/json" \
  -d '{"email":"test@example.com","password":"password123"}'

# タスク一覧（token を置き換える）
curl http://localhost:8002/api/tasks \
  -H "Authorization: Bearer <token>" -H "Accept: application/json"
```

### laminas

```bash
# ブラウザで http://localhost:8003/tasks を開く（/register から登録）
```

### テスト実行

```bash
make test          # 3アプリ一括（fullstack 53 / api 41 / laminas 36）
make test-fs       # laravel-fullstack
make test-api      # laravel-api
make test-laminas  # laminas
```
