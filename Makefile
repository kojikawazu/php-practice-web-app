.PHONY: setup up down build logs ps migrate test test-fs test-api test-laminas e2e actionlint md-lint md-fix

# 初回セットアップ（fresh clone から 3 アプリが応答する状態まで）。
# .env・vendor・APP_KEY・書き込み権限・マイグレーションまでを一括で用意する。
# 冪等なので、環境が壊れたと思ったら何度でも実行してよい。
# 手順の正本は scripts/setup.sh。CI の e2e ジョブも同じスクリプトを実行する。
setup:
	./scripts/setup.sh

# コンテナ起動 / 停止
up:
	docker compose up -d --build

down:
	docker compose down

build:
	docker compose build

logs:
	docker compose logs -f

ps:
	docker compose ps

# 両 Laravel アプリのマイグレーション
migrate:
	docker compose exec php-fs php artisan migrate --force
	docker compose exec php-api php artisan migrate --force

# テスト（全アプリ）
test: test-fs test-api test-laminas

test-fs:
	docker compose exec php-fs php artisan test

test-api:
	docker compose exec php-api php artisan test

test-laminas:
	docker compose exec php-laminas ./vendor/bin/phpunit

# E2E（Playwright）。事前に `make up && make migrate` で実環境を起動しておくこと。
# 3アプリ（fullstack/laminas=ブラウザ, api=HTTP）を 1 ランナーで実行する。
e2e:
	cd e2e && npm ci && npx playwright install chromium && npx playwright test

# GitHub Actions ワークフローの静的解析（構文・式・runner ラベル + run: の shellcheck）。
# CI の actionlint ジョブがこのターゲットを呼ぶため、コマンドとイメージのタグの定義は
# ここが唯一の正本（2 箇所に書き写すと、片方だけ上げても CI は緑のまま検査内容がずれる）。
# 公式イメージを使うのは shellcheck が同梱されているため。バイナリだけ入れると run: の
# 検査が警告もなく静かにスキップされ、終了コード 0 のまま検査が減ったことに気づけない。
actionlint:
	docker run --rm -v "$$PWD":/repo --workdir /repo rhysd/actionlint:1.7.12 -color

# markdown lint。対象と無効化ルールの理由は .markdownlint-cli2.jsonc に記載。
# CI の markdown-lint ジョブと同じコマンド・同じバージョンを実行する（手元で先に直せるように）。
# バージョンは package.json の devDependency で完全固定し、更新は Dependabot の PR で上がる
# （run: に npx で直書きするとマニフェストではないため Dependabot から見えない）。
md-lint: node_modules
	npm run lint:md

# 自動修正できる指摘（テーブルの列スタイル・空行の過不足など）を直す。MD040 等は手で直す。
md-fix: node_modules
	npm run lint:md:fix

# package-lock.json より古いときだけ入れ直す（毎回 npm ci を走らせない）。
node_modules: package-lock.json
	npm ci
	@touch node_modules
