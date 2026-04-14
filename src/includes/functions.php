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

/**
 * Gibt eine Fehlermeldung zurück, die für Admins technische Debug-Details enthält.
 * Reguläre Benutzer sehen nur die generische Meldung.
 *
 * @param string    $genericMessage Fehlermeldung für alle Benutzer
 * @param Throwable $e              Geworfene Ausnahme
 * @return string Fehlermeldung (mit Debug-Details für Admins)
 */
function appendAdminError(string $genericMessage, Throwable $e): string
{
    if (empty($_SESSION['is_admin']) || $_SESSION['is_admin'] != 1) {
        return $genericMessage;
    }
    return $genericMessage . ' [Debug: ' . get_class($e) . ': ' . $e->getMessage()
        . ' (' . basename($e->getFile()) . ':' . $e->getLine() . ')]';
}

/**
 * Liefert die globalen Feature-Einstellungen (Kommentare & Upvotes) aus der
 * settings-Tabelle, mit sicheren Defaults.
 *
 * @return array{
 *   comments_enabled: bool,
 *   comments_permission: string,
 *   upvotes_enabled: bool,
 *   upvotes_permission: string
 * }
 */
function getFeatureSettings(PDO $db): array
{
    $defaults = [
        'comments_enabled'    => true,
        'comments_permission' => 'all',
        'upvotes_enabled'     => true,
        'upvotes_permission'  => 'all',
    ];

    try {
        $stmt = $db->query(
            "SELECT setting_key, setting_value FROM settings
             WHERE setting_key IN ('comments_enabled','comments_permission','upvotes_enabled','upvotes_permission')"
        );
        $rows = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);
    } catch (Exception $e) {
        return $defaults;
    }

    $permNormalize = fn($v) => in_array($v, ['all', 'admin'], true) ? $v : 'all';

    return [
        'comments_enabled'    => isset($rows['comments_enabled'])    ? $rows['comments_enabled']    === '1' : $defaults['comments_enabled'],
        'comments_permission' => isset($rows['comments_permission']) ? $permNormalize($rows['comments_permission']) : $defaults['comments_permission'],
        'upvotes_enabled'     => isset($rows['upvotes_enabled'])     ? $rows['upvotes_enabled']     === '1' : $defaults['upvotes_enabled'],
        'upvotes_permission'  => isset($rows['upvotes_permission'])  ? $permNormalize($rows['upvotes_permission'])  : $defaults['upvotes_permission'],
    ];
}

/**
 * Wendet Projekt-spezifische Overrides auf die globalen Einstellungen an.
 * Regel: Abgelehnte Projekte können nicht upgevotet werden.
 *
 * @param array $project         Projektzeile (Spalten: status, comments_enabled, comments_permission, upvotes_enabled, upvotes_permission)
 * @param array $globalSettings  Rückgabe von getFeatureSettings()
 */
function resolveProjectFeatures(array $project, array $globalSettings): array
{
    $result = $globalSettings;

    if (array_key_exists('comments_enabled', $project) && $project['comments_enabled'] !== null) {
        $result['comments_enabled'] = (int)$project['comments_enabled'] === 1;
    }
    if (!empty($project['comments_permission'])) {
        $result['comments_permission'] = $project['comments_permission'] === 'admin' ? 'admin' : 'all';
    }
    if (array_key_exists('upvotes_enabled', $project) && $project['upvotes_enabled'] !== null) {
        $result['upvotes_enabled'] = (int)$project['upvotes_enabled'] === 1;
    }
    if (!empty($project['upvotes_permission'])) {
        $result['upvotes_permission'] = $project['upvotes_permission'] === 'admin' ? 'admin' : 'all';
    }

    // Harte Regel: abgelehnte Projekte erlauben keine Upvotes
    if (($project['status'] ?? '') === 'Abgelehnt') {
        $result['upvotes_enabled'] = false;
    }

    return $result;
}

/**
 * Prüft, ob der aktuelle Nutzer kommentieren darf.
 */
function canComment(array $features, bool $isAdmin): bool
{
    if (!$features['comments_enabled']) return false;
    if ($features['comments_permission'] === 'admin' && !$isAdmin) return false;
    return true;
}

/**
 * Prüft, ob der aktuelle Nutzer upvoten darf.
 */
function canUpvote(array $features, bool $isAdmin): bool
{
    if (!$features['upvotes_enabled']) return false;
    if ($features['upvotes_permission'] === 'admin' && !$isAdmin) return false;
    return true;
}
