<?php
/**
 * Verwaltungsseite (nur für Admins)
 * Tabs: Projektverwaltung, Nutzerverwaltung, Systemeinstellungen.
 */

require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/mailer.php';
require_once __DIR__ . '/../auth.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

requireAdmin();

require_once __DIR__ . '/../includes/theme.php';

$db = getDB();
$success = '';
$error = '';

// Sicherstellen, dass die settings-Tabelle existiert
try {
    $db->exec("CREATE TABLE IF NOT EXISTS settings (
        id INT AUTO_INCREMENT PRIMARY KEY,
        setting_key VARCHAR(100) NOT NULL UNIQUE,
        setting_value TEXT DEFAULT NULL,
        updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    $db->exec("INSERT IGNORE INTO settings (setting_key, setting_value) VALUES ('mail_disabled', '0')");
} catch (Exception $e) {
    error_log('Settings-Tabelle: ' . $e->getMessage());
}

// ============================================================
// POST-Anfragen verarbeiten
// ============================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!validateCsrfToken()) {
        $error = 'Ungültige Anfrage. Bitte versuchen Sie es erneut.';
    } else {
        $action = $_POST['action'] ?? '';

        // --- Projekt bearbeiten ---
        if ($action === 'edit_project') {
            $projectId    = (int)($_POST['project_id'] ?? 0);
            $name         = trim($_POST['name'] ?? '');
            $description  = trim($_POST['description'] ?? '');
            $reason       = trim($_POST['reason'] ?? '');
            $status       = $_POST['status'] ?? '';
            $decisionReason = trim($_POST['decision_reason'] ?? '');

            // Feature-Overrides: "" = Standard (NULL), "1"/"0" für Toggles, "all"/"admin" für Permission
            $toggleOverride = function($v): ?int {
                if ($v === '1') return 1;
                if ($v === '0') return 0;
                return null;
            };
            $permOverride = function($v): ?string {
                if ($v === 'all')   return 'all';
                if ($v === 'admin') return 'admin';
                return null;
            };
            $commentsEnabledOv    = $toggleOverride($_POST['comments_enabled_override']    ?? '');
            $commentsPermissionOv = $permOverride ($_POST['comments_permission_override'] ?? '');
            $upvotesEnabledOv     = $toggleOverride($_POST['upvotes_enabled_override']     ?? '');
            $upvotesPermissionOv  = $permOverride ($_POST['upvotes_permission_override']  ?? '');

            $validStatuses = ['Vorgeschlagen', 'In Besprechung', 'In Bearbeitung', 'Angenommen', 'Abgelehnt'];
            if ($name === '' || $description === '' || $reason === '' || !in_array($status, $validStatuses)) {
                $error = 'Bitte füllen Sie alle Pflichtfelder aus.';
            } elseif ($status === 'Abgelehnt' && $decisionReason === '') {
                $error = 'Bei Ablehnung ist eine Begründung erforderlich.';
            } else {
                try {
                    $stmtOld = $db->prepare('SELECT status, proposed_by, is_anonymous FROM projects WHERE id = ?');
                    $stmtOld->execute([$projectId]);
                    $oldProject = $stmtOld->fetch();

                    $decisionReasonValue = in_array($status, ['Angenommen', 'Abgelehnt']) ? $decisionReason : null;
                    $decidedByValue      = in_array($status, ['Angenommen', 'Abgelehnt']) ? $_SESSION['user_id'] : null;

                    $stmt = $db->prepare(
                        'UPDATE projects SET name = ?, description = ?, reason = ?, status = ?,
                         decision_reason = ?, decided_by = ?,
                         comments_enabled = ?, comments_permission = ?,
                         upvotes_enabled = ?, upvotes_permission = ?,
                         updated_at = NOW() WHERE id = ?'
                    );
                    $stmt->execute([
                        $name, $description, $reason, $status,
                        $decisionReasonValue, $decidedByValue,
                        $commentsEnabledOv, $commentsPermissionOv,
                        $upvotesEnabledOv,  $upvotesPermissionOv,
                        $projectId,
                    ]);
                    $success = 'Projekt erfolgreich aktualisiert.';

                    if ($oldProject && in_array($status, ['Angenommen', 'Abgelehnt'])
                        && $oldProject['status'] !== $status
                        && !$oldProject['is_anonymous']
                        && $oldProject['proposed_by']) {
                        notifyProposerDecision((int)$oldProject['proposed_by'], $name, $status, $decisionReason);
                    }
                } catch (Exception $e) {
                    error_log('Projekt-Update-Fehler: ' . $e->getMessage());
                    $error = appendAdminError('Fehler beim Aktualisieren des Projekts.', $e);
                }
            }
        }

        // --- Projekt löschen ---
        if ($action === 'delete_project') {
            $projectId = (int)($_POST['project_id'] ?? 0);
            if ($projectId > 0) {
                try {
                    $stmt = $db->prepare('DELETE FROM projects WHERE id = ?');
                    $stmt->execute([$projectId]);
                    $success = 'Vorschlag erfolgreich gelöscht.';
                } catch (Exception $e) {
                    error_log('Projekt-Lösch-Fehler: ' . $e->getMessage());
                    $error = appendAdminError('Fehler beim Löschen des Vorschlags.', $e);
                }
            }
        }

        // --- Nutzer anlegen ---
        if ($action === 'create_user') {
            $username           = trim($_POST['username'] ?? '');
            $email              = trim($_POST['email'] ?? '');
            $password           = $_POST['password'] ?? '';
            $isAdmin            = isset($_POST['is_admin']) ? 1 : 0;
            $emailNotifications = isset($_POST['email_notifications']) ? 1 : 0;

            // E-Mail ist optional; ohne E-Mail keine Benachrichtigungen möglich
            $emailValue = $email === '' ? null : $email;
            if ($emailValue === null) {
                $emailNotifications = 0;
            }

            if ($username === '' || $password === '') {
                $error = 'Bitte füllen Sie alle Pflichtfelder aus.';
            } else {
                try {
                    $hash = password_hash($password, PASSWORD_BCRYPT);
                    $stmt = $db->prepare(
                        'INSERT INTO users (username, password_hash, email, is_admin, email_notifications)
                         VALUES (?, ?, ?, ?, ?)'
                    );
                    $stmt->execute([$username, $hash, $emailValue, $isAdmin, $emailNotifications]);
                    $success = 'Nutzer erfolgreich angelegt.';
                } catch (PDOException $e) {
                    if ($e->getCode() == '23000') {
                        $error = 'Benutzername existiert bereits.';
                    } else {
                        error_log('Nutzer-Fehler: ' . $e->getMessage());
                        $error = appendAdminError('Fehler beim Anlegen des Nutzers.', $e);
                    }
                }
            }
        }

        // --- Nutzer löschen ---
        if ($action === 'delete_user') {
            $userId = (int)($_POST['user_id'] ?? 0);
            if ($userId === (int)$_SESSION['user_id']) {
                $error = 'Sie können Ihren eigenen Account nicht löschen.';
            } elseif ($userId > 0) {
                try {
                    $stmt = $db->prepare('DELETE FROM users WHERE id = ?');
                    $stmt->execute([$userId]);
                    $success = 'Nutzer erfolgreich gelöscht.';
                } catch (Exception $e) {
                    error_log('Nutzer-Lösch-Fehler: ' . $e->getMessage());
                    $error = appendAdminError('Fehler beim Löschen des Nutzers.', $e);
                }
            }
        }

        // --- Passwort zurücksetzen ---
        if ($action === 'reset_password') {
            $userId      = (int)($_POST['user_id'] ?? 0);
            $newPassword = $_POST['new_password'] ?? '';

            if ($userId > 0 && $newPassword !== '') {
                try {
                    $hash = password_hash($newPassword, PASSWORD_BCRYPT);
                    $stmt = $db->prepare('UPDATE users SET password_hash = ? WHERE id = ?');
                    $stmt->execute([$hash, $userId]);
                    $success = 'Passwort erfolgreich zurückgesetzt.';
                } catch (Exception $e) {
                    error_log('Passwort-Reset-Fehler: ' . $e->getMessage());
                    $error = appendAdminError('Fehler beim Zurücksetzen des Passworts.', $e);
                }
            } else {
                $error = 'Bitte geben Sie ein neues Passwort ein.';
            }
        }

        // --- E-Mail bearbeiten (leer = entfernen) ---
        if ($action === 'update_email') {
            $userId   = (int)($_POST['user_id'] ?? 0);
            $newEmail = trim($_POST['new_email'] ?? '');
            $finalEmail = $newEmail === '' ? null : $newEmail;

            if ($userId > 0) {
                try {
                    // Ohne E-Mail werden Benachrichtigungen automatisch deaktiviert
                    if ($finalEmail === null) {
                        $stmt = $db->prepare('UPDATE users SET email = NULL, email_notifications = 0 WHERE id = ?');
                        $stmt->execute([$userId]);
                        $success = 'E-Mail-Adresse entfernt.';
                    } else {
                        $stmt = $db->prepare('UPDATE users SET email = ? WHERE id = ?');
                        $stmt->execute([$finalEmail, $userId]);
                        $success = 'E-Mail-Adresse erfolgreich aktualisiert.';
                    }
                } catch (Exception $e) {
                    error_log('E-Mail-Update-Fehler: ' . $e->getMessage());
                    $error = appendAdminError('Fehler beim Aktualisieren der E-Mail-Adresse.', $e);
                }
            } else {
                $error = 'Ungültige Benutzer-ID.';
            }
        }

        // --- Globale E-Mail-Sperre ---
        if ($action === 'update_mail_setting') {
            $disabled = isset($_POST['mail_disabled']) ? '1' : '0';
            try {
                $stmt = $db->prepare("INSERT INTO settings (setting_key, setting_value)
                                      VALUES ('mail_disabled', ?)
                                      ON DUPLICATE KEY UPDATE setting_value = ?");
                $stmt->execute([$disabled, $disabled]);
                $success = 'E-Mail-Einstellung gespeichert.';
            } catch (Exception $e) {
                error_log('Settings-Update-Fehler: ' . $e->getMessage());
                $error = appendAdminError('Fehler beim Speichern der Einstellung.', $e);
            }
        }

        // --- Feature-Einstellungen (Kommentare & Upvotes) ---
        if ($action === 'update_feature_settings') {
            $commentsEnabled    = isset($_POST['comments_enabled']) ? '1' : '0';
            $upvotesEnabled     = isset($_POST['upvotes_enabled'])  ? '1' : '0';
            $commentsPermission = ($_POST['comments_permission'] ?? 'all') === 'admin' ? 'admin' : 'all';
            $upvotesPermission  = ($_POST['upvotes_permission']  ?? 'all') === 'admin' ? 'admin' : 'all';
            try {
                $stmt = $db->prepare("INSERT INTO settings (setting_key, setting_value)
                                      VALUES (?, ?)
                                      ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)");
                $stmt->execute(['comments_enabled',    $commentsEnabled]);
                $stmt->execute(['comments_permission', $commentsPermission]);
                $stmt->execute(['upvotes_enabled',     $upvotesEnabled]);
                $stmt->execute(['upvotes_permission',  $upvotesPermission]);
                $success = 'Feature-Einstellungen gespeichert.';
            } catch (Exception $e) {
                error_log('Feature-Settings-Update-Fehler: ' . $e->getMessage());
                $error = appendAdminError('Fehler beim Speichern der Feature-Einstellungen.', $e);
            }
        }
    }
}

