<?php
/**
 * AJAX-Endpunkt für Projekte (Live-Update)
 * GET:  Gibt alle Projekte als JSON zurück.
 * POST ?action=prioritize: Setzt die Priorität eines Projekts (nur Admins).
 */

require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../auth.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

header('Content-Type: application/json');

// Nur für eingeloggte Benutzer
if (!isLoggedIn()) {
    http_response_code(401);
    echo json_encode(['error' => 'Nicht autorisiert']);
    exit;
}

$action = $_GET['action'] ?? '';

// ============================================================
// POST: Priorität setzen (nur Admins)
// ============================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'prioritize') {
    if (!isAdmin()) {
        http_response_code(403);
        echo json_encode(['success' => false, 'error' => 'Keine Berechtigung']);
        exit;
    }

    // CSRF aus Header oder Body
    $csrfToken = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
    if (!hash_equals($_SESSION['csrf_token'] ?? '', $csrfToken)) {
        http_response_code(403);
        echo json_encode(['success' => false, 'error' => 'Ungültiger CSRF-Token']);
        exit;
    }

    $input = json_decode(file_get_contents('php://input'), true);
    if (!is_array($input)) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Ungültige Anfrage']);
        exit;
    }

    $projectId = isset($input['project_id']) ? (int)$input['project_id'] : 0;
    $priority  = array_key_exists('priority', $input) ? $input['priority'] : 'NULL_SENTINEL';

    // null explizit erlaubt (Karte in "Unpriorisiert" verschoben)
    if ($priority === null || $priority === 'null') {
        $priority = null;
    }

    $validPriorities = ['Hoch', 'Mittel', 'Niedrig', null];
    if (!in_array($priority, $validPriorities, true)) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Ungültige Priorität']);
        exit;
    }

    if ($projectId <= 0) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Ungültige Projekt-ID']);
        exit;
    }

    $priorisierbar = ['Vorgeschlagen', 'In Besprechung', 'In Bearbeitung'];

    try {
        $db = getDB();

        // Projekt laden und Status prüfen
        $stmt = $db->prepare('SELECT id, status FROM projects WHERE id = ?');
        $stmt->execute([$projectId]);
        $project = $stmt->fetch();

        if (!$project) {
            http_response_code(404);
            echo json_encode(['success' => false, 'error' => 'Projekt nicht gefunden']);
            exit;
        }

        if (!in_array($project['status'], $priorisierbar)) {
            http_response_code(422);
            echo json_encode(['success' => false, 'error' => 'Dieser Status kann nicht priorisiert werden']);
            exit;
        }

        $stmt = $db->prepare('UPDATE projects SET priority = ?, updated_at = NOW() WHERE id = ?');
        $stmt->execute([$priority, $projectId]);

        echo json_encode([
            'success'    => true,
            'message'    => 'Priorität aktualisiert',
            'project_id' => $projectId,
            'priority'   => $priority,
        ]);
    } catch (Exception $e) {
        error_log('Prioritize-Fehler: ' . $e->getMessage());
        http_response_code(500);
        $response = ['success' => false, 'error' => 'Interner Fehler'];
        if (!empty($_SESSION['is_admin']) && $_SESSION['is_admin'] == 1) {
            $response['debug'] = get_class($e) . ': ' . $e->getMessage()
                . ' (' . basename($e->getFile()) . ':' . $e->getLine() . ')';
        }
        echo json_encode($response);
    }
    exit;
}

// ============================================================
// GET: Projekte abrufen
// ============================================================
try {
    $db = getDB();
    $stmt = $db->query(
        'SELECT p.id, p.name, p.description, p.reason, p.status, p.priority, p.is_anonymous,
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
            'priority'         => $p['priority'],
            'is_anonymous'     => (int)$p['is_anonymous'],
            'proposed_by_name' => $p['proposed_by_name'],
            'decision_reason'  => $p['decision_reason'],
        ];
    }, $projects);

    echo json_encode($result, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
} catch (Exception $e) {
    error_log('API Projekte Fehler: ' . $e->getMessage());
    http_response_code(500);
    $response = ['error' => 'Interner Fehler'];
    if (!empty($_SESSION['is_admin']) && $_SESSION['is_admin'] == 1) {
        $response['debug'] = get_class($e) . ': ' . $e->getMessage()
            . ' (' . basename($e->getFile()) . ':' . $e->getLine() . ')';
    }
    echo json_encode($response);
}
