<?php
/**
 * Schwarzes Brett
 * Zeigt alle Projekte als Karten-Grid an.
 * Filter: Name, Status, Beschreibung/Begründung (je mit Checkbox).
 * Live-Update alle 30 Sekunden via fetch().
 */

require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../auth.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

requireLogin();

require_once __DIR__ . '/../includes/theme.php';

// Projekte laden
try {
    $db = getDB();
    $stmt = $db->query(
        'SELECT p.*, u.username AS proposed_by_name
         FROM projects p
         LEFT JOIN users u ON p.proposed_by = u.id
         ORDER BY p.created_at DESC'
    );
    $projects = $stmt->fetchAll();
} catch (Exception $e) {
    error_log('Schwarzes Brett Fehler: ' . $e->getMessage());
    $projects = [];
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
                <h3 class="text-sm font-bold text-gray-700">Filter</h3>
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
                <!-- Beschreibung/Begründung -->
                <div class="flex items-center gap-2 min-w-0">
                    <input type="checkbox" id="f-text-en" class="filter-cb shrink-0" onchange="applyFilter()">
                    <label for="f-text-en" class="text-sm text-gray-600 whitespace-nowrap shrink-0">Beschreibung/Begründung:</label>
                    <input type="text" id="f-text" placeholder="Suchen…"
                           class="px-2 py-1 border border-gray-300 rounded text-sm min-w-0 w-full sm:w-36"
                           oninput="applyFilter()">
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
                    <div>
                        <span id="modal-status" class="inline-block px-3 py-1 rounded-full text-sm font-medium"></span>
                    </div>
                    <div>
                        <strong class="text-gray-700">Vorgeschlagen von:</strong>
                        <span id="modal-proposed-by" class="text-gray-600"></span>
                    </div>
                    <div>
                        <strong class="text-gray-700">Beschreibung:</strong>
                        <p id="modal-description" class="text-gray-600 mt-1"></p>
                    </div>
                    <div>
                        <strong class="text-gray-700">Begründung:</strong>
                        <p id="modal-reason" class="text-gray-600 mt-1"></p>
                    </div>
                    <div id="modal-decision-section" class="hidden border-t pt-3 mt-3">
                        <strong class="text-gray-700">Begründung der Entscheidung:</strong>
                        <p id="modal-decision-reason" class="text-gray-600 mt-1"></p>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script>
        // Projektdaten als JSON (für Modal und Filter)
        let projectsData = <?= json_encode(array_map(function($p) {
            return [
                'id' => (int)$p['id'],
                'name' => $p['name'],
                'description' => $p['description'],
                'reason' => $p['reason'],
                'status' => $p['status'],
                'is_anonymous' => (int)$p['is_anonymous'],
                'proposed_by_name' => $p['proposed_by_name'] ?? null,
                'decision_reason' => $p['decision_reason'],
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

        function escapeHtml(str) {
            if (!str) return '';
            const div = document.createElement('div');
            div.textContent = str;
            return div.innerHTML;
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
                const proposedBy = project.is_anonymous ? 'Anonym' : escapeHtml(project.proposed_by_name || 'Unbekannt');
                return '<div class="project-card bg-white rounded-lg shadow-md border-l-4 ' + borderColor +
                    ' p-4 sm:p-5 cursor-pointer hover:shadow-lg transition-shadow active:scale-95"' +
                    ' onclick="showDetail(' + project.id + ')" data-project-id="' + project.id + '">' +
                    '<h3 class="text-base sm:text-lg font-semibold text-gray-800 mb-2">' + escapeHtml(project.name) + '</h3>' +
                    '<span class="inline-block px-3 py-1 rounded-full text-xs sm:text-sm font-medium ' + statusColor + '">' + escapeHtml(project.status) + '</span>' +
                    '<p class="text-gray-500 text-xs sm:text-sm mt-2">' + proposedBy + '</p></div>';
            }).join('');
        }

        /**
         * Filtert projectsData und rendert das Grid neu.
         */
        function applyFilter() {
            const nameEnabled  = document.getElementById('f-name-en').checked;
            const statusEnabled = document.getElementById('f-status-en').checked;
            const textEnabled  = document.getElementById('f-text-en').checked;

            const nameVal   = document.getElementById('f-name').value.toLowerCase().trim();
            const statusVal = document.getElementById('f-status').value;
            const textVal   = document.getElementById('f-text').value.toLowerCase().trim();

            const filtered = projectsData.filter(project => {
                if (nameEnabled && nameVal && !project.name.toLowerCase().includes(nameVal)) return false;
                if (statusEnabled && statusVal && project.status !== statusVal) return false;
                if (textEnabled && textVal) {
                    const desc   = (project.description || '').toLowerCase();
                    const reason = (project.reason || '').toLowerCase();
                    if (!desc.includes(textVal) && !reason.includes(textVal)) return false;
                }
                return true;
            });

            renderGrid(filtered);
        }

        function clearFilter() {
            document.querySelectorAll('.filter-cb').forEach(cb => cb.checked = false);
            document.getElementById('f-name').value   = '';
            document.getElementById('f-status').value = '';
            document.getElementById('f-text').value   = '';
            applyFilter();
        }

        /**
         * Zeigt das Detail-Modal für ein Projekt.
         */
        function showDetail(projectId) {
            const project = projectsData.find(p => p.id === projectId);
            if (!project) return;

            document.getElementById('modal-name').textContent = project.name;

            const statusEl = document.getElementById('modal-status');
            statusEl.textContent = project.status;
            statusEl.className = 'inline-block px-3 py-1 rounded-full text-sm font-medium ' + (statusColors[project.status] || '');

            document.getElementById('modal-proposed-by').textContent =
                project.is_anonymous ? 'Anonym' : (project.proposed_by_name || 'Unbekannt');
            document.getElementById('modal-description').textContent = project.description;
            document.getElementById('modal-reason').textContent = project.reason;

            const decisionSection = document.getElementById('modal-decision-section');
            if ((project.status === 'Angenommen' || project.status === 'Abgelehnt') && project.decision_reason) {
                decisionSection.classList.remove('hidden');
                document.getElementById('modal-decision-reason').textContent = project.decision_reason;
            } else {
                decisionSection.classList.add('hidden');
            }

            document.getElementById('detail-modal').classList.remove('hidden');
        }

        function closeDetail() {
            document.getElementById('detail-modal').classList.add('hidden');
        }

        document.getElementById('detail-modal').addEventListener('click', function(e) {
            if (e.target === this) closeDetail();
        });

        document.addEventListener('keydown', function(e) {
            if (e.key === 'Escape') closeDetail();
        });

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
