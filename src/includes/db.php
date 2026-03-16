<?php
/**
 * PDO-Datenbankverbindung (Singleton)
 * Stellt eine einzelne PDO-Instanz für die gesamte Anwendung bereit.
 */

require_once __DIR__ . '/../config.php';

/**
 * Gibt die PDO-Datenbankinstanz zurück (Singleton-Pattern).
 *
 * @return PDO Die Datenbankverbindung
 */
function getDB(): PDO
{
    static $pdo = null;

    if ($pdo === null) {
        $dsn = sprintf(
            'mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4',
            DB_HOST,
            DB_PORT,
            DB_NAME
        );

        $pdo = new PDO($dsn, DB_USER, DB_PASS, [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ]);
    }

    return $pdo;
}
