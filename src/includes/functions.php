<?php
/**
 * Allgemeine Hilfsfunktionen
 */

/**
 * Escaped einen String für sichere HTML-Ausgabe (XSS-Schutz).
 *
 * @param string|null $str Der zu escapende String
 * @return string Escapeter String
 */
function e(?string $str): string
{
    return htmlspecialchars($str ?? '', ENT_QUOTES, 'UTF-8');
}

/**
 * Gibt die CSS-Klasse für einen Projektstatus zurück (Tailwind-Farben).
 *
 * @param string $status Der Projektstatus
 * @return string Tailwind CSS-Klassen
 */
function getStatusColor(string $status): string
{
    return match ($status) {
        'Vorgeschlagen'   => 'bg-gray-200 text-gray-800',
        'In Besprechung'  => 'bg-yellow-200 text-yellow-800',
        'In Bearbeitung'  => 'bg-blue-200 text-blue-800',
        'Angenommen'      => 'bg-green-200 text-green-800',
        'Abgelehnt'       => 'bg-red-200 text-red-800',
        default           => 'bg-gray-100 text-gray-600',
    };
}

/**
 * Gibt die Hintergrundfarbe für einen Projektstatus zurück (für Karten-Rahmen).
 *
 * @param string $status Der Projektstatus
 * @return string Tailwind CSS-Klasse für den Rahmen
 */
function getStatusBorderColor(string $status): string
{
    return match ($status) {
        'Vorgeschlagen'   => 'border-gray-400',
        'In Besprechung'  => 'border-yellow-400',
        'In Bearbeitung'  => 'border-blue-400',
        'Angenommen'      => 'border-green-400',
        'Abgelehnt'       => 'border-red-400',
        default           => 'border-gray-300',
    };
}

/**
 * Prüft ob ein Login-Versuch aufgrund von Brute-Force-Schutz gesperrt ist.
 *
 * @param PDO    $db       Datenbankverbindung
 * @param string $ip       IP-Adresse
 * @param string $username Benutzername
 * @return bool True wenn gesperrt
 */
function isLoginLocked(PDO $db, string $ip, string $username): bool
{
    $stmt = $db->prepare(
        'SELECT COUNT(*) FROM login_attempts
         WHERE ip_address = ? AND username = ?
         AND attempted_at > DATE_SUB(NOW(), INTERVAL ? MINUTE)'
    );
    $stmt->execute([$ip, $username, LOGIN_LOCKOUT_MINUTES]);

    return $stmt->fetchColumn() >= MAX_LOGIN_ATTEMPTS;
}

/**
 * Registriert einen fehlgeschlagenen Login-Versuch.
 *
 * @param PDO    $db       Datenbankverbindung
 * @param string $ip       IP-Adresse
 * @param string $username Benutzername
 */
function recordFailedLogin(PDO $db, string $ip, string $username): void
{
    $stmt = $db->prepare('INSERT INTO login_attempts (ip_address, username) VALUES (?, ?)');
    $stmt->execute([$ip, $username]);
}

/**
 * Löscht Login-Versuche nach erfolgreichem Login.
 *
 * @param PDO    $db       Datenbankverbindung
 * @param string $ip       IP-Adresse
 * @param string $username Benutzername
 */
function clearLoginAttempts(PDO $db, string $ip, string $username): void
{
    $stmt = $db->prepare('DELETE FROM login_attempts WHERE ip_address = ? AND username = ?');
    $stmt->execute([$ip, $username]);
}
