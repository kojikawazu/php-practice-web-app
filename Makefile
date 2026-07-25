.PHONY: up down build logs ps migrate test test-fs test-api test-laminas e2e md-lint md-fix

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

# markdown lint。対象と無効化ルールの理由は .markdownlint-cli2.jsonc に記載。
# CI の markdown-lint ジョブと同じコマンドを実行する（手元で先に直せるように）。
md-lint:
	npx --yes markdownlint-cli2@0.18.1

# 自動修正できる指摘（空行の過不足など）を直す。MD040 等は手で直す必要がある。
md-fix:
	npx --yes markdownlint-cli2@0.18.1 --fix
