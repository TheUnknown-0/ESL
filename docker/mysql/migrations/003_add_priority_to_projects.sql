-- Migration 003: Prioritätsspalte zur projects-Tabelle hinzufügen
-- priority = NULL bedeutet "unpriorisiert"
-- priority IN ('Hoch', 'Mittel', 'Niedrig') = priorisiert

SET @col_exists = (
    SELECT COUNT(*)
    FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME   = 'projects'
      AND COLUMN_NAME  = 'priority'
);
SET @sql = IF(
    @col_exists = 0,
    "ALTER TABLE projects ADD COLUMN priority ENUM('Hoch','Mittel','Niedrig') DEFAULT NULL AFTER status",
    'SELECT 1'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
