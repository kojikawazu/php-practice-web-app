#!/usr/bin/env bash
#
# fresh clone から 3 アプリが応答する状態までを一括で用意する。
#
# compose の bind mount はイメージ側の内容を隠すため、`docker compose up` しただけでは
# vendor/ も Laravel の .env / APP_KEY も存在せず、3 アプリは起動しない。
# その初期化手順の正本をこのスクリプトに置き、ローカル（make setup）と CI の e2e ジョブが
# 同じものを実行する。手順が README・Makefile・ci.yml に分かれて乖離するのを防ぐため。
#
# 冪等性: 何度実行しても既存の .env・APP_KEY・DB のデータを壊さない。
# 特に APP_KEY は再生成すると既存セッション・暗号化データが復号できなくなるため、
# 未設定のときだけ生成する（CI は常に fresh なので実質毎回生成される）。
set -euo pipefail

cd "$(dirname "$0")/.."

log() { printf '\n==> %s\n' "$*"; }

# ---- 1. ルート .env（compose が MySQL の認証情報を読む）----
log 'ルート .env を用意する'
if [ -f .env ]; then
  echo '.env は既にあるので触らない'
else
  cp .env.example .env
  echo '.env を .env.example から作成した'
fi

# compose と同じ .env を読み込み、ポートの上書きに追従する
set -a
# shellcheck disable=SC1091 # 実行時に生成される .env（リポジトリ管理外）のため解析できない
. ./.env
set +a

# ---- 2. コンテナ起動 ----
log 'コンテナを起動する（docker compose up -d --build）'
docker compose up -d --build

log 'MySQL の healthy を待つ'
for _ in $(seq 1 60); do
  if docker compose ps mysql | grep -q healthy; then
    echo 'mysql healthy'
    break
  fi
  sleep 3
done
if ! docker compose ps mysql | grep -q healthy; then
  echo 'mysql が healthy になりませんでした' >&2
  docker compose ps >&2
  exit 1
fi

# ---- 3. Laravel アプリの .env ----
log 'Laravel 2 アプリの .env を用意する'
for app in laravel-fullstack laravel-api; do
  if [ -f "apps/$app/.env" ]; then
    echo "apps/$app/.env は既にあるので触らない"
  else
    cp "apps/$app/.env.example" "apps/$app/.env"
    echo "apps/$app/.env を作成した"
  fi
done

# ---- 4. Composer 依存（コンテナ内）----
# bind mount がイメージ側の vendor を隠すため、コンテナ内で入れ直す必要がある。
log '3 アプリの Composer 依存を導入する'
for svc in php-fs php-api php-laminas; do
  echo "--- $svc ---"
  docker compose exec -T "$svc" composer install --no-interaction --prefer-dist --no-progress
done

# ---- 5. フレームワークの書き込み先 ----
# php-fpm は www-data で動くが、チェックアウトはホストユーザー所有。Laravel の
# storage / bootstrap-cache（ビューのコンパイル）と laminas の data/cache（設定キャッシュ）に
# 書けないと 500 になる。macOS の bind mount は寛容だが Linux は厳格なため、そちらで顕在化する。
#
# `a+rwX` の X（大文字）はディレクトリにだけ実行権を付ける。`777` にすると
# 対象に含まれる追跡ファイル（.gitignore / .gitkeep）の実行ビットまで立ち、
# git が 100644 => 100755 のモード変更として拾って作業ツリーが汚れる（issue #112）。
# ディレクトリの権限は 777 相当のままなので、www-data の書き込みには影響しない。
log 'フレームワークの書き込み先を用意する'
for svc in php-fs php-api; do
  docker compose exec -T --user root "$svc" sh -c \
    'mkdir -p storage/framework/sessions storage/framework/views storage/framework/cache bootstrap/cache && chmod -R a+rwX storage bootstrap/cache'
done
docker compose exec -T --user root php-laminas sh -c 'mkdir -p data/cache && chmod -R a+rwX data'

# ---- 6. APP_KEY（未設定のときだけ生成する）----
log 'Laravel の APP_KEY を確認する'
set -- php-fs:laravel-fullstack php-api:laravel-api
for pair in "$@"; do
  svc="${pair%%:*}"
  app="${pair##*:}"
  if grep -qE '^APP_KEY=.+' "apps/$app/.env"; then
    echo "apps/$app: APP_KEY は設定済みのため再生成しない"
  else
    docker compose exec -T "$svc" php artisan key:generate --force
  fi
done

# ---- 7. laminas のスキーマ（既存ボリュームにも反映する）----
# docker/mysql/init/*.sql は mysql コンテナの初回起動時（空ボリューム時）にしか実行されない。
# そのため既存環境ではテーブル追加が反映されず、laminas だけ実行時に落ちる（docs/05）。
# ここで同じ SQL を流し直して差を埋める。CREATE TABLE IF NOT EXISTS のみで構成されているため
# 何度実行しても既存データを壊さない（.claude/rules/production-data.md）。
log 'laminas のスキーマを適用する（初期化 SQL を冪等に流し直す）'
for sql in docker/mysql/init/*.sql; do
  echo "--- $(basename "$sql") ---"
  docker compose exec -T mysql \
    mysql -u"${MYSQL_USER:-app}" -p"${MYSQL_PASSWORD:-secret}" "${MYSQL_DATABASE:-php_practice}" < "$sql"
done

# ---- 7. マイグレーション（lam_tasks は MySQL の初期化 SQL で作成済み）----
log 'Laravel 2 アプリのマイグレーションを流す'
docker compose exec -T php-fs php artisan migrate --force
docker compose exec -T php-api php artisan migrate --force

# ---- 8. 3 アプリが応答することを確認する ----
# 200/302/401 を「正常起動」とみなす（api は Accept ヘッダ付きで 401 を期待）。
# ここで落としておかないと、後続のテストが 500 のまま長時間ハングする。
log '3 アプリの応答を確認する'
check() {
  url="$1"
  shift
  last=''
  for _ in $(seq 1 40); do
    code=$(curl -s -o /dev/null -w '%{http_code}' "$@" "$url" || true)
    last="$code"
    case "$code" in
      200 | 302 | 401)
        echo "$url -> $code"
        return 0
        ;;
    esac
    sleep 3
  done
  echo "アプリが応答しません: $url (最後のステータス: $last)" >&2
  return 1
}
fs_port="${FS_PORT:-8001}"
api_port="${API_PORT:-8002}"
laminas_port="${LAMINAS_PORT:-8003}"
check "http://localhost:${fs_port}/login"
check "http://localhost:${laminas_port}/login"
check "http://localhost:${api_port}/api/tasks" -H 'Accept: application/json'

log 'セットアップ完了'
printf '  fullstack: http://localhost:%s/tasks\n' "$fs_port"
printf '  api:       http://localhost:%s/api/tasks\n' "$api_port"
printf '  laminas:   http://localhost:%s/tasks\n' "$laminas_port"
