CREATE DATABASE IF NOT EXISTS workerman_test CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE workerman_test;
CREATE TABLE IF NOT EXISTS acceptance_requests (
    marker VARCHAR(96) NOT NULL PRIMARY KEY,
    payload VARCHAR(96) NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;
CREATE TABLE IF NOT EXISTS acceptance_counters (
    counter_key VARCHAR(64) NOT NULL PRIMARY KEY,
    counter_value BIGINT UNSIGNED NOT NULL DEFAULT 0
) ENGINE=InnoDB;
INSERT INTO acceptance_counters (counter_key, counter_value) VALUES ('total', 0)
    ON DUPLICATE KEY UPDATE counter_key = VALUES(counter_key);
