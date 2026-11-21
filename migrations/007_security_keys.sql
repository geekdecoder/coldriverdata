-- Security keys (YubiKey and other FIDO2 / WebAuthn keys) as a second step at sign-in.
CREATE TABLE security_keys (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id INT UNSIGNED NOT NULL,
  credential_id VARCHAR(700) NOT NULL,
  public_key TEXT NOT NULL,
  sign_count BIGINT UNSIGNED NOT NULL DEFAULT 0,
  name VARCHAR(80) NOT NULL,
  created_at DATETIME NOT NULL,
  last_used_at DATETIME NULL,
  UNIQUE KEY uniq_credential (credential_id),
  KEY (user_id),
  CONSTRAINT fk_key_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
