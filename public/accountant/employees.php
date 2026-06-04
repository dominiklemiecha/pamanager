<?php
/**
 * Anagrafica completa dipendenti - Consulente del lavoro (read-only).
 * Filtrato per azienda corrente (Tenant::currentCompanyId()).
 */

require_once dirname(__DIR__, 2) . '/config/config.php';

Auth::init();
setSecurityHeaders();
Auth::requireUser('accountant');

$user = Auth::getUser();
$search = $_GET['search'] ?? '';
$employees = Employee::getAll(true, $search);

$pageTitle = 'Anagrafica dipendenti';
include dirname(__DIR__) . '/includes/header-admin.php';
?>

<div class="cl-banner">
    <div>
        <h2>Anagrafica dipendenti</h2>
        <p><strong><?= count($employees) ?></strong> dipendent<?= count($employees) === 1 ? 'e attivo' : 'i attivi' ?> · Consulta dati anagrafici, contratto e dati economici.</p>
    </div>
    <form method="GET" class="cl-search">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" width="16" height="16"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
        <input type="search" name="search" value="<?= e($search) ?>" placeholder="Nome, codice fiscale, email…">
        <?php if ($search): ?>
            <a href="employees.php" title="Reset">×</a>
        <?php endif; ?>
    </form>
</div>

