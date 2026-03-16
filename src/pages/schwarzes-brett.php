<?php
/**
 * Schwarzes Brett
 * Zeigt alle Projekte als Karten-Grid an.
 * Mit Modal/Detail-Panel bei Klick auf eine Karte.
 * Live-Update alle 30 Sekunden via fetch().
 */

require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../auth.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

requireLogin();

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
<html lang="de">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Schwarzes Brett</title>
    <script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="bg-gray-100 min-h-screen">
    <!-- Kopfzeile -->
    <header class="bg-white shadow">
        <div class="max-w-7xl mx-auto px-4 py-4 flex justify-between items-center">
            <h1 class="text-2xl font-bold text-gray-800">Schwarzes Brett</h1>
            <div class="flex gap-3">
                <a href="index.php?page=nav" class="bg-gray-200 text-gray-700 px-4 py-2 rounded-md hover:bg-gray-300 text-sm">← Navigation</a>
                <a href="index.php?page=logout" class="bg-red-500 text-white px-4 py-2 rounded-md hover:bg-red-600 text-sm">Abmelden</a>
            </div>
        </div>
    </header>

    <!-- Projekte Grid -->
    <main class="max-w-7xl mx-auto px-4 py-8">
        <div id="projects-grid" class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6">
            <?php foreach ($projects as $project): ?>
                <div class="project-card bg-white rounded-lg shadow-md border-l-4 <?= getStatusBorderColor($project['status']) ?> p-5 cursor-pointer hover:shadow-lg transition-shadow"
                     onclick="showDetail(<?= (int)$project['id'] ?>)"
                     data-project-id="<?= (int)$project['id'] ?>">
                    <h3 class="text-lg font-semibold text-gray-800 mb-2"><?= e($project['name']) ?></h3>
                    <span class="inline-block px-3 py-1 rounded-full text-sm font-medium <?= getStatusColor($project['status']) ?>">
                        <?= e($project['status']) ?>
                    </span>
                    <p class="text-gray-500 text-sm mt-2">
                        <?= $project['is_anonymous'] ? 'Anonym' : e($project['proposed_by_name'] ?? 'Unbekannt') ?>
                    </p>
                </div>
            <?php endforeach; ?>

            <?php if (empty($projects)): ?>
                <div class="col-span-full text-center text-gray-500 py-12">
                    <p class="text-lg">Noch keine Projekte vorhanden.</p>
                    <a href="index.php?page=vorschlag" class="text-blue-600 hover:underline mt-2 inline-block">Ersten Vorschlag einreichen →</a>
                </div>
            <?php endif; ?>
        </div>
    </main>

    <!-- Detail-Modal -->
    <div id="detail-modal" class="fixed inset-0 bg-black bg-opacity-50 hidden z-50 flex items-center justify-center">
        <div class="bg-white rounded-lg shadow-xl max-w-lg w-full mx-4 max-h-[90vh] overflow-y-auto">
            <div class="p-6">
                <div class="flex justify-between items-start mb-4">
                    <h2 id="modal-name" class="text-xl font-bold text-gray-800"></h2>
                    <button onclick="closeDetail()" class="text-gray-400 hover:text-gray-600 text-2xl">&times;</button>
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
        // Projektdaten als JSON für das Modal bereitstellen
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

        /**
         * Statusfarben-Mapping für JavaScript
         */
        const statusColors = {
            'Vorgeschlagen':   'bg-gray-200 text-gray-800',
            'In Besprechung':  'bg-yellow-200 text-yellow-800',
            'In Bearbeitung':  'bg-blue-200 text-blue-800',
            'Umgesetzt':       'bg-purple-200 text-purple-800',
            'Angenommen':      'bg-green-200 text-green-800',
            'Abgelehnt':       'bg-red-200 text-red-800',
        };

        const statusBorderColors = {
            'Vorgeschlagen':   'border-gray-400',
            'In Besprechung':  'border-yellow-400',
            'In Bearbeitung':  'border-blue-400',
            'Umgesetzt':       'border-purple-400',
            'Angenommen':      'border-green-400',
            'Abgelehnt':       'border-red-400',
        };

        /**
         * Escaped HTML-Sonderzeichen
         */
        function escapeHtml(str) {
            if (!str) return '';
            const div = document.createElement('div');
            div.textContent = str;
            return div.innerHTML;
        }

        /**
         * Zeigt das Detail-Modal für ein Projekt an.
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

            // Entscheidungsbegründung anzeigen bei Angenommen/Abgelehnt
            const decisionSection = document.getElementById('modal-decision-section');
            if ((project.status === 'Angenommen' || project.status === 'Abgelehnt') && project.decision_reason) {
                decisionSection.classList.remove('hidden');
                document.getElementById('modal-decision-reason').textContent = project.decision_reason;
            } else {
                decisionSection.classList.add('hidden');
            }

            document.getElementById('detail-modal').classList.remove('hidden');
        }

        /**
         * Schließt das Detail-Modal.
         */
        function closeDetail() {
            document.getElementById('detail-modal').classList.add('hidden');
        }

        // Modal schließen bei Klick außerhalb
        document.getElementById('detail-modal').addEventListener('click', function(e) {
            if (e.target === this) closeDetail();
        });

        // Escape-Taste schließt Modal
        document.addEventListener('keydown', function(e) {
            if (e.key === 'Escape') closeDetail();
        });

        /**
         * Live-Update: Projekte alle 30 Sekunden neu laden.
         */
        function refreshProjects() {
            fetch('index.php?page=api-projects')
                .then(response => response.json())
                .then(data => {
                    if (!Array.isArray(data)) return;

                    projectsData = data;
                    const grid = document.getElementById('projects-grid');

                    if (data.length === 0) {
                        grid.innerHTML = '<div class="col-span-full text-center text-gray-500 py-12">' +
                            '<p class="text-lg">Noch keine Projekte vorhanden.</p>' +
                            '<a href="index.php?page=vorschlag" class="text-blue-600 hover:underline mt-2 inline-block">Ersten Vorschlag einreichen →</a></div>';
                        return;
                    }

                    grid.innerHTML = data.map(project => {
                        const borderColor = statusBorderColors[project.status] || 'border-gray-300';
                        const statusColor = statusColors[project.status] || 'bg-gray-100 text-gray-600';
                        const proposedBy = project.is_anonymous ? 'Anonym' : escapeHtml(project.proposed_by_name || 'Unbekannt');

                        return '<div class="project-card bg-white rounded-lg shadow-md border-l-4 ' + borderColor + ' p-5 cursor-pointer hover:shadow-lg transition-shadow" ' +
                            'onclick="showDetail(' + project.id + ')" data-project-id="' + project.id + '">' +
                            '<h3 class="text-lg font-semibold text-gray-800 mb-2">' + escapeHtml(project.name) + '</h3>' +
                            '<span class="inline-block px-3 py-1 rounded-full text-sm font-medium ' + statusColor + '">' + escapeHtml(project.status) + '</span>' +
                            '<p class="text-gray-500 text-sm mt-2">' + proposedBy + '</p></div>';
                    }).join('');
                })
                .catch(err => console.error('Fehler beim Aktualisieren:', err));
        }

        // Alle 30 Sekunden aktualisieren
        setInterval(refreshProjects, 30000);
    </script>
</body>
</html>
