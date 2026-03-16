<?php
/**
 * Authentifizierungs-Hilfsfunktion
 * Prüft ob der Benutzer eingeloggt ist und leitet ggf. zum Login um.
 */

/**
 * Prüft ob eine gültige Session existiert.
 * Leitet zum Login um, falls nicht eingeloggt.
 */
function requireLogin(): void
{
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }

    if (empty($_SESSION['user_id'])) {
        header('Location: index.php?page=login');
        exit;
    }
}

/**
 * Prüft ob der eingeloggte Benutzer Admin-Rechte hat.
 * Gibt 403 zurück, falls keine Admin-Rechte vorhanden.
 */
function requireAdmin(): void
{
    requireLogin();

    if (empty($_SESSION['is_admin']) || $_SESSION['is_admin'] != 1) {
        http_response_code(403);
        echo '<!DOCTYPE html><html><head><title>403 Verboten</title></head><body>';
        echo '<h1>403 – Zugriff verweigert</h1>';
        echo '<p>Sie haben keine Berechtigung, diese Seite aufzurufen.</p>';
        echo '<a href="index.php?page=nav">Zurück zur Navigation</a>';
        echo '</body></html>';
        exit;
    }
}

/**
 * Prüft ob der Benutzer eingeloggt ist (ohne Redirect).
 *
 * @return bool True wenn eingeloggt
 */
function isLoggedIn(): bool
{
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }

    return !empty($_SESSION['user_id']);
}

/**
 * Prüft ob der eingeloggte Benutzer ein Admin ist.
 *
 * @return bool True wenn Admin
 */
function isAdmin(): bool
{
    return isLoggedIn() && !empty($_SESSION['is_admin']) && $_SESSION['is_admin'] == 1;
}