// ============================================================
// Daten laden
// ============================================================

try {
    $stmtProjects = $db->query(
        "SELECT p.*, u.username AS proposed_by_name,
                COALESCE(uv.upvote_count, 0) AS upvote_count,
                uv.upvoter_names
         FROM projects p
         LEFT JOIN users u ON p.proposed_by = u.id
         LEFT JOIN (
             SELECT pu.project_id,
                    COUNT(*) AS upvote_count,
                    GROUP_CONCAT(DISTINCT uu.username ORDER BY uu.username SEPARATOR ', ') AS upvoter_names
             FROM project_upvotes pu
             LEFT JOIN users uu ON uu.id = pu.user_id
             GROUP BY pu.project_id
         ) uv ON uv.project_id = p.id
         ORDER BY p.created_at DESC"
    );
    $projects = $stmtProjects->fetchAll();
} catch (Exception $e) {
    error_log('Verwaltung Projekte Fehler: ' . $e->getMessage());
    $projects = [];
    $error = appendAdminError('Fehler beim Laden der Projekte.', $e);
}

try {
    $stmtUsers = $db->query('SELECT * FROM users ORDER BY username ASC');
    $users = $stmtUsers->fetchAll();
} catch (Exception $e) {
    error_log('Verwaltung Nutzer Fehler: ' . $e->getMessage());
    $users = [];
    $error = appendAdminError('Fehler beim Laden der Nutzerliste.', $e);
}

// Globale Mail-Einstellung laden
try {
    $stmtSetting = $db->prepare("SELECT setting_value FROM settings WHERE setting_key = 'mail_disabled'");
    $stmtSetting->execute();
    $mailDisabled = $stmtSetting->fetchColumn() === '1';
} catch (Exception $e) {
    $mailDisabled = false;
}

// Globale Feature-Einstellungen (Kommentare & Upvotes) laden
$featureSettings = getFeatureSettings($db);

$validStatuses = ['Vorgeschlagen', 'In Besprechung', 'In Bearbeitung', 'Angenommen', 'Abgelehnt'];

// Priorisierbare Projekte für das Kanban-Board laden
$priorisierbar = ['Vorgeschlagen', 'In Besprechung', 'In Bearbeitung'];
$prioProjects = ['Unpriorisiert' => [], 'Hoch' => [], 'Mittel' => [], 'Niedrig' => []];
foreach ($projects as $p) {
    if (!in_array($p['status'], $priorisierbar)) continue;
    $col = $p['priority'] ?? null;
    if ($col === null || !isset($prioProjects[$col])) {
        $prioProjects['Unpriorisiert'][] = $p;
    } else {
        $prioProjects[$col][] = $p;
    }
}
?>
<!DOCTYPE html>
<html lang="de" class="<?= e($themeHtmlClasses) ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Verwaltung</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <script src="https://cdn.jsdelivr.net/npm/sortablejs@1.15.2/Sortable.min.js"></script>
    <?php outputThemeHead(); ?>
