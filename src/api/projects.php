<?php
/**
 * AJAX-Endpunkt für Projekte (Live-Update)
 * Gibt alle Projekte als JSON zurück.
 */

require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../auth.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Nur für eingeloggte Benutzer
if (!isLoggedIn()) {
    http_response_code(401);
    header('Content-Type: application/json');
    echo json_encode(['error' => 'Nicht autorisiert']);
    exit;
}

header('Content-Type: application/json');

try {
    $db = getDB();
    $stmt = $db->query(
        'SELECT p.id, p.name, p.description, p.reason, p.status, p.is_anonymous,
                p.decision_reason, u.username AS proposed_by_name
         FROM projects p
         LEFT JOIN users u ON p.proposed_by = u.id
         ORDER BY p.created_at DESC'
    );
    $projects = $stmt->fetchAll();

    // Daten für JSON aufbereiten
    $result = array_map(function ($p) {
        return [
            'id'               => (int)$p['id'],
            'name'             => $p['name'],
            'description'      => $p['description'],
            'reason'           => $p['reason'],
            'status'           => $p['status'],
            'is_anonymous'     => (int)$p['is_anonymous'],
            'proposed_by_name' => $p['proposed_by_name'],
            'decision_reason'  => $p['decision_reason'],
        ];
    }, $projects);

    echo json_encode($result, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
} catch (Exception $e) {
    error_log('API Projekte Fehler: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['error' => 'Interner Fehler']);
}
