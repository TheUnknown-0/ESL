<?php
/**
 * Einstellungen
 * Nutzer können ihr Erscheinungsbild (Theme, Style) und
 * E-Mail-Benachrichtigungen selbst konfigurieren.
 */

require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../auth.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

requireLogin();

require_once __DIR__ . '/../includes/theme.php';

$db = getDB();
$success = '';
$error = '';

// Auto-Migration: Spalten anlegen falls DB-Schema veraltet ist
try {
    $check = $db->query("SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users' AND COLUMN_NAME = 'theme'");
    if ((int)$check->fetchColumn() === 0) {
        $db->exec("ALTER TABLE users ADD COLUMN theme ENUM('light','dark') DEFAULT 'light'");
        $db->exec("ALTER TABLE users ADD COLUMN style ENUM('default','anthropic') DEFAULT 'default'");
    }
    $db->exec("INSERT IGNORE INTO pages (slug, label, icon, requires_admin, sort_order) VALUES ('einstellungen', 'Einstellungen', NULL, 0, 4)");
} catch (Exception $e) {
    error_log('Einstellungen Migration Fehler: ' . $e->getMessage());
}

// ============================================================
// POST: Einstellungen speichern
// ============================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!validateCsrfToken()) {
        $error = 'Ungültige Anfrage. Bitte versuchen Sie es erneut.';
    } else {
        $action = $_POST['action'] ?? '';

        // --- Erscheinungsbild speichern ---
        if ($action === 'save_appearance') {
            $theme = $_POST['theme'] ?? 'light';
            $style = $_POST['style'] ?? 'default';

            if (!in_array($theme, ['light', 'dark'])) $theme = 'light';
            if (!in_array($style, ['default', 'anthropic'])) $style = 'default';

            try {
                $stmt = $db->prepare('UPDATE users SET theme = ?, style = ? WHERE id = ?');
                $stmt->execute([$theme, $style, $_SESSION['user_id']]);

                $_SESSION['theme'] = $theme;
                $_SESSION['style'] = $style;

                // Aktualisierte Klassen für sofortige Anzeige
                $_parts = [];
                if ($theme === 'dark') $_parts[] = 'dark';
                if ($style === 'anthropic') $_parts[] = 'style-anthropic';
                $themeHtmlClasses = implode(' ', $_parts);

                $success = 'Erscheinungsbild gespeichert.';
            } catch (Exception $e) {
                error_log('Einstellungen-Fehler: ' . $e->getMessage());
                $error = appendAdminError('Fehler beim Speichern. Bitte versuchen Sie es erneut.', $e);
            }
        }

        // --- E-Mail-Benachrichtigungen speichern ---
        if ($action === 'save_email') {
            $emailNotifications = isset($_POST['email_notifications']) ? 1 : 0;

            try {
                $stmt = $db->prepare('UPDATE users SET email_notifications = ? WHERE id = ?');
                $stmt->execute([$emailNotifications, $_SESSION['user_id']]);
                $success = 'E-Mail-Einstellungen gespeichert.';
            } catch (Exception $e) {
                error_log('Einstellungen-Fehler: ' . $e->getMessage());
                $error = appendAdminError('Fehler beim Speichern. Bitte versuchen Sie es erneut.', $e);
            }
        }
    }
}

// ============================================================
// Aktuelle Einstellungen laden
// ============================================================
try {
    $stmt = $db->prepare('SELECT theme, style, email_notifications, email FROM users WHERE id = ?');
    $stmt->execute([$_SESSION['user_id']]);
    $userPrefs = $stmt->fetch();
} catch (Exception $e) {
    error_log('Einstellungen laden Fehler: ' . $e->getMessage());
    $userPrefs = [];
}

$currentTheme = $userPrefs['theme'] ?? 'light';
$currentStyle = $userPrefs['style'] ?? 'default';
$currentEmailNotif = $userPrefs['email_notifications'] ?? 1;
$currentEmail = $userPrefs['email'] ?? '';