<style>
.cl-banner {
    background: white;
    border: 1px solid #e6e8f0;
    border-left: 4px solid #0b3aa4;
    border-radius: 14px;
    padding: 18px 22px;
    margin-bottom: 16px;
    display: flex; align-items: center; justify-content: space-between;
    gap: 16px; flex-wrap: wrap;
    box-shadow: 0 1px 2px rgba(15,23,42,0.04);
}
.cl-banner h2 {
    font-family: 'Host Grotesk', sans-serif;
    margin: 0 0 4px;
    font-size: 19px; font-weight: 700;
    color: #0b3aa4; letter-spacing: -0.02em;
}
.cl-banner > div > p { margin: 0; font-size: 13px; color: #6e7191; }
.cl-search {
    display: inline-flex; align-items: center; gap: 8px;
    background: #fafbfd;
    border: 1px solid #e6e8f0;
    border-radius: 9px;
    padding: 8px 12px;
    min-width: 260px;
    transition: all .12s ease;
}
.cl-search:focus-within { border-color: #0b3aa4; background: white; box-shadow: 0 0 0 3px rgba(11,58,164,0.10); }
.cl-search svg { color: #94a3b8; flex-shrink: 0; }
.cl-search input {
    flex: 1; border: none; background: transparent;
    font-family: inherit; font-size: 13px; outline: none;
    color: #1e1e2f;
}
.cl-search a {
    color: #94a3b8; text-decoration: none; font-size: 18px;
    line-height: 1; padding: 0 2px;
}
.cl-search a:hover { color: #cc2d39; }

/* ===== Lista dipendenti ===== */
.cl-emp-list { display: flex; flex-direction: column; gap: 10px; }
.cl-emp-card {
    background: white;
    border: 1px solid #e6e8f0;
    border-radius: 14px;
    overflow: hidden;
}
.cl-emp-head {
    display: flex; align-items: center; gap: 14px;
    padding: 16px 20px;
    cursor: pointer;
    user-select: none;
    transition: background .12s ease;
    list-style: none;
}
.cl-emp-head::-webkit-details-marker { display: none; }
.cl-emp-card[open] .cl-emp-head { background: rgba(11,58,164,0.03); border-bottom: 1px solid #e6e8f0; }
.cl-emp-avatar {
    width: 46px; height: 46px;
    border-radius: 50%;
    background: linear-gradient(135deg, #0b3aa4, #082b7b);
    color: white;
    display: inline-flex; align-items: center; justify-content: center;
    font-family: 'Space Grotesk', sans-serif;
    font-weight: 700; font-size: 15px;
    flex-shrink: 0;
    overflow: hidden;
}
.cl-emp-avatar img { width: 100%; height: 100%; object-fit: cover; display: block; }
.cl-emp-id { flex: 1; min-width: 0; }
.cl-emp-name {
    font-family: 'Host Grotesk', sans-serif;
    font-size: 15px; font-weight: 700;
    color: #1e1e2f; margin: 0;
    letter-spacing: -0.01em;
    display: flex; align-items: center; gap: 8px; flex-wrap: wrap;
}
.cl-emp-meta {
    font-size: 12px; color: #6e7191;
    margin-top: 3px;
    display: flex; gap: 10px; flex-wrap: wrap;
}
.cl-emp-meta .sep { color: #cbd5e0; }
.cl-emp-pill {
    display: inline-flex; align-items: center; gap: 5px;
    padding: 2px 9px; border-radius: 999px;
    font-size: 10px; font-weight: 700;
    text-transform: uppercase; letter-spacing: 0.04em;
}
.cl-emp-pill::before { content: ""; width: 6px; height: 6px; border-radius: 50%; background: currentColor; }
.cl-emp-pill.on  { background: rgba(11,58,164,0.10); color: #0b3aa4; }
.cl-emp-pill.off { background: rgba(247,92,108,0.10); color: #cc2d39; }
.cl-emp-caret {
    width: 28px; height: 28px;
    color: #94a3b8;
    display: inline-flex; align-items: center; justify-content: center;
    transition: transform .15s ease;
    flex-shrink: 0;
}
.cl-emp-card[open] .cl-emp-caret { transform: rotate(180deg); color: #0b3aa4; }

.cl-emp-body {
    display: grid;
    grid-template-columns: repeat(3, 1fr);
    gap: 18px;
    padding: 18px 20px 20px;
}
.cl-emp-section h4 {
    font-family: 'Host Grotesk', sans-serif;
    font-size: 10px; font-weight: 700;
    text-transform: uppercase; letter-spacing: 0.06em;
    color: #94a3b8;
    margin: 0 0 10px;
    padding-bottom: 6px;
    border-bottom: 1px solid #f1f5f9;
}
.cl-emp-rows { display: flex; flex-direction: column; gap: 8px; }
.cl-emp-row {
    display: flex; justify-content: space-between; align-items: center;
    gap: 10px;
    font-size: 12.5px;
    line-height: 1.4;
}
.cl-emp-row .lbl {
    color: #94a3b8; font-weight: 500;
    flex-shrink: 0;
}
.cl-emp-row .val {
    color: #1e1e2f; font-weight: 500;
    text-align: right;
    word-break: break-word;
    min-width: 0;
    overflow-wrap: anywhere;
}
.cl-emp-row .val.muted { color: #cbd5e0; font-weight: 400; }
.cl-emp-row .val code {
    background: #f1f5f9;
    padding: 2px 7px; border-radius: 5px;
    font-size: 11.5px; font-weight: 600;
    color: #082b7b;
}
.cl-emp-row .val a {
    color: #0b3aa4; text-decoration: none;
    overflow-wrap: anywhere;
}
.cl-emp-row .val a:hover { text-decoration: underline; }

.cl-empty {
    background: white;
    border: 1px solid #e6e8f0;
    border-radius: 14px;
    padding: 48px 20px;
    text-align: center;
    color: #94a3b8;
}

@media (max-width: 900px) {
    .cl-banner { flex-direction: column; align-items: stretch; }
    .cl-search { width: 100%; min-width: 0; }
    .cl-emp-body { grid-template-columns: 1fr 1fr; }
}
@media (max-width: 560px) {
    .cl-emp-body { grid-template-columns: 1fr; }
    .cl-emp-head { padding: 14px 16px; gap: 12px; }
    .cl-emp-body { padding: 14px 16px 16px; }
}
</style>

<?php if (empty($employees)): ?>
    <div class="cl-empty">
        <p style="margin:0;">Nessun dipendente<?= $search ? ' per la ricerca corrente' : ' in questa azienda' ?>.</p>
    </div>
<?php else: ?>
    <div class="cl-emp-list">
    <?php foreach ($employees as $emp):
        $initials = strtoupper(substr($emp['first_name'], 0, 1) . substr($emp['last_name'], 0, 1));
        $photo = !empty($emp['photo_path']) ? PUBLIC_URL . '/' . ltrim($emp['photo_path'], '/') : '';
        $departmentName = $emp['department_name'] ?? '';
    ?>
        <details class="cl-emp-card">
            <summary class="cl-emp-head">
                <div class="cl-emp-avatar">
                    <?php if ($photo): ?>
                        <img src="<?= htmlspecialchars($photo) ?>" alt="" loading="lazy">
                    <?php else: ?>
                        <?= htmlspecialchars($initials) ?>
                    <?php endif; ?>
                </div>
                <div class="cl-emp-id">
                    <h3 class="cl-emp-name">
                        <?= e($emp['last_name'] . ' ' . $emp['first_name']) ?>
                        <span class="cl-emp-pill <?= $emp['is_active'] ? 'on' : 'off' ?>"><?= $emp['is_active'] ? 'Attivo' : 'Cessato' ?></span>
                    </h3>
                    <div class="cl-emp-meta">
                        <?php if (!empty($emp['position'])): ?><span><?= e($emp['position']) ?></span><?php endif; ?>
                        <?php if (!empty($emp['position']) && $departmentName): ?><span class="sep">·</span><?php endif; ?>
                        <?php if ($departmentName): ?><span><?= e($departmentName) ?></span><?php endif; ?>
                        <?php if (!empty($emp['fiscal_code'])): ?>
                            <?php if (!empty($emp['position']) || $departmentName): ?><span class="sep">·</span><?php endif; ?>
                            <span><?= e($emp['fiscal_code']) ?></span>
                        <?php endif; ?>
                    </div>
                </div>
                <span class="cl-emp-caret">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" width="16" height="16"><polyline points="6 9 12 15 18 9"/></svg>
                </span>
            </summary>

            <div class="cl-emp-body">
                <div class="cl-emp-section">
                    <h4>Dati anagrafici</h4>
                    <div class="cl-emp-rows">
                        <div class="cl-emp-row"><span class="lbl">Codice Fiscale</span><span class="val"><?= !empty($emp['fiscal_code']) ? '<code>' . e($emp['fiscal_code']) . '</code>' : '<span class="val muted">—</span>' ?></span></div>
                        <div class="cl-emp-row"><span class="lbl">Nascita</span><span class="val <?= empty($emp['birth_date']) ? 'muted' : '' ?>"><?= !empty($emp['birth_date']) ? formatDate($emp['birth_date']) : '—' ?></span></div>
                        <div class="cl-emp-row"><span class="lbl">Indirizzo</span><span class="val <?= empty($emp['address']) ? 'muted' : '' ?>"><?= !empty($emp['address']) ? e($emp['address']) : '—' ?></span></div>
                        <div class="cl-emp-row"><span class="lbl">Email</span><span class="val <?= empty($emp['email']) ? 'muted' : '' ?>"><?= !empty($emp['email']) ? '<a href="mailto:' . e($emp['email']) . '">' . e($emp['email']) . '</a>' : '—' ?></span></div>
                        <div class="cl-emp-row"><span class="lbl">Telefono</span><span class="val <?= empty($emp['phone']) ? 'muted' : '' ?>"><?= !empty($emp['phone']) ? e($emp['phone']) : '—' ?></span></div>
                    </div>
                </div>
                <div class="cl-emp-section">
                    <h4>Contratto</h4>
                    <div class="cl-emp-rows">
                        <div class="cl-emp-row"><span class="lbl">Assunzione</span><span class="val <?= empty($emp['hire_date']) ? 'muted' : '' ?>"><?= !empty($emp['hire_date']) ? formatDate($emp['hire_date']) : '—' ?></span></div>
                        <div class="cl-emp-row"><span class="lbl">Livello CCNL</span><span class="val <?= empty($emp['job_level']) ? 'muted' : '' ?>"><?= !empty($emp['job_level']) ? e($emp['job_level']) : '—' ?></span></div>
                        <div class="cl-emp-row"><span class="lbl">Posizione</span><span class="val <?= empty($emp['position']) ? 'muted' : '' ?>"><?= !empty($emp['position']) ? e($emp['position']) : '—' ?></span></div>
                        <div class="cl-emp-row"><span class="lbl">Reparto</span><span class="val <?= !$departmentName ? 'muted' : '' ?>"><?= $departmentName ? e($departmentName) : '—' ?></span></div>
                    </div>
                </div>
                <div class="cl-emp-section">
                    <h4>Dati economici</h4>
                    <div class="cl-emp-rows">
                        <div class="cl-emp-row"><span class="lbl">RAL annua</span><span class="val <?= empty($emp['ral_amount']) ? 'muted' : '' ?>"><?= !empty($emp['ral_amount']) ? '€ ' . number_format((float)$emp['ral_amount'], 2, ',', '.') : '—' ?></span></div>
                        <div class="cl-emp-row"><span class="lbl">Mensile lordo</span><span class="val <?= empty($emp['monthly_salary']) ? 'muted' : '' ?>"><?= !empty($emp['monthly_salary']) ? '€ ' . number_format((float)$emp['monthly_salary'], 2, ',', '.') : '—' ?></span></div>
                        <div class="cl-emp-row"><span class="lbl">IBAN</span><span class="val <?= empty($emp['iban']) ? 'muted' : '' ?>"><?= !empty($emp['iban']) ? '<code>' . e($emp['iban']) . '</code>' : '—' ?></span></div>
                    </div>
                </div>
            </div>
        </details>
    <?php endforeach; ?>
    </div>
<?php endif; ?>

<?php include dirname(__DIR__) . '/includes/footer-admin.php'; ?>
