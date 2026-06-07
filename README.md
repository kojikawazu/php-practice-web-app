# PHP Practice Web App

[![CI](https://github.com/kojikawazu/php-practice-web-app/actions/workflows/ci.yml/badge.svg)](https://github.com/kojikawazu/php-practice-web-app/actions/workflows/ci.yml)

PHP の学習を目的とした練習用 Web アプリケーション

## 概要

3 つの PHP フレームワークを 1 リポジトリ（モノレポ）で学べる構成。共有 MySQL に対し、アプリごとにテーブルプレフィックスで名前空間を分離する。

| アプリ | パス | フレームワーク | URL | prefix |
|--------|------|----------------|-----|--------|
| フルスタック | `apps/laravel-fullstack` | Laravel 12 + Blade | http://localhost:8001 | `fs_` |
| API | `apps/laravel-api` | Laravel 12（JSON API）| http://localhost:8002 | `api_` |
| Laminas | `apps/laminas` | Laminas MVC（旧 Zend 後継）| http://localhost:8003 | `lam_` |

スタック: PHP 8.3 / MySQL 8 / nginx / Docker Compose。各アプリにサンプルの「タスク CRUD」とテストを同梱。

認証付き: 各アプリにログイン機能を実装し、タスクはユーザー毎に保護される（fullstack/laminas はセッション認証、api は Sanctum トークン認証）。`/register` から登録できる。

主な機能: タスク CRUD・完了切替・複製、タイトル検索とページネーション、開始日/終了日（カレンダー）、画像アップロード（所有者のみ閲覧）、URL の OGP プレビュー（SSRF 対策込み・fullstack）、新規/編集/複製の 2 ステップ確認画面（fullstack）。画面は Tailwind CSS（Play CDN）で簡易スタイリング。

## セットアップ

前提: Docker / Docker Compose（ホストに PHP・Composer は不要）。

```bash
cp .env.example .env
make up                 # = docker compose up -d --build
make migrate            # 2つの Laravel アプリのマイグレーション（lam_tasks は MySQL 初期化SQLで自動作成）
```

## 使い方

- フルスタック: http://localhost:8001/tasks
- API: `curl http://localhost:8002/api/tasks`
- Laminas: http://localhost:8003/tasks

```bash
make test               # 3アプリのテストを一括実行（ローカル / Docker）
make logs               # ログ追従
make down               # 停止
```

CI: push / Pull Request 時に GitHub Actions（`.github/workflows/ci.yml`）が 3 アプリのテストを自動実行する。

各アプリ内で artisan / composer を使う例:

```bash
docker compose exec php-fs php artisan ...
docker compose exec php-laminas composer ...
```

## ドキュメント

仕様書は `docs/` 配下に番号付きで整理しています。開発ルールは [`.claude/rules/`](./.claude/rules/) を参照。

### よくある探し物（クイックリンク）

| 知りたいこと | 参照先 |
|---|---|
| **3 アプリの構成・アーキテクチャ**（nginx / php-fpm / 共有 MySQL） | [docs/09-architecture-specification.md](docs/09-architecture-specification.md) |
| **ポート番号**（fullstack 8001 / api 8002 / laminas 8003 / MySQL 3306）| [docs/09-architecture-specification.md](docs/09-architecture-specification.md) |
| **DB（ER 図・テーブルスキーマ・prefix 分離）** | [docs/05-data-specification.md](docs/05-data-specification.md) |
| **セキュリティ**（認証・認可・SSRF 対策） | [docs/06-security-specification.md](docs/06-security-specification.md) |
| **API エンドポイント一覧**（laravel-api / Sanctum） | [docs/07-api-specification.md](docs/07-api-specification.md) |
| **3 アプリの実装を読み比べる**（起動コマンド・差分） | [docs/12-code-reading-guide.md](docs/12-code-reading-guide.md) |
| **進捗・タスク** | [docs/11-tasks.md](docs/11-tasks.md) |

### ドキュメント一覧

| # | ファイル | 内容 |
|---|---|---|
| 01 | [business-requirements](docs/01-business-requirements.md) | 要求仕様（背景・目標・スコープ） |
| 02 | [requirements-specification](docs/02-requirements-specification.md) | 要件仕様（機能要件一覧・受け入れ条件・優先度） |
| 03 | [functional-specification](docs/03-functional-specification.md) | 機能仕様（各機能詳細・ユーザーフロー・確認画面・バリデーション） |
| 04 | [non-functional-specification](docs/04-non-functional-specification.md) | 非機能仕様（性能・可用性・保守性） |
| 05 | [data-specification](docs/05-data-specification.md) | データ仕様（ER 図・テーブルスキーマ・prefix 分離） |
| 06 | [security-specification](docs/06-security-specification.md) | セキュリティ仕様（認証・認可・SSRF 対策） |
| 07 | [api-specification](docs/07-api-specification.md) | API 仕様（エンドポイント・トークン・エラー） |
| 08 | [test-specification](docs/08-test-specification.md) | テスト仕様（戦略・テストケース） |
| 09 | [architecture-specification](docs/09-architecture-specification.md) | アーキテクチャ仕様（**3 アプリ構成・Docker/nginx・ポート**） |
| 10 | [miscellaneous-specification](docs/10-miscellaneous-specification.md) | その他（用語集・参照資料・決定事項） |
| 11 | [tasks](docs/11-tasks.md) | タスク・進捗・マイルストーン |
| 12 | [code-reading-guide](docs/12-code-reading-guide.md) | コードリーディングガイド（3 アプリの読み比べ） |
