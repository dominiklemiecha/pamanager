<?php
/**
 * Ferie e permessi — interfaccia Cora (Figma 900:3907 elenco, 900:4200
 * modale "Nuova richiesta", 900:4571 toast "Richiesta inviata").
 * Inclusa da employee/leave-requests.php dopo la gestione dei POST:
 * il form invia gli stessi campi dell'interfaccia classica.
 */
// Accesso diretto al file: niente
if (!class_exists('CoraUi', false)) { http_response_code(404); exit; }
$coraPage = true;
$pageTitle = 'Ferie e permessi';
$__b = PUBLIC_URL;

// Tutte le richieste (i filtri per stato sono lato client)
$__all = LeaveRequest::getByEmployee((int) $employeeId);
$__counts = ['pending' => 0, 'approved' => 0, 'rejected' => 0];
foreach ($__all as $__r) {
    if (isset($__counts[$__r['status']])) $__counts[$__r['status']]++;
}

$__bal = class_exists('LeaveBalance') ? LeaveBalance::getForEmployee((int) $employeeId, (int) date('Y')) : [];
$__resFerie = (float) ($__bal['ferie']['residual'] ?? 0);
$__resPerm = (float) ($__bal['permesso']['residual'] ?? 0);

$__typeColor = [
    'ferie' => 'ferie', 'permesso' => 'permesso', 'permesso_104' => 'permesso', 'malattia' => 'malattia',
    'smart_working' => 'sw', 'congedo_parentale' => 'altro', 'congedo_separazione' => 'altro',
    'congedo_mestruale' => 'altro', 'altro' => 'altro', 'chiusura' => 'altro',
];
$__statusLbl = ['pending' => 'In attesa', 'approved' => 'Approvata', 'rejected' => 'Rifiutata', 'cancelled' => 'Annullata'];

/** "23 ott → 29 ott 2026", "8 ott 2026", "3 ott 2026 - 14:00 – 18:00" */
$__period = static function (array $r): string {
    $s = $r['start_date']; $e = $r['end_date'];
    if ($s === $e) {
        $out = CoraUi::shortDate($s);
    } else {
        $out = CoraUi::shortDate($s, substr($s, 0, 4) !== substr($e, 0, 4)) . ' → ' . CoraUi::shortDate($e);
    }
    if (empty($r['is_full_day']) && !empty($r['start_time'])) {
        $out .= ' - ' . substr($r['start_time'], 0, 5) . ' – ' . substr((string) $r['end_time'], 0, 5);
    }
    return $out;
};
/** "5 giorni", "1 giorno", "4 ore" */
$__duration = static function (array $r): string {
    $days = LeaveRequest::calculateWorkingDays($r['start_date'], $r['end_date']);
    if (empty($r['is_full_day']) && !empty($r['start_time']) && !empty($r['end_time'])) {
        $h = max(0, (strtotime($r['end_time']) - strtotime($r['start_time'])) / 3600) * max(1, $days);
        $h = CoraUi::num(round($h, 1));
        return $h . ($h === '1' ? ' ora' : ' ore');
    }
    return $days . ($days === 1 ? ' giorno' : ' giorni');
};

// Festivita' per il conteggio dei giorni lavorativi nella modale
$__holidays = [];
foreach ([(int) date('Y'), (int) date('Y') + 1] as $__y) {
    $__holidays = array_merge($__holidays, array_keys(ItalianHolidays::forYear($__y)));
}

// Riapertura della modale dopo un errore di validazione, con i valori inviati
$__post = ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'create') ? $_POST : [];
$__preset = $__post['leave_type'] ?? ($_GET['new'] ?? '');
$__types = [
    'ferie' => ['Ferie', true], 'permesso' => ['Permesso', false], 'malattia' => ['Malattia', true],
    'permesso_104' => ['L.104', false], 'smart_working' => ['Smart working', true], 'altro' => ['Altro', true],
];
if (!isset($__types[$__preset])) $__preset = 'ferie';
$__isFull = isset($__post['is_full_day']) ? $__post['is_full_day'] === '1' : $__types[$__preset][1];

$__flash = [
    'created'    => ['Richiesta inviata', "L'HR riceverà una notifica. Ti avviseremo quando sarà approvata."],
    'cancelled'  => ['Richiesta annullata', ''],
    'docs_saved' => ['Documenti salvati', 'Protocollo e certificato sono stati aggiornati.'],
][$_GET['message'] ?? ''] ?? null;

include dirname(__DIR__, 2) . '/includes/header-employee.php';

