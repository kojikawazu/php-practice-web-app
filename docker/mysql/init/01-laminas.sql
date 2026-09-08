-- Laminas はマイグレーションツールを使わないため、初期テーブルを SQL で用意する。
-- このファイルは mysql コンテナ初回起動時（空ボリューム時）に自動実行される。
--
-- 既存ボリュームには自動適用されないため、scripts/setup.sh が同じ内容を冪等に流し直す。
-- そのため **全文を「何度実行しても既存データを壊さない」形に保つこと**
-- （CREATE TABLE IF NOT EXISTS のみ。DROP / TRUNCATE / 無条件 DELETE を書かない。
--  .claude/rules/production-data.md）。

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
    start_date DATE            NULL DEFAULT NULL,
    end_date   DATE            NULL DEFAULT NULL,
    created_at TIMESTAMP       NULL DEFAULT NULL,
    updated_at TIMESTAMP       NULL DEFAULT NULL,
    PRIMARY KEY (id),
    KEY idx_lam_tasks_user (user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ログイン・登録の試行回数（レートリミット。issue #142）。
-- Laravel の RateLimiter はキャッシュに置くが、laminas には相当機構が無いため
-- 自前で持つ。セッションに置くと Cookie を捨てるだけで回避できるので使えない。
--
-- attempt_key は「何で区切って数えるか」をそのまま入れる（例: login|alice|10.0.0.1）。
-- 期間外の行は判定のたびに削除するため、掃除用のバッチを持たない。
CREATE TABLE IF NOT EXISTS lam_login_attempts (
    id           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    attempt_key  VARCHAR(191)    NOT NULL,
    attempted_at TIMESTAMP       NOT NULL,
    PRIMARY KEY (id),
    KEY idx_lam_login_attempts_key_time (attempt_key, attempted_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
