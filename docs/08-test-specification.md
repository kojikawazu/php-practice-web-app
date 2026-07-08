# 08. テスト仕様書（Test Specification）

テストの戦略・ケース・品質目標を定義する。テスト分類・原則は `.claude/rules/testing.md` 準拠。

## テスト戦略

| アプリ | ツール | DB | 種別 |
|--------|--------|----|------|
| laravel-fullstack | PHPUnit（`php artisan test`） | SQLite in-memory | Feature（HTTP）|
| laravel-api | PHPUnit（`php artisan test`） | SQLite in-memory | Feature（JSON API）|
| laminas | PHPUnit（`vendor/bin/phpunit`） | 認可テストのみ SQLite in-memory（他はモデル/サービス/InputFilter 単体で DB 不要）| Unit（Task / PasswordHasher / InputFilter×3 / **TaskTable 所有者スコープ**）+ 既存 Controller。セッション認証フローはライブ smoke |

> テストを SQLite in-memory にしている理由: `RefreshDatabase` は `migrate:fresh`（全テーブル DROP）を行うため、共有 MySQL に対して実行すると他アプリのテーブルを巻き込む。テストは隔離された in-memory DB で実行し、prefix 動作は実 DB へのマイグレーションで確認する。

## テストケース（CRUD + 認証）

正常系 : 異常系（準正常系含む）= 1 : 2 以上を目安に配置。

| アプリ | 主な正常系 | 主な異常系 |
|--------|-----------|-----------|
| fullstack Task | 一覧/作成/トグル/編集/複製/日付付き作成/画像アップロード・所有者閲覧・差替・複製コピー/2ステップ確認(確認表示・確定まで未作成)/ページネーション(5件)/タイトル検索 | guest→login / 他人タスク非表示 / 空title / 他人のtoggle・destroy・update(確認)・edit・duplicate・image 404 / 非画像422 / 検索ヒットなし / 終了日<開始日 / 不正日付 |
| fullstack Auth | 登録&自動ログイン / ログイン / ログアウト | 誤パスワード / メール重複 / 確認不一致 / 短パスワード |
| api Task | 一覧/201作成/更新/複製(201)/日付付き作成/画像アップロード(image_url返却)・所有者取得/ページネーション(meta)/per_page/検索 | guest 401 / title欠落 422 / 長すぎ 422 / 更新時空title 422 / 終了日<開始日 422 / 不正日付 422 / 非画像 422 / 他人タスク view・delete・update・duplicate・image 404 / 検索ヒットなし |
| api Auth | register トークン / login トークン / logout | 誤パスワード 422 / メール重複 422 / 短パスワード 422 / token無し 401 |
| api Token | 一覧(ハッシュ非公開)/発行/期限付き発行/失効 | name必須422 / 不正expiry422 / 他人失効404 / guest401 / 期限切れトークン401 |
| laminas Task model | exchangeArray全項目 / getArrayCopy | 空配列デフォルト / '0'→false / 数値文字列→int / false→0 |
| laminas TaskTable（所有者スコープ・SQLite） | 本人タスクの取得/一覧/検索/作成/更新/削除 | 他人タスクの取得null / 他人タスクを削除しても消えない / user_id偽装updateが他人に及ばない / countが他人を除外 / 一覧が他人を除外 / 検索ヒットなし |
| fullstack LinkPreview(SSRF) | public IP 許可 / title・og:image 抽出 | private・loopback・link-local・予約IP 拒否 / 非http拒否 / 内部ホスト拒否 / og:image非http除外 |
| laminas PasswordHasher | hash→verify / bcrypt形式 | 誤パスワード / 空 / 不正ハッシュ / ソルトで毎回異なる |
| laminas InputFilter | Task/Register/Login の有効入力通過・StringTrim 整形・日付任意通過 | 必須欠落 / 空 / 空白のみ / 長すぎ(255超) / 短パスワード(8未満) / 不正日付 / 終了日<開始日 |

> laminas のセッション認証フロー（register→login→保護→logout）は PHPUnit（CLI/セッション）でなく **ライブ smoke テスト（curl + cookie）** で検証する方針。認証ロジックの核（bcrypt）は `PasswordHasherTest` で単体保証する。
> 一方、**認可（所有者スコープ）の実体である `TaskTable` の SQL** は、SQLite in-memory アダプタを差した `TaskTableTest` で PHPUnit 化済み（他人タスクの取得・更新・削除が SQL レベルで弾かれることを実データで検証）。

## 実行方法

```bash
make test          # 3アプリ一括
make test-fs       # laravel-fullstack
make test-api      # laravel-api
make test-laminas  # laminas
```

## カバレッジ目標

学習用のためサンプル機能（Task CRUD）を最低限カバー。新機能追加時は同テーブル（正常1:異常2）を維持する。
