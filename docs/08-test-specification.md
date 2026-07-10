# 08. テスト仕様書（Test Specification）

テストの戦略・ケース・品質目標を定義する。テスト分類（正常/準正常/異常）・原則は `.claude/rules/testing.md` 準拠。

## テストの粒度（単体 / IT / E2E）

「入力に対する期待結果」を見る**分類**（正常/準正常/異常）とは直交する軸として、**どこまでのレイヤを通すか**の粒度を定義する。

| 粒度 | 定義 | 本リポジトリでの実体 |
|------|------|----------------------|
| 単体（Unit） | 1 レイヤ/クラスの契約を隔離して検証 | Task モデル・PasswordHasher・InputFilter・**TaskTable（SQL 所有者スコープ）** |
| IT（統合／結合） | ルーティング → コントローラ → 認証 → データアクセス層 → 実 DB を横断で検証 | **Laravel の Feature テスト全般**（ミドルウェア認証→Eloquent→SQLite）／**laminas の `*IntegrationTest`**（`AbstractHttpControllerTestCase` で dispatch し、SQLite in-memory + 認証識別子を注入）|
| E2E | ブラウザ自動化で UI から通しで検証 | 未導入（`docs/11 #13` のバックログ）|

> laminas の IT は、bootstrap 後に ServiceManager の `AdapterInterface` を SQLite in-memory へ、`AuthenticationService` を NonPersistent ストレージ（識別子を直接注入）へ差し替えて実現する。MySQL・実セッションに依存せず、コントローラの認可分岐・リダイレクト・DB 反映という「配線」を検証する。

## テスト戦略

| アプリ | ツール | DB | 種別 |
|--------|--------|----|------|
| laravel-fullstack | PHPUnit（`php artisan test`） | SQLite in-memory | Feature（HTTP）=**IT** |
| laravel-api | PHPUnit（`php artisan test`） | SQLite in-memory（Unit は DB 不要） | Feature（JSON API）=**IT** + Unit（Task モデル）|
| laminas | PHPUnit（`vendor/bin/phpunit`） | IT・認可テストは SQLite in-memory（他はモデル/サービス/InputFilter 単体で DB 不要）| Unit（Task / PasswordHasher / InputFilter×3 / **TaskTable 所有者スコープ**）+ **IT（TaskController / AuthController を dispatch）**。実セッション永続のみライブ smoke 補完 |

> テストを SQLite in-memory にしている理由: `RefreshDatabase` は `migrate:fresh`（全テーブル DROP）を行うため、共有 MySQL に対して実行すると他アプリのテーブルを巻き込む。テストは隔離された in-memory DB で実行し、prefix 動作は実 DB へのマイグレーションで確認する。

## テストケース（CRUD + 認証）

異常系（準正常系含む）が正常系を上回ることを目安とし、**認可・境界値・不正入力**を優先的にカバーする（数合わせのための水増しはしない）。現状の実測は約 正常系 1 : 異常系 1.3。分類・原則は `.claude/rules/testing.md` 準拠。

| アプリ | 主な正常系 | 主な異常系 |
|--------|-----------|-----------|
| fullstack Task | 一覧/作成/トグル/編集/複製/日付付き作成/画像アップロード・所有者閲覧・差替・複製コピー/2ステップ確認(確認表示・確定まで未作成)/ページネーション(5件)/タイトル検索 | guest→login / 他人タスク非表示 / 空title / 他人のtoggle・destroy・update(確認)・edit・duplicate・image 404 / 非画像422 / 検索ヒットなし / 終了日<開始日 / 不正日付 |
| fullstack Auth | 登録&自動ログイン / ログイン / ログアウト | 誤パスワード / メール重複 / 確認不一致 / 短パスワード |
| api Task | 一覧/201作成/更新/複製(201)/日付付き作成/画像アップロード(image_url返却)・所有者取得/ページネーション(meta)/per_page/検索 | guest 401 / title欠落 422 / 長すぎ 422 / 更新時空title 422 / 終了日<開始日 422 / 不正日付 422 / 非画像 422 / 他人タスク view・delete・update・duplicate・image 404 / 検索ヒットなし |
| api Task model（単体・DB/アプリ不要） | title mass assign / done bool化 / 日付 Carbon 化(Y-m-d) / hidden・appends 設定 | done 既定false / '0'→false / 画像なしで image_url=null / toArray が user_id・image_path を隠す / 非fillable id は無視 |
| api Auth | register トークン / login トークン / logout | 誤パスワード 422 / メール重複 422 / 短パスワード 422 / token無し 401 |
| api Token | 一覧(ハッシュ非公開)/発行/期限付き発行/失効 | name必須422 / 不正expiry422 / 他人失効404 / guest401 / 期限切れトークン401 |
| laminas Task model | exchangeArray全項目 / getArrayCopy | 空配列デフォルト / '0'→false / 数値文字列→int / false→0 |
| laminas TaskTable（所有者スコープ・SQLite） | 本人タスクの取得/一覧/検索/作成/更新/削除 | 他人タスクの取得null / 他人タスクを削除しても消えない / user_id偽装updateが他人に及ばない / countが他人を除外 / 一覧が他人を除外 / 検索ヒットなし |
| laminas TaskController（**IT**・dispatch・SQLite） | 認証済み一覧が本人分のみ描画 / POST作成→302+user_idスコープ / 編集POST→更新 / 複製→「（コピー）」作成 / 削除→消える | ゲストは index・作成・削除で /login へ / 他人タスクの編集・削除・複製が無効 / 空title は未作成で再描画 / 不在id編集は一覧へ |
| laminas AuthController（**IT**・dispatch・SQLite） | 登録→ユーザー作成+302 / 正パスワードでログイン→/tasks / ログアウト→/login | 重複ユーザー名は拒否（既存PW不変） / 短パスワードは未作成 / 誤パスワード拒否 / 未登録ユーザー拒否 |
| fullstack LinkPreview(SSRF) | public IP 許可 / title・og:image 抽出 | private・loopback・link-local・予約IP 拒否 / 非http拒否 / 内部ホスト拒否 / og:image非http除外 |
| laminas PasswordHasher | hash→verify / bcrypt形式 | 誤パスワード / 空 / 不正ハッシュ / ソルトで毎回異なる |
| laminas InputFilter | Task/Register/Login の有効入力通過・StringTrim 整形・日付任意通過 | 必須欠落 / 空 / 空白のみ / 長すぎ(255超) / 短パスワード(8未満) / 不正日付 / 終了日<開始日 |

> laminas の認証フロー（register→login→logout）は、`AuthControllerIntegrationTest` で **IT として PHPUnit 化済み**（コントローラ→InputFilter→UserTable→DB→bcrypt 照合を通しで検証）。**実セッションの永続**（Cookie を跨いだ保護ページ維持）のみ CLI では再現しないため、その部分だけライブ smoke（curl + cookie）で補完する。認証ロジックの核（bcrypt）は `PasswordHasherTest` でも単体保証する。
> **認可（所有者スコープ）**は 2 段で担保する: SQL レベルは `TaskTableTest`（単体）、コントローラを通した横断フローは `TaskControllerIntegrationTest`（IT）で、他人タスクの編集・削除・複製が弾かれることを実データで検証する。

## 実行方法

```bash
make test          # 3アプリ一括
make test-fs       # laravel-fullstack
make test-api      # laravel-api
make test-laminas  # laminas
```

## カバレッジ目標

学習用のためサンプル機能（Task CRUD）を最低限カバー。新機能追加時は同テーブル（正常1:異常2）を維持する。
