<?php

/**
 * Global Configuration Override
 *
 * You can use this file for overriding configuration values from modules, etc.
 * You would place values in here that are agnostic to the environment and not
 * sensitive to security.
 *
 * NOTE: In practice, this file will typically be INCLUDED in your source
 * control, so do not include passwords or other sensitive information in this
 * file.
 */

return [
    // DB 接続情報（学習用のため getenv フォールバック付き）。
    // 本番では認証情報を local.php（リポジトリ管理外）へ移すこと。
    'db' => [
        'driver'   => 'Pdo_Mysql',
        'hostname' => getenv('DB_HOST') ?: 'mysql',
        'port'     => (int) (getenv('DB_PORT') ?: 3306),
        'database' => getenv('DB_DATABASE') ?: 'php_practice',
        'username' => getenv('DB_USERNAME') ?: 'app',
        'password' => getenv('DB_PASSWORD') ?: 'secret',
        'charset'  => 'utf8mb4',
        'driver_options' => [
            PDO::MYSQL_ATTR_INIT_COMMAND => 'SET NAMES utf8mb4',
        ],
    ],
];
