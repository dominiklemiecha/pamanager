<?php
/**
 * Comunicazioni - Dipendente
 * PAManager - Comune
 */

require_once dirname(__DIR__, 2) . '/config/config.php';

Auth::init();
setSecurityHeaders();
Auth::requireEmployee();

$employee = Auth::getEmployee();

// Visualizzazione singola comunicazione
$viewId = isset($_GET['id']) ? (int) $_GET['id'] : null;
$communication = null;

if ($viewId) {
    $communication = Communication::getById($viewId);

    if ($communication) {
        $now = date('Y-m-d');
        if (!$communication['is_published'] ||
            $communication['publish_date'] > $now ||
            ($communication['expire_date'] && $communication['expire_date'] < $now)) {
            $communication = null;
        }
    }

    if ($communication) {
        Communication::markAsRead($viewId, $employee['id']);
    }
}

// Filtro priorità
$filterPriority = $_GET['priority'] ?? null;

// Lista comunicazioni
$communications = Communication::getActive($employee['id']);
$unreadCount = Communication::countUnread($employee['id']);

// Filtra per priorità se specificato
if ($filterPriority && in_array($filterPriority, ['high', 'normal', 'low'])) {
    $communications = array_filter($communications, function($c) use ($filterPriority) {
        return $c['priority'] === $filterPriority;
    });
}

$pageTitle = $communication ? htmlspecialchars($communication['title']) : 'Comunicazioni';
include dirname(__DIR__) . '/includes/header-employee.php';
?>

<style>
/* Communications Page */
.filters-card {
    background: white;
    padding: 0.75rem 1rem;
    border-radius: 10px;
    box-shadow: 0 2px 8px rgba(0,0,0,0.06);
    margin-bottom: 1.25rem;
    display: flex;
    align-items: center;
    gap: 0.75rem;
    flex-wrap: wrap;
}

.filters-card label {
    font-size: 0.75rem;
    color: #4a5568;
    font-weight: 500;
}

.filters-card select {
    padding: 0.4rem 0.75rem;
    border: 1px solid #e2e8f0;
    border-radius: 6px;
    font-size: 0.8rem;
    background: white;
    min-width: 130px;
}

.filters-card .btn {
    padding: 0.4rem 0.85rem;
    font-size: 0.75rem;
}

.filter-tags {
    display: flex;
    gap: 0.4rem;
    margin-left: auto;
}

.filter-tag {
    display: inline-flex;
    align-items: center;
    gap: 0.25rem;
    background: #ebf8ff;
    color: #2b6cb0;
    padding: 0.25rem 0.6rem;
    border-radius: 15px;
    font-size: 0.7rem;
}

