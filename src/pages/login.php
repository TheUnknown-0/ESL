<?php
/**
 * Login-Seite (Landing Page)
 * Formular mit Benutzername + Passwort, CSRF-Schutz und Brute-Force-Schutz.
 */

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/functions.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Bereits eingeloggt → zur Navigation weiterleiten
if (!empty($_SESSION['user_id'])) {
    header('Location: index.php?page=nav');
    exit;
}

$error = '';

// Login-Formular verarbeiten
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // CSRF-Token prüfen
    if (!validateCsrfToken()) {
        $error = 'Ungültige Anfrage. Bitte versuchen Sie es erneut.';
    } else {
        $username = trim($_POST['username'] ?? '');
        $password = $_POST['password'] ?? '';
        $ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';

        if ($username === '' || $password === '') {
            $error = 'Ungültige Anmeldedaten';
        } else {
            try {
                $db = getDB();

                // Brute-Force-Schutz: Prüfen ob IP+Username gesperrt
                if (isLoginLocked($db, $ip, $username)) {
                    $error = 'Zu viele Fehlversuche. Bitte warten Sie 15 Minuten.';
                } else {
                    // Benutzer in der Datenbank suchen
                    $stmt = $db->prepare('SELECT id, username, password_hash, is_admin FROM users WHERE username = ?');
                    $stmt->execute([$username]);
                    $user = $stmt->fetch();

                    if ($user && password_verify($password, $user['password_hash'])) {
                        // Erfolgreicher Login
                        session_regenerate_id(true);
                        $_SESSION['user_id'] = $user['id'];
                        $_SESSION['username'] = $user['username'];
                        $_SESSION['is_admin'] = $user['is_admin'];

                        // Fehlgeschlagene Versuche zurücksetzen
                        clearLoginAttempts($db, $ip, $username);

                        header('Location: index.php?page=nav');
                        exit;
                    } else {
                        // Fehlgeschlagener Login
                        recordFailedLogin($db, $ip, $username);
                        // Generische Fehlermeldung (kein Hinweis ob User oder Passwort falsch)
                        $error = 'Ungültige Anmeldedaten';
                    }
                }
            } catch (Exception $e) {
                error_log('Login-Fehler: ' . $e->getMessage());
                $error = 'Ein Fehler ist aufgetreten. Bitte versuchen Sie es später erneut.';
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Login – Schwarzes Brett</title>
    <script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="bg-gray-100 min-h-screen flex items-center justify-center">
    <div class="bg-white p-8 rounded-lg shadow-md w-full max-w-md">
        <h1 class="text-2xl font-bold text-center mb-6 text-gray-800">Anmeldung</h1>

        <?php if ($error): ?>
            <div class="bg-red-100 border border-red-400 text-red-700 px-4 py-3 rounded mb-4">
                <?= e($error) ?>
            </div>
        <?php endif; ?>

        <form method="POST" action="index.php?page=login">
            <?= csrfField() ?>

            <div class="mb-4">
                <label for="username" class="block text-gray-700 text-sm font-bold mb-2">Benutzername</label>
                <input type="text" id="username" name="username" required
                       class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-blue-500"
                       value="<?= e($_POST['username'] ?? '') ?>"
                       autocomplete="username">
            </div>

            <div class="mb-6">
                <label for="password" class="block text-gray-700 text-sm font-bold mb-2">Passwort</label>
                <input type="password" id="password" name="password" required
                       class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-blue-500"
                       autocomplete="current-password">
            </div>

            <button type="submit"
                    class="w-full bg-blue-600 text-white py-2 px-4 rounded-md hover:bg-blue-700 focus:outline-none focus:ring-2 focus:ring-blue-500 font-bold">
                Anmelden
            </button>
        </form>
    </div>
</body>
</html>
