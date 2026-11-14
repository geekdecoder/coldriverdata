-- Cold River Data schema (MySQL 8 / MariaDB 10.6+)
CREATE TABLE IF NOT EXISTS users (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  email VARCHAR(190) NOT NULL UNIQUE,
  password_hash VARCHAR(255) NOT NULL,
  plan VARCHAR(32) NULL,
  pay_method VARCHAR(16) NULL,              -- card | crypto
  plan_status VARCHAR(32) NULL,             -- active | past_due | canceled
  paid_through DATETIME NULL,               -- UTC; access ends after this
  stripe_customer_id VARCHAR(64) NULL,
  stripe_subscription_id VARCHAR(64) NULL,
  created_at DATETIME NOT NULL,
  KEY (stripe_customer_id), KEY (stripe_subscription_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS files (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id INT UNSIGNED NOT NULL,
  name VARCHAR(255) NOT NULL,
  size BIGINT UNSIGNED NOT NULL,
  sha256 CHAR(64) NOT NULL,
  storage_key VARCHAR(80) NOT NULL,
  created_at DATETIME NOT NULL,
  KEY (user_id),
  CONSTRAINT fk_files_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS imports (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id INT UNSIGNED NOT NULL,
  file_id INT UNSIGNED NOT NULL,
  status VARCHAR(16) NOT NULL DEFAULT 'queued',  -- queued | running | done | skipped | failed
  conversations INT UNSIGNED NOT NULL DEFAULT 0,
  messages INT UNSIGNED NOT NULL DEFAULT 0,
  error VARCHAR(500) NULL,
  created_at DATETIME NOT NULL,
  finished_at DATETIME NULL,
  KEY (status), KEY (user_id),
  CONSTRAINT fk_imports_file FOREIGN KEY (file_id) REFERENCES files(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS conversations (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id INT UNSIGNED NOT NULL,
  file_id INT UNSIGNED NULL,
  source VARCHAR(16) NOT NULL,              -- chatgpt | claude
  ext_id VARCHAR(100) NOT NULL,
  title VARCHAR(500) NOT NULL,
  started_at DATETIME NULL,
  updated_at DATETIME NULL,
  message_count INT UNSIGNED NOT NULL DEFAULT 0,
  UNIQUE KEY uniq_conv (user_id, source, ext_id),
  KEY (user_id, updated_at),
  CONSTRAINT fk_conv_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  CONSTRAINT fk_conv_file FOREIGN KEY (file_id) REFERENCES files(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS messages (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  conversation_id INT UNSIGNED NOT NULL,
  user_id INT UNSIGNED NOT NULL,
  position INT UNSIGNED NOT NULL,
  role VARCHAR(16) NOT NULL,                -- user | assistant
  body MEDIUMTEXT NOT NULL,
  sent_at DATETIME NULL,
  KEY (conversation_id, position), KEY (user_id),
  FULLTEXT KEY ft_body (body),
  CONSTRAINT fk_msg_conv FOREIGN KEY (conversation_id) REFERENCES conversations(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS password_resets (
  token_hash CHAR(64) PRIMARY KEY,
  user_id INT UNSIGNED NOT NULL,
  expires_at DATETIME NOT NULL,
  CONSTRAINT fk_reset_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS login_attempts (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  ip VARCHAR(45) NOT NULL,
  at DATETIME NOT NULL,
  KEY (ip, at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS stripe_events (
  id VARCHAR(80) PRIMARY KEY,
  received_at DATETIME NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
