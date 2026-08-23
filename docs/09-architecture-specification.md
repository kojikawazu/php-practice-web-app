# 09. アーキテクチャ仕様書（Architecture Specification）

システム構成・技術スタック・デプロイ方針を定義する。

## システム構成

PHP フレームワークの学習用モノレポ。3 つのアプリを 1 リポジトリに同居させ、Docker Compose で MySQL を含むコンテナ群を一括起動する。

```text
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
| `nginx` | nginx:alpine | リバースプロキシ。ポート 8001/8002/8003（`.env` の `FS_PORT` / `API_PORT` / `LAMINAS_PORT` で上書き可）|

PHP 拡張: `pdo_mysql` ほか各 FW が要求するもの。`docker/php/Dockerfile` で導入。

タスク画像は名前付きボリューム `task-uploads` を php-fs / php-api の `/var/www/uploads` にマウントして保存（公開ディレクトリ外）。Laravel の `uploads` ディスク（`UPLOADS_ROOT` 基準、アプリ別サブディレクトリ `fs/` `api/`）経由で読み書きし、所有者チェック付きの配信ルートでのみ返す。ボリュームのマウント先は Dockerfile で `www-data` 所有にして php-fpm から書けるようにしている。

### ホスト側の公開ポート

ホストへ公開するポートは `.env` で上書きできる（既定は MySQL 3306 / 8001 / 8002 / 8003）。

| 変数 | 既定 | 用途 |
|---|---|---|
| `MYSQL_PORT` | 3306 | MySQL（ホストの GUI クライアント等から繋ぐ用。**アプリは使わない**）|
| `FS_PORT` / `API_PORT` / `LAMINAS_PORT` | 8001 / 8002 / 8003 | nginx が各アプリを公開するポート |

**アプリ間は compose ネットワーク（`DB_HOST=mysql`）で繋がるため、公開ポートを変えても動作は変わらない。** 既に MySQL や 8001 番台を使っているマシンで、fresh clone がポート衝突だけで起動できなくなるのを避けるために可変にしている。

> **CSP は nginx ではなくアプリ側で付与する。** nonce をリクエストごとに生成して HTML へ埋め込む必要があり、nginx 側で同じ値を作れないため。両方で設定するとヘッダーが重複し、ブラウザが全ポリシーの積を適用して意図が読めなくなる（`docs/06`）。

## CI（継続的インテグレーション）

`.github/workflows/ci.yml` が push（main）/ Pull Request 時に実行される。6 ジョブ構成。`GITHUB_TOKEN` は最小権限（`contents: read` / `pull-requests: read`）を明示する（`pull-requests: read` は `dorny/paths-filter` が PR の変更ファイルを GitHub API で取得するために必要）。`concurrency`（`cancel-in-progress: true`）で同一 PR の連続 push 時に古い実行をキャンセルする。

発火制御の方針は `.claude/rules/github-actions.md` に従い、**変更内容に関係のあるジョブだけを動かす**（ドキュメント変更でテストを回さない／逆にコード変更でテストを取りこぼさない）。

- **changes**: `dorny/paths-filter` で差分パスを判定する軽量ジョブ。判定は**除外リスト**で書く（`docs/**` / `**/*.md` / `.claude/**` 以外はコード変更とみなす）。対象リスト方式（`apps/**` の列挙）だと新しいトップレベルディレクトリが増えたときに黙ってテストが走らなくなる（fail-open）ため、安全側に反転させている。
  - paths-filter はパターンごとに picomatch を評価し既定では OR（`some`）で束ねるため、否定パターンだけを並べると互いを打ち消して常に true になる。`predicate-quantifier: every` で AND 評価にし「どの除外にも当たらない = コード変更」と解釈させる。
  - `every` は肯定形フィルタを壊すため、`docs`（`**/*.md`）の判定は既定の OR 評価の**別ステップ**に分けている。
  - 出力は `test` / `lint` / `e2e` / `docs` / `workflows` の 5 つ。**ジョブが読まないと確認できたファイルのみを除外する**方針で、3 つのコード系フィルタを個別に持つ。
  - `workflows` だけは**肯定リスト**（`.github/workflows/**`）で書く。actionlint の検査対象がこのディレクトリで閉じており、未知のファイルが増えても検査すべき対象は増えないため、対象リスト方式でも fail-open にならない。

  各ツールが実際に読む範囲（設定ファイルで確認済み）:

  | ツール | 対象範囲 | 根拠 |
  |---|---|---|
  | Larastan | `apps/laravel-*/app` のみ | `phpstan.neon` の `paths` |
  | Pint | プロジェクト全体（`tests/` `database/` `routes/` も整形対象） | `pint.json` なし = 既定 |
  | Psalm | `module` + `config` + `public/index.php`（`module/*/test` を含む） | `psalm.xml` の `projectFiles` |
  | phpcs | `config` + `module` + `public/index.php`（php / dist / phtml） | `phpcs.xml` の `<file>` |
  | PHPUnit | 各アプリの `phpunit.xml` / `phpunit.xml.dist` | - |

  ここから導かれる発火条件の差分:

  | 変更内容 | test | lint | e2e | markdown-lint | actionlint |
  |---|---|---|---|---|---|
  | `apps/**` の PHP / Blade / phtml / config / migration、`composer.lock`、`.env.example` | ✅ | ✅ | ✅ | ❌ | ❌ |
  | テストコードのみ（`apps/*/tests/**`、`apps/laminas/module/*/test/**`） | ✅ | ✅ | ❌ | ❌ | ❌ |
  | 静的解析の設定のみ（`phpstan.neon` / `psalm.xml` / `phpcs.xml` / 各 baseline） | ❌ | ✅ | ❌ | ❌ | ❌ |
  | PHPUnit の設定のみ（`phpunit.xml` / `phpunit.xml.dist`） | ✅ | ❌ | ❌ | ❌ | ❌ |
  | `compose.yaml` / `docker/**` / `e2e/**` | ❌ | ❌ | ✅ | ❌ | ❌ |
  | md ドキュメント / `.claude/**` / `.markdownlint-cli2.jsonc` | ❌ | ❌ | ❌ | ✅ | ❌ |
  | `.github/workflows/**` | ✅ | ✅ | ✅ | ❌ | ✅ |
  | 上記に当てはまらない変更（新規ディレクトリ等） | ✅ | ✅ | ✅ | ❌ | ❌ |

  `test` が Docker 系（`compose.yaml` / `docker/**`）に依存しないのは、`shivammathur/setup-php` で動き Docker を使わないため。`Makefile` はどのジョブも参照しない（CI は各コマンドを直接叩く）ため全フィルタで除外している。

- **actionlint**: `if: workflows == 'true'` で `.github/workflows/**` の変更時のみ実行。workflow の構文・式（`${{ }}`）・runner ラベル・action の入力に加え、**`run:` の中身を shellcheck に流す**（`.claude/rules/github-actions.md` が要求する品質ゲートの実体）。取得は公式 Docker イメージ `rhysd/actionlint` のバージョン固定タグで、`make actionlint` と**同一コマンド**のため手元と CI で結果が一致する。バージョンを上げる際は `Makefile` と `ci.yml` の両方を揃える。
- **markdown-lint**: `if: docs == 'true'` で md 変更時のみ実行（`markdownlint-cli2`）。対象と無効化ルールの理由は `.markdownlint-cli2.jsonc` に記載し、**警告ゼロを維持**する（`.claude/rules/static-analysis.md`）。ローカルは `make md-lint` / `make md-fix`。
- **test**: `needs: changes` + `if: test == 'true'` で実行。`shivammathur/setup-php`（PHP 8.3）で各アプリをセットアップ（Docker 不使用）、matrix で 3 アプリを並行ジョブ実行（`fail-fast: false`）。Laravel ×2 は `php artisan test`（テスト DB は SQLite in-memory のため MySQL サービス不要）、Laminas は `vendor/bin/phpunit`。
- **lint**: `if: lint == 'true'` で実行する静的チェックジョブ（matrix で 3 アプリ並行）。Laravel ×2 は `vendor/bin/pint --test`（整形の差分検査）+ `composer analyse`（Larastan/PHPStan・`level: max`）、Laminas は `composer cs-check`（phpcs / Laminas Coding Standard）+ `vendor/bin/psalm`（型解析・`errorLevel=1`）。静的解析の既存指摘は baseline（Laravel=`apps/laravel-*/phpstan-baseline.neon` / Laminas=`apps/laminas/psalm-baseline.xml`）に記録済みで、CI は**新規に増えた指摘のみ**で失敗する（baseline 運用）。ローカルでの自動修正は Laravel=`vendor/bin/pint`、Laminas=`composer cs-fix`。
- **e2e**: `if: e2e == 'true'` で実行。compose で app + 実 MySQL を起動し、migrate → Playwright で 3 アプリ横断の E2E を検証する（詳細は `docs/08`）。失敗時は Playwright レポートを artifact に上げ、compose ログを出力する。

> 補足: 必須チェックの落とし穴を避けるため、**ワークフローレベルの `paths` / `paths-ignore` は使わない**。ワークフロー自体が起動しないと必須チェックが `pending` のまま完了せず PR がマージ不能になるため、「常に起動してジョブレベル `if:` でスキップする（= skipped は成功扱い）」形を採る（`.claude/rules/github-actions.md`）。
>
> `Makefile` のみを変更した PR では**どのジョブも実行されない**（CI は Makefile を経由しないため検査手段がない）。意図的なトレードオフとして受け入れている。検査が必要になった場合は `make -n` の dry-run 等を軽量チェックとして追加する。なお初期化手順の実体は `scripts/setup.sh` にあり、こちらは e2e ジョブが実際に実行するため CI で検査される（`Makefile` の `setup` ターゲットはそれを呼ぶだけ）。
>
> branch protection（必須チェック）導入時は、マトリクスを `if` でスキップすると `test (app)` 個別の check run が生成されない点に注意。その際は `if: always()` の集約ゲートジョブを追加し、それ 1 つを必須チェックに指定する設計が必要になる。現状このリポジトリは private 無料プランで branch protection を利用できないため、集約ゲートは導入していない。

## デプロイ

学習用のためローカル `docker compose up` のみ。環境変数は `.env`（`.env.example` を雛形）。

```bash
make setup   # = scripts/setup.sh（.env・vendor・APP_KEY・書き込み権限・migrate・疎通確認）
```

`docker compose up` だけでは起動しない。**compose の bind mount がイメージ側の `vendor/` を隠す**ため、コンテナ内で `composer install` をやり直す必要があり、Laravel の `.env` / `APP_KEY` も生成されていないため。初期化手順の正本は `scripts/setup.sh` に置き、ローカル（`make setup`）と CI の `e2e` ジョブが同じものを実行する。
