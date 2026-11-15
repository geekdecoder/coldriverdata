-- Two-step sign-in (authenticator app codes plus one-time recovery codes).
ALTER TABLE users
  ADD COLUMN totp_secret_enc TEXT NULL,
  ADD COLUMN totp_enabled_at DATETIME NULL,
  ADD COLUMN totp_last_step BIGINT UNSIGNED NULL;

CREATE TABLE recovery_codes (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id INT UNSIGNED NOT NULL,
  code_hash CHAR(64) NOT NULL,
  used_at DATETIME NULL,
  KEY (user_id),
  CONSTRAINT fk_recovery_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
