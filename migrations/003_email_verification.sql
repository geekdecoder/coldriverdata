-- Email verification. Accounts that already exist are treated as verified.
ALTER TABLE users ADD COLUMN email_verified_at DATETIME NULL;
UPDATE users SET email_verified_at = created_at;

CREATE TABLE email_verifications (
  token_hash CHAR(64) PRIMARY KEY,
  user_id INT UNSIGNED NOT NULL,
  created_at DATETIME NOT NULL,
  expires_at DATETIME NOT NULL,
  KEY (user_id),
  CONSTRAINT fk_verify_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
