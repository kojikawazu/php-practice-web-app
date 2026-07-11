.PHONY: up down build logs ps migrate test test-fs test-api test-laminas e2e

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
