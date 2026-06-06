-- Laminas はマイグレーションツールを使わないため、初期テーブルを SQL で用意する。
-- このファイルは mysql コンテナ初回起動時（空ボリューム時）に自動実行される。

CREATE TABLE IF NOT EXISTS lam_users (
    id         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    username   VARCHAR(255)    NOT NULL,
    password   VARCHAR(255)    NOT NULL,
    created_at TIMESTAMP       NULL DEFAULT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_lam_users_username (username)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS lam_tasks (
    id         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id    BIGINT UNSIGNED NOT NULL,
    title      VARCHAR(255)    NOT NULL,
    done       TINYINT(1)      NOT NULL DEFAULT 0,
    created_at TIMESTAMP       NULL DEFAULT NULL,
    updated_at TIMESTAMP       NULL DEFAULT NULL,
    PRIMARY KEY (id),
    KEY idx_lam_tasks_user (user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
