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
| -------- | ------ | --------- | ---------------- |
| `apps/laravel-fullstack` | フルスタック | Blade | `fs_` |
| `apps/laravel-api` | API 専用 | なし（JSON） | `api_` |
| `apps/laminas` | Laminas MVC（旧 Zend 後継） | PHTML | `lam_` |

## 技術スタック

| 項目 | 採用 |
| ------ | ------ |
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
| ---------- | --------- | ------ |
| `mysql` | mysql:8 | 共有 DB。永続ボリューム |
| `php-fs` | php:8.3-fpm（自前ビルド） | laravel-fullstack |
| `php-api` | php:8.3-fpm（自前ビルド） | laravel-api |
| `php-laminas` | php:8.3-fpm（自前ビルド） | laminas |
| `nginx` | nginx:alpine | リバースプロキシ。ポート 8001/8002/8003（`.env` の `FS_PORT` / `API_PORT` / `LAMINAS_PORT` で上書き可） |

PHP 拡張: `pdo_mysql` ほか各 FW が要求するものに加え、カバレッジ計測用の `pcov`（手元で `make coverage` を CI と同じ形で動かすため。CI 側は `setup-php` の `coverage: pcov` で入る）。`docker/php/Dockerfile` で導入。PHP の設定上書きは `docker/php/uploads.ini`（アップロードサイズ）を `conf.d/` へ配置する。

### リクエストサイズの上限

画像アップロード（最大 2MB）を**アプリのバリデーションで判定させる**ため、各層の上限を **nginx < PHP** の順に並べる。nginx を通ったリクエストは必ず PHP に届き、Laravel が最終判定する（境界の仕様は `docs/07`）。

| 層 | 設定 | 値 | 既定 |
| --- | --- | --- | --- |
| nginx（fullstack / api） | `client_max_body_size` | 5m | 1m |
| nginx（laminas） | — | 既定のまま | 1m |
| PHP（3 アプリ共通イメージ） | `upload_max_filesize` / `post_max_size` | 5M / 6M | 2M / 8M |

laminas はアップロード機能を持たないため既定の 1m のままとし、不要に大きな body を受け付けない。

タスク画像は名前付きボリューム `task-uploads` を php-fs / php-api の `/var/www/uploads` にマウントして保存（公開ディレクトリ外）。Laravel の `uploads` ディスク（`UPLOADS_ROOT` 基準、アプリ別サブディレクトリ `fs/` `api/`）経由で読み書きし、所有者チェック付きの配信ルートでのみ返す。ボリュームのマウント先は Dockerfile で `www-data` 所有にして php-fpm から書けるようにしている。

### ホスト側の公開ポート

ホストへ公開するポートは `.env` で上書きできる（既定は MySQL 3306 / 8001 / 8002 / 8003）。

| 変数 | 既定 | 用途 |
| --- | --- | --- |
| `MYSQL_PORT` | 3306 | MySQL（ホストの GUI クライアント等から繋ぐ用。**アプリは使わない**） |
| `FS_PORT` / `API_PORT` / `LAMINAS_PORT` | 8001 / 8002 / 8003 | nginx が各アプリを公開するポート |

**アプリ間は compose ネットワーク（`DB_HOST=mysql`）で繋がるため、公開ポートを変えても動作は変わらない。** 既に MySQL や 8001 番台を使っているマシンで、fresh clone がポート衝突だけで起動できなくなるのを避けるために可変にしている。

> **CSP は nginx ではなくアプリ側で付与する。** nonce をリクエストごとに生成して HTML へ埋め込む必要があり、nginx 側で同じ値を作れないため。両方で設定するとヘッダーが重複し、ブラウザが全ポリシーの積を適用して意図が読めなくなる（`docs/06`）。

## CI（継続的インテグレーション）

`.github/workflows/ci.yml` が push（main）/ Pull Request 時に実行される。7 ジョブ構成。`GITHUB_TOKEN` は最小権限（`contents: read` / `pull-requests: read`）を明示する（`pull-requests: read` は `dorny/paths-filter` が PR の変更ファイルを GitHub API で取得するために必要）。`concurrency`（`cancel-in-progress: true`）で同一 PR の連続 push 時に古い実行をキャンセルする。

