<?php
/**
 * Academy - modelli attestato: elenco + editor visuale (porting dell'editor di formacamere).
 *
 * Foglio A4 842x595 px (orizzontale) o 595x842 (verticale). Elementi: testo, titolo,
 * immagine (logo/sfondo/firma), linea, rettangolo, QR di verifica. I campi automatici
 * ({NOME_COGNOME}, {CORSO}, ...) vengono sostituiti alla generazione del PDF.
 *
 * Il wrapper deve aver chiamato Auth::init(), setSecurityHeaders(), Auth::requireUser().
 */

$__role = Auth::getUser()['role'];
$area = ['consulente_lavoro' => 'consulente-lavoro', 'formatore' => 'formatore'][$__role] ?? 'admin';
$self = PUBLIC_URL . '/' . $area . '/academy-templates.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    CSRF::verifyOrDie();
    $action = (string) ($_POST['action'] ?? '');

    if ($action === 'upload_image') {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(AcademyCertificate::uploadImage($_FILES['image'] ?? []));
        exit;
    }

    if ($action === 'preview') {
        $pdf = AcademyCertificate::previewPdf((string) ($_POST['orientation'] ?? 'landscape'), (string) ($_POST['layout_json'] ?? '[]'));
        while (ob_get_level()) { ob_end_clean(); }
        header('Content-Type: application/pdf');
        header('Content-Disposition: inline; filename="anteprima-attestato.pdf"');
        header('Content-Length: ' . strlen($pdf));
        echo $pdf;
        exit;
    }

    if ($action === 'save') {
        $id = (int) ($_POST['id'] ?? 0);
        $r = AcademyCertificate::saveTemplate(
            (string) ($_POST['name'] ?? ''),
            (string) ($_POST['orientation'] ?? 'landscape'),
            (string) ($_POST['layout_json'] ?? '[]'),
            $id > 0 ? $id : null
        );
        $q = $r['success'] ? 'edit=' . $r['id'] . '&ok=' . urlencode('Modello salvato') : ($id > 0 ? 'edit=' . $id : 'new=1') . '&err=' . urlencode($r['error']);
        header('Location: ' . $self . '?' . $q);
        exit;
    }
    if ($action === 'duplicate') {
        $r = AcademyCertificate::duplicateTemplate((int) $_POST['id']);
        header('Location: ' . $self . ($r['success'] ? '?edit=' . $r['id'] . '&ok=' . urlencode('Modello duplicato') : '?err=' . urlencode($r['error'])));
        exit;
    }
    if ($action === 'delete') {
        $r = AcademyCertificate::deleteTemplate((int) $_POST['id']);
        header('Location: ' . $self . '?' . ($r['success'] ? 'ok=' . urlencode('Modello eliminato') : 'err=' . urlencode($r['error'])));
        exit;
    }
    header('Location: ' . $self);
    exit;
}

$okMessage = (string) ($_GET['ok'] ?? '');
$errMessage = (string) ($_GET['err'] ?? '');
$edit = null;
$editMode = isset($_GET['new']);
if (isset($_GET['edit'])) {
    $edit = AcademyCertificate::getTemplate((int) $_GET['edit']);
    $editMode = $edit !== null;
}
// Un modello nuovo parte dal layout predefinito, cosi non si parte da un foglio bianco
$layoutJson = $edit ? ($edit['layout_json'] ?: '[]') : json_encode(AcademyCertificate::defaultLayout());
$orientation = $edit['orientation'] ?? 'landscape';

// Anteprima (come formacamere): valori d'esempio o di un dipendente vero dell'azienda
$previewSets = [];
if ($editMode) {
    $previewSets[] = ['label' => 'Dati di esempio', 'values' => AcademyCertificate::sampleValues()];
    $sample = AcademyCertificate::sampleValues();
    $companyName = $sample['{AZIENDA}'];
    foreach (array_slice(Employee::getAll(true), 0, 300) as $e) {
        $values = AcademyCertificate::placeholderValues([
            'first_name' => $e['first_name'], 'last_name' => $e['last_name'], 'fiscal_code' => $e['fiscal_code'],
            'birth_date' => $e['birth_date'] ?? null, 'position' => $e['position'] ?? '',
            'department_name' => $e['department_name'] ?? '', 'company_name' => $companyName,
            'course_title' => $sample['{CORSO}'], 'duration_hours' => 4,
            'started_at' => date('Y-m-d'), 'completed_at' => date('Y-m-d'),
        ], $sample['{NUMERO_ATTESTATO}'], date('Y-m-d H:i:s'), str_repeat('0', 32));
        $previewSets[] = ['label' => $e['last_name'] . ' ' . $e['first_name'], 'values' => $values];
    }
}

$pageTitle = 'Modelli attestato';
include __DIR__ . '/header-admin.php';
?>

