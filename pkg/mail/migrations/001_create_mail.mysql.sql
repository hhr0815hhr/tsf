CREATE TABLE IF NOT EXISTS mail (
    id         INT AUTO_INCREMENT PRIMARY KEY,
    uid        INT NOT NULL,
    title      VARCHAR(64) NOT NULL,
    body       VARCHAR(1024) NOT NULL DEFAULT '',
    items      VARCHAR(1024) NOT NULL DEFAULT '',
    created_at BIGINT NOT NULL,
    expire_at  BIGINT NOT NULL DEFAULT 0,
    read_at    BIGINT NOT NULL DEFAULT 0,
    claimed_at BIGINT NOT NULL DEFAULT 0,
    INDEX idx_mail_uid (uid)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
