# 08. テスト仕様書（Test Specification）

テストの戦略・ケース・品質目標を定義する。テスト分類・原則は `.claude/rules/testing.md` 準拠。

## テスト戦略

| アプリ | ツール | DB | 種別 |
|--------|--------|----|------|
| laravel-fullstack | PHPUnit（`php artisan test`） | SQLite in-memory | Feature（HTTP）|
| laravel-api | PHPUnit（`php artisan test`） | SQLite in-memory | Feature（JSON API）|
| laminas | PHPUnit（`vendor/bin/phpunit`） | なし（モデル/サービス/InputFilter 単体）| Unit（Task / PasswordHasher / InputFilter×3）+ 既存 Controller。認証・CRUD フローはライブ smoke |

> テストを SQLite in-memory にしている理由: `RefreshDatabase` は `migrate:fresh`（全テーブル DROP）を行うため、共有 MySQL に対して実行すると他アプリのテーブルを巻き込む。テストは隔離された in-memory DB で実行し、prefix 動作は実 DB へのマイグレーションで確認する。

## テストケース（CRUD + 認証）

正常系 : 異常系（準正常系含む）= 1 : 2 以上を目安に配置。

| アプリ | 主な正常系 | 主な異常系 |
|--------|-----------|-----------|
| fullstack Task | 一覧/作成/トグル/編集/ページネーション(5件)/タイトル検索 | guest→login / 他人タスク非表示 / 空title / 他人のtoggle・destroy・update・edit 404 / 検索ヒットなし / 検索でも他人分は出ない |
| fullstack Auth | 登録&自動ログイン / ログイン / ログアウト | 誤パスワード / メール重複 / 確認不一致 / 短パスワード |
| api Task | 一覧/201作成/更新/ページネーション(meta)/per_page/検索 | guest 401 / title欠落 422 / 長すぎ 422 / 更新時空title 422 / 他人タスク view・delete・update 404 / 検索ヒットなし |
| api Auth | register トークン / login トークン / logout | 誤パスワード 422 / メール重複 422 / 短パスワード 422 / token無し 401 |
| api Token | 一覧(ハッシュ非公開)/発行/期限付き発行/失効 | name必須422 / 不正expiry422 / 他人失効404 / guest401 / 期限切れトークン401 |
| laminas Task model | exchangeArray全項目 / getArrayCopy | 空配列デフォルト / '0'→false / 数値文字列→int / false→0 |
| laminas PasswordHasher | hash→verify / bcrypt形式 | 誤パスワード / 空 / 不正ハッシュ / ソルトで毎回異なる |
| laminas InputFilter | Task/Register/Login の有効入力通過・StringTrim 整形 | 必須欠落 / 空 / 空白のみ / 長すぎ(255超) / 短パスワード(8未満) |

> laminas のセッション認証フロー（register→login→保護→logout）は PHPUnit（CLI/セッション）でなく **ライブ smoke テスト（curl + cookie）** で検証する方針。認証ロジックの核（bcrypt）は `PasswordHasherTest` で単体保証する。

## 実行方法

```bash
make test          # 3アプリ一括
make test-fs       # laravel-fullstack
make test-api      # laravel-api
make test-laminas  # laminas
```

## カバレッジ目標

学習用のためサンプル機能（Task CRUD）を最低限カバー。新機能追加時は同テーブル（正常1:異常2）を維持する。
