<?php
/**
 * Konfigurationsdatei
 * Liest alle Werte aus Umgebungsvariablen.
 * Niemals Zugangsdaten hardcoden!
 */

// Fehlerbehandlung: Fehler nur ins Log schreiben, nicht anzeigen
ini_set('display_errors', '0');
ini_set('log_errors', '1');
error_reporting(E_ALL);

// Session-Konfiguration für Sicherheit
ini_set('session.cookie_httponly', '1');
ini_set('session.cookie_samesite', 'Strict');
ini_set('session.use_strict_mode', '1');

// Datenbank-Konfiguration aus Umgebungsvariablen
define('DB_HOST', getenv('DB_HOST') ?: 'db');
define('DB_PORT', getenv('DB_PORT') ?: '3306');
define('DB_NAME', getenv('DB_NAME') ?: 'webapp');
define('DB_USER', getenv('DB_USER') ?: 'webuser');
define('DB_PASS', getenv('DB_PASS') ?: 'geheimespasswort');

// E-Mail-Konfiguration
define('MAIL_FROM', getenv('MAIL_FROM') ?: 'noreply@example.com');

// Anwendungsumgebung
define('APP_ENV', getenv('APP_ENV') ?: 'production');

// Brute-Force-Schutz Konstanten
define('MAX_LOGIN_ATTEMPTS', 5);
define('LOGIN_LOCKOUT_MINUTES', 15);
