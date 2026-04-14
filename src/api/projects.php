<?php
/**
 * AJAX-Endpunkt für Projekte (Live-Update + Upvotes + Kommentare)
 *
 * GET:  Gibt alle Projekte als JSON zurück (inkl. Priorität, Upvote-Zähler,
 *       user_has_upvoted, resolved features, Kommentare).
 *
 * POST ?action=prioritize      Setzt die Priorität eines Projekts (nur Admins).
 * POST ?action=upvote          Setzt einen Upvote (1 Stimme pro Nutzer/Projekt).
 * POST ?action=remove_upvote   Entfernt den eigenen Upvote.
 * POST ?action=add_comment     Legt einen Kommentar an.
 * POST ?action=delete_comment  Löscht einen Kommentar (Admin oder Autor).
 */

require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/functions.php';
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
$userId = (int)($_SESSION['user_id'] ?? 0);

/**
 * Liefert ein Projekt inkl. Override-Felder und Status.
 */
function loadProjectForFeatures(PDO $db, int $projectId): ?array
{
    $stmt = $db->prepare(
        'SELECT id, status, comments_enabled, comments_permission,
                upvotes_enabled, upvotes_permission
         FROM projects WHERE id = ?'
    );
    $stmt->execute([$projectId]);
    $row = $stmt->fetch();
    return $row ?: null;
}

/**
 * Einheitlicher CSRF-Check (Header bevorzugt, sonst Body).
 */
function verifyCsrf(array $input = []): bool
{
    $token = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? ($input['csrf_token'] ?? '');
    return hash_equals($_SESSION['csrf_token'] ?? '', (string)$token);
}

