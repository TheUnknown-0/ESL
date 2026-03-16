<?php
/**
 * CSRF-Schutz
 * Generiert und prüft CSRF-Tokens für alle POST-Formulare.
 */

/**
 * Generiert ein CSRF-Token und speichert es in der Session.
 *
 * @return string Das generierte Token
 */
function generateCsrfToken(): string
{
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }

    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }

    return $_SESSION['csrf_token'];
}

/**
 * Gibt ein verstecktes HTML-Input-Feld mit dem CSRF-Token zurück.
 *
 * @return string HTML-Input-Element
 */
function csrfField(): string
{
    $token = htmlspecialchars(generateCsrfToken(), ENT_QUOTES, 'UTF-8');
    return '<input type="hidden" name="csrf_token" value="' . $token . '">';
}

/**
 * Prüft ob das übermittelte CSRF-Token gültig ist.
 *
 * @return bool True wenn gültig
 */
function validateCsrfToken(): bool
{
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }

    if (empty($_POST['csrf_token']) || empty($_SESSION['csrf_token'])) {
        return false;
    }

    $valid = hash_equals($_SESSION['csrf_token'], $_POST['csrf_token']);

    return $valid;
}
