# 09. アーキテクチャ仕様書（Architecture Specification）

システム構成・技術スタック・デプロイ方針を定義する。

## システム構成

PHP フレームワークの学習用モノレポ。3 つのアプリを 1 リポジトリに同居させ、Docker Compose で MySQL を含むコンテナ群を一括起動する。

```
[ブラウザ] → [nginx] ┬→ localhost:8001 → php-fs       (laravel-fullstack)
                     ├→ localhost:8002 → php-api      (laravel-api)
                     └→ localhost:8003 → php-laminas  (laminas)
                                               ↓ ↓ ↓
                                            [mysql] 共有DB: php_practice
```

| アプリ | 役割 | フロント | テーブルprefix |
|--------|------|---------|----------------|
| `apps/laravel-fullstack` | フルスタック | Blade | `fs_` |
| `apps/laravel-api` | API 専用 | なし（JSON） | `api_` |
| `apps/laminas` | Laminas MVC（旧 Zend 後継） | PHTML | `lam_` |

## 技術スタック

| 項目 | 採用 |
|------|------|
| 言語 | PHP 8.3 |
| FW（×2） | Laravel 12（12.61.1。当初 11 → CVE-2026-48019 解消のため 12 へ） |
| FW（×1） | Laminas MVC（PHP 8.3 対応） |
| DB | MySQL 8 |
| Web | nginx |
| 実行基盤 | Docker / Docker Compose |
| テスト | PHPUnit（各アプリ） |
| 依存管理 | Composer（コンテナ内で実行、ホストに PHP/Composer 不要） |

## インフラ

Docker Compose のサービス構成:

| サービス | イメージ | 役割 |
|----------|---------|------|
| `mysql` | mysql:8 | 共有 DB。永続ボリューム |
| `php-fs` | php:8.3-fpm（自前ビルド） | laravel-fullstack |
| `php-api` | php:8.3-fpm（自前ビルド） | laravel-api |
| `php-laminas` | php:8.3-fpm（自前ビルド） | laminas |
| `nginx` | nginx:alpine | リバースプロキシ。ポート 8001/8002/8003 |

PHP 拡張: `pdo_mysql` ほか各 FW が要求するもの。`docker/php/Dockerfile` で導入。

タスク画像は名前付きボリューム `task-uploads` を php-fs / php-api の `/var/www/uploads` にマウントして保存（公開ディレクトリ外）。Laravel の `uploads` ディスク（`UPLOADS_ROOT` 基準、アプリ別サブディレクトリ `fs/` `api/`）経由で読み書きし、所有者チェック付きの配信ルートでのみ返す。ボリュームのマウント先は Dockerfile で `www-data` 所有にして php-fpm から書けるようにしている。

## CI（継続的インテグレーション）

`.github/workflows/ci.yml` が push（main）/ Pull Request 時に実行される。3 ジョブ構成。`GITHUB_TOKEN` は最小権限（`contents: read` / `pull-requests: read`）を明示する（`pull-requests: read` は `dorny/paths-filter` が PR の変更ファイルを GitHub API で取得するために必要）。

- **changes**: `dorny/paths-filter` で差分パスを判定する軽量ジョブ。`apps/**` または `.github/workflows/**` が変わった場合のみ `code=true` を出力する。
- **test**: `needs: changes` + `if: code == 'true'` で、コード変更時のみ実行（doc-only 変更ではスキップ）。`shivammathur/setup-php`（PHP 8.3）で各アプリをセットアップ（Docker 不使用）、matrix で 3 アプリを並行ジョブ実行（`fail-fast: false`）。Laravel ×2 は `php artisan test`（テスト DB は SQLite in-memory のため MySQL サービス不要）、Laminas は `vendor/bin/phpunit`。
- **lint**: `test` と同じく `needs: changes` + code 変更時のみ実行する静的チェックジョブ（matrix で 3 アプリ並行）。Laravel ×2 は `vendor/bin/pint --test`（整形の差分検査）+ `composer analyse`（Larastan/PHPStan・`level: max`）、Laminas は `composer cs-check`（phpcs / Laminas Coding Standard）+ `vendor/bin/psalm`（型解析・`errorLevel=1`）。静的解析の既存指摘は baseline（Laravel=`apps/laravel-*/phpstan-baseline.neon` / Laminas=`apps/laminas/psalm-baseline.xml`）に記録済みで、CI は**新規に増えた指摘のみ**で失敗する（baseline 運用）。ローカルでの自動修正は Laravel=`vendor/bin/pint`、Laminas=`composer cs-fix`。

> 補足: branch protection（必須チェック）導入時は、マトリクスを `if` でスキップすると `test (app)` 個別の check run が生成されない点に注意。その際は `if: always()` の集約ゲートジョブを追加し、それ 1 つを必須チェックに指定する設計が必要になる。現状このリポジトリは private 無料プランで branch protection を利用できないため、集約ゲートは導入していない。

## デプロイ

学習用のためローカル `docker compose up` のみ。環境変数は `.env`（`.env.example` を雛形）。

```bash
cp .env.example .env
docker compose up -d --build
# 各アプリの初期化（マイグレーション等）は README / Makefile 参照
```
