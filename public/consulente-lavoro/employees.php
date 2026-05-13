<?php
/**
 * Anagrafica completa dipendenti - Consulente del lavoro (read-only).
 * Mostra tutti i dati necessari all'elaborazione delle paghe:
 *  - dati anagrafici (CF, indirizzo, nascita, contatti)
 *  - dati contrattuali (data assunzione, livello, posizione)
 *  - dati economici (RAL, retribuzione mensile, IBAN)
 */

require_once dirname(__DIR__, 2) . '/config/config.php';

Auth::init();
setSecurityHeaders();
Auth::requireUser('consulente_lavoro');

$user = Auth::getUser();
$search = $_GET['search'] ?? '';
$employees = Employee::getAll(true, $search);

$pageTitle = 'Anagrafica dipendenti';
include dirname(__DIR__) . '/includes/header-admin.php';
?>

<style>
.cons-empl-card {
    background: white;
    border-radius: 10px;
    box-shadow: 0 2px 8px rgba(0,0,0,0.06);
    margin-bottom: 1rem;
    overflow: hidden;
}
.cons-empl-header {
    padding: 1rem 1.25rem;
    background: #f7fafc;
    border-bottom: 1px solid #e2e8f0;
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 1rem;
}
.cons-empl-header h3 { margin: 0; font-size: 1rem; color: #2d3748; }
.cons-empl-header .sub { font-size: 0.75rem; color: #718096; }
.cons-empl-grid {
    padding: 1rem 1.25rem;
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
    gap: 0.75rem 1.25rem;
}
.cons-field { font-size: 0.8rem; }
.cons-field .lbl {
    display: block;
    color: #a0aec0;
    text-transform: uppercase;
    font-size: 0.65rem;
    letter-spacing: 0.5px;
    margin-bottom: 0.15rem;
}
.cons-field .val {
    color: #2d3748;
    font-weight: 500;
    word-break: break-word;
}
.cons-field code { font-family: 'SF Mono', Monaco, monospace; font-size: 0.78rem; background: #edf2f7; padding: 1px 5px; border-radius: 3px; }
.cons-section-title {
    grid-column: 1 / -1;
    font-size: 0.7rem;
    color: #4a5568;
    text-transform: uppercase;
    letter-spacing: 0.5px;
    border-bottom: 1px solid #edf2f7;
    padding-bottom: 0.3rem;
    margin-top: 0.5rem;
}
.cons-section-title:first-child { margin-top: 0; }
.cons-search { background: white; padding: 0.75rem 1rem; border-radius: 10px; box-shadow: 0 2px 8px rgba(0,0,0,0.06); margin-bottom: 1rem; display: flex; gap: 0.5rem; flex-wrap: wrap; }
.cons-search input { flex: 1; padding: 0.5rem 0.75rem; border: 1px solid #e2e8f0; border-radius: 6px; font-size: 0.85rem; min-width: 200px; }
.cons-empty { background: white; border-radius: 10px; padding: 2rem; text-align: center; color: #a0aec0; box-shadow: 0 2px 8px rgba(0,0,0,0.06); }
</style>

<div class="dashboard">
    <h2 style="margin: 0 0 1rem;">Anagrafica dipendenti</h2>

    <form method="GET" class="cons-search">
        <input type="text" name="search" value="<?= htmlspecialchars($search) ?>" placeholder="Cerca per nome, cognome, CF, email...">
        <button type="submit" class="btn btn-primary btn-sm">Cerca</button>
        <?php if ($search): ?>
            <a href="employees.php" class="btn btn-secondary btn-sm">Reset</a>
        <?php endif; ?>
    </form>

    <?php if (empty($employees)): ?>
        <div class="cons-empty">Nessun dipendente trovato.</div>
    <?php else: ?>
        <?php foreach ($employees as $emp): ?>
            <div class="cons-empl-card">
                <div class="cons-empl-header">
                    <div>
                        <h3><?= htmlspecialchars($emp['last_name'] . ' ' . $emp['first_name']) ?></h3>
                        <div class="sub">
                            <?= htmlspecialchars($emp['position'] ?? '') ?>
                            <?php if (!empty($emp['department_name'])): ?> · <?= htmlspecialchars($emp['department_name']) ?><?php endif; ?>
                        </div>
                    </div>
                    <span class="badge <?= $emp['is_active'] ? 'badge-success' : 'badge-danger' ?>">
                        <?= $emp['is_active'] ? 'Attivo' : 'Cessato' ?>
                    </span>
                </div>
                <div class="cons-empl-grid">

                    <div class="cons-section-title">Dati anagrafici</div>

                    <div class="cons-field">
                        <span class="lbl">Codice Fiscale</span>
                        <span class="val"><code><?= htmlspecialchars($emp['fiscal_code']) ?></code></span>
                    </div>
                    <div class="cons-field">
                        <span class="lbl">Data di nascita</span>
                        <span class="val"><?= !empty($emp['birth_date']) ? formatDate($emp['birth_date']) : '<span style="color:#cbd5e0;">—</span>' ?></span>
                    </div>
                    <div class="cons-field">
                        <span class="lbl">Indirizzo</span>
                        <span class="val"><?= !empty($emp['address']) ? htmlspecialchars($emp['address']) : '<span style="color:#cbd5e0;">—</span>' ?></span>
                    </div>
                    <div class="cons-field">
                        <span class="lbl">Email</span>
                        <span class="val"><?= !empty($emp['email']) ? htmlspecialchars($emp['email']) : '<span style="color:#cbd5e0;">—</span>' ?></span>
                    </div>
                    <div class="cons-field">
                        <span class="lbl">Telefono</span>
                        <span class="val"><?= !empty($emp['phone']) ? htmlspecialchars($emp['phone']) : '<span style="color:#cbd5e0;">—</span>' ?></span>
                    </div>

                    <div class="cons-section-title">Dati contrattuali</div>

                    <div class="cons-field">
                        <span class="lbl">Data assunzione</span>
                        <span class="val"><?= !empty($emp['hire_date']) ? formatDate($emp['hire_date']) : '<span style="color:#cbd5e0;">—</span>' ?></span>
                    </div>
                    <div class="cons-field">
                        <span class="lbl">Livello / Qualifica</span>
                        <span class="val"><?= !empty($emp['job_level']) ? htmlspecialchars($emp['job_level']) : '<span style="color:#cbd5e0;">—</span>' ?></span>
                    </div>
                    <div class="cons-field">
                        <span class="lbl">Posizione</span>
                        <span class="val"><?= !empty($emp['position']) ? htmlspecialchars($emp['position']) : '<span style="color:#cbd5e0;">—</span>' ?></span>
                    </div>
                    <div class="cons-field">
                        <span class="lbl">Reparto</span>
                        <span class="val"><?= !empty($emp['department_name']) ? htmlspecialchars($emp['department_name']) : '<span style="color:#cbd5e0;">—</span>' ?></span>
                    </div>

                    <div class="cons-section-title">Dati economici</div>

                    <div class="cons-field">
                        <span class="lbl">RAL annua</span>
                        <span class="val"><?= !empty($emp['ral_amount']) ? '&euro; ' . number_format((float)$emp['ral_amount'], 2, ',', '.') : '<span style="color:#cbd5e0;">—</span>' ?></span>
                    </div>
                    <div class="cons-field">
                        <span class="lbl">Retribuzione mensile</span>
                        <span class="val"><?= !empty($emp['monthly_salary']) ? '&euro; ' . number_format((float)$emp['monthly_salary'], 2, ',', '.') : '<span style="color:#cbd5e0;">—</span>' ?></span>
                    </div>
                    <div class="cons-field">
                        <span class="lbl">IBAN</span>
                        <span class="val"><?= !empty($emp['iban']) ? '<code>' . htmlspecialchars($emp['iban']) . '</code>' : '<span style="color:#cbd5e0;">—</span>' ?></span>
                    </div>
                </div>
            </div>
        <?php endforeach; ?>
    <?php endif; ?>
</div>

<?php include dirname(__DIR__) . '/includes/footer-admin.php'; ?>
