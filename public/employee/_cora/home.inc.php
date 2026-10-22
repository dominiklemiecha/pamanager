<?php
/**
 * Home dipendente — interfaccia Cora (Figma 744:122 "Dashboard Dipendente",
 * intro 751:2112 / 751:2232). Inclusa da employee/index.php, che ha gia'
 * calcolato $employee, $__balances, $communications, $allDocs.
 */
// Accesso diretto al file: niente
if (!class_exists('CoraUi', false)) { http_response_code(404); exit; }
$coraPage = true;
$pageTitle = 'Home';
$__b = PUBLIC_URL;
$__empId = (int) $employee['id'];
$__cid = (int) $employee['company_id'];

// Saluto e data ("Venerdì 16 Ottobre 2026")
$__hour = (int) date('H');
$__greet = $__hour < 13 ? 'Buongiorno' : ($__hour < 18 ? 'Buon pomeriggio' : 'Buonasera');
$__days = ['Domenica', 'Lunedì', 'Martedì', 'Mercoledì', 'Giovedì', 'Venerdì', 'Sabato'];
$__monthsCap = ['', 'Gennaio', 'Febbraio', 'Marzo', 'Aprile', 'Maggio', 'Giugno', 'Luglio', 'Agosto',
                'Settembre', 'Ottobre', 'Novembre', 'Dicembre'];
$__todayLabel = $__days[(int) date('w')] . ' ' . (int) date('j') . ' ' . $__monthsCap[(int) date('n')] . ' ' . date('Y');

// Compleanni di oggi (il festeggiato vede gli auguri, gli altri l'invito a farli)
$__bday = null;
try {
    $__bdRows = Database::fetchAll(
        "SELECT id, first_name, last_name, photo_path FROM employees
         WHERE is_active = TRUE AND birth_date IS NOT NULL AND company_id = ?
           AND DATE_FORMAT(birth_date, '%m-%d') = ?
         ORDER BY (id = ?) DESC, first_name",
        [$__cid, date('m-d'), $__empId]
    );
    if ($__bdRows) {
        $__first = $__bdRows[0];
        $__isMe = (int) $__first['id'] === $__empId;
        $__others = array_values(array_filter($__bdRows, static fn($r) => (int) $r['id'] !== $__empId));
        if ($__isMe) {
            $__bday = ['me' => true, 'text' => 'Buon compleanno, ' . CoraUi::name($__first['first_name']) . '! Tanti auguri', 'person' => $__first];
        } elseif ($__others) {
            $__names = array_map(static fn($r) => CoraUi::name($r['first_name']) . ' ' . CoraUi::name($r['last_name']), $__others);
            $__last = count($__names) > 1 ? ' e ' . array_pop($__names) : '';
            $__bday = ['me' => false, 'text' => 'Oggi è il compleanno di ' . implode(', ', $__names) . $__last, 'person' => $__others[0]];
        }
    }
} catch (Throwable $__e) {}

