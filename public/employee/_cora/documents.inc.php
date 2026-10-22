<?php
/**
 * Documenti — interfaccia Cora (Figma 901:4284 elenco, 901:4571 vuota).
 * Inclusa da employee/documents.php dopo la gestione dei download:
 * buste paga e CU ($documents), documenti personali e aziendali ($personalDocs).
 */
// Accesso diretto al file: niente
if (!class_exists('CoraUi', false)) { http_response_code(404); exit; }
$coraPage = true;
$pageTitle = 'Documenti';
$__b = PUBLIC_URL;
$__empId = (int) $employee['id'];

// Documenti personali gia' scaricati dal dipendente
$__seenPersonal = [];
try {
    foreach (Database::fetchAll(
        "SELECT DISTINCT employee_document_id FROM employee_document_downloads WHERE user_type = 'employee' AND user_id = ?",
        [$__empId]
    ) as $__r) {
        $__seenPersonal[(int) $__r['employee_document_id']] = true;
    }
} catch (Throwable $__e) {}

$__months = ['', 'Gennaio', 'Febbraio', 'Marzo', 'Aprile', 'Maggio', 'Giugno', 'Luglio', 'Agosto',
             'Settembre', 'Ottobre', 'Novembre', 'Dicembre'];

// Elenco unico: ogni card ha categoria (chip), tag per i filtri, link di download
$__items = [];
foreach ($documents as $__d) {
    $__type = $__d['type'];
    $__isContract = $__type === 'other' && preg_match('/contratt/i', (string) $__d['title']);
    [$__cat, $__tag] = match (true) {
        $__type === 'payslip' => ['Busta paga', 'payslip'],
        $__type === 'cud'     => ['CU', 'cu'],
        $__isContract         => ['Contratto', 'contract'],
        default               => ['Documento', 'other'],
    };
    $__items[] = [
        'title' => $__d['title'],
        'meta'  => CoraUi::ext($__d['original_name'] ?? $__d['file_name'] ?? '', $__d['mime_type'] ?? null)
                 . ' - ' . CoraUi::size((int) $__d['file_size']) . ' - ' . CoraUi::shortDate($__d['created_at']),
        'cat'   => $__cat,
        'tag'   => $__tag,
        'new'   => empty($__d['is_downloaded']),
        'date'  => $__d['created_at'],
        'year'  => (int) ($__d['year'] ?? date('Y', strtotime($__d['created_at']))),
        'url'   => $__b . '/employee/documents.php?download=' . (int) $__d['id'],
    ];
}
foreach ($personalDocs as $__d) {
    $__bulk = !empty($__d['bulk_group']);
    $__isContract = !$__bulk && preg_match('/contratt/i', (string) $__d['name']);
    [$__cat, $__tag] = $__bulk ? ['Aziendale', 'company'] : ($__isContract ? ['Contratto', 'contract'] : ['Personale', 'personal']);
    $__meta = CoraUi::ext($__d['original_name'] ?? $__d['file_name'] ?? '', $__d['mime_type'] ?? null)
            . ' - ' . CoraUi::size((int) $__d['file_size']) . ' - ' . CoraUi::shortDate($__d['created_at']);
    if (!empty($__d['expires_on'])) $__meta .= ' - scade il ' . date('d/m/Y', strtotime($__d['expires_on']));
    $__items[] = [
        'title' => $__d['name'],
        'meta'  => $__meta,
        'cat'   => $__cat,
        'tag'   => $__tag,
        'new'   => empty($__seenPersonal[(int) $__d['id']]),
        'date'  => $__d['created_at'],
        'year'  => (int) date('Y', strtotime($__d['created_at'])),
        'url'   => $__b . '/employee/documents.php?download_personal=' . (int) $__d['id'],
    ];
}

// Anno (menu "Tutti gli anni"): $filterYear filtra gia' buste paga e CU, qui anche il resto
$__years = [];
foreach ($__items as $__it) $__years[$__it['year']] = true;
foreach ((array) $availableYears as $__y) $__years[(int) (is_array($__y) ? reset($__y) : $__y)] = true;
krsort($__years);
if ($filterYear) {
    $__items = array_values(array_filter($__items, static fn($it) => $it['year'] === (int) $filterYear));
}
usort($__items, static fn($a, $b) => strcmp((string) $b['date'], (string) $a['date']));

$__toRead = array_values(array_filter($__items, static fn($it) => $it['new']));
$__archive = array_values(array_filter($__items, static fn($it) => !$it['new']));
$__hasAny = (bool) $__items;

include dirname(__DIR__, 2) . '/includes/header-employee.php';

