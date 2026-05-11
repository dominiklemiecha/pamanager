<?php
/**
 * Dashboard Commercialista
 * PAManager - Comune
 */

require_once dirname(__DIR__, 2) . '/config/config.php';

Auth::init();
setSecurityHeaders();
Auth::requireUser('accountant');

$user = Auth::getUser();

// Statistiche
$employeeCount = Employee::count(true);
$documentCount = Database::count('documents', 'uploaded_by = ?', [$user['id']]);
$thisMonthDocs = Database::count(
    'documents',
    'uploaded_by = ? AND MONTH(created_at) = ? AND YEAR(created_at) = ?',
    [$user['id'], date('n'), date('Y')]
);

// Ultimi documenti caricati dall'utente
$recentDocuments = Database::fetchAll(
    "SELECT d.*, e.first_name, e.last_name, e.fiscal_code
     FROM documents d
     JOIN employees e ON d.employee_id = e.id
     WHERE d.uploaded_by = ?
     ORDER BY d.created_at DESC
     LIMIT 10",
    [$user['id']]
);

$pageTitle = 'Dashboard - Commercialista';
include dirname(__DIR__) . '/includes/header-admin.php';
?>

<style>
/* Dashboard Commercialista */
.acc-dashboard {
    display: flex;
    flex-direction: column;
    gap: 1.25rem;
}