// Aktiv-Tab aus URL (nach POST-Redirect wäre schöner, hier zur Vereinfachung per GET)
$activeTab = $_GET['tab'] ?? 'appearance';
if (!in_array($activeTab, ['appearance', 'email'])) $activeTab = 'appearance';
?>
<!DOCTYPE html>
<html lang="de" class="<?= e($themeHtmlClasses) ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Einstellungen</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <?php outputThemeHead(); ?>
</head>
<body class="bg-gray-100 min-h-screen">
    <!-- Kopfzeile -->
    <header class="bg-white shadow">
        <div class="max-w-4xl mx-auto px-4 py-3 flex flex-wrap justify-between items-center gap-2">
            <h1 class="text-xl sm:text-2xl font-bold text-gray-800">Einstellungen</h1>
            <div class="flex flex-wrap gap-2">
                <a href="index.php?page=nav" class="bg-gray-200 text-gray-700 px-3 py-2 rounded-md hover:bg-gray-300 text-sm">← Navigation</a>
                <a href="index.php?page=logout" class="bg-red-500 text-white px-3 py-2 rounded-md hover:bg-red-600 text-sm">Abmelden</a>
            </div>
        </div>
    </header>

    <main class="max-w-4xl mx-auto px-4 py-8">
        <?php if ($success): ?>
            <div class="bg-green-100 border border-green-400 text-green-700 px-4 py-3 rounded mb-6">
                <?= e($success) ?>
            </div>
        <?php endif; ?>

        <?php if ($error): ?>
            <div class="bg-red-100 border border-red-400 text-red-700 px-4 py-3 rounded mb-6">
                <?= e($error) ?>
            </div>
        <?php endif; ?>

        <!-- Tab-Navigation -->
        <div class="mb-6">
            <nav class="flex space-x-2 border-b border-gray-200">
                <button onclick="showTab('appearance')" id="tab-appearance"
                        class="px-5 py-2 -mb-px text-sm font-semibold border-b-2 transition-colors <?= $activeTab === 'appearance' ? 'border-blue-600 text-blue-600' : 'border-transparent text-gray-600 hover:text-gray-800' ?>">
                    🎨 Erscheinungsbild
                </button>
                <button onclick="showTab('email')" id="tab-email"
                        class="px-5 py-2 -mb-px text-sm font-semibold border-b-2 transition-colors <?= $activeTab === 'email' ? 'border-blue-600 text-blue-600' : 'border-transparent text-gray-600 hover:text-gray-800' ?>">
                    📧 E-Mail
                </button>
            </nav>
        </div>

        <!-- ============================================================ -->
        <!-- Tab: Erscheinungsbild -->
        <!-- ============================================================ -->
        <div id="panel-appearance" class="<?= $activeTab !== 'appearance' ? 'hidden' : '' ?>">
            <form method="POST" action="index.php?page=einstellungen">
                <?= csrfField() ?>
                <input type="hidden" name="action" value="save_appearance">

                <!-- Farbmodus -->
                <div class="bg-white rounded-lg shadow-md p-6 mb-6">
                    <h2 class="text-lg font-bold text-gray-800 mb-1">Farbmodus</h2>
                    <p class="text-gray-500 text-sm mb-4">Wählen Sie zwischen hellem und dunklem Design.</p>

                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                        <!-- Light Mode -->
                        <label class="cursor-pointer">
                            <input type="radio" name="theme" value="light" class="sr-only peer"
                                   <?= $currentTheme === 'light' ? 'checked' : '' ?>>
                            <div class="border-2 rounded-xl p-4 transition-all peer-checked:border-blue-500 peer-checked:ring-2 peer-checked:ring-blue-200 border-gray-200 hover:border-gray-300 bg-white">
                                <div class="flex items-center gap-3">
                                    <div class="w-10 h-10 rounded-lg bg-gray-100 border border-gray-200 flex items-center justify-center text-xl">☀️</div>
                                    <div>
                                        <div class="font-semibold text-gray-800">Hell</div>
                                        <div class="text-xs text-gray-500">Helles Design (Standard)</div>
                                    </div>
                                </div>
                                <!-- Preview -->
                                <div class="mt-3 rounded-lg overflow-hidden border border-gray-100">
                                    <div class="h-2 bg-gray-100"></div>
                                    <div class="bg-white p-2 flex gap-1">
                                        <div class="h-2 w-16 bg-gray-300 rounded"></div>
                                        <div class="h-2 w-10 bg-gray-200 rounded"></div>
                                    </div>
                                    <div class="bg-gray-50 p-2">
                                        <div class="h-1.5 w-full bg-gray-200 rounded mb-1"></div>
                                        <div class="h-1.5 w-3/4 bg-gray-200 rounded"></div>
                                    </div>
                                </div>
                            </div>
                        </label>

                        <!-- Dark Mode -->
                        <label class="cursor-pointer">
                            <input type="radio" name="theme" value="dark" class="sr-only peer"
                                   <?= $currentTheme === 'dark' ? 'checked' : '' ?>>
                            <div class="border-2 rounded-xl p-4 transition-all peer-checked:border-blue-500 peer-checked:ring-2 peer-checked:ring-blue-200 border-gray-200 hover:border-gray-300 bg-white">
                                <div class="flex items-center gap-3">
                                    <div class="w-10 h-10 rounded-lg bg-slate-800 border border-slate-700 flex items-center justify-center text-xl">🌙</div>
                                    <div>
                                        <div class="font-semibold text-gray-800">Dunkel</div>
                                        <div class="text-xs text-gray-500">Augenschonendes dunkles Design</div>
                                    </div>
                                </div>
                                <!-- Preview -->
                                <div class="mt-3 rounded-lg overflow-hidden border border-slate-700">
                                    <div class="h-2 bg-slate-900"></div>
                                    <div class="bg-slate-800 p-2 flex gap-1">
                                        <div class="h-2 w-16 bg-slate-500 rounded"></div>
                                        <div class="h-2 w-10 bg-slate-600 rounded"></div>
                                    </div>
                                    <div class="bg-slate-900 p-2">
                                        <div class="h-1.5 w-full bg-slate-700 rounded mb-1"></div>
                                        <div class="h-1.5 w-3/4 bg-slate-700 rounded"></div>
                                    </div>
                                </div>
                            </div>
                        </label>
                    </div>
                </div>

                <!-- Stil -->
                <div class="bg-white rounded-lg shadow-md p-6 mb-6">
                    <h2 class="text-lg font-bold text-gray-800 mb-1">Stil</h2>
                    <p class="text-gray-500 text-sm mb-4">Wählen Sie die visuelle Gestaltung der Oberfläche.</p>

                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                        <!-- Standard -->
                        <label class="cursor-pointer">
                            <input type="radio" name="style" value="default" class="sr-only peer"
                                   <?= $currentStyle === 'default' ? 'checked' : '' ?>>
                            <div class="border-2 rounded-xl p-4 transition-all peer-checked:border-blue-500 peer-checked:ring-2 peer-checked:ring-blue-200 border-gray-200 hover:border-gray-300 bg-white">
                                <div class="flex items-center gap-3 mb-3">
                                    <div class="w-10 h-10 rounded-lg bg-blue-600 flex items-center justify-center text-white font-bold text-sm">S</div>
                                    <div>
                                        <div class="font-semibold text-gray-800">Standard</div>
                                        <div class="text-xs text-gray-500">Modernes, klares Design</div>
                                    </div>
                                </div>
                                <div class="flex gap-2">
                                    <span class="inline-block w-6 h-6 rounded-full bg-blue-600"></span>
                                    <span class="inline-block w-6 h-6 rounded-full bg-gray-100 border border-gray-200"></span>
                                    <span class="inline-block w-6 h-6 rounded-full bg-green-600"></span>
                                    <span class="inline-block w-6 h-6 rounded-full bg-red-500"></span>
                                </div>
                            </div>
                        </label>

                        <!-- Anthropic -->
                        <label class="cursor-pointer">
                            <input type="radio" name="style" value="anthropic" class="sr-only peer"
                                   <?= $currentStyle === 'anthropic' ? 'checked' : '' ?>>
                            <div class="border-2 rounded-xl p-4 transition-all peer-checked:border-blue-500 peer-checked:ring-2 peer-checked:ring-blue-200 border-gray-200 hover:border-gray-300 bg-white">
                                <div class="flex items-center gap-3 mb-3">
                                    <div class="w-10 h-10 rounded-lg flex items-center justify-center text-white font-bold text-sm" style="background-color:#D97757">A</div>
                                    <div>
                                        <div class="font-semibold text-gray-800">Anthropic</div>
                                        <div class="text-xs text-gray-500">Warmes, elegantes Design</div>
                                    </div>
                                </div>
                                <!-- Preview -->
                                <div class="mt-3 rounded-lg overflow-hidden" style="border:1px solid #E5E0D8">
                                    <div class="h-2" style="background:#FAF9F6"></div>
                                    <div class="p-2 flex gap-1" style="background:#FFFFFF">
                                        <div class="h-2 w-16 rounded" style="background:#D97757"></div>
                                        <div class="h-2 w-10 rounded" style="background:#E5E0D8"></div>
                                    </div>
                                    <div class="p-2" style="background:#F0EDE8">
                                        <div class="h-1.5 w-full rounded mb-1" style="background:#E5E0D8"></div>
                                        <div class="h-1.5 w-3/4 rounded" style="background:#E5E0D8"></div>
                                    </div>
                                </div>
                                <div class="flex gap-2 mt-3">
                                    <span class="inline-block w-6 h-6 rounded-full" style="background:#D97757"></span>
                                    <span class="inline-block w-6 h-6 rounded-full" style="background:#FAF9F6;border:1px solid #E5E0D8"></span>
                                    <span class="inline-block w-6 h-6 rounded-full" style="background:#2E7D5B"></span>
                                    <span class="inline-block w-6 h-6 rounded-full" style="background:#1A1A1A"></span>
                                </div>
                            </div>
                        </label>
                    </div>
                </div>

                <button type="submit"
                        class="bg-blue-600 text-white px-6 py-2 rounded-md hover:bg-blue-700 font-bold">
                    Erscheinungsbild speichern
                </button>
            </form>
        </div>

        <!-- ============================================================ -->
        <!-- Tab: E-Mail -->
        <!-- ============================================================ -->
        <div id="panel-email" class="<?= $activeTab !== 'email' ? 'hidden' : '' ?>">
            <div class="bg-white rounded-lg shadow-md p-6 mb-6">
                <h2 class="text-lg font-bold text-gray-800 mb-1">E-Mail-Konto</h2>
                <p class="text-gray-500 text-sm mb-3">Ihre hinterlegte E-Mail-Adresse.</p>
                <div class="flex items-center gap-3 p-3 bg-gray-50 rounded-lg border border-gray-200">
                    <span class="text-gray-500">📧</span>
                    <span class="font-medium text-gray-700"><?= e($currentEmail ?: '—') ?></span>
                </div>
                <p class="text-xs text-gray-400 mt-2">Die E-Mail-Adresse kann nur von einem Administrator geändert werden.</p>
            </div>

            <div class="bg-white rounded-lg shadow-md p-6">
                <h2 class="text-lg font-bold text-gray-800 mb-1">Benachrichtigungen</h2>
                <p class="text-gray-500 text-sm mb-5">Legen Sie fest, wann Sie per E-Mail benachrichtigt werden möchten.</p>

                <form method="POST" action="index.php?page=einstellungen">
                    <?= csrfField() ?>
                    <input type="hidden" name="action" value="save_email">

                    <div class="space-y-4 mb-6">
                        <label class="flex items-start gap-4 cursor-pointer group">
                            <div class="relative mt-1">
                                <input type="checkbox" name="email_notifications" value="1"
                                       id="email_notifications"
                                       class="sr-only peer"
                                       <?= $currentEmailNotif ? 'checked' : '' ?>>
                                <div class="w-11 h-6 bg-gray-200 rounded-full peer-checked:bg-blue-600 transition-colors"></div>
                                <div class="absolute left-0.5 top-0.5 w-5 h-5 bg-white rounded-full shadow transition-transform peer-checked:translate-x-5"></div>
                            </div>
                            <div>
                                <div class="font-semibold text-gray-800">E-Mail-Benachrichtigungen aktivieren</div>
                                <div class="text-sm text-gray-500">
                                    Sie erhalten eine E-Mail, wenn ein Ihrer Vorschläge angenommen oder abgelehnt wird.
                                </div>
                            </div>
                        </label>
                    </div>

                    <div class="p-4 bg-gray-50 rounded-lg border border-gray-200 text-sm text-gray-600 mb-5">
                        <strong class="text-gray-700">Aktueller Status:</strong>
                        <?php if ($currentEmailNotif): ?>
                            <span class="text-green-600 font-semibold">Benachrichtigungen aktiv</span>
                        <?php else: ?>
                            <span class="text-gray-400 font-semibold">Benachrichtigungen deaktiviert</span>
                        <?php endif; ?>
                    </div>

                    <button type="submit"
                            class="bg-blue-600 text-white px-6 py-2 rounded-md hover:bg-blue-700 font-bold">
                        E-Mail-Einstellungen speichern
                    </button>
                </form>
            </div>
        </div>
    </main>

    <script>
        const tabIds = ['appearance', 'email'];

        function showTab(tab) {
            tabIds.forEach(function(t) {
                const panel = document.getElementById('panel-' + t);
                const btn   = document.getElementById('tab-' + t);
                if (t === tab) {
                    panel.classList.remove('hidden');
                    btn.classList.add('border-blue-600', 'text-blue-600');
                    btn.classList.remove('border-transparent', 'text-gray-600');
                } else {
                    panel.classList.add('hidden');
                    btn.classList.remove('border-blue-600', 'text-blue-600');
                    btn.classList.add('border-transparent', 'text-gray-600');
                }
            });
        }

        // Toggle-Checkbox visuell verknüpfen
        document.getElementById('email_notifications').addEventListener('change', function() {
            // Der Toggle wird durch CSS peer-Klassen gesteuert, kein JS nötig
        });
    </script>
</body>
</html>
