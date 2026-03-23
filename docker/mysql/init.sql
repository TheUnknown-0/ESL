-- ============================================================
-- Datenbankschema für PHP Webapp: Schwarzes Brett & Vorschlagssystem
-- Diese Datei wird beim ersten Start automatisch ausgeführt.
-- ============================================================

-- Tabelle: login_attempts (Brute-Force-Schutz)
CREATE TABLE IF NOT EXISTS login_attempts (
    id INT AUTO_INCREMENT PRIMARY KEY,
    ip_address VARCHAR(45) NOT NULL,
    username VARCHAR(100) NOT NULL,
    attempted_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_ip_user (ip_address, username),
    INDEX idx_attempted (attempted_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Tabelle: users
CREATE TABLE IF NOT EXISTS users (
    id INT AUTO_INCREMENT PRIMARY KEY,
    username VARCHAR(100) NOT NULL UNIQUE,
    password_hash VARCHAR(255) NOT NULL,
    email VARCHAR(255) NOT NULL,
    is_admin TINYINT(1) DEFAULT 0,
    email_notifications TINYINT(1) DEFAULT 1,
    theme ENUM('light','dark') DEFAULT 'light',
    style ENUM('default','anthropic') DEFAULT 'default',
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Tabelle: pages (modulare Navigation)
CREATE TABLE IF NOT EXISTS pages (
    id INT AUTO_INCREMENT PRIMARY KEY,
    slug VARCHAR(100) NOT NULL UNIQUE,
    label VARCHAR(100) NOT NULL,
    icon VARCHAR(50) DEFAULT NULL,
    requires_admin TINYINT(1) DEFAULT 0,
    sort_order INT DEFAULT 0,
    active TINYINT(1) DEFAULT 1
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Tabelle: projects
CREATE TABLE IF NOT EXISTS projects (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(255) NOT NULL,
    description TEXT NOT NULL,
    reason TEXT NOT NULL,
    status ENUM('Vorgeschlagen','In Besprechung','In Bearbeitung','Angenommen','Abgelehnt') DEFAULT 'Vorgeschlagen',
    priority ENUM('Hoch','Mittel','Niedrig') DEFAULT NULL,
    is_anonymous TINYINT(1) DEFAULT 0,
    proposed_by INT DEFAULT NULL,
    decision_reason TEXT DEFAULT NULL,
    decided_by INT DEFAULT NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (proposed_by) REFERENCES users(id) ON DELETE SET NULL,
    FOREIGN KEY (decided_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Tabelle: settings (globale Konfiguration)
CREATE TABLE IF NOT EXISTS settings (
    id INT AUTO_INCREMENT PRIMARY KEY,
    setting_key VARCHAR(100) NOT NULL UNIQUE,
    setting_value TEXT DEFAULT NULL,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- Initiale Daten
-- ============================================================

-- Navigationsseiten einfügen
INSERT INTO pages (slug, label, icon, requires_admin, sort_order) VALUES
    ('schwarzes-brett', 'Schwarzes Brett', NULL, 0, 1),
    ('vorschlag',       'Vorschlag',       NULL, 0, 2),
    ('verwaltung',      'Verwaltung',      NULL, 1, 3),
    ('einstellungen',   'Einstellungen',   NULL, 0, 4);

-- Globale Einstellungen
INSERT INTO settings (setting_key, setting_value) VALUES ('mail_disabled', '0');

-- Initialer Admin-Nutzer (Passwort: Admin123!)
-- bcrypt-Hash für 'Admin123!' erzeugt mit password_hash('Admin123!', PASSWORD_BCRYPT)
INSERT INTO users (username, password_hash, email, is_admin, email_notifications) VALUES
    ('admin', '$2y$10$fBZGHsAqKFBql08jRD3kDelFpiLABwHgUgIuktTLmX0Pv.j2wKM0G', 'admin@example.com', 1, 1);