.filter-tag.high { background: #fed7d7; color: #c53030; }
.filter-tag.normal { background: #e2e8f0; color: #4a5568; }
.filter-tag.low { background: #c6f6d5; color: #276749; }

/* Empty State */
.empty-box {
    background: white;
    border-radius: 10px;
    padding: 3rem 1.5rem;
    text-align: center;
    box-shadow: 0 2px 8px rgba(0,0,0,0.06);
}

.empty-box svg {
    width: 50px;
    height: 50px;
    color: #cbd5e0;
    margin-bottom: 0.75rem;
}

.empty-box h3 {
    color: #4a5568;
    margin: 0 0 0.35rem;
    font-size: 1rem;
}

.empty-box p {
    color: #718096;
    margin: 0;
    font-size: 0.8rem;
}

/* Communications List */
.comms-list {
    display: flex;
    flex-direction: column;
    gap: 0.75rem;
}

.comm-card {
    background: white;
    border-radius: 10px;
    box-shadow: 0 2px 8px rgba(0,0,0,0.06);
    overflow: hidden;
    text-decoration: none;
    display: block;
    transition: transform 0.2s, box-shadow 0.2s;
    position: relative;
}

.comm-card:hover {
    transform: translateY(-2px);
    box-shadow: 0 4px 12px rgba(0,0,0,0.1);
}

.comm-card.unread {
    border-left: 3px solid #3182ce;
}

.comm-card-inner {
    padding: 1rem;
}

.comm-card-top {
    display: flex;
    align-items: center;
    gap: 0.4rem;
    margin-bottom: 0.5rem;
}

.comm-card .new-badge {
    background: #e53e3e;
    color: white;
    font-size: 0.55rem;
    padding: 0.15rem 0.4rem;
    border-radius: 3px;
    font-weight: 700;
}

.comm-card .priority-badge {
    font-size: 0.6rem;
    padding: 0.15rem 0.5rem;
    border-radius: 3px;
    font-weight: 600;
}

.priority-badge.high { background: #fed7d7; color: #c53030; }
.priority-badge.normal { background: #e2e8f0; color: #4a5568; }
.priority-badge.low { background: #c6f6d5; color: #276749; }

.comm-card h2 {
    font-size: 0.95rem;
    margin: 0 0 0.35rem;
    color: #2d3748;
}

.comm-card .preview {
    color: #718096;
    font-size: 0.8rem;
    line-height: 1.4;
    margin: 0 0 0.75rem;
    display: -webkit-box;
    -webkit-line-clamp: 2;
    -webkit-box-orient: vertical;
    overflow: hidden;
}

.comm-card-footer {
    display: flex;
    align-items: center;
    justify-content: space-between;
    padding-top: 0.6rem;
    border-top: 1px solid #edf2f7;
}

.comm-card-footer .date {
    font-size: 0.7rem;
    color: #a0aec0;
}

.comm-card .read-more {
    display: inline-flex;
    align-items: center;
    gap: 0.2rem;
    color: #3182ce;
    font-size: 0.75rem;
    font-weight: 500;
}

.comm-card .read-more svg {
    width: 14px;
    height: 14px;
}

/* Communication Detail */
.back-link {
    display: inline-flex;
    align-items: center;
    gap: 0.4rem;
    color: #3182ce;
    text-decoration: none;
    font-size: 0.8rem;
    margin-bottom: 1.25rem;
}

.back-link:hover {
    text-decoration: underline;
}

.back-link svg {
    width: 16px;
    height: 16px;
}

.comm-detail {
    background: white;
    border-radius: 10px;
    box-shadow: 0 2px 8px rgba(0,0,0,0.06);
    overflow: hidden;
}

.comm-detail-header {
    padding: 1.5rem;
    border-bottom: 1px solid #edf2f7;
}

.comm-detail-header .badges {
    display: flex;
    gap: 0.4rem;
    margin-bottom: 0.75rem;
}

.comm-detail-header h1 {
    font-size: 1.25rem;
    margin: 0 0 0.75rem;
    color: #1a202c;
}

.comm-detail-header .meta {
    display: flex;
    align-items: center;
    gap: 1.25rem;
    flex-wrap: wrap;
    color: #718096;
    font-size: 0.8rem;
}

.comm-detail-header .meta svg {
    width: 14px;
    height: 14px;
    margin-right: 0.25rem;
    vertical-align: middle;
}

.comm-detail-body {
    padding: 1.5rem;
}

.comm-detail-body .content {
    color: #4a5568;
    line-height: 1.7;
    font-size: 0.9rem;
}

.comm-detail-body .content p {
    margin: 0 0 0.75rem;
}

.comm-detail-footer {
    padding: 1.25rem 1.5rem;
    background: #f7fafc;
    border-top: 1px solid #edf2f7;
}

.expire-notice {
    display: flex;
    align-items: center;
    gap: 0.4rem;
    color: #c53030;
    font-size: 0.8rem;
}

.expire-notice svg {
    width: 16px;
    height: 16px;
}
</style>

<?php if ($communication): ?>
    <!-- Dettaglio Comunicazione -->
    <a href="communications.php" class="back-link">
        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor">
            <path d="M20 11H7.83l5.59-5.59L12 4l-8 8 8 8 1.41-1.41L7.83 13H20v-2z"/>
        </svg>
        Torna alle comunicazioni
    </a>

    <article class="comm-detail">
        <header class="comm-detail-header">
            <div class="badges">
                <span class="priority-badge <?php echo $communication['priority']; ?>">
                    <?php echo Communication::PRIORITIES[$communication['priority']] ?? $communication['priority']; ?>
                </span>
            </div>
            <h1><?php echo htmlspecialchars($communication['title']); ?></h1>
            <div class="meta">
                <span>
                    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor">
                        <path d="M12 12c2.21 0 4-1.79 4-4s-1.79-4-4-4-4 1.79-4 4 1.79 4 4 4zm0 2c-2.67 0-8 1.34-8 4v2h16v-2c0-2.66-5.33-4-8-4z"/>
                    </svg>
                    <?php echo htmlspecialchars($communication['author_name']); ?>
                </span>
                <span>
                    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor">
                        <path d="M19 3h-1V1h-2v2H8V1H6v2H5c-1.1 0-2 .9-2 2v14c0 1.1.9 2 2 2h14c1.1 0 2-.9 2-2V5c0-1.1-.9-2-2-2zm0 16H5V8h14v11z"/>
                    </svg>
                    <?php echo formatDate($communication['publish_date']); ?>
                </span>
            </div>
        </header>

        <div class="comm-detail-body">
            <div class="content">
                <?php echo nl2br(htmlspecialchars($communication['content'])); ?>
            </div>
        </div>

        <?php if ($communication['expire_date']): ?>
            <footer class="comm-detail-footer">
                <div class="expire-notice">
                    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor">
                        <path d="M12 2C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12 2zm1 15h-2v-2h2v2zm0-4h-2V7h2v6z"/>
                    </svg>
                    Questa comunicazione scade il <?php echo formatDate($communication['expire_date']); ?>
                </div>
            </footer>
        <?php endif; ?>
    </article>

<?php else: ?>
    <!-- Filtri -->
    <form method="GET" class="filters-card">
        <label>Filtra per urgenza:</label>
        <select name="priority" onchange="this.form.submit()">
            <option value="">Tutte le priorità</option>
            <option value="high" <?php echo $filterPriority === 'high' ? 'selected' : ''; ?>>Alta (Urgente)</option>
            <option value="normal" <?php echo $filterPriority === 'normal' ? 'selected' : ''; ?>>Normale</option>
            <option value="low" <?php echo $filterPriority === 'low' ? 'selected' : ''; ?>>Bassa</option>
        </select>

        <?php if ($filterPriority): ?>
            <div class="filter-tags">
                <span class="filter-tag <?php echo $filterPriority; ?>">
                    <?php echo Communication::PRIORITIES[$filterPriority] ?? $filterPriority; ?>
                </span>
                <a href="communications.php" class="btn btn-link" style="padding:0;font-size:0.75rem;">Reset</a>
            </div>
        <?php endif; ?>

        <span style="margin-left:auto;font-size:0.75rem;color:#718096;">
            <?php echo count($communications); ?> comunicazioni
            <?php if ($unreadCount > 0): ?>
                <span style="color:#e53e3e;font-weight:600;">(<?php echo $unreadCount; ?> da leggere)</span>
            <?php endif; ?>
        </span>
    </form>

    <?php if (empty($communications)): ?>
        <div class="empty-box">
            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor">
                <path d="M20 2H4c-1.1 0-2 .9-2 2v18l4-4h14c1.1 0 2-.9 2-2V4c0-1.1-.9-2-2-2z"/>
            </svg>
            <h3>Nessuna comunicazione</h3>
            <?php if ($filterPriority): ?>
                <p>Non ci sono comunicazioni con questa priorità</p>
            <?php else: ?>
                <p>Non ci sono comunicazioni attive al momento</p>
            <?php endif; ?>
        </div>
    <?php else: ?>
        <div class="comms-list">
            <?php foreach ($communications as $comm): ?>
                <a href="?id=<?php echo $comm['id']; ?>" class="comm-card <?php echo empty($comm['is_read']) ? 'unread' : ''; ?>">
                    <div class="comm-card-inner">
                        <div class="comm-card-top">
                            <?php if (empty($comm['is_read'])): ?>
                                <span class="new-badge">NUOVO</span>
                            <?php endif; ?>
                            <span class="priority-badge <?php echo $comm['priority']; ?>">
                                <?php echo Communication::PRIORITIES[$comm['priority']] ?? $comm['priority']; ?>
                            </span>
                        </div>
                        <h2><?php echo htmlspecialchars($comm['title']); ?></h2>
                        <p class="preview"><?php echo htmlspecialchars(substr(strip_tags($comm['content']), 0, 200)); ?></p>
                        <div class="comm-card-footer">
                            <span class="date"><?php echo formatDate($comm['publish_date']); ?></span>
                            <span class="read-more">
                                Leggi
                                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor">
                                    <path d="M12 4l-1.41 1.41L16.17 11H4v2h12.17l-5.58 5.59L12 20l8-8z"/>
                                </svg>
                            </span>
                        </div>
                    </div>
                </a>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
<?php endif; ?>

<?php include dirname(__DIR__) . '/includes/footer-employee.php'; ?>
