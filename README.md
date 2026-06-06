# PHP Practice Web App

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
make test               # 3アプリのテストを一括実行
make logs               # ログ追従
make down               # 停止
```

各アプリ内で artisan / composer を使う例:

```bash
docker compose exec php-fs php artisan ...
docker compose exec php-laminas composer ...
```

## ドキュメント

仕様書は [`docs/`](./docs/) を参照。開発ルールは [`.claude/rules/`](./.claude/rules/) を参照。
