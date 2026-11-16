-- Chat folders and stars, per-user theme, display name and profile photo.
ALTER TABLE users
  ADD COLUMN display_name VARCHAR(80) NULL,
  ADD COLUMN avatar_at DATETIME NULL,
  ADD COLUMN theme VARCHAR(20) NULL;

CREATE TABLE folders (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id INT UNSIGNED NOT NULL,
  name VARCHAR(80) NOT NULL,
  created_at DATETIME NOT NULL,
  UNIQUE KEY uniq_folder (user_id, name),
  CONSTRAINT fk_folder_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

ALTER TABLE conversations
  ADD COLUMN folder_id INT UNSIGNED NULL,
  ADD COLUMN starred TINYINT(1) NOT NULL DEFAULT 0,
  ADD KEY idx_conv_folder (user_id, folder_id),
  ADD CONSTRAINT fk_conv_folder FOREIGN KEY (folder_id) REFERENCES folders(id) ON DELETE SET NULL;