$__newBtn = '<button type="button" class="cora-btn cora-btn-primary" data-cora-open="coraLeaveModal" data-tour="leave-form">'
          . CoraUi::svg('logo-cora-white.svg') . 'Nuova richiesta</button>';
?>
<?php if ($__flash): ?>
    <span data-cora-flash data-title="<?= htmlspecialchars($__flash[0]) ?>" data-message="<?= htmlspecialchars($__flash[1]) ?>" hidden></span>
<?php endif; ?>

<div class="cora-reveal" style="--i:0"><?= CoraUi::topbar('Ferie e permessi', 'Le tue richieste di ferie, permessi e malattia, con lo stato di approvazione.', $__newBtn) ?></div>

<?php if ($error): ?>
    <div class="cora-alert is-error"><?= htmlspecialchars($error) ?></div>
<?php endif; ?>

<?php
try { $__sickLate = LeaveRequest::sickPendingDocs(24, (int) $employeeId); } catch (Throwable $e) { $__sickLate = []; }
if ($__sickLate): ?>
    <div class="cora-alert is-error">
        Hai <?= count($__sickLate) ?> richiest<?= count($__sickLate) === 1 ? 'a' : 'e' ?> di malattia senza documenti: apri la richiesta e carica protocollo INPS e certificato.
    </div>
<?php endif; ?>

<section class="cora-stats cora-reveal" style="--i:1">
    <div class="cora-card cora-stat">
        <span class="cora-stat-l">Ferie residue</span>
        <span class="cora-stat-v"><strong><?= CoraUi::num($__resFerie) ?></strong><span>giorni</span></span>
    </div>
    <div class="cora-card cora-stat">
        <span class="cora-stat-l">Permessi residui</span>
        <span class="cora-stat-v"><strong><?= CoraUi::num($__resPerm) ?></strong><span>ore</span></span>
    </div>
    <div class="cora-card cora-stat">
        <span class="cora-stat-l"><i class="cora-stat-dot"></i>In attesa di approvazione</span>
        <span class="cora-stat-v"><strong><?= $__counts['pending'] ?></strong><span><?= $__counts['pending'] === 1 ? 'richiesta' : 'richieste' ?></span></span>
    </div>
</section>

<section class="cora-card cora-table-card cora-reveal" style="--i:2" id="coraLeaveList" data-tour="leave-list">
    <div class="cora-filters" data-cora-filter-group="#coraLeaveList">
        <?php
        $__initial = in_array($_GET['status'] ?? '', ['pending', 'approved', 'rejected'], true) ? $_GET['status'] : 'all';
        foreach (['all' => 'Tutte', 'pending' => 'In attesa', 'approved' => 'Approvate', 'rejected' => 'Rifiutate'] as $__k => $__l): ?>
            <button type="button" class="cora-tab<?= $__initial === $__k ? ' is-active' : '' ?>" data-filter="<?= $__k ?>">
                <?= $__l ?>
                <?php if ($__k === 'pending' && $__counts['pending'] > 0): ?><span class="cora-tab-count"><?= $__counts['pending'] ?></span><?php endif; ?>
            </button>
        <?php endforeach; ?>
    </div>

    <?php if (!$__all): ?>
        <div class="cora-empty" style="min-height: 320px">
            <span class="cora-empty-ic"><?= CoraUi::svg('ic-empty.svg') ?></span>
            <h3>Nessuna richiesta</h3>
            <p>Quando invierai una richiesta di ferie o permesso la troverai qui.</p>
        </div>
    <?php else: ?>
        <div class="cora-table" data-cora-section>
            <div class="cora-tr cora-th">
                <span class="c-type">Tipo</span><span class="c-period">Periodo</span><span class="c-dur">Durata</span>
                <span class="c-status">Stato</span><span class="c-sent">Inviata</span><span class="c-chev"></span>
            </div>
            <?php foreach ($__all as $__r):
                $__id = (int) $__r['id'];
                $__isSick = $__r['leave_type'] === 'malattia';
                $__sub = $__isSick
                    ? 'comunicata il ' . date('d/m', strtotime($__r['created_at']))
                    : 'creata ' . CoraUi::ago($__r['created_at']);
                $__label = LeaveRequest::LEAVE_TYPES[$__r['leave_type']] ?? ucfirst($__r['leave_type']);
            ?>
                <button type="button" class="cora-tr" data-cora-filterable data-tags="<?= htmlspecialchars($__r['status']) ?>" data-cora-open="coraLeave<?= $__id ?>">
                    <span class="c-type">
                        <i class="cora-type-dot is-<?= $__typeColor[$__r['leave_type']] ?? 'altro' ?>"></i>
                        <span><strong><?= htmlspecialchars($__label) ?></strong><small><?= htmlspecialchars($__sub) ?></small></span>
                    </span>
                    <span class="c-period"><?= htmlspecialchars($__period($__r)) ?></span>
                    <span class="c-dur"><?= htmlspecialchars($__duration($__r)) ?></span>
                    <span class="c-status"><span class="cora-status is-<?= htmlspecialchars($__r['status']) ?>"><?= $__statusLbl[$__r['status']] ?? htmlspecialchars($__r['status']) ?></span></span>
                    <span class="c-sent">Inviata il <?= date('d/m - H:i', strtotime($__r['created_at'])) ?></span>
                    <span class="c-chev"><?= CoraUi::svg('ic-chev-r-light.svg') ?></span>
                </button>
            <?php endforeach; ?>
        </div>
        <div class="cora-empty" data-cora-filter-empty hidden style="min-height: 200px">
            <h3>Nessuna richiesta</h3>
            <p>Non ci sono richieste con questo stato.</p>
        </div>
    <?php endif; ?>