</head>
<body class="bg-gray-100 min-h-screen">
    <!-- Kopfzeile -->
    <header class="bg-white shadow">
        <div class="max-w-7xl mx-auto px-4 py-3 flex flex-wrap justify-between items-center gap-2">
            <h1 class="text-xl sm:text-2xl font-bold text-gray-800">Verwaltung</h1>
            <div class="flex flex-wrap gap-2">
                <a href="index.php?page=einstellungen" class="bg-gray-200 text-gray-700 px-3 py-2 rounded-md hover:bg-gray-300 text-sm">⚙️</a>
                <a href="index.php?page=nav" class="bg-gray-200 text-gray-700 px-3 py-2 rounded-md hover:bg-gray-300 text-sm">← Navigation</a>
                <a href="index.php?page=logout" class="bg-red-500 text-white px-3 py-2 rounded-md hover:bg-red-600 text-sm">Abmelden</a>
            </div>
        </div>
    </header>

    <main class="max-w-7xl mx-auto px-4 py-6">
        <?php if ($success): ?>
            <div class="bg-green-100 border border-green-400 text-green-700 px-4 py-3 rounded mb-4">
                <?= e($success) ?>
            </div>
        <?php endif; ?>

        <?php if ($error): ?>
            <div class="bg-red-100 border border-red-400 text-red-700 px-4 py-3 rounded mb-4">
                <?= e($error) ?>
            </div>
        <?php endif; ?>

        <!-- Tab-Navigation -->
        <div class="mb-6 overflow-x-auto">
            <nav class="flex space-x-2 min-w-max">
                <button onclick="showTab('projects')" id="tab-projects"
                        class="px-4 py-2 rounded-md bg-blue-600 text-white font-bold text-sm sm:text-base">
                    Projektverwaltung
                </button>
                <button onclick="showTab('users')" id="tab-users"
                        class="px-4 py-2 rounded-md bg-gray-200 text-gray-700 font-bold text-sm sm:text-base">
                    Nutzerverwaltung
                </button>
                <button onclick="showTab('system')" id="tab-system"
                        class="px-4 py-2 rounded-md bg-gray-200 text-gray-700 font-bold text-sm sm:text-base">
                    System
                </button>
                <button onclick="showTab('prioritization')" id="tab-prioritization"
                        class="px-4 py-2 rounded-md bg-gray-200 text-gray-700 font-bold text-sm sm:text-base">
                    Priorisierung
                </button>
            </nav>
        </div>

        <!-- ============================================================ -->
        <!-- Projektverwaltung -->
        <!-- ============================================================ -->
        <div id="panel-projects">
            <!-- Filter -->
            <div class="bg-white rounded-lg shadow-md p-4 mb-4">
                <div class="flex items-center justify-between mb-3">
                    <h3 class="text-sm font-bold text-gray-700">Filter</h3>
                    <button onclick="clearProjectFilter()" class="text-xs text-blue-600 hover:underline">Zurücksetzen</button>
                </div>
                <div class="flex flex-col sm:flex-row flex-wrap gap-3">
                    <div class="flex items-center gap-2 min-w-0">
                        <input type="checkbox" id="pf-name-en" class="proj-filter-cb shrink-0" onchange="applyProjectFilter()">
                        <label for="pf-name-en" class="text-sm text-gray-600 whitespace-nowrap shrink-0">Name:</label>
                        <input type="text" id="pf-name" placeholder="Suchen…"
                               class="px-2 py-1 border border-gray-300 rounded text-sm min-w-0 w-full sm:w-36"
                               oninput="applyProjectFilter()">
                    </div>
                    <div class="flex items-center gap-2 min-w-0">
                        <input type="checkbox" id="pf-status-en" class="proj-filter-cb shrink-0" onchange="applyProjectFilter()">
                        <label for="pf-status-en" class="text-sm text-gray-600 whitespace-nowrap shrink-0">Status:</label>
                        <select id="pf-status" class="px-2 py-1 border border-gray-300 rounded text-sm min-w-0" onchange="applyProjectFilter()">
                            <option value="">Alle</option>
                            <?php foreach ($validStatuses as $s): ?>
                                <option value="<?= e($s) ?>"><?= e($s) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="flex items-center gap-2 min-w-0">
                        <input type="checkbox" id="pf-text-en" class="proj-filter-cb shrink-0" onchange="applyProjectFilter()">
                        <label for="pf-text-en" class="text-sm text-gray-600 whitespace-nowrap shrink-0">Beschreibung/Begründung:</label>
                        <input type="text" id="pf-text" placeholder="Suchen…"
                               class="px-2 py-1 border border-gray-300 rounded text-sm min-w-0 w-full sm:w-36"
                               oninput="applyProjectFilter()">
                    </div>
                </div>
            </div>

            <!-- Projekttabelle -->
            <div class="bg-white rounded-lg shadow-md overflow-hidden">
                <div class="overflow-x-auto">
                    <table class="w-full text-left text-sm">
                        <thead class="bg-gray-50">
                            <tr>
                                <th class="px-3 py-3 font-semibold text-gray-700">Name</th>
                                <th class="px-3 py-3 font-semibold text-gray-700">Status</th>
                                <th class="px-3 py-3 font-semibold text-gray-700 hidden sm:table-cell">Vorgeschlagen von</th>
                                <th class="px-3 py-3 font-semibold text-gray-700 hidden md:table-cell">Datum</th>
                                <th class="px-3 py-3 font-semibold text-gray-700">Upvotes</th>
                                <th class="px-3 py-3 font-semibold text-gray-700">Aktionen</th>
                            </tr>
                        </thead>
                        <tbody id="projects-tbody" class="divide-y divide-gray-200">
                            <?php foreach ($projects as $project): ?>
                                <tr data-name="<?= e(strtolower($project['name'])) ?>"
                                    data-status="<?= e($project['status']) ?>"
                                    data-description="<?= e(strtolower($project['description'])) ?>"
                                    data-reason="<?= e(strtolower($project['reason'])) ?>">
                                    <td class="px-3 py-3 font-medium"><?= e($project['name']) ?></td>
                                    <td class="px-3 py-3">
                                        <span class="inline-block px-2 py-1 rounded-full text-xs font-medium <?= getStatusColor($project['status']) ?> whitespace-nowrap">
                                            <?= e($project['status']) ?>
                                        </span>
                                    </td>
                                    <td class="px-3 py-3 text-gray-600 hidden sm:table-cell">
                                        <?= $project['is_anonymous'] ? 'Anonym' : e($project['proposed_by_name'] ?? 'Unbekannt') ?>
                                    </td>
                                    <td class="px-3 py-3 text-gray-500 hidden md:table-cell whitespace-nowrap">
                                        <?= e(substr($project['created_at'], 0, 10)) ?>
                                    </td>
                                    <td class="px-3 py-3">
                                        <?php $upCount = (int)($project['upvote_count'] ?? 0); ?>
                                        <?php if ($upCount > 0 && !empty($project['upvoter_names'])): ?>
                                            <details class="text-xs text-gray-600">
                                                <summary class="cursor-pointer select-none whitespace-nowrap">👍 <?= $upCount ?></summary>
                                                <div class="mt-1 text-gray-500 max-h-32 overflow-y-auto max-w-xs">
                                                    <?= e($project['upvoter_names']) ?>
                                                </div>
                                            </details>
                                        <?php else: ?>
                                            <span class="text-xs text-gray-400 whitespace-nowrap">👍 <?= $upCount ?></span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="px-3 py-3">
                                        <div class="flex flex-wrap gap-1">
                                            <button onclick="openEditProject(<?= (int)$project['id'] ?>)"
                                                    class="bg-blue-500 text-white px-2 py-1 rounded text-xs hover:bg-blue-600 whitespace-nowrap">
                                                Bearbeiten
                                            </button>
                                            <button onclick="confirmDeleteProject(<?= (int)$project['id'] ?>, '<?= e(addslashes($project['name'])) ?>')"
                                                    class="bg-red-500 text-white px-2 py-1 rounded text-xs hover:bg-red-600 whitespace-nowrap">
                                                Löschen
                                            </button>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>

                            <?php if (empty($projects)): ?>
                                <tr id="projects-empty">
                                    <td colspan="6" class="px-4 py-6 text-center text-gray-500">Keine Projekte vorhanden.</td>
                                </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
                <div id="projects-no-results" class="hidden px-4 py-6 text-center text-gray-500">
                    Keine Projekte entsprechen dem Filter.
                </div>
            </div>
        </div>

        <!-- ============================================================ -->
        <!-- Nutzerverwaltung -->
        <!-- ============================================================ -->
        <div id="panel-users" class="hidden">
            <!-- Nutzer anlegen -->
            <div class="bg-white rounded-lg shadow-md p-5 mb-4">
                <h2 class="text-base font-bold text-gray-800 mb-4">Neuen Nutzer anlegen</h2>
                <form method="POST" action="index.php?page=verwaltung" class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                    <?= csrfField() ?>
                    <input type="hidden" name="action" value="create_user">

                    <div>
                        <label class="block text-sm font-bold text-gray-700 mb-1">Benutzername *</label>
                        <input type="text" name="username" required
                               class="w-full px-3 py-2 border border-gray-300 rounded-md focus:ring-2 focus:ring-blue-500">
                    </div>
                    <div>
                        <label class="block text-sm font-bold text-gray-700 mb-1">
                            E-Mail <span class="text-gray-400 text-xs font-normal">(optional)</span>
                        </label>
                        <input type="email" name="email"
                               class="w-full px-3 py-2 border border-gray-300 rounded-md focus:ring-2 focus:ring-blue-500">
                    </div>
                    <div>
                        <label class="block text-sm font-bold text-gray-700 mb-1">Passwort *</label>
                        <input type="password" name="password" required
                               class="w-full px-3 py-2 border border-gray-300 rounded-md focus:ring-2 focus:ring-blue-500">
                    </div>
                    <div class="flex flex-wrap items-end gap-4">
                        <label class="flex items-center gap-2 cursor-pointer">
                            <input type="checkbox" name="is_admin" value="1" class="w-4 h-4">
                            <span class="text-sm text-gray-700">Admin</span>
                        </label>
                        <label class="flex items-center gap-2 cursor-pointer">
                            <input type="checkbox" name="email_notifications" value="1" class="w-4 h-4">
                            <span class="text-sm text-gray-700">E-Mail-Benachrichtigungen</span>
                        </label>
                        <button type="submit" class="bg-green-600 text-white px-4 py-2 rounded-md hover:bg-green-700 font-bold text-sm">
                            Anlegen
                        </button>
                    </div>
                </form>
            </div>

            <!-- Filter Nutzer -->
            <div class="bg-white rounded-lg shadow-md p-4 mb-4">
                <div class="flex items-center justify-between mb-3">
                    <h3 class="text-sm font-bold text-gray-700">Filter</h3>
                    <button onclick="clearUserFilter()" class="text-xs text-blue-600 hover:underline">Zurücksetzen</button>
                </div>
                <div class="flex flex-col sm:flex-row flex-wrap gap-3">
                    <div class="flex items-center gap-2 min-w-0">
                        <input type="checkbox" id="uf-name-en" class="user-filter-cb shrink-0" onchange="applyUserFilter()">
                        <label for="uf-name-en" class="text-sm text-gray-600 whitespace-nowrap shrink-0">Benutzername:</label>
                        <input type="text" id="uf-name" placeholder="Suchen…"
                               class="px-2 py-1 border border-gray-300 rounded text-sm min-w-0 w-full sm:w-36"
                               oninput="applyUserFilter()">
                    </div>
                    <div class="flex items-center gap-2 min-w-0">
                        <input type="checkbox" id="uf-admin-en" class="user-filter-cb shrink-0" onchange="applyUserFilter()">
                        <label for="uf-admin-en" class="text-sm text-gray-600 whitespace-nowrap shrink-0">Admin:</label>
                        <select id="uf-admin" class="px-2 py-1 border border-gray-300 rounded text-sm min-w-0" onchange="applyUserFilter()">
                            <option value="">Alle</option>
                            <option value="1">Ja</option>
                            <option value="0">Nein</option>
                        </select>
                    </div>
                </div>
            </div>

            <!-- Nutzerliste -->
            <div class="bg-white rounded-lg shadow-md overflow-hidden">
                <div class="overflow-x-auto">
                    <table class="w-full text-left text-sm">
                        <thead class="bg-gray-50">
                            <tr>
                                <th class="px-3 py-3 font-semibold text-gray-700">Benutzername</th>
                                <th class="px-3 py-3 font-semibold text-gray-700 hidden sm:table-cell">E-Mail</th>
                                <th class="px-3 py-3 font-semibold text-gray-700">Admin</th>
                                <th class="px-3 py-3 font-semibold text-gray-700 hidden md:table-cell">Benachrichtigungen</th>
                                <th class="px-3 py-3 font-semibold text-gray-700">Aktionen</th>
                            </tr>
                        </thead>
                        <tbody id="users-tbody" class="divide-y divide-gray-200">
                            <?php foreach ($users as $user): ?>
                                <tr data-username="<?= e(strtolower($user['username'])) ?>"
                                    data-is-admin="<?= (int)$user['is_admin'] ?>">
                                    <td class="px-3 py-3 font-medium"><?= e($user['username']) ?></td>
                                    <td class="px-3 py-3 text-gray-600 hidden sm:table-cell"><?= e($user['email'] ?: '—') ?></td>
                                    <td class="px-3 py-3">
                                        <?= $user['is_admin']
                                            ? '<span class="text-green-600 font-bold">Ja</span>'
                                            : '<span class="text-gray-400">Nein</span>' ?>
                                    </td>
                                    <td class="px-3 py-3 hidden md:table-cell">
                                        <?= $user['email_notifications']
                                            ? '<span class="text-green-600">Aktiv</span>'
                                            : '<span class="text-gray-400">Inaktiv</span>' ?>
                                    </td>
                                    <td class="px-3 py-3">
                                        <div class="flex flex-wrap gap-1">
                                            <button onclick="openResetPassword(<?= (int)$user['id'] ?>, '<?= e(addslashes($user['username'])) ?>')"
                                                    class="bg-yellow-500 text-white px-2 py-1 rounded text-xs hover:bg-yellow-600 whitespace-nowrap">
                                                Passwort
                                            </button>
                                            <button onclick="openUpdateEmail(<?= (int)$user['id'] ?>, '<?= e(addslashes($user['username'])) ?>', '<?= e(addslashes($user['email'] ?? '')) ?>')"
                                                    class="bg-blue-500 text-white px-2 py-1 rounded text-xs hover:bg-blue-600 whitespace-nowrap">
                                                E-Mail
                                            </button>
                                            <?php if ((int)$user['id'] !== (int)$_SESSION['user_id']): ?>
                                                <button onclick="confirmDeleteUser(<?= (int)$user['id'] ?>, '<?= e(addslashes($user['username'])) ?>')"
                                                        class="bg-red-500 text-white px-2 py-1 rounded text-xs hover:bg-red-600 whitespace-nowrap">
                                                    Löschen
                                                </button>
                                            <?php endif; ?>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <div id="users-no-results" class="hidden px-4 py-6 text-center text-gray-500">
                    Keine Nutzer entsprechen dem Filter.
                </div>
            </div>
        </div>

        <!-- ============================================================ -->
        <!-- System-Einstellungen -->
        <!-- ============================================================ -->
        <div id="panel-system" class="hidden">
            <div class="bg-white rounded-lg shadow-md p-5">
                <h2 class="text-base font-bold text-gray-800 mb-4">Systemeinstellungen</h2>

                <!-- E-Mail-Sperre -->
                <form method="POST" action="index.php?page=verwaltung">
                    <?= csrfField() ?>
                    <input type="hidden" name="action" value="update_mail_setting">

                    <div class="border border-gray-200 rounded-lg p-4 mb-4">
                        <div class="flex flex-wrap items-start justify-between gap-4">
                            <div>
                                <h3 class="font-semibold text-gray-800">E-Mail-Versand global deaktivieren</h3>
                                <p class="text-sm text-gray-500 mt-1">
                                    Wenn aktiviert, werden keine E-Mails versendet (auch wenn Nutzer Benachrichtigungen aktiviert haben).
                                    Die persönlichen Einstellungen bleiben gespeichert und werden nach Reaktivierung wieder genutzt.
                                    Nützlich, wenn der Server aktuell keine E-Mails versenden kann.
                                </p>
                            </div>
                            <label class="flex items-center gap-2 cursor-pointer shrink-0">
                                <input type="checkbox" name="mail_disabled" value="1"
                                       <?= $mailDisabled ? 'checked' : '' ?>
                                       class="w-5 h-5 rounded">
                                <span class="text-sm font-medium text-gray-700">
                                    <?= $mailDisabled ? '<span class="text-red-600">Deaktiviert</span>' : '<span class="text-gray-600">Aktiv</span>' ?>
                                </span>
                            </label>
                        </div>
                    </div>

                    <button type="submit" class="bg-blue-600 text-white px-4 py-2 rounded-md hover:bg-blue-700 font-bold text-sm">
                        Einstellungen speichern
                    </button>
                </form>
            </div>

            <!-- Feature-Einstellungen: Kommentare & Upvotes -->
            <div class="bg-white rounded-lg shadow-md p-5 mt-6">
                <h2 class="text-base font-bold text-gray-800 mb-4">Interaktions-Einstellungen</h2>
                <p class="text-xs text-gray-500 mb-4">
                    Diese Einstellungen sind die globalen Standardwerte. Einzelne Projekte können im Bearbeiten-Dialog
                    abweichend konfiguriert werden. Abgelehnte Projekte können grundsätzlich nicht upgevotet werden.
                </p>
                <form method="POST" action="index.php?page=verwaltung">
                    <?= csrfField() ?>
                    <input type="hidden" name="action" value="update_feature_settings">

                    <!-- Kommentare -->
                    <div class="border border-gray-200 rounded-lg p-4 mb-4">
                        <div class="flex flex-wrap items-start justify-between gap-4">
                            <div>
                                <h3 class="font-semibold text-gray-800">Kommentare</h3>
                                <p class="text-sm text-gray-500 mt-1">
                                    Nutzer können Projekte kommentieren.
                                </p>
                            </div>
                            <label class="flex items-center gap-2 cursor-pointer shrink-0">
                                <input type="checkbox" name="comments_enabled" value="1"
                                       <?= $featureSettings['comments_enabled'] ? 'checked' : '' ?>
                                       class="w-5 h-5 rounded">
                                <span class="text-sm font-medium text-gray-700">Aktiv</span>
                            </label>
                        </div>
                        <div class="mt-3">
                            <label class="block text-sm text-gray-700 mb-1">Wer darf kommentieren?</label>
                            <select name="comments_permission" class="px-3 py-2 border border-gray-300 rounded-md text-sm">
                                <option value="all"   <?= $featureSettings['comments_permission'] === 'all'   ? 'selected' : '' ?>>Alle Nutzer</option>
                                <option value="admin" <?= $featureSettings['comments_permission'] === 'admin' ? 'selected' : '' ?>>Nur Verwaltung</option>
                            </select>
                        </div>
                    </div>

                    <!-- Upvotes -->
                    <div class="border border-gray-200 rounded-lg p-4 mb-4">
                        <div class="flex flex-wrap items-start justify-between gap-4">
                            <div>
                                <h3 class="font-semibold text-gray-800">Upvotes</h3>
                                <p class="text-sm text-gray-500 mt-1">
                                    Nutzer können Projekte mit einer Stimme pro Projekt bewerten.
                                </p>
                            </div>
                            <label class="flex items-center gap-2 cursor-pointer shrink-0">
                                <input type="checkbox" name="upvotes_enabled" value="1"
                                       <?= $featureSettings['upvotes_enabled'] ? 'checked' : '' ?>
                                       class="w-5 h-5 rounded">
                                <span class="text-sm font-medium text-gray-700">Aktiv</span>
                            </label>
                        </div>
                        <div class="mt-3">
                            <label class="block text-sm text-gray-700 mb-1">Wer darf upvoten?</label>
                            <select name="upvotes_permission" class="px-3 py-2 border border-gray-300 rounded-md text-sm">
                                <option value="all"   <?= $featureSettings['upvotes_permission'] === 'all'   ? 'selected' : '' ?>>Alle Nutzer</option>
                                <option value="admin" <?= $featureSettings['upvotes_permission'] === 'admin' ? 'selected' : '' ?>>Nur Verwaltung</option>
                            </select>
                        </div>
                    </div>

                    <button type="submit" class="bg-blue-600 text-white px-4 py-2 rounded-md hover:bg-blue-700 font-bold text-sm">
                        Interaktions-Einstellungen speichern
                    </button>
                </form>
            </div>
        </div>
        <!-- ============================================================ -->
        <!-- Priorisierung (Kanban) -->
        <!-- ============================================================ -->
        <div id="panel-prioritization" class="hidden">

            <!-- Toast-Container -->
            <div id="toast-container" class="fixed top-4 right-4 z-50 flex flex-col gap-2"></div>

            <!-- Suchfeld -->
            <div class="mb-4">
                <input
                    type="text"
                    id="prioritization-search"
                    placeholder="🔍 Projekte suchen…"
                    class="w-full p-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500"
                />
            </div>

            <!-- Kanban-Spalten (Desktop: 4 Spalten, Mobile: Stack mit Collapsible + Dropdown) -->
            <div id="priority-board" class="flex flex-col gap-3 md:grid md:grid-cols-4 md:gap-4">

                <?php
                $columnConfig = [
                    'Unpriorisiert' => ['emoji' => '📌', 'bg' => 'bg-gray-50',   'border' => 'border-gray-300',  'hdr' => 'text-gray-700',   'cnt' => 'bg-gray-200',   'drop' => 'border-gray-300'],
                    'Hoch'          => ['emoji' => '🔴', 'bg' => 'bg-red-50',    'border' => 'border-red-300',   'hdr' => 'text-red-700',    'cnt' => 'bg-red-200',    'drop' => 'border-red-300'],
                    'Mittel'        => ['emoji' => '🟡', 'bg' => 'bg-yellow-50', 'border' => 'border-yellow-300','hdr' => 'text-yellow-700', 'cnt' => 'bg-yellow-200', 'drop' => 'border-yellow-300'],
                    'Niedrig'       => ['emoji' => '🟢', 'bg' => 'bg-green-50',  'border' => 'border-green-300', 'hdr' => 'text-green-700',  'cnt' => 'bg-green-200',  'drop' => 'border-green-300'],
                ];

                foreach ($columnConfig as $colName => $cfg):
                    $cards = $prioProjects[$colName];
                    $count = count($cards);
                ?>
                <div class="priority-category <?= $cfg['bg'] ?> rounded-lg p-4 border-2 <?= $cfg['border'] ?>"
                     data-category="<?= e($colName) ?>">
                    <button type="button"
                            class="priority-header w-full flex items-center gap-2 font-bold text-lg <?= $cfg['hdr'] ?> mb-4 md:cursor-default md:pointer-events-none"
                            onclick="togglePriorityCategory(this)">
                        <span class="priority-chevron md:hidden text-base transition-transform">▼</span>
                        <?= $cfg['emoji'] ?> <?= e($colName) ?>
                        <span class="text-sm <?= $cfg['cnt'] ?> px-2 py-0.5 rounded priority-count"><?= $count ?></span>
                    </button>
                    <div class="priority-body">
                        <div
                            class="priority-column min-h-40 bg-white rounded border-2 border-dashed <?= $cfg['drop'] ?> p-2 space-y-2"
                            data-priority="<?= e($colName) ?>"
                        >
                            <?php foreach ($cards as $card):
                                $statusSlug = strtolower(str_replace(' ', '-', $card['status']));
                                $upvoteCount = (int)($card['upvote_count'] ?? 0);
                            ?>
                            <div
                                class="draggable-project-card p-3 bg-white border border-gray-200 rounded md:cursor-move hover:shadow-md transition select-none"
                                data-project-id="<?= (int)$card['id'] ?>"
                                draggable="true"
                            >
                                <h4 class="font-semibold text-sm leading-snug"><?= e($card['name']) ?></h4>
                                <?php if ($card['description']): ?>
                                <p class="text-xs text-gray-500 mt-1 line-clamp-2"><?= e(mb_strimwidth($card['description'], 0, 100, '…')) ?></p>
                                <?php endif; ?>
                                <div class="mt-2 flex flex-wrap items-center gap-2">
                                    <span class="text-xs px-2 py-0.5 rounded-full font-medium status-badge-<?= e($statusSlug) ?>">
                                        <?= e($card['status']) ?>
                                    </span>
                                    <span class="text-xs text-gray-500" title="Upvotes">👍 <?= $upvoteCount ?></span>
                                </div>
                                <!-- Mobile: Dropdown zum Ändern der Priorität -->
                                <select class="mobile-priority-select md:hidden mt-2 w-full text-xs border border-gray-300 rounded px-2 py-1 bg-white"
                                        data-project-id="<?= (int)$card['id'] ?>"
                                        onchange="handleMobilePriorityChange(this)">
                                    <?php foreach (array_keys($columnConfig) as $opt): ?>
                                        <option value="<?= e($opt) ?>" <?= $opt === $colName ? 'selected' : '' ?>>
                                            <?= $columnConfig[$opt]['emoji'] ?> <?= e($opt) ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </div>
                <?php endforeach; ?>

            </div>

            <!-- Status-Badge-Styles -->
            <style>
                .status-badge-vorgeschlagen   { background: #dbeafe; color: #1d4ed8; }
                .status-badge-in-besprechung  { background: #ccfbf1; color: #0f766e; }
                .status-badge-in-bearbeitung  { background: #ede9fe; color: #6d28d9; }
                .sortable-ghost { opacity: 0.4; }
                .sortable-drag  { box-shadow: 0 8px 24px rgba(0,0,0,.18); }
                .line-clamp-2 { overflow: hidden; display: -webkit-box; -webkit-line-clamp: 2; -webkit-box-orient: vertical; }
                .priority-category.collapsed .priority-body { display: none; }
                .priority-category.collapsed .priority-chevron { transform: rotate(-90deg); }
                @media (min-width: 768px) {
                    .priority-category.collapsed .priority-body { display: block; }
                    .priority-category.collapsed .priority-chevron { transform: none; }
                }
            </style>
        </div>

    </main>

    <!-- ============================================================ -->
    <!-- Projekt bearbeiten Modal -->
    <!-- ============================================================ -->
    <div id="edit-project-modal" class="fixed inset-0 bg-black bg-opacity-50 hidden z-50 flex items-center justify-center p-4">
        <div class="bg-white rounded-lg shadow-xl w-full max-w-lg max-h-[90vh] overflow-y-auto">
            <div class="p-5 sm:p-6">
                <div class="flex justify-between items-start mb-4">
                    <h2 class="text-xl font-bold text-gray-800">Projekt bearbeiten</h2>
                    <button onclick="closeEditProject()" class="text-gray-400 hover:text-gray-600 text-2xl leading-none shrink-0">&times;</button>
                </div>
                <form method="POST" action="index.php?page=verwaltung">
                    <?= csrfField() ?>
                    <input type="hidden" name="action" value="edit_project">
                    <input type="hidden" name="project_id" id="edit-project-id">

                    <div class="mb-4">
                        <label class="block text-sm font-bold text-gray-700 mb-1">Name *</label>
                        <input type="text" name="name" id="edit-project-name" required
                               class="w-full px-3 py-2 border border-gray-300 rounded-md focus:ring-2 focus:ring-blue-500">
                    </div>
                    <div class="mb-4">
                        <label class="block text-sm font-bold text-gray-700 mb-1">Beschreibung *</label>
                        <textarea name="description" id="edit-project-description" rows="3" required
                                  class="w-full px-3 py-2 border border-gray-300 rounded-md focus:ring-2 focus:ring-blue-500"></textarea>
                    </div>
                    <div class="mb-4">
                        <label class="block text-sm font-bold text-gray-700 mb-1">Begründung *</label>
                        <textarea name="reason" id="edit-project-reason" rows="2" required
                                  class="w-full px-3 py-2 border border-gray-300 rounded-md focus:ring-2 focus:ring-blue-500"></textarea>
                    </div>
                    <div class="mb-4">
                        <label class="block text-sm font-bold text-gray-700 mb-1">Status *</label>
                        <select name="status" id="edit-project-status" required onchange="toggleDecisionReason()"
                                class="w-full px-3 py-2 border border-gray-300 rounded-md focus:ring-2 focus:ring-blue-500">
                            <?php foreach ($validStatuses as $s): ?>
                                <option value="<?= e($s) ?>"><?= e($s) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="mb-4 hidden" id="decision-reason-group">
                        <label class="block text-sm font-bold text-gray-700 mb-1">Begründung der Entscheidung</label>
                        <textarea name="decision_reason" id="edit-project-decision-reason" rows="2"
                                  class="w-full px-3 py-2 border border-gray-300 rounded-md focus:ring-2 focus:ring-blue-500"></textarea>
                    </div>

                    <!-- Feature-Overrides -->
                    <div class="border border-gray-200 rounded-md p-3 mb-4 bg-gray-50">
                        <p class="text-xs font-bold text-gray-700 mb-2">
                            Überschreibung pro Projekt
                            <span class="font-normal text-gray-500">(Leer = globale Standardeinstellung)</span>
                        </p>
                        <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                            <div>
                                <label class="block text-xs text-gray-600 mb-1">Kommentare</label>
                                <select name="comments_enabled_override" id="edit-comments-enabled-override"
                                        class="w-full px-2 py-1 border border-gray-300 rounded text-sm">
                                    <option value="">Standard</option>
                                    <option value="1">An</option>
                                    <option value="0">Aus</option>
                                </select>
                            </div>
                            <div>
                                <label class="block text-xs text-gray-600 mb-1">Kommentieren dürfen</label>
                                <select name="comments_permission_override" id="edit-comments-permission-override"
                                        class="w-full px-2 py-1 border border-gray-300 rounded text-sm">
                                    <option value="">Standard</option>
                                    <option value="all">Alle Nutzer</option>
                                    <option value="admin">Nur Verwaltung</option>
                                </select>
                            </div>
                            <div>
                                <label class="block text-xs text-gray-600 mb-1">Upvotes</label>
                                <select name="upvotes_enabled_override" id="edit-upvotes-enabled-override"
                                        class="w-full px-2 py-1 border border-gray-300 rounded text-sm">
                                    <option value="">Standard</option>
                                    <option value="1">An</option>
                                    <option value="0">Aus</option>
                                </select>
                            </div>
                            <div>
                                <label class="block text-xs text-gray-600 mb-1">Upvoten dürfen</label>
                                <select name="upvotes_permission_override" id="edit-upvotes-permission-override"
                                        class="w-full px-2 py-1 border border-gray-300 rounded text-sm">
                                    <option value="">Standard</option>
                                    <option value="all">Alle Nutzer</option>
                                    <option value="admin">Nur Verwaltung</option>
                                </select>
                            </div>
                        </div>
                        <p class="text-xs text-gray-500 mt-2">
                            Hinweis: Abgelehnte Projekte können nie upgevotet werden (harte Sperre).
                        </p>
                    </div>

                    <button type="submit" class="w-full bg-blue-600 text-white py-2 px-4 rounded-md hover:bg-blue-700 font-bold">
                        Speichern
                    </button>
                </form>
            </div>
        </div>
    </div>

    <!-- ============================================================ -->
    <!-- Passwort zurücksetzen Modal -->
    <!-- ============================================================ -->
    <div id="reset-password-modal" class="fixed inset-0 bg-black bg-opacity-50 hidden z-50 flex items-center justify-center p-4">
        <div class="bg-white rounded-lg shadow-xl w-full max-w-md">
            <div class="p-5 sm:p-6">
                <div class="flex justify-between items-start mb-4">
                    <h2 class="text-xl font-bold text-gray-800">Passwort zurücksetzen</h2>
                    <button onclick="closeResetPassword()" class="text-gray-400 hover:text-gray-600 text-2xl leading-none">&times;</button>
                </div>
                <p class="text-gray-600 mb-4">Neues Passwort für: <strong id="reset-username"></strong></p>
                <form method="POST" action="index.php?page=verwaltung">
                    <?= csrfField() ?>
                    <input type="hidden" name="action" value="reset_password">
                    <input type="hidden" name="user_id" id="reset-user-id">

                    <div class="mb-4">
                        <label class="block text-sm font-bold text-gray-700 mb-1">Neues Passwort *</label>
                        <input type="password" name="new_password" required
                               class="w-full px-3 py-2 border border-gray-300 rounded-md focus:ring-2 focus:ring-blue-500">
                    </div>

                    <button type="submit" class="w-full bg-yellow-600 text-white py-2 px-4 rounded-md hover:bg-yellow-700 font-bold">
                        Passwort zurücksetzen
                    </button>
                </form>
            </div>
        </div>
    </div>

    <!-- ============================================================ -->
    <!-- E-Mail bearbeiten Modal -->
    <!-- ============================================================ -->
    <div id="update-email-modal" class="fixed inset-0 bg-black bg-opacity-50 hidden z-50 flex items-center justify-center p-4">
        <div class="bg-white rounded-lg shadow-xl w-full max-w-md">
            <div class="p-5 sm:p-6">
                <div class="flex justify-between items-start mb-4">
                    <h2 class="text-xl font-bold text-gray-800">E-Mail bearbeiten</h2>
                    <button onclick="closeUpdateEmail()" class="text-gray-400 hover:text-gray-600 text-2xl leading-none">&times;</button>
                </div>
                <p class="text-gray-600 mb-4">E-Mail ändern für: <strong id="email-username"></strong></p>
                <form id="update-email-form" method="POST" action="index.php?page=verwaltung">
                    <?= csrfField() ?>
                    <input type="hidden" name="action" value="update_email">
                    <input type="hidden" name="user_id" id="email-user-id">

                    <div class="mb-4">
                        <label class="block text-sm font-bold text-gray-700 mb-1">
                            E-Mail-Adresse <span class="text-gray-400 text-xs font-normal">(leer lassen zum Entfernen)</span>
                        </label>
                        <input type="email" name="new_email" id="email-current"
                               class="w-full px-3 py-2 border border-gray-300 rounded-md focus:ring-2 focus:ring-blue-500">
                    </div>

                    <div class="flex flex-col sm:flex-row gap-2">
                        <button type="submit" class="flex-1 bg-blue-600 text-white py-2 px-4 rounded-md hover:bg-blue-700 font-bold">
                            E-Mail speichern
                        </button>
                        <button type="button" onclick="removeEmail()"
                                class="flex-1 bg-red-600 text-white py-2 px-4 rounded-md hover:bg-red-700 font-bold">
                            E-Mail entfernen
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Versteckte Formulare für Lösch-Aktionen -->
    <form id="delete-user-form" method="POST" action="index.php?page=verwaltung" class="hidden">
        <?= csrfField() ?>
        <input type="hidden" name="action" value="delete_user">
        <input type="hidden" name="user_id" id="delete-user-id">
    </form>

    <form id="delete-project-form" method="POST" action="index.php?page=verwaltung" class="hidden">
        <?= csrfField() ?>
        <input type="hidden" name="action" value="delete_project">
        <input type="hidden" name="project_id" id="delete-project-id">
    </form>

    <script>
        // Projektdaten für das Bearbeiten-Modal
        const projectsData = <?= json_encode(array_map(function($p) {
            $toggleToString = fn($v) => $v === null ? '' : ((int)$v === 1 ? '1' : '0');
            return [
                'id'                           => (int)$p['id'],
                'name'                         => $p['name'],
                'description'                  => $p['description'],
                'reason'                       => $p['reason'],
                'status'                       => $p['status'],
                'decision_reason'              => $p['decision_reason'] ?? '',
                'comments_enabled_override'    => $toggleToString($p['comments_enabled'] ?? null),
                'comments_permission_override' => $p['comments_permission'] ?? '',
                'upvotes_enabled_override'     => $toggleToString($p['upvotes_enabled']  ?? null),
                'upvotes_permission_override'  => $p['upvotes_permission']  ?? '',
            ];
        }, $projects), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;

        // ============================================================
        // Tab-Umschaltung
        // ============================================================
        // ---- URL-Hash-Persistenz: Tab & Collapse-Zustand überleben einen Reload,
        //      werden aber nicht auf anderen Seiten getragen, weil der Menü-Link zur
        //      Verwaltung ohne Hash aufgerufen wird. ----
        function parseVerwaltungHash() {
            const raw = (location.hash || '').replace(/^#/, '');
            const out = {};
            raw.split('&').filter(Boolean).forEach(kv => {
                const [k, v = ''] = kv.split('=');
                if (!k) return;
                out[decodeURIComponent(k)] = decodeURIComponent(v);
            });
            return out;
        }
        function writeVerwaltungHash(obj) {
            const s = Object.entries(obj)
                .filter(([, v]) => v !== '' && v !== null && v !== undefined)
                .map(([k, v]) => encodeURIComponent(k) + '=' + encodeURIComponent(v))
                .join('&');
            history.replaceState(null, '', s ? '#' + s : location.pathname + location.search);
        }

        function showTab(tab, pushHash = true) {
            ['projects', 'users', 'system', 'prioritization'].forEach(t => {
                document.getElementById('panel-' + t).classList.toggle('hidden', t !== tab);
                const btn = document.getElementById('tab-' + t);
                btn.className = t === tab
                    ? 'px-4 py-2 rounded-md bg-blue-600 text-white font-bold text-sm sm:text-base'
                    : 'px-4 py-2 rounded-md bg-gray-200 text-gray-700 font-bold text-sm sm:text-base';
            });
            if (pushHash) {
                const h = parseVerwaltungHash();
                h.tab = tab;
                writeVerwaltungHash(h);
            }
        }

        // Initial-Tab aus dem URL-Hash wiederherstellen (falls vorhanden)
        document.addEventListener('DOMContentLoaded', function () {
            const validTabs = ['projects', 'users', 'system', 'prioritization'];
            const h = parseVerwaltungHash();
            if (h.tab && validTabs.includes(h.tab)) showTab(h.tab, false);
        });

        // ============================================================
        // Projekt-Filter
        // ============================================================
        function applyProjectFilter() {
            const nameEnabled   = document.getElementById('pf-name-en').checked;
            const statusEnabled = document.getElementById('pf-status-en').checked;
            const textEnabled   = document.getElementById('pf-text-en').checked;

            const nameVal   = document.getElementById('pf-name').value.toLowerCase().trim();
            const statusVal = document.getElementById('pf-status').value;
            const textVal   = document.getElementById('pf-text').value.toLowerCase().trim();

            const rows = document.querySelectorAll('#projects-tbody tr[data-name]');
            let visibleCount = 0;

            rows.forEach(row => {
                const name    = row.dataset.name || '';
                const status  = row.dataset.status || '';
                const desc    = row.dataset.description || '';
                const reason  = row.dataset.reason || '';

                let show = true;
                if (nameEnabled   && nameVal   && !name.includes(nameVal))   show = false;
                if (statusEnabled && statusVal && status !== statusVal)       show = false;
                if (textEnabled   && textVal   && !desc.includes(textVal) && !reason.includes(textVal)) show = false;

                row.style.display = show ? '' : 'none';
                if (show) visibleCount++;
            });

            document.getElementById('projects-no-results').classList.toggle('hidden', visibleCount > 0 || rows.length === 0);
        }

        function clearProjectFilter() {
            document.querySelectorAll('.proj-filter-cb').forEach(cb => cb.checked = false);
            document.getElementById('pf-name').value   = '';
            document.getElementById('pf-status').value = '';
            document.getElementById('pf-text').value   = '';
            applyProjectFilter();
        }

        // ============================================================
        // Nutzer-Filter
        // ============================================================
        function applyUserFilter() {
            const nameEnabled  = document.getElementById('uf-name-en').checked;
            const adminEnabled = document.getElementById('uf-admin-en').checked;

            const nameVal  = document.getElementById('uf-name').value.toLowerCase().trim();
            const adminVal = document.getElementById('uf-admin').value;

            const rows = document.querySelectorAll('#users-tbody tr[data-username]');
            let visibleCount = 0;

            rows.forEach(row => {
                const username = row.dataset.username || '';
                const isAdmin  = row.dataset.isAdmin  || '0';

                let show = true;
                if (nameEnabled  && nameVal  && !username.includes(nameVal)) show = false;
                if (adminEnabled && adminVal && isAdmin !== adminVal)         show = false;

                row.style.display = show ? '' : 'none';
                if (show) visibleCount++;
            });

            document.getElementById('users-no-results').classList.toggle('hidden', visibleCount > 0 || rows.length === 0);
        }

        function clearUserFilter() {
            document.querySelectorAll('.user-filter-cb').forEach(cb => cb.checked = false);
            document.getElementById('uf-name').value  = '';
            document.getElementById('uf-admin').value = '';
            applyUserFilter();
        }

        // ============================================================
        // Projekt bearbeiten Modal
        // ============================================================
        function openEditProject(projectId) {
            const project = projectsData.find(p => p.id === projectId);
            if (!project) return;

            document.getElementById('edit-project-id').value              = project.id;
            document.getElementById('edit-project-name').value            = project.name;
            document.getElementById('edit-project-description').value     = project.description;
            document.getElementById('edit-project-reason').value          = project.reason;
            document.getElementById('edit-project-status').value          = project.status;
            document.getElementById('edit-project-decision-reason').value = project.decision_reason || '';

            document.getElementById('edit-comments-enabled-override').value    = project.comments_enabled_override    || '';
            document.getElementById('edit-comments-permission-override').value = project.comments_permission_override || '';
            document.getElementById('edit-upvotes-enabled-override').value     = project.upvotes_enabled_override     || '';
            document.getElementById('edit-upvotes-permission-override').value  = project.upvotes_permission_override  || '';

            toggleDecisionReason();
            document.getElementById('edit-project-modal').classList.remove('hidden');
        }

        function closeEditProject() {
            document.getElementById('edit-project-modal').classList.add('hidden');
        }

        function toggleDecisionReason() {
            const status   = document.getElementById('edit-project-status').value;
            const group    = document.getElementById('decision-reason-group');
            const textarea = document.getElementById('edit-project-decision-reason');
            const show     = status === 'Angenommen' || status === 'Abgelehnt';
            group.classList.toggle('hidden', !show);
            textarea.required = status === 'Abgelehnt';
        }

        // ============================================================
        // Projekt löschen
        // ============================================================
        function confirmDeleteProject(projectId, name) {
            if (confirm('Vorschlag "' + name + '" wirklich löschen? Diese Aktion kann nicht rückgängig gemacht werden.')) {
                document.getElementById('delete-project-id').value = projectId;
                document.getElementById('delete-project-form').submit();
            }
        }

        // ============================================================
        // Nutzer-Modals
        // ============================================================
        function openResetPassword(userId, username) {
            document.getElementById('reset-user-id').value = userId;
            document.getElementById('reset-username').textContent = username;
            document.getElementById('reset-password-modal').classList.remove('hidden');
        }

        function closeResetPassword() {
            document.getElementById('reset-password-modal').classList.add('hidden');
        }

        function openUpdateEmail(userId, username, currentEmail) {
            document.getElementById('email-user-id').value = userId;
            document.getElementById('email-username').textContent = username;
            document.getElementById('email-current').value = currentEmail;
            document.getElementById('update-email-modal').classList.remove('hidden');
        }

        function closeUpdateEmail() {
            document.getElementById('update-email-modal').classList.add('hidden');
        }

        function removeEmail() {
            const username = document.getElementById('email-username').textContent;
            if (!confirm('E-Mail von "' + username + '" wirklich entfernen?\n\nDadurch werden E-Mail-Benachrichtigungen für diesen Nutzer automatisch deaktiviert.')) {
                return;
            }
            document.getElementById('email-current').value = '';
            document.getElementById('update-email-form').submit();
        }

        function confirmDeleteUser(userId, username) {
            if (confirm('Nutzer "' + username + '" wirklich löschen?')) {
                document.getElementById('delete-user-id').value = userId;
                document.getElementById('delete-user-form').submit();
            }
        }

        // Modals schließen bei Klick außerhalb
        ['edit-project-modal', 'reset-password-modal', 'update-email-modal'].forEach(function(id) {
            document.getElementById(id).addEventListener('click', function(e) {
                if (e.target === this) this.classList.add('hidden');
            });
        });

        // Escape schließt alle Modals
        document.addEventListener('keydown', function(e) {
            if (e.key === 'Escape') {
                closeEditProject();
                closeResetPassword();
                closeUpdateEmail();
            }
        });

        // Nach einem POST (Redirect) den Tab aus dem URL-Hash wiederherstellen
        <?php if ($success || $error): ?>
        (function () {
            const validTabs = ['projects', 'users', 'system', 'prioritization'];
            const h = parseVerwaltungHash();
            if (h.tab && validTabs.includes(h.tab)) showTab(h.tab, false);
        })();
        <?php endif; ?>

        // Beim Absenden eines Formulars den aktiven Tab im Hash festhalten,
        // damit nach dem POST-Redirect der gleiche Tab wieder aktiv ist.
        document.querySelectorAll('form').forEach(form => {
            form.addEventListener('submit', () => {
                const tabs = ['projects', 'users', 'system', 'prioritization'];
                const activeTab = tabs.find(t => !document.getElementById('panel-' + t).classList.contains('hidden')) || 'projects';
                const h = parseVerwaltungHash();
                h.tab = activeTab;
                writeVerwaltungHash(h);
            });
        });

        // ============================================================
        // Priorisierungs-Kanban
        // ============================================================
        (function () {
            // Toast-Helper
            function showToast(msg, type) {
                const container = document.getElementById('toast-container');
                if (!container) return;
                const toast = document.createElement('div');
                toast.className = [
                    'px-4 py-3 rounded shadow-lg text-sm font-medium transition-opacity duration-300',
                    type === 'success' ? 'bg-green-100 border border-green-400 text-green-800'
                                      : 'bg-red-100 border border-red-400 text-red-800',
                ].join(' ');
                toast.textContent = msg;
                container.appendChild(toast);
                setTimeout(() => { toast.style.opacity = '0'; setTimeout(() => toast.remove(), 300); }, 3000);
            }

            // CSRF-Token aus dem DOM holen
            function getCsrfToken() {
                return document.querySelector('input[name="csrf_token"]')?.value ?? '';
            }

            // Ajax-Request
            function sendPrioritizeRequest(projectId, newPriority, cardEl, originColumn) {
                function revertCard() {
                    originColumn.appendChild(cardEl);
                    // Mobile-Dropdown ebenfalls zurücksetzen
                    const sel = cardEl.querySelector('.mobile-priority-select');
                    if (sel && originColumn.dataset.priority) {
                        sel.value = originColumn.dataset.priority;
                    }
                    updateColumnCounts();
                }

                fetch('index.php?page=api-projects&action=prioritize', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-CSRF-Token': getCsrfToken(),
                    },
                    body: JSON.stringify({
                        project_id: projectId,
                        priority: newPriority === 'Unpriorisiert' ? null : newPriority,
                    }),
                })
                .then(r => {
                    if (!r.ok) {
                        return r.text().then(text => {
                            try { return JSON.parse(text); }
                            catch { return { success: false, error: 'Server-Fehler (' + r.status + ')' }; }
                        });
                    }
                    return r.json();
                })
                .then(data => {
                    if (data.success) {
                        showToast('✓ Priorität aktualisiert', 'success');
                        updateColumnCounts();
                    } else {
                        showToast('✗ ' + (data.error || 'Fehler'), 'error');
                        revertCard();
                    }
                })
                .catch(() => {
                    showToast('✗ Netzwerkfehler – bitte Verbindung prüfen', 'error');
                    revertCard();
                });
            }

            // Spalten-Counter aktualisieren
            function updateColumnCounts() {
                document.querySelectorAll('.priority-column').forEach(col => {
                    const category = col.closest('.priority-category');
                    const countBadge = category?.querySelector('.priority-count');
                    if (countBadge) countBadge.textContent = col.querySelectorAll('.draggable-project-card').length;
                });
            }

            // Karte in die Zielspalte verschieben (nach Drop oder Dropdown-Wechsel)
            function moveCardToColumn(cardEl, targetPriority) {
                const targetColumn = document.querySelector('.priority-column[data-priority="' + CSS.escape(targetPriority) + '"]');
                if (targetColumn && cardEl.parentElement !== targetColumn) {
                    targetColumn.appendChild(cardEl);
                }
            }

            // Sortable.js – media-query-aware: nur auf >= md (768px) aktivieren
            const mq = window.matchMedia('(min-width: 768px)');
            let sortables = [];
            function initSortables() {
                sortables.forEach(s => s.destroy());
                sortables = [];
                if (!mq.matches) return; // auf Mobile kein Drag & Drop
                document.querySelectorAll('.priority-column').forEach(column => {
                    sortables.push(Sortable.create(column, {
                        group: 'priority-projects',
                        animation: 150,
                        ghostClass: 'sortable-ghost',
                        dragClass: 'sortable-drag',
                        onEnd: function (evt) {
                            if (evt.from === evt.to) return;
                            const projectId  = parseInt(evt.item.dataset.projectId, 10);
                            const newPriority = evt.to.dataset.priority;
                            sendPrioritizeRequest(projectId, newPriority, evt.item, evt.from);
                            // Dropdown in der Karte synchron halten
                            const sel = evt.item.querySelector('.mobile-priority-select');
                            if (sel) sel.value = newPriority;
                        },
                    }));
                });
            }
            initSortables();
            // Bei Resize/Viewport-Wechsel (Desktop ↔ Mobile) neu initialisieren
            if (mq.addEventListener) mq.addEventListener('change', initSortables);
            else if (mq.addListener) mq.addListener(initSortables);

            // Mobile-Dropdown: Priorität per Select ändern (global exponiert für onchange)
            window.handleMobilePriorityChange = function (selectEl) {
                const card = selectEl.closest('.draggable-project-card');
                if (!card) return;
                const originColumn = card.parentElement;
                const projectId = parseInt(selectEl.dataset.projectId, 10);
                const newPriority = selectEl.value;
                if (originColumn?.dataset?.priority === newPriority) return;
                moveCardToColumn(card, newPriority);
                updateColumnCounts();
                sendPrioritizeRequest(projectId, newPriority, card, originColumn);
            };

            // Collapse-Toggle für Kategorie-Kopfzeilen (nur Mobile effektiv; global exponiert)
            window.togglePriorityCategory = function (headerBtn) {
                const category = headerBtn.closest('.priority-category');
                if (!category) return;
                category.classList.toggle('collapsed');
                // Zustand in den URL-Hash schreiben, damit er einen Reload überlebt
                if (window.__persistPrioState) window.__persistPrioState();
            };

            // Hash-Zustand für Collapse wiederherstellen (nur auf Mobile sinnvoll,
            // Desktop-CSS überschreibt Collapse ohnehin)
            window.__restorePrioState = function () {
                const h = parseVerwaltungHash();
                const collapsed = (h.collapsed || '').split(',').filter(Boolean);
                document.querySelectorAll('.priority-category').forEach(cat => {
                    if (collapsed.includes(cat.dataset.category)) cat.classList.add('collapsed');
                    else cat.classList.remove('collapsed');
                });
            };
            window.__persistPrioState = function () {
                const collapsed = Array.from(document.querySelectorAll('.priority-category.collapsed'))
                    .map(c => c.dataset.category)
                    .filter(Boolean);
                const h = parseVerwaltungHash();
                if (collapsed.length) h.collapsed = collapsed.join(',');
                else delete h.collapsed;
                writeVerwaltungHash(h);
            };
            window.__restorePrioState();

            // Live-Suchfilter: Treffer in jeder Spalte nach oben, Rest bleibt ausgegraut.
            // Beim ersten Aufruf wird die Ursprungsreihenfolge pro Karte festgehalten,
            // damit ein leerer Suchbegriff die Original-Sortierung wiederherstellt.
            const searchInput = document.getElementById('prioritization-search');
            if (searchInput) {
                // Ursprungsreihenfolge pro Karte merken (einmalig)
                document.querySelectorAll('.priority-column').forEach(column => {
                    Array.from(column.querySelectorAll('.draggable-project-card')).forEach((card, idx) => {
                        if (!('origIndex' in card.dataset)) card.dataset.origIndex = String(idx);
                    });
                });

                searchInput.addEventListener('input', function () {
                    const term = this.value.toLowerCase().trim();
                    document.querySelectorAll('.priority-column').forEach(column => {
                        const cards = Array.from(column.querySelectorAll('.draggable-project-card'));
                        if (!term) {
                            // Ursprungsreihenfolge wiederherstellen, Opacity zurücksetzen
                            cards
                                .sort((a, b) => parseInt(a.dataset.origIndex, 10) - parseInt(b.dataset.origIndex, 10))
                                .forEach(card => {
                                    card.style.opacity = '1';
                                    column.appendChild(card);
                                });
                            return;
                        }
                        // Treffer nach oben, Nicht-Treffer ausgrauen und nach unten
                        cards
                            .map(card => ({
                                card,
                                match: card.textContent.toLowerCase().includes(term),
                                orig:  parseInt(card.dataset.origIndex, 10),
                            }))
                            .sort((a, b) => (a.match === b.match) ? (a.orig - b.orig) : (a.match ? -1 : 1))
                            .forEach(({ card, match }) => {
                                card.style.opacity = match ? '1' : '0.2';
                                column.appendChild(card);
                            });
                    });
                });
            }
        })();
    </script>
</body>
</html>
