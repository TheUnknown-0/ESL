-- Migration: 'Umgesetzt' und 'Angenommen' zu einem Status 'Angenommen' zusammenführen
-- Bestehende 'Umgesetzt'-Einträge auf 'Angenommen' setzen
UPDATE projects SET status = 'Angenommen' WHERE status = 'Umgesetzt';

-- ENUM-Spalte anpassen (Umgesetzt entfernen)
ALTER TABLE projects
    MODIFY COLUMN status ENUM('Vorgeschlagen','In Besprechung','In Bearbeitung','Angenommen','Abgelehnt') DEFAULT 'Vorgeschlagen';

-- Globale E-Mail-Sperre Einstellung (für Admins)
ALTER TABLE pages ADD COLUMN IF NOT EXISTS extra_json TEXT DEFAULT NULL;

-- System-Einstellungen-Tabelle (globale Konfiguration)
CREATE TABLE IF NOT EXISTS settings (
    id INT AUTO_INCREMENT PRIMARY KEY,
    setting_key VARCHAR(100) NOT NULL UNIQUE,
    setting_value TEXT DEFAULT NULL,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO settings (setting_key, setting_value) VALUES ('mail_disabled', '0');