発火制御の方針は `.claude/rules/github-actions.md` に従い、**変更内容に関係のあるジョブだけを動かす**（ドキュメント変更でテストを回さない／逆にコード変更でテストを取りこぼさない）。

- **changes**: `dorny/paths-filter` で差分パスを判定する軽量ジョブ。判定は**除外リスト**で書く（`docs/**` / `**/*.md` / `.claude/**` 以外はコード変更とみなす）。対象リスト方式（`apps/**` の列挙）だと新しいトップレベルディレクトリが増えたときに黙ってテストが走らなくなる（fail-open）ため、安全側に反転させている。
  - paths-filter はパターンごとに picomatch を評価し既定では OR（`some`）で束ねるため、否定パターンだけを並べると互いを打ち消して常に true になる。`predicate-quantifier: every` で AND 評価にし「どの除外にも当たらない = コード変更」と解釈させる。
  - `every` は肯定形フィルタを壊すため、`docs`（`**/*.md`）の判定は既定の OR 評価の**別ステップ**に分けている。
  - 出力は `test` / `lint` / `e2e` / `docs` / `workflows` / `deps` の 6 つ。**ジョブが読まないと確認できたファイルのみを除外する**方針で、3 つのコード系フィルタを個別に持つ。
  - `workflows` だけは**肯定リスト**（`.github/workflows/**`）で書く。actionlint の検査対象がこのディレクトリで閉じており、未知のファイルが増えても検査すべき対象は増えないため、対象リスト方式でも fail-open にならない。
  - `deps` も**肯定リスト**（`apps/*/composer.json` / `apps/*/composer.lock`）。`lint` ジョブの `composer audit` ステップだけがこれを見る。**全 PR で監査を走らせない**のは、新しいアドバイザリが公開されただけで依存を 1 行も触っていない PR が落ちるようになるため。依存を触らないまま公開される新規アドバイザリの検出は Dependabot alerts（リポジトリ設定）が担い、層を分けている（`docs/06`）。
  - **AND 評価が効いていること自体を、同ジョブ内で自己検証する**（`Verify predicate-quantifier is AND`）。`every` が効かなくなると否定パターンが互いを打ち消して**全フィルタが常に true** になるが、その壊れ方は「必要なジョブは走り続ける」ため CI は緑のままで気づけない。同時には満たせない 2 条件（`'**'` と `'!**'`）を評価し、AND なら false・OR なら true になる差で判定する。変更ファイル 0 件で空振りするのを防ぐため、単独の `'**'` を対照に置き、**検査できなかった場合も失敗させる**（`.claude/rules/testing.md`「ガード自体をテストする」）。Dependabot が `dorny/paths-filter` のメジャー更新を PR で上げるようになったため（`.github/dependabot.yml`）、この自己検証が更新 PR の合否根拠になる。

  各ツールが実際に読む範囲（設定ファイルで確認済み）:

  | ツール | 対象範囲 | 根拠 |
  | --- | --- | --- |
  | Larastan | `apps/laravel-*/app` のみ | `phpstan.neon` の `paths` |
  | Pint | プロジェクト全体（`tests/` `database/` `routes/` も整形対象） | `pint.json` なし = 既定 |
  | Psalm | `module` + `config` + `public/index.php`（`module/*/test` を含む） | `psalm.xml` の `projectFiles` |
  | phpcs | `config` + `module` + `public/index.php`（php / dist / phtml） | `phpcs.xml` の `<file>` |
  | PHPUnit | 各アプリの `phpunit.xml` / `phpunit.xml.dist` | - |

  ここから導かれる発火条件の差分:

  | 変更内容 | test | lint | e2e | markdown-lint | actionlint |
  | --- | --- | --- | --- | --- | --- |
  | `apps/**` の PHP / Blade / phtml / config / migration、`composer.lock`、`.env.example` | ✅ | ✅ | ✅ | ❌ | ❌ |
  | テストコードのみ（`apps/*/tests/**`、`apps/laminas/module/*/test/**`） | ✅ | ✅ | ❌ | ❌ | ❌ |
  | 静的解析の設定のみ（`phpstan.neon` / `psalm.xml` / `phpcs.xml` / 各 baseline） | ❌ | ✅ | ❌ | ❌ | ❌ |
  | PHPUnit の設定のみ（`phpunit.xml` / `phpunit.xml.dist`） | ✅ | ❌ | ❌ | ❌ | ❌ |
  | `compose.yaml` / `docker/**` / `e2e/**` | ❌ | ❌ | ✅ | ❌ | ❌ |
  | `scripts/**`（`setup.sh` / `coverage-threshold.php`） | ✅ | ✅ | ✅ | ❌ | ❌ |
  | md ドキュメント（`.github/PULL_REQUEST_TEMPLATE/**` を含む） / `.claude/**` / markdown lint の設定（`.markdownlint-cli2.jsonc` と入れ子の `.markdownlint.jsonc`） | ❌ | ❌ | ❌ | ✅ | ❌ |
  | ルート直下の `package.json` / `package-lock.json`（markdown lint の実行環境） | ❌ | ❌ | ❌ | ✅ | ❌ |
  | `.github/workflows/**` | ✅ | ✅ | ✅ | ❌ | ✅ |
  | `Makefile`（actionlint のコマンド定義） | ❌ | ❌ | ❌ | ❌ | ✅ |
  | 上記に当てはまらない変更（新規ディレクトリ等） | ✅ | ✅ | ✅ | ❌ | ❌ |

  `test` が Docker 系（`compose.yaml` / `docker/**`）に依存しないのは、`shivammathur/setup-php` で動き Docker を使わないため（カバレッジドライバも setup-php が入れるので、`docker/php/Dockerfile` への pcov 追加は CI に影響しない）。逆に **`scripts/**` は除外しない**。`test` ジョブがカバレッジ下限の判定に `scripts/coverage-threshold.php` を実行するため、ここを除外すると下限を変更しても検証されないまま通ってしまう。`Makefile` は PHP 系 3 ジョブが参照しない（CI は各コマンドを直接叩く）ため、それらのフィルタでは除外している。**例外は actionlint ジョブ**で、イメージのタグを 2 箇所に書き写さないために `make actionlint` を呼ぶ。そのため `workflows` フィルタは `Makefile` も対象に含める。 ルート直下の `package*.json` は markdown lint 専用のため PHP 系 3 ジョブから除外している（`e2e/package*.json` は別パスで、従来どおり `e2e` を発火させる）。

  **`secret-scan` は本表の対象外**（`changes` に依存せず常に実行する）。秘匿ファイルの混入はどの変更種別でも起こりうるため、発火制御をかけない。

