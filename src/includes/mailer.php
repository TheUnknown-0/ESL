<?php
/**
 * E-Mail-Versand
 * Unterstützt SMTP (TLS/SSL) sowie PHP mail() als Fallback.
 */

require_once __DIR__ . '/../config.php';

/**
 * Sendet eine E-Mail via SMTP oder PHP mail()-Fallback.
 *
 * @param string $to      Empfänger-Adresse
 * @param string $subject Betreff
 * @param string $body    Nachrichtentext
 * @return bool True bei Erfolg
 */
function sendMail(string $to, string $subject, string $body): bool
{
    if (SMTP_HOST !== '') {
        return sendMailSmtp($to, $subject, $body);
    }

    // Fallback: PHP mail()
    $headers = [
        'From: ' . MAIL_FROM,
        'Content-Type: text/plain; charset=UTF-8',
        'X-Mailer: PHP/' . phpversion(),
    ];

    $result = mail($to, $subject, $body, implode("\r\n", $headers));

    if (!$result) {
        error_log('E-Mail-Versand (mail()) fehlgeschlagen an: ' . $to);
    }

    return $result;
}

/**
 * Sendet eine E-Mail direkt über einen SMTP-Server.
 * Unterstützt STARTTLS (Port 587) und SSL/TLS (Port 465).
 *
 * @param string $to      Empfänger-Adresse
 * @param string $subject Betreff
 * @param string $body    Nachrichtentext
 * @return bool True bei Erfolg
 */
function sendMailSmtp(string $to, string $subject, string $body): bool
{
    $host    = SMTP_HOST;
    $port    = SMTP_PORT;
    $secure  = strtolower(SMTP_SECURE);
    $user    = SMTP_USER;
    $pass    = SMTP_PASS;
    $from    = MAIL_FROM;

    // Verbindung aufbauen
    $socketAddress = ($secure === 'ssl')
        ? "ssl://$host:$port"
        : "tcp://$host:$port";

    $errno  = 0;
    $errstr = '';
    $socket = @stream_socket_client($socketAddress, $errno, $errstr, 10);

    if ($socket === false) {
        error_log("SMTP-Verbindung fehlgeschlagen ($host:$port): $errstr ($errno)");
        return false;
    }

    stream_set_timeout($socket, 10);

    $read = function () use ($socket): string {
        $response = '';
        while ($line = fgets($socket, 512)) {
            $response .= $line;
            // SMTP-Antwort ist vollständig, wenn das 4. Zeichen kein '-' ist
            if (isset($line[3]) && $line[3] !== '-') {
                break;
            }
        }
        return $response;
    };

    $write = function (string $cmd) use ($socket): void {
        fwrite($socket, $cmd . "\r\n");
    };

    $check = function (string $response, string $expected, string $step) use ($socket): bool {
        if (strpos($response, $expected) !== 0) {
            error_log("SMTP-Fehler bei $step: $response");
            fclose($socket);
            return false;
        }
        return true;
    };

    // Begrüßung lesen
    $resp = $read();
    if (!$check($resp, '220', 'CONNECT')) return false;

    // EHLO senden
    $write('EHLO ' . gethostname());
    $resp = $read();
    if (!$check($resp, '250', 'EHLO')) return false;

    // STARTTLS aktivieren wenn nötig
    if ($secure === 'tls') {
        $write('STARTTLS');
        $resp = $read();
        if (!$check($resp, '220', 'STARTTLS')) return false;

        if (!stream_socket_enable_crypto($socket, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) {
            error_log('SMTP STARTTLS-Aushandlung fehlgeschlagen');
            fclose($socket);
            return false;
        }

        // Nach TLS erneut EHLO
        $write('EHLO ' . gethostname());
        $resp = $read();
        if (!$check($resp, '250', 'EHLO nach TLS')) return false;
    }

    // Authentifizierung (nur wenn Zugangsdaten gesetzt)
    if ($user !== '' && $pass !== '') {
        $write('AUTH LOGIN');
        $resp = $read();
        if (!$check($resp, '334', 'AUTH LOGIN')) return false;

        $write(base64_encode($user));
        $resp = $read();
        if (!$check($resp, '334', 'AUTH USER')) return false;

        $write(base64_encode($pass));
        $resp = $read();
        if (!$check($resp, '235', 'AUTH PASS')) return false;
    }

    // Absender und Empfänger
    $write("MAIL FROM:<$from>");
    $resp = $read();
    if (!$check($resp, '250', 'MAIL FROM')) return false;

    $write("RCPT TO:<$to>");
    $resp = $read();
    if (!$check($resp, '250', 'RCPT TO')) return false;

    // Nachricht senden
    $write('DATA');
    $resp = $read();
    if (!$check($resp, '354', 'DATA')) return false;

    $encodedSubject = '=?UTF-8?B?' . base64_encode($subject) . '?=';
    $message  = "From: $from\r\n";
    $message .= "To: $to\r\n";
    $message .= "Subject: $encodedSubject\r\n";
    $message .= "MIME-Version: 1.0\r\n";
    $message .= "Content-Type: text/plain; charset=UTF-8\r\n";
    $message .= "Content-Transfer-Encoding: base64\r\n";
    $message .= "\r\n";
    $message .= chunk_split(base64_encode($body));
    $message .= "\r\n.";

    $write($message);
    $resp = $read();
    if (!$check($resp, '250', 'MESSAGE')) return false;

    $write('QUIT');
    fclose($socket);

    return true;
}

/**
 * Benachrichtigt alle Admins über einen neuen Vorschlag.
 * Wird nur aufgerufen wenn der Vorschlag NICHT anonym ist.
 *
 * @param string $projectName Name des Projekts
 * @param string $proposedBy  Name des Vorschlagenden
 */
function notifyAdminsNewProposal(string $projectName, string $proposedBy): void
{
    $db = getDB();

    $stmt = $db->prepare('SELECT email FROM users WHERE is_admin = 1 AND email_notifications = 1');
    $stmt->execute();
    $admins = $stmt->fetchAll();

    $subject = 'Neuer Vorschlag: ' . $projectName;
    $body    = "Ein neuer Vorschlag wurde eingereicht.\n\n"
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
 * @param int    $userId         ID des Vorschlagenden
 * @param string $projectName    Name des Projekts
 * @param string $status         Neuer Status
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
    $body    = "Ihr Vorschlag wurde bearbeitet.\n\n"
             . "Projekt: $projectName\n"
             . "Neuer Status: $status\n"
             . "Begründung: $decisionReason\n";

    sendMail($user['email'], $subject, $body);
}