$__yearSel = '';
if ($__years) {
    ob_start(); ?>
    <form method="GET" class="cora-select">
        <select name="year" onchange="this.form.submit()" aria-label="Anno">
            <option value="">Tutti gli anni</option>
            <?php foreach (array_keys($__years) as $__y): ?>
                <option value="<?= (int) $__y ?>"<?= (int) $filterYear === (int) $__y ? ' selected' : '' ?>><?= (int) $__y ?></option>
            <?php endforeach; ?>
        </select>
        <?= CoraUi::svg('ic-chev-d.svg') ?>
    </form>
    <?php $__yearSel = (string) ob_get_clean();
}

$__card = static function (array $it): string {
    ob_start(); ?>
    <a class="cora-doc" href="<?= htmlspecialchars($it['url']) ?>" data-cora-filterable
       data-tags="<?= htmlspecialchars($it['tag'] . ($it['new'] ? ' unread' : '')) ?>"
       data-search="<?= htmlspecialchars(mb_strtolower($it['title'] . ' ' . $it['cat'])) ?>">
        <span class="cora-doc-top">
            <span class="cora-doc-ic"><?= CoraUi::svg('ic-file.svg') ?></span>
            <?php if ($it['new']): ?><span class="cora-doc-new">Nuovo</span><?php endif; ?>
        </span>
        <span class="cora-doc-txt">
            <strong><?= htmlspecialchars($it['title']) ?></strong>
            <span><?= htmlspecialchars($it['meta']) ?></span>
        </span>
        <span class="cora-doc-bottom">
            <span class="cora-doc-cat"><?= htmlspecialchars($it['cat']) ?></span>
            <span class="cora-doc-dl" title="Scarica"><?= CoraUi::svg('ic-down.svg') ?></span>
        </span>
    </a>
    <?php return (string) ob_get_clean();
};
?>
<div class="cora-reveal" style="--i:0"><?= CoraUi::topbar('I tuoi documenti', 'Buste paga, CU, contratto e documenti aziendali: sempre disponibili da scaricare.', $__hasAny || $filterYear ? $__yearSel : '') ?></div>

<?php if (!empty($error)): ?>
    <div class="cora-alert is-error"><?= htmlspecialchars($error) ?></div>
<?php endif; ?>

<?php if (!$__hasAny): ?>
    <section class="cora-card cora-empty cora-empty-page cora-reveal" style="--i:1">
        <span class="cora-empty-ic"><?= CoraUi::svg('ic-empty.svg') ?></span>
        <h3>Nessun documento</h3>
        <p><?= $filterYear ? 'Non ci sono documenti per questo anno.' : 'Quando l’azienda caricherà buste paga o documenti per te, li troverai qui.' ?></p>
    </section>
<?php else: ?>
    <section class="cora-card cora-docs cora-reveal" style="--i:1" id="coraDocs">
        <div class="cora-filters" data-cora-filter-group="#coraDocs" data-tour="docs-filters">
            <?php foreach (['all' => 'Tutti', 'unread' => 'Da leggere', 'payslip' => 'Buste paga', 'cu' => 'CU', 'contract' => 'Contratti', 'company' => 'Aziendali'] as $__k => $__l): ?>
                <button type="button" class="cora-tab<?= $__k === 'all' ? ' is-active' : '' ?>" data-filter="<?= $__k ?>">
                    <?= $__l ?>
                    <?php if ($__k === 'unread' && $__toRead): ?><span class="cora-tab-count"><?= count($__toRead) ?></span><?php endif; ?>
                </button>
            <?php endforeach; ?>
            <span class="cora-filters-spacer"></span>
            <label class="cora-search">
                <?= CoraUi::svg('ic-search.svg') ?>
                <input type="search" placeholder="Cerca un documento" data-cora-search aria-label="Cerca un documento">
            </label>
        </div>

        <?php foreach ([['Da leggere', $__toRead], ['Archivio', $__archive]] as [$__secTitle, $__secItems]):
            if (!$__secItems) continue; ?>
            <div class="cora-doc-section" data-cora-section>
                <h2 class="cora-section-h"><?= $__secTitle ?> <span data-cora-section-count><?= count($__secItems) ?></span></h2>
                <div class="cora-doc-grid">
                    <?php foreach ($__secItems as $__it) echo $__card($__it); ?>
                </div>
            </div>
        <?php endforeach; ?>

        <div class="cora-empty" data-cora-filter-empty hidden style="min-height: 260px">
            <span class="cora-empty-ic"><?= CoraUi::svg('ic-empty.svg') ?></span>
            <h3>Nessun documento</h3>
            <p>Nessun documento corrisponde al filtro scelto.</p>
        </div>
    </section>
<?php endif; ?>

<?php include dirname(__DIR__, 2) . '/includes/footer-employee.php'; ?>