- **secret-scan**: `changes` に依存せず**常に実行**する。`git ls-files`（インデックスを読むだけで履歴もワーキングツリーも走査しない）に対し、`.env` 系・秘密鍵・証明書・サービスアカウント鍵のパターンを照合し、1 件でも追跡されていればジョブを失敗させる。`.gitignore` は**未追跡ファイルにしか効かない**ため「混入させない」側しか担保できず、`git add -f` や新規ディレクトリでの書き漏れを止められない。加えて Git の履歴は追記型なので、push 済みの秘匿ファイルは追跡除外しても履歴に残り、対処は**鍵・トークンのローテーションしかない**（不可逆）。だから「追跡された時点で落とす」検出側を CI に置く。
  - 判定式は `run:` にインラインで書く。`scripts/*.sh` へ切り出すと actionlint 経由の **shellcheck の検査対象から外れる**ため（同じ理由で actionlint は shellcheck 同梱の公式イメージを使っている）。
  - 照合は `grep` ではなく `awk` で行う。`grep` は**マッチ 0 件でも終了コード 1** を返し、`run:` は `bash -e` で動くため `tracked=$(... | grep ...)` と書くと「1 件も見つからない = 正常」のときにスクリプトごと無言で落ちる。`awk` は出力の有無に関わらず 0 で終わるためこの罠を構造的に避けられる。パターンは `-v` ではなく `ENVIRON` 経由で渡す（`-v` は値のエスケープを再解釈し `\.` が `.` に化けて別物の正規表現になる）。
  - **ガード自体を同じステップで自己検証する**。検出すべき 8 パス・素通りすべき 9 パスの分類を本走査の前に突き合わせ、食い違えば失敗させる。ガードが壊れると「常に緑」になり検査の停止に誰も気づけないため（`.claude/rules/testing.md`）。
  - 除外は `*.example` / `*.sample` / `*.template` / `*.dist` と `.env.d.ts`。本リポジトリの `.env.example` 4 件（ルート / laravel-api / laravel-fullstack / e2e）はここで素通りする。
  - **ファイル名だけを見る検査**であり、中身は読まない。ソースコードへ直書きされた認証情報は検出できない（`docs/06` の「既知の注意点」を参照）。ジョブは `permissions: contents: read` に絞る。