</section>

<?php // Dettaglio di ogni richiesta
foreach ($__all as $__r):
    $__id = (int) $__r['id'];
    $__isSick = $__r['leave_type'] === 'malattia';
    $__hasProto = !empty($__r['protocol_number']);
    $__hasCert = !empty($__r['certificate_path']);
    $__waived = !empty($__r['certificate_waived']);
    $__needsDocs = $__isSick && (!$__hasProto || (!$__hasCert && !$__waived)) && in_array($__r['status'], ['pending', 'approved'], true);
?>
<div class="cora-modal" id="coraLeave<?= $__id ?>" hidden>
    <div class="cora-modal-box cora-modal-sm" role="dialog" aria-modal="true">
        <div class="cora-modal-h">
            <div>
                <h2><?= htmlspecialchars(LeaveRequest::LEAVE_TYPES[$__r['leave_type']] ?? ucfirst($__r['leave_type'])) ?></h2>
                <p><?= htmlspecialchars($__period($__r)) ?> · <?= htmlspecialchars($__duration($__r)) ?></p>
            </div>
            <button type="button" class="cora-modal-x" data-cora-close aria-label="Chiudi"><?= CoraUi::svg('ic-x-modal.svg') ?></button>
        </div>
        <dl class="cora-detail">
            <div><dt>Stato</dt><dd><span class="cora-status is-<?= htmlspecialchars($__r['status']) ?>"><?= $__statusLbl[$__r['status']] ?? htmlspecialchars($__r['status']) ?></span></dd></div>
            <div><dt>Inviata</dt><dd><?= date('d/m/Y - H:i', strtotime($__r['created_at'])) ?></dd></div>
            <?php if (!empty($__r['reason'])): ?><div><dt>Motivazione</dt><dd><?= htmlspecialchars($__r['reason']) ?></dd></div><?php endif; ?>
            <?php if (!empty($__r['notes'])): ?><div><dt>Note</dt><dd><?= htmlspecialchars($__r['notes']) ?></dd></div><?php endif; ?>
            <?php if ($__r['status'] === 'rejected' && !empty($__r['rejection_reason'])): ?><div><dt>Motivo del rifiuto</dt><dd><?= htmlspecialchars($__r['rejection_reason']) ?></dd></div><?php endif; ?>
            <?php if ($__isSick): ?>
                <div><dt>Protocollo INPS</dt><dd><?= $__hasProto ? htmlspecialchars($__r['protocol_number']) : '—' ?></dd></div>
                <div><dt>Certificato</dt><dd>
                    <?php if ($__hasCert): ?><a class="cora-link" href="?download_cert=<?= $__id ?>">Scarica</a>
                    <?php elseif ($__waived): ?>Non richiesto<?php else: ?>—<?php endif; ?>
                </dd></div>
            <?php endif; ?>
        </dl>
        <?php if ($__needsDocs): ?>
            <form method="POST" enctype="multipart/form-data" class="cora-form">
                <?= CSRF::field() ?>
                <input type="hidden" name="action" value="add_sick_docs">
                <input type="hidden" name="request_id" value="<?= $__id ?>">
                <div class="cora-alert is-info">Carica numero di protocollo INPS e certificato medico.</div>
                <?php if (!$__hasProto): ?>
                    <label class="cora-field"><span class="cora-label">Numero di protocollo</span>
                        <input class="cora-input" type="text" name="protocol_number" maxlength="100" placeholder="Es. 1234567890"></label>
                <?php endif; ?>
                <?php if (!$__hasCert): ?>
                    <label class="cora-field"><span class="cora-label">Certificato medico</span>
                        <input class="cora-input cora-file" type="file" name="certificate" accept=".pdf,.jpg,.jpeg,.png"></label>
                <?php endif; ?>
                <div class="cora-modal-actions"><button type="submit" class="cora-btn cora-btn-primary"><?= CoraUi::svg('logo-cora-white.svg') ?>Salva documenti</button></div>
            </form>
        <?php endif; ?>
        <?php if ($__r['status'] === 'pending'): ?>
            <form method="POST" class="cora-modal-actions" onsubmit="return confirm('Annullare questa richiesta?')">
                <?= CSRF::field() ?>
                <input type="hidden" name="action" value="cancel">
                <input type="hidden" name="request_id" value="<?= $__id ?>">
                <button type="submit" class="cora-btn cora-btn-secondary cora-btn-danger-text">Annulla richiesta</button>
            </form>
        <?php endif; ?>
    </div>
