<?php
/**
 * Comunicazioni — interfaccia Cora (Figma 901:4704 elenco per priorita',
 * 901:4931 comunicazione aperta, 901:5094 vuota).
 * Inclusa da employee/communications.php: $communications (attive per il
 * dipendente, con is_read), $communication (aperta, gia' verificata).
 */
// Accesso diretto al file: niente
if (!class_exists('CoraUi', false)) { http_response_code(404); exit; }
$coraPage = true;
$__b = PUBLIC_URL;

$__prio = static fn(string $p): string => in_array($p, ['high', 'urgent'], true) ? 'high' : ($p === 'low' ? 'low' : 'normal');
$__prioLabel = ['high' => 'Priorità alta', 'normal' => 'Priorità normale', 'low' => 'Priorità bassa'];
$__author = static function (array $c): string {
    $name = trim((string) ($c['author_name'] ?? ''));
    return $name !== '' ? $name : 'HR';
};

if ($communication):
    $pageTitle = $communication['title'];
    $__p = $__prio($communication['priority']);
    $__isRead = (bool) Database::fetchColumn(
        "SELECT 1 FROM communication_reads WHERE communication_id = ? AND employee_id = ?",
        [(int) $communication['id'], (int) $employee['id']]
    );
    $__atts = Communication::getAttachments((int) $communication['id']);
    $__words = preg_split('/\s+/', $__author($communication));
    $__initials = mb_strtoupper(mb_substr($__words[0] ?? '', 0, 1) . mb_substr($__words[1] ?? '', 0, 1));
    include dirname(__DIR__, 2) . '/includes/header-employee.php';
?>
    <?php if (($_GET['message'] ?? '') === 'ack'): ?>
        <span data-cora-flash data-title="Lettura confermata" data-message="L’HR vedrà che hai letto questa comunicazione." hidden></span>
    <?php endif; ?>
    <a class="cora-back cora-reveal" style="--i:0" href="<?= $__b ?>/employee/communications.php"><?= CoraUi::svg('ic-arr-l.svg') ?>Torna alle comunicazioni</a>

    <article class="cora-card cora-comm cora-reveal" style="--i:1">
        <span class="cora-prio-pill is-<?= $__p ?>"><?= CoraUi::svg('ic-flag-' . ($__p === 'high' ? 'alta-sm' : ($__p === 'low' ? 'bassa' : 'normale')) . '.svg') ?><?= $__prioLabel[$__p] ?></span>
        <h1><?= htmlspecialchars($communication['title']) ?></h1>
        <div class="cora-comm-meta">
            <span class="cora-comm-author"><i><?= htmlspecialchars($__initials) ?></i><?= htmlspecialchars($__author($communication)) ?></span>
            <span class="cora-comm-date"><?= CoraUi::svg('ic-cal-sm.svg') ?><?= htmlspecialchars(CoraUi::longDate($communication['publish_date'])) ?></span>
        </div>
        <hr>
        <div class="cora-comm-body cm-rich"><?= sanitizeRichHtml($communication['content']) ?></div>

        <?php foreach ($__atts as $__a): ?>
            <a class="cora-attach" href="<?= htmlspecialchars(Communication::attachmentUrl((int) $__a['id'])) ?>" target="_blank" rel="noopener">
                <span class="cora-attach-ic"><?= CoraUi::svg('ic-file-orange.svg') ?></span>
                <span class="cora-attach-txt">
                    <strong><?= htmlspecialchars($__a['original_name']) ?></strong>
                    <span><?= CoraUi::ext($__a['original_name'], $__a['mime_type'] ?? null) ?> - <?= CoraUi::size((int) $__a['size_bytes']) ?></span>
                </span>
                <span class="cora-attach-dl"><?= CoraUi::svg('ic-down-sm.svg') ?></span>
            </a>
        <?php endforeach; ?>

        <?php if ($__p === 'high' && !$__isRead): ?>
            <form method="POST" class="cora-ack">
                <?= CSRF::field() ?>
                <input type="hidden" name="action" value="ack">
                <span class="cora-ack-txt">
                    <strong>Conferma di presa visione</strong>
                    <span>L’HR ha chiesto di confermare la lettura di questa comunicazione.</span>
                </span>
                <button type="submit" class="cora-btn cora-btn-primary"><?= CoraUi::svg('logo-cora-white.svg') ?>Ho letto</button>
            </form>
        <?php endif; ?>
    </article>
