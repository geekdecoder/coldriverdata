-- Encryption at rest for object storage, and database backups.
ALTER TABLE storage_backends
  ADD COLUMN encrypt TINYINT(1) NOT NULL DEFAULT 0,
  ADD COLUMN is_backup TINYINT(1) NOT NULL DEFAULT 0;

ALTER TABLE files ADD COLUMN encrypted TINYINT(1) NOT NULL DEFAULT 0;

CREATE TABLE backups (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  backend_id INT UNSIGNED NOT NULL,
  object_key VARCHAR(190) NOT NULL,
  size BIGINT UNSIGNED NOT NULL,
  created_at DATETIME NOT NULL,
  KEY (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
