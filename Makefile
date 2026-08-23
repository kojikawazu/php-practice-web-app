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
# CI の actionlint ジョブと同じコマンド・同じバージョンを実行する（手元で先に直せるように）。
# バージョンを上げるときは .github/workflows/ci.yml も同じタグに揃える。
actionlint:
	docker run --rm -v "$$PWD":/repo --workdir /repo rhysd/actionlint:1.7.12 -color

# markdown lint。対象と無効化ルールの理由は .markdownlint-cli2.jsonc に記載。
# CI の markdown-lint ジョブと同じコマンドを実行する（手元で先に直せるように）。
md-lint:
	npx --yes markdownlint-cli2@0.18.1

# 自動修正できる指摘（空行の過不足など）を直す。MD040 等は手で直す必要がある。
md-fix:
	npx --yes markdownlint-cli2@0.18.1 --fix
