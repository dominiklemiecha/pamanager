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
        <div class="cons-emp-grid">
        <?php foreach ($employees as $emp): ?>
            <section class="dashboard-card cons-emp-card">
                <div class="card-header">
                    <div>
                        <h3 class="cons-emp-name">
                            <?= e($emp['last_name'] . ' ' . $emp['first_name']) ?>
                            <span class="badge <?= $emp['is_active'] ? 'badge-success' : 'badge-danger' ?>">
                                <?= $emp['is_active'] ? 'Attivo' : 'Cessato' ?>
                            </span>
                        </h3>
                        <div class="cons-emp-sub">
                            <?= e($emp['position'] ?? '') ?>
                            <?php if (!empty($emp['department_name'])): ?> · <?= e($emp['department_name']) ?><?php endif; ?>
                        </div>
                    </div>
                </div>

                <div class="cons-emp-body">
                    <?php
                    $sections = [
                        'Dati anagrafici' => [
                            'Codice Fiscale'  => $emp['fiscal_code'] ? '<code>' . e($emp['fiscal_code']) . '</code>' : null,
                            'Data di nascita' => !empty($emp['birth_date']) ? formatDate($emp['birth_date']) : null,
                            'Indirizzo'       => !empty($emp['address']) ? e($emp['address']) : null,
                            'Email'           => !empty($emp['email']) ? '<a href="mailto:' . e($emp['email']) . '">' . e($emp['email']) . '</a>' : null,
                            'Telefono'        => !empty($emp['phone']) ? e($emp['phone']) : null,
                        ],
                        'Contratto' => [
                            'Data assunzione'   => !empty($emp['hire_date']) ? formatDate($emp['hire_date']) : null,
                            'Livello/Qualifica' => !empty($emp['job_level']) ? e($emp['job_level']) : null,
                            'Posizione'         => !empty($emp['position']) ? e($emp['position']) : null,
                            'Reparto'           => !empty($emp['department_name']) ? e($emp['department_name']) : null,
                        ],
                        'Dati economici' => [
                            'RAL annua'           => !empty($emp['ral_amount']) ? '&euro; ' . number_format((float)$emp['ral_amount'], 2, ',', '.') : null,
                            'Retribuzione mensile'=> !empty($emp['monthly_salary']) ? '&euro; ' . number_format((float)$emp['monthly_salary'], 2, ',', '.') : null,
                            'IBAN'                => !empty($emp['iban']) ? '<code>' . e($emp['iban']) . '</code>' : null,
                        ],
                    ];
                    ?>
                    <?php foreach ($sections as $title => $fields): ?>
                        <div class="cons-emp-section">
                            <h4><?= e($title) ?></h4>
                            <div class="cons-emp-fields">
                                <?php foreach ($fields as $label => $value): ?>
                                    <div class="cons-emp-field">
                                        <span class="lbl"><?= e($label) ?></span>
                                        <span class="val"><?= $value !== null ? $value : '<span class="text-muted">—</span>' ?></span>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </section>
        <?php endforeach; ?>
        </div>

        <style>
        .cons-emp-grid { display: flex; flex-direction: column; gap: 1rem; }
        .cons-emp-card { padding: 0; }
        .cons-emp-card .card-header { padding: 1rem 1.25rem; }
        .cons-emp-name { margin: 0; display: flex; align-items: center; gap: .5rem; font-size: 1rem; }
        .cons-emp-sub { font-size: .78rem; color: var(--muted); margin-top: .25rem; }
        .cons-emp-body { padding: 1rem 1.25rem 1.25rem; display: grid; grid-template-columns: repeat(auto-fit, minmax(260px, 1fr)); gap: 1.5rem; }
        .cons-emp-section h4 {
            font-size: .68rem;
            text-transform: uppercase;
            letter-spacing: .5px;
            color: var(--muted);
            margin: 0 0 .65rem;
            font-weight: 700;
            padding-bottom: .35rem;
            border-bottom: 1px solid var(--border);
        }
        .cons-emp-fields { display: flex; flex-direction: column; gap: .55rem; }
        .cons-emp-field { display: flex; flex-direction: column; gap: .15rem; }
        .cons-emp-field .lbl { font-size: .68rem; text-transform: uppercase; letter-spacing: .4px; color: var(--muted); }
        .cons-emp-field .val { font-size: .85rem; color: var(--ink); font-weight: 500; word-break: break-word; }
        .cons-emp-field .val code { background: #edf2f7; padding: 2px 6px; border-radius: 4px; font-size: .8rem; }
        .cons-emp-field .val a { color: #3182ce; text-decoration: none; }
        </style>
    <?php endif; ?>
</div>

<?php include dirname(__DIR__) . '/includes/footer-admin.php'; ?>
