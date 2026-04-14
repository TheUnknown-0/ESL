<?php
/**
 * Schwarzes Brett
 * Zeigt alle Projekte als Karten-Grid an.
 * Filter: Name, Status, Beschreibung/Begründung, Priorität (je mit Checkbox).
 * Sortierung: Datum, Priorität, Upvotes.
 * Upvotes & Kommentare gemäß globaler Einstellung / Projekt-Override.
 * Live-Update alle 30 Sekunden via fetch().
 */

require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../auth.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

requireLogin();

require_once __DIR__ . '/../includes/theme.php';

$userId      = (int)($_SESSION['user_id'] ?? 0);
$isAdminUser = isAdmin();

// Projekte + zugehörige Upvotes/Kommentare laden
try {
    $db = getDB();
    $stmt = $db->query(
        'SELECT p.*, u.username AS proposed_by_name
         FROM projects p
         LEFT JOIN users u ON p.proposed_by = u.id
         ORDER BY p.created_at DESC'
    );
    $projects = $stmt->fetchAll();

    $globalSettings = getFeatureSettings($db);

    $projectIds = array_map(fn($p) => (int)$p['id'], $projects);
    $upvoteCounts = [];
    $userUpvoted  = [];
    $commentsByProject = [];

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
} catch (Exception $e) {
    error_log('Schwarzes Brett Fehler: ' . $e->getMessage());
    $projects          = [];
    $globalSettings    = getFeatureSettings($db ?? null);
    $upvoteCounts      = [];
    $userUpvoted       = [];
    $commentsByProject = [];
}
?>
<!DOCTYPE html>
<html lang="de" class="<?= e($themeHtmlClasses) ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Schwarzes Brett</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <?php outputThemeHead(); ?>
</head>
<body class="bg-gray-100 min-h-screen">
    <!-- Kopfzeile -->
    <header class="bg-white shadow">
        <div class="max-w-7xl mx-auto px-4 py-3 flex flex-wrap justify-between items-center gap-2">
            <h1 class="text-xl sm:text-2xl font-bold text-gray-800">Schwarzes Brett</h1>
            <div class="flex flex-wrap gap-2">
                <a href="index.php?page=einstellungen" class="bg-gray-200 text-gray-700 px-3 py-2 rounded-md hover:bg-gray-300 text-sm">⚙️</a>
                <a href="index.php?page=nav" class="bg-gray-200 text-gray-700 px-3 py-2 rounded-md hover:bg-gray-300 text-sm">← Navigation</a>
                <a href="index.php?page=logout" class="bg-red-500 text-white px-3 py-2 rounded-md hover:bg-red-600 text-sm">Abmelden</a>
            </div>
        </div>
    </header>

    <main class="max-w-7xl mx-auto px-4 py-6">

        <!-- Filter-Panel -->
        <div class="bg-white rounded-lg shadow-md p-4 mb-6">
            <div class="flex items-center justify-between mb-3">
                <h3 class="text-sm font-bold text-gray-700">Filter &amp; Sortierung</h3>
                <button onclick="clearFilter()" class="text-xs text-blue-600 hover:underline">Zurücksetzen</button>
            </div>
            <div class="flex flex-col sm:flex-row flex-wrap gap-3">
                <!-- Name -->
                <div class="flex items-center gap-2 min-w-0">
                    <input type="checkbox" id="f-name-en" class="filter-cb shrink-0" onchange="applyFilter()">
                    <label for="f-name-en" class="text-sm text-gray-600 whitespace-nowrap shrink-0">Name:</label>
                    <input type="text" id="f-name" placeholder="Suchen…"
                           class="px-2 py-1 border border-gray-300 rounded text-sm min-w-0 w-full sm:w-36"
                           oninput="applyFilter()">
                </div>
                <!-- Status -->
                <div class="flex items-center gap-2 min-w-0">
                    <input type="checkbox" id="f-status-en" class="filter-cb shrink-0" onchange="applyFilter()">
                    <label for="f-status-en" class="text-sm text-gray-600 whitespace-nowrap shrink-0">Status:</label>
                    <select id="f-status" class="px-2 py-1 border border-gray-300 rounded text-sm min-w-0" onchange="applyFilter()">
                        <option value="">Alle</option>
                        <option>Vorgeschlagen</option>
                        <option>In Besprechung</option>
                        <option>In Bearbeitung</option>
                        <option>Angenommen</option>
                        <option>Abgelehnt</option>
                    </select>
                </div>
                <!-- Priorität -->
                <div class="flex items-center gap-2 min-w-0">
                    <input type="checkbox" id="f-prio-en" class="filter-cb shrink-0" onchange="applyFilter()">
                    <label for="f-prio-en" class="text-sm text-gray-600 whitespace-nowrap shrink-0">Priorität:</label>
                    <select id="f-prio" class="px-2 py-1 border border-gray-300 rounded text-sm min-w-0" onchange="applyFilter()">
                        <option value="">Alle</option>
                        <option value="Hoch">Hoch</option>
                        <option value="Mittel">Mittel</option>
                        <option value="Niedrig">Niedrig</option>
                        <option value="Unpriorisiert">Unpriorisiert</option>
                    </select>
                </div>
                <!-- Beschreibung/Begründung -->
                <div class="flex items-center gap-2 min-w-0">
                    <input type="checkbox" id="f-text-en" class="filter-cb shrink-0" onchange="applyFilter()">
                    <label for="f-text-en" class="text-sm text-gray-600 whitespace-nowrap shrink-0">Beschreibung/Begründung:</label>
                    <input type="text" id="f-text" placeholder="Suchen…"
                           class="px-2 py-1 border border-gray-300 rounded text-sm min-w-0 w-full sm:w-36"
                           oninput="applyFilter()">
                </div>
                <!-- Sortierung -->
                <div class="flex items-center gap-2 min-w-0">
                    <label for="f-sort" class="text-sm text-gray-600 whitespace-nowrap shrink-0">Sortierung:</label>
                    <select id="f-sort" class="px-2 py-1 border border-gray-300 rounded text-sm min-w-0" onchange="applyFilter()">
                        <option value="date_desc">Datum (neu → alt)</option>
                        <option value="prio_desc">Priorität (hoch → niedrig)</option>
                        <option value="prio_asc">Priorität (niedrig → hoch)</option>
                        <option value="upvotes_desc">Upvotes (viel → wenig)</option>
                    </select>
                </div>
            </div>
        </div>

        <!-- Projekte Grid (wird via JS befüllt) -->
        <div id="projects-grid" class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-4 sm:gap-6">
            <div class="col-span-full text-center text-gray-400 py-8 text-sm">Lade Projekte…</div>
        </div>
    </main>

    <!-- Detail-Modal -->
    <div id="detail-modal" class="fixed inset-0 bg-black bg-opacity-50 hidden z-50 flex items-center justify-center p-4">
        <div class="bg-white rounded-lg shadow-xl w-full max-w-lg max-h-[90vh] overflow-y-auto">
            <div class="p-5 sm:p-6">
                <div class="flex justify-between items-start mb-4">
                    <h2 id="modal-name" class="text-xl font-bold text-gray-800 pr-4"></h2>
                    <button onclick="closeDetail()" class="text-gray-400 hover:text-gray-600 text-2xl shrink-0 leading-none">&times;</button>
                </div>
                <div class="space-y-3">
                    <div class="flex flex-wrap items-center gap-2">
                        <span id="modal-status" class="inline-block px-3 py-1 rounded-full text-sm font-medium"></span>
                        <span id="modal-priority" class="inline-block px-3 py-1 rounded-full text-sm font-medium hidden"></span>
                    </div>
                    <div>
                        <strong class="text-gray-700">Vorgeschlagen von:</strong>
                        <span id="modal-proposed-by" class="text-gray-600"></span>
                    </div>
                    <div>
                        <strong class="text-gray-700">Beschreibung:</strong>
                        <p id="modal-description" class="text-gray-600 mt-1 whitespace-pre-wrap"></p>
                    </div>
                    <div>
                        <strong class="text-gray-700">Begründung:</strong>
                        <p id="modal-reason" class="text-gray-600 mt-1 whitespace-pre-wrap"></p>
                    </div>
                    <div id="modal-decision-section" class="hidden border-t pt-3 mt-3">
                        <strong class="text-gray-700">Begründung der Entscheidung:</strong>
                        <p id="modal-decision-reason" class="text-gray-600 mt-1 whitespace-pre-wrap"></p>
                    </div>

                    <!-- Upvotes -->
                    <div id="modal-upvote-section" class="hidden border-t pt-3 mt-3">
                        <strong class="text-gray-700">Upvotes:</strong>
                        <div class="flex items-center gap-3 mt-2">
                            <button id="modal-upvote-btn" type="button"
                                    class="px-3 py-1.5 rounded-md text-sm font-medium border transition"
                                    onclick="toggleUpvote()">
                                👍 <span id="modal-upvote-count">0</span>
                            </button>
                            <span id="modal-upvote-hint" class="text-xs text-gray-500"></span>
                        </div>
                        <div id="modal-upvoters-list" class="hidden mt-2"></div>
                    </div>

                    <!-- Kommentare -->
                    <div id="modal-comments-section" class="hidden border-t pt-3 mt-3">
                        <strong class="text-gray-700">Kommentare (<span id="modal-comments-count">0</span>):</strong>
                        <div id="modal-comments-list" class="mt-2 space-y-2"></div>
                        <form id="modal-comment-form" class="mt-3 hidden" onsubmit="submitComment(event)">
                            <textarea id="modal-comment-input" rows="2" maxlength="2000" required
                                      placeholder="Kommentar schreiben…"
                                      class="w-full px-3 py-2 border border-gray-300 rounded-md focus:ring-2 focus:ring-blue-500 text-sm"></textarea>
                            <div class="flex justify-end mt-1">
                                <button type="submit"
                                        class="bg-blue-600 text-white px-3 py-1.5 rounded-md text-sm hover:bg-blue-700 font-medium">
                                    Senden
                                </button>
                            </div>
                        </form>
                        <p id="modal-comments-hint" class="text-xs text-gray-500 mt-2 hidden"></p>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- CSRF-Token für Fetch-Requests -->
    <input type="hidden" id="csrf-token" value="<?= e($_SESSION['csrf_token'] ?? '') ?>">

    <script>
        const CURRENT_USER_ID = <?= (int)$userId ?>;
        const IS_ADMIN        = <?= $isAdminUser ? 'true' : 'false' ?>;

        // Projektdaten als JSON (für Modal und Filter)
        let projectsData = <?= json_encode(array_map(function($p) use ($globalSettings, $upvoteCounts, $userUpvoted, $commentsByProject, $isAdminUser) {
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
                'proposed_by_name' => $p['proposed_by_name'] ?? null,
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
                'comments'         => $commentsByProject[$id] ?? [],
            ];
        }, $projects), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;

        const statusColors = {
            'Vorgeschlagen':  'bg-gray-200 text-gray-800',
            'In Besprechung': 'bg-yellow-200 text-yellow-800',
            'In Bearbeitung': 'bg-blue-200 text-blue-800',
            'Angenommen':     'bg-green-200 text-green-800',
            'Abgelehnt':      'bg-red-200 text-red-800',
        };

        const statusBorderColors = {
            'Vorgeschlagen':  'border-gray-400',
            'In Besprechung': 'border-yellow-400',
            'In Bearbeitung': 'border-blue-400',
            'Angenommen':     'border-green-400',
            'Abgelehnt':      'border-red-400',
        };

        const priorityStyles = {
            'Hoch':    { emoji: '🔴', cls: 'bg-red-100 text-red-800',       label: 'Hoch'    },
            'Mittel':  { emoji: '🟡', cls: 'bg-yellow-100 text-yellow-800', label: 'Mittel'  },
            'Niedrig': { emoji: '🟢', cls: 'bg-green-100 text-green-800',   label: 'Niedrig' },
        };

        // Für Sortierung: höhere Zahl = höhere Priorität, null = ganz unten
        const priorityRank = { 'Hoch': 3, 'Mittel': 2, 'Niedrig': 1 };

        function escapeHtml(str) {
            if (str === null || str === undefined) return '';
            const div = document.createElement('div');
            div.textContent = String(str);
            return div.innerHTML;
        }

        function formatDate(iso) {
            if (!iso) return '';
            const d = new Date(iso.replace(' ', 'T'));
            if (isNaN(d.getTime())) return escapeHtml(iso);
            return d.toLocaleString('de-DE', { dateStyle: 'short', timeStyle: 'short' });
        }

        /**
         * Rendert das Karten-Grid aus einem Array von Projekten.
         */
        function renderGrid(data) {
            const grid = document.getElementById('projects-grid');
            if (data.length === 0) {
                grid.innerHTML = '<div class="col-span-full text-center text-gray-500 py-12">' +
                    '<p class="text-lg">Keine Projekte gefunden.</p>' +
                    '<a href="index.php?page=vorschlag" class="text-blue-600 hover:underline mt-2 inline-block">Ersten Vorschlag einreichen →</a></div>';
                return;
            }
            grid.innerHTML = data.map(project => {
                const borderColor = statusBorderColors[project.status] || 'border-gray-300';
                const statusColor = statusColors[project.status] || 'bg-gray-100 text-gray-600';
                const proposedBy  = project.is_anonymous ? 'Anonym' : escapeHtml(project.proposed_by_name || 'Unbekannt');

                const prioMeta = priorityStyles[project.priority];
                const prioBadge = prioMeta
                    ? '<span class="inline-block px-2 py-0.5 rounded-full text-xs font-medium ' + prioMeta.cls + '">' +
                      prioMeta.emoji + ' ' + prioMeta.label + '</span>'
                    : '';

                const f = project.features || {};
                const upvotesVisible = f.upvotes_enabled;
                const upvoteBtn = upvotesVisible
                    ? ('<button type="button" onclick="event.stopPropagation(); toggleUpvoteFromCard(' + project.id + ')" ' +
                        'class="inline-flex items-center gap-1 px-2 py-0.5 rounded-full text-xs font-medium border transition ' +
                        (project.user_has_upvoted
                            ? 'bg-blue-100 text-blue-800 border-blue-300'
                            : 'bg-gray-100 text-gray-700 border-gray-300 hover:bg-gray-200') +
                        (f.can_upvote ? '' : ' opacity-60 cursor-not-allowed') + '"' +
                        (f.can_upvote ? '' : ' disabled') +
                        ' title="' + (f.can_upvote ? 'Upvote abgeben oder zurücknehmen' : 'Upvotes sind hier nicht erlaubt') + '">' +
                        '👍 <span>' + project.upvote_count + '</span></button>')
                    : '';

                const commentCount = (project.comments || []).length;
                const commentsVisible = f.comments_enabled || commentCount > 0;
                const commentBadge = commentsVisible
                    ? ('<span class="inline-flex items-center gap-1 px-2 py-0.5 rounded-full text-xs font-medium bg-gray-100 text-gray-700 border border-gray-300">' +
                        '💬 ' + commentCount + '</span>')
                    : '';

                return '<div class="project-card bg-white rounded-lg shadow-md border-l-4 ' + borderColor +
                    ' p-4 sm:p-5 cursor-pointer hover:shadow-lg transition-shadow active:scale-95"' +
                    ' onclick="showDetail(' + project.id + ')" data-project-id="' + project.id + '">' +
                    '<h3 class="text-base sm:text-lg font-semibold text-gray-800 mb-2">' + escapeHtml(project.name) + '</h3>' +
                    '<div class="flex flex-wrap items-center gap-2">' +
                        '<span class="inline-block px-3 py-1 rounded-full text-xs sm:text-sm font-medium ' + statusColor + '">' + escapeHtml(project.status) + '</span>' +
                        prioBadge +
                    '</div>' +
                    '<p class="text-gray-500 text-xs sm:text-sm mt-2">' + proposedBy + '</p>' +
                    (upvoteBtn || commentBadge
                        ? '<div class="flex items-center gap-2 mt-3">' + upvoteBtn + commentBadge + '</div>'
                        : '') +
                    '</div>';
            }).join('');
        }

        /**
         * Filtert projectsData, sortiert und rendert das Grid neu.
         */
        function applyFilter() {
            const nameEnabled   = document.getElementById('f-name-en').checked;
            const statusEnabled = document.getElementById('f-status-en').checked;
            const prioEnabled   = document.getElementById('f-prio-en').checked;
            const textEnabled   = document.getElementById('f-text-en').checked;

            const nameVal   = document.getElementById('f-name').value.toLowerCase().trim();
            const statusVal = document.getElementById('f-status').value;
            const prioVal   = document.getElementById('f-prio').value;
            const textVal   = document.getElementById('f-text').value.toLowerCase().trim();
            const sortVal   = document.getElementById('f-sort').value;

            let filtered = projectsData.filter(project => {
                if (nameEnabled && nameVal && !project.name.toLowerCase().includes(nameVal)) return false;
                if (statusEnabled && statusVal && project.status !== statusVal) return false;
                if (prioEnabled && prioVal) {
                    if (prioVal === 'Unpriorisiert' && project.priority) return false;
                    if (prioVal !== 'Unpriorisiert' && project.priority !== prioVal) return false;
                }
                if (textEnabled && textVal) {
                    const desc   = (project.description || '').toLowerCase();
                    const reason = (project.reason || '').toLowerCase();
                    if (!desc.includes(textVal) && !reason.includes(textVal)) return false;
                }
                return true;
            });

            const rankOf = p => priorityRank[p.priority] || 0;

            filtered.sort((a, b) => {
                switch (sortVal) {
                    case 'prio_desc':    return rankOf(b) - rankOf(a) || (b.created_at || '').localeCompare(a.created_at || '');
                    case 'prio_asc':     return rankOf(a) - rankOf(b) || (b.created_at || '').localeCompare(a.created_at || '');
                    case 'upvotes_desc': return (b.upvote_count || 0) - (a.upvote_count || 0) || (b.created_at || '').localeCompare(a.created_at || '');
                    case 'date_desc':
                    default:             return (b.created_at || '').localeCompare(a.created_at || '');
                }
            });

            renderGrid(filtered);

            // Wenn das Detail-Modal geöffnet ist, frisch nachziehen.
            const modal = document.getElementById('detail-modal');
            if (!modal.classList.contains('hidden') && currentModalProjectId !== null) {
                const latest = projectsData.find(p => p.id === currentModalProjectId);
                if (latest) renderModalDynamic(latest);
            }
        }

        function clearFilter() {
            document.querySelectorAll('.filter-cb').forEach(cb => cb.checked = false);
            document.getElementById('f-name').value   = '';
            document.getElementById('f-status').value = '';
            document.getElementById('f-prio').value   = '';
            document.getElementById('f-text').value   = '';
            document.getElementById('f-sort').value   = 'date_desc';
            applyFilter();
        }

        // ========================================================
        // Detail-Modal inkl. Upvote/Kommentar
        // ========================================================
        let currentModalProjectId = null;

        function showDetail(projectId) {
            const project = projectsData.find(p => p.id === projectId);
            if (!project) return;
            currentModalProjectId = projectId;

            document.getElementById('modal-name').textContent = project.name;

            const statusEl = document.getElementById('modal-status');
            statusEl.textContent = project.status;
            statusEl.className   = 'inline-block px-3 py-1 rounded-full text-sm font-medium ' + (statusColors[project.status] || '');

            const prioEl = document.getElementById('modal-priority');
            const prioMeta = priorityStyles[project.priority];
            if (prioMeta) {
                prioEl.textContent = prioMeta.emoji + ' ' + prioMeta.label;
                prioEl.className   = 'inline-block px-3 py-1 rounded-full text-sm font-medium ' + prioMeta.cls;
                prioEl.classList.remove('hidden');
            } else {
                prioEl.classList.add('hidden');
            }

            document.getElementById('modal-proposed-by').textContent =
                project.is_anonymous ? 'Anonym' : (project.proposed_by_name || 'Unbekannt');
            document.getElementById('modal-description').textContent = project.description;
            document.getElementById('modal-reason').textContent      = project.reason;

            const decisionSection = document.getElementById('modal-decision-section');
            if ((project.status === 'Angenommen' || project.status === 'Abgelehnt') && project.decision_reason) {
                decisionSection.classList.remove('hidden');
                document.getElementById('modal-decision-reason').textContent = project.decision_reason;
            } else {
                decisionSection.classList.add('hidden');
            }

            renderModalDynamic(project);
            document.getElementById('detail-modal').classList.remove('hidden');
        }

        /**
         * Rendert die dynamischen Abschnitte (Upvotes, Kommentare) neu,
         * ohne das ganze Modal zu schließen.
         */
        function renderModalDynamic(project) {
            const f = project.features || {};

            // Upvotes
            const upvoteSection = document.getElementById('modal-upvote-section');
            if (f.upvotes_enabled) {
                upvoteSection.classList.remove('hidden');
                document.getElementById('modal-upvote-count').textContent = project.upvote_count;
                const btn = document.getElementById('modal-upvote-btn');
                btn.className = 'px-3 py-1.5 rounded-md text-sm font-medium border transition ' +
                    (project.user_has_upvoted
                        ? 'bg-blue-100 text-blue-800 border-blue-300'
                        : 'bg-gray-100 text-gray-700 border-gray-300 hover:bg-gray-200') +
                    (f.can_upvote ? '' : ' opacity-60 cursor-not-allowed');
                btn.disabled = !f.can_upvote;
                const hint = document.getElementById('modal-upvote-hint');
                if (!f.can_upvote) {
                    if (project.status === 'Abgelehnt') {
                        hint.textContent = 'Abgelehnte Projekte können nicht upgevotet werden.';
                    } else if (f.upvotes_permission === 'admin') {
                        hint.textContent = 'Nur die Verwaltung darf upvoten.';
                    } else {
                        hint.textContent = '';
                    }
                } else {
                    hint.textContent = project.user_has_upvoted ? 'Du hast upgevotet.' : '';
                }
            } else {
                upvoteSection.classList.add('hidden');
            }

            // Upvoter-Liste (nur für Admins sichtbar)
            const upvotersBox = document.getElementById('modal-upvoters-list');
            if (IS_ADMIN && f.upvotes_enabled && Array.isArray(project.upvoters) && project.upvoters.length > 0) {
                upvotersBox.classList.remove('hidden');
                upvotersBox.innerHTML = '<div class="text-xs font-semibold text-gray-700 mb-1">Upgevotet von:</div>' +
                    '<ul class="text-xs text-gray-600 list-disc list-inside max-h-32 overflow-y-auto">' +
                    project.upvoters.map(u => '<li>' + escapeHtml(u.username || 'Unbekannt') + '</li>').join('') +
                    '</ul>';
            } else {
                upvotersBox.classList.add('hidden');
                upvotersBox.innerHTML = '';
            }

            // Kommentare
            const commentsSection = document.getElementById('modal-comments-section');
            const commentCount = (project.comments || []).length;
            // Sichtbar, wenn Feature aktiv ODER bestehende Kommentare vorhanden sind
            if (f.comments_enabled || commentCount > 0) {
                commentsSection.classList.remove('hidden');
                document.getElementById('modal-comments-count').textContent = commentCount;

                const list = document.getElementById('modal-comments-list');
                if (commentCount === 0) {
                    list.innerHTML = '<p class="text-xs text-gray-500 italic">Noch keine Kommentare.</p>';
                } else {
                    list.innerHTML = project.comments.map(c => {
                        const canDelete = IS_ADMIN || (c.user_id !== null && c.user_id === CURRENT_USER_ID);
                        const deleteBtn = canDelete
                            ? '<button type="button" onclick="deleteComment(' + c.id + ')" ' +
                              'class="text-xs text-red-600 hover:underline ml-2" title="Kommentar löschen">Löschen</button>'
                            : '';
                        return '<div class="bg-gray-50 border border-gray-200 rounded p-2">' +
                            '<div class="flex items-baseline justify-between gap-2">' +
                                '<span class="text-xs font-medium text-gray-700">' + escapeHtml(c.author || 'Unbekannt') + '</span>' +
                                '<span class="text-xs text-gray-400">' + formatDate(c.created_at) + deleteBtn + '</span>' +
                            '</div>' +
                            '<p class="text-sm text-gray-700 mt-1 whitespace-pre-wrap">' + escapeHtml(c.content) + '</p>' +
                            '</div>';
                    }).join('');
                }

                const form = document.getElementById('modal-comment-form');
                const hint = document.getElementById('modal-comments-hint');
                if (f.comments_enabled && f.can_comment) {
                    form.classList.remove('hidden');
                    hint.classList.add('hidden');
                } else {
                    form.classList.add('hidden');
                    hint.classList.remove('hidden');
                    if (!f.comments_enabled) {
                        hint.textContent = 'Neue Kommentare sind für diesen Vorschlag deaktiviert.';
                    } else if (f.comments_permission === 'admin') {
                        hint.textContent = 'Nur die Verwaltung darf kommentieren.';
                    } else {
                        hint.textContent = 'Kommentieren ist für dieses Projekt nicht erlaubt.';
                    }
                }
            } else {
                commentsSection.classList.add('hidden');
            }
        }

        function closeDetail() {
            document.getElementById('detail-modal').classList.add('hidden');
            currentModalProjectId = null;
        }

        document.getElementById('detail-modal').addEventListener('click', function(e) {
            if (e.target === this) closeDetail();
        });

        document.addEventListener('keydown', function(e) {
            if (e.key === 'Escape') closeDetail();
        });

        // ========================================================
        // Upvotes
        // ========================================================
        function csrfToken() {
            return document.getElementById('csrf-token').value;
        }

        function toggleUpvote() {
            if (currentModalProjectId === null) return;
            toggleUpvoteFromCard(currentModalProjectId);
        }

        function toggleUpvoteFromCard(projectId) {
            const project = projectsData.find(p => p.id === projectId);
            if (!project || !project.features?.can_upvote) return;

            const action = project.user_has_upvoted ? 'remove_upvote' : 'upvote';
            fetch('index.php?page=api-projects&action=' + action, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': csrfToken() },
                body: JSON.stringify({ project_id: projectId }),
            })
            .then(r => r.json())
            .then(data => {
                if (!data.success) {
                    alert(data.error || 'Upvote fehlgeschlagen.');
                    return;
                }
                project.upvote_count     = data.upvote_count;
                project.user_has_upvoted = data.user_has_upvoted;
                applyFilter();
            })
            .catch(() => alert('Netzwerkfehler beim Upvote.'));
        }

        // ========================================================
        // Kommentare
        // ========================================================
        function submitComment(ev) {
            ev.preventDefault();
            if (currentModalProjectId === null) return;
            const input = document.getElementById('modal-comment-input');
            const content = input.value.trim();
            if (!content) return;

            fetch('index.php?page=api-projects&action=add_comment', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': csrfToken() },
                body: JSON.stringify({ project_id: currentModalProjectId, content }),
            })
            .then(r => r.json())
            .then(data => {
                if (!data.success) {
                    alert(data.error || 'Kommentar konnte nicht gespeichert werden.');
                    return;
                }
                const project = projectsData.find(p => p.id === currentModalProjectId);
                if (project) {
                    project.comments = (project.comments || []).concat([data.comment]);
                    applyFilter();
                }
                input.value = '';
            })
            .catch(() => alert('Netzwerkfehler beim Senden des Kommentars.'));
        }

        function deleteComment(commentId) {
            if (!confirm('Kommentar wirklich löschen?')) return;
            fetch('index.php?page=api-projects&action=delete_comment', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': csrfToken() },
                body: JSON.stringify({ comment_id: commentId }),
            })
            .then(r => r.json())
            .then(data => {
                if (!data.success) {
                    alert(data.error || 'Kommentar konnte nicht gelöscht werden.');
                    return;
                }
                projectsData.forEach(p => {
                    p.comments = (p.comments || []).filter(c => c.id !== commentId);
                });
                applyFilter();
            })
            .catch(() => alert('Netzwerkfehler beim Löschen des Kommentars.'));
        }

        /**
         * Live-Update: Projekte alle 30 Sekunden neu laden und Filter beibehalten.
         */
        function refreshProjects() {
            fetch('index.php?page=api-projects')
                .then(response => response.json())
                .then(data => {
                    if (!Array.isArray(data)) return;
                    projectsData = data;
                    applyFilter();
                })
                .catch(err => console.error('Fehler beim Aktualisieren:', err));
        }

        // Initial rendern
        document.addEventListener('DOMContentLoaded', () => applyFilter());
        setInterval(refreshProjects, 30000);
    </script>
</body>
</html>
