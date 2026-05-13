<?php
/**
 * Anagrafica completa dipendenti - Consulente del lavoro (read-only).
 * Filtrato per azienda corrente (Tenant::currentCompanyId()).
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

<div class="dashboard">
    <div class="page-header">
        <h1>Anagrafica dipendenti</h1>
        <span class="page-subtitle"><?= count($employees) ?> attiv<?= count($employees) === 1 ? 'o' : 'i' ?></span>
    </div>

    <div class="dashboard-card dashboard-card-full" style="margin-bottom:1rem;">
        <form method="GET" style="display:flex;gap:.5rem;flex-wrap:wrap;align-items:center;">
            <input type="text" name="search" value="<?= e($search) ?>"
                   placeholder="Cerca nome, cognome, codice fiscale, email..."
                   class="form-control" style="flex:1;min-width:240px;">
            <button type="submit" class="btn btn-primary btn-sm">Cerca</button>
            <?php if ($search): ?>
                <a href="employees.php" class="btn btn-secondary btn-sm">Reset</a>
            <?php endif; ?>
        </form>
    </div>

    <?php if (empty($employees)): ?>
        <div class="dashboard-card dashboard-card-full" style="text-align:center;padding:3rem 1rem;color:var(--muted);">
            <p>Nessun dipendente attivo<?= $search ? ' per la ricerca corrente' : ' in questa azienda' ?>.</p>
        </div>
    <?php else: ?>
        <?php foreach ($employees as $emp): ?>
            <section class="dashboard-card dashboard-card-full" style="margin-bottom:1rem;">
                <div class="card-header" style="display:flex;justify-content:space-between;align-items:center;gap:1rem;flex-wrap:wrap;">
                    <div>
                        <h3 style="margin:0;display:flex;align-items:center;gap:.5rem;">
                            <?= e($emp['last_name'] . ' ' . $emp['first_name']) ?>
                            <span class="badge <?= $emp['is_active'] ? 'badge-success' : 'badge-danger' ?>">
                                <?= $emp['is_active'] ? 'Attivo' : 'Cessato' ?>
                            </span>
                        </h3>
                        <div class="text-muted" style="font-size:.8rem;">
                            <?= e($emp['position'] ?? '') ?>
                            <?php if (!empty($emp['department_name'])): ?> · <?= e($emp['department_name']) ?><?php endif; ?>
                        </div>
                    </div>
                </div>

                <div class="info-grid" style="display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:.5rem 1.5rem;padding:1rem 0;">
                    <?php
                    $rows = [
                        'DATI ANAGRAFICI' => [
                            'Codice Fiscale'  => $emp['fiscal_code'] ? '<code>' . e($emp['fiscal_code']) . '</code>' : null,
                            'Data di nascita' => !empty($emp['birth_date']) ? formatDate($emp['birth_date']) : null,
                            'Indirizzo'       => !empty($emp['address']) ? e($emp['address']) : null,
                            'Email'           => !empty($emp['email']) ? '<a href="mailto:' . e($emp['email']) . '">' . e($emp['email']) . '</a>' : null,
                            'Telefono'        => !empty($emp['phone']) ? e($emp['phone']) : null,
                        ],
                        'CONTRATTO' => [
                            'Data assunzione'   => !empty($emp['hire_date']) ? formatDate($emp['hire_date']) : null,
                            'Livello/Qualifica' => !empty($emp['job_level']) ? e($emp['job_level']) : null,
                            'Posizione'         => !empty($emp['position']) ? e($emp['position']) : null,
                            'Reparto'           => !empty($emp['department_name']) ? e($emp['department_name']) : null,
                        ],
                        'ECONOMICI' => [
                            'RAL annua'           => !empty($emp['ral_amount']) ? '&euro; ' . number_format((float)$emp['ral_amount'], 2, ',', '.') : null,
                            'Retribuzione mensile'=> !empty($emp['monthly_salary']) ? '&euro; ' . number_format((float)$emp['monthly_salary'], 2, ',', '.') : null,
                            'IBAN'                => !empty($emp['iban']) ? '<code>' . e($emp['iban']) . '</code>' : null,
                        ],
                    ];
                    ?>
                    <?php foreach ($rows as $section => $fields): ?>
                        <div style="grid-column: 1 / -1; border-top: 1px solid var(--border); padding-top: .5rem; margin-top: .25rem;">
                            <div class="text-muted" style="font-size:.7rem;letter-spacing:.5px;font-weight:600;margin-bottom:.4rem;"><?= e($section) ?></div>
                        </div>
                        <?php foreach ($fields as $label => $value): ?>
                            <div>
                                <div class="text-muted" style="font-size:.7rem;text-transform:uppercase;letter-spacing:.5px;margin-bottom:.15rem;"><?= e($label) ?></div>
                                <div style="font-size:.85rem;color:var(--ink);font-weight:500;">
                                    <?= $value !== null ? $value : '<span class="text-muted">—</span>' ?>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    <?php endforeach; ?>
                </div>
            </section>
        <?php endforeach; ?>
    <?php endif; ?>
</div>

<?php include dirname(__DIR__) . '/includes/footer-admin.php'; ?>
