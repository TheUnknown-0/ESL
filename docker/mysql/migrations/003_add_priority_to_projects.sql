-- Migration 003: Prioritätsspalte zur projects-Tabelle hinzufügen
-- priority = NULL bedeutet "unpriorisiert"
-- priority IN ('Hoch', 'Mittel', 'Niedrig') = priorisiert

ALTER TABLE projects
    ADD COLUMN priority ENUM('Hoch', 'Mittel', 'Niedrig') DEFAULT NULL
    AFTER status;
