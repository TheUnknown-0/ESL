<?php
/**
 * E-Mail-Versand
 * Nutzt PHP mail() für den E-Mail-Versand.
 */

require_once __DIR__ . '/../config.php';

/**
 * Sendet eine E-Mail.
 *
 * @param string $to Empfänger-Adresse
 * @param string $subject Betreff
 * @param string $body Nachrichtentext
 * @return bool True bei Erfolg
 */
function sendMail(string $to, string $subject, string $body): bool
{
    $headers = [
        'From: ' . MAIL_FROM,
        'Content-Type: text/plain; charset=UTF-8',
        'X-Mailer: PHP/' . phpversion(),
    ];

    return @mail($to, $subject, $body, implode("\r\n", $headers));
}

/**
 * Benachrichtigt alle Admins über einen neuen Vorschlag.
 * Wird nur aufgerufen wenn der Vorschlag NICHT anonym ist.
 *
 * @param string $projectName Name des Projekts
 * @param string $proposedBy Name des Vorschlagenden
 */
function notifyAdminsNewProposal(string $projectName, string $proposedBy): void
{
    $db = getDB();

    // Alle Admins mit aktivierten E-Mail-Benachrichtigungen abrufen
    $stmt = $db->prepare('SELECT email FROM users WHERE is_admin = 1 AND email_notifications = 1');
    $stmt->execute();
    $admins = $stmt->fetchAll();

    $subject = 'Neuer Vorschlag: ' . $projectName;
    $body = "Ein neuer Vorschlag wurde eingereicht.\n\n"
          . "Projekt: $projectName\n"
          . "Vorgeschlagen von: $proposedBy\n\n"
          . "Bitte prüfen Sie den Vorschlag in der Verwaltung.";

    foreach ($admins as $admin) {
        sendMail($admin['email'], $subject, $body);
    }
}

/**
 * Benachrichtigt den Vorschlagenden über eine Entscheidung.
 * Wird nur aufgerufen wenn NICHT anonym und E-Mail-Benachrichtigungen aktiv.
 *
 * @param int    $userId        ID des Vorschlagenden
 * @param string $projectName   Name des Projekts
 * @param string $status        Neuer Status
 * @param string $decisionReason Begründung der Entscheidung
 */
function notifyProposerDecision(int $userId, string $projectName, string $status, string $decisionReason): void
{
    $db = getDB();

    $stmt = $db->prepare('SELECT email, email_notifications FROM users WHERE id = ?');
    $stmt->execute([$userId]);
    $user = $stmt->fetch();

    if (!$user || !$user['email_notifications']) {
        return;
    }

    $subject = "Ihr Vorschlag '$projectName' wurde $status";
    $body = "Ihr Vorschlag wurde bearbeitet.\n\n"
          . "Projekt: $projectName\n"
          . "Neuer Status: $status\n"
          . "Begründung: $decisionReason\n";

    sendMail($user['email'], $subject, $body);
}
