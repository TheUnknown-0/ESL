-- Migration 005: E-Mail optional + Benachrichtigungen default aus
-- Bestehende Nutzer behalten ihre aktuellen Werte; Defaults gelten nur für neue Inserts.

ALTER TABLE users MODIFY COLUMN email VARCHAR(255) NULL;
ALTER TABLE users MODIFY COLUMN email_notifications TINYINT(1) DEFAULT 0;