</div>
<?php endforeach; ?>

<!-- Modale "Nuova richiesta" (Figma 900:4200) -->
<div class="cora-modal" id="coraLeaveModal" hidden>
    <form class="cora-modal-box" method="POST" enctype="multipart/form-data" id="coraLeaveForm" role="dialog" aria-modal="true" aria-labelledby="coraLeaveTitle">
        <?= CSRF::field() ?>
        <input type="hidden" name="action" value="create">
        <input type="hidden" name="leave_type" value="<?= htmlspecialchars($__preset) ?>" data-leave-type>

        <div class="cora-modal-h">
            <div>
                <h2 id="coraLeaveTitle">Nuova richiesta</h2>
                <p>Scegli il tipo e il periodo: la richiesta arriva all’HR per l’approvazione.</p>
            </div>
            <button type="button" class="cora-modal-x" data-cora-close aria-label="Chiudi"><?= CoraUi::svg('ic-x-modal.svg') ?></button>
        </div>

        <?php if ($__post && $error): ?><div class="cora-alert is-error"><?= htmlspecialchars($error) ?></div><?php endif; ?>

        <div class="cora-fieldset" data-tour="leave-types">
            <span class="cora-label">Tipo di richiesta</span>
            <div class="cora-chips" data-leave-types>
                <?php foreach ($__types as $__k => [$__l, $__full]): ?>
                    <button type="button" class="cora-chip<?= $__preset === $__k ? ' is-selected' : '' ?>" data-type="<?= $__k ?>" data-full="<?= $__full ? '1' : '0' ?>"><?= $__l ?></button>
                <?php endforeach; ?>
            </div>
        </div>

        <div class="cora-fieldset">
            <span class="cora-label">Periodo rapido</span>
            <div class="cora-chips" data-leave-presets>
                <button type="button" class="cora-chip" data-preset="today">Oggi</button>
                <button type="button" class="cora-chip" data-preset="tomorrow">Domani</button>
                <button type="button" class="cora-chip" data-preset="rest-of-week">Resto della settimana</button>
                <button type="button" class="cora-chip" data-preset="next-week">Settimana prossima</button>
            </div>
        </div>

        <div class="cora-fieldset">
            <span class="cora-label">Date</span>
            <div class="cora-grid-2">
                <label class="cora-field"><span class="cora-sublabel">Inizio</span>
                    <span class="cora-picker" data-cora-date data-role="start"><input class="cora-input" type="text" inputmode="numeric" autocomplete="off" placeholder="gg/mm/aaaa" aria-label="Data di inizio"><input type="hidden" name="start_date" value="<?= htmlspecialchars($__post['start_date'] ?? '') ?>" data-start><span class="cora-field-ic"><?= CoraUi::svg('ic-cal.svg') ?></span></span></label>
                <label class="cora-field"><span class="cora-sublabel">Fine</span>
                    <span class="cora-picker" data-cora-date data-role="end"><input class="cora-input" type="text" inputmode="numeric" autocomplete="off" placeholder="gg/mm/aaaa" aria-label="Data di fine"><input type="hidden" name="end_date" value="<?= htmlspecialchars($__post['end_date'] ?? '') ?>" data-end><span class="cora-field-ic"><?= CoraUi::svg('ic-cal.svg') ?></span></span></label>
            </div>
        </div>

        <div class="cora-fieldset">
            <span class="cora-label">Durata</span>
            <div class="cora-grid-2">
                <label class="cora-radio"><input type="radio" name="is_full_day" value="1"<?= $__isFull ? ' checked' : '' ?>><i></i>Giornata intera</label>
                <label class="cora-radio"><input type="radio" name="is_full_day" value="0"<?= $__isFull ? '' : ' checked' ?>><i></i>Solo alcune ore</label>
            </div>
            <div class="cora-grid-2" data-leave-times<?= $__isFull ? ' hidden' : '' ?>>
                <label class="cora-field"><span class="cora-sublabel">Dalle ore</span>
                    <span class="cora-picker" data-cora-time><input class="cora-input" type="text" inputmode="numeric" autocomplete="off" maxlength="5" placeholder="hh:mm" name="start_time" value="<?= htmlspecialchars($__post['start_time'] ?? '09:00') ?>" data-start-time><span class="cora-field-ic"><svg viewBox="0 0 18 18" width="18" height="18" fill="none" aria-hidden="true"><circle cx="9" cy="9" r="6.75" stroke="#8A9794" stroke-width="1.35"/><path d="M9 5.25V9l2.25 1.5" stroke="#8A9794" stroke-width="1.35" stroke-linecap="round" stroke-linejoin="round"/></svg></span></span></label>
                <label class="cora-field"><span class="cora-sublabel">Alle ore</span>
                    <span class="cora-picker" data-cora-time><input class="cora-input" type="text" inputmode="numeric" autocomplete="off" maxlength="5" placeholder="hh:mm" name="end_time" value="<?= htmlspecialchars($__post['end_time'] ?? '13:00') ?>" data-end-time><span class="cora-field-ic"><svg viewBox="0 0 18 18" width="18" height="18" fill="none" aria-hidden="true"><circle cx="9" cy="9" r="6.75" stroke="#8A9794" stroke-width="1.35"/><path d="M9 5.25V9l2.25 1.5" stroke="#8A9794" stroke-width="1.35" stroke-linecap="round" stroke-linejoin="round"/></svg></span></span></label>
            </div>
        </div>

        <div class="cora-fieldset" data-leave-sick hidden>
            <span class="cora-label">Documenti malattia</span>
            <div class="cora-grid-2">
                <label class="cora-field"><span class="cora-sublabel">Numero di protocollo INPS</span>
                    <input class="cora-input" type="text" name="protocol_number" maxlength="100" placeholder="Es. 1234567890" value="<?= htmlspecialchars($__post['protocol_number'] ?? '') ?>"></label>
                <label class="cora-field"><span class="cora-sublabel">Certificato medico</span>
                    <input class="cora-input cora-file" type="file" name="certificate" accept=".pdf,.jpg,.jpeg,.png"></label>
            </div>
            <span class="cora-hint">Se non li hai ancora puoi aggiungerli dopo, aprendo la richiesta.</span>
        </div>

        <div class="cora-fieldset">
            <span class="cora-label">Motivazione (facoltativa)</span>
            <textarea class="cora-input cora-textarea" name="reason" rows="3" placeholder="Es. viaggio in famiglia"><?= htmlspecialchars($__post['reason'] ?? '') ?></textarea>
        </div>

        <div class="cora-info" data-leave-info hidden>
            <?= CoraUi::svg('ic-info.svg') ?>
            <span data-leave-info-text></span>
        </div>

        <div class="cora-modal-actions">
            <button type="button" class="cora-btn cora-btn-ghost" data-cora-close>Annulla</button>
            <button type="submit" class="cora-btn cora-btn-primary"><?= CoraUi::svg('logo-cora-white.svg') ?>Invia richiesta</button>
        </div>
    </form>
</div>

<script>
window.CORA_LEAVE = {
    holidays: <?= json_encode(array_values($__holidays)) ?>,
    residual: { ferie: <?= json_encode($__resFerie) ?>, permesso: <?= json_encode($__resPerm) ?> },
    open: <?= json_encode((bool) ($__post && $error) || isset($_GET['new'])) ?>
};
</script>
<script src="<?= $__b ?>/assets/js/cora-pickers.js?v=<?= time() ?>"></script>
<script src="<?= $__b ?>/assets/js/cora-leave.js?v=<?= time() ?>"></script>
<?php include dirname(__DIR__, 2) . '/includes/footer-employee.php'; ?>
