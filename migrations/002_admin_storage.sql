-- Admin area and storage locations.
ALTER TABLE users
  ADD COLUMN is_admin TINYINT(1) NOT NULL DEFAULT 0,
  ADD COLUMN suspended_at DATETIME NULL;

CREATE TABLE storage_backends (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(100) NOT NULL,
  type VARCHAR(8) NOT NULL,                 -- local | s3
  path VARCHAR(500) NULL,                   -- local: folder on this server
  endpoint VARCHAR(255) NULL,               -- s3: e.g. fsn1.your-objectstorage.com
  region VARCHAR(64) NULL,
  bucket VARCHAR(100) NULL,
  access_key VARCHAR(190) NULL,
  secret_enc TEXT NULL,                     -- s3 secret key, encrypted with app_key
  path_style TINYINT(1) NOT NULL DEFAULT 0,
  capacity_bytes BIGINT UNSIGNED NULL,      -- optional, for the usage display
  status VARCHAR(10) NOT NULL DEFAULT 'active',  -- active | readonly
  is_default TINYINT(1) NOT NULL DEFAULT 0, -- new uploads go here
  created_at DATETIME NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- backend_id NULL = the built-in storage_dir from config.php.
-- ship_to = object storage the worker still has to move this file to.
ALTER TABLE files
  ADD COLUMN backend_id INT UNSIGNED NULL,
  ADD COLUMN ship_to INT UNSIGNED NULL,
  ADD COLUMN ship_error VARCHAR(500) NULL,
  ADD KEY (backend_id), ADD KEY (ship_to);

CREATE TABLE admin_log (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  admin_id INT UNSIGNED NOT NULL,
  admin_email VARCHAR(190) NOT NULL,
  action VARCHAR(60) NOT NULL,
  target VARCHAR(190) NULL,
  detail VARCHAR(500) NULL,
  at DATETIME NOT NULL,
  KEY (at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