.acc-welcome {
    background: linear-gradient(135deg, #1a365d 0%, #2c5282 100%);
    border-radius: 12px;
    padding: 1.5rem;
    color: white;
    display: flex;
    align-items: center;
    justify-content: space-between;
    flex-wrap: wrap;
    gap: 1rem;
}

.acc-welcome-info h1 {
    font-size: 1.25rem;
    margin: 0 0 0.25rem;
    color: white;
}

.acc-welcome-info p {
    margin: 0;
    opacity: 0.85;
    font-size: 0.85rem;
}

.acc-welcome .btn {
    background: rgba(255,255,255,0.15);
    border: 1px solid rgba(255,255,255,0.3);
    color: white;
    display: flex;
    align-items: center;
    gap: 0.5rem;
    padding: 0.6rem 1.25rem;
    font-size: 0.85rem;
}

.acc-welcome .btn:hover {
    background: rgba(255,255,255,0.25);
}

.acc-welcome .btn svg {
    width: 18px;
    height: 18px;
}

/* Stats */
.acc-stats {
    display: grid;
    grid-template-columns: repeat(3, 1fr);
    gap: 1rem;
}

.acc-stat {
    background: white;
    border-radius: 10px;
    padding: 1.25rem;
    display: flex;
    align-items: center;
    gap: 1rem;
    box-shadow: 0 2px 8px rgba(0,0,0,0.06);
    border-left: 4px solid;
}

.acc-stat.primary { border-left-color: #3182ce; }
.acc-stat.success { border-left-color: #38a169; }
.acc-stat.warning { border-left-color: #d69e2e; }

.acc-stat-icon {
    width: 48px;
    height: 48px;
    border-radius: 10px;
    display: flex;
    align-items: center;
    justify-content: center;
}

.acc-stat.primary .acc-stat-icon { background: #ebf8ff; color: #3182ce; }
.acc-stat.success .acc-stat-icon { background: #f0fff4; color: #38a169; }
.acc-stat.warning .acc-stat-icon { background: #fffff0; color: #d69e2e; }

.acc-stat-icon svg {
    width: 24px;
    height: 24px;
}

.acc-stat-info {
    flex: 1;
}

.acc-stat-value {
    font-size: 1.75rem;
    font-weight: 700;
    color: #1a202c;
    line-height: 1;
}

.acc-stat-label {
    font-size: 0.75rem;
    color: #718096;
    margin-top: 0.2rem;
}

/* Recent Docs */
.acc-section {
    background: white;
    border-radius: 10px;
    box-shadow: 0 2px 8px rgba(0,0,0,0.06);
    overflow: hidden;
}

.acc-section-header {
    padding: 1rem 1.25rem;
    border-bottom: 1px solid #edf2f7;
    display: flex;
    align-items: center;
    justify-content: space-between;
    background: #f7fafc;
}

.acc-section-header h2 {
    font-size: 0.95rem;
    margin: 0;
    color: #2d3748;
    display: flex;
    align-items: center;
    gap: 0.5rem;
}

.acc-section-header h2 svg {
    width: 18px;
    height: 18px;
    color: #718096;
}

/* Docs List */
.acc-docs-list {
    max-height: 400px;
    overflow-y: auto;
}

.acc-doc-item {
    display: flex;
    align-items: center;
    gap: 0.75rem;
    padding: 0.85rem 1.25rem;
    border-bottom: 1px solid #f7fafc;
    transition: background 0.2s;
}

.acc-doc-item:last-child { border-bottom: none; }
.acc-doc-item:hover { background: #f7fafc; }

.acc-doc-type {
    width: 36px;
    height: 36px;
    border-radius: 8px;
    display: flex;
    align-items: center;
    justify-content: center;
    flex-shrink: 0;
}

.acc-doc-type svg {
    width: 18px;
    height: 18px;
}

.acc-doc-type.payslip { background: #c6f6d5; color: #276749; }
.acc-doc-type.cud { background: #fefcbf; color: #975a16; }
.acc-doc-type.other { background: #e2e8f0; color: #4a5568; }

.acc-doc-info {
    flex: 1;
    min-width: 0;
}

.acc-doc-title {
    font-weight: 500;
    color: #2d3748;
    font-size: 0.85rem;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
}

.acc-doc-meta {
    font-size: 0.7rem;
    color: #a0aec0;
    display: flex;
    gap: 0.75rem;
    flex-wrap: wrap;
}

.acc-doc-employee {
    font-size: 0.8rem;
    color: #4a5568;
    min-width: 120px;
}

.acc-doc-date {
    font-size: 0.75rem;
    color: #a0aec0;
    white-space: nowrap;
}

.acc-empty {
    padding: 2.5rem;
    text-align: center;
    color: #a0aec0;
}

/* Responsive */
@media (max-width: 768px) {
    .acc-stats {
        grid-template-columns: 1fr;
    }

    .acc-welcome {
        flex-direction: column;
        text-align: center;
    }

    .acc-doc-item {
        flex-wrap: wrap;
    }

    .acc-doc-employee {
        width: 100%;
        order: -1;
        margin-bottom: 0.25rem;
    }
}
</style>

<div class="acc-dashboard">
    <!-- Welcome Header -->
    <div class="acc-welcome">
        <div class="acc-welcome-info">
            <h1>Benvenuto, <?= e($user['name']) ?></h1>
            <p><?= getItalianDate() ?></p>
        </div>
        <a href="documents.php" class="btn">
            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor">
                <path d="M9 16h6v-6h4l-7-7-7 7h4v6zm-4 2h14v2H5v-2z"/>
            </svg>
            Carica Documenti
        </a>
    </div>

    <!-- Stats -->
    <div class="acc-stats">
        <div class="acc-stat primary">
            <div class="acc-stat-icon">
                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor">
                    <path d="M16 11c1.66 0 2.99-1.34 2.99-3S17.66 5 16 5c-1.66 0-3 1.34-3 3s1.34 3 3 3zm-8 0c1.66 0 2.99-1.34 2.99-3S9.66 5 8 5C6.34 5 5 6.34 5 8s1.34 3 3 3zm0 2c-2.33 0-7 1.17-7 3.5V19h14v-2.5c0-2.33-4.67-3.5-7-3.5z"/>
                </svg>
            </div>
            <div class="acc-stat-info">
                <div class="acc-stat-value"><?= $employeeCount ?></div>
                <div class="acc-stat-label">Dipendenti Attivi</div>
            </div>
        </div>

        <div class="acc-stat success">
            <div class="acc-stat-icon">
                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor">
                    <path d="M14 2H6c-1.1 0-2 .9-2 2v16c0 1.1.9 2 2 2h12c1.1 0 2-.9 2-2V8l-6-6zm-1 7V3.5L18.5 9H13z"/>
                </svg>
            </div>
            <div class="acc-stat-info">
                <div class="acc-stat-value"><?= $documentCount ?></div>
                <div class="acc-stat-label">Documenti Totali</div>
            </div>
        </div>

        <div class="acc-stat warning">
            <div class="acc-stat-icon">
                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor">
                    <path d="M19 3h-1V1h-2v2H8V1H6v2H5c-1.1 0-2 .9-2 2v14c0 1.1.9 2 2 2h14c1.1 0 2-.9 2-2V5c0-1.1-.9-2-2-2zm0 16H5V8h14v11z"/>
                </svg>
            </div>
            <div class="acc-stat-info">
                <div class="acc-stat-value"><?= $thisMonthDocs ?></div>
                <div class="acc-stat-label">Questo Mese</div>
            </div>
        </div>
    </div>

    <!-- Recent Documents -->
    <div class="acc-section">
        <div class="acc-section-header">
            <h2>
                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor">
                    <path d="M13 3c-4.97 0-9 4.03-9 9H1l3.89 3.89.07.14L9 12H6c0-3.87 3.13-7 7-7s7 3.13 7 7-3.13 7-7 7c-1.93 0-3.68-.79-4.94-2.06l-1.42 1.42C8.27 19.99 10.51 21 13 21c4.97 0 9-4.03 9-9s-4.03-9-9-9z"/>
                </svg>
                Ultimi Documenti
            </h2>
            <a href="documents.php" class="btn btn-sm btn-secondary">Vedi tutti</a>
        </div>

        <?php if (empty($recentDocuments)): ?>
            <div class="acc-empty">Nessun documento caricato</div>
        <?php else: ?>
            <div class="acc-docs-list">
                <?php foreach ($recentDocuments as $doc): ?>
                    <div class="acc-doc-item">
                        <div class="acc-doc-type <?= e($doc['type']) ?>">
                            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor">
                                <path d="M14 2H6c-1.1 0-2 .9-2 2v16c0 1.1.9 2 2 2h12c1.1 0 2-.9 2-2V8l-6-6z"/>
                            </svg>
                        </div>
                        <div class="acc-doc-info">
                            <div class="acc-doc-title"><?= e($doc['title']) ?></div>
                            <div class="acc-doc-meta">
                                <span><?= getMonthName($doc['month']) ?> <?= $doc['year'] ?></span>
                                <span><?= e(Document::TYPES[$doc['type']] ?? $doc['type']) ?></span>
                            </div>
                        </div>
                        <div class="acc-doc-employee"><?= e($doc['last_name'] . ' ' . $doc['first_name']) ?></div>
                        <div class="acc-doc-date"><?= formatDateTime($doc['created_at']) ?></div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>
</div>

<?php include dirname(__DIR__) . '/includes/footer-admin.php'; ?>