// Saldi: il donut mostra la quota utilizzata del totale maturato
$__sal = [];
foreach (['ferie', 'permesso'] as $__t) {
    $__bb = $__balances[$__t] ?? null;
    $__tot = $__bb ? (float) $__bb['total'] : 0.0;
    $__used = $__bb ? (float) $__bb['used'] : 0.0;
    $__sal[$__t] = [
        'title' => ($__t === 'ferie' ? 'Ferie ' : 'Permessi ') . date('Y'),
        'unit'  => $__t === 'ferie' ? 'gg' : 'h',
        'label' => $__t === 'ferie' ? 'giorni residui' : 'ore residue',
        'total' => $__tot,
        'used'  => $__used,
        'resid' => $__bb ? (float) $__bb['residual'] : 0.0,
        'pct'   => $__tot > 0 ? max(0, min(1, $__used / $__tot)) : 0,
    ];
}
$__pendingByType = ['ferie' => 0, 'permesso' => 0];
try {
    foreach (Database::fetchAll(
        "SELECT leave_type, COUNT(*) n FROM leave_requests WHERE employee_id = ? AND status = 'pending'
         AND leave_type IN ('ferie','permesso') GROUP BY leave_type", [$__empId]) as $__r) {
        $__pendingByType[$__r['leave_type']] = (int) $__r['n'];
    }
} catch (Throwable $__e) {}

// Presenze di oggi: colleghi attivi, assenti = assenza approvata oggi (smart working escluso)
$__today = date('Y-m-d');
$__people = [];
try {
    $__people = Database::fetchAll(
        "SELECT e.id, e.first_name, e.last_name, e.photo_path,
                EXISTS (SELECT 1 FROM leave_requests lr
                         WHERE lr.employee_id = e.id AND lr.status = 'approved'
                           AND lr.leave_type <> 'smart_working'
                           AND lr.start_date <= ? AND lr.end_date >= ?) AS is_absent
         FROM employees e
         WHERE e.is_active = TRUE AND e.company_id = ?
         ORDER BY is_absent DESC, e.last_name, e.first_name",
        [$__today, $__today, $__cid]
    );
} catch (Throwable $__e) {}
$__absent = count(array_filter($__people, static fn($p) => (int) $p['is_absent'] === 1));
$__present = count($__people) - $__absent;

// Documenti recenti: buste paga/CU + documenti personali e aziendali visibili
$__docs = [];
foreach ($allDocs as $__d) {
    $__docs[] = ['title' => $__d['title'], 'date' => $__d['created_at'],
                 'url' => $__b . '/employee/documents.php?download=' . (int) $__d['id']];
}
try {
    foreach (EmployeeDocument::getByEmployee($__empId, true) as $__d) {
        $__docs[] = ['title' => $__d['name'], 'date' => $__d['created_at'],
                     'url' => $__b . '/employee/documents.php?download_personal=' . (int) $__d['id']];
    }
} catch (Throwable $__e) {}
usort($__docs, static fn($a, $b) => strcmp((string) $b['date'], (string) $a['date']));
$__docs = array_slice($__docs, 0, 4);

$__comms = array_slice($communications, 0, 3);

$__leaves = [];
try {
    $__leaves = Database::fetchAll(
        "SELECT id, leave_type, start_date, end_date, is_full_day, start_time, end_time, status, created_at
         FROM leave_requests WHERE employee_id = ? ORDER BY created_at DESC LIMIT 4",
        [$__empId]
    );
} catch (Throwable $__e) {}

/** "23 – 29 ott", "8 ott", "30 set – 1 ott" */
$__range = static function (string $s, string $e): string {
    if ($s === $e) return CoraUi::shortDate($s, false);
    if (substr($s, 0, 7) === substr($e, 0, 7)) return (int) date('j', strtotime($s)) . ' – ' . CoraUi::shortDate($e, false);
    return CoraUi::shortDate($s, false) . ' – ' . CoraUi::shortDate($e, false);
};
$__statusLbl = ['pending' => 'In attesa', 'approved' => 'Approvata', 'rejected' => 'Rifiutata', 'cancelled' => 'Annullata'];

// Anello: raggio medio 78, spessore 20 (176px come nel design)
$__R = 78;
$__C = 2 * M_PI * $__R;

include dirname(__DIR__, 2) . '/includes/header-employee.php';
?>
<?php
$__center = '';
if ($__bday) {
    $__p = $__bday['person'];
    $__ph = !empty($__p['photo_path']) ? $__b . '/' . ltrim($__p['photo_path'], '/') : '';
    ob_start(); ?>
    <div class="cora-bday" data-cora-bday hidden>
        <span class="cora-bday-confetti" aria-hidden="true"><?= CoraUi::svg('coriandoli.svg') ?></span>
        <span class="cora-bday-who">
            <span class="cora-bday-avatar">
                <?php if ($__ph): ?><img src="<?= htmlspecialchars($__ph) ?>" alt=""><?php else: ?><?= htmlspecialchars(mb_strtoupper(mb_substr($__p['first_name'], 0, 1) . mb_substr($__p['last_name'], 0, 1))) ?><?php endif; ?>
            </span>
            <span class="cora-bday-text"><?= htmlspecialchars($__bday['text']) ?></span>
        </span>
        <span class="cora-bday-actions">
            <?php if (!$__bday['me']): ?>
                <a class="cora-bday-cta" href="<?= $__b ?>/employee/chat.php">Fai gli auguri</a>
            <?php endif; ?>
            <button type="button" class="cora-bday-close" data-cora-bday-close aria-label="Chiudi"><?= CoraUi::svg('ic-x.svg') ?></button>
        </span>
    </div>
    <?php $__center = (string) ob_get_clean();
}
?>
<div class="cora-reveal" style="--i:0"><?= CoraUi::topbar($__greet . ', ' . CoraUi::name($employee['first_name']), $__todayLabel, '', $__center) ?></div>

<section class="cora-home-row cora-home-saldi cora-reveal" style="--i:0" data-tour="balance">
    <?php foreach ($__sal as $__t => $__s):
        $__dash = $__s['pct'] * $__C; ?>
        <div class="cora-card cora-saldo">
            <h2><?= htmlspecialchars($__s['title']) ?></h2>
            <div class="cora-saldo-body">
                <div class="cora-donut">
                    <svg viewBox="0 0 176 176" width="176" height="176" aria-hidden="true">
                        <circle cx="88" cy="88" r="<?= $__R ?>" fill="none" stroke="var(--cora-accent-soft)" stroke-width="20"/>
                        <circle class="cora-donut-arc" cx="88" cy="88" r="<?= $__R ?>" fill="none" stroke="var(--cora-accent)" stroke-width="20"
                                stroke-dasharray="<?= round($__dash, 2) ?> <?= round($__C, 2) ?>" style="--arc: <?= round($__dash, 2) ?>"
                                transform="rotate(-90 88 88)"/>
                    </svg>
                    <div class="cora-donut-val">
                        <strong><?= CoraUi::num($__s['resid']) ?></strong>
                        <span><?= $__s['label'] ?></span>
                    </div>
                    <?php if ($__pendingByType[$__t] > 0): ?>
                        <a class="cora-donut-pending" href="<?= $__b ?>/employee/leave-requests.php?status=pending" title="<?= $__pendingByType[$__t] ?> richiest<?= $__pendingByType[$__t] === 1 ? 'a' : 'e' ?> in attesa">
                            <?= CoraUi::svg('ic-orologio.svg') ?>
                        </a>
                    <?php endif; ?>
                </div>
                <div class="cora-saldo-rows">
                    <div class="cora-saldo-row"><i class="dot is-used"></i><span>Utilizzati</span><b><?= CoraUi::num($__s['used']) ?> <?= $__s['unit'] ?></b></div>
                    <div class="cora-saldo-row"><i class="dot is-resid"></i><span>Residui</span><b><?= CoraUi::num($__s['resid']) ?> <?= $__s['unit'] ?></b></div>
                    <div class="cora-saldo-row"><i class="dot is-total"></i><span>Totale maturato</span><b><?= CoraUi::num($__s['total']) ?> <?= $__s['unit'] ?></b></div>
                </div>
            </div>
        </div>
    <?php endforeach; ?>
</section>

<section class="cora-home-row cora-home-actions cora-reveal" style="--i:1" data-tour="quick-actions">
    <a class="cora-card cora-quick" href="<?= $__b ?>/employee/leave-requests.php?new=ferie">
        <span class="cora-quick-txt"><strong>Richiedi ferie</strong><span>Crea una nuova richiesta</span></span>
        <span class="cora-quick-logo"><?= CoraUi::svg('logo-cora.svg') ?></span>
    </a>
    <div class="cora-card cora-presence">
        <div class="cora-card-h">
            <h2>Presenze di oggi</h2>
            <a href="#" class="cora-see-all" data-cora-open="coraPresenceModal">Vedi tutti <?= CoraUi::svg('ic-arr-r.svg') ?></a>
        </div>
        <div class="cora-presence-body">
            <div class="cora-avatars">
                <?php foreach (array_slice($__people, 0, 7) as $__i => $__p):
                    $__ph = !empty($__p['photo_path']) ? $__b . '/' . ltrim($__p['photo_path'], '/') : ''; ?>
                    <span class="cora-av" style="z-index: <?= 10 - $__i ?>" title="<?= htmlspecialchars(CoraUi::name($__p['first_name']) . ' ' . CoraUi::name($__p['last_name'])) ?>">
                        <?php if ($__ph): ?><img src="<?= htmlspecialchars($__ph) ?>" alt="" loading="lazy"><?php else: ?><span><?= htmlspecialchars(mb_strtoupper(mb_substr($__p['first_name'], 0, 1) . mb_substr($__p['last_name'], 0, 1))) ?></span><?php endif; ?>
                        <i class="<?= (int) $__p['is_absent'] ? 'is-absent' : 'is-present' ?>"></i>
                    </span>
                <?php endforeach; ?>
            </div>
            <div class="cora-presence-count">
                <span><i class="is-present"></i><?= $__present ?> present<?= $__present === 1 ? 'e' : 'i' ?></span>
                <span><i class="is-absent"></i><?= $__absent ?> assent<?= $__absent === 1 ? 'e' : 'i' ?></span>
            </div>
        </div>
    </div>
    <a class="cora-card cora-quick" href="<?= $__b ?>/employee/leave-requests.php?new=permesso">
        <span class="cora-quick-txt"><strong>Richiedi permesso</strong><span>Ore o intera giornata</span></span>
        <span class="cora-quick-logo"><?= CoraUi::svg('logo-cora.svg') ?></span>
    </a>
</section>

<section class="cora-home-row cora-home-lists cora-reveal" style="--i:2">
    <div class="cora-card cora-list-card<?= $__comms ? "" : " is-empty" ?>">
        <div class="cora-card-h">
            <h2>Avvisi</h2>
            <?php if ($__comms): ?><a href="<?= $__b ?>/employee/communications.php" class="cora-see-all">Vedi tutti <?= CoraUi::svg('ic-arr-r.svg') ?></a><?php endif; ?>
        </div>
        <?php if (!$__comms): ?>
            <div class="cora-list-empty">
                <span class="cora-list-empty-ic"><?= CoraUi::svg('ic-comunicazioni.svg') ?></span>
                <strong>Nessun avviso</strong>
                <span>Qui troverai i nuovi aggiornamenti aziendali</span>
            </div>
        <?php else: ?>
            <div class="cora-rows">
                <?php foreach ($__comms as $__c): ?>
                    <a class="cora-row" href="<?= $__b ?>/employee/communications.php?id=<?= (int) $__c['id'] ?>">
                        <span class="cora-row-ic"><?= CoraUi::svg('ic-comunicazioni.svg') ?></span>
                        <span class="cora-row-txt">
                            <strong><?= htmlspecialchars($__c['title']) ?></strong>
                            <span><?= htmlspecialchars(CoraUi::shortDate($__c['publish_date'])) ?></span>
                        </span>
                        <?php if (empty($__c['is_read'])): ?><i class="cora-unread-dot"></i><?php endif; ?>
                        <span class="cora-row-chev"><?= CoraUi::svg('ic-chev-r.svg') ?></span>
                    </a>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>

    <div class="cora-card cora-list-card<?= $__docs ? "" : " is-empty" ?>">
        <div class="cora-card-h">
            <h2>Documenti recenti</h2>
            <a href="<?= $__b ?>/employee/documents.php" class="cora-see-all">Vedi tutti <?= CoraUi::svg('ic-arr-r.svg') ?></a>
        </div>
        <?php if (!$__docs): ?>
            <div class="cora-list-empty">
                <span class="cora-list-empty-ic"><?= CoraUi::svg('ic-documento.svg') ?></span>
                <strong>Nessun documento</strong>
                <span>Buste paga e documenti arriveranno qui</span>
            </div>
        <?php else: ?>
            <div class="cora-rows">
                <?php foreach ($__docs as $__d): ?>
                    <a class="cora-row" href="<?= htmlspecialchars($__d['url']) ?>">
                        <span class="cora-row-ic"><?= CoraUi::svg('ic-documento.svg') ?></span>
                        <span class="cora-row-txt">
                            <strong><?= htmlspecialchars($__d['title']) ?></strong>
                            <span><?= htmlspecialchars(CoraUi::shortDate($__d['date'])) ?></span>
                        </span>
                        <span class="cora-row-chev"><?= CoraUi::svg('ic-chev-r.svg') ?></span>
                    </a>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>

    <div class="cora-card cora-list-card cora-list-narrow<?= $__leaves ? "" : " is-empty" ?>">
        <div class="cora-card-h">
            <h2>Le tue ultime richieste</h2>
            <a href="<?= $__b ?>/employee/leave-requests.php" class="cora-see-all">Vedi tutte <?= CoraUi::svg('ic-arr-r.svg') ?></a>
        </div>
        <?php if (!$__leaves): ?>
            <div class="cora-list-empty">
                <span class="cora-list-empty-ic"><?= CoraUi::svg('ic-ferie-permessi.svg') ?></span>
                <strong>Nessuna richiesta</strong>
                <span>Le tue richieste di ferie e permessi compariranno qui</span>
            </div>
        <?php else: ?>
            <div class="cora-rows">
                <?php foreach ($__leaves as $__l): ?>
                    <a class="cora-row" href="<?= $__b ?>/employee/leave-requests.php">
                        <span class="cora-row-ic"><?= CoraUi::svg('ic-ferie-permessi.svg') ?></span>
                        <span class="cora-row-txt">
                            <strong><?= htmlspecialchars(LeaveRequest::LEAVE_TYPES[$__l['leave_type']] ?? ucfirst($__l['leave_type'])) ?></strong>
                            <span><?= htmlspecialchars($__range($__l['start_date'], $__l['end_date'])) ?> · <?= $__l['leave_type'] === 'malattia' ? 'comunicata' : 'creata' ?> <?= htmlspecialchars(CoraUi::ago($__l['created_at'])) ?></span>
                        </span>
                        <span class="cora-status is-<?= htmlspecialchars($__l['status']) ?>"><?= $__statusLbl[$__l['status']] ?? htmlspecialchars($__l['status']) ?></span>
                    </a>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>
</section>

<div class="cora-modal" id="coraPresenceModal" hidden>
    <div class="cora-modal-box cora-modal-sm" role="dialog" aria-modal="true" aria-labelledby="coraPresenceTitle">
        <div class="cora-modal-h">
            <div><h2 id="coraPresenceTitle">Presenze di oggi</h2><p><?= $__present ?> present<?= $__present === 1 ? 'e' : 'i' ?> · <?= $__absent ?> assent<?= $__absent === 1 ? 'e' : 'i' ?></p></div>
            <button type="button" class="cora-modal-x" data-cora-close aria-label="Chiudi"><?= CoraUi::svg('ic-x-modal.svg') ?></button>
        </div>
        <div class="cora-people">
            <?php foreach ($__people as $__p):
                $__ph = !empty($__p['photo_path']) ? $__b . '/' . ltrim($__p['photo_path'], '/') : ''; ?>
                <div class="cora-person">
                    <span class="cora-av">
                        <?php if ($__ph): ?><img src="<?= htmlspecialchars($__ph) ?>" alt="" loading="lazy"><?php else: ?><span><?= htmlspecialchars(mb_strtoupper(mb_substr($__p['first_name'], 0, 1) . mb_substr($__p['last_name'], 0, 1))) ?></span><?php endif; ?>
                        <i class="<?= (int) $__p['is_absent'] ? 'is-absent' : 'is-present' ?>"></i>
                    </span>
                    <span class="cora-person-name"><?= htmlspecialchars(CoraUi::name($__p['first_name']) . ' ' . CoraUi::name($__p['last_name'])) ?></span>
                    <span class="cora-person-state <?= (int) $__p['is_absent'] ? 'is-absent' : 'is-present' ?>"><?= (int) $__p['is_absent'] ? 'Assente' : 'Presente' ?></span>
                </div>
            <?php endforeach; ?>
        </div>
    </div>
</div>

<script>
// Liste della Home: le righe che non entrano per intero restano nascoste
(function () {
    function fit() {
        document.querySelectorAll('.cora-home-lists .cora-rows').forEach(function (box) {
            var rows = box.children;
            for (var i = 0; i < rows.length; i++) rows[i].hidden = false;
            if (getComputedStyle(box).overflow !== 'hidden') return;
            var limit = box.getBoundingClientRect().bottom + 1;
            for (var j = 0; j < rows.length; j++) {
                if (rows[j].getBoundingClientRect().bottom > limit) rows[j].hidden = true;
            }
        });
    }
    fit();
    // ripete a fine animazione di ingresso (le righe arrivano spostate di 14px)
    setTimeout(fit, 900);
    window.addEventListener('resize', fit);
})();
(function () {
    var b = document.querySelector('[data-cora-bday]');
    if (!b) return;
    var key = 'cora-bday-' + new Date().toISOString().slice(0, 10);
    try { if (localStorage.getItem(key)) return; } catch (e) {}
    b.hidden = false;
    b.querySelector('[data-cora-bday-close]').addEventListener('click', function () {
        b.hidden = true;
        try { localStorage.setItem(key, '1'); } catch (e) {}
    });
})();
</script>
<?php include dirname(__DIR__, 2) . '/includes/footer-employee.php'; ?>