<?php
else:
    $pageTitle = 'Comunicazioni';
    $__groups = ['high' => [], 'normal' => [], 'low' => []];
    $__unread = 0;
    foreach ($communications as $__c) {
        $__groups[$__prio($__c['priority'])][] = $__c;
        if (empty($__c['is_read'])) $__unread++;
    }
    include dirname(__DIR__, 2) . '/includes/header-employee.php';
?>
    <div class="cora-reveal" style="--i:0"><?= CoraUi::topbar('Comunicazioni', 'Avvisi e news dall’azienda. Le più importanti sono sempre in cima.') ?></div>

    <?php if (!$communications): ?>
        <section class="cora-card cora-empty cora-empty-page cora-reveal" style="--i:1">
            <span class="cora-empty-ic"><?= CoraUi::svg('ic-empty.svg') ?></span>
            <h3>Nessuna comunicazione</h3>
            <p>Sei aggiornato: quando l’azienda pubblicherà un avviso lo vedrai qui.</p>
        </section>
    <?php else: ?>
        <section class="cora-card cora-comms cora-reveal" style="--i:1" id="coraComms">
            <div class="cora-filters" data-cora-filter-group="#coraComms" data-tour="comms-filters">
                <?php foreach (['all' => ['Tutte', 0], 'unread' => ['Non lette', $__unread], 'high' => ['Alta', count($__groups['high'])],
                                'normal' => ['Normale', 0], 'low' => ['Bassa', 0]] as $__k => [$__l, $__n]): ?>
                    <button type="button" class="cora-tab<?= $__k === 'all' ? ' is-active' : '' ?>" data-filter="<?= $__k ?>">
                        <?= $__l ?><?php if ($__n > 0): ?><span class="cora-tab-count"><?= $__n ?></span><?php endif; ?>
                    </button>
                <?php endforeach; ?>
            </div>

            <?php foreach ($__groups as $__p => $__list):
                if (!$__list) continue; ?>
                <div class="cora-comm-group" data-cora-section>
                    <h2 class="cora-section-h is-flag"><?= CoraUi::svg('ic-flag-' . ($__p === 'high' ? 'alta' : ($__p === 'low' ? 'bassa' : 'normale')) . '.svg') ?><?= $__prioLabel[$__p] ?></h2>
                    <div class="cora-comm-grid">
                        <?php foreach ($__list as $__c): ?>
                            <a class="cora-comm-card" href="<?= $__b ?>/employee/communications.php?id=<?= (int) $__c['id'] ?>"
                               data-cora-filterable data-tags="<?= $__p . (empty($__c['is_read']) ? ' unread' : '') ?>">
                                <span class="cora-row-ic"><?= CoraUi::svg('ic-mega.svg') ?></span>
                                <span class="cora-row-txt">
                                    <strong><?= htmlspecialchars($__c['title']) ?></strong>
                                    <span><?= htmlspecialchars($__author($__c)) ?> - <?= date('d/m/Y', strtotime($__c['publish_date'])) ?></span>
                                </span>
                                <?php if (empty($__c['is_read'])): ?><i class="cora-unread-dot" title="Non letta"></i><?php endif; ?>
                                <span class="cora-row-chev"><?= CoraUi::svg('ic-chev-r.svg') ?></span>
                            </a>
                        <?php endforeach; ?>
                    </div>
                </div>
            <?php endforeach; ?>

            <div class="cora-empty" data-cora-filter-empty hidden style="min-height: 220px">
                <h3>Nessuna comunicazione</h3>
                <p>Nessuna comunicazione corrisponde al filtro scelto.</p>
            </div>
        </section>
    <?php endif; ?>
<?php endif; ?>
<?php include dirname(__DIR__, 2) . '/includes/footer-employee.php'; ?>
