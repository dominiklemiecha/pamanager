<?php
/**
 * Gestione Dipendenti - Admin
 * PAManager - Comune
 */

require_once dirname(__DIR__, 2) . '/config/config.php';

Auth::init();
setSecurityHeaders();
Auth::requireUser('admin');

$user = Auth::getUser();
$action = $_GET['action'] ?? 'list';
$id = isset($_POST['id']) && $_POST['id'] !== ''
    ? (int) $_POST['id']
    : (isset($_GET['id']) ? (int) $_GET['id'] : null);
$message = '';
$error = '';
$generatedPassword = null;

// Gestione azioni POST
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    CSRF::verifyOrDie();

    $postAction = $_POST['action'] ?? '';

    switch ($postAction) {
        case 'inline_update':
            $__eid = (int)($_POST['id'] ?? 0);
            $__field = $_POST['field'] ?? '';
            $__value = trim($_POST['value'] ?? '');
            $__allowed = ['first_name','last_name','position','email','phone','birth_date','address','hire_date','job_level','iban','ral_amount','monthly_salary','department_id'];
            if ($__eid > 0 && in_array($__field, $__allowed, true)) {
                $__upd = [];
                if (in_array($__field, ['birth_date','hire_date'], true)) {
                    $__upd[$__field] = $__value !== '' ? $__value : null;
                } elseif (in_array($__field, ['ral_amount','monthly_salary'], true)) {
                    $__upd[$__field] = $__value !== '' ? (float)str_replace(',', '.', $__value) : null;
                } elseif ($__field === 'department_id') {
                    $__upd[$__field] = $__value !== '' ? (int)$__value : null;
                } else {
                    $__upd[$__field] = $__value !== '' ? $__value : null;
                }
                $__r = Employee::update($__eid, $__upd);
                header('Content-Type: application/json');
                echo json_encode(['success' => !empty($__r['success']), 'error' => $__r['error'] ?? null]);
                exit;
            }
            header('Content-Type: application/json');
            echo json_encode(['success' => false, 'error' => 'Campo non valido']);
            exit;

        case 'create':
            // Orario personalizzato dal form (override aziendale)
            $createData = [
                'username' => $_POST['username'] ?? '',
                'password' => '', // password generata automaticamente
                'fiscal_code' => $_POST['fiscal_code'] ?? '',
                'first_name' => $_POST['first_name'] ?? '',
                'last_name' => $_POST['last_name'] ?? '',
                'email' => $_POST['email'] ?? '',
                'phone' => $_POST['phone'] ?? '',
                'department_id' => !empty($_POST['department_id']) ? (int) $_POST['department_id'] : null,
                'position' => $_POST['position'] ?? '',
                'hire_date' => $_POST['hire_date'] ?? null,
                // Dati personali ed economici
                'address'        => $_POST['address'] ?? null,
                'birth_date'     => $_POST['birth_date'] ?? null,
                'job_level'      => $_POST['job_level'] ?? null,
                'ral_amount'     => $_POST['ral_amount'] ?? null,
                'monthly_salary' => $_POST['monthly_salary'] ?? null,
                'iban'           => $_POST['iban'] ?? null,
                'ccnl_id'        => !empty($_POST['ccnl_id']) ? (int) $_POST['ccnl_id'] : null,
                'ferie_year_override'    => isset($_POST['ferie_year_override']) && $_POST['ferie_year_override'] !== '' ? (float) str_replace(',', '.', $_POST['ferie_year_override']) : null,
                'permessi_year_override' => isset($_POST['permessi_year_override']) && $_POST['permessi_year_override'] !== '' ? (float) str_replace(',', '.', $_POST['permessi_year_override']) : null,
            ];
            $__newHasOverride = isset($_POST['working_days_override']) && $_POST['working_days_override'] === '1';
            if ($__newHasOverride) {
                $__wdPosted = $_POST['working_days'] ?? [];
                $__allowed = LeaveBalance::allDayKeys();
                $__clean = is_array($__wdPosted) ? array_values(array_intersect($__allowed, $__wdPosted)) : [];
                $createData['working_days'] = implode(',', $__clean);
                $__hpdPosted = trim($_POST['hours_per_day'] ?? '');
                $createData['hours_per_day'] = $__hpdPosted === '' ? null : (float) str_replace(',', '.', $__hpdPosted);
            }
            $result = Employee::create($createData);

            // Salva configurazione visibilita campi anche dal form di creazione
            if (isset($_POST['visibility']) && is_array($_POST['visibility'])) {
                $visCfg = [];
                foreach (array_keys(FieldVisibility::FIELDS) as $f) {
                    $visCfg[$f] = $_POST['visibility'][$f] ?? [];
                }
                try { FieldVisibility::saveConfig($visCfg, (int)Auth::getUser()['id']); } catch (Throwable $e) {}
            }

            // Salva saldi ferie/permessi iniziali per il nuovo dipendente
            if (!empty($result['success']) && !empty($result['id']) && isset($_POST['balance']) && is_array($_POST['balance'])) {
                $newEmpId  = (int) $result['id'];
                $companyId = (int) ($_POST['_balance_company_id'] ?? (class_exists('Tenant') ? Tenant::currentCompanyId() : 1));
                $adminId   = (int) (Auth::getUser()['id'] ?? 0);
                foreach (LeaveBalance::TYPES as $bt) {
                    $b = $_POST['balance'][$bt] ?? [];
                    $year = (int) ($b['year'] ?? date('Y'));
                    $entitled = (float) str_replace(',', '.', (string) ($b['entitled'] ?? '0'));
                    $carried  = (float) str_replace(',', '.', (string) ($b['carried']  ?? '0'));
                    $manual   = (float) str_replace(',', '.', (string) ($b['manual_used'] ?? '0'));
                    if ($year > 1900 && $year < 2100) {
                        LeaveBalance::save($newEmpId, $companyId, $year, $bt, $entitled, $carried, $manual, null, $adminId);
                    }
                }
            }

            if ($result['success']) {
                $params = ['message' => 'created'];
                if (!empty($result['email_sent'])) {
                    $params['email'] = 'sent';
                } else {
                    $params['email'] = 'failed';
                    $params['pwd'] = $result['temp_password'] ?? '';
                    $params['err'] = $result['email_error'] ?? '';
                }
                header('Location: employees.php?' . http_build_query($params));
                exit;
            }
            $error = $result['error'];
            $action = 'new';
            break;

        case 'update':
            if ($id) {
                $updateData = [
                    'username' => $_POST['username'] ?? '',
                    'fiscal_code' => $_POST['fiscal_code'] ?? '',
                    'first_name' => $_POST['first_name'] ?? '',
                    'last_name' => $_POST['last_name'] ?? '',
                    'email' => $_POST['email'] ?? '',
                    'phone' => $_POST['phone'] ?? '',
                    'department_id' => !empty($_POST['department_id']) ? (int) $_POST['department_id'] : null,
                    'position' => $_POST['position'] ?? '',
                    'hire_date' => $_POST['hire_date'] ?? null,
                    // Nuovi campi (migration 013)
                    'address'        => $_POST['address'] ?? null,
                    'birth_date'     => $_POST['birth_date'] ?? null,
                    'job_level'      => $_POST['job_level'] ?? null,
                    'ral_amount'     => $_POST['ral_amount'] ?? null,
                    'monthly_salary' => $_POST['monthly_salary'] ?? null,
                    'iban'           => $_POST['iban'] ?? null,
                    'ccnl_id'        => !empty($_POST['ccnl_id']) ? (int) $_POST['ccnl_id'] : null,
                    'ferie_year_override'    => isset($_POST['ferie_year_override']) && $_POST['ferie_year_override'] !== '' ? (float) str_replace(',', '.', $_POST['ferie_year_override']) : null,
                    'permessi_year_override' => isset($_POST['permessi_year_override']) && $_POST['permessi_year_override'] !== '' ? (float) str_replace(',', '.', $_POST['permessi_year_override']) : null,
                    'is_active' => isset($_POST['is_active'])
                ];

                // Salva configurazione visibilita campi (globale, non per-dipendente)
                if (isset($_POST['visibility']) && is_array($_POST['visibility'])) {
                    $visCfg = [];
                    foreach (array_keys(FieldVisibility::FIELDS) as $f) {
                        $visCfg[$f] = $_POST['visibility'][$f] ?? [];
                    }
                    try { FieldVisibility::saveConfig($visCfg, (int)Auth::getUser()['id']); } catch (Throwable $e) {}
                }

                // Orario lavorativo: se override non spuntato -> null (usa default azienda)
                $hasOverride = isset($_POST['working_days_override']) && $_POST['working_days_override'] === '1';
                if ($hasOverride) {
                    $wdPosted = $_POST['working_days'] ?? [];
                    $allowed = LeaveBalance::allDayKeys();
                    $clean = is_array($wdPosted) ? array_values(array_intersect($allowed, $wdPosted)) : [];
                    $updateData['working_days'] = implode(',', $clean);
                    $hpdPosted = trim($_POST['hours_per_day'] ?? '');
                    $updateData['hours_per_day'] = $hpdPosted === '' ? null : (float) str_replace(',', '.', $hpdPosted);
                } else {
                    $updateData['working_days'] = null;
                    $updateData['hours_per_day'] = null;
                }

                $result = Employee::update($id, $updateData);

                // Salva saldi ferie/permessi
                if ($result['success'] && isset($_POST['balance']) && is_array($_POST['balance'])) {
                    $companyId = (int) ($_POST['_balance_company_id'] ?? 0);
                    $userId    = (int) (Auth::getUser()['id'] ?? 0);
                    foreach (LeaveBalance::TYPES as $bt) {
                        $b = $_POST['balance'][$bt] ?? [];
                        $year = (int) ($b['year'] ?? date('Y'));
                        $entitled = (float) str_replace(',', '.', (string) ($b['entitled'] ?? '0'));
                        $carried  = (float) str_replace(',', '.', (string) ($b['carried']  ?? '0'));
                        $manual   = (float) str_replace(',', '.', (string) ($b['manual_used'] ?? '0'));
                        $notes    = trim((string) ($b['notes'] ?? '')) ?: null;
                        if ($year > 1900 && $year < 2100) {
                            LeaveBalance::save($id, $companyId, $year, $bt, $entitled, $carried, $manual, $notes, $userId);
                        }
                    }
                }

                if ($result['success']) {
                    header('Location: employees.php?message=updated');
                    exit;
                }
                $error = $result['error'];
                $action = 'edit';
            }
            break;

        case 'update_balance_inline':
            if ($id) {
                $emp = Employee::getById($id);
                if (!$emp) {
                    $error = 'Dipendente non trovato';
                    break;
                }
                // Aggiorna CCNL + override sul dipendente
                $payload = [
                    'ccnl_id' => !empty($_POST['ccnl_id']) ? (int) $_POST['ccnl_id'] : null,
                    'ferie_year_override'    => isset($_POST['ferie_year_override']) && $_POST['ferie_year_override'] !== ''
                        ? (float) str_replace(',', '.', $_POST['ferie_year_override']) : null,
                    'permessi_year_override' => isset($_POST['permessi_year_override']) && $_POST['permessi_year_override'] !== ''
                        ? (float) str_replace(',', '.', $_POST['permessi_year_override']) : null,
                ];
                Employee::update($id, $payload);

                // Salva saldi: il modal invia "residual" (residuo ad oggi).
                // Lo memorizziamo come snapshot: balance_set_at=oggi, carried=residual, entitled=0, manual=0.
                $companyId = (int) $emp['company_id'];
                $userId    = (int) (Auth::getUser()['id'] ?? 0);
                $year      = (int) ($_POST['year'] ?? date('Y'));
                $today     = date('Y-m-d');
                foreach (LeaveBalance::TYPES as $bt) {
                    $b = $_POST['balance'][$bt] ?? null;
                    if (!$b || !isset($b['residual'])) continue;
                    $residual = (float) str_replace(',', '.', (string) $b['residual']);
                    LeaveBalance::setSnapshotResidual($id, $companyId, $year, $bt, $residual, $today, $userId);
                }
                header('Location: employees.php?action=view&id=' . $id . '&message=balance_updated');
                exit;
            }
            break;

        case 'delete':
            if ($id) {
                $result = Employee::delete($id);
                if ($result['success']) {
                    header('Location: employees.php?message=deleted');
                    exit;
                }
                $error = $result['error'];
            }
            break;

        case 'toggle_active':
            if ($id) {
                $employee = Employee::getById($id);
                if ($employee) {
                    $result = $employee['is_active']
                        ? Employee::deactivate($id)
                        : Employee::activate($id);
                    if ($result['success']) {
                        header('Location: employees.php?message=updated');
                        exit;
                    }
                    $error = $result['error'];
                }
            }
            break;

        case 'reset_password':
            if ($id) {
                $result = Employee::resetPassword($id);
                if ($result['success']) {
                    $generatedPassword = $result['password'];
                    $message = 'Password reimpostata con successo';
                    $action = 'view';
                } else {
                    $error = $result['error'];
                }
            }
            break;
    }
}

// Messaggi di conferma
if (isset($_GET['message'])) {
    if ($_GET['message'] === 'created') {
        if (($_GET['email'] ?? '') === 'sent') {
            $message = 'Dipendente creato con successo. Le credenziali di accesso sono state inviate via email.';
        } else {
            $tempPwd = $_GET['pwd'] ?? '';
            $emailErr = $_GET['err'] ?? '';
            $message = 'Dipendente creato, ma invio email fallito'
                . ($emailErr ? ' (' . htmlspecialchars($emailErr) . ')' : '')
                . '. Comunica manualmente la password temporanea: <strong><code>'
                . htmlspecialchars($tempPwd) . '</code></strong>';
        }
    } else {
        $messages = [
            'updated' => 'Dipendente aggiornato con successo',
            'deleted' => 'Dipendente eliminato con successo',
            'balance_updated' => 'Saldi ferie/permessi aggiornati',
        ];
        $message = $messages[$_GET['message']] ?? '';
    }
}

// Carica dati in base all'azione
$employee = null;
$employees = [];
$departments = Department::getAll(true); // Carica reparti attivi

// Configurazione visibilita campi (globale)
$visibilityConfig = FieldVisibility::getConfig();
$renderVisChip = function(string $field) use ($visibilityConfig) {
    $current = $visibilityConfig[$field] ?? [];
    $labels = [];
    if (in_array('accountant', $current, true))    $labels[] = 'Comm.';
    if (in_array('admin_reparto', $current, true)) $labels[] = 'Reparto';
    $summary = empty($labels) ? 'Solo admin' : 'Admin + ' . implode(', ', $labels);
    ?>
    <details class="vis-popover" data-vis-field="<?= htmlspecialchars($field) ?>">
        <summary title="Configura chi puo vedere questo campo">
            <svg viewBox="0 0 24 24" width="12" height="12" fill="currentColor"><path d="M12 4.5C7 4.5 2.73 7.61 1 12c1.73 4.39 6 7.5 11 7.5s9.27-3.11 11-7.5C21.27 7.61 17 4.5 12 4.5zM12 17c-2.76 0-5-2.24-5-5s2.24-5 5-5 5 2.24 5 5-2.24 5-5 5zm0-8c-1.66 0-3 1.34-3 3s1.34 3 3 3 3-1.34 3-3-1.34-3-3-3z"/></svg>
            <span class="vis-summary"><?= htmlspecialchars($summary) ?></span>
        </summary>
        <div class="vis-options">
            <p class="vis-hint">Visibile a:</p>
            <label class="vis-opt is-disabled">
                <input type="checkbox" checked disabled>
                <span class="vis-label">Amministratore</span>
                <span class="vis-badge">sempre</span>
            </label>
            <label class="vis-opt">
                <input type="checkbox" name="visibility[<?= $field ?>][]" value="accountant" <?= in_array('accountant', $current, true) ? 'checked' : '' ?>>
                <span class="vis-label">Commercialista</span>
            </label>
            <label class="vis-opt">
                <input type="checkbox" name="visibility[<?= $field ?>][]" value="admin_reparto" <?= in_array('admin_reparto', $current, true) ? 'checked' : '' ?>>
                <span class="vis-label">Admin reparto</span>
            </label>
        </div>
    </details>
    <?php
};
$search = $_GET['search'] ?? '';
$showInactive = isset($_GET['show_inactive']);

if ($action === 'list') {
    $employees = Employee::getAll(!$showInactive, $search);
    // Conteggi per i chip filtro (scoped per azienda corrente)
    $__empCid = class_exists('Tenant') ? Tenant::currentCompanyId() : 1;
    try {
        $countActive   = (int) Database::fetchColumn("SELECT COUNT(*) FROM employees WHERE company_id = ? AND is_active = TRUE",  [$__empCid]);
        $countInactive = (int) Database::fetchColumn("SELECT COUNT(*) FROM employees WHERE company_id = ? AND is_active = FALSE", [$__empCid]);
    } catch (Throwable $e) { $countActive = count($employees); $countInactive = 0; }
    $countTotal = $countActive + $countInactive;
    // Calcola stato push subscription (chi ha almeno un device registrato)
    $_pushSubscribed = [];
    try {
        $rows = Database::fetchAll("SELECT DISTINCT user_id FROM push_subscriptions WHERE user_type = 'employee'");
        foreach ($rows as $r) { $_pushSubscribed[(int) $r['user_id']] = true; }
    } catch (Throwable $e) {}
} elseif ($action === 'edit' && $id) {
    $employee = Employee::getById($id);
    if (!$employee) {
        header('Location: employees.php?error=not_found');
        exit;
    }
} elseif ($action === 'view' && $id) {
    $employee = Employee::getById($id);
    if (!$employee) {
        header('Location: employees.php?error=not_found');
        exit;
    }
    // Carica documenti del dipendente
    $documents = Document::getByEmployee($id);
}

