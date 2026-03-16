<?php
/**
 * Verwaltungsseite (nur für Admins)
 * Enthält Projektverwaltung und Nutzerverwaltung.
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

$db = getDB();
$success = '';
$error = '';

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
            $projectId = (int)($_POST['project_id'] ?? 0);
            $name = trim($_POST['name'] ?? '');
            $description = trim($_POST['description'] ?? '');
            $reason = trim($_POST['reason'] ?? '');
            $status = $_POST['status'] ?? '';
            $decisionReason = trim($_POST['decision_reason'] ?? '');

            // Validierung
            $validStatuses = ['Vorgeschlagen', 'In Besprechung', 'In Bearbeitung', 'Umgesetzt', 'Angenommen', 'Abgelehnt'];
            if ($name === '' || $description === '' || $reason === '' || !in_array($status, $validStatuses)) {
                $error = 'Bitte füllen Sie alle Pflichtfelder aus.';
            } elseif (in_array($status, ['Angenommen', 'Abgelehnt']) && $decisionReason === '') {
                $error = 'Bei Annahme oder Ablehnung ist eine Begründung erforderlich.';
            } else {
                try {
                    // Alten Status laden für Benachrichtigungslogik
                    $stmtOld = $db->prepare('SELECT status, proposed_by, is_anonymous FROM projects WHERE id = ?');
                    $stmtOld->execute([$projectId]);
                    $oldProject = $stmtOld->fetch();

                    $stmt = $db->prepare(
                        'UPDATE projects SET name = ?, description = ?, reason = ?, status = ?,
                         decision_reason = ?, decided_by = ?, updated_at = NOW()
                         WHERE id = ?'
                    );
                    $decisionReasonValue = in_array($status, ['Angenommen', 'Abgelehnt']) ? $decisionReason : null;
                    $decidedByValue = in_array($status, ['Angenommen', 'Abgelehnt']) ? $_SESSION['user_id'] : null;
                    $stmt->execute([$name, $description, $reason, $status, $decisionReasonValue, $decidedByValue, $projectId]);

                    $success = 'Projekt erfolgreich aktualisiert.';

                    // E-Mail bei Statusänderung auf Angenommen/Abgelehnt
                    if ($oldProject && in_array($status, ['Angenommen', 'Abgelehnt'])
                        && $oldProject['status'] !== $status
                        && !$oldProject['is_anonymous']
                        && $oldProject['proposed_by']) {
                        notifyProposerDecision(
                            (int)$oldProject['proposed_by'],
                            $name,
                            $status,
                            $decisionReason
                        );
                    }
                } catch (Exception $e) {
                    error_log('Projekt-Update-Fehler: ' . $e->getMessage());
                    $error = 'Fehler beim Aktualisieren des Projekts.';
                }
            }
        }

        // --- Nutzer anlegen ---
        if ($action === 'create_user') {
            $username = trim($_POST['username'] ?? '');
            $email = trim($_POST['email'] ?? '');
            $password = $_POST['password'] ?? '';
            $isAdmin = isset($_POST['is_admin']) ? 1 : 0;
            $emailNotifications = isset($_POST['email_notifications']) ? 1 : 0;

            if ($username === '' || $email === '' || $password === '') {
                $error = 'Bitte füllen Sie alle Pflichtfelder aus.';
            } else {
                try {
                    $hash = password_hash($password, PASSWORD_BCRYPT);
                    $stmt = $db->prepare(
                        'INSERT INTO users (username, password_hash, email, is_admin, email_notifications)
                         VALUES (?, ?, ?, ?, ?)'
                    );
                    $stmt->execute([$username, $hash, $email, $isAdmin, $emailNotifications]);
                    $success = 'Nutzer erfolgreich angelegt.';
                } catch (PDOException $e) {
                    if ($e->getCode() == '23000') {
                        $error = 'Benutzername existiert bereits.';
                    } else {
                        error_log('Nutzer-Fehler: ' . $e->getMessage());
                        $error = 'Fehler beim Anlegen des Nutzers.';
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
                    $error = 'Fehler beim Löschen des Nutzers.';
                }
            }
        }

        // --- Passwort zurücksetzen ---
        if ($action === 'reset_password') {
            $userId = (int)($_POST['user_id'] ?? 0);
            $newPassword = $_POST['new_password'] ?? '';

            if ($userId > 0 && $newPassword !== '') {
                try {
                    $hash = password_hash($newPassword, PASSWORD_BCRYPT);
                    $stmt = $db->prepare('UPDATE users SET password_hash = ? WHERE id = ?');
                    $stmt->execute([$hash, $userId]);
                    $success = 'Passwort erfolgreich zurückgesetzt.';
                } catch (Exception $e) {
                    error_log('Passwort-Reset-Fehler: ' . $e->getMessage());
                    $error = 'Fehler beim Zurücksetzen des Passworts.';
                }
            } else {
                $error = 'Bitte geben Sie ein neues Passwort ein.';
            }
        }

        // --- E-Mail bearbeiten ---
        if ($action === 'update_email') {
            $userId = (int)($_POST['user_id'] ?? 0);
            $newEmail = trim($_POST['new_email'] ?? '');

            if ($userId > 0 && $newEmail !== '') {
                try {
                    $stmt = $db->prepare('UPDATE users SET email = ? WHERE id = ?');
                    $stmt->execute([$newEmail, $userId]);
                    $success = 'E-Mail-Adresse erfolgreich aktualisiert.';
                } catch (Exception $e) {
                    error_log('E-Mail-Update-Fehler: ' . $e->getMessage());
                    $error = 'Fehler beim Aktualisieren der E-Mail-Adresse.';
                }
            } else {
                $error = 'Bitte geben Sie eine gültige E-Mail-Adresse ein.';
            }
        }
    }
}

// ============================================================
// Daten laden
// ============================================================

// Alle Projekte laden
try {
    $stmtProjects = $db->query(
        'SELECT p.*, u.username AS proposed_by_name
         FROM projects p
         LEFT JOIN users u ON p.proposed_by = u.id
         ORDER BY p.created_at DESC'
    );
    $projects = $stmtProjects->fetchAll();
} catch (Exception $e) {
    error_log('Verwaltung Projekte Fehler: ' . $e->getMessage());
    $projects = [];
}

// Alle Nutzer laden
try {
    $stmtUsers = $db->query('SELECT * FROM users ORDER BY username ASC');
    $users = $stmtUsers->fetchAll();
} catch (Exception $e) {
    error_log('Verwaltung Nutzer Fehler: ' . $e->getMessage());
    $users = [];
}

$validStatuses = ['Vorgeschlagen', 'In Besprechung', 'In Bearbeitung', 'Umgesetzt', 'Angenommen', 'Abgelehnt'];
?>
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Verwaltung</title>
    <script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="bg-gray-100 min-h-screen">
    <!-- Kopfzeile -->
    <header class="bg-white shadow">
        <div class="max-w-7xl mx-auto px-4 py-4 flex justify-between items-center">
            <h1 class="text-2xl font-bold text-gray-800">Verwaltung</h1>
            <div class="flex gap-3">
                <a href="index.php?page=nav" class="bg-gray-200 text-gray-700 px-4 py-2 rounded-md hover:bg-gray-300 text-sm">← Navigation</a>
                <a href="index.php?page=logout" class="bg-red-500 text-white px-4 py-2 rounded-md hover:bg-red-600 text-sm">Abmelden</a>
            </div>
        </div>
    </header>

    <main class="max-w-7xl mx-auto px-4 py-8">
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
            <nav class="flex space-x-4">
                <button onclick="showTab('projects')" id="tab-projects"
                        class="px-4 py-2 rounded-md bg-blue-600 text-white font-bold">Projektverwaltung</button>
                <button onclick="showTab('users')" id="tab-users"
                        class="px-4 py-2 rounded-md bg-gray-200 text-gray-700 font-bold">Nutzerverwaltung</button>
            </nav>
        </div>

        <!-- ============================================================ -->
        <!-- Projektverwaltung -->
        <!-- ============================================================ -->
        <div id="panel-projects">
            <div class="bg-white rounded-lg shadow-md overflow-hidden">
                <table class="w-full text-left">
                    <thead class="bg-gray-50">
                        <tr>
                            <th class="px-4 py-3 text-sm font-semibold text-gray-700">Name</th>
                            <th class="px-4 py-3 text-sm font-semibold text-gray-700">Status</th>
                            <th class="px-4 py-3 text-sm font-semibold text-gray-700">Vorgeschlagen von</th>
                            <th class="px-4 py-3 text-sm font-semibold text-gray-700">Datum</th>
                            <th class="px-4 py-3 text-sm font-semibold text-gray-700">Aktion</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-200">
                        <?php foreach ($projects as $project): ?>
                            <tr>
                                <td class="px-4 py-3"><?= e($project['name']) ?></td>
                                <td class="px-4 py-3">
                                    <span class="inline-block px-2 py-1 rounded-full text-xs font-medium <?= getStatusColor($project['status']) ?>">
                                        <?= e($project['status']) ?>
                                    </span>
                                </td>
                                <td class="px-4 py-3">
                                    <?= $project['is_anonymous'] ? 'Anonym' : e($project['proposed_by_name'] ?? 'Unbekannt') ?>
                                </td>
                                <td class="px-4 py-3 text-sm text-gray-500"><?= e($project['created_at']) ?></td>
                                <td class="px-4 py-3">
                                    <button onclick="openEditProject(<?= (int)$project['id'] ?>)"
                                            class="bg-blue-500 text-white px-3 py-1 rounded text-sm hover:bg-blue-600">
                                        Bearbeiten
                                    </button>
                                </td>
                            </tr>
                        <?php endforeach; ?>

                        <?php if (empty($projects)): ?>
                            <tr>
                                <td colspan="5" class="px-4 py-6 text-center text-gray-500">Keine Projekte vorhanden.</td>
                            </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- ============================================================ -->
        <!-- Nutzerverwaltung -->
        <!-- ============================================================ -->
        <div id="panel-users" class="hidden">
            <!-- Nutzer anlegen -->
            <div class="bg-white rounded-lg shadow-md p-6 mb-6">
                <h2 class="text-lg font-bold text-gray-800 mb-4">Neuen Nutzer anlegen</h2>
                <form method="POST" action="index.php?page=verwaltung" class="grid grid-cols-1 md:grid-cols-2 gap-4">
                    <?= csrfField() ?>
                    <input type="hidden" name="action" value="create_user">

                    <div>
                        <label class="block text-sm font-bold text-gray-700 mb-1">Benutzername *</label>
                        <input type="text" name="username" required
                               class="w-full px-3 py-2 border border-gray-300 rounded-md focus:ring-2 focus:ring-blue-500">
                    </div>
                    <div>
                        <label class="block text-sm font-bold text-gray-700 mb-1">E-Mail *</label>
                        <input type="email" name="email" required
                               class="w-full px-3 py-2 border border-gray-300 rounded-md focus:ring-2 focus:ring-blue-500">
                    </div>
                    <div>
                        <label class="block text-sm font-bold text-gray-700 mb-1">Passwort *</label>
                        <input type="password" name="password" required
                               class="w-full px-3 py-2 border border-gray-300 rounded-md focus:ring-2 focus:ring-blue-500">
                    </div>
                    <div class="flex items-end gap-4">
                        <label class="flex items-center">
                            <input type="checkbox" name="is_admin" value="1" class="mr-2">
                            <span class="text-sm text-gray-700">Admin</span>
                        </label>
                        <label class="flex items-center">
                            <input type="checkbox" name="email_notifications" value="1" checked class="mr-2">
                            <span class="text-sm text-gray-700">E-Mail-Benachrichtigungen</span>
                        </label>
                        <button type="submit" class="bg-green-600 text-white px-4 py-2 rounded-md hover:bg-green-700 font-bold">
                            Anlegen
                        </button>
                    </div>
                </form>
            </div>

            <!-- Nutzerliste -->
            <div class="bg-white rounded-lg shadow-md overflow-hidden">
                <table class="w-full text-left">
                    <thead class="bg-gray-50">
                        <tr>
                            <th class="px-4 py-3 text-sm font-semibold text-gray-700">Benutzername</th>
                            <th class="px-4 py-3 text-sm font-semibold text-gray-700">E-Mail</th>
                            <th class="px-4 py-3 text-sm font-semibold text-gray-700">Admin</th>
                            <th class="px-4 py-3 text-sm font-semibold text-gray-700">Benachrichtigungen</th>
                            <th class="px-4 py-3 text-sm font-semibold text-gray-700">Aktionen</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-200">
                        <?php foreach ($users as $user): ?>
                            <tr>
                                <td class="px-4 py-3 font-medium"><?= e($user['username']) ?></td>
                                <td class="px-4 py-3"><?= e($user['email']) ?></td>
                                <td class="px-4 py-3">
                                    <?= $user['is_admin'] ? '<span class="text-green-600 font-bold">Ja</span>' : '<span class="text-gray-400">Nein</span>' ?>
                                </td>
                                <td class="px-4 py-3">
                                    <?= $user['email_notifications'] ? '<span class="text-green-600">Aktiv</span>' : '<span class="text-gray-400">Inaktiv</span>' ?>
                                </td>
                                <td class="px-4 py-3 space-x-2">
                                    <button onclick="openResetPassword(<?= (int)$user['id'] ?>, '<?= e($user['username']) ?>')"
                                            class="bg-yellow-500 text-white px-2 py-1 rounded text-xs hover:bg-yellow-600">
                                        Passwort
                                    </button>
                                    <button onclick="openUpdateEmail(<?= (int)$user['id'] ?>, '<?= e($user['username']) ?>', '<?= e($user['email']) ?>')"
                                            class="bg-blue-500 text-white px-2 py-1 rounded text-xs hover:bg-blue-600">
                                        E-Mail
                                    </button>
                                    <?php if ((int)$user['id'] !== (int)$_SESSION['user_id']): ?>
                                        <button onclick="confirmDeleteUser(<?= (int)$user['id'] ?>, '<?= e($user['username']) ?>')"
                                                class="bg-red-500 text-white px-2 py-1 rounded text-xs hover:bg-red-600">
                                            Löschen
                                        </button>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </main>

    <!-- ============================================================ -->
    <!-- Projekt bearbeiten Modal -->
    <!-- ============================================================ -->
    <div id="edit-project-modal" class="fixed inset-0 bg-black bg-opacity-50 hidden z-50 flex items-center justify-center">
        <div class="bg-white rounded-lg shadow-xl max-w-lg w-full mx-4 max-h-[90vh] overflow-y-auto">
            <div class="p-6">
                <div class="flex justify-between items-start mb-4">
                    <h2 class="text-xl font-bold text-gray-800">Projekt bearbeiten</h2>
                    <button onclick="closeEditProject()" class="text-gray-400 hover:text-gray-600 text-2xl">&times;</button>
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
                        <label class="block text-sm font-bold text-gray-700 mb-1">Begründung der Entscheidung *</label>
                        <textarea name="decision_reason" id="edit-project-decision-reason" rows="2"
                                  class="w-full px-3 py-2 border border-gray-300 rounded-md focus:ring-2 focus:ring-blue-500"></textarea>
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
    <div id="reset-password-modal" class="fixed inset-0 bg-black bg-opacity-50 hidden z-50 flex items-center justify-center">
        <div class="bg-white rounded-lg shadow-xl max-w-md w-full mx-4">
            <div class="p-6">
                <div class="flex justify-between items-start mb-4">
                    <h2 class="text-xl font-bold text-gray-800">Passwort zurücksetzen</h2>
                    <button onclick="closeResetPassword()" class="text-gray-400 hover:text-gray-600 text-2xl">&times;</button>
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
    <div id="update-email-modal" class="fixed inset-0 bg-black bg-opacity-50 hidden z-50 flex items-center justify-center">
        <div class="bg-white rounded-lg shadow-xl max-w-md w-full mx-4">
            <div class="p-6">
                <div class="flex justify-between items-start mb-4">
                    <h2 class="text-xl font-bold text-gray-800">E-Mail bearbeiten</h2>
                    <button onclick="closeUpdateEmail()" class="text-gray-400 hover:text-gray-600 text-2xl">&times;</button>
                </div>
                <p class="text-gray-600 mb-4">E-Mail ändern für: <strong id="email-username"></strong></p>
                <form method="POST" action="index.php?page=verwaltung">
                    <?= csrfField() ?>
                    <input type="hidden" name="action" value="update_email">
                    <input type="hidden" name="user_id" id="email-user-id">

                    <div class="mb-4">
                        <label class="block text-sm font-bold text-gray-700 mb-1">Neue E-Mail-Adresse *</label>
                        <input type="email" name="new_email" id="email-current" required
                               class="w-full px-3 py-2 border border-gray-300 rounded-md focus:ring-2 focus:ring-blue-500">
                    </div>

                    <button type="submit" class="w-full bg-blue-600 text-white py-2 px-4 rounded-md hover:bg-blue-700 font-bold">
                        E-Mail aktualisieren
                    </button>
                </form>
            </div>
        </div>
    </div>

    <!-- ============================================================ -->
    <!-- Nutzer löschen (verstecktes Formular) -->
    <!-- ============================================================ -->
    <form id="delete-user-form" method="POST" action="index.php?page=verwaltung" class="hidden">
        <?= csrfField() ?>
        <input type="hidden" name="action" value="delete_user">
        <input type="hidden" name="user_id" id="delete-user-id">
    </form>

    <script>
        // Projektdaten für das Bearbeiten-Modal
        const projectsData = <?= json_encode(array_map(function($p) {
            return [
                'id' => (int)$p['id'],
                'name' => $p['name'],
                'description' => $p['description'],
                'reason' => $p['reason'],
                'status' => $p['status'],
                'decision_reason' => $p['decision_reason'] ?? '',
            ];
        }, $projects), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;

        /**
         * Tab-Umschaltung zwischen Projekt- und Nutzerverwaltung
         */
        function showTab(tab) {
            document.getElementById('panel-projects').classList.toggle('hidden', tab !== 'projects');
            document.getElementById('panel-users').classList.toggle('hidden', tab !== 'users');
            document.getElementById('tab-projects').className = tab === 'projects'
                ? 'px-4 py-2 rounded-md bg-blue-600 text-white font-bold'
                : 'px-4 py-2 rounded-md bg-gray-200 text-gray-700 font-bold';
            document.getElementById('tab-users').className = tab === 'users'
                ? 'px-4 py-2 rounded-md bg-blue-600 text-white font-bold'
                : 'px-4 py-2 rounded-md bg-gray-200 text-gray-700 font-bold';
        }

        /**
         * Öffnet das Modal zum Bearbeiten eines Projekts.
         */
        function openEditProject(projectId) {
            const project = projectsData.find(p => p.id === projectId);
            if (!project) return;

            document.getElementById('edit-project-id').value = project.id;
            document.getElementById('edit-project-name').value = project.name;
            document.getElementById('edit-project-description').value = project.description;
            document.getElementById('edit-project-reason').value = project.reason;
            document.getElementById('edit-project-status').value = project.status;
            document.getElementById('edit-project-decision-reason').value = project.decision_reason || '';

            toggleDecisionReason();
            document.getElementById('edit-project-modal').classList.remove('hidden');
        }

        function closeEditProject() {
            document.getElementById('edit-project-modal').classList.add('hidden');
        }

        /**
         * Zeigt/versteckt das Feld für die Entscheidungsbegründung
         * basierend auf dem ausgewählten Status.
         */
        function toggleDecisionReason() {
            const status = document.getElementById('edit-project-status').value;
            const group = document.getElementById('decision-reason-group');
            const textarea = document.getElementById('edit-project-decision-reason');

            if (status === 'Angenommen' || status === 'Abgelehnt') {
                group.classList.remove('hidden');
                textarea.required = true;
            } else {
                group.classList.add('hidden');
                textarea.required = false;
            }
        }

        /**
         * Öffnet das Modal zum Zurücksetzen eines Passworts.
         */
        function openResetPassword(userId, username) {
            document.getElementById('reset-user-id').value = userId;
            document.getElementById('reset-username').textContent = username;
            document.getElementById('reset-password-modal').classList.remove('hidden');
        }

        function closeResetPassword() {
            document.getElementById('reset-password-modal').classList.add('hidden');
        }

        /**
         * Öffnet das Modal zum Bearbeiten einer E-Mail-Adresse.
         */
        function openUpdateEmail(userId, username, currentEmail) {
            document.getElementById('email-user-id').value = userId;
            document.getElementById('email-username').textContent = username;
            document.getElementById('email-current').value = currentEmail;
            document.getElementById('update-email-modal').classList.remove('hidden');
        }

        function closeUpdateEmail() {
            document.getElementById('update-email-modal').classList.add('hidden');
        }

        /**
         * Bestätigungsdialog zum Löschen eines Nutzers.
         */
        function confirmDeleteUser(userId, username) {
            if (confirm('Möchten Sie den Nutzer "' + username + '" wirklich löschen?')) {
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

        // Escape-Taste schließt alle Modals
        document.addEventListener('keydown', function(e) {
            if (e.key === 'Escape') {
                closeEditProject();
                closeResetPassword();
                closeUpdateEmail();
            }
        });
    </script>
</body>
</html>
