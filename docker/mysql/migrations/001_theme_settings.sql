-- Migration 001: Theme-Einstellungen pro Nutzer
-- Ausführen bei bereits bestehenden Datenbanken:
--   docker exec -i <mysql-container> mysql -u<user> -p<pass> <dbname> < migrations/001_theme_settings.sql

-- Prüfen ob Spalten bereits existieren, dann hinzufügen
SET @col_exists = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users' AND COLUMN_NAME = 'theme');
SET @sql = IF(@col_exists = 0, "ALTER TABLE users ADD COLUMN theme ENUM('light','dark') DEFAULT 'light'", 'SELECT 1');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @col_exists = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users' AND COLUMN_NAME = 'style');
SET @sql = IF(@col_exists = 0, "ALTER TABLE users ADD COLUMN style ENUM('default','anthropic') DEFAULT 'default'", 'SELECT 1');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

INSERT IGNORE INTO pages (slug, label, icon, requires_admin, sort_order)
VALUES ('einstellungen', 'Einstellungen', NULL, 0, 4);
