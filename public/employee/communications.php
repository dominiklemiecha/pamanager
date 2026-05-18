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
    background: #eef3fb;
    color: #082b7b;
    padding: 0.25rem 0.6rem;
    border-radius: 15px;
    font-size: 0.7rem;
}

.filter-tag.high { background: #fde2e5; color: #cc2d39; }
.filter-tag.normal { background: #e2e8f0; color: #4a5568; }
.filter-tag.low { background: #eef3fb; color: #082b7b; }

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
    border-left: 3px solid #0b3aa4;
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
    background: #f75c6c;
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

.priority-badge.high { background: #fde2e5; color: #cc2d39; }
.priority-badge.normal { background: #e2e8f0; color: #4a5568; }
.priority-badge.low { background: #eef3fb; color: #082b7b; }

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
    color: #0b3aa4;
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
    color: #0b3aa4;
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
    color: #cc2d39;
    font-size: 0.8rem;
}

.expire-notice svg {
    width: 16px;
    height: 16px;
}

/* Rich content (HTML body) */
.cm-rich p { margin: 0 0 0.75rem; }
.cm-rich p:last-child { margin-bottom: 0; }
.cm-rich strong, .cm-rich b { font-weight: 700; color: #1a202c; }
.cm-rich em, .cm-rich i { font-style: italic; }
.cm-rich u { text-decoration: underline; }
.cm-rich ul, .cm-rich ol { margin: 0 0 0.875rem; padding-left: 1.5rem; }
.cm-rich ul li, .cm-rich ol li { margin-bottom: 0.3rem; line-height: 1.6; }
.cm-rich h1, .cm-rich h2, .cm-rich h3, .cm-rich h4 { color: #1a202c; font-weight: 700; margin: 1rem 0 0.5rem; }
.cm-rich h3 { font-size: 1rem; }
.cm-rich h4 { font-size: 0.9rem; }
.cm-rich blockquote { margin: 0.75rem 0; padding: 0.5rem 1rem; border-left: 3px solid #0b3aa4; background: rgba(11,58,164,0.04); border-radius: 0 6px 6px 0; color: #4a5568; font-style: italic; }
.cm-rich a { color: #0b3aa4; text-decoration: underline; }
.cm-rich img { max-width: 100%; height: auto; border-radius: 6px; margin: 0.4rem 0; border: 1px solid #edf2f7; }

/* Attachments */
.comm-attachments { margin-top: 1.5rem; padding-top: 1rem; border-top: 1px solid #edf2f7; }
.comm-attachments h3 {
    font-size: 0.75rem; font-weight: 700; color: #718096;
    text-transform: uppercase; letter-spacing: 0.05em;
    margin: 0 0 0.5rem;
}
.comm-att-list { display: flex; flex-direction: column; gap: 0.4rem; }
.comm-att-item {
    display: flex; align-items: center; gap: 0.65rem;
    padding: 0.55rem 0.75rem;
    background: #f7fafc; border: 1px solid #edf2f7;
    border-radius: 8px; text-decoration: none; color: #4a5568;
    transition: all .12s ease;
}
.comm-att-item:hover { border-color: #0b3aa4; background: rgba(11,58,164,0.04); color: #0b3aa4; }
.comm-att-icon {
    width: 32px; height: 32px;
    display: flex; align-items: center; justify-content: center;
    background: rgba(11,58,164,0.10); color: #0b3aa4;
    border-radius: 6px; flex-shrink: 0;
}
.comm-att-info { flex: 1; min-width: 0; display: flex; flex-direction: column; }
.comm-att-name { font-size: 0.82rem; font-weight: 600; color: #2d3748; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.comm-att-meta { font-size: 0.7rem; color: #a0aec0; }
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
            <div class="content cm-rich"><?php echo sanitizeRichHtml($communication['content']); ?></div>

            <?php
            $__atts = Communication::getAttachments((int)$communication['id']);
            if (!empty($__atts)): ?>
                <div class="comm-attachments">
                    <h3>Allegati</h3>
                    <div class="comm-att-list">
                        <?php foreach ($__atts as $att): ?>
                            <a href="<?= htmlspecialchars(Communication::attachmentUrl((int)$att['id'])) ?>" target="_blank" class="comm-att-item">
                                <span class="comm-att-icon">
                                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/></svg>
                                </span>
                                <span class="comm-att-info">
                                    <span class="comm-att-name"><?= htmlspecialchars($att['original_name']) ?></span>
                                    <span class="comm-att-meta"><?= number_format($att['size_bytes']/1024, 0) ?> KB</span>
                                </span>
                                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/></svg>
                            </a>
                        <?php endforeach; ?>
                    </div>
                </div>
            <?php endif; ?>
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
    <?php $__commTotal = is_array($communications) ? count($communications) : 0; ?>
    <div class="emp-banner">
        <div>
            <h2>Comunicazioni</h2>
            <p>
                Avvisi, news e comunicazioni dall'azienda.
                <?php if ($unreadCount > 0): ?>
                    <strong style="color:#0b3aa4;"><?= $unreadCount ?> da leggere</strong>.
                <?php else: ?>
                    Tutte le comunicazioni sono state lette.
                <?php endif; ?>
            </p>
        </div>
    </div>
    <style>
    .emp-banner {
        background: white;
        border: 1px solid #e6e8f0;
        border-left: 4px solid #0b3aa4;
        border-radius: 14px;
        padding: 18px 22px;
        margin-bottom: 16px;
        box-shadow: 0 1px 2px rgba(15,23,42,0.04);
    }
    .emp-banner h2 {
        font-family: 'Host Grotesk', sans-serif;
        margin: 0 0 4px;
        font-size: 19px; font-weight: 700;
        color: #0b3aa4; letter-spacing: -0.02em;
    }
    .emp-banner p { margin: 0; font-size: 13px; color: #6e7191; }
    </style>
    <?php
    // Conteggi per tab filtri
    $__total = count($communications);
    $__byPri = ['high' => 0, 'normal' => 0, 'low' => 0];
    foreach (Communication::getActive($employee['id']) as $__c) {
        if (isset($__byPri[$__c['priority']])) $__byPri[$__c['priority']]++;
    }
    ?>
    <!-- Filtri tab -->
    <div class="cm-filters">
        <div class="cm-tabs">
            <a href="communications.php" class="cm-tab <?= !$filterPriority ? 'active' : '' ?>">
                Tutte<span class="tab-count"><?= array_sum($__byPri) ?></span>
            </a>
            <a href="?priority=high" class="cm-tab <?= $filterPriority === 'high' ? 'active' : '' ?>">
                Alta<?php if ($__byPri['high'] > 0): ?><span class="tab-count tab-count-warn"><?= $__byPri['high'] ?></span><?php endif; ?>
            </a>
            <a href="?priority=normal" class="cm-tab <?= $filterPriority === 'normal' ? 'active' : '' ?>">
                Normale<?php if ($__byPri['normal'] > 0): ?><span class="tab-count"><?= $__byPri['normal'] ?></span><?php endif; ?>
            </a>
            <a href="?priority=low" class="cm-tab <?= $filterPriority === 'low' ? 'active' : '' ?>">
                Bassa<?php if ($__byPri['low'] > 0): ?><span class="tab-count"><?= $__byPri['low'] ?></span><?php endif; ?>
            </a>
        </div>
    </div>

    <?php if (empty($communications)): ?>
        <div class="cm-empty">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" width="42" height="42"><path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/></svg>
            <h3>Nessuna comunicazione</h3>
            <p><?= $filterPriority ? 'Non ci sono comunicazioni con questa priorità.' : 'Nessuna comunicazione attiva al momento.' ?></p>
        </div>
    <?php else: ?>
        <div class="cm-list">
            <?php foreach ($communications as $comm):
                $isUnread = empty($comm['is_read']);
                $priClass = $comm['priority'];
                $priLabel = Communication::PRIORITIES[$comm['priority']] ?? $comm['priority'];
                $preview  = trim(substr(strip_tags($comm['content']), 0, 180));
            ?>
                <a href="?id=<?= (int)$comm['id'] ?>" class="cm-card <?= $isUnread ? 'is-unread' : '' ?>">
                    <div class="cm-card-side cm-prio-<?= e($priClass) ?>"></div>
                    <div class="cm-card-body">
                        <div class="cm-card-head">
                            <h2><?php if ($isUnread): ?><span class="cm-dot"></span><?php endif; ?><?= htmlspecialchars($comm['title']) ?></h2>
                            <span class="cm-pri cm-pri-<?= e($priClass) ?>"><?= e($priLabel) ?></span>
                        </div>
                        <?php if ($preview): ?>
                            <p class="cm-preview"><?= htmlspecialchars($preview) ?><?= mb_strlen($preview) >= 180 ? '…' : '' ?></p>
                        <?php endif; ?>
                        <div class="cm-card-foot">
                            <span class="cm-date">
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" width="12" height="12"><rect x="3" y="4" width="18" height="18" rx="2"/><path d="M16 2v4M8 2v4M3 10h18"/></svg>
                                <?= formatDate($comm['publish_date']) ?>
                            </span>
                            <span class="cm-read-more">
                                Leggi
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" width="12" height="12"><line x1="5" y1="12" x2="19" y2="12"/><polyline points="12 5 19 12 12 19"/></svg>
                            </span>
                        </div>
                    </div>
                </a>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>

    <style>
    .cm-filters {
        background: white;
        border: 1px solid #e6e8f0;
        border-radius: 12px;
        padding: 6px;
        margin-bottom: 16px;
        display: flex;
    }
    .cm-tabs {
        display: flex; gap: 2px;
        background: #f1f5f9;
        border-radius: 10px;
        padding: 4px;
        width: 100%;
        flex-wrap: wrap;
    }
    .cm-tab {
        display: inline-flex; align-items: center; gap: 6px;
        flex: 1; min-width: 100px;
        padding: 8px 14px;
        border-radius: 8px;
        font-size: 12px; font-weight: 600;
        color: #6e7191;
        text-decoration: none;
        transition: all .12s ease;
        justify-content: center;
    }
    .cm-tab:hover { color: #0b3aa4; }
    .cm-tab.active {
        background: white;
        color: #0b3aa4;
        box-shadow: 0 1px 3px rgba(15,23,42,0.08);
    }
    .tab-count {
        background: rgba(11,58,164,0.10);
        color: #0b3aa4;
        font-size: 10px; font-weight: 700;
        padding: 1px 7px; border-radius: 999px;
    }
    .tab-count-warn {
        background: rgba(247,92,108,0.10);
        color: #cc2d39;
    }

    .cm-empty {
        background: white;
        border: 1px solid #e6e8f0;
        border-radius: 14px;
        padding: 48px 18px;
        text-align: center;
    }
    .cm-empty svg { color: #cbd5e0; margin-bottom: 10px; }
    .cm-empty h3 {
        font-family: 'Host Grotesk', sans-serif;
        font-size: 15px; font-weight: 700;
        color: #475569; margin: 0 0 4px;
    }
    .cm-empty p { color: #94a3b8; margin: 0; font-size: 13px; }

    .cm-list { display: flex; flex-direction: column; gap: 10px; }
    .cm-card {
        display: flex;
        background: white;
        border: 1px solid #e6e8f0;
        border-radius: 12px;
        text-decoration: none;
        transition: all .12s ease;
        overflow: hidden;
    }
    .cm-card:hover {
        border-color: #0b3aa4;
        transform: translateY(-1px);
        box-shadow: 0 6px 18px rgba(11,58,164,0.08);
    }
    .cm-card-side {
        width: 4px; flex-shrink: 0;
    }
    .cm-prio-high   { background: #f75c6c; }
    .cm-prio-normal { background: #94a3b8; }
    .cm-prio-low    { background: #adc1e8; }

    .cm-card-body { flex: 1; padding: 14px 18px; min-width: 0; }
    .cm-card-head {
        display: flex; justify-content: space-between; align-items: flex-start;
        gap: 10px; margin-bottom: 6px;
    }
    .cm-card-head h2 {
        font-family: 'Host Grotesk', sans-serif;
        font-size: 14px; font-weight: 700;
        color: #1e1e2f; margin: 0;
        letter-spacing: -0.01em;
        line-height: 1.35;
        display: flex; align-items: center; gap: 8px; flex-wrap: wrap;
    }
    .cm-dot {
        display: inline-block;
        width: 7px; height: 7px;
        background: #0b3aa4;
        border-radius: 50%;
        flex-shrink: 0;
    }
    .is-unread .cm-card-head h2 { color: #0b3aa4; }
    .cm-pri {
        padding: 2px 9px;
        border-radius: 999px;
        font-size: 10px; font-weight: 700;
        text-transform: uppercase; letter-spacing: 0.04em;
        white-space: nowrap;
        flex-shrink: 0;
    }
    .cm-pri-high   { background: #fde2e5; color: #cc2d39; }
    .cm-pri-normal { background: #f1f5f9; color: #475569; }
    .cm-pri-low    { background: rgba(11,58,164,0.10); color: #0b3aa4; }
    .cm-preview {
        font-size: 12.5px; color: #6e7191;
        line-height: 1.5;
        margin: 0 0 8px;
        overflow: hidden;
        display: -webkit-box;
        -webkit-line-clamp: 2;
        -webkit-box-orient: vertical;
    }
    .cm-card-foot {
        display: flex; justify-content: space-between; align-items: center;
        padding-top: 8px;
        border-top: 1px solid #f1f5f9;
    }
    .cm-date {
        display: inline-flex; align-items: center; gap: 6px;
        font-size: 11px; color: #94a3b8;
    }
    .cm-read-more {
        display: inline-flex; align-items: center; gap: 4px;
        font-size: 11px; font-weight: 600; color: #0b3aa4;
    }

    @media (max-width: 640px) {
        .cm-tabs {
            display: grid !important;
            grid-template-columns: repeat(2, 1fr);
            gap: 4px;
        }
        .cm-tab { min-width: 0; padding: 9px 8px; }
        .cm-card-body { padding: 12px 14px; }
        .cm-card-head h2 { font-size: 13px; }
    }
    </style>
<?php endif; ?>

<?php include dirname(__DIR__) . '/includes/footer-employee.php'; ?>
