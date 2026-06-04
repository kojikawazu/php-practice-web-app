# 3 アプリ共通の PHP-FPM イメージ（PHP 8.3）
FROM php:8.3-fpm

# 必要な OS パッケージ
RUN apt-get update && apt-get install -y --no-install-recommends \
        git unzip libzip-dev libicu-dev libonig-dev \
    && rm -rf /var/lib/apt/lists/*

# PHP 拡張（Laravel / Laminas が要求するもの）
RUN docker-php-ext-configure intl \
    && docker-php-ext-install -j"$(nproc)" pdo_mysql mysqli zip intl bcmath

# Composer（公式イメージからバイナリをコピー）
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /var/www