- **actionlint**: `if: workflows == 'true'` で `.github/workflows/**` または `Makefile` の変更時のみ実行。workflow の構文・式（`${{ }}`）・runner ラベル・action の入力・**スクリプトインジェクション**に加え、**`run:` の中身を shellcheck に流す**（`.claude/rules/github-actions.md` が要求する品質ゲートの実体）。取得は公式 Docker イメージ `rhysd/actionlint` のバージョン固定タグ。**CI は `make actionlint` を呼ぶ**ため、コマンドとタグの定義は `Makefile` の 1 箇所だけになり、手元と CI が構造的に一致する（バージョンを上げるときも `Makefile` を変えるだけでよい）。公式イメージを使う理由は **shellcheck が同梱されている**こと。バイナリだけ入れると `run:` の検査が警告もなく静かにスキップされ、終了コード 0 のまま検査が減ったことに気づけない。ジョブは `permissions: contents: read` に絞る。
- **markdown-lint**: `if: docs == 'true'` で md 変更時のみ実行（`markdownlint-cli2`）。対象と無効化ルールの理由は `.markdownlint-cli2.jsonc` に記載し、**警告ゼロを維持**する（`.claude/rules/static-analysis.md`）。ローカルは `make md-lint` / `make md-fix`。バージョンはルートの `package.json` の devDependency で完全固定し（`npm ci`）、更新は Dependabot（`.github/dependabot.yml`・npm / weekly）が PR で上げる。**`run:` に `npx pkg@x.y.z` と直書きするとマニフェストではないため Dependabot から見えず、更新契機が生まれない**（actionlint の Docker タグ固定には同じ制約が残っている）。
- **test**: `needs: changes` + `if: test == 'true'` で実行。`shivammathur/setup-php`（PHP 8.3・`coverage: pcov`）で各アプリをセットアップ（Docker 不使用）、matrix で 3 アプリを並行ジョブ実行（`fail-fast: false`）。Laravel ×2 は `php artisan test --coverage`（テスト DB は SQLite in-memory のため MySQL サービス不要）、Laminas は `vendor/bin/phpunit --coverage-text`。いずれも Clover を出力し、続く手順で `scripts/coverage-threshold.php` が**行カバレッジの下限**を判定する（下限値の正本はそのスクリプト。手元の `make coverage` も同じものを呼ぶ。詳細は `docs/08`）。**ガード自体も同じジョブで自己検証する**（下限割れ・レポート不在の 2 経路で確実に落ちること）。判定が壊れると「常に緑」になり検査の停止に気づけないため、`secret-scan` と同じ考え方で自己検証を置いている。
- **lint**: `if: lint == 'true'` で実行する静的チェックジョブ（matrix で 3 アプリ並行）。Laravel ×2 は `vendor/bin/pint --test`（整形の差分検査）+ `composer analyse`（Larastan/PHPStan・`level: max`）、Laminas は `composer cs-check`（phpcs。`phpcs.xml` の独自 ruleset = PSR-12 + Generic/Squiz の個別 sniff + Slevomat の `DeclareStrictTypes`）+ `vendor/bin/psalm`（型解析・`errorLevel=1`）。**`deps == 'true'` のときは 3 アプリとも `composer audit --abandoned=ignore` を追加実行する**（`--abandoned=ignore` が要るのは、laminas の abandoned パッケージ 4 件があるだけで終了コードが 1 になりゲートとして機能しなくなるため）。静的解析の既存指摘は baseline（Laravel=`apps/laravel-*/phpstan-baseline.neon` / Laminas=`apps/laminas/psalm-baseline.xml`）に記録済みで、CI は**新規に増えた指摘のみ**で失敗する（baseline 運用）。ローカルでの自動修正は Laravel=`vendor/bin/pint`、Laminas=`composer cs-fix`。
- **e2e**: `if: e2e == 'true'` で実行。compose で app + 実 MySQL を起動し、migrate → Playwright で 3 アプリ横断の E2E を検証する（詳細は `docs/08`）。失敗時は Playwright レポートを artifact に上げ、compose ログを出力する。