$pageTitle = $action === 'new' ? 'Nuovo Dipendente'
           : ($action === 'edit' ? 'Modifica Dipendente'
           : ($action === 'view' ? 'Dettaglio Dipendente'
           : 'Gestione Dipendenti'));
include dirname(__DIR__) . '/includes/header-admin.php';
?>

<style>
/* === Hero card stile welcome (compatta per le pagine sezione) === */
.welcome-card.emp-hero {
    padding: var(--sp-5) var(--sp-6);
    margin-bottom: var(--sp-5);
}
.welcome-card.emp-hero h2 {
    font-family: 'Space Grotesk', var(--font-sans);
    font-size: 1.6rem; font-weight: 700;
    letter-spacing: -0.025em; line-height: 1.1;
    margin: 0 0 6px;
    color: #0b3aa4;
}
.welcome-card.emp-hero p { margin: 0; font-size: var(--text-sm); color: #6e7191; }
@media (min-width: 900px) {
    .welcome-card.emp-hero h2 { font-size: 1.85rem; }
}

/* === Filters bar === */
.emp-filters {
    display: flex; gap: var(--sp-2); margin-bottom: var(--sp-4);
    flex-wrap: wrap; align-items: center;
}
.filter-search {
    flex: 1; min-width: 240px; position: relative;
}
.filter-search input {
    width: 100%;
    padding: 10px var(--sp-3) 10px calc(var(--sp-3) + 22px);
    background: var(--surface); border: 1px solid var(--border);
    border-radius: 10px; font-size: var(--text-sm); font-family: inherit;
    color: var(--ink);
}
.filter-search input:focus { outline: none; border-color: #0b3aa4; box-shadow: 0 0 0 3px rgba(11,58,164,0.12); }
.filter-search svg {
    position: absolute; left: var(--sp-3); top: 50%; transform: translateY(-50%);
    width: 16px; height: 16px; color: var(--muted);
}
.chip {
    display: inline-flex; align-items: center; gap: var(--sp-1);
    padding: 8px var(--sp-3); border-radius: 999px;
    background: var(--surface); border: 1px solid var(--border);
    color: var(--ink-2); font-size: var(--text-sm); cursor: pointer;
    font-family: inherit;
    text-decoration: none;
    transition: all .12s var(--ease);
}
.chip:hover { background: var(--slate-50); border-color: #93c5fd; color: #0b3aa4; text-decoration: none; }
.chip.active {
    background: rgba(11,58,164,0.08); border-color: #0b3aa4;
    color: #0b3aa4; font-weight: 600;
}
.chip-count { opacity: 0.6; font-weight: 500; }

/* === Table === */
.emp-table-card {
    background: var(--surface); border: 1px solid var(--border);
    border-radius: 12px; overflow: hidden;
}
.emp-table {
    width: 100%; border-collapse: collapse;
    table-layout: auto;
}
.emp-table th, .emp-table td { white-space: nowrap; }
.emp-table th:first-child, .emp-table td:first-child {
    width: 100%; /* la colonna Dipendente prende lo spazio rimanente */
}
.emp-table th {
    text-align: left;
    padding: 14px var(--sp-3);
    background: var(--slate-50);
    font-size: 11px; font-weight: 600;
    color: var(--muted); text-transform: uppercase; letter-spacing: 0.06em;
    border-bottom: 1px solid var(--border);
}
.emp-table td {
    padding: 14px var(--sp-3);
    border-bottom: 1px solid var(--border);
    font-size: var(--text-sm);
    vertical-align: middle;
}
.emp-table tbody tr:last-child td { border-bottom: none; }
.emp-table tbody tr { transition: background .1s var(--ease); }
.emp-table tbody tr:hover { background: var(--slate-50); }
.emp-table tbody tr.inactive { opacity: 0.55; }

.emp-cell { display: flex; align-items: center; gap: var(--sp-3); min-width: 0; max-width: 100%; }
.emp-cell .av {
    width: 36px; height: 36px;
    border-radius: 50%;
    background: linear-gradient(135deg, #0b3aa4, #0b3aa4);
    color: white; display: inline-flex; align-items: center; justify-content: center;
    font-size: 11px; font-weight: 700;
    flex-shrink: 0; overflow: hidden;
    text-transform: uppercase;
}
.emp-cell .av img { width: 100%; height: 100%; object-fit: cover; }
.emp-cell-info { min-width: 0; flex: 1; }
.emp-name {
    font-weight: 600; color: var(--ink); font-size: var(--text-sm);
    line-height: 1.2;
    white-space: nowrap; overflow: hidden; text-overflow: ellipsis;
}
.emp-name a { color: var(--ink); text-decoration: none; }
.emp-name a:hover { color: #0b3aa4; text-decoration: none; }
.emp-sub {
    font-size: var(--text-xs); color: var(--muted); margin-top: 2px;
    white-space: nowrap; overflow: hidden; text-overflow: ellipsis;
}

.dept-pill {
    background: rgba(11,58,164,0.08); color: #0b3aa4;
    padding: 3px 10px; border-radius: 6px;
    font-size: var(--text-xs); font-weight: 500;
    display: inline-block;
    max-width: 100%;
    white-space: nowrap; overflow: hidden; text-overflow: ellipsis;
}
.emp-table code {
    font-size: 11px; color: var(--ink-2);
    background: var(--slate-100); padding: 2px 6px; border-radius: 4px;
    font-family: ui-monospace, "SF Mono", Menlo, monospace;
    display: inline-block; max-width: 100%;
    white-space: nowrap; overflow: hidden; text-overflow: ellipsis;
    vertical-align: middle;
}

.notif-icons { display: inline-flex; gap: 4px; align-items: center; }
.notif-mini {
    width: 22px; height: 22px; border-radius: 6px;
    display: inline-flex; align-items: center; justify-content: center;
}
.notif-mini.on  { background: #dcfce7; color: #16a34a; }
.notif-mini.off { background: var(--slate-100); color: #cbd5e1; }
.notif-mini svg { width: 12px; height: 12px; }

.emp-actions-cell { display: flex; gap: 4px; justify-content: flex-end; flex-wrap: nowrap; }
.icon-btn-sm {
    width: 30px; height: 30px; border-radius: 6px;
    border: 1px solid var(--border); background: var(--surface);
    color: var(--muted); cursor: pointer;
    display: inline-flex; align-items: center; justify-content: center;
    transition: all .12s var(--ease);
    text-decoration: none;
    padding: 0;
}
.icon-btn-sm:hover { border-color: #93c5fd; color: #0b3aa4; background: rgba(11,58,164,0.05); text-decoration: none; }
.icon-btn-sm.danger:hover { border-color: #fca5a5; color: #f75c6c; background: #fef2f2; }
.icon-btn-sm svg { width: 14px; height: 14px; }

.inline-form { display: inline-block; margin: 0; }

/* Tablet: nascondi CF (rimane visibile su click) */
@media (max-width: 1100px) {
    .emp-table th:nth-child(2),
    .emp-table td[data-label="CF"] { display: none; }
}
@media (max-width: 900px) {
    .emp-table th:nth-child(4),
    .emp-table td[data-label="Notifiche"] { display: none; }
}

/* Mobile: layout card */
@media (max-width: 768px) {
    .emp-table thead { display: none; }
    .emp-table, .emp-table tbody { display: block; width: 100%; }
    .emp-table tr {
        display: grid;
        grid-template-columns: 1fr auto;
        gap: 8px 12px;
        padding: var(--sp-3) var(--sp-4);
        border-bottom: 1px solid var(--border);
        align-items: center;
    }
    .emp-table tr:last-child { border-bottom: none; }
    .emp-table td {
        display: block; padding: 0; border: 0;
        white-space: normal;
        width: auto !important;
    }
    .emp-table td[data-label="Dipendente"] { grid-column: 1 / -1; }
    .emp-table td[data-label="CF"]       { grid-column: 1; display: block; }
    .emp-table td[data-label="Reparto"]  { grid-column: 2; display: block; }
    .emp-table td[data-label="Notifiche"]{ grid-column: 1; display: block; }
    .emp-table td[data-label="Stato"]    { grid-column: 2; display: block; }
    .emp-table td[data-label="CF"]::before,
    .emp-table td[data-label="Reparto"]::before,
    .emp-table td[data-label="Notifiche"]::before,
    .emp-table td[data-label="Stato"]::before {
        content: attr(data-label);
        font-size: 10px; font-weight: 600; color: var(--muted);
        text-transform: uppercase; letter-spacing: 0.05em;
        display: block; margin-bottom: 2px;
    }
    .emp-table td[data-label="Azioni"] {
        grid-column: 1 / -1;
        padding-top: var(--sp-2);
        border-top: 1px dashed var(--border);
    }
    .emp-actions-cell { justify-content: flex-start; }
}
</style>

<div class="admin-page">
    <?php if ($action === 'list'): ?>
        <div class="welcome-card emp-hero">
            <div>
                <h2>Gestione dipendenti</h2>
                <p>Anagrafica, accessi e contatti. <?= $countActive ?> dipendent<?= $countActive === 1 ? 'e' : 'i' ?> attiv<?= $countActive === 1 ? 'o' : 'i' ?><?php if ($countInactive > 0): ?> · <?= $countInactive ?> disattivat<?= $countInactive === 1 ? 'o' : 'i' ?><?php endif; ?>.</p>
            </div>
            <a href="?action=new" class="btn btn-lg" style="background: #0b3aa4; border: 1px solid #0b3aa4; color: white;">
                <svg viewBox="0 0 24 24" width="16" height="16" fill="currentColor"><path d="M19 13h-6v6h-2v-6H5v-2h6V5h2v6h6v2z"/></svg>
                Nuovo dipendente
            </a>
        </div>
    <?php else: ?>
        <div class="page-top" style="margin-bottom: var(--sp-4);">
            <a href="employees.php" class="btn btn-secondary">← Torna alla Lista</a>
        </div>
    <?php endif; ?>

    <?php if ($message): ?>
        <div class="alert alert-success"><?php echo $message; ?></div>
    <?php endif; ?>

    <?php if ($error): ?>
        <div class="alert alert-error"><?php echo htmlspecialchars($error); ?></div>
    <?php endif; ?>

    <?php if ($generatedPassword): ?>
        <div class="alert alert-warning">
            <strong>Nuova Password Generata:</strong>
            <code class="password-display"><?php echo htmlspecialchars($generatedPassword); ?></code>
            <br><small>Comunica questa password al dipendente. Non sara piu visibile dopo aver lasciato questa pagina.</small>
        </div>
    <?php endif; ?>

    <?php if ($action === 'list'): ?>
        <!-- Lista Dipendenti -->
        <div class="emp-filters">
            <form method="GET" class="filter-search" style="margin:0;">
                <svg viewBox="0 0 24 24" fill="currentColor"><path d="M15.5 14h-.79l-.28-.27A6.5 6.5 0 1 0 13 15.5l.27.28v.79l5 5L19.49 20l-5-5zm-6 0A4.5 4.5 0 1 1 14 9.5 4.5 4.5 0 0 1 9.5 14z"/></svg>
                <input type="search" name="search" value="<?php echo htmlspecialchars($search); ?>" placeholder="Cerca per nome, cognome, CF o username..." autocomplete="off">
            </form>
        </div>

        <?php if (empty($employees)): ?>
            <div class="empty-box" style="background:white;padding:3rem;border-radius:12px;text-align:center;border:1px solid var(--border);">
                <svg xmlns="http://www.w3.org/2000/svg" width="48" height="48" viewBox="0 0 24 24" fill="#cbd5e0"><path d="M12 12c2.21 0 4-1.79 4-4s-1.79-4-4-4-4 1.79-4 4 1.79 4 4 4zm0 2c-2.67 0-8 1.34-8 4v2h16v-2c0-2.66-5.33-4-8-4z"/></svg>
                <p style="color:#718096;margin:1rem 0 0;">Nessun dipendente trovato</p>
            </div>
        <?php else: ?>
            <div class="emp-table-card">
                <table class="emp-table">
                    <thead>
                        <tr>
                            <th>Dipendente</th>
                            <th>Codice fiscale</th>
                            <th>Reparto</th>
                            <th>Notifiche</th>
                            <th>Stato</th>
                            <th style="text-align:right;">Azioni</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php
                        $__deptColors = ['#0b3aa4','#2877ff','#4fa1ff','#7c3aed','#ec4899','#f59e0b','#10b981','#0891b2'];
                        foreach ($employees as $emp):
                            $_emailOn = (int) ($emp['notify_email'] ?? 1) === 1 && !empty($emp['email']);
                            $_pushOn  = (int) ($emp['notify_push'] ?? 1) === 1 && !empty($_pushSubscribed[(int)$emp['id']]);
                            $_fullName = trim($emp['last_name'] . ' ' . $emp['first_name']);
                            $_initials = '';
                            foreach (preg_split('/\s+/', $_fullName) as $p) { if ($p !== '') $_initials .= mb_substr($p,0,1); if (mb_strlen($_initials) >= 2) break; }
                            $_initials = mb_strtoupper($_initials);
                            $_photo = !empty($emp['photo_path']) ? (PUBLIC_URL . '/' . ltrim($emp['photo_path'], '/')) : null;
                            $_avBg = $__deptColors[crc32($emp['username']) % count($__deptColors)];
                        ?>
                            <tr class="<?php echo !$emp['is_active'] ? 'inactive' : ''; ?>">
                                <td data-label="Dipendente">
                                    <div class="emp-cell">
                                        <div class="av" style="background: linear-gradient(135deg, <?= $_avBg ?>, <?= $_avBg ?>cc);">
                                            <?php if ($_photo): ?>
                                                <img src="<?= htmlspecialchars($_photo) ?>" alt="<?= htmlspecialchars($_fullName) ?>">
                                            <?php else: ?>
                                                <?= htmlspecialchars($_initials) ?>
                                            <?php endif; ?>
                                        </div>
                                        <div class="emp-cell-info">
                                            <div class="emp-name">
                                                <a href="?action=view&id=<?= $emp['id'] ?>"><?= htmlspecialchars($_fullName) ?></a>
                                            </div>
                                            <div class="emp-sub"><?= htmlspecialchars($emp['email'] ?? $emp['username']) ?></div>
                                        </div>
                                    </div>
                                </td>
                                <td data-label="CF"><code><?php echo htmlspecialchars($emp['fiscal_code']); ?></code></td>
                                <td data-label="Reparto">
                                    <?php if (!empty($emp['department_name'])): ?>
                                        <span class="dept-pill"><?= htmlspecialchars($emp['department_name']) ?></span>
                                    <?php else: ?>
                                        <span style="color:var(--muted);font-size:var(--text-xs);">—</span>
                                    <?php endif; ?>
                                </td>
                                <td data-label="Notifiche">
                                    <div class="notif-icons">
                                        <span class="notif-mini <?= $_emailOn ? 'on' : 'off' ?>" title="Email <?= $_emailOn ? 'attive' : 'disattive' ?>">
                                            <svg viewBox="0 0 24 24" fill="currentColor"><path d="M20 4H4c-1.1 0-2 .9-2 2v12c0 1.1.9 2 2 2h16c1.1 0 2-.9 2-2V6c0-1.1-.9-2-2-2zm0 4l-8 5-8-5V6l8 5 8-5v2z"/></svg>
                                        </span>
                                        <span class="notif-mini <?= $_pushOn ? 'on' : 'off' ?>" title="Push <?= $_pushOn ? 'attive' : 'disattive' ?>">
                                            <svg viewBox="0 0 24 24" fill="currentColor"><path d="M12 22c1.1 0 2-.9 2-2h-4c0 1.1.9 2 2 2zm6-6v-5c0-3.07-1.63-5.64-4.5-6.32V4c0-.83-.67-1.5-1.5-1.5s-1.5.67-1.5 1.5v.68C7.64 5.36 6 7.92 6 11v5l-2 2v1h16v-1l-2-2z"/></svg>
                                        </span>
                                    </div>
                                </td>
                                <td data-label="Stato">
                                    <span class="badge <?php echo $emp['is_active'] ? 'badge-success' : 'badge-danger'; ?> badge-dot">
                                        <?php echo $emp['is_active'] ? 'Attivo' : 'Disattivato'; ?>
                                    </span>
                                </td>
                                <td data-label="Azioni">
                                    <div class="emp-actions-cell">
                                        <a href="?action=view&id=<?= $emp['id'] ?>" class="icon-btn-sm" title="Visualizza">
                                            <svg viewBox="0 0 24 24" fill="currentColor"><path d="M12 4.5C7 4.5 2.73 7.61 1 12c1.73 4.39 6 7.5 11 7.5s9.27-3.11 11-7.5c-1.73-4.39-6-7.5-11-7.5zM12 17c-2.76 0-5-2.24-5-5s2.24-5 5-5 5 2.24 5 5-2.24 5-5 5zm0-8c-1.66 0-3 1.34-3 3s1.34 3 3 3 3-1.34 3-3-1.34-3-3-3z"/></svg>
                                        </a>
                                        <a href="?action=edit&id=<?= $emp['id'] ?>" class="icon-btn-sm" title="Modifica">
                                            <svg viewBox="0 0 24 24" fill="currentColor"><path d="M3 17.25V21h3.75L17.81 9.94l-3.75-3.75L3 17.25zM20.71 7.04c.39-.39.39-1.02 0-1.41l-2.34-2.34c-.39-.39-1.02-.39-1.41 0l-1.83 1.83 3.75 3.75 1.83-1.83z"/></svg>
                                        </a>
                                        <form method="POST" class="inline-form" onsubmit="return confirm('Eliminare definitivamente <?= e($emp['username']) ?>? I documenti associati verranno rimossi. Operazione IRREVERSIBILE.')">
                                            <?= CSRF::field() ?>
                                            <input type="hidden" name="action" value="delete">
                                            <input type="hidden" name="id" value="<?= $emp['id'] ?>">
                                            <button type="submit" class="icon-btn-sm danger" title="Elimina">
                                                <svg viewBox="0 0 24 24" fill="currentColor"><path d="M6 19c0 1.1.9 2 2 2h8c1.1 0 2-.9 2-2V7H6v12zM19 4h-3.5l-1-1h-5l-1 1H5v2h14V4z"/></svg>
                                            </button>
                                        </form>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>

    <?php elseif ($action === 'new' || $action === 'edit'): ?>
        <!-- Form Dipendente -->
        <form method="POST" class="form-card">
            <?php echo CSRF::field(); ?>
            <input type="hidden" name="action" value="<?php echo $action === 'new' ? 'create' : 'update'; ?>">

            <h3>Credenziali di Accesso</h3>
            <div class="form-grid">
                <div class="form-group">
                    <label for="username">Username * <?php $renderVisChip('username'); ?></label>
                    <input type="text" id="username" name="username" required
                           minlength="3" maxlength="50" pattern="[a-zA-Z0-9_\.]+"
                           value="<?php echo htmlspecialchars($employee['username'] ?? $_POST['username'] ?? ''); ?>"
                           <?php echo $action === 'edit' ? '' : 'autocomplete="off"'; ?>>
                    <small>Minimo 3 caratteri. Solo lettere, numeri, underscore e punti.</small>
                </div>

                <?php if ($action === 'new'): ?>
                    <div class="form-group">
                        <label>Password</label>
                        <input type="text" disabled value="Generata automaticamente" style="background:#f7fafc;color:#718096;">
                        <small>Una password temporanea sicura verrà generata automaticamente e inviata via email al dipendente. Al primo accesso dovrà sceglierne una nuova.</small>
                    </div>
                <?php endif; ?>
            </div>

            <h3>Dati Anagrafici</h3>
            <div class="form-grid">
                <div class="form-group">
                    <label for="fiscal_code">Codice Fiscale * <?php $renderVisChip('fiscal_code'); ?></label>
                    <input type="text" id="fiscal_code" name="fiscal_code" required
                           maxlength="16" pattern="[A-Za-z]{6}[0-9]{2}[A-Za-z][0-9]{2}[A-Za-z][0-9]{3}[A-Za-z]"
                           value="<?php echo htmlspecialchars($employee['fiscal_code'] ?? $_POST['fiscal_code'] ?? ''); ?>"
                           class="uppercase">
                    <small>Formato: RSSMRA80A01H501U</small>
                </div>

                <div class="form-group">
                    <label for="first_name">Nome * <?php $renderVisChip('first_name'); ?></label>
                    <input type="text" id="first_name" name="first_name" required maxlength="50"
                           value="<?php echo htmlspecialchars($employee['first_name'] ?? $_POST['first_name'] ?? ''); ?>">
                </div>

                <div class="form-group">
                    <label for="last_name">Cognome * <?php $renderVisChip('last_name'); ?></label>
                    <input type="text" id="last_name" name="last_name" required maxlength="50"
                           value="<?php echo htmlspecialchars($employee['last_name'] ?? $_POST['last_name'] ?? ''); ?>">
                </div>

                <div class="form-group">
                    <label for="email">Email * <?php $renderVisChip('email'); ?></label>
                    <input type="email" id="email" name="email" maxlength="100" required
                           value="<?php echo htmlspecialchars($employee['email'] ?? $_POST['email'] ?? ''); ?>">
                    <?php if ($action === 'new'): ?>
                        <small>Le credenziali di accesso saranno inviate a questo indirizzo.</small>
                    <?php endif; ?>
                </div>

                <div class="form-group">
                    <label for="phone">Telefono <?php $renderVisChip('phone'); ?></label>
                    <input type="tel" id="phone" name="phone" maxlength="20"
                           value="<?php echo htmlspecialchars($employee['phone'] ?? $_POST['phone'] ?? ''); ?>">
                </div>

                <div class="form-group">
                    <label for="department_id">Reparto * <?php $renderVisChip('department_id'); ?></label>
                    <select id="department_id" name="department_id" required>
                        <option value="">-- Seleziona Reparto --</option>
                        <?php foreach ($departments as $dept): ?>
                            <option value="<?php echo $dept['id']; ?>"
                                <?php echo (($employee['department_id'] ?? $_POST['department_id'] ?? '') == $dept['id']) ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($dept['name']); ?> (<?php echo htmlspecialchars($dept['code']); ?>)
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="form-group">
                    <label for="position">Posizione <?php $renderVisChip('position'); ?></label>
                    <input type="text" id="position" name="position" maxlength="100"
                           value="<?php echo htmlspecialchars($employee['position'] ?? $_POST['position'] ?? ''); ?>">
                </div>

                <div class="form-group">
                    <label for="hire_date">Data Assunzione <?php $renderVisChip('hire_date'); ?></label>
                    <input type="date" id="hire_date" name="hire_date"
                           value="<?php echo htmlspecialchars($employee['hire_date'] ?? $_POST['hire_date'] ?? ''); ?>">
                </div>

                <!-- Dati personali estesi -->
                <h3 style="grid-column: 1 / -1; margin-top: 1.5rem; font-size: 1rem; color: #475569; border-top: 1px solid #e2e8f0; padding-top: 1rem;">Dati personali</h3>
                <div class="form-group">
                    <label for="birth_date">Data di nascita <?php $renderVisChip('birth_date'); ?></label>
                    <input type="date" id="birth_date" name="birth_date"
                           value="<?php echo htmlspecialchars($employee['birth_date'] ?? $_POST['birth_date'] ?? ''); ?>">
                    <small style="color:#94a3b8;">La data di nascita e' sempre visibile a tutti i colleghi per il banner compleanno.</small>
                </div>
                <div class="form-group">
                    <label for="address">Indirizzo di residenza <?php $renderVisChip('address'); ?></label>
                    <input type="text" id="address" name="address" maxlength="255"
                           value="<?php echo htmlspecialchars($employee['address'] ?? $_POST['address'] ?? ''); ?>">
                </div>

                <h3 style="grid-column: 1 / -1; margin-top: 1.5rem; font-size: 1rem; color: #475569; border-top: 1px solid #e2e8f0; padding-top: 1rem;">Dati economici</h3>
                <div class="form-group">
                    <label for="job_level">Livello CCNL <?php $renderVisChip('job_level'); ?></label>
                    <input type="text" id="job_level" name="job_level" maxlength="50"
                           value="<?php echo htmlspecialchars($employee['job_level'] ?? $_POST['job_level'] ?? ''); ?>">
                </div>
                <div class="form-group">
                    <label for="ral_amount">RAL annua (&euro;) <?php $renderVisChip('ral_amount'); ?></label>
                    <input type="number" step="0.01" min="0" id="ral_amount" name="ral_amount"
                           value="<?php echo htmlspecialchars($employee['ral_amount'] ?? $_POST['ral_amount'] ?? ''); ?>">
                </div>
                <div class="form-group">
                    <label for="monthly_salary">Retribuzione mensile lorda (&euro;) <?php $renderVisChip('monthly_salary'); ?></label>
                    <input type="number" step="0.01" min="0" id="monthly_salary" name="monthly_salary"
                           value="<?php echo htmlspecialchars($employee['monthly_salary'] ?? $_POST['monthly_salary'] ?? ''); ?>">
                </div>
                <div class="form-group">
                    <label for="iban">IBAN <?php $renderVisChip('iban'); ?></label>
                    <input type="text" id="iban" name="iban" maxlength="34" style="text-transform: uppercase; font-family: monospace;"
                           placeholder="IT00 X000 0000 0000 0000 0000 000"
                           value="<?php echo htmlspecialchars($employee['iban'] ?? $_POST['iban'] ?? ''); ?>">
                </div>

                <?php
                $__wdCompId = $action === 'edit' ? (int) $employee['company_id'] : (class_exists('Tenant') ? Tenant::currentCompanyId() : 1);
                $compDefaults = LeaveBalance::companyDefaults($__wdCompId);
                $empHasOverride = $action === 'edit'
                    ? (!empty($employee['working_days']) || $employee['hours_per_day'] !== null)
                    : false;
                $effDays = $action === 'edit' && !empty($employee['working_days'])
                    ? array_filter(array_map('trim', explode(',', $employee['working_days'])))
                    : $compDefaults['days'];
                $effHours = ($action === 'edit' && $employee['hours_per_day'] !== null)
                    ? (float) $employee['hours_per_day']
                    : $compDefaults['hours'];
                $currentYear = (int) date('Y');
                $balances = $action === 'edit'
                    ? LeaveBalance::getForEmployee((int) $employee['id'], $currentYear)
                    : [
                        'ferie'    => ['entitled' => 26, 'carried' => 0, 'manual_used' => 0, 'auto_used' => 0, 'residual' => 26],
                        'permesso' => ['entitled' => 32, 'carried' => 0, 'manual_used' => 0, 'auto_used' => 0, 'residual' => 32],
                    ];
                ?>

                <h3 style="grid-column: 1 / -1; margin-top: 1.5rem; font-size: 1rem; color: #475569; border-top: 1px solid #e2e8f0; padding-top: 1rem;">Orario lavorativo</h3>
                <div class="form-group" style="grid-column: 1 / -1;">
                    <label class="checkbox-label">
                        <input type="checkbox" name="working_days_override" value="1" id="wd_override" <?= $empHasOverride ? 'checked' : '' ?>>
                        Personalizza orario per questo dipendente
                    </label>
                    <small style="color:#94a3b8;">Default azienda: <?= htmlspecialchars(implode(', ', array_map(['LeaveBalance','dayLabel'], $compDefaults['days']))) ?> · <?= rtrim(rtrim(number_format($compDefaults['hours'], 2, ',', '.'), '0'), ',') ?>h/giorno</small>
                </div>
                <div class="form-group" id="wd_panel" style="grid-column: 1 / -1; <?= $empHasOverride ? '' : 'display:none;' ?>">
                    <label>Giorni lavorativi</label>
                    <div style="display:flex; gap:0.75rem; flex-wrap:wrap; margin-bottom:0.75rem;">
                        <?php foreach (LeaveBalance::allDayKeys() as $dk): ?>
                            <label class="checkbox-label" style="font-weight:500;">
                                <input type="checkbox" name="working_days[]" value="<?= $dk ?>" <?= in_array($dk, $effDays, true) ? 'checked' : '' ?>>
                                <?= htmlspecialchars(LeaveBalance::dayLabel($dk)) ?>
                            </label>
                        <?php endforeach; ?>
                    </div>
                    <label for="hours_per_day">Ore/giorno</label>
                    <input type="number" step="0.25" min="0" max="24" id="hours_per_day" name="hours_per_day"
                           value="<?= htmlspecialchars($employee['hours_per_day'] !== null ? (string) $employee['hours_per_day'] : '') ?>"
                           placeholder="<?= rtrim(rtrim(number_format($compDefaults['hours'], 2, '.', ''), '0'), '.') ?>" style="max-width:160px;">
                </div>

                <h3 style="grid-column: 1 / -1; margin-top: 1.5rem; font-size: 1rem; color: #475569; border-top: 1px solid #e2e8f0; padding-top: 1rem;">Saldo ferie e permessi (<?= $currentYear ?>)</h3>
                <input type="hidden" name="_balance_company_id" value="<?= $__wdCompId ?>">

                <?php
                $__ccnls = LeaveBalance::availableCcnls($__wdCompId);
                $__compRow = Database::fetchOne("SELECT default_ccnl_id FROM companies WHERE id = ?", [$__wdCompId]);
                $__compDefaultCcnl = $__compRow ? ($__compRow['default_ccnl_id'] ?? null) : null;
                $__compDefaultName = null;
                if ($__compDefaultCcnl) {
                    foreach ($__ccnls as $cc) { if ((int)$cc['id'] === (int)$__compDefaultCcnl) { $__compDefaultName = $cc['name']; break; } }
                }
                $__currentCcnl = $action === 'edit' ? ($employee['ccnl_id'] ?? null) : null;
                $__feOv = $action === 'edit' ? ($employee['ferie_year_override'] ?? null) : null;
                $__peOv = $action === 'edit' ? ($employee['permessi_year_override'] ?? null) : null;
                ?>

                <div class="form-group" style="grid-column: 1 / -1;">
                    <label for="ccnl_id">CCNL applicato</label>
                    <select id="ccnl_id" name="ccnl_id">
                        <option value="">— eredita default azienda <?= $__compDefaultName ? '(' . htmlspecialchars($__compDefaultName) . ')' : '(non impostato in Configurazione)' ?> —</option>
                        <?php foreach ($__ccnls as $cc): ?>
                            <option value="<?= (int)$cc['id'] ?>" <?= (int)$__currentCcnl === (int)$cc['id'] ? 'selected' : '' ?>>
                                <?= htmlspecialchars($cc['name']) ?> · <?= rtrim(rtrim(number_format($cc['ferie_days_year'], 1, ',', '.'), '0'), ',') ?>gg ferie / <?= rtrim(rtrim(number_format($cc['permessi_hours_year'], 1, ',', '.'), '0'), ',') ?>h permessi
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <small style="color:#94a3b8;">Determina la maturazione annua. Lascia vuoto per usare il CCNL aziendale.</small>
                </div>

                <div class="form-group" style="grid-column: 1 / -1; display:grid; grid-template-columns: 1fr 1fr; gap: 0.75rem;">
                    <div>
                        <label for="ferie_year_override">Override ferie/anno (giorni)</label>
                        <input type="text" inputmode="decimal" id="ferie_year_override" name="ferie_year_override"
                               pattern="[0-9]+([.,][0-9]+)?"
                               value="<?= $__feOv !== null ? htmlspecialchars(rtrim(rtrim(number_format((float)$__feOv, 2, ',', ''), '0'), ',')) : '' ?>"
                               placeholder="lascia vuoto: usa CCNL">
                    </div>
                    <div>
                        <label for="permessi_year_override">Override permessi/anno (ore)</label>
                        <input type="text" inputmode="decimal" id="permessi_year_override" name="permessi_year_override"
                               pattern="[0-9]+([.,][0-9]+)?"
                               value="<?= $__peOv !== null ? htmlspecialchars(rtrim(rtrim(number_format((float)$__peOv, 2, ',', ''), '0'), ',')) : '' ?>"
                               placeholder="lascia vuoto: usa CCNL">
                    </div>
                </div>
                <p style="grid-column: 1 / -1; font-size: 0.85rem; color: #475569; background: #eff6ff; border-left: 3px solid #0b3aa4; padding: 10px 14px; border-radius: 6px; margin: 0;">
                    💡 <strong>Prima volta?</strong> Inserisci solo il <strong>"Residuo riportato"</strong> con il valore attuale che il dipendente ha ad oggi (anche con virgola, es. <code>38,45</code>). La maturazione mensile e l'utilizzo dalle richieste approvate si aggiungono in automatico.
                </p>
                <?php foreach (LeaveBalance::TYPES as $bt):
                    $b = $balances[$bt];
                    $isF = $bt === 'ferie';
                    $unit = $isF ? 'giorni' : 'ore';
                    $label = $isF ? 'Ferie' : 'Permessi';
                    $entStr = rtrim(rtrim(number_format((float)$b['entitled'], 2, ',', ''), '0'), ',');
                    $carStr = rtrim(rtrim(number_format((float)$b['carried'],  2, ',', ''), '0'), ',');
                    $manStr = rtrim(rtrim(number_format((float)$b['manual_used'], 2, ',', ''), '0'), ',');
                ?>
                <div class="form-group" style="grid-column: 1 / -1; background:#f8fafc; padding:1rem; border-radius:8px; margin-bottom:0.5rem;">
                    <strong style="display:block; margin-bottom:0.5rem;"><?= $label ?> (<?= $unit ?>)</strong>
                    <input type="hidden" name="balance[<?= $bt ?>][year]" value="<?= $currentYear ?>">
                    <div style="display:flex; flex-direction:column; gap:0.6rem;">
                        <div>
                            <label style="font-size:0.8rem; color:#64748b;">Residuo riportato <small style="color:#94a3b8;">(da inserire al primo setup)</small></label>
                            <input type="text" inputmode="decimal" pattern="-?[0-9]+([.,][0-9]+)?"
                                   name="balance[<?= $bt ?>][carried]"
                                   value="<?= htmlspecialchars($carStr === '' ? '0' : $carStr) ?>"
                                   placeholder="es. 38,45">
                        </div>
                        <div>
                            <label style="font-size:0.8rem; color:#64748b;">Maturato anno corrente <small style="color:#94a3b8;">(auto)</small></label>
                            <input type="text" inputmode="decimal" pattern="[0-9]+([.,][0-9]+)?"
                                   name="balance[<?= $bt ?>][entitled]"
                                   value="<?= htmlspecialchars($entStr === '' ? '0' : $entStr) ?>"
                                   placeholder="0,00">
                        </div>
                        <div>
                            <label style="font-size:0.8rem; color:#64748b;">Correttivo manuale usati</label>
                            <input type="text" inputmode="decimal" pattern="[0-9]+([.,][0-9]+)?"
                                   name="balance[<?= $bt ?>][manual_used]"
                                   value="<?= htmlspecialchars($manStr === '' ? '0' : $manStr) ?>"
                                   placeholder="0,00">
                        </div>
                    </div>
                    <small style="display:block; margin-top:0.5rem; color:#64748b;">
                        Auto-dedotti da richieste approvate: <strong><?= rtrim(rtrim(number_format($b['auto_used'], 2, ',', '.'), '0'), ',') ?> <?= $unit ?></strong>
                        · Residuo attuale: <strong><?= rtrim(rtrim(number_format($b['residual'], 2, ',', '.'), '0'), ',') ?> <?= $unit ?></strong>
                    </small>
                </div>
                <?php endforeach; ?>

                <?php /* Checkbox "Dipendente attivo" rimossa: la disattivazione causa errori e non è necessaria */ ?>
                <input type="hidden" name="is_active" value="1">
                <script>
                (function() {
                    var cb = document.getElementById('wd_override');
                    var panel = document.getElementById('wd_panel');
                    if (cb && panel) {
                        cb.addEventListener('change', function() {
                            panel.style.display = cb.checked ? '' : 'none';
                        });
                    }
                })();
                </script>
            </div>

            <div class="form-actions">
                <button type="submit" class="btn btn-primary">
                    <?php echo $action === 'new' ? 'Crea Dipendente' : 'Salva Modifiche'; ?>
                </button>
                <a href="employees.php" class="btn btn-secondary">Annulla</a>
            </div>
        </form>

        <script>
        (function() {
            // Aggiorna in tempo reale il testo summary del chip "Visibile a" quando
            // l'admin (de)seleziona checkbox.
            function updateSummary(details) {
                var summaryEl = details.querySelector('.vis-summary');
                if (!summaryEl) return;
                var checks = details.querySelectorAll('input[type="checkbox"]:not(:disabled):checked');
                var labels = [];
                checks.forEach(function(c) {
                    var v = c.value;
                    if (v === 'accountant')    labels.push('Comm.');
                    if (v === 'admin_reparto') labels.push('Reparto');
                });
                summaryEl.textContent = labels.length === 0 ? 'Solo admin' : 'Admin + ' + labels.join(', ');
            }
            document.querySelectorAll('.vis-popover').forEach(function(d) {
                d.addEventListener('change', function() { updateSummary(d); });
            });
        })();
        </script>

    <?php elseif ($action === 'view' && $employee): ?>
        <!-- Dettaglio Dipendente — layout Option 1 (KPI strip + photo hero + tabs) -->
        <?php
        $docStats = ['payslip' => 0, 'cud' => 0, 'other' => 0, 'total' => count($documents ?? [])];
        foreach ($documents ?? [] as $doc) {
            if (isset($docStats[$doc['type']])) $docStats[$doc['type']]++;
        }
        $documentsByYear = [];
        foreach ($documents ?? [] as $doc) {
            $year = $doc['year'];
            if (!isset($documentsByYear[$year])) $documentsByYear[$year] = [];
            $documentsByYear[$year][] = $doc;
        }
        krsort($documentsByYear);

        // Anzianità (anni con 1 decimale)
        $__seniority = '-';
        if (!empty($employee['hire_date'])) {
            $__hd = new DateTime($employee['hire_date']);
            $__now = new DateTime('today');
            $__diff = $__hd->diff($__now);
            $__years = $__diff->y + round($__diff->m / 12, 1);
            $__seniority = number_format($__years, 1, ',', '') . ' anni';
        }
        // Iniziali per avatar
        $__empInitials = strtoupper(substr($employee['first_name'], 0, 1) . substr($employee['last_name'], 0, 1));
        $__empPhotoUrl = !empty($employee['photo_path']) ? (PUBLIC_URL . '/' . ltrim($employee['photo_path'], '/')) : null;
        ?>

        <?php
        // Helper editable field
        $fmtDate = function($d) { return $d ? date('d/m/Y', strtotime($d)) : ''; };
        $renderEdit = function($field, $type, $value, $display = null, $extra = []) use ($employee) {
            $display = $display ?? ($value ?: '—');
            $rawVal = $value;
            $attrs = '';
            foreach ($extra as $k => $v) { $attrs .= ' data-' . $k . '="' . htmlspecialchars((string)$v, ENT_QUOTES) . '"'; }
            ?>
            <div class="ed-field" data-id="<?= (int)$employee['id'] ?>" data-field="<?= $field ?>" data-type="<?= $type ?>" data-raw="<?= htmlspecialchars((string)$rawVal, ENT_QUOTES) ?>"<?= $attrs ?>>
                <span class="ed-value"><?= $display ?></span>
                <button type="button" class="ed-pencil" title="Modifica">
                    <svg width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"/></svg>
                </button>
            </div>
            <?php
        };
        // Lista reparti per select inline
        $__deptsForInline = [];
        try { $__deptsForInline = Database::fetchAll("SELECT id, name FROM departments WHERE company_id = ? AND is_active = TRUE ORDER BY name", [(int)$employee['company_id']]); } catch (Throwable $e) {}
        ?>

        <div class="emp-c-layout">
            <!-- =========== SIDEBAR sticky =========== -->
            <aside class="emp-side">
                <div class="emp-side-photo">
                    <?php if ($__empPhotoUrl): ?>
                        <img src="<?= htmlspecialchars($__empPhotoUrl) ?>" alt="<?= htmlspecialchars($employee['first_name'] . ' ' . $employee['last_name']) ?>">
                    <?php else: ?>
                        <span><?= htmlspecialchars($__empInitials) ?></span>
                    <?php endif; ?>
                </div>
                <h2 class="emp-side-name"><?= htmlspecialchars($employee['first_name'] . ' ' . $employee['last_name']) ?></h2>
                <div class="emp-side-role"><?= htmlspecialchars($employee['position'] ?? 'Posizione non specificata') ?></div>
                <div class="emp-side-tags">
                    <?php if (!empty($employee['department_name'])): ?>
                        <span class="tag-pill"><?= htmlspecialchars($employee['department_name']) ?></span>
                    <?php endif; ?>
                    <span class="tag-pill <?= $employee['is_active'] ? 'tag-green' : 'tag-red' ?>">
                        <?= $employee['is_active'] ? '🟢 Attivo' : '🔴 Disattivo' ?>
                    </span>
                </div>

                <div class="emp-side-contacts">
                    <?php if (!empty($employee['email'])): ?>
                    <a href="mailto:<?= htmlspecialchars($employee['email']) ?>" class="emp-side-c">
                        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="5" width="18" height="14" rx="2"/><path d="M3 7l9 6 9-6"/></svg>
                        <span><?= htmlspecialchars($employee['email']) ?></span>
                    </a>
                    <?php endif; ?>
                    <?php if (!empty($employee['phone'])): ?>
                    <a href="tel:<?= htmlspecialchars($employee['phone']) ?>" class="emp-side-c">
                        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M22 16.92v3a2 2 0 0 1-2.18 2A19.86 19.86 0 0 1 2 4.18 2 2 0 0 1 4 2h3a2 2 0 0 1 2 1.72c.13.93.36 1.84.7 2.71"/></svg>
                        <span><?= htmlspecialchars($employee['phone']) ?></span>
                    </a>
                    <?php endif; ?>
                    <?php if (!empty($employee['address'])): ?>
                    <div class="emp-side-c">
                        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 10c0 7-9 13-9 13S3 17 3 10a9 9 0 0 1 18 0z"/><circle cx="12" cy="10" r="3"/></svg>
                        <span><?= htmlspecialchars($employee['address']) ?></span>
                    </div>
                    <?php endif; ?>
                </div>

                <div class="emp-side-acts">
                    <a href="?action=edit&id=<?= $employee['id'] ?>" class="btn-c btn-c-primary">Modifica avanzata</a>
                </div>

                <div class="emp-side-tip">💡 Clicca <svg width="10" height="10" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="vertical-align:-1px;"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"/></svg> per modificare i campi inline.</div>
            </aside>

            <!-- =========== MAIN COLUMN =========== -->
            <div class="emp-main-col">
                <!-- KPI strip -->
                <div class="emp-kpi-strip">
                    <div class="emp-mkpi">
                        <div class="l">Anzianità</div>
                        <div class="v"><?= $__seniority ?></div>
                    </div>
                    <div class="emp-mkpi">
                        <div class="l">Documenti totali</div>
                        <div class="v"><?= $docStats['total'] ?></div>
                    </div>
                    <div class="emp-mkpi">
                        <div class="l">Buste paga · CUD</div>
                        <div class="v"><?= $docStats['payslip'] ?> <span style="font-size:14px; color:var(--muted); font-weight:500;">·</span> <?= $docStats['cud'] ?></div>
                    </div>
                </div>

                <!-- ===== ROW 1: Anagrafica grande (2fr) + Stats stacked (1fr) ===== -->
                <!-- ===== Ferie e permessi · GAUGE (visibilità immediata) ===== -->
                <?php
                $__balances = LeaveBalance::getForEmployee((int)$employee['id'], (int)date('Y'));
                $__leaveCfg = [
                    'ferie'    => ['label' => 'Ferie ' . date('Y'), 'color' => '#0b3aa4'],
                    'permesso' => ['label' => 'Permessi',           'color' => '#2877ff'],
                ];
                $__gaugeArc = pi() * 85;
                ?>
                <div class="emp-c-card">
                    <div class="emp-c-card-h">
                        <h3>Ferie e permessi · <?= date('Y') ?></h3>
                        <button type="button" class="emp-edit-balance-btn" onclick="empOpenBalanceModal()">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" width="14" height="14"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"/></svg>
                            Modifica
                        </button>
                    </div>
                    <div class="emp-c-card-b">
                        <div class="emp-gauges">
                            <?php foreach (LeaveBalance::TYPES as $type):
                                $b = $__balances[$type] ?? null;
                                if (!$b) continue;
                                $cfg = $__leaveCfg[$type];
                                $total = (float)$b['total'];
                                $used  = (float)$b['used'];
                                $resid = (float)$b['residual'];
                                $pctUsed = $total > 0 ? min(100, ($used / $total) * 100) : 0;
                                $dashUsed = ($pctUsed / 100) * $__gaugeArc;
                                $unit = $b['unit'] ?? 'gg';
                            ?>
                            <div class="emp-gauge-cell">
                                <h4><?= htmlspecialchars($cfg['label']) ?></h4>
                                <?php if ($total > 0): ?>
                                <div class="emp-gauge">
                                    <svg viewBox="0 0 200 110" preserveAspectRatio="xMidYMax meet">
                                        <path class="g-track" d="M 15 100 A 85 85 0 0 1 185 100"/>
                                        <path class="g-arc" d="M 15 100 A 85 85 0 0 1 185 100"
                                              stroke-dasharray="<?= number_format($dashUsed, 2, '.', '') ?> <?= number_format($__gaugeArc, 2, '.', '') ?>"
                                              style="stroke: <?= $cfg['color'] ?>;"/>
                                    </svg>
                                    <div class="g-center">
                                        <div class="g-big"><?= rtrim(rtrim(number_format($resid, 1, ',', '.'), '0'), ',') ?></div>
                                        <div class="g-lbl"><?= htmlspecialchars($unit) ?> residui</div>
                                    </div>
                                </div>
                                <div class="emp-gauge-ends">
                                    <span>0</span>
                                    <span><?= rtrim(rtrim(number_format($total, 1, ',', '.'), '0'), ',') ?> <?= $unit ?></span>
                                </div>
                                <div class="emp-gauge-stats">
                                    <div class="ss">
                                        <div class="l">Utilizzati</div>
                                        <div class="v"><?= rtrim(rtrim(number_format($used, 1, ',', '.'), '0'), ',') ?></div>
                                    </div>
                                    <div class="ss">
                                        <div class="l">Residui</div>
                                        <div class="v" style="color:<?= $cfg['color'] ?>"><?= rtrim(rtrim(number_format($resid, 1, ',', '.'), '0'), ',') ?></div>
                                    </div>
                                    <div class="ss">
                                        <div class="l">Totale</div>
                                        <div class="v"><?= rtrim(rtrim(number_format($total, 1, ',', '.'), '0'), ',') ?></div>
                                    </div>
                                </div>
                                <?php else: ?>
                                    <div class="emp-gauge-empty"><p>Saldo non configurato</p></div>
                                <?php endif; ?>
                            </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </div>

                <?php
                $__ccnlList = LeaveBalance::availableCcnls((int)$employee['company_id']);
                $__compDef = Database::fetchOne("SELECT default_ccnl_id FROM companies WHERE id = ?", [(int)$employee['company_id']]);
                $__compDefId = $__compDef['default_ccnl_id'] ?? null;
                $__compDefName = '';
                foreach ($__ccnlList as $cc) {
                    if ((int)$cc['id'] === (int)$__compDefId) { $__compDefName = $cc['name']; break; }
                }
                $bF = $__balances['ferie']    ?? ['entitled'=>0,'carried'=>0,'manual_used'=>0];
                $bP = $__balances['permesso'] ?? ['entitled'=>0,'carried'=>0,'manual_used'=>0];
                $fmtNum = fn($v) => rtrim(rtrim(number_format((float)$v, 2, ',', ''), '0'), ',');
                ?>
                <div class="emp-balance-modal-overlay" id="empBalanceModal" onclick="if(event.target===this) empCloseBalanceModal()">
                    <div class="emp-balance-modal">
                        <div class="emp-balance-modal-h">
                            <h3>Modifica saldi <?= date('Y') ?></h3>
                            <button type="button" class="emp-balance-modal-close" onclick="empCloseBalanceModal()">&times;</button>
                        </div>
                        <form method="POST" action="employees.php">
                            <?= CSRF::field() ?>
                            <input type="hidden" name="action" value="update_balance_inline">
                            <input type="hidden" name="id" value="<?= (int)$employee['id'] ?>">
                            <input type="hidden" name="year" value="<?= date('Y') ?>">

                            <div class="emp-balance-modal-b">
                                <div class="emp-balance-info">
                                    💡 Inserisci il <strong>residuo attuale</strong> di ferie e permessi (es. <code>38,45</code> dal file paghe). Il sistema da oggi in poi farà <strong>maturare</strong> il dovuto mensilmente e <strong>sottrarrà</strong> le richieste approvate. Non serve toccare altro.
                                </div>

                                <div class="emp-balance-grid">
                                    <div class="emp-balance-fg" style="grid-column: 1 / -1;">
                                        <label>Residuo ferie ad oggi (giorni)</label>
                                        <input type="text" inputmode="decimal" pattern="-?[0-9]+([.,][0-9]+)?"
                                               name="balance[ferie][residual]"
                                               value="<?= htmlspecialchars($fmtNum($bF['residual'] ?? 0)) ?>"
                                               placeholder="es. 20" required>
                                    </div>
                                    <div class="emp-balance-fg" style="grid-column: 1 / -1;">
                                        <label>Residuo permessi ad oggi (ore)</label>
                                        <input type="text" inputmode="decimal" pattern="-?[0-9]+([.,][0-9]+)?"
                                               name="balance[permesso][residual]"
                                               value="<?= htmlspecialchars($fmtNum($bP['residual'] ?? 0)) ?>"
                                               placeholder="es. 38,45" required>
                                    </div>
                                </div>

                                <details class="emp-balance-advanced">
                                    <summary>Avanzate: contratto e override</summary>
                                    <div class="emp-balance-fg" style="margin-top: 12px;">
                                        <label for="bm_ccnl">CCNL applicato</label>
                                        <select id="bm_ccnl" name="ccnl_id">
                                            <option value="">— eredita default azienda <?= $__compDefName ? '('. htmlspecialchars($__compDefName) .')' : '(non impostato)' ?> —</option>
                                            <?php foreach ($__ccnlList as $cc): ?>
                                                <option value="<?= (int)$cc['id'] ?>" <?= (int)($employee['ccnl_id'] ?? 0) === (int)$cc['id'] ? 'selected' : '' ?>>
                                                    <?= htmlspecialchars($cc['name']) ?> · <?= rtrim(rtrim(number_format($cc['ferie_days_year'], 1, ',', '.'), '0'), ',') ?>gg / <?= rtrim(rtrim(number_format($cc['permessi_hours_year'], 1, ',', '.'), '0'), ',') ?>h
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                    <div class="emp-balance-grid" style="margin-top: 10px;">
                                        <div class="emp-balance-fg">
                                            <label>Override ferie/anno (giorni)</label>
                                            <input type="text" inputmode="decimal" pattern="[0-9]+([.,][0-9]+)?"
                                                   name="ferie_year_override"
                                                   value="<?= $employee['ferie_year_override'] !== null ? htmlspecialchars($fmtNum($employee['ferie_year_override'])) : '' ?>"
                                                   placeholder="usa CCNL">
                                        </div>
                                        <div class="emp-balance-fg">
                                            <label>Override permessi/anno (ore)</label>
                                            <input type="text" inputmode="decimal" pattern="[0-9]+([.,][0-9]+)?"
                                                   name="permessi_year_override"
                                                   value="<?= $employee['permessi_year_override'] !== null ? htmlspecialchars($fmtNum($employee['permessi_year_override'])) : '' ?>"
                                                   placeholder="usa CCNL">
                                        </div>
                                    </div>
                                </details>
                            </div>
                            <div class="emp-balance-modal-f">
                                <button type="button" class="emp-balance-btn emp-balance-btn-ghost" onclick="empCloseBalanceModal()">Annulla</button>
                                <button type="submit" class="emp-balance-btn emp-balance-btn-primary">Salva saldi</button>
                            </div>
                        </form>
                    </div>
                </div>

                <style>
                .emp-edit-balance-btn {
                    display: inline-flex; align-items: center; gap: 6px;
                    background: white; border: 1px solid #e6e8f0; color: #0b3aa4;
                    padding: 6px 12px; border-radius: 8px;
                    font-family: inherit; font-size: 12px; font-weight: 600;
                    cursor: pointer; transition: all .12s ease;
                }
                .emp-edit-balance-btn:hover { border-color: #0b3aa4; background: rgba(11,58,164,0.04); }

                .emp-balance-modal-overlay {
                    position: fixed; inset: 0; background: rgba(15,23,42,0.45);
                    display: none; align-items: center; justify-content: center; z-index: 1000;
                    padding: 16px;
                }
                .emp-balance-modal-overlay.show { display: flex; }
                .emp-balance-modal {
                    background: white; border-radius: 16px; max-width: 560px; width: 100%;
                    max-height: 92vh; overflow-y: auto;
                    box-shadow: 0 24px 64px rgba(15,23,42,0.25);
                }
                .emp-balance-modal-h {
                    padding: 18px 22px; border-bottom: 1px solid #e6e8f0;
                    display: flex; align-items: center; justify-content: space-between;
                }
                .emp-balance-modal-h h3 {
                    margin: 0; font-family: 'Host Grotesk', sans-serif;
                    font-size: 16px; font-weight: 700; color: #1e1e2f;
                }
                .emp-balance-modal-close {
                    background: transparent; border: none; cursor: pointer;
                    color: #94a3b8; font-size: 22px; line-height: 1;
                    width: 28px; height: 28px;
                }
                .emp-balance-modal-b { padding: 18px 22px; display: flex; flex-direction: column; gap: 14px; }
                .emp-balance-info {
                    font-size: 12.5px; color: #475569;
                    background: #eff6ff; border-left: 3px solid #0b3aa4;
                    padding: 10px 14px; border-radius: 6px;
                    line-height: 1.5;
                }
                .emp-balance-info code { background: white; padding: 1px 6px; border-radius: 4px; }
                .emp-balance-fg label {
                    display: block; font-size: 11px; font-weight: 600; color: #475569;
                    text-transform: uppercase; letter-spacing: 0.04em; margin-bottom: 4px;
                }
                .emp-balance-fg input, .emp-balance-fg select {
                    width: 100%; padding: 10px 12px;
                    border: 1px solid #e6e8f0; border-radius: 8px;
                    font-family: inherit; font-size: 14px; background: white;
                }
                .emp-balance-fg input:focus, .emp-balance-fg select:focus {
                    outline: none; border-color: #0b3aa4;
                    box-shadow: 0 0 0 3px rgba(11,58,164,0.10);
                }
                .emp-balance-grid {
                    display: grid; grid-template-columns: 1fr 1fr; gap: 12px;
                }
                .emp-balance-advanced {
                    margin-top: 4px;
                    border-top: 1px solid #e6e8f0;
                    padding-top: 12px;
                }
                .emp-balance-advanced summary {
                    cursor: pointer;
                    font-size: 12.5px; font-weight: 600;
                    color: #0b3aa4;
                    padding: 4px 0;
                    list-style: none;
                }
                .emp-balance-advanced summary::-webkit-details-marker { display: none; }
                .emp-balance-advanced summary::before {
                    content: '▶'; display: inline-block; font-size: 10px;
                    margin-right: 6px; transition: transform .15s ease;
                }
                .emp-balance-advanced[open] summary::before { transform: rotate(90deg); }
                .emp-balance-modal-f {
                    padding: 14px 22px; border-top: 1px solid #e6e8f0;
                    display: flex; justify-content: flex-end; gap: 8px;
                }
                .emp-balance-btn {
                    padding: 9px 18px; border-radius: 8px; border: 1px solid transparent;
                    font-family: inherit; font-size: 13px; font-weight: 600; cursor: pointer;
                }
                .emp-balance-btn-ghost { background: white; color: #475569; border-color: #e6e8f0; }
                .emp-balance-btn-ghost:hover { border-color: #0b3aa4; color: #0b3aa4; }
                .emp-balance-btn-primary { background: #0b3aa4; color: white; }
                .emp-balance-btn-primary:hover { background: #082b7b; }
                @media (max-width: 640px) {
                    .emp-balance-grid { grid-template-columns: 1fr; }
                }
                </style>
                <script>
                function empOpenBalanceModal() { document.getElementById('empBalanceModal').classList.add('show'); }
                function empCloseBalanceModal() { document.getElementById('empBalanceModal').classList.remove('show'); }
                </script>

                <div class="emp-c-card">
                    <div class="emp-c-card-h">
                        <h3>Anagrafica</h3>
                        <span class="hint">Solo admin</span>
                    </div>
                    <div class="emp-c-card-b">
                        <div class="emp-c-facts emp-c-facts-3">
                            <div class="fact"><div class="l">Nome</div><div class="v"><?php $renderEdit('first_name', 'text', $employee['first_name'], htmlspecialchars($employee['first_name'])); ?></div></div>
                            <div class="fact"><div class="l">Cognome</div><div class="v"><?php $renderEdit('last_name', 'text', $employee['last_name'], htmlspecialchars($employee['last_name'])); ?></div></div>
                            <div class="fact"><div class="l">Username</div><div class="v"><code><?= htmlspecialchars($employee['username']) ?></code></div></div>
                            <div class="fact"><div class="l">Codice fiscale</div><div class="v"><code><?= htmlspecialchars($employee['fiscal_code']) ?></code></div></div>
                            <div class="fact"><div class="l">Data nascita</div><div class="v"><?php $renderEdit('birth_date', 'date', $employee['birth_date'] ?? '', $employee['birth_date'] ? $fmtDate($employee['birth_date']) : '<span style="color:var(--muted);">—</span>'); ?></div></div>
                            <div class="fact" style="grid-column: span 2;"><div class="l">Email</div><div class="v"><?php $renderEdit('email', 'email', $employee['email'] ?? '', $employee['email'] ? '<a href="mailto:'.htmlspecialchars($employee['email']).'">'.htmlspecialchars($employee['email']).'</a>' : '<span style="color:var(--muted);">—</span>'); ?></div></div>
                            <div class="fact"><div class="l">Telefono</div><div class="v"><?php $renderEdit('phone', 'tel', $employee['phone'] ?? '', $employee['phone'] ? '<a href="tel:'.htmlspecialchars($employee['phone']).'">'.htmlspecialchars($employee['phone']).'</a>' : '<span style="color:var(--muted);">—</span>'); ?></div></div>
                            <div class="fact" style="grid-column: span 3;"><div class="l">Indirizzo</div><div class="v"><?php $renderEdit('address', 'text', $employee['address'] ?? '', $employee['address'] ? htmlspecialchars($employee['address']) : '<span style="color:var(--muted);">—</span>'); ?></div></div>
                        </div>
                    </div>
                </div>

                <!-- ===== Lavoro ===== -->
                <div class="emp-c-card">
                    <div class="emp-c-card-h"><h3>Lavoro</h3></div>
                    <div class="emp-c-card-b">
                        <div class="emp-c-facts emp-c-facts-4">
                            <div class="fact"><div class="l">Reparto</div><div class="v"><?php $renderEdit('department_id', 'select', $employee['department_id'] ?? '', htmlspecialchars($employee['department_name'] ?? '—'), ['options' => json_encode(array_map(fn($d) => ['v' => $d['id'], 'l' => $d['name']], $__deptsForInline))]); ?></div></div>
                            <div class="fact"><div class="l">Posizione</div><div class="v"><?php $renderEdit('position', 'text', $employee['position'] ?? '', $employee['position'] ? htmlspecialchars($employee['position']) : '<span style="color:var(--muted);">—</span>'); ?></div></div>
                            <div class="fact"><div class="l">Data assunzione</div><div class="v"><?php $renderEdit('hire_date', 'date', $employee['hire_date'] ?? '', $employee['hire_date'] ? $fmtDate($employee['hire_date']) : '<span style="color:var(--muted);">—</span>'); ?></div></div>
                            <div class="fact"><div class="l">Inquadramento</div><div class="v"><?php $renderEdit('job_level', 'text', $employee['job_level'] ?? '', $employee['job_level'] ? htmlspecialchars($employee['job_level']) : '<span style="color:var(--muted);">—</span>'); ?></div></div>
                        </div>
                    </div>
                </div>

                <!-- ===== Dati economici ===== -->
                <div class="emp-c-card">
                    <div class="emp-c-card-h"><h3>Dati economici</h3><span class="hint">Solo admin</span></div>
                    <div class="emp-c-card-b">
                        <div class="emp-c-facts emp-c-facts-4">
                            <div class="fact"><div class="l">RAL annua</div><div class="v"><?php $renderEdit('ral_amount', 'number', $employee['ral_amount'] ?? '', !empty($employee['ral_amount']) ? '€ ' . number_format((float)$employee['ral_amount'], 0, ',', '.') : '<span style="color:var(--muted);">—</span>'); ?></div></div>
                            <div class="fact"><div class="l">Mensile</div><div class="v"><?php $renderEdit('monthly_salary', 'number', $employee['monthly_salary'] ?? '', !empty($employee['monthly_salary']) ? '€ ' . number_format((float)$employee['monthly_salary'], 0, ',', '.') : '<span style="color:var(--muted);">—</span>'); ?></div></div>
                            <div class="fact" style="grid-column: span 2;"><div class="l">IBAN</div><div class="v"><?php $renderEdit('iban', 'text', $employee['iban'] ?? '', $employee['iban'] ? '<code>' . htmlspecialchars($employee['iban']) . '</code>' : '<span style="color:var(--muted);">—</span>'); ?></div></div>
                        </div>
                    </div>
                </div>

                <!-- ===== Documenti consulente/commercialista (buste, CUD) ===== -->
                <div class="emp-c-row">
                    <div class="emp-c-card">
                        <div class="emp-c-card-h">
                            <h3>Documenti (buste paga, CUD)</h3>
                            <span class="hint">Caricati da consulente / commercialista</span>
                        </div>
                        <div class="emp-c-card-b">
                    <div class="docs-section" style="margin-top:0;">
                <div class="docs-header" style="border:0; padding:0 0 12px;">
                    <div class="doc-filters">
                        <button class="filter-btn active" data-filter="all">Tutti</button>
                        <button class="filter-btn" data-filter="payslip">Buste</button>
                        <button class="filter-btn" data-filter="cud">CUD</button>
                        <button class="filter-btn" data-filter="other">Altri</button>
                    </div>
                </div>
                <?php if (empty($documents)): ?>
                    <div class="empty-msg">Nessun documento caricato</div>
                <?php else: ?>
                    <div class="docs-list">
                        <?php foreach ($documentsByYear as $year => $yearDocs): ?>
                        <div class="year-group">
                            <h4 class="year-title"><?php echo $year; ?></h4>
                            <?php foreach ($yearDocs as $doc): ?>
                            <div class="doc-row" data-type="<?php echo $doc['type']; ?>">
                                <div class="doc-type <?php echo $doc['type']; ?>"><svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor"><path d="M14 2H6c-1.1 0-2 .9-2 2v16c0 1.1.9 2 2 2h12c1.1 0 2-.9 2-2V8l-6-6z"/></svg></div>
                                <div class="doc-info"><span class="name"><?php echo htmlspecialchars($doc['title']); ?></span><span class="date"><?php echo getMonthName($doc['month']); ?></span></div>
                                <a href="<?php echo PUBLIC_URL; ?>/api/download.php?id=<?php echo $doc['id']; ?>" class="doc-dl"><svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor"><path d="M19 9h-4V3H9v6H5l7 7 7-7zM5 18v2h14v-2H5z"/></svg></a>
                            </div>
                            <?php endforeach; ?>
                        </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
                    </div><!-- /.docs-section consulente -->
                        </div>
                    </div>
                </div>

                <!-- ===== ROW 5: Documenti caricati dall'admin (contratti, certificati) ===== -->
                <div class="emp-c-row">
                    <div class="emp-c-card">
                        <div class="emp-c-card-h">
                            <h3>Documenti dipendente</h3>
                            <span class="hint">Caricati dall'admin</span>
                        </div>
                        <div class="emp-c-card-b">
            <?php
            $_edDocs = EmployeeDocument::getByEmployee((int) $employee['id']);
            $_edStatus = $_GET['ed_status'] ?? '';
            ?>

            <!-- Upload area inline (sempre visibile per admin) -->
            <form method="post" action="employee-documents.php" enctype="multipart/form-data" class="ed-upload-inline">
                <?= CSRF::field() ?>
                <input type="hidden" name="action" value="upload">
                <input type="hidden" name="employee_id" value="<?= (int) $employee['id'] ?>">
                <div class="ed-upload-row">
                    <label class="ed-upload-file">
                        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="17 8 12 3 7 8"/><line x1="12" y1="3" x2="12" y2="15"/></svg>
                        <span class="ed-upload-text">Scegli file…</span>
                        <input type="file" name="document" required onchange="document.getElementById('edu-name').focus(); this.parentNode.querySelector('.ed-upload-text').textContent = this.files[0]?.name || 'Scegli file…';">
                    </label>
                    <input id="edu-name" type="text" name="name" required maxlength="255" placeholder="Nome documento (es. Contratto 2026)" class="ed-upload-name">
                    <label class="ed-upload-vis" title="Rendi visibile al dipendente">
                        <input type="checkbox" name="visible_to_employee" value="1">
                        <span>Visibile al dip.</span>
                    </label>
                    <button type="submit" class="btn-c btn-c-primary">
                        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
                        Carica
                    </button>
                </div>
                <?php if ($_edStatus === 'uploaded'): ?>
                    <div class="alert alert-success" style="margin: 12px 0 0;">Documento caricato.</div>
                <?php elseif ($_edStatus === 'updated'): ?>
                    <div class="alert alert-success" style="margin: 12px 0 0;">Documento aggiornato.</div>
                <?php elseif ($_edStatus === 'deleted'): ?>
                    <div class="alert alert-success" style="margin: 12px 0 0;">Documento eliminato.</div>
                <?php elseif (strpos((string) $_edStatus, 'error') === 0): ?>
                    <div class="alert alert-danger" style="margin: 12px 0 0;">Errore: <?= htmlspecialchars(substr((string) $_edStatus, 6)) ?></div>
                <?php endif; ?>
            </form>

            <div id="docs" class="docs-section" style="margin-top:14px;">

                <?php if ($_edStatus === 'uploaded'): ?>
                    <div class="alert alert-success" style="margin:0.5rem 0;">Documento caricato.</div>
                <?php elseif ($_edStatus === 'updated'): ?>
                    <div class="alert alert-success" style="margin:0.5rem 0;">Documento aggiornato.</div>
                <?php elseif ($_edStatus === 'deleted'): ?>
                    <div class="alert alert-success" style="margin:0.5rem 0;">Documento eliminato.</div>
                <?php elseif (strpos((string) $_edStatus, 'error') === 0): ?>
                    <div class="alert alert-danger" style="margin:0.5rem 0;">Errore: <?= htmlspecialchars(substr((string) $_edStatus, 6)) ?></div>
                <?php endif; ?>

                <?php if (empty($_edDocs)): ?>
                    <div class="empty-msg">Nessun documento caricato</div>
                <?php else: ?>
                    <div class="docs-list">
                        <?php foreach ($_edDocs as $d): ?>
                            <div class="doc-row">
                                <div class="doc-type other"><svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor"><path d="M14 2H6c-1.1 0-2 .9-2 2v16c0 1.1.9 2 2 2h12c1.1 0 2-.9 2-2V8l-6-6z"/></svg></div>
                                <div class="doc-info">
                                    <span class="name"><?= htmlspecialchars($d['name']) ?></span>
                                    <span class="date">
                                        <?= number_format($d['file_size'] / 1024, 1) ?> KB · <?= htmlspecialchars(date('d/m/Y', strtotime($d['created_at']))) ?>
                                        <?php if ($d['expires_on']): ?> · scade <?= htmlspecialchars(date('d/m/Y', strtotime($d['expires_on']))) ?><?php endif; ?>
                                    </span>
                                </div>
                                <span class="doc-badge <?= $d['visible_to_employee'] ? 'visible' : 'hidden' ?>" title="<?= $d['visible_to_employee'] ? 'Visibile al dipendente' : 'Nascosto al dipendente' ?>">
                                    <?= $d['visible_to_employee'] ? 'Visibile' : 'Nascosto' ?>
                                </span>
                                <div class="doc-actions">
                                    <a href="employee-documents.php?download=<?= (int) $d['id'] ?>" class="doc-dl" title="Scarica">
                                        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor"><path d="M19 9h-4V3H9v6H5l7 7 7-7zM5 18v2h14v-2H5z"/></svg>
                                    </a>
                                    <form method="post" action="employee-documents.php" class="doc-action-form">
                                        <?= CSRF::field() ?>
                                        <input type="hidden" name="action" value="toggle_visibility">
                                        <input type="hidden" name="employee_id" value="<?= (int) $employee['id'] ?>">
                                        <input type="hidden" name="document_id" value="<?= (int) $d['id'] ?>">
                                        <button type="submit" class="doc-dl" title="<?= $d['visible_to_employee'] ? 'Nascondi al dipendente' : 'Rendi visibile al dipendente' ?>">
                                            <?php if ($d['visible_to_employee']): ?>
                                                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor"><path d="M12 4.5C7 4.5 2.73 7.61 1 12c1.73 4.39 6 7.5 11 7.5s9.27-3.11 11-7.5c-1.73-4.39-6-7.5-11-7.5zM12 17c-2.76 0-5-2.24-5-5s2.24-5 5-5 5 2.24 5 5-2.24 5-5 5zm0-8c-1.66 0-3 1.34-3 3s1.34 3 3 3 3-1.34 3-3-1.34-3-3-3z"/></svg>
                                            <?php else: ?>
                                                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor"><path d="M12 7c2.76 0 5 2.24 5 5 0 .65-.13 1.26-.36 1.83l2.92 2.92c1.51-1.26 2.7-2.89 3.43-4.75-1.73-4.39-6-7.5-11-7.5-1.4 0-2.74.25-3.98.7l2.16 2.16C10.74 7.13 11.35 7 12 7zM2 4.27l2.28 2.28.46.46C3.08 8.3 1.78 10.02 1 12c1.73 4.39 6 7.5 11 7.5 1.55 0 3.03-.3 4.38-.84l.42.42L19.73 22 21 20.73 3.27 3 2 4.27zM7.53 9.8l1.55 1.55c-.05.21-.08.43-.08.65 0 1.66 1.34 3 3 3 .22 0 .44-.03.65-.08l1.55 1.55c-.67.33-1.41.53-2.2.53-2.76 0-5-2.24-5-5 0-.79.2-1.53.53-2.2zm4.31-.78l3.15 3.15.02-.16c0-1.66-1.34-3-3-3l-.17.01z"/></svg>
                                            <?php endif; ?>
                                        </button>
                                    </form>
                                    <button type="button" class="doc-dl" title="Rinomina"
                                            onclick="var n=prompt('Nuovo nome:', <?= htmlspecialchars(json_encode($d['name']), ENT_QUOTES, 'UTF-8') ?>); if(n){var f=window.document.getElementById('ed-rename-<?= (int) $d['id'] ?>'); f.querySelector('input[name=name]').value=n; f.submit();}">
                                        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor"><path d="M3 17.25V21h3.75L17.81 9.94l-3.75-3.75L3 17.25zM20.71 7.04c.39-.39.39-1.02 0-1.41l-2.34-2.34c-.39-.39-1.02-.39-1.41 0l-1.83 1.83 3.75 3.75 1.83-1.83z"/></svg>
                                    </button>
                                    <form id="ed-rename-<?= (int) $d['id'] ?>" method="post" action="employee-documents.php" style="display:none;">
                                        <?= CSRF::field() ?>
                                        <input type="hidden" name="action" value="rename">
                                        <input type="hidden" name="employee_id" value="<?= (int) $employee['id'] ?>">
                                        <input type="hidden" name="document_id" value="<?= (int) $d['id'] ?>">
                                        <input type="hidden" name="name" value="">
                                    </form>
                                    <form method="post" action="employee-documents.php" class="doc-action-form" onsubmit="return confirm('Eliminare definitivamente?');">
                                        <?= CSRF::field() ?>
                                        <input type="hidden" name="action" value="delete">
                                        <input type="hidden" name="employee_id" value="<?= (int) $employee['id'] ?>">
                                        <input type="hidden" name="document_id" value="<?= (int) $d['id'] ?>">
                                        <button type="submit" class="doc-dl doc-danger" title="Elimina">
                                            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor"><path d="M6 19c0 1.1.9 2 2 2h8c1.1 0 2-.9 2-2V7H6v12zM19 4h-3.5l-1-1h-5l-1 1H5v2h14V4z"/></svg>
                                        </button>
                                    </form>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>

            <style>
            .doc-badge {
                font-size: 0.65rem;
                padding: 2px 8px;
                border-radius: 10px;
                font-weight: 600;
                flex-shrink: 0;
                margin-right: 0.5rem;
            }
            .doc-badge.visible { background: #c6f6d5; color: #276749; }
            .doc-badge.hidden { background: #e2e8f0; color: #718096; }
            .doc-actions {
                display: flex;
                align-items: center;
                gap: 0.3rem;
                flex-shrink: 0;
            }
            .doc-action-form { display: inline; margin: 0; }
            .doc-actions button.doc-dl {
                background: #edf2f7;
                border: none;
                padding: 0;
                cursor: pointer;
            }
            .doc-actions button.doc-dl:hover { background: #3182ce; color: white; }
            .doc-actions button.doc-danger:hover { background: #e53e3e; color: white; }
            </style>

                        </div><!-- /.emp-c-card-b -->
                    </div><!-- /.emp-c-card -->
                </div><!-- /.emp-c-row docs admin -->
            </div><!-- /.emp-main-col -->
        </div><!-- /.emp-c-layout -->

            <script>
            // Inline editor per i campi del profilo dipendente
            (function(){
                const csrf = document.querySelector('meta[name="csrf-token"]')?.content || '';
                document.querySelectorAll('.ed-field').forEach(field => {
                    const pencil = field.querySelector('.ed-pencil');
                    const valueEl = field.querySelector('.ed-value');
                    let originalHTML = valueEl.innerHTML;

                    const startEdit = () => {
                        if (field.classList.contains('editing')) return;
                        originalHTML = valueEl.innerHTML;
                        const type = field.dataset.type;
                        const raw = field.dataset.raw;
                        let input;
                        if (type === 'select') {
                            input = document.createElement('select');
                            try {
                                const opts = JSON.parse(field.dataset.options || '[]');
                                const empty = document.createElement('option');
                                empty.value = ''; empty.textContent = '—';
                                input.appendChild(empty);
                                opts.forEach(o => {
                                    const op = document.createElement('option');
                                    op.value = o.v; op.textContent = o.l;
                                    if (String(o.v) === String(raw)) op.selected = true;
                                    input.appendChild(op);
                                });
                            } catch (e) {}
                        } else if (type === 'number') {
                            input = document.createElement('input');
                            input.type = 'number'; input.step = 'any';
                            input.value = raw;
                        } else if (type === 'date') {
                            input = document.createElement('input');
                            input.type = 'date';
                            input.value = raw;
                        } else {
                            input = document.createElement('input');
                            input.type = type || 'text';
                            input.value = raw;
                        }
                        valueEl.innerHTML = '';
                        valueEl.appendChild(input);
                        field.classList.add('editing');
                        input.focus();
                        if (input.select) input.select();

                        const cleanup = () => {
                            field.classList.remove('editing');
                            input.removeEventListener('blur', onBlur);
                            input.removeEventListener('keydown', onKey);
                        };
                        const cancel = () => {
                            valueEl.innerHTML = originalHTML;
                            cleanup();
                        };
                        const save = async () => {
                            const newValue = input.value;
                            if (newValue === raw) { cancel(); return; }
                            field.classList.add('saving');
                            try {
                                const fd = new FormData();
                                fd.append('csrf_token', csrf);
                                fd.append('action', 'inline_update');
                                fd.append('id', field.dataset.id);
                                fd.append('field', field.dataset.field);
                                fd.append('value', newValue);
                                const r = await fetch(window.location.href, { method: 'POST', body: fd, credentials: 'same-origin' });
                                const d = await r.json();
                                if (!d.success) throw new Error(d.error || 'Errore');
                                // Aggiorna display
                                let display = newValue || '—';
                                if (field.dataset.type === 'date' && newValue) {
                                    const [y,m,dd] = newValue.split('-');
                                    display = `${dd}/${m}/${y}`;
                                } else if (field.dataset.type === 'select') {
                                    const opt = input.options[input.selectedIndex];
                                    display = opt ? opt.textContent : '—';
                                } else if (field.dataset.type === 'number' && newValue) {
                                    display = '€ ' + parseFloat(newValue).toLocaleString('it-IT', {maximumFractionDigits:0});
                                } else if (field.dataset.type === 'email' && newValue) {
                                    display = `<a href="mailto:${newValue}">${newValue}</a>`;
                                } else if (field.dataset.type === 'tel' && newValue) {
                                    display = `<a href="tel:${newValue}">${newValue}</a>`;
                                }
                                valueEl.innerHTML = display;
                                field.dataset.raw = newValue;
                                field.classList.remove('saving');
                                field.classList.add('saved-flash');
                                setTimeout(() => field.classList.remove('saved-flash'), 700);
                                cleanup();
                            } catch (err) {
                                field.classList.remove('saving');
                                field.classList.add('error');
                                setTimeout(() => field.classList.remove('error'), 1200);
                                cancel();
                                alert('Errore salvataggio: ' + err.message);
                            }
                        };
                        const onKey = (e) => {
                            if (e.key === 'Enter' && type !== 'select') { e.preventDefault(); save(); }
                            else if (e.key === 'Escape') { e.preventDefault(); cancel(); }
                        };
                        const onBlur = () => save();
                        input.addEventListener('keydown', onKey);
                        input.addEventListener('blur', onBlur);
                        if (type === 'select') {
                            input.addEventListener('change', save);
                        }
                    };

                    pencil.addEventListener('click', startEdit);
                    valueEl.addEventListener('dblclick', startEdit);
                });
            })();
            </script>

            <!-- Modale upload documento dipendente -->
            <div id="ed-upload-modal" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.5);z-index:1000;align-items:center;justify-content:center;">
                <div style="background:#fff;padding:2rem;border-radius:10px;max-width:480px;width:90%;box-shadow:0 10px 40px rgba(0,0,0,.3);">
                    <h3 style="margin-top:0;">Carica documento</h3>
                    <form method="post" action="employee-documents.php" enctype="multipart/form-data">
                        <?= CSRF::field() ?>
                        <input type="hidden" name="action" value="upload">
                        <input type="hidden" name="employee_id" value="<?= (int) $employee['id'] ?>">
                        <div style="margin-bottom:1rem;">
                            <label style="display:block;font-weight:600;margin-bottom:.25rem;">Nome documento *</label>
                            <input type="text" name="name" required maxlength="255" class="form-control" placeholder="es. Contratto 2026" style="width:100%;">
                        </div>
                        <div style="margin-bottom:1rem;">
                            <label style="display:block;font-weight:600;margin-bottom:.25rem;">File *</label>
                            <input type="file" name="document" required class="form-control" style="width:100%;">
                        </div>
                        <div style="margin-bottom:1rem;">
                            <label style="display:block;font-weight:600;margin-bottom:.25rem;">Scadenza (opzionale)</label>
                            <input type="date" name="expires_on" class="form-control" style="width:100%;">
                        </div>
                        <div style="margin-bottom:1.25rem;">
                            <label><input type="checkbox" name="visible_to_employee" value="1"> Rendi visibile al dipendente (invia notifica)</label>
                        </div>
                        <div style="display:flex;gap:.5rem;justify-content:flex-end;">
                            <button type="button" class="btn btn-secondary" onclick="window.document.getElementById('ed-upload-modal').style.display='none';">Annulla</button>
                            <button type="submit" class="btn btn-primary">Carica</button>
                        </div>
                    </form>
                </div>
            </div>

        <style>
        /* ===== Employee profile — Option 2 (sticky sidebar + main) ===== */
        .emp-c-layout {
            display: grid;
            grid-template-columns: 300px 1fr;
            gap: 20px;
            align-items: start;
            margin-bottom: 24px;
        }
        .emp-side {
            background: white;
            border: 1px solid var(--border);
            border-radius: 14px;
            padding: 22px;
            position: sticky;
            top: calc(var(--header-h, 60px) + 16px);
        }
        .emp-side-photo {
            width: 200px; height: 200px;
            max-width: 100%;
            border-radius: 16px;
            background: linear-gradient(135deg, #4fa1ff, #0b3aa4);
            color: white;
            display: flex; align-items: center; justify-content: center;
            overflow: hidden;
            margin: 0 auto 16px;
        }
        .emp-side-photo img {
            width: 100%; height: 100%; object-fit: cover;
            image-rendering: -webkit-optimize-contrast;
            image-rendering: crisp-edges;
        }
        .emp-side-photo span {
            font-family: 'Space Grotesk', var(--font-sans);
            font-size: 72px; font-weight: 700;
            letter-spacing: -0.04em;
        }
        .emp-side-name {
            font-family: 'Host Grotesk', var(--font-sans);
            font-size: 19px; font-weight: 700;
            letter-spacing: -0.02em;
            margin: 0 0 3px;
        }
        .emp-side-role { font-size: 13px; color: var(--muted); margin-bottom: 10px; }
        .emp-side-tags {
            display: flex; flex-wrap: wrap; gap: 6px;
            margin-bottom: 14px;
        }
        .emp-side-tags .tag-pill {
            display: inline-flex; align-items: center;
            padding: 3px 10px; border-radius: 999px;
            font-size: 11px; font-weight: 600;
            background: rgba(11,58,164,0.08); color: var(--p-1);
        }
        .emp-side-tags .tag-green { background: #dcfce7; color: #0b3aa4; }
        .emp-side-tags .tag-red { background: #fee2e2; color: #b91c1c; }
        .emp-side-contacts {
            display: flex; flex-direction: column; gap: 8px;
            padding: 14px 0;
            border-top: 1px solid var(--border);
            border-bottom: 1px solid var(--border);
            margin-bottom: 14px;
        }
        .emp-side-c {
            display: flex; align-items: flex-start; gap: 8px;
            font-size: 12.5px; color: var(--ink-2);
            text-decoration: none;
            word-break: break-word;
            line-height: 1.4;
        }
        .emp-side-c svg { color: var(--muted); flex-shrink: 0; margin-top: 2px; }
        .emp-side-c:hover { color: var(--p-1); text-decoration: none; }
        .emp-side-c:hover svg { color: var(--p-1); }
        .emp-side-c span { min-width: 0; }
        .emp-side-acts {
            display: flex; flex-direction: column; gap: 8px;
        }
        .emp-side-acts .btn-c { width: 100%; justify-content: center; }
        .emp-side-acts form { margin: 0; }
        .emp-side-tip {
            font-size: 11px; color: var(--muted);
            margin-top: 14px; padding-top: 14px;
            border-top: 1px solid var(--border);
            line-height: 1.5;
        }

        .emp-main-col {
            display: flex; flex-direction: column; gap: 16px;
            min-width: 0;
        }
        .emp-kpi-strip {
            display: grid; grid-template-columns: repeat(3, 1fr); gap: 12px;
        }
        .emp-mkpi {
            background: white;
            border: 1px solid var(--border);
            border-radius: 12px;
            padding: 14px 16px;
        }
        .emp-mkpi .l {
            font-size: 11px; color: var(--muted);
            text-transform: uppercase; letter-spacing: 0.06em;
        }
        .emp-mkpi .v {
            font-family: 'Space Grotesk', var(--font-sans);
            font-size: 24px; font-weight: 700;
            margin-top: 4px;
            letter-spacing: -0.025em;
            line-height: 1.1;
        }
        .emp-mkpi.accent {
            background: linear-gradient(135deg, #03081f, #0b3aa4 140%);
            color: white; border-color: transparent;
        }
        .emp-mkpi.accent .l { color: rgba(255,255,255,0.7); }
        .emp-mkpi.accent .v { color: white; }

        @media (max-width: 1100px) {
            .emp-c-layout { grid-template-columns: 1fr; gap: 16px; }
            .emp-side { position: static; max-height: none; padding: 18px; }
            .emp-side-photo { width: 160px; height: 160px; }
            .emp-side-name { font-size: 17px; }
            .emp-side-photo span { font-size: 56px; }
            .emp-kpi-strip { grid-template-columns: repeat(3, 1fr); gap: 10px; }
        }
        @media (max-width: 700px) {
            .emp-kpi-strip { grid-template-columns: 1fr; }
            .emp-side-photo { width: 140px; height: 140px; }
            .emp-side { padding: 16px; }
            .emp-side-name { font-size: 16px; }
            .emp-side-contacts { padding: 10px 0; margin-bottom: 10px; }
            .emp-side-c { font-size: 12px; }
            .emp-c-card-b { padding: 14px; }
            .emp-c-card-h { padding: 12px 14px; }
            .emp-c-card-h h3 { font-size: 14px; }
            .emp-c-facts { gap: 14px 16px; }
            .emp-gauge { max-width: 200px; }
            .emp-gauge .g-big { font-size: 32px; }
            .emp-mkpi { padding: 12px 14px; }
            .emp-mkpi .v { font-size: 20px; }
        }
        @media (max-width: 480px) {
            .emp-gauge-stats { gap: 4px; }
            .emp-gauge-stats .v { font-size: 16px; }
            .emp-gauge-stats .l { font-size: 9px; }
        }

        /* ===== legacy emp-c CSS (kept for nested elements) ===== */
        .emp-c {
            background: white;
            border: 1px solid var(--border);
            border-radius: 16px;
            overflow: hidden;
            margin-bottom: 24px;
        }
        .emp-c-cover {
            height: 160px;
            background:
                radial-gradient(ellipse 70% 100% at 100% 100%, #4fa1ff 0%, #0b3aa4 38%, transparent 65%),
                linear-gradient(135deg, #03081f, #1437a8 80%);
            position: relative;
            overflow: hidden;
        }
        .emp-c-cover::before {
            content: ""; position: absolute; inset: 0;
            background-image:
                radial-gradient(1.5px 1.5px at 18% 28%, rgba(255,255,255,0.7), transparent 70%),
                radial-gradient(1px 1px at 50% 60%, rgba(255,255,255,0.55), transparent 70%),
                radial-gradient(1.2px 1.2px at 78% 30%, rgba(255,255,255,0.65), transparent 70%),
                radial-gradient(1px 1px at 30% 80%, rgba(255,255,255,0.5), transparent 70%);
        }
        .emp-c-body { padding: 0 28px 28px; }
        .emp-c-body-noscover { padding-top: 28px; }
        .emp-c-photo {
            width: 132px; height: 132px;
            border-radius: 16px;
            background: linear-gradient(135deg, #4fa1ff, #0b3aa4);
            color: white;
            display: flex; align-items: center; justify-content: center;
            font-family: 'Space Grotesk', var(--font-sans);
            font-size: 46px; font-weight: 700;
            letter-spacing: -0.04em;
            margin-top: 0;
            flex-shrink: 0;
            overflow: hidden;
        }
        .emp-c-photo img { width: 100%; height: 100%; object-fit: cover; }
        .emp-c-head {
            display: flex; align-items: center;
            gap: 20px;
            margin-bottom: 18px;
            padding-bottom: 18px;
            border-bottom: 1px solid var(--border);
        }
        .emp-c-titles { flex: 1; min-width: 0; }
        .emp-c-name {
            font-family: 'Host Grotesk', var(--font-sans);
            font-size: 28px; font-weight: 700;
            letter-spacing: -0.02em;
            margin: 0 0 4px;
            line-height: 1.15;
        }
        .emp-c-role {
            font-size: 14px; color: var(--ink-2);
            margin: 0 0 4px;
        }
        .emp-c-meta {
            font-size: 12px; color: var(--muted);
            display: flex; align-items: center; gap: 12px;
            flex-wrap: wrap;
        }
        .emp-c-meta .dot { display: inline-block; width: 4px; height: 4px; background: var(--muted); border-radius: 50%; }
        .emp-c-actions { display: flex; gap: 8px; padding-bottom: 6px; }
        .emp-c-actions form { margin: 0; }
        .btn-c, button.btn-c, a.btn-c {
            display: inline-flex !important;
            align-items: center; justify-content: center;
            gap: 6px;
            padding: 10px 16px;
            border-radius: 9px;
            font-size: 13px; font-weight: 600;
            line-height: 1.2;
            cursor: pointer; text-decoration: none;
            border: 1px solid var(--border);
            background: white;
            color: var(--ink-2);
            font-family: inherit;
            min-height: 40px;
            white-space: nowrap;
        }
        .btn-c:hover { border-color: #93c5fd; color: var(--p-1); text-decoration: none; }
        .btn-c.btn-c-primary, button.btn-c.btn-c-primary, a.btn-c.btn-c-primary {
            background: #0b3aa4 !important;
            border-color: #0b3aa4 !important;
            color: white !important;
        }
        .btn-c.btn-c-primary:hover { background: #0b3aa4 !important; border-color: #0b3aa4 !important; color: white !important; }
        .btn-c.btn-c-primary svg { color: white; }

        .emp-c-tip {
            font-size: 12px; color: var(--muted);
            background: #fafbfc;
            border: 1px solid var(--border);
            border-radius: 8px;
            padding: 8px 12px;
            margin-bottom: 18px;
            display: inline-flex; align-items: center; gap: 6px;
        }
        .emp-c-tip kbd {
            font-family: 'JetBrains Mono', monospace;
            font-size: 11px;
            background: white;
            border: 1px solid var(--border);
            padding: 1px 5px;
            border-radius: 3px;
        }

        .emp-c-row {
            display: grid; gap: 16px;
            margin-bottom: 16px;
        }
        .emp-c-row-21 { grid-template-columns: 2fr 1fr; }
        .emp-c-row-12 { grid-template-columns: 1fr 2fr; }
        .emp-c-row.cols-3 { grid-template-columns: 1fr 1fr 1fr; }
        .emp-c-facts-3 { grid-template-columns: repeat(3, 1fr) !important; }
        .emp-c-facts-4 { grid-template-columns: repeat(4, 1fr) !important; }
        @media (max-width: 900px) {
            .emp-c-facts-4 { grid-template-columns: repeat(2, 1fr) !important; }
            .emp-c-facts-3 { grid-template-columns: repeat(2, 1fr) !important; }
        }
        .emp-c-sections {
            display: grid;
            grid-template-columns: 2fr 1fr 1fr;
            gap: 16px;
            margin-bottom: 16px;
        }

        /* ===== Gauge ferie (half-circle) ===== */
        .emp-gauges {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 22px;
        }
        .emp-gauge-cell {
            border: 1px solid var(--border);
            border-radius: 14px;
            padding: 18px 20px 16px;
            text-align: center;
            background: linear-gradient(180deg, #fafbff, white);
        }
        .emp-gauge-cell h4 {
            font-family: 'Host Grotesk', var(--font-sans);
            font-size: 14px; font-weight: 600;
            margin: 0 0 12px;
            color: var(--ink);
        }
        .emp-gauge {
            position: relative;
            width: 100%; max-width: 240px;
            margin: 0 auto;
            aspect-ratio: 200 / 110;
        }
        .emp-gauge svg { width: 100%; height: 100%; display: block; }
        .emp-gauge .g-track {
            fill: none;
            stroke: #e0e7ff;
            stroke-width: 18;
            stroke-linecap: round;
        }
        .emp-gauge .g-arc {
            fill: none;
            stroke-width: 18;
            stroke-linecap: round;
            transition: stroke-dasharray .6s ease;
        }
        .emp-gauge .g-center {
            position: absolute;
            left: 0; right: 0;
            bottom: 4px;
            text-align: center;
        }
        .emp-gauge .g-big {
            font-family: 'Space Grotesk', var(--font-sans);
            font-size: 40px; font-weight: 700;
            letter-spacing: -0.04em;
            color: var(--ink);
            line-height: 1;
        }
        .emp-gauge .g-lbl {
            font-size: 11px; color: var(--muted);
            text-transform: uppercase;
            letter-spacing: 0.08em;
            margin-top: 4px;
            font-weight: 500;
        }
        .emp-gauge-ends {
            display: flex; justify-content: space-between;
            font-size: 11px; color: var(--muted);
            margin: -6px 18px 0;
            font-weight: 500;
        }
        .emp-gauge-stats {
            display: flex; justify-content: space-around;
            margin-top: 16px;
            padding-top: 14px;
            border-top: 1px solid var(--border);
        }
        .emp-gauge-stats .ss { text-align: center; }
        .emp-gauge-stats .l {
            font-size: 10px; color: var(--muted);
            text-transform: uppercase; letter-spacing: 0.08em;
            font-weight: 600;
        }
        .emp-gauge-stats .v {
            font-family: 'Space Grotesk', var(--font-sans);
            font-size: 20px; font-weight: 700;
            margin-top: 4px;
            letter-spacing: -0.02em;
        }
        .emp-gauge-empty {
            padding: 30px 12px;
            color: var(--muted); font-size: 13px;
        }
        @media (max-width: 700px) {
            .emp-gauges { grid-template-columns: 1fr; }
        }

        /* Donut ferie/permessi (legacy, non più usato) */
        .emp-c-donuts {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(280px, 1fr));
            gap: 28px;
        }
        .emp-c-donuts > .donut + .donut-meta {
            margin-left: -18px;
        }
        .emp-c-donuts {
            display: flex; flex-wrap: wrap; gap: 28px;
        }
        .emp-c-donuts > .donut, .emp-c-donuts > .donut-meta {
            display: inline-flex;
        }
        .emp-c-donuts {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 24px;
            align-items: center;
        }
        @media (max-width: 800px) {
            .emp-c-donuts { grid-template-columns: 1fr; }
        }
        .emp-c-donuts > * { box-sizing: border-box; }
        .donut {
            position: relative;
            width: 140px; height: 140px;
            flex-shrink: 0;
            margin: 0 auto;
        }
        .donut-svg { width: 100%; height: 100%; display: block; }
        .donut-center {
            position: absolute; inset: 0;
            display: flex; flex-direction: column;
            align-items: center; justify-content: center;
            pointer-events: none;
        }
        .donut-big {
            font-family: 'Space Grotesk', var(--font-sans);
            font-size: 36px; font-weight: 700;
            line-height: 1; color: var(--ink);
            letter-spacing: -0.03em;
        }
        .donut-unit {
            font-size: 11px; color: var(--muted);
            margin-top: 4px;
            text-transform: uppercase; letter-spacing: 0.06em;
            font-weight: 500;
        }
        .donut-meta { display: flex; flex-direction: column; justify-content: center; }
        .donut-meta h4 {
            margin: 0 0 12px;
            font-family: 'Host Grotesk', var(--font-sans);
            font-size: 15px; font-weight: 600;
            color: var(--ink);
        }
        .donut-meta-row {
            display: flex; align-items: center; gap: 8px;
            font-size: 13px;
            color: var(--ink-2);
            padding: 4px 0;
        }
        .donut-meta-row .dot {
            width: 10px; height: 10px; border-radius: 3px;
            flex-shrink: 0;
        }
        .donut-meta-row .ml { color: var(--muted); flex: 1; }
        .donut-meta-row .mv { font-weight: 600; color: var(--ink); font-variant-numeric: tabular-nums; }

        /* Upload inline */
        .ed-upload-inline {
            background: linear-gradient(135deg, rgba(11,58,164,0.04), rgba(79,161,255,0.05));
            border: 1px dashed #93c5fd;
            border-radius: 10px;
            padding: 12px;
            margin-bottom: 4px;
        }
        .ed-upload-row {
            display: grid;
            grid-template-columns: minmax(140px, 1fr) minmax(200px, 2fr) auto;
            grid-template-areas: "file name submit";
            gap: 8px;
            align-items: stretch;
        }
        .ed-upload-row .ed-upload-file { grid-area: file; }
        .ed-upload-row .ed-upload-name { grid-area: name; }
        .ed-upload-row > button[type=submit] { grid-area: submit; white-space: nowrap; }
        .ed-upload-row .ed-upload-vis {
            grid-column: 1 / -1;
            border-top: 1px dashed rgba(11,58,164,0.2);
            margin-top: 4px;
            padding: 8px 4px 0;
        }
        @media (max-width: 900px) {
            .ed-upload-row {
                grid-template-columns: 1fr;
                grid-template-areas:
                    "file"
                    "name"
                    "submit";
            }
            .ed-upload-row > button[type=submit] { padding: 12px; font-size: 14px; }
        }
        .ed-upload-file {
            display: inline-flex; align-items: center; gap: 8px;
            background: white;
            border: 1px solid var(--border);
            border-radius: 8px;
            padding: 8px 12px;
            cursor: pointer;
            font-size: 13px; font-weight: 500;
            color: var(--ink-2);
            transition: all .12s ease;
            overflow: hidden;
        }
        .ed-upload-file:hover { border-color: #93c5fd; color: var(--p-1); }
        .ed-upload-file .ed-upload-text {
            white-space: nowrap; overflow: hidden; text-overflow: ellipsis;
            flex: 1; min-width: 0;
        }
        .ed-upload-file input[type=file] { display: none; }
        .ed-upload-name, .ed-upload-date {
            background: white;
            border: 1px solid var(--border);
            border-radius: 8px;
            padding: 8px 12px;
            font-size: 13px;
            font-family: inherit;
            color: var(--ink);
            outline: none;
        }
        .ed-upload-name:focus, .ed-upload-date:focus {
            border-color: var(--p-1);
            box-shadow: 0 0 0 3px rgba(11,58,164,0.12);
        }
        .ed-upload-vis {
            display: inline-flex; align-items: center; gap: 6px;
            padding: 0 10px;
            font-size: 12px; color: var(--ink-2);
            cursor: pointer;
            user-select: none;
        }
        @media (max-width: 900px) {
            .ed-upload-row { grid-template-columns: 1fr; }
        }

        /* Docs-section dentro emp-c-card-b: rimuovo box duplicato */
        .emp-c-card-b .docs-section {
            background: transparent;
            box-shadow: none;
            border-radius: 0;
        }

        /* Tag stato */
        .emp-c-facts .tag {
            display: inline-flex; align-items: center;
            padding: 3px 10px; border-radius: 999px;
            font-size: 12px; font-weight: 600;
        }
        .emp-c-facts .tag-green { background: rgba(34,197,94,0.12); color: #0b3aa4; }
        .emp-c-facts .tag-red   { background: rgba(247,92,108,0.10); color: #b91c1c; }

        /* "Donut + meta" come una cella container */
        .emp-c-donuts .donut-cell {
            display: flex; align-items: center; gap: 20px;
            padding: 12px;
            border: 1px solid var(--border);
            border-radius: 12px;
            background: #fafbfc;
        }
        @media (max-width: 600px) {
            .donut { width: 110px; height: 110px; }
            .donut-big { font-size: 30px; }
        }
        @media (max-width: 1100px) {
            .emp-c-row-21, .emp-c-row-12 { grid-template-columns: 1fr; }
            .emp-c-facts-3 { grid-template-columns: 1fr 1fr !important; }
        }
        .emp-c-card {
            border: 1px solid var(--border);
            border-radius: 12px;
            overflow: hidden;
            background: white;
        }
        .emp-c-card.span-2 { grid-column: span 2; }
        .emp-c-card-h {
            padding: 14px 16px;
            border-bottom: 1px solid var(--border);
            display: flex; align-items: center; justify-content: space-between;
        }
        .emp-c-card-h h3 {
            margin: 0;
            font-family: 'Host Grotesk', var(--font-sans);
            font-size: 14px; font-weight: 600;
        }
        .emp-c-card-h .hint {
            font-size: 11px; color: var(--muted);
            text-transform: uppercase; letter-spacing: 0.06em;
        }
        .emp-c-card-b { padding: 20px; }
        .emp-c-facts {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 18px 24px;
        }
        .emp-c-facts .fact { min-width: 0; }
        .emp-c-facts .fact .l {
            font-size: 11px; color: var(--muted);
            text-transform: uppercase; letter-spacing: 0.06em;
            margin-bottom: 5px;
            font-weight: 600;
        }
        .emp-c-facts .fact .v {
            font-size: 14px; color: var(--ink); font-weight: 500;
            line-height: 1.35;
        }
        .emp-c-facts .fact .v code {
            background: #f1f5f9; padding: 2px 7px; border-radius: 4px;
            font-family: 'JetBrains Mono', monospace; font-size: 12px;
        }
        .emp-c-facts .fact .v a { color: var(--p-1); text-decoration: none; }
        .emp-c-facts .fact .v a:hover { text-decoration: underline; }

        .emp-c-stats {
            display: grid;
            grid-template-columns: 1fr;
            gap: 10px;
            grid-column: span 1;
            grid-row: 1;
            align-content: start;
        }
        .emp-c-stat {
            background: #fafbfc;
            border: 1px solid var(--border);
            border-radius: 10px;
            padding: 12px 14px;
            display: flex; align-items: center; justify-content: space-between;
            gap: 12px;
        }
        .emp-c-stat .info { min-width: 0; }
        .emp-c-stat .info .l {
            font-size: 10px; color: var(--muted);
            text-transform: uppercase; letter-spacing: 0.06em;
        }
        .emp-c-stat .info .v {
            font-family: 'Space Grotesk', var(--font-sans);
            font-size: 18px; font-weight: 700;
            margin-top: 2px;
            line-height: 1.1;
        }
        .emp-c-stat .icon {
            width: 32px; height: 32px;
            border-radius: 8px;
            background: rgba(11,58,164,0.08);
            color: var(--p-1);
            display: inline-flex; align-items: center; justify-content: center;
            flex-shrink: 0;
        }
        .emp-c-extras {
            margin-top: 16px;
        }

        /* === Inline editable field === */
        .ed-field {
            display: inline-flex; align-items: center; gap: 6px;
            padding: 2px 6px;
            border-radius: 5px;
            position: relative;
        }
        .ed-field:hover {
            background: #f1f5f9;
        }
        .ed-field:hover .ed-pencil { opacity: 1; }
        .ed-pencil {
            opacity: 0;
            background: none; border: none;
            color: var(--muted);
            cursor: pointer;
            padding: 2px;
            display: inline-flex; align-items: center;
            border-radius: 4px;
            transition: opacity .15s ease, background .15s ease;
        }
        .ed-pencil:hover { background: rgba(11,58,164,0.1); color: var(--p-1); }
        .ed-field.editing { background: #fff; box-shadow: 0 0 0 2px var(--p-1); padding: 4px 6px; }
        .ed-field.editing .ed-pencil { display: none; }
        .ed-field input, .ed-field select {
            border: 0; outline: 0;
            font: inherit; color: inherit;
            background: transparent;
            min-width: 80px;
            width: 100%;
        }
        .ed-field.saving { opacity: 0.6; pointer-events: none; }
        .ed-field.error { box-shadow: 0 0 0 2px var(--danger-500, #ef4444); }
        .ed-field.saved-flash { background: rgba(34,197,94,0.12); }

        @media (max-width: 1100px) {
            .emp-c-sections { grid-template-columns: 1fr 1fr; }
            .emp-c-stats { grid-column: span 2; grid-row: auto; grid-template-columns: repeat(2, 1fr); }
        }
        @media (max-width: 700px) {
            .emp-c-sections, .emp-c-stats { grid-template-columns: 1fr; }
            .emp-c-stats { grid-column: span 1; }
            .emp-c-head { flex-wrap: wrap; }
            .emp-c-photo { width: 110px; height: 110px; font-size: 38px; margin-top: -60px; }
            .emp-c-name { font-size: 22px; }
        }

        /* ===== Old layout CSS (kept harmless, was Option 1) ===== */
        .emp-profile { display: flex; flex-direction: column; gap: 20px; margin-bottom: 24px; }

        /* KPI strip */
        .emp-kpis {
            display: grid; grid-template-columns: repeat(4, 1fr); gap: 16px;
        }
        .emp-kpis .kpi {
            background: white; border: 1px solid var(--border);
            border-radius: 14px; padding: 18px 20px;
            position: relative; overflow: hidden;
        }
        .emp-kpis .kpi-l {
            font-size: 12px; font-weight: 600;
            color: var(--muted);
            text-transform: uppercase; letter-spacing: 0.08em;
        }
        .emp-kpis .kpi-v {
            font-family: 'Space Grotesk', var(--font-sans);
            font-size: 28px; font-weight: 700;
            color: var(--ink);
            line-height: 1.1; margin-top: 6px; letter-spacing: -0.025em;
        }
        .emp-kpis .kpi-s { font-size: 11px; color: var(--muted); margin-top: 4px; }
        .emp-kpis .kpi.kpi-hero {
            background: linear-gradient(135deg, #03081f, #0b3aa4 130%);
            color: white; border-color: transparent;
        }
        .emp-kpis .kpi.kpi-hero .kpi-l,
        .emp-kpis .kpi.kpi-hero .kpi-v { color: white; }
        .emp-kpis .kpi.kpi-hero .kpi-s { color: rgba(255,255,255,0.75); }

        /* Main grid */
        .emp-main {
            display: grid;
            grid-template-columns: 320px 1fr;
            gap: 20px;
            align-items: start;
        }

        /* Photo card */
        .emp-photo-card {
            background: #03081f;
            border-radius: 14px;
            padding: 24px;
            color: white;
            text-align: center;
            position: relative; overflow: hidden; isolation: isolate;
        }
        .emp-photo-card::before {
            content: ""; position: absolute; inset: 0; z-index: 0;
            background-image:
                radial-gradient(1.2px 1.2px at 12% 22%, rgba(255,255,255,0.7), transparent 70%),
                radial-gradient(1px 1px at 80% 14%, rgba(255,255,255,0.55), transparent 70%),
                radial-gradient(1.2px 1.2px at 40% 78%, rgba(129,200,255,0.6), transparent 70%),
                radial-gradient(1px 1px at 92% 60%, rgba(255,255,255,0.45), transparent 70%),
                radial-gradient(1.5px 1.5px at 24% 90%, rgba(255,255,255,0.5), transparent 70%);
            opacity: 0.7;
            pointer-events: none;
        }
        .emp-photo-card > * { position: relative; z-index: 1; }
        .emp-photo {
            width: 100%; aspect-ratio: 4/5;
            border-radius: 14px;
            background: rgba(255,255,255,0.1);
            border: 2px solid rgba(255,255,255,0.18);
            display: flex; align-items: center; justify-content: center;
            overflow: hidden;
            margin-bottom: 16px;
        }
        .emp-photo img { width: 100%; height: 100%; object-fit: cover; }
        .emp-initials {
            font-family: 'Space Grotesk', var(--font-sans);
            font-size: 64px; font-weight: 700;
            color: white;
        }
        .emp-name {
            font-family: 'Space Grotesk', var(--font-sans);
            font-size: 20px; font-weight: 700;
            color: white;
            margin: 0 0 4px;
            letter-spacing: -0.02em;
            line-height: 1.15;
        }
        .emp-role {
            font-size: 13px; color: rgba(255,255,255,0.75);
        }
        .emp-quick {
            display: flex; gap: 8px; justify-content: center; margin: 16px 0 14px;
        }
        .emp-quick a {
            width: 36px; height: 36px;
            border-radius: 9px;
            background: rgba(255,255,255,0.14);
            color: white;
            display: inline-flex; align-items: center; justify-content: center;
            text-decoration: none;
            transition: background .12s ease;
        }
        .emp-quick a:hover { background: rgba(255,255,255,0.25); }
        .emp-quick-actions {
            display: flex; flex-direction: column; gap: 8px;
            padding-top: 14px; border-top: 1px solid rgba(255,255,255,0.12);
        }
        .emp-quick-actions form { margin: 0; }
        .btn-quick {
            display: inline-flex; align-items: center; gap: 6px;
            justify-content: center;
            width: 100%;
            padding: 10px 12px;
            background: rgba(255,255,255,0.12);
            border: 1px solid rgba(255,255,255,0.2);
            color: white;
            border-radius: 9px;
            font-size: 13px; font-weight: 600;
            cursor: pointer;
            text-decoration: none;
            font-family: inherit;
            transition: background .12s ease;
        }
        .btn-quick:hover { background: rgba(255,255,255,0.22); text-decoration: none; color: white; }
        .btn-quick-primary {
            background: white; color: #0b3aa4; border-color: white;
        }
        .btn-quick-primary:hover { background: #e0e7ff; color: #0b3aa4; }

        /* Content area */
        .emp-content {
            background: white;
            border: 1px solid var(--border);
            border-radius: 14px;
            overflow: hidden;
        }
        .emp-tabs {
            display: flex; gap: 2px;
            padding: 0 16px;
            border-bottom: 1px solid var(--border);
            background: #fafbfc;
        }
        .emp-tab {
            padding: 14px 16px;
            font-size: 13px; font-weight: 600;
            color: var(--muted);
            text-decoration: none;
            border-bottom: 2px solid transparent;
            margin-bottom: -1px;
            transition: color .12s ease, border-color .12s ease;
        }
        .emp-tab:hover { color: var(--ink); text-decoration: none; }
        .emp-tab.active {
            color: #0b3aa4;
            border-bottom-color: #0b3aa4;
        }
        .emp-tab .tab-count {
            display: inline-block;
            background: rgba(11,58,164,0.10);
            color: #0b3aa4;
            padding: 1px 7px; border-radius: 999px;
            font-size: 10px; font-weight: 700;
            margin-left: 4px;
        }
        .emp-section { padding: 22px; display: none; }
        .emp-section.active { display: block; }
        .emp-section-h {
            font-family: 'Host Grotesk', var(--font-sans);
            font-size: 16px; font-weight: 600;
            color: var(--ink);
            margin: 0 0 16px;
            display: flex; align-items: center;
        }
        .emp-info-grid {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 14px 24px;
        }
        .emp-info-grid .info-item .l {
            font-size: 11px; color: var(--muted);
            text-transform: uppercase; letter-spacing: 0.06em;
            display: block; margin-bottom: 4px;
        }
        .emp-info-grid .info-item .v {
            font-size: 14px; color: var(--ink); font-weight: 500;
        }
        .emp-info-grid .info-item .v code {
            font-family: 'JetBrains Mono', monospace;
            font-size: 12px;
            background: #f1f5f9; padding: 2px 7px; border-radius: 4px;
        }
        .emp-info-grid .info-item .v a { color: #0b3aa4; text-decoration: none; }
        .emp-info-grid .info-item .v a:hover { text-decoration: underline; }

        @media (max-width: 1100px) {
            .emp-kpis { grid-template-columns: repeat(2, 1fr); }
            .emp-main { grid-template-columns: 1fr; }
        }
        @media (max-width: 600px) {
            .emp-kpis { grid-template-columns: 1fr; }
            .emp-info-grid { grid-template-columns: 1fr; }
        }

        /* ===== Legacy classes (kept for documents sections) ===== */
        .employee-view-layout {
            display: flex;
            flex-direction: column;
            gap: 1rem;
        }

        /* Top Section - Profile + Stats + Actions inline */
        .employee-top {
            background: white;
            border-radius: 10px;
            padding: 1rem;
            box-shadow: 0 2px 8px rgba(0,0,0,0.06);
            display: flex;
            align-items: center;
            gap: 1rem;
            flex-wrap: wrap;
        }

        .profile-compact {
            display: flex;
            align-items: center;
            gap: 0.75rem;
            flex: 1;
            min-width: 200px;
        }

        .profile-avatar-sm {
            width: 50px;
            height: 50px;
            background: linear-gradient(135deg, #3182ce, #2c5282);
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1rem;
            font-weight: 700;
            color: white;
            flex-shrink: 0;
        }

        .profile-info h2 {
            font-size: 1rem;
            margin: 0 0 0.15rem;
            color: #1a202c;
        }

        .profile-info .meta {
            font-size: 0.75rem;
            color: #718096;
        }

        .profile-info .badge {
            font-size: 0.6rem;
            padding: 0.15rem 0.4rem;
            margin-left: 0.5rem;
        }

        /* Stats inline */
        .stats-inline {
            display: flex;
            gap: 1rem;
            padding: 0 1rem;
            border-left: 1px solid #e2e8f0;
        }

        .stat-item {
            text-align: center;
        }

        .stat-item .num {
            font-size: 1.1rem;
            font-weight: 700;
            color: #3182ce;
            display: block;
        }

        .stat-item .lbl {
            font-size: 0.6rem;
            color: #718096;
            text-transform: uppercase;
        }

        /* Actions inline */
        .actions-inline {
            display: flex;
            gap: 0.35rem;
            margin-left: auto;
        }

        .actions-inline .btn {
            padding: 0.4rem 0.75rem;
            font-size: 0.75rem;
            display: flex;
            align-items: center;
            gap: 0.3rem;
        }

        .actions-inline .btn svg {
            width: 14px;
            height: 14px;
        }

        .actions-inline form { margin: 0; }

        /* Info Grid */
        .info-grid {
            background: white;
            border-radius: 10px;
            padding: 0.75rem 1rem;
            box-shadow: 0 2px 8px rgba(0,0,0,0.06);
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
            gap: 0.5rem 1.5rem;
        }

        .info-cell {
            display: flex;
            align-items: center;
            gap: 0.5rem;
            padding: 0.35rem 0;
        }

        .info-cell svg {
            width: 14px;
            height: 14px;
            color: #a0aec0;
            flex-shrink: 0;
        }

        .info-cell .label {
            font-size: 0.65rem;
            color: #a0aec0;
            text-transform: uppercase;
            min-width: 70px;
        }

        .info-cell .value {
            font-size: 0.8rem;
            color: #2d3748;
        }

        .info-cell code {
            font-size: 0.75rem;
            background: #edf2f7;
            padding: 0.1rem 0.3rem;
            border-radius: 3px;
        }

        .info-cell a {
            color: #3182ce;
            text-decoration: none;
            font-size: 0.8rem;
        }

        .text-muted { color: #a0aec0 !important; font-style: italic; }

        /* Documents Section */
        .docs-section {
            background: white;
            border-radius: 10px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.06);
        }

        .docs-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 0.75rem 1rem;
            border-bottom: 1px solid #edf2f7;
            gap: 0.75rem;
            flex-wrap: wrap;
        }

        .docs-header h3 {
            font-size: 0.9rem;
            margin: 0;
            color: #2d3748;
            display: flex;
            align-items: center;
            gap: 0.4rem;
        }

        .docs-header h3 svg {
            width: 16px;
            height: 16px;
            color: #718096;
        }

        .doc-filters {
            display: flex;
            gap: 0.25rem;
        }

        /* Segmented control filter pills */
        .doc-filters {
            display: inline-flex;
            background: #f1f5f9;
            border-radius: 999px;
            padding: 4px;
            gap: 2px;
        }
        .filter-btn {
            font-family: inherit;
            background: transparent;
            border: 0;
            padding: 7px 16px;
            border-radius: 999px;
            font-size: 12.5px;
            font-weight: 600;
            color: var(--muted);
            cursor: pointer;
            transition: all .15s ease;
            white-space: nowrap;
        }
        .filter-btn-redef-stub {
            padding: 0.3rem 0.6rem;
            border: 1px solid #e2e8f0;
            background: white;
            border-radius: 4px;
            font-size: 0.7rem;
            cursor: pointer;
            transition: all 0.2s;
        }

        .filter-btn:hover { color: var(--ink); background: rgba(255,255,255,0.6); }
        .filter-btn.active {
            background: white;
            color: #0b3aa4;
            box-shadow: 0 1px 3px rgba(15,23,42,0.08);
        }

        /* Docs List */
        .docs-list {
            max-height: 400px;
            overflow-y: auto;
        }

        .year-group {
            border-bottom: 1px solid #edf2f7;
        }

        .year-group:last-child { border-bottom: none; }

        .year-title {
            font-size: 0.7rem;
            color: #718096;
            padding: 0.5rem 1rem;
            background: #f7fafc;
            margin: 0;
            font-weight: 600;
        }

        .doc-row {
            display: flex;
            align-items: center;
            gap: 0.6rem;
            padding: 0.5rem 1rem;
            border-bottom: 1px solid #f7fafc;
            transition: background 0.2s;
        }

        .doc-row:last-child { border-bottom: none; }
        .doc-row:hover { background: #f7fafc; }

        .doc-type {
            width: 28px;
            height: 28px;
            border-radius: 6px;
            display: flex;
            align-items: center;
            justify-content: center;
            flex-shrink: 0;
        }

        .doc-type svg {
            width: 14px;
            height: 14px;
        }

        .doc-type.payslip { background: #c6f6d5; color: #276749; }
        .doc-type.cud { background: #fefcbf; color: #975a16; }
        .doc-type.other { background: #e2e8f0; color: #4a5568; }

        .doc-info {
            flex: 1;
            min-width: 0;
        }

        .doc-info .name {
            font-size: 0.8rem;
            color: #2d3748;
            display: block;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }

        .doc-info .date {
            font-size: 0.65rem;
            color: #a0aec0;
        }

        .doc-dl {
            width: 28px;
            height: 28px;
            display: flex;
            align-items: center;
            justify-content: center;
            background: #edf2f7;
            border-radius: 4px;
            color: #3182ce;
            text-decoration: none;
            flex-shrink: 0;
        }

        .doc-dl:hover { background: #3182ce; color: white; }

        .doc-dl svg {
            width: 14px;
            height: 14px;
        }

        /* Empty */
        .empty-msg {
            padding: 2rem;
            text-align: center;
            color: #a0aec0;
            font-size: 0.85rem;
        }

        /* Responsive */
        @media (max-width: 768px) {
            .employee-top {
                flex-direction: column;
                align-items: stretch;
            }

            .profile-compact {
                min-width: auto;
            }

            .stats-inline {
                border-left: none;
                border-top: 1px solid #e2e8f0;
                padding: 0.75rem 0 0;
                justify-content: space-around;
            }

            .actions-inline {
                margin-left: 0;
                justify-content: stretch;
            }

            .actions-inline .btn {
                flex: 1;
                justify-content: center;
            }

            .info-grid {
                grid-template-columns: 1fr 1fr;
            }

            .doc-filters {
                width: 100%;
                justify-content: flex-start;
                overflow-x: auto;
            }
        }

        @media (max-width: 480px) {
            .info-grid {
                grid-template-columns: 1fr;
            }

            .actions-inline {
                flex-wrap: wrap;
            }

            .actions-inline .btn {
                flex: 1 1 calc(50% - 0.2rem);
                font-size: 0.7rem;
                padding: 0.35rem 0.5rem;
            }
        }
        </style>

        <script>
        document.querySelectorAll('.filter-btn').forEach(btn => {
            btn.addEventListener('click', function() {
                document.querySelectorAll('.filter-btn').forEach(b => b.classList.remove('active'));
                this.classList.add('active');
                const filter = this.dataset.filter;
                document.querySelectorAll('.doc-row').forEach(item => {
                    item.style.display = (filter === 'all' || item.dataset.type === filter) ? 'flex' : 'none';
                });
            });
        });
        </script>
    <?php endif; ?>
</div>

<?php include dirname(__DIR__) . '/includes/footer-admin.php'; ?>
