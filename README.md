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
make setup              # 初回のみ。3 アプリが応答する状態まで一括で用意する
```

`make setup` は `scripts/setup.sh` を実行し、次をまとめて行う。**冪等**なので、環境が壊れたと思ったら何度でも実行してよい（既存の `.env` / `APP_KEY` / DB のデータは壊さない）。

1. ルートと Laravel 2 アプリの `.env` を `.env.example` から作成（既にあれば触らない）
2. `docker compose up -d --build` と MySQL の healthy 待ち
3. **3 アプリの `composer install`（コンテナ内）** — compose の bind mount がイメージ側の `vendor/` を隠すため、fresh clone では必ず必要
4. フレームワークの書き込み先（Laravel の `storage` / `bootstrap/cache`、laminas の `data/cache`）を用意
5. Laravel の `APP_KEY` を生成（**未設定のときだけ**。再生成すると既存セッション・暗号化データが読めなくなる）
6. マイグレーション（`lam_tasks` は MySQL の初期化 SQL で自動作成）
7. 3 アプリが応答することを確認

CI の `e2e` ジョブも同じ `scripts/setup.sh` を実行する。手順を 2 か所に書くと片方だけ直されて fresh clone が起動できない状態に戻るため、正本を 1 つにしている。

2 回目以降の起動は `make up` だけでよい。

### ポートが衝突する場合

既定は MySQL 3306 / fullstack 8001 / api 8002 / laminas 8003。既に使っているポートがあれば `.env` で変更する（アプリ間は compose ネットワークで繋がるため、公開ポートを変えても動作は変わらない）。

```bash
MYSQL_PORT=3307
FS_PORT=18001
API_PORT=18002
LAMINAS_PORT=18003
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

### E2E テスト（Playwright）

3 アプリ横断の E2E は `e2e/`（Playwright / TypeScript）にあり、**`docker compose` で起動した実環境（実 MySQL）** に対して実行する（fullstack/laminas はブラウザ、api は HTTP/Bearer）。

```bash
make setup               # 実環境を起動（初回・再実行可）
make e2e                 # e2e/ で npm ci → chromium 導入 → playwright test（3 projects）
# 個別: cd e2e && npx playwright test --project=api
```

対象 URL は既定で compose のポート（8001/8002/8003）。`E2E_FS_URL` / `E2E_LAMINAS_URL` / `E2E_API_URL` で上書きできる。CI では push/PR 時に専用 `e2e` ジョブが compose 起動 → migrate → Playwright を実行する。

### markdown lint

ドキュメント（`*.md`）は `markdownlint-cli2` で検査する。対象・無効化ルールとその理由は `.markdownlint-cli2.jsonc` に記載。

```bash
make md-lint            # 検査（CI の markdown-lint ジョブと同じコマンド）
make md-fix             # 自動修正できる指摘（空行の過不足など）を直す
```

### GitHub Actions ワークフローの検査（actionlint）

`.github/workflows/**` を変更したら actionlint を通す。構文・式（`${{ }}`）・runner ラベルに加え、`run:` の中身を shellcheck で検査する。

```bash
make actionlint         # 検査（CI の actionlint ジョブと同じコマンド・同じバージョン）
```

Docker イメージ `rhysd/actionlint` をバージョン固定で使うため、ホストへのインストールは不要。バージョンを上げるときは `Makefile` と `.github/workflows/ci.yml` の両方を同じタグに揃える。

CI: push / Pull Request 時に GitHub Actions（`.github/workflows/ci.yml`）が 3 アプリの **テスト**（PHPUnit）と **静的チェック**（Laravel=Pint + Larastan / Laminas=phpcs + Psalm）、および **markdown lint** / **actionlint** を自動実行する。ローカルでの自動修正は Laravel=`vendor/bin/pint`、Laminas=`composer cs-fix`、md=`make md-fix`。

発火は変更内容で制御される（`.claude/rules/github-actions.md`）。`test` / `lint` / `e2e` / `markdown-lint` / `actionlint` はそれぞれ読むファイルが違うため、条件も分けている。

| 変更内容 | 実行されるジョブ |
|---|---|
| md ドキュメント / `.claude/**` | `markdown-lint` |
| `apps/**` のコード | `test` + `lint` + `e2e` |
| テストコードのみ | `test` + `lint` |
| 静的解析の設定のみ（`phpstan.neon` / `psalm.xml` / `phpcs.xml` / baseline） | `lint` |
| `phpunit.xml` のみ | `test` |
| `compose.yaml` / `docker/**` / `e2e/**` | `e2e` |
| `.github/workflows/**` | `actionlint` + `test` + `lint` + `e2e` |

詳細（各ツールが読む範囲の根拠を含む）は `docs/09`。

各アプリ内で artisan / composer を使う例:

```bash
docker compose exec php-fs php artisan ...
docker compose exec php-laminas composer ...
```

## ドキュメント

仕様書は `docs/` 配下に番号付きで整理しています。開発ルールは [`.claude/rules/`](./.claude/rules/) を参照。

## AI エージェント向けルール

開発ルールの正本は [`.claude/rules/`](.claude/rules/) です。Claude Code は [`CLAUDE.md`](CLAUDE.md) から、Codex はリポジトリ階層の [`AGENTS.md`](AGENTS.md) から同じルールを参照します。ルール本文は複製せず、変更対象に最も近い `AGENTS.md` が指定する追加ルールも適用します。

| 対象 | Codex 向け指示ファイル | 追加で参照するルール |
|---|---|---|
| リポジトリ全体 | [`AGENTS.md`](AGENTS.md) | 共通ルール |
| `apps/**` | [`apps/AGENTS.md`](apps/AGENTS.md) | PHP 共通・3 アプリ横断 |
| `apps/laravel-fullstack/**` | [`apps/laravel-fullstack/AGENTS.md`](apps/laravel-fullstack/AGENTS.md) | Laravel・Blade・セッション認証 |
| `apps/laravel-api/**` | [`apps/laravel-api/AGENTS.md`](apps/laravel-api/AGENTS.md) | Laravel・Sanctum・JSON API |
| `apps/laminas/**` | [`apps/laminas/AGENTS.md`](apps/laminas/AGENTS.md) | Laminas・TableGateway・DI |

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

## ライセンス

本リポジトリは [MIT License](LICENSE) で公開しています（Copyright (c) 2026 kojikawazu）。

ただし、フレームワークのスケルトン由来のファイルは元のライセンスに従います。

| 対象 | ライセンス | 条文 |
|---|---|---|
| リポジトリ全体（自作コード・ドキュメント） | MIT | [`LICENSE`](LICENSE) |
| `apps/laminas`（Laminas MVC スケルトン由来） | BSD-3-Clause | [`apps/laminas/LICENSE.md`](apps/laminas/LICENSE.md) / [`apps/laminas/COPYRIGHT.md`](apps/laminas/COPYRIGHT.md) |
| `vendor/`・`node_modules/` の依存パッケージ | 各パッケージのライセンス | 各パッケージ同梱の条文 |