依存更新の追跡範囲（`.github/dependabot.yml`・いずれも weekly）:

| 対象 | エコシステム | 備考 |
| --- | --- | --- |
| ルート `package.json`（markdownlint-cli2） | `npm` | 完全固定（キャレット無し）。#114 で導入 |
| `ci.yml` の `uses:` で参照する GitHub Actions | `github-actions` | #125 で追加。登録漏れにより 4 アクションが Node.js 20 ランタイムのまま取り残されていた |
| `Makefile` の `rhysd/actionlint:<tag>` | **追跡不可** | `run:` 内の `docker run` はマニフェストではないため、`github-actions` でも `docker`（Dockerfile / compose を読む）でも検出されない。**手動更新**（手順は README） |
| 3 アプリの `composer.json` | 未登録（更新 PR は上がらない） | 更新は手動。**脆弱性の検出は Dependabot alerts（リポジトリ設定・有効）と CI の `composer audit`（`deps` 変更時）の 2 層**で担保する（issue #138・`docs/06`） |

**「マニフェストに書かれた依存だけが追跡される」**という制約が共通の判断軸になる。`uses:` は追跡できる形式なのに登録し忘れていたのが #125 の原因で、構造としては #114（`npx pkg@x.y.z` の直書き）と同じ欠陥だった。追跡できないものは、**追跡できないと明記して手動更新の手順を残す**（黙って放置しない）。

> 補足: 必須チェックの落とし穴を避けるため、**ワークフローレベルの `paths` / `paths-ignore` は使わない**。ワークフロー自体が起動しないと必須チェックが `pending` のまま完了せず PR がマージ不能になるため、「常に起動してジョブレベル `if:` でスキップする（= skipped は成功扱い）」形を採る（`.claude/rules/github-actions.md`）。
>
> `Makefile` のみを変更した PR では **actionlint ジョブだけが実行される**（CI が `make actionlint` を呼ぶため）。他のターゲット（`test` / `md-lint` 等）は CI が Makefile を経由しないため検査されない。意図的なトレードオフとして受け入れている。なお初期化手順の実体は `scripts/setup.sh` にあり、こちらは e2e ジョブが実際に実行するため CI で検査される（`Makefile` の `setup` ターゲットはそれを呼ぶだけ）。
>
> branch protection（必須チェック）導入時は、マトリクスを `if` でスキップすると `test (app)` 個別の check run が生成されない点に注意。その際は `if: always()` の集約ゲートジョブを追加し、それ 1 つを必須チェックに指定する設計が必要になる。現状このリポジトリは private 無料プランで branch protection を利用できないため、集約ゲートは導入していない。

## デプロイ

学習用のためローカル `docker compose up` のみ。環境変数は `.env`（`.env.example` を雛形）。

```bash
make setup   # = scripts/setup.sh（.env・vendor・APP_KEY・書き込み権限・migrate・疎通確認）
```

`docker compose up` だけでは起動しない。**compose の bind mount がイメージ側の `vendor/` を隠す**ため、コンテナ内で `composer install` をやり直す必要があり、Laravel の `.env` / `APP_KEY` も生成されていないため。初期化手順の正本は `scripts/setup.sh` に置き、ローカル（`make setup`）と CI の `e2e` ジョブが同じものを実行する。
