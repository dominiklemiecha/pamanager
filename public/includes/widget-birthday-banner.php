<?php
/**
 * Banner compleanni — mostra messaggi diversi a seconda di chi guarda:
 *   - Festeggiato: "Buon compleanno, Marco! Tanti auguri!" (oggi)
 *   - Altri:       "Oggi e il compleanno di Marco — facciamogli gli auguri!"
 *   - Per il giorno DOMANI, il festeggiato NON vede l'alert anticipato.
 */

// birth_date e' hardcoded visibile a tutti i dipendenti (vedi FieldVisibility::HARDCODED_EMPLOYEE_VISIBLE)
// quindi il banner e' sempre attivo, nessun controllo settings necessario.

// Identifica viewer (dipendente)
$viewerEmployeeId = null;
try {
    $viewerEmp = Auth::getEmployee();
    if ($viewerEmp) $viewerEmployeeId = (int)$viewerEmp['id'];
} catch (Throwable $e) {}

try {
    $today = new DateTime('today');
    $tomorrow = (clone $today)->modify('+1 day');
    $todayMD    = $today->format('m-d');
    $tomorrowMD = $tomorrow->format('m-d');

    $__bdCid = class_exists('Tenant') ? Tenant::currentCompanyId() : 1;
    $rows = Database::fetchAll(
        "SELECT id, first_name, DATE_FORMAT(birth_date, '%m-%d') AS md
         FROM employees
         WHERE is_active = TRUE AND birth_date IS NOT NULL AND company_id = ?
           AND DATE_FORMAT(birth_date, '%m-%d') IN (?, ?)",
        [$__bdCid, $todayMD, $tomorrowMD]
    );
} catch (Throwable $e) {
    return;
}
if (empty($rows)) return;

$todayOthers = [];          // nomi altri festeggiati di OGGI (per chi non e' il festeggiato)
$todayViewerName = null;    // se il viewer e' un festeggiato di oggi, qui il suo nome
$tomorrowOthers = [];       // nomi festeggiati di DOMANI (visibile solo agli altri)

foreach ($rows as $r) {
    $isViewer = ($viewerEmployeeId !== null && (int)$r['id'] === $viewerEmployeeId);
    if ($r['md'] === $todayMD) {
        if ($isViewer) $todayViewerName = $r['first_name'];
        else           $todayOthers[]   = $r['first_name'];
    }
    if ($r['md'] === $tomorrowMD) {
        if (!$isViewer) $tomorrowOthers[] = $r['first_name'];
    }
}

if ($todayViewerName === null && empty($todayOthers) && empty($tomorrowOthers)) return;

$joinNames = function(array $names): string {
    $n = count($names);
    if ($n === 0) return '';
    if ($n === 1) return $names[0];
    if ($n === 2) return $names[0] . ' e ' . $names[1];
    $last = array_pop($names);
    return implode(', ', $names) . ' e ' . $last;
};

?>
<?php // Overlay full-screen SOLO per il festeggiato (no banner). Auto-dismiss + sessionStorage. ?>
<?php if ($todayViewerName !== null): ?>
<div class="bday-overlay" id="bdayOverlay" role="dialog" aria-label="Buon compleanno">
    <div class="bday-overlay-confetti" aria-hidden="true">
        <?php for ($i = 0; $i < 30; $i++): ?>
            <span class="bday-piece" style="--i:<?= $i ?>;
                --left:<?= rand(0, 100) ?>%;
                --delay:<?= rand(0, 2500)/1000 ?>s;
                --dur:<?= rand(3500, 6000)/1000 ?>s;
                --rot:<?= rand(180, 720) ?>deg;
                --size:<?= rand(18, 36) ?>px;"><?= ['&#127874;','&#127881;','&#127882;','&#127880;','&#127873;','&#127872;'][$i % 6] ?></span>
        <?php endfor; ?>
    </div>
    <div class="bday-overlay-card">
        <div class="bday-cake">&#127874;</div>
        <h1 class="bday-title">Buon compleanno,<br><span><?= htmlspecialchars($todayViewerName) ?>!</span></h1>
        <p class="bday-msg">Tantissimi auguri da tutto il team &#127881;</p>
        <button type="button" class="bday-close" id="bdayClose">Grazie! &#127881;</button>
    </div>
</div>
<script>
(function() {
    var ov = document.getElementById('bdayOverlay');
    if (!ov) return;
    var KEY = 'pamBirthdayShown_' + new Date().toISOString().slice(0,10);
    try {
        if (sessionStorage.getItem(KEY) === '1') { ov.parentNode.removeChild(ov); return; }
        sessionStorage.setItem(KEY, '1');
    } catch (e) {}
    function dismiss() {
        ov.classList.add('is-closing');
        setTimeout(function(){ if (ov.parentNode) ov.parentNode.removeChild(ov); }, 600);
    }
    document.getElementById('bdayClose').addEventListener('click', dismiss);
    setTimeout(dismiss, 8000);
})();
</script>
<?php endif; ?>

<?php // Per gli altri: banner normale + confetti se c'e festeggiato di oggi ?>
<?php $showConfetti = !empty($todayOthers); ?>
<?php if ($showConfetti): ?>
<div class="bday-confetti" aria-hidden="true">
    <?php foreach (['&#127874;','&#127880;','&#127881;','&#127882;','&#127881;','&#127874;','&#127880;','&#127881;'] as $i => $e): ?>
        <span style="--i:<?= $i ?>"><?= $e ?></span>
    <?php endforeach; ?>
</div>
<?php endif; ?>

<?php if (!empty($todayOthers) || !empty($tomorrowOthers)): ?>
<div class="birthday-banner">
    <?php if (!empty($todayOthers)): ?>
        <div class="birthday-row birthday-today">
            <span class="birthday-icon">&#127874;</span>
            <div>
                <strong>Oggi e il compleanno di <?= htmlspecialchars($joinNames($todayOthers)) ?>!</strong>
                <span class="birthday-sub">Facciamogli gli auguri!</span>
            </div>
        </div>
    <?php endif; ?>
    <?php if (!empty($tomorrowOthers)): ?>
        <div class="birthday-row birthday-tomorrow">
            <span class="birthday-icon">&#127881;</span>
            <div>
                <strong>Domani e il compleanno di <?= htmlspecialchars($joinNames($tomorrowOthers)) ?></strong>
                <span class="birthday-sub">Ricordati di fare gli auguri!</span>
            </div>
        </div>
    <?php endif; ?>
</div>
<?php endif; ?>
