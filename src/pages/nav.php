<?php
/**
 * Navigationsseite
 * Zeigt alle aktiven Seiten als Kacheln/Buttons an.
 * Admin-Seiten werden nur für Admins angezeigt.
 */

require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../auth.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

requireLogin();

// Aktive Seiten aus der Datenbank laden
try {
    $db = getDB();
    $stmt = $db->query('SELECT slug, label, icon, requires_admin FROM pages WHERE active = 1 ORDER BY sort_order ASC');
    $pages = $stmt->fetchAll();
} catch (Exception $e) {
    error_log('Navigation-Fehler: ' . $e->getMessage());
    $pages = [];
}

// Seiten nach Admin-Berechtigung filtern
$visiblePages = array_filter($pages, function ($p) {
    return !$p['requires_admin'] || isAdmin();
});
?>
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Navigation – Schwarzes Brett</title>
    <script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="bg-gray-100 min-h-screen">
    <div class="max-w-4xl mx-auto py-12 px-4">
        <div class="flex justify-between items-center mb-8">
            <h1 class="text-3xl font-bold text-gray-800">Navigation</h1>
            <div class="flex items-center gap-4">
                <span class="text-gray-600">Eingeloggt als: <strong><?= e($_SESSION['username'] ?? '') ?></strong></span>
                <a href="index.php?page=logout"
                   class="bg-red-500 text-white px-4 py-2 rounded-md hover:bg-red-600 text-sm font-bold">
                    Abmelden
                </a>
            </div>
        </div>

        <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6">
            <?php foreach ($visiblePages as $p): ?>
                <a href="index.php?page=<?= e($p['slug']) ?>"
                   class="block bg-white rounded-lg shadow-md p-6 hover:shadow-lg transition-shadow duration-200 text-center">
                    <?php if ($p['icon']): ?>
                        <div class="text-4xl mb-3"><?= e($p['icon']) ?></div>
                    <?php endif; ?>
                    <h2 class="text-xl font-semibold text-gray-800"><?= e($p['label']) ?></h2>
                    <?php if ($p['requires_admin']): ?>
                        <span class="inline-block mt-2 text-xs bg-yellow-100 text-yellow-800 px-2 py-1 rounded">Admin</span>
                    <?php endif; ?>
                </a>
            <?php endforeach; ?>
        </div>
    </div>
</body>
</html>
