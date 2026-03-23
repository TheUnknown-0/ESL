<?php
/**
 * Vorschlag-Seite
 * Formular zum Einreichen neuer Projektvorschläge.
 */

require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/mailer.php';
require_once __DIR__ . '/../auth.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

requireLogin();

require_once __DIR__ . '/../includes/theme.php';

$success = '';
$error = '';
$clearForm = false;

// Formular verarbeiten
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!validateCsrfToken()) {
        $error = 'Ungültige Anfrage. Bitte versuchen Sie es erneut.';
    } else {
        $name = trim($_POST['name'] ?? '');
        $description = trim($_POST['description'] ?? '');
        $reason = trim($_POST['reason'] ?? '');
        $isAnonymous = isset($_POST['is_anonymous']) ? 1 : 0;

        // Validierung
        if ($name === '' || $description === '' || $reason === '') {
            $error = 'Bitte füllen Sie alle Pflichtfelder aus.';
        } else {
            try {
                $db = getDB();

                // Projekt einfügen
                $proposedBy = $isAnonymous ? null : $_SESSION['user_id'];
                $stmt = $db->prepare(
                    'INSERT INTO projects (name, description, reason, status, is_anonymous, proposed_by)
                     VALUES (?, ?, ?, ?, ?, ?)'
                );
                $stmt->execute([$name, $description, $reason, 'Vorgeschlagen', $isAnonymous, $proposedBy]);

                $success = 'Ihr Vorschlag wurde erfolgreich eingereicht!';
                $clearForm = true;

                // E-Mail-Benachrichtigung an Admins (nur wenn NICHT anonym)
                if (!$isAnonymous) {
                    notifyAdminsNewProposal($name, $_SESSION['username']);
                }
            } catch (Exception $e) {
                error_log('Vorschlag-Fehler: ' . $e->getMessage());
                $error = 'Ein Fehler ist aufgetreten. Bitte versuchen Sie es später erneut.';
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="de" class="<?= e($themeHtmlClasses) ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Vorschlag einreichen</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <?php outputThemeHead(); ?>
</head>
<body class="bg-gray-100 min-h-screen">
    <!-- Kopfzeile -->
    <header class="bg-white shadow">
        <div class="max-w-7xl mx-auto px-4 py-3 flex flex-wrap justify-between items-center gap-2">
            <h1 class="text-xl sm:text-2xl font-bold text-gray-800">Vorschlag einreichen</h1>
            <div class="flex flex-wrap gap-2">
                <a href="index.php?page=einstellungen" class="bg-gray-200 text-gray-700 px-3 py-2 rounded-md hover:bg-gray-300 text-sm">⚙️</a>
                <a href="index.php?page=nav" class="bg-gray-200 text-gray-700 px-3 py-2 rounded-md hover:bg-gray-300 text-sm">← Navigation</a>
                <a href="index.php?page=logout" class="bg-red-500 text-white px-3 py-2 rounded-md hover:bg-red-600 text-sm">Abmelden</a>
            </div>
        </div>
    </header>

    <main class="max-w-2xl mx-auto px-4 py-8">
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

        <div class="bg-white rounded-lg shadow-md p-6">
            <form method="POST" action="index.php?page=vorschlag">
                <?= csrfField() ?>

                <div class="mb-4">
                    <label for="name" class="block text-gray-700 text-sm font-bold mb-2">Projektname *</label>
                    <input type="text" id="name" name="name" required
                           class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-blue-500"
                           value="<?= $clearForm ? '' : e($_POST['name'] ?? '') ?>">
                </div>

                <div class="mb-4">
                    <label for="description" class="block text-gray-700 text-sm font-bold mb-2">Beschreibung *</label>
                    <textarea id="description" name="description" rows="4" required
                              class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-blue-500"><?= $clearForm ? '' : e($_POST['description'] ?? '') ?></textarea>
                </div>

                <div class="mb-4">
                    <label for="reason" class="block text-gray-700 text-sm font-bold mb-2">Begründung *</label>
                    <textarea id="reason" name="reason" rows="3" required
                              class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-blue-500"><?= $clearForm ? '' : e($_POST['reason'] ?? '') ?></textarea>
                </div>

                <div class="mb-6">
                    <label class="flex items-center">
                        <input type="checkbox" name="is_anonymous" value="1"
                               class="mr-2 rounded border-gray-300 text-blue-600 focus:ring-blue-500">
                        <span class="text-gray-700 text-sm">Anonym vorschlagen</span>
                    </label>
                </div>

                <button type="submit"
                        class="w-full bg-blue-600 text-white py-2 px-4 rounded-md hover:bg-blue-700 focus:outline-none focus:ring-2 focus:ring-blue-500 font-bold">
                    Vorschlag einreichen
                </button>
            </form>
        </div>
    </main>
</body>
</html>
