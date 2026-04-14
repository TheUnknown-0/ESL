-- Migration 004: Kommentare, Upvotes und Feature-Einstellungen
-- Idempotent: mehrfaches Ausführen ist sicher (läuft bei jedem Container-Start).

-- ---------- 1. Neue Settings-Keys (INSERT IGNORE ist idempotent) ----------
INSERT IGNORE INTO settings (setting_key, setting_value) VALUES
    ('comments_enabled',    '1'),
    ('comments_permission', 'all'),
    ('upvotes_enabled',     '1'),
    ('upvotes_permission',  'all');

-- ---------- 2. Neue Spalten in projects (pro Spalte einzeln prüfen) -------
-- Pattern wie in Migration 003: COUNT aus INFORMATION_SCHEMA + PREPARE/EXECUTE.

-- comments_enabled
SET @c = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
          WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'projects'
            AND COLUMN_NAME = 'comments_enabled');
SET @s = IF(@c = 0,
    "ALTER TABLE projects ADD COLUMN comments_enabled TINYINT(1) DEFAULT NULL AFTER priority",
    'SELECT 1');
PREPARE stmt FROM @s; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- comments_permission
SET @c = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
          WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'projects'
            AND COLUMN_NAME = 'comments_permission');
SET @s = IF(@c = 0,
    "ALTER TABLE projects ADD COLUMN comments_permission ENUM('all','admin') DEFAULT NULL AFTER comments_enabled",
    'SELECT 1');
PREPARE stmt FROM @s; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- upvotes_enabled
SET @c = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
          WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'projects'
            AND COLUMN_NAME = 'upvotes_enabled');
SET @s = IF(@c = 0,
    "ALTER TABLE projects ADD COLUMN upvotes_enabled TINYINT(1) DEFAULT NULL AFTER comments_permission",
    'SELECT 1');
PREPARE stmt FROM @s; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- upvotes_permission
SET @c = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
          WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'projects'
            AND COLUMN_NAME = 'upvotes_permission');
SET @s = IF(@c = 0,
    "ALTER TABLE projects ADD COLUMN upvotes_permission ENUM('all','admin') DEFAULT NULL AFTER upvotes_enabled",
    'SELECT 1');
PREPARE stmt FROM @s; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- ---------- 3. Neue Tabellen (CREATE TABLE IF NOT EXISTS ist idempotent) --
CREATE TABLE IF NOT EXISTS project_comments (
    id INT AUTO_INCREMENT PRIMARY KEY,
    project_id INT NOT NULL,
    user_id    INT DEFAULT NULL,
    content    TEXT NOT NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (project_id) REFERENCES projects(id) ON DELETE CASCADE,
    FOREIGN KEY (user_id)    REFERENCES users(id)    ON DELETE SET NULL,
    INDEX idx_project (project_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS project_upvotes (
    id INT AUTO_INCREMENT PRIMARY KEY,
    project_id INT NOT NULL,
    user_id    INT NOT NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_project_user (project_id, user_id),
    FOREIGN KEY (project_id) REFERENCES projects(id) ON DELETE CASCADE,
    FOREIGN KEY (user_id)    REFERENCES users(id)    ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
