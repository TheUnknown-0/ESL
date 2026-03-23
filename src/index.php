<?php
/**
 * Zentraler Router
 * Lädt Seiten dynamisch aus der pages-Tabelle und prüft Berechtigungen.
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/csrf.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/auth.php';

// Session starten
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Angeforderte Seite ermitteln
$page = $_GET['page'] ?? '';

// Login-Seite ohne Authentifizierung zugänglich
if ($page === 'login' || $page === '') {
    require __DIR__ . '/pages/login.php';
    exit;
}

// Alle anderen Seiten erfordern Login
requireLogin();

// Nutzerpräferenzen (Theme/Style) in Session laden, falls noch nicht vorhanden
if (!isset($_SESSION['theme'])) {
    try {
        $db = getDB();
        $stmt = $db->prepare('SELECT theme, style FROM users WHERE id = ?');
        $stmt->execute([$_SESSION['user_id']]);
        $prefs = $stmt->fetch();
        $_SESSION['theme'] = $prefs['theme'] ?? 'light';
        $_SESSION['style'] = $prefs['style'] ?? 'default';
    } catch (Exception $e) {
        $_SESSION['theme'] = 'light';
        $_SESSION['style'] = 'default';
    }
}

// Navigationsseite
if ($page === 'nav') {
    require __DIR__ . '/pages/nav.php';
    exit;
}

// Logout-Aktion
if ($page === 'logout') {
    session_unset();
    session_destroy();
    header('Location: index.php?page=login');
    exit;
}

// Einstellungsseite
if ($page === 'einstellungen') {
    require __DIR__ . '/pages/einstellungen.php';
    exit;
}

// API-Endpunkte
if ($page === 'api-projects') {
    require __DIR__ . '/api/projects.php';
    exit;
}

// Verfügbare Seiten aus der Datenbank laden
try {
    $db = getDB();
    $stmt = $db->prepare('SELECT slug, label, requires_admin FROM pages WHERE active = 1 AND slug = ?');
    $stmt->execute([$page]);
    $pageData = $stmt->fetch();
} catch (Exception $e) {
    error_log('Router-Fehler: ' . $e->getMessage());
    http_response_code(500);
    $detail = (!empty($_SESSION['is_admin']) && $_SESSION['is_admin'] == 1)
        ? ' [Debug: ' . htmlspecialchars(get_class($e) . ': ' . $e->getMessage()
            . ' (' . basename($e->getFile()) . ':' . $e->getLine() . ')', ENT_QUOTES, 'UTF-8') . ']'
        : '';
    echo 'Ein interner Fehler ist aufgetreten.' . $detail;
    exit;
}

// Seite nicht gefunden
if (!$pageData) {
    http_response_code(404);
    echo '<!DOCTYPE html><html><head><title>404 Nicht gefunden</title></head><body>';
    echo '<h1>404 – Seite nicht gefunden</h1>';
    echo '<a href="index.php?page=nav">Zurück zur Navigation</a>';
    echo '</body></html>';
    exit;
}

// Admin-Berechtigung prüfen
if ($pageData['requires_admin']) {
    requireAdmin();
}

// Seitendatei laden
$filePath = __DIR__ . '/pages/' . basename($pageData['slug']) . '.php';

if (file_exists($filePath)) {
    require $filePath;
} else {
    http_response_code(404);
    echo '<!DOCTYPE html><html><head><title>Seite nicht verfügbar</title></head><body>';
    echo '<h1>Seite nicht verfügbar</h1>';
    echo '<p>Die Seitendatei wurde nicht gefunden.</p>';
    echo '<a href="index.php?page=nav">Zurück zur Navigation</a>';
    echo '</body></html>';
}
