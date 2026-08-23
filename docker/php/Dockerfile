# 3 アプリ共通の PHP-FPM イメージ（PHP 8.3）
FROM php:8.3-fpm

# 必要な OS パッケージ
RUN apt-get update && apt-get install -y --no-install-recommends \
        git unzip libzip-dev libicu-dev libonig-dev \
    && rm -rf /var/lib/apt/lists/*

# PHP 拡張（Laravel / Laminas が要求するもの）
RUN docker-php-ext-configure intl \
    && docker-php-ext-install -j"$(nproc)" pdo_mysql mysqli zip intl bcmath

# アップロードのサイズ上限（既定の 2M / 8M を上書き。理由は uploads.ini に記載）
COPY uploads.ini /usr/local/etc/php/conf.d/uploads.ini

# Composer（公式イメージからバイナリをコピー）
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

# アップロード用ボリュームのマウント先を php-fpm 実行ユーザー(www-data)が書けるようにする。
# 空の名前付きボリュームは初回マウント時にこのディレクトリの所有権を引き継ぐ。
RUN mkdir -p /var/www/uploads && chown -R www-data:www-data /var/www/uploads

WORKDIR /var/www