<style>
.at-alert { padding: 0.7rem 0.9rem; border-radius: 8px; font-size: 0.86rem; margin-bottom: var(--sp-4); }
.at-alert.ok { background: #dcfce7; color: #166534; }
.at-alert.err { background: #fee2e2; color: #991b1b; }
.at-hero { padding: var(--sp-5) var(--sp-6); margin-bottom: var(--sp-5); display: flex; justify-content: space-between; gap: 1rem; flex-wrap: wrap; align-items: flex-start; }
.at-hero h2 { font-family: 'Space Grotesk', var(--font-sans); font-size: 1.6rem; font-weight: 700; margin: 0 0 6px; color: var(--accent); }
.at-hero p { margin: 0; font-size: var(--text-sm); color: #6e7191; max-width: 640px; }
.at-back { font-size: 0.82rem; color: #64748b; text-decoration: none; display: inline-block; margin-bottom: 6px; }
.at-table { width: 100%; border-collapse: collapse; font-size: 0.88rem; }
.at-table th { text-align: left; font-size: 0.72rem; text-transform: uppercase; color: #94a3b8; font-weight: 600; padding: 0 0.6rem 0.5rem; }
.at-table td { padding: 0.7rem 0.6rem; border-top: 1px solid #eef2f7; }
.at-table td:last-child { text-align: right; white-space: nowrap; }
.at-table form { display: inline; }

/* ---- Editor ---- */
.ed-wrap { display: flex; height: calc(100vh - 150px); min-height: 560px; border: 1px solid #e2e8f0; border-radius: 12px; overflow: hidden; background: #fff; }
.ed-side { width: 250px; min-width: 250px; border-right: 1px solid #e2e8f0; overflow-y: auto; display: flex; flex-direction: column; font-size: 0.8rem; }
.ed-sec { padding: 0.75rem 0.85rem; border-bottom: 1px solid #eef2f7; }
.ed-sec h6 { margin: 0 0 0.5rem; font-size: 0.68rem; text-transform: uppercase; letter-spacing: 0.05em; color: #94a3b8; font-weight: 700; }
.ed-input { width: 100%; padding: 0.4rem 0.55rem; border: 1px solid #e2e8f0; border-radius: 6px; font-size: 0.82rem; box-sizing: border-box; background: #fff; font-family: inherit; }
.ed-label { display: block; font-size: 0.7rem; font-weight: 600; color: #64748b; margin: 0.4rem 0 3px; }
.ed-row { display: flex; gap: 6px; }
.ed-row > * { flex: 1; min-width: 0; }
.ed-btns { display: grid; grid-template-columns: 1fr 1fr; gap: 5px; }
.ed-btn { padding: 0.4rem 0.5rem; border: 1px solid #e2e8f0; background: #f8fafc; border-radius: 6px; cursor: pointer; font-size: 0.78rem; text-align: left; font-family: inherit; color: #334155; }
.ed-btn:hover { background: var(--accent); border-color: var(--accent); color: #fff; }
.ed-chips { display: flex; flex-wrap: wrap; gap: 4px; }
.ed-chip { padding: 2px 6px; background: #eef2ff; color: var(--accent); border-radius: 4px; font-size: 0.7rem; cursor: pointer; font-family: ui-monospace, monospace; }
.ed-chip:hover { background: var(--accent); color: #fff; }
.ed-hint { font-size: 0.72rem; color: #94a3b8; margin: 0.4rem 0 0; line-height: 1.4; }
.ed-props { border: 2px solid var(--accent); border-radius: 8px; margin: 0.6rem; padding: 0.6rem; display: none; }
.ed-props h6 { color: var(--accent); }
.ed-foot { margin-top: auto; padding: 0.75rem; border-top: 1px solid #e2e8f0; display: grid; gap: 6px; }
.ed-canvas-wrap { flex: 1; background: #d5d8dc; overflow: hidden; display: flex; align-items: center; justify-content: center; position: relative; }
.ed-canvas { background: #fff; box-shadow: 0 4px 20px rgba(0,0,0,0.15); position: relative; overflow: hidden; transform-origin: center center; flex-shrink: 0; }
.ed-canvas.landscape { width: 842px; height: 595px; }
.ed-canvas.portrait { width: 595px; height: 842px; }
.cel { position: absolute; cursor: move; border: 1px dashed transparent; box-sizing: border-box; }
.cel:hover:not(.locked) { border-color: rgba(11,58,164,0.45); }
.cel.selected:not(.locked) { border: 1px solid var(--accent); }
.cel.locked { pointer-events: none; }
.cel.el-hidden { display: none !important; }
.cel-resize { position: absolute; width: 10px; height: 10px; background: var(--accent); border-radius: 2px; bottom: -5px; right: -5px; cursor: se-resize; display: none; z-index: 10; }
.cel.selected:not(.locked) .cel-resize { display: block; }
.cel-del { position: absolute; top: -9px; right: -9px; width: 18px; height: 18px; background: #dc2626; color: #fff; border-radius: 50%; font-size: 11px; line-height: 18px; text-align: center; cursor: pointer; display: none; z-index: 10; }
.cel.selected:not(.locked) .cel-del { display: block; }
.cel-text { padding: 2px 4px; line-height: 1.3; word-wrap: break-word; overflow-wrap: break-word; width: 100%; height: 100%; box-sizing: border-box; font-family: Helvetica, Arial, sans-serif; }
.cel-text[contenteditable="true"] { outline: none; cursor: text; background: rgba(11,58,164,0.04); }
.cel-img img { width: 100%; height: 100%; object-fit: contain; pointer-events: none; }
.cel-img .ph { display: flex; align-items: center; justify-content: center; height: 100%; color: #94a3b8; font-size: 11px; background: #f8fafc; cursor: pointer; }
.cel-line { width: 100%; height: 100%; min-height: 1px; }
.cel-rect { width: 100%; height: 100%; border: 2px solid; box-sizing: border-box; }
.cel-qr { width: 100%; height: 100%; display: grid; grid-template-columns: repeat(5, 1fr); gap: 2px; padding: 2px; box-sizing: border-box; }
.cel-qr span { background: currentColor; }
.cel-qr span:nth-child(3n) { background: transparent; }
.ed-layers { position: fixed; bottom: 20px; right: 20px; width: 240px; background: #fff; border-radius: 10px; border: 1px solid #e2e8f0; box-shadow: 0 8px 30px rgba(0,0,0,0.18); z-index: 900; overflow: hidden; }
.ed-layers-head { padding: 0.5rem 0.75rem; background: var(--accent); color: #fff; font-size: 0.72rem; text-transform: uppercase; letter-spacing: 0.05em; cursor: pointer; display: flex; justify-content: space-between; }
.ed-layers.collapsed .ed-layers-body { display: none; }
.ed-layers-list { max-height: 220px; overflow-y: auto; }
.ed-layer { display: flex; align-items: center; gap: 6px; padding: 0.35rem 0.6rem; font-size: 0.75rem; cursor: pointer; border-bottom: 1px solid #f1f5f9; }
.ed-layer:hover { background: #f8fafc; }
.ed-layer.active { background: #eef2ff; border-left: 3px solid var(--accent); }
.ed-layer.dim { opacity: 0.45; }
.ed-layer .nm { flex: 1; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.ed-layer button { border: none; background: none; cursor: pointer; font-size: 0.7rem; color: #64748b; padding: 2px 4px; border-radius: 4px; }
.ed-layer button:hover { background: #e2e8f0; }
.ed-layer-actions { display: flex; gap: 4px; padding: 0.4rem; border-top: 1px solid #e2e8f0; }
.ed-layer-actions button { flex: 1; padding: 0.3rem; font-size: 0.72rem; border: 1px solid #e2e8f0; background: #fff; border-radius: 5px; cursor: pointer; }
.ed-tt { position: fixed; display: none; background: #0f172a; border-radius: 6px; padding: 4px 6px; z-index: 10000; gap: 3px; align-items: center; box-shadow: 0 4px 16px rgba(0,0,0,0.25); }
.ed-tt.visible { display: flex; }
.ed-tt button { width: 28px; height: 28px; border: none; background: transparent; color: #cbd5e1; border-radius: 4px; cursor: pointer; font-size: 13px; }
.ed-tt button:hover { background: rgba(255,255,255,0.15); color: #fff; }
.ed-tt select { height: 28px; background: rgba(255,255,255,0.1); color: #fff; border: 1px solid rgba(255,255,255,0.2); border-radius: 4px; font-size: 11px; }
.ed-tt select option { color: #0f172a; }
.ed-tt input[type=color] { width: 28px; height: 28px; border: none; background: transparent; padding: 0; cursor: pointer; }
.pv-overlay { position: fixed; inset: 0; background: rgba(15,23,42,0.55); z-index: 11000; display: none; align-items: center; justify-content: center; padding: 16px; }
.pv-overlay.open { display: flex; }
.pv-modal { background: #fff; border-radius: 14px; width: min(1100px, 100%); max-height: 100%; display: flex; flex-direction: column; overflow: hidden; box-shadow: 0 20px 60px rgba(0,0,0,0.3); }
.pv-head { display: flex; align-items: center; gap: 0.6rem; padding: 0.8rem 1rem; border-bottom: 1px solid #e2e8f0; flex-wrap: wrap; }
.pv-body { background: #e5e7eb; padding: 24px; overflow: auto; flex: 1; display: flex; justify-content: center; align-items: flex-start; min-height: 300px; }
.pv-sheet { background: #fff; position: relative; box-shadow: 0 4px 20px rgba(0,0,0,0.15); transform-origin: top left; flex-shrink: 0; font-family: Helvetica, Arial, sans-serif; }
.pv-sheet > div { position: absolute; overflow: hidden; box-sizing: border-box; }
.pv-foot { padding: 0.55rem 1rem; font-size: 0.75rem; color: #64748b; border-top: 1px solid #e2e8f0; }
@media (max-width: 900px) {
    .ed-wrap { flex-direction: column; height: auto; }
    .ed-side { width: 100%; min-width: 0; border-right: none; border-bottom: 1px solid #e2e8f0; max-height: 360px; }
    .ed-canvas-wrap { min-height: 420px; }
}
</style>

<?php if (!$editMode): /* ======================= ELENCO ======================= */
    $templates = AcademyCertificate::getTemplates(); ?>

<div class="welcome-card at-hero">
    <div>
        <a class="at-back" href="<?= PUBLIC_URL . '/' . $area ?>/academy.php">&larr; Academy</a>
        <h2>Modelli attestato</h2>
        <p>Disegna l'attestato una volta (logo, sfondo, firma, testi) e sceglilo nei corsi. I dati del dipendente e del corso vengono inseriti in automatico. Senza modello si usa quello predefinito.</p>
    </div>
    <a class="btn btn-primary" href="<?= $self ?>?new=1">+ Nuovo modello</a>
</div>

<?php if ($okMessage !== ''): ?><div class="at-alert ok"><?= htmlspecialchars($okMessage) ?></div><?php endif; ?>
<?php if ($errMessage !== ''): ?><div class="at-alert err"><?= htmlspecialchars($errMessage) ?></div><?php endif; ?>

<?php
/** QR segnaposto (solo visivo): il PDF contiene quello vero di verifica. */
function at_qr_svg(): string
{
    $cells = '';
    $seed = 7;
    for ($y = 0; $y < 21; $y++) {
        for ($x = 0; $x < 21; $x++) {
            $finder = ($x < 7 && $y < 7) || ($x > 13 && $y < 7) || ($x < 7 && $y > 13);
            if ($finder) {
                $on = in_array($x, [0, 6, 14, 20], true) || in_array($y, [0, 6, 14, 20], true)
                    || ((($x >= 2 && $x <= 4) || ($x >= 16 && $x <= 18)) && (($y >= 2 && $y <= 4) || ($y >= 16 && $y <= 18)));
            } else {
                $seed = ($seed * 9301 + 49297) % 233280;
                $on = $seed / 233280 > 0.5;
            }
            if ($on) $cells .= '<rect x="' . $x . '" y="' . $y . '" width="1" height="1"/>';
        }
    }
    return '<svg viewBox="0 0 21 21" width="100%" height="100%" shape-rendering="crispEdges" fill="currentColor">' . $cells . '</svg>';
}

/** Disegna il foglio a grandezza reale (842x595 o 595x842); il JS lo scala nella card. */
function at_render_sheet(array $layout, string $orientation, array $values): string
{
    $w = $orientation === 'portrait' ? 595 : 842;
    $h = $orientation === 'portrait' ? 842 : 595;
    $qr = at_qr_svg();
    $html = '<div class="tp-sheet" style="width:' . $w . 'px;height:' . $h . 'px;" data-w="' . $w . '">';
    foreach ($layout as $i => $el) {
        if (isset($el['visible']) && !$el['visible']) continue;
        $type = $el['type'] ?? 'text';
        $color = preg_match('/^#[0-9a-fA-F]{6}$/', (string) ($el['color'] ?? '')) ? $el['color'] : '#1f2937';
        $style = sprintf('left:%spx;top:%spx;width:%spx;height:%spx;z-index:%d;',
            (float) ($el['x'] ?? 0), (float) ($el['y'] ?? 0), (float) ($el['width'] ?? 100), (float) ($el['height'] ?? 30), $i + 1);
        $inner = '';
        if ($type === 'text' || $type === 'title') {
            $content = (string) ($el['content'] ?? '');
            foreach ($values as $ph => $v) {
                $content = str_replace($ph, htmlspecialchars((string) $v), $content);
            }
            $style .= sprintf('font-size:%spx;color:%s;font-weight:%s;text-align:%s;',
                (float) ($el['fontSize'] ?? 14), $color, !empty($el['bold']) ? 'bold' : 'normal',
                in_array($el['align'] ?? '', ['left', 'center', 'right'], true) ? $el['align'] : 'left');
            $inner = $content; // HTML già ripulito al salvataggio (sanitizeLayout)
        } elseif ($type === 'image' && !empty($el['src'])) {
            $inner = '<img src="' . htmlspecialchars($el['src']) . '" alt="" loading="lazy">';
        } elseif ($type === 'line') {
            $style .= 'background:' . $color . ';';
        } elseif ($type === 'rect') {
            $style .= 'border:2px solid ' . $color . ';';
        } elseif ($type === 'qr') {
            $style .= 'color:' . $color . ';';
            $inner = $qr;
        }
        $html .= '<div class="tp-el tp-' . htmlspecialchars($type) . '" style="' . $style . '">' . $inner . '</div>';
    }
    return $html . '</div>';
}

$__sampleValues = AcademyCertificate::sampleValues();
$__cards = [['id' => 0, 'name' => 'Modello predefinito', 'orientation' => 'landscape',
             'layout' => AcademyCertificate::defaultLayout(), 'course_count' => null]];
foreach ($templates as $t) {
    $__cards[] = ['id' => (int) $t['id'], 'name' => $t['name'], 'orientation' => $t['orientation'],
                  'layout' => json_decode($t['layout_json'] ?: '[]', true) ?: [], 'course_count' => (int) $t['course_count']];
}
?>

<style>
.tp-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(300px, 1fr)); gap: var(--sp-4); }
.tp-card { padding: 1rem; display: flex; flex-direction: column; gap: 0.75rem; }
.tp-card-head { display: flex; justify-content: space-between; align-items: flex-start; gap: 0.5rem; }
.tp-card-head strong { font-size: 0.95rem; color: #0f172a; }
.tp-meta { font-size: 0.76rem; color: #94a3b8; }
.tp-frame { background: #e5e7eb; border-radius: 8px; padding: 10px; display: flex; justify-content: center; text-decoration: none; }
.tp-box { position: relative; overflow: hidden; background: #fff; box-shadow: 0 2px 10px rgba(0,0,0,0.12); width: 100%; }
.tp-box.landscape { aspect-ratio: 842 / 595; }
.tp-box.portrait { aspect-ratio: 595 / 842; width: 62%; }
.tp-sheet { position: absolute; top: 0; left: 0; transform-origin: top left; font-family: Helvetica, Arial, sans-serif; pointer-events: none; }
.tp-el { position: absolute; overflow: hidden; box-sizing: border-box; line-height: 1.3; }
.tp-text, .tp-title { padding: 2px 4px; }
.tp-image img { width: 100%; height: 100%; object-fit: contain; }
.tp-actions { display: flex; gap: 0.4rem; flex-wrap: wrap; }
.tp-actions form { display: inline; }
.tp-actions .grow { flex: 1; text-align: center; }
</style>

<div class="tp-grid">
    <?php foreach ($__cards as $card): ?>
        <section class="card tp-card">
            <div class="tp-card-head">
                <div>
                    <strong><?= htmlspecialchars($card['name']) ?></strong>
                    <div class="tp-meta">
                        <?= $card['orientation'] === 'portrait' ? 'A4 verticale' : 'A4 orizzontale' ?>
                        <?php if ($card['course_count'] !== null): ?>
                            · usato da <?= $card['course_count'] ?> <?= $card['course_count'] === 1 ? 'corso' : 'corsi' ?>
                        <?php else: ?>
                            · usato dai corsi senza modello
                        <?php endif; ?>
                    </div>
                </div>
                <?php if ($card['id'] === 0): ?><span class="badge badge-neutral">Predefinito</span><?php endif; ?>
            </div>
            <a class="tp-frame" href="<?= $card['id'] ? $self . '?edit=' . $card['id'] . '&preview=1' : $self . '?new=1' ?>"
               title="<?= $card['id'] ? 'Apri anteprima ingrandita' : 'Crea un modello partendo da questo' ?>">
                <div class="tp-box <?= $card['orientation'] === 'portrait' ? 'portrait' : 'landscape' ?>">
                    <?= at_render_sheet($card['layout'], $card['orientation'], $__sampleValues) ?>
                </div>
            </a>
            <div class="tp-actions">
                <?php if ($card['id']): ?>
                    <a class="btn btn-sm btn-primary grow" href="<?= $self ?>?edit=<?= $card['id'] ?>">Modifica</a>
                    <form method="POST" action="<?= $self ?>">
                        <?= CSRF::field() ?>
                        <input type="hidden" name="action" value="duplicate">
                        <input type="hidden" name="id" value="<?= $card['id'] ?>">
                        <button type="submit" class="btn btn-sm btn-secondary">Duplica</button>
                    </form>
                    <form method="POST" action="<?= $self ?>" onsubmit="return confirm('Eliminare il modello? I corsi che lo usano passeranno al modello predefinito. Gli attestati già emessi non cambiano.');">
                        <?= CSRF::field() ?>
                        <input type="hidden" name="action" value="delete">
                        <input type="hidden" name="id" value="<?= $card['id'] ?>">
                        <button type="submit" class="btn btn-sm btn-danger">Elimina</button>
                    </form>
                <?php else: ?>
                    <a class="btn btn-sm btn-secondary grow" href="<?= $self ?>?new=1">Crea un modello da questo</a>
                <?php endif; ?>
            </div>
        </section>
    <?php endforeach; ?>
</div>

<script>
// Scala ogni foglio a grandezza reale dentro la sua card
(function () {
    function fit() {
        document.querySelectorAll('.tp-box').forEach(function (box) {
            var sheet = box.querySelector('.tp-sheet');
            sheet.style.transform = 'scale(' + (box.clientWidth / parseFloat(sheet.dataset.w)) + ')';
        });
    }
    fit();
    window.addEventListener('resize', fit);
})();
</script>

<?php else: /* ======================= EDITOR ======================= */ ?>

<div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:0.75rem; gap:1rem; flex-wrap:wrap;">
    <div>
        <a class="at-back" href="<?= $self ?>">&larr; Modelli attestato</a>
        <strong style="font-size:1.05rem; display:block;"><?= $edit ? 'Modifica: ' . htmlspecialchars($edit['name']) : 'Nuovo modello' ?></strong>
    </div>
    <span style="font-size:0.78rem; color:#64748b;">Doppio clic su un testo per modificarlo · trascina per spostare · angolo in basso a destra per ridimensionare</span>
</div>
<?php if ($okMessage !== ''): ?><div class="at-alert ok"><?= htmlspecialchars($okMessage) ?></div><?php endif; ?>
<?php if ($errMessage !== ''): ?><div class="at-alert err"><?= htmlspecialchars($errMessage) ?></div><?php endif; ?>

<form method="POST" action="<?= $self ?>" id="tplForm">
    <?= CSRF::field() ?>
    <input type="hidden" name="action" value="save">
    <input type="hidden" name="id" value="<?= (int) ($edit['id'] ?? 0) ?>">
    <input type="hidden" name="layout_json" id="layoutJson" value="<?= htmlspecialchars($layoutJson) ?>">

    <div class="ed-wrap">
        <aside class="ed-side">
            <div class="ed-sec">
                <h6>Modello</h6>
                <input type="text" class="ed-input" name="name" required maxlength="150" placeholder="Nome del modello" value="<?= htmlspecialchars($edit['name'] ?? '') ?>">
                <label class="ed-label">Formato</label>
                <select class="ed-input" name="orientation" id="orientation" onchange="changeOrientation(this.value)">
                    <option value="landscape" <?= $orientation === 'landscape' ? 'selected' : '' ?>>A4 orizzontale</option>
                    <option value="portrait" <?= $orientation === 'portrait' ? 'selected' : '' ?>>A4 verticale</option>
                </select>
            </div>
            <div class="ed-sec">
                <h6>Aggiungi</h6>
                <div class="ed-btns">
                    <button type="button" class="ed-btn" onclick="addElement('title')">Titolo</button>
                    <button type="button" class="ed-btn" onclick="addElement('text')">Testo</button>
                    <button type="button" class="ed-btn" onclick="addElement('image')">Immagine</button>
                    <button type="button" class="ed-btn" onclick="addElement('qr')">QR verifica</button>
                    <button type="button" class="ed-btn" onclick="addElement('line')">Linea</button>
                    <button type="button" class="ed-btn" onclick="addElement('rect')">Cornice</button>
                </div>
                <p class="ed-hint">Per uno sfondo: aggiungi un'immagine, allargala a tutta pagina e portala in fondo dai livelli.</p>
            </div>
            <?php foreach (AcademyCertificate::PLACEHOLDERS as $group => $list): ?>
                <div class="ed-sec">
                    <h6>Campi - <?= htmlspecialchars($group) ?></h6>
                    <div class="ed-chips">
                        <?php foreach ($list as $ph): ?>
                            <span class="ed-chip" onmousedown="event.preventDefault()" onclick="addPlaceholder('<?= $ph ?>')"><?= $ph ?></span>
                        <?php endforeach; ?>
                    </div>
                </div>
            <?php endforeach; ?>
            <div class="ed-sec">
                <h6>Allinea nella pagina</h6>
                <div class="ed-btns">
                    <button type="button" class="ed-btn" onclick="alignElement('center-h')">Centra orizz.</button>
                    <button type="button" class="ed-btn" onclick="alignElement('center-v')">Centra vert.</button>
                    <button type="button" class="ed-btn" onclick="alignElement('stretch-h')">Larghezza 100%</button>
                    <button type="button" class="ed-btn" onclick="alignElement('fill')">Tutta pagina</button>
                </div>
            </div>

            <div class="ed-props" id="propsPanel">
                <h6>Elemento selezionato</h6>
                <label class="ed-label">Posizione (X, Y)</label>
                <div class="ed-row">
                    <input type="number" class="ed-input" id="propX" onchange="updateProp('x', this.value)">
                    <input type="number" class="ed-input" id="propY" onchange="updateProp('y', this.value)">
                </div>
                <label class="ed-label">Dimensioni (L, A)</label>
                <div class="ed-row">
                    <input type="number" class="ed-input" id="propW" onchange="updateProp('width', this.value)">
                    <input type="number" class="ed-input" id="propH" onchange="updateProp('height', this.value)">
                </div>
                <div id="propTextGroup">
                    <label class="ed-label">Carattere (dimensione, colore)</label>
                    <div class="ed-row">
                        <input type="number" class="ed-input" id="propFontSize" onchange="updateProp('fontSize', this.value)">
                        <input type="color" class="ed-input" id="propColor" style="height:32px; padding:2px;" onchange="updateProp('color', this.value)">
                    </div>
                    <label class="ed-label">Stile</label>
                    <div class="ed-row">
                        <label style="display:flex; gap:4px; align-items:center;"><input type="checkbox" id="propBold" onchange="updateProp('bold', this.checked)"> Grassetto</label>
                        <select class="ed-input" id="propAlign" onchange="updateProp('align', this.value)">
                            <option value="left">Sinistra</option>
                            <option value="center">Centro</option>
                            <option value="right">Destra</option>
                        </select>
                    </div>
                </div>
                <div id="propShapeGroup">
                    <label class="ed-label">Colore</label>
                    <input type="color" class="ed-input" id="propShapeColor" style="height:32px; padding:2px;" onchange="updateProp('color', this.value)">
                </div>
                <button type="button" class="btn btn-sm btn-danger" style="width:100%; margin-top:0.6rem;" onclick="deleteSelected()">Elimina elemento</button>
            </div>

            <div class="ed-foot">
                <button type="button" class="btn btn-secondary" onclick="showPreview()">Anteprima</button>
                <button type="button" class="btn btn-ghost" onclick="previewPdf()">Anteprima PDF</button>
                <button type="submit" class="btn btn-primary">Salva modello</button>
            </div>
        </aside>
        <div class="ed-canvas-wrap" id="canvasWrap">
            <div class="ed-canvas <?= $orientation ?>" id="canvas"></div>
        </div>
    </div>
</form>

<!-- Anteprima a video (come formacamere): disegna il layout corrente con dati d'esempio o di un dipendente -->
<div class="pv-overlay" id="pvOverlay" aria-hidden="true">
    <div class="pv-modal" role="dialog" aria-modal="true" aria-labelledby="pvTitle">
        <div class="pv-head">
            <strong id="pvTitle">Anteprima attestato</strong>
            <select id="pvPerson" class="ed-input" style="max-width:260px;">
                <?php foreach ($previewSets as $i => $set): ?>
                    <option value="<?= $i ?>"><?= htmlspecialchars($set['label']) ?></option>
                <?php endforeach; ?>
            </select>
            <span style="flex:1;"></span>
            <button type="button" class="btn btn-sm btn-secondary" onclick="printPreview()">Stampa</button>
            <button type="button" class="btn btn-sm btn-ghost" onclick="previewPdf()">PDF</button>
            <button type="button" class="btn btn-sm btn-ghost" onclick="closePreview()" aria-label="Chiudi">✕</button>
        </div>
        <div class="pv-body" id="pvBody">
            <div class="pv-sheet" id="pvSheet"></div>
        </div>
        <div class="pv-foot">Anteprima indicativa: il PDF finale usa gli stessi elementi e posizioni. Il QR porta alla pagina di verifica dell'attestato.</div>
    </div>
</div>

<!-- Anteprima: invio del layout corrente al server, il PDF si apre in una nuova scheda -->
<form method="POST" action="<?= $self ?>" target="_blank" id="previewForm" style="display:none;">
    <?= CSRF::field() ?>
    <input type="hidden" name="action" value="preview">
    <input type="hidden" name="orientation" id="previewOrientation">
    <input type="hidden" name="layout_json" id="previewLayout">
</form>

<div class="ed-layers collapsed" id="layers">
    <div class="ed-layers-head" onclick="document.getElementById('layers').classList.toggle('collapsed')">
        <span>Livelli <span id="layerCount" style="opacity:.7;"></span></span><span>▾</span>
    </div>
    <div class="ed-layers-body">
        <div class="ed-layers-list" id="layersList"></div>
        <div class="ed-layer-actions">
            <button type="button" onclick="moveLayer('up')" title="Porta sopra">Su</button>
            <button type="button" onclick="moveLayer('down')" title="Porta sotto">Giù</button>
            <button type="button" onclick="moveLayer('top')" title="In primo piano">In cima</button>
            <button type="button" onclick="moveLayer('bottom')" title="Sullo sfondo">In fondo</button>
        </div>
    </div>
</div>

<input type="file" id="imageUpload" accept="image/png,image/jpeg,image/gif,image/webp" style="display:none;">

<div class="ed-tt" id="textToolbar">
    <button type="button" onmousedown="execFormat(event,'bold')" title="Grassetto"><b>B</b></button>
    <button type="button" onmousedown="execFormat(event,'italic')" title="Corsivo"><i>I</i></button>
    <button type="button" onmousedown="execFormat(event,'underline')" title="Sottolineato"><u>U</u></button>
    <select onchange="execFontSize(event)" title="Dimensione">
        <option value="">px</option>
        <option value="1">8</option><option value="2">10</option><option value="3">12</option>
        <option value="4">14</option><option value="5">18</option><option value="6">24</option><option value="7">32</option>
    </select>
    <input type="color" value="#1f2937" onchange="execColor(event)" title="Colore">
</div>

<script>
// ===================== STATO =====================
var elements = [];
var selected = null;
var idCounter = 1;
var dragging = false, resizing = false;
var dragOffset = { x: 0, y: 0 };
var editingId = null;
var activeEditable = null;
var csrfToken = '<?= CSRF::getToken() ?>';
var NAMES = { text: 'Testo', title: 'Titolo', image: 'Immagine', line: 'Linea', rect: 'Cornice', qr: 'QR verifica' };

function canvasEl() { return document.getElementById('canvas'); }
function canvasSize() {
    var land = canvasEl().classList.contains('landscape');
    return { w: land ? 842 : 595, h: land ? 595 : 842 };
}
function getScale() { return parseFloat(canvasEl().dataset.scale) || 1; }
function esc(s) { return String(s).replace(/[&<>"']/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]; }); }

function scaleCanvas() {
    var wrap = document.getElementById('canvasWrap');
    var size = canvasSize();
    var s = Math.min((wrap.clientWidth - 60) / size.w, (wrap.clientHeight - 60) / size.h, 1);
    canvasEl().style.transform = 'scale(' + s + ')';
    canvasEl().dataset.scale = s;
}

// ===================== INIT =====================
document.addEventListener('DOMContentLoaded', function () {
    try { elements = JSON.parse(document.getElementById('layoutJson').value) || []; } catch (e) { elements = []; }
    elements.forEach(function (el) {
        if (el.locked === undefined) el.locked = false;
        if (el.visible === undefined) el.visible = true;
        if (!el.name) el.name = (NAMES[el.type] || 'Elemento') + ' ' + el.id;
        idCounter = Math.max(idCounter, (el.id || 0) + 1);
    });
    elements.forEach(function (el, i) { renderElement(el, i); });
    refreshLayers();
    scaleCanvas();
    window.addEventListener('resize', scaleCanvas);

    canvasEl().addEventListener('mousedown', function (e) {
        if (e.target === this) { exitTextEditing(); deselect(); }
    });
    document.getElementById('tplForm').addEventListener('submit', function () { exitTextEditing(); saveLayout(); });
});

function changeOrientation(o) {
    canvasEl().classList.remove('portrait', 'landscape');
    canvasEl().classList.add(o);
    setTimeout(scaleCanvas, 30);
}

// ===================== ELEMENTI =====================
function addElement(type) {
    var el = {
        id: idCounter++, type: type, x: 60, y: 60,
        width: { line: 200, image: 150, title: 400, qr: 80 }[type] || 240,
        height: { line: 1, image: 100, title: 44, qr: 80, rect: 120 }[type] || 30,
        content: type === 'title' ? 'ATTESTATO DI PARTECIPAZIONE' : (type === 'text' ? 'Testo' : ''),
        fontSize: type === 'title' ? 26 : 14, color: type === 'qr' ? '#111827' : '#1f2937',
        bold: type === 'title', align: type === 'title' ? 'center' : 'left',
        src: '', locked: false, visible: true, name: ''
    };
    el.name = (NAMES[type] || 'Elemento') + ' ' + el.id;
    elements.push(el);
    renderElement(el, elements.length - 1);
    select(el.id);
    saveLayout();
    refreshLayers();
    if (type === 'image') triggerImageUpload(el.id);
}

function renderElement(el, z) {
    if (z === undefined) z = elements.indexOf(el);
    var div = document.getElementById('el-' + el.id);
    if (!div) {
        div = document.createElement('div');
        div.id = 'el-' + el.id;
        div.className = 'cel';
        div.innerHTML = '<div class="cel-content"></div><div class="cel-resize"></div><div class="cel-del" title="Elimina">×</div>';
        canvasEl().appendChild(div);
        div.addEventListener('mousedown', function (e) { startDrag(e, el.id); });
        div.querySelector('.cel-resize').addEventListener('mousedown', function (e) { startResize(e, el.id); });
        div.querySelector('.cel-del').addEventListener('mousedown', function (e) { e.stopPropagation(); deleteElement(el.id); });
    }
    div.style.left = el.x + 'px';
    div.style.top = el.y + 'px';
    div.style.width = el.width + 'px';
    div.style.height = el.height + 'px';
    div.style.zIndex = z + 1;
    div.classList.toggle('locked', !!el.locked);
    div.classList.toggle('el-hidden', !el.visible);

    var c = div.querySelector('.cel-content');
    c.style.cssText = '';
    if (el.type === 'text' || el.type === 'title') {
        c.className = 'cel-content cel-text';
        var editing = editingId === el.id;
        c.contentEditable = editing && !el.locked ? 'true' : 'false';
        c.style.cursor = editing ? 'text' : 'move';
        if (activeEditable !== c) c.innerHTML = el.content;
        c.style.fontSize = el.fontSize + 'px';
        c.style.color = el.color;
        c.style.fontWeight = el.bold ? 'bold' : 'normal';
        c.style.textAlign = el.align;
        c.onblur = function () {
            el.content = c.innerHTML; activeEditable = null; editingId = null;
            c.contentEditable = 'false'; c.style.cursor = 'move'; hideToolbar(); saveLayout();
        };
        c.onfocus = function () { activeEditable = c; };
        c.ondblclick = function (e) {
            if (el.locked) return;
            e.stopPropagation(); editingId = el.id; c.contentEditable = 'true'; c.style.cursor = 'text'; c.focus();
        };
        c.onmouseup = c.onkeyup = handleSelection;
    } else if (el.type === 'image') {
        c.className = 'cel-content cel-img';
        c.style.width = '100%'; c.style.height = '100%';
        c.innerHTML = el.src
            ? '<img src="' + esc(el.src) + '" alt="">'
            : '<div class="ph">Clicca due volte per caricare</div>';
        c.ondblclick = function (e) { e.stopPropagation(); triggerImageUpload(el.id); };
    } else if (el.type === 'line') {
        c.className = 'cel-content cel-line';
        c.style.backgroundColor = el.color;
    } else if (el.type === 'rect') {
        c.className = 'cel-content cel-rect';
        c.style.borderColor = el.color;
    } else if (el.type === 'qr') {
        c.className = 'cel-content cel-qr';
        c.style.color = el.color;
        if (!c.children.length) c.innerHTML = new Array(26).join('<span></span>');
    }
}

// ===================== TESTO RICCO =====================
function handleSelection() {
    var sel = window.getSelection();
    if (!sel || sel.isCollapsed || !sel.rangeCount) {
        setTimeout(function () { var s2 = window.getSelection(); if (!s2 || s2.isCollapsed) hideToolbar(); }, 200);
        return;
    }
    var r = sel.getRangeAt(0).getBoundingClientRect();
    if (!r.width) return hideToolbar();
    var tb = document.getElementById('textToolbar');
    tb.style.left = Math.max(8, r.left + r.width / 2 - 110) + 'px';
    tb.style.top = Math.max(8, r.top - 44) + 'px';
    tb.classList.add('visible');
}
function hideToolbar() { document.getElementById('textToolbar').classList.remove('visible'); }
function syncEditing() { if (selected && activeEditable) { selected.content = activeEditable.innerHTML; saveLayout(); } }
function execFormat(e, cmd) { e.preventDefault(); document.execCommand(cmd, false, null); syncEditing(); }
function execFontSize(e) { e.preventDefault(); if (e.target.value) { document.execCommand('fontSize', false, e.target.value); syncEditing(); } e.target.value = ''; }
function execColor(e) { e.preventDefault(); document.execCommand('foreColor', false, e.target.value); syncEditing(); }

// ===================== TRASCINA / RIDIMENSIONA =====================
function startDrag(e, id) {
    var el = elements.find(function (x) { return x.id === id; });
    if (!el || el.locked) return;
    if (e.target.closest('.cel-resize') || e.target.closest('.cel-del')) return;
    if (editingId === id) return;
    if (editingId !== null) exitTextEditing();
    e.preventDefault();
    select(id);
    dragging = true;
    var rect = canvasEl().getBoundingClientRect(), s = getScale();
    dragOffset.x = (e.clientX - rect.left) / s - el.x;
    dragOffset.y = (e.clientY - rect.top) / s - el.y;
    document.addEventListener('mousemove', onDrag);
    document.addEventListener('mouseup', stopDrag);
}
function onDrag(e) {
    if (!dragging || !selected) return;
    var rect = canvasEl().getBoundingClientRect(), s = getScale(), size = canvasSize();
    var x = (e.clientX - rect.left) / s - dragOffset.x;
    var y = (e.clientY - rect.top) / s - dragOffset.y;
    selected.x = Math.round(Math.max(-selected.width / 2, Math.min(x, size.w - selected.width / 2)));
    selected.y = Math.round(Math.max(-selected.height / 2, Math.min(y, size.h - selected.height / 2)));
    renderElement(selected);
    updatePanel();
}
function stopDrag() { dragging = false; document.removeEventListener('mousemove', onDrag); document.removeEventListener('mouseup', stopDrag); saveLayout(); }
function startResize(e, id) {
    var el = elements.find(function (x) { return x.id === id; });
    if (!el || el.locked) return;
    e.preventDefault(); e.stopPropagation();
    select(id);
    resizing = true;
    document.addEventListener('mousemove', onResize);
    document.addEventListener('mouseup', stopResize);
}
function onResize(e) {
    if (!resizing || !selected) return;
    var rect = canvasEl().getBoundingClientRect(), s = getScale();
    selected.width = Math.max(10, Math.round((e.clientX - rect.left) / s - selected.x));
    selected.height = Math.max(1, Math.round((e.clientY - rect.top) / s - selected.y));
    if (selected.type === 'qr') selected.height = selected.width;
    renderElement(selected);
    updatePanel();
}
function stopResize() { resizing = false; document.removeEventListener('mousemove', onResize); document.removeEventListener('mouseup', stopResize); saveLayout(); }

function exitTextEditing() {
    if (editingId === null) return;
    var el = elements.find(function (x) { return x.id === editingId; });
    var div = document.getElementById('el-' + editingId);
    if (el && div) {
        var c = div.querySelector('.cel-content');
        el.content = c.innerHTML; c.contentEditable = 'false'; c.style.cursor = 'move'; c.blur();
    }
    editingId = null; activeEditable = null; hideToolbar(); saveLayout();
}

// ===================== SELEZIONE / PROPRIETA =====================
function select(id) {
    document.querySelectorAll('.cel').forEach(function (d) { d.classList.remove('selected'); });
    hideToolbar();
    selected = elements.find(function (x) { return x.id === id; }) || null;
    if (!selected) return;
    document.getElementById('el-' + id).classList.add('selected');
    document.getElementById('propsPanel').style.display = 'block';
    updatePanel();
    refreshLayers();
}
function deselect() {
    document.querySelectorAll('.cel').forEach(function (d) { d.classList.remove('selected'); });
    selected = null;
    document.getElementById('propsPanel').style.display = 'none';
    hideToolbar();
    refreshLayers();
}
function updatePanel() {
    if (!selected) return;
    ['x', 'y'].forEach(function (k) { document.getElementById('prop' + k.toUpperCase()).value = selected[k]; });
    document.getElementById('propW').value = selected.width;
    document.getElementById('propH').value = selected.height;
    var isText = selected.type === 'text' || selected.type === 'title';
    document.getElementById('propTextGroup').style.display = isText ? '' : 'none';
    document.getElementById('propShapeGroup').style.display = ['line', 'rect', 'qr'].indexOf(selected.type) !== -1 ? '' : 'none';
    if (isText) {
        document.getElementById('propFontSize').value = selected.fontSize;
        document.getElementById('propColor').value = selected.color;
        document.getElementById('propBold').checked = !!selected.bold;
        document.getElementById('propAlign').value = selected.align;
    } else {
        document.getElementById('propShapeColor').value = selected.color || '#1f2937';
    }
}
function updateProp(prop, value) {
    if (!selected) return;
    if (['x', 'y', 'width', 'height', 'fontSize'].indexOf(prop) !== -1) value = parseFloat(value) || 0;
    selected[prop] = value;
    if (selected.type === 'qr' && (prop === 'width' || prop === 'height')) { selected.width = selected.height = value; }
    renderElement(selected);
    updatePanel();
    saveLayout();
}
function deleteElement(id) {
    elements = elements.filter(function (x) { return x.id !== id; });
    var d = document.getElementById('el-' + id);
    if (d) d.remove();
    deselect();
    reapplyZ();
    saveLayout();
}
function deleteSelected() { if (selected) deleteElement(selected.id); }

// ===================== LIVELLI =====================
function refreshLayers() {
    var list = document.getElementById('layersList');
    document.getElementById('layerCount').textContent = elements.length;
    list.innerHTML = '';
    for (var i = elements.length - 1; i >= 0; i--) {
        (function (el) {
            var item = document.createElement('div');
            item.className = 'ed-layer' + (selected && selected.id === el.id ? ' active' : '') + (!el.visible || el.locked ? ' dim' : '');
            item.innerHTML = '<span class="nm" title="' + esc(el.name) + '">' + esc(el.name) + '</span>'
                + '<button type="button" data-act="vis">' + (el.visible ? 'Nascondi' : 'Mostra') + '</button>'
                + '<button type="button" data-act="lock">' + (el.locked ? 'Sblocca' : 'Blocca') + '</button>';
            item.addEventListener('click', function (e) {
                var act = e.target.dataset && e.target.dataset.act;
                if (act === 'vis') { el.visible = !el.visible; renderElement(el); saveLayout(); refreshLayers(); return; }
                if (act === 'lock') {
                    el.locked = !el.locked; renderElement(el); saveLayout();
                    if (el.locked && selected && selected.id === el.id) deselect(); else refreshLayers();
                    return;
                }
                if (!el.locked) select(el.id);
            });
            list.appendChild(item);
        })(elements[i]);
    }
}
function moveLayer(dir) {
    if (!selected) return;
    var i = elements.indexOf(selected), n = i;
    if (dir === 'up') n = Math.min(i + 1, elements.length - 1);
    if (dir === 'down') n = Math.max(i - 1, 0);
    if (dir === 'top') n = elements.length - 1;
    if (dir === 'bottom') n = 0;
    if (n === i) return;
    elements.splice(i, 1);
    elements.splice(n, 0, selected);
    reapplyZ(); saveLayout(); refreshLayers();
}
function reapplyZ() { elements.forEach(function (el, i) { var d = document.getElementById('el-' + el.id); if (d) d.style.zIndex = i + 1; }); }

// ===================== IMMAGINI =====================
function triggerImageUpload(id) {
    var input = document.getElementById('imageUpload');
    input.value = '';
    input.onchange = function () { if (input.files[0]) uploadImage(input.files[0], id); };
    input.click();
}
function uploadImage(file, id) {
    if (file.size > 5 * 1024 * 1024) { alert('Immagine troppo grande (massimo 5MB).'); return; }
    var fd = new FormData();
    fd.append('action', 'upload_image');
    fd.append('csrf_token', csrfToken);
    fd.append('image', file);
    fetch(location.pathname, { method: 'POST', body: fd, credentials: 'same-origin' })
        .then(function (r) { return r.json(); })
        .then(function (d) {
            if (!d.success) { alert(d.error || 'Caricamento non riuscito'); return; }
            var el = elements.find(function (x) { return x.id === id; });
            if (el) { el.src = d.url; renderElement(el); saveLayout(); }
        })
        .catch(function () { alert('Errore di rete durante il caricamento'); });
}

// ===================== ALLINEAMENTO / CAMPI =====================
function alignElement(a) {
    if (!selected) { alert('Seleziona prima un elemento'); return; }
    var size = canvasSize();
    if (a === 'center-h') selected.x = Math.round((size.w - selected.width) / 2);
    if (a === 'center-v') selected.y = Math.round((size.h - selected.height) / 2);
    if (a === 'stretch-h') { selected.x = 0; selected.width = size.w; }
    if (a === 'fill') { selected.x = 0; selected.y = 0; selected.width = size.w; selected.height = size.h; }
    renderElement(selected); updatePanel(); saveLayout();
}
function addPlaceholder(text) {
    if (selected && (selected.type === 'text' || selected.type === 'title')) {
        var c = document.getElementById('el-' + selected.id).querySelector('.cel-content');
        var sel = window.getSelection();
        if (editingId === selected.id && sel.rangeCount && c.contains(sel.getRangeAt(0).startContainer)) {
            var range = sel.getRangeAt(0);
            range.deleteContents();
            var node = document.createTextNode(text);
            range.insertNode(node);
            range.setStartAfter(node); range.setEndAfter(node);
            sel.removeAllRanges(); sel.addRange(range);
        } else {
            c.innerHTML += (c.innerHTML ? ' ' : '') + text;
        }
        selected.content = c.innerHTML;
        saveLayout();
        return;
    }
    var el = { id: idCounter++, type: 'text', x: 100, y: 100, width: 260, height: 26, content: text, fontSize: 14,
               color: '#1f2937', bold: false, align: 'left', src: '', locked: false, visible: true, name: '' };
    el.name = 'Testo ' + el.id;
    elements.push(el);
    renderElement(el, elements.length - 1);
    select(el.id);
    saveLayout();
    refreshLayers();
}

// ===================== ANTEPRIMA A VIDEO =====================
var PREVIEW_SETS = <?= json_encode(array_map(fn($s) => $s['values'], $previewSets), JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
// QR finto (solo segnaposto visivo): il PDF contiene quello vero
var QR_SVG = (function () {
    var cells = '', seed = 7;
    for (var y = 0; y < 21; y++) for (var x = 0; x < 21; x++) {
        var finder = (x < 7 && y < 7) || (x > 13 && y < 7) || (x < 7 && y > 13);
        var on = finder ? ((x % 20 === 0 || y % 20 === 0 || x === 6 || y === 6 || x === 14 || y === 14) || ((x >= 2 && x <= 4 || x >= 16 && x <= 18) && (y >= 2 && y <= 4 || y >= 16 && y <= 18)))
                        : ((seed = (seed * 9301 + 49297) % 233280) / 233280 > 0.5);
        if (on) cells += '<rect x="' + x + '" y="' + y + '" width="1" height="1"/>';
    }
    return '<svg viewBox="0 0 21 21" width="100%" height="100%" shape-rendering="crispEdges" fill="currentColor">' + cells + '</svg>';
})();

function fillPlaceholders(html, values) {
    Object.keys(values).forEach(function (ph) { html = html.split(ph).join(esc(values[ph])); });
    return html;
}

function renderPreview() {
    exitTextEditing();
    var values = PREVIEW_SETS[parseInt(document.getElementById('pvPerson').value, 10) || 0] || {};
    var size = canvasSize();
    var sheet = document.getElementById('pvSheet');
    sheet.style.width = size.w + 'px';
    sheet.style.height = size.h + 'px';
    sheet.innerHTML = '';
    elements.forEach(function (el, i) {
        if (!el.visible) return;
        var d = document.createElement('div');
        d.style.left = el.x + 'px'; d.style.top = el.y + 'px';
        d.style.width = el.width + 'px'; d.style.height = el.height + 'px';
        d.style.zIndex = i + 1;
        if (el.type === 'text' || el.type === 'title') {
            d.innerHTML = fillPlaceholders(el.content || '', values);
            d.style.fontSize = el.fontSize + 'px';
            d.style.color = el.color;
            d.style.fontWeight = el.bold ? 'bold' : 'normal';
            d.style.textAlign = el.align;
            d.style.lineHeight = '1.3';
            d.style.padding = '2px 4px';
        } else if (el.type === 'image' && el.src) {
            d.innerHTML = '<img src="' + esc(el.src) + '" style="width:100%;height:100%;object-fit:contain;" alt="">';
        } else if (el.type === 'line') {
            d.style.backgroundColor = el.color;
        } else if (el.type === 'rect') {
            d.style.border = '2px solid ' + el.color;
        } else if (el.type === 'qr') {
            d.style.color = el.color || '#111827';
            d.innerHTML = QR_SVG;
        }
        sheet.appendChild(d);
    });
    // Adatta il foglio alla finestra
    var body = document.getElementById('pvBody');
    var scale = Math.min(1, (body.clientWidth - 48) / size.w, (window.innerHeight * 0.72) / size.h);
    sheet.style.transform = 'scale(' + scale + ')';
    sheet.style.marginRight = (size.w * scale - size.w) + 'px';
    sheet.style.marginBottom = (size.h * scale - size.h) + 'px';
}

function showPreview() {
    document.getElementById('pvOverlay').classList.add('open');
    renderPreview();
}
function closePreview() { document.getElementById('pvOverlay').classList.remove('open'); }

function printPreview() {
    var land = canvasSize().w > canvasSize().h;
    var sheet = document.getElementById('pvSheet').cloneNode(true);
    sheet.style.transform = 'scale(' + ((land ? 297 : 210) / 25.4 * 96 / canvasSize().w) + ')';
    sheet.style.margin = '0';
    sheet.style.boxShadow = 'none';
    var w = window.open('', '_blank');
    w.document.write('<!DOCTYPE html><html><head><title>Stampa attestato</title><style>'
        + '@page { size: A4 ' + (land ? 'landscape' : 'portrait') + '; margin: 0; } body { margin: 0; }'
        + '.pv-sheet { position: relative; transform-origin: top left; font-family: Helvetica, Arial, sans-serif; }'
        + '.pv-sheet > div { position: absolute; overflow: hidden; box-sizing: border-box; }'
        + '</style></head><body>' + sheet.outerHTML
        + '<scr' + 'ipt>window.onload = function () { window.print(); };</scr' + 'ipt></body></html>');
    w.document.close();
}

document.addEventListener('DOMContentLoaded', function () {
    document.getElementById('pvPerson').addEventListener('change', renderPreview);
    document.getElementById('pvOverlay').addEventListener('mousedown', function (e) { if (e.target === this) closePreview(); });
    document.addEventListener('keydown', function (e) { if (e.key === 'Escape') closePreview(); });
    window.addEventListener('resize', function () { if (document.getElementById('pvOverlay').classList.contains('open')) renderPreview(); });
    if (new URLSearchParams(location.search).get('preview') === '1') setTimeout(showPreview, 50);
});

// ===================== SALVA / ANTEPRIMA =====================
function saveLayout() { document.getElementById('layoutJson').value = JSON.stringify(elements); }
function previewPdf() {
    exitTextEditing();
    saveLayout();
    document.getElementById('previewOrientation').value = document.getElementById('orientation').value;
    document.getElementById('previewLayout').value = document.getElementById('layoutJson').value;
    document.getElementById('previewForm').submit();
}
</script>

<?php endif; ?>

<?php include __DIR__ . '/footer-admin.php'; ?>