// ============================================================
// POST: Priorität setzen (nur Admins)
// ============================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'prioritize') {
    if (!isAdmin()) {
        http_response_code(403);
        echo json_encode(['success' => false, 'error' => 'Keine Berechtigung']);
        exit;
    }

    if (!verifyCsrf()) {
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
// POST: Upvote setzen / entfernen
// ============================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($action === 'upvote' || $action === 'remove_upvote')) {
    $input = json_decode(file_get_contents('php://input'), true) ?: [];
    if (!verifyCsrf($input)) {
        http_response_code(403);
        echo json_encode(['success' => false, 'error' => 'Ungültiger CSRF-Token']);
        exit;
    }

    $projectId = isset($input['project_id']) ? (int)$input['project_id'] : 0;
    if ($projectId <= 0) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Ungültige Projekt-ID']);
        exit;
    }

    try {
        $db = getDB();
        $project = loadProjectForFeatures($db, $projectId);
        if (!$project) {
            http_response_code(404);
            echo json_encode(['success' => false, 'error' => 'Projekt nicht gefunden']);
            exit;
        }

        $features = resolveProjectFeatures($project, getFeatureSettings($db));
        if (!canUpvote($features, isAdmin())) {
            http_response_code(403);
            echo json_encode(['success' => false, 'error' => 'Upvotes sind für dieses Projekt nicht erlaubt']);
            exit;
        }

        if ($action === 'upvote') {
            // INSERT IGNORE: bei Duplikat keine Fehlermeldung, nur keine neue Zeile
            $stmt = $db->prepare('INSERT IGNORE INTO project_upvotes (project_id, user_id) VALUES (?, ?)');
            $stmt->execute([$projectId, $userId]);
        } else {
            $stmt = $db->prepare('DELETE FROM project_upvotes WHERE project_id = ? AND user_id = ?');
            $stmt->execute([$projectId, $userId]);
        }

        // Aktuellen Zähler + eigenen Status zurückgeben
        $cnt = $db->prepare('SELECT COUNT(*) FROM project_upvotes WHERE project_id = ?');
        $cnt->execute([$projectId]);
        $upvoteCount = (int)$cnt->fetchColumn();

        $has = $db->prepare('SELECT 1 FROM project_upvotes WHERE project_id = ? AND user_id = ?');
        $has->execute([$projectId, $userId]);
        $userHasUpvoted = (bool)$has->fetchColumn();

        echo json_encode([
            'success'          => true,
            'project_id'       => $projectId,
            'upvote_count'     => $upvoteCount,
            'user_has_upvoted' => $userHasUpvoted,
        ]);
    } catch (Exception $e) {
        error_log('Upvote-Fehler: ' . $e->getMessage());
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
// POST: Kommentar hinzufügen
// ============================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'add_comment') {
    $input = json_decode(file_get_contents('php://input'), true) ?: [];
    if (!verifyCsrf($input)) {
        http_response_code(403);
        echo json_encode(['success' => false, 'error' => 'Ungültiger CSRF-Token']);
        exit;
    }

    $projectId = isset($input['project_id']) ? (int)$input['project_id'] : 0;
    $content   = trim((string)($input['content'] ?? ''));

    if ($projectId <= 0 || $content === '') {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Projekt und Kommentartext sind erforderlich']);
        exit;
    }

    // Harter Längen-Limit zum Schutz
    if (mb_strlen($content) > 2000) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Kommentar ist zu lang (max. 2000 Zeichen)']);
        exit;
    }

    try {
        $db = getDB();
        $project = loadProjectForFeatures($db, $projectId);
        if (!$project) {
            http_response_code(404);
            echo json_encode(['success' => false, 'error' => 'Projekt nicht gefunden']);
            exit;
        }

        $features = resolveProjectFeatures($project, getFeatureSettings($db));
        if (!canComment($features, isAdmin())) {
            http_response_code(403);
            echo json_encode(['success' => false, 'error' => 'Kommentieren ist für dieses Projekt nicht erlaubt']);
            exit;
        }

        $stmt = $db->prepare('INSERT INTO project_comments (project_id, user_id, content) VALUES (?, ?, ?)');
        $stmt->execute([$projectId, $userId, $content]);
        $commentId = (int)$db->lastInsertId();

        $stmt = $db->prepare(
            'SELECT c.id, c.project_id, c.content, c.created_at, c.user_id, u.username AS author
             FROM project_comments c LEFT JOIN users u ON c.user_id = u.id
             WHERE c.id = ?'
        );
        $stmt->execute([$commentId]);
        $comment = $stmt->fetch();

        echo json_encode([
            'success' => true,
            'comment' => [
                'id'         => (int)$comment['id'],
                'project_id' => (int)$comment['project_id'],
                'content'    => $comment['content'],
                'created_at' => $comment['created_at'],
                'user_id'    => $comment['user_id'] !== null ? (int)$comment['user_id'] : null,
                'author'     => $comment['author'] ?? 'Unbekannt',
            ],
        ]);
    } catch (Exception $e) {
        error_log('Comment-Fehler: ' . $e->getMessage());
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
// POST: Kommentar löschen (Admin oder Autor)
// ============================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'delete_comment') {
    $input = json_decode(file_get_contents('php://input'), true) ?: [];
    if (!verifyCsrf($input)) {
        http_response_code(403);
        echo json_encode(['success' => false, 'error' => 'Ungültiger CSRF-Token']);
        exit;
    }

    $commentId = isset($input['comment_id']) ? (int)$input['comment_id'] : 0;
    if ($commentId <= 0) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Ungültige Kommentar-ID']);
        exit;
    }

    try {
        $db = getDB();
        $stmt = $db->prepare('SELECT id, user_id FROM project_comments WHERE id = ?');
        $stmt->execute([$commentId]);
        $comment = $stmt->fetch();
        if (!$comment) {
            http_response_code(404);
            echo json_encode(['success' => false, 'error' => 'Kommentar nicht gefunden']);
            exit;
        }

        $ownerId = $comment['user_id'] !== null ? (int)$comment['user_id'] : null;
        if (!isAdmin() && $ownerId !== $userId) {
            http_response_code(403);
            echo json_encode(['success' => false, 'error' => 'Keine Berechtigung']);
            exit;
        }

        $stmt = $db->prepare('DELETE FROM project_comments WHERE id = ?');
        $stmt->execute([$commentId]);

        echo json_encode(['success' => true, 'comment_id' => $commentId]);
    } catch (Exception $e) {
        error_log('Comment-Delete-Fehler: ' . $e->getMessage());
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
// GET: Projekte abrufen (inkl. Features, Upvotes, Kommentare)
// ============================================================
try {
    $db = getDB();
    $isAdminUser = isAdmin();
    $globalSettings = getFeatureSettings($db);

    $stmt = $db->query(
        'SELECT p.id, p.name, p.description, p.reason, p.status, p.priority,
                p.comments_enabled, p.comments_permission,
                p.upvotes_enabled,  p.upvotes_permission,
                p.is_anonymous, p.decision_reason, p.created_at,
                u.username AS proposed_by_name
         FROM projects p
         LEFT JOIN users u ON p.proposed_by = u.id
         ORDER BY p.created_at DESC'
    );
    $projects = $stmt->fetchAll();

    $projectIds = array_map(fn($p) => (int)$p['id'], $projects);

    // Upvote-Zähler pro Projekt (ein Query)
    $upvoteCounts = [];
    $userUpvoted  = [];
    if ($projectIds) {
        $placeholders = implode(',', array_fill(0, count($projectIds), '?'));
        $stmt = $db->prepare("SELECT project_id, COUNT(*) AS cnt FROM project_upvotes
                              WHERE project_id IN ($placeholders) GROUP BY project_id");
        $stmt->execute($projectIds);
        foreach ($stmt->fetchAll() as $row) {
            $upvoteCounts[(int)$row['project_id']] = (int)$row['cnt'];
        }

        $params = array_merge($projectIds, [$userId]);
        $stmt = $db->prepare("SELECT project_id FROM project_upvotes
                              WHERE project_id IN ($placeholders) AND user_id = ?");
        $stmt->execute($params);
        foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $pid) {
            $userUpvoted[(int)$pid] = true;
        }
    }

    // Upvoter-Details pro Projekt (nur für Admins)
    $upvotersByProject = [];
    if ($isAdminUser && $projectIds) {
        $placeholders = implode(',', array_fill(0, count($projectIds), '?'));
        $stmt = $db->prepare(
            "SELECT pu.project_id, pu.user_id, u.username, pu.created_at
             FROM project_upvotes pu
             LEFT JOIN users u ON pu.user_id = u.id
             WHERE pu.project_id IN ($placeholders)
             ORDER BY pu.created_at ASC"
        );
        $stmt->execute($projectIds);
        foreach ($stmt->fetchAll() as $row) {
            $upvotersByProject[(int)$row['project_id']][] = [
                'user_id'    => (int)$row['user_id'],
                'username'   => $row['username'] ?? 'Unbekannt',
                'created_at' => $row['created_at'],
            ];
        }
    }

    // Kommentare pro Projekt (ein Query, in PHP gruppiert)
    $commentsByProject = [];
    if ($projectIds) {
        $placeholders = implode(',', array_fill(0, count($projectIds), '?'));
        $stmt = $db->prepare(
            "SELECT c.id, c.project_id, c.user_id, c.content, c.created_at, u.username AS author
             FROM project_comments c LEFT JOIN users u ON c.user_id = u.id
             WHERE c.project_id IN ($placeholders)
             ORDER BY c.created_at ASC"
        );
        $stmt->execute($projectIds);
        foreach ($stmt->fetchAll() as $c) {
            $commentsByProject[(int)$c['project_id']][] = [
                'id'         => (int)$c['id'],
                'user_id'    => $c['user_id'] !== null ? (int)$c['user_id'] : null,
                'author'     => $c['author'] ?? 'Unbekannt',
                'content'    => $c['content'],
                'created_at' => $c['created_at'],
            ];
        }
    }

    $result = array_map(function ($p) use ($globalSettings, $isAdminUser, $upvoteCounts, $userUpvoted, $commentsByProject, $upvotersByProject) {
        $id       = (int)$p['id'];
        $features = resolveProjectFeatures($p, $globalSettings);
        return [
            'id'               => $id,
            'name'             => $p['name'],
            'description'      => $p['description'],
            'reason'           => $p['reason'],
            'status'           => $p['status'],
            'priority'         => $p['priority'],
            'is_anonymous'     => (int)$p['is_anonymous'],
            'proposed_by_name' => $p['proposed_by_name'],
            'decision_reason'  => $p['decision_reason'],
            'created_at'       => $p['created_at'] ?? null,
            'features'         => [
                'comments_enabled'    => $features['comments_enabled'],
                'comments_permission' => $features['comments_permission'],
                'upvotes_enabled'     => $features['upvotes_enabled'],
                'upvotes_permission'  => $features['upvotes_permission'],
                'can_comment'         => canComment($features, $isAdminUser),
                'can_upvote'          => canUpvote($features, $isAdminUser),
            ],
            'upvote_count'     => $upvoteCounts[$id] ?? 0,
            'user_has_upvoted' => !empty($userUpvoted[$id]),
            'upvoters'         => $isAdminUser ? ($upvotersByProject[$id] ?? []) : null,
            'comments'         => $commentsByProject[$id] ?? [],
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
