# 08. テスト仕様書（Test Specification）

テストの戦略・ケース・品質目標を定義する。テスト分類・原則は `.claude/rules/testing.md` 準拠。

## テスト戦略

| アプリ | ツール | DB | 種別 |
|--------|--------|----|------|
| laravel-fullstack | PHPUnit（`php artisan test`） | SQLite in-memory | Feature（HTTP）|
| laravel-api | PHPUnit（`php artisan test`） | SQLite in-memory | Feature（JSON API）|
| laminas | PHPUnit（`vendor/bin/phpunit`） | なし（モデル単体）| Unit + 既存 Controller |

> テストを SQLite in-memory にしている理由: `RefreshDatabase` は `migrate:fresh`（全テーブル DROP）を行うため、共有 MySQL に対して実行すると他アプリのテーブルを巻き込む。テストは隔離された in-memory DB で実行し、prefix 動作は実 DB へのマイグレーションで確認する。

## テストケース（サンプル CRUD）

正常系 : 異常系（準正常系含む）= 1 : 2 以上を目安に配置。

| アプリ | 正常系 | 異常系 |
|--------|--------|--------|
| fullstack | 一覧表示 / 作成 / 完了トグル | 空title 422 / 256字 422 / toggle 404 / destroy 404 |
| api | JSON一覧 / 201作成 | title欠落 422 / 長すぎ 422 / done非bool 422 / show 404 |
| laminas (Task model) | exchangeArray全項目 / getArrayCopy | 空配列デフォルト / '0'→false / 数値文字列→int / false→0 |

## 実行方法

```bash
make test          # 3アプリ一括
make test-fs       # laravel-fullstack
make test-api      # laravel-api
make test-laminas  # laminas
```

## カバレッジ目標

学習用のためサンプル機能（Task CRUD）を最低限カバー。新機能追加時は同テーブル（正常1:異常2）を維持する。
