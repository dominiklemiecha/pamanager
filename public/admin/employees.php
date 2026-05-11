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
        case 'create':
            $result = Employee::create([
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
            ]);

            // Salva configurazione visibilita campi anche dal form di creazione
            if (isset($_POST['visibility']) && is_array($_POST['visibility'])) {
                $visCfg = [];
                foreach (array_keys(FieldVisibility::FIELDS) as $f) {
                    $visCfg[$f] = $_POST['visibility'][$f] ?? [];
                }
                try { FieldVisibility::saveConfig($visCfg, (int)Auth::getUser()['id']); } catch (Throwable $e) {}
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

                $result = Employee::update($id, $updateData);

                if ($result['success']) {
                    header('Location: employees.php?message=updated');
                    exit;
                }
                $error = $result['error'];
                $action = 'edit';
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
            'deleted' => 'Dipendente eliminato con successo'
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
/* Filters */
.filters-bar {
    display: flex;
    align-items: center;
    gap: 0.75rem;
    margin-bottom: 1.25rem;
    flex-wrap: wrap;
    background: white;
    padding: 0.75rem;
    border-radius: 10px;
    box-shadow: 0 2px 8px rgba(0,0,0,0.06);
}

.filters-bar .search-input {
    flex: 1;
    min-width: 180px;
    display: flex;
    gap: 0.4rem;
}

.filters-bar .search-input input {
    flex: 1;
    padding: 0.4rem 0.75rem;
    border: 1px solid #e2e8f0;
    border-radius: 6px;
    font-size: 0.8rem;
}

.filters-bar .search-input button {
    padding: 0.4rem 0.75rem;
    font-size: 0.75rem;
}

.filters-bar .checkbox-wrap {
    display: flex;
    align-items: center;
    gap: 0.4rem;
    font-size: 0.75rem;
    color: #4a5568;
}

.filters-bar .checkbox-wrap input {
    width: 14px;
    height: 14px;
}

/* Page Header */
.page-top {
    display: flex;
    align-items: center;
    justify-content: space-between;
    margin-bottom: 1.25rem;
    flex-wrap: wrap;
    gap: 0.75rem;
}

.page-top h2 {
    margin: 0;
    font-size: 1.1rem;
    color: #1a202c;
}

@media (max-width: 768px) {
    .filters-bar {
        flex-direction: column;
        align-items: stretch;
    }

    .filters-bar .search-input {
        width: 100%;
    }
}
</style>

<div class="admin-page">
    <div class="page-top">
        <?php if ($action === 'list'): ?>
            <a href="?action=new" class="btn btn-primary">+ Nuovo Dipendente</a>
        <?php else: ?>
            <a href="employees.php" class="btn btn-secondary">Torna alla Lista</a>
        <?php endif; ?>
    </div>

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
        <div class="filters-bar">
            <form method="GET" class="search-input">
                <input type="text" name="search" value="<?php echo htmlspecialchars($search); ?>" placeholder="Cerca per nome, cognome, CF o username...">
                <button type="submit" class="btn btn-primary">Cerca</button>
                <?php if ($search): ?>
                    <a href="employees.php" class="btn btn-secondary">Reset</a>
                <?php endif; ?>
            </form>
            <label class="checkbox-wrap">
                <input type="checkbox" name="show_inactive" <?php echo $showInactive ? 'checked' : ''; ?>
                       onchange="document.getElementById('filter-form').submit()">
                Mostra disattivati
            </label>
            <form id="filter-form" method="GET" style="display:none;">
                <input type="hidden" name="search" value="<?php echo htmlspecialchars($search); ?>">
                <?php if (!$showInactive): ?>
                    <input type="hidden" name="show_inactive" value="1">
                <?php endif; ?>
            </form>
        </div>

        <?php if (empty($employees)): ?>
            <div class="empty-box" style="background:white;padding:3rem;border-radius:12px;text-align:center;">
                <svg xmlns="http://www.w3.org/2000/svg" width="48" height="48" viewBox="0 0 24 24" fill="#cbd5e0"><path d="M12 12c2.21 0 4-1.79 4-4s-1.79-4-4-4-4 1.79-4 4 1.79 4 4 4zm0 2c-2.67 0-8 1.34-8 4v2h16v-2c0-2.66-5.33-4-8-4z"/></svg>
                <p style="color:#718096;margin:1rem 0 0;">Nessun dipendente trovato</p>
            </div>
        <?php else: ?>
            <div class="table-scroll">
                <table class="data-table responsive">
                    <thead>
                        <tr>
                            <th>Dipendente</th>
                            <th>Username</th>
                            <th>Codice Fiscale</th>
                            <th>Email</th>
                            <th>Reparto</th>
                            <th>Stato</th>
                            <th>Azioni</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($employees as $emp): ?>
                            <tr class="<?php echo !$emp['is_active'] ? 'inactive' : ''; ?>">
                                <td data-label="Dipendente">
                                    <a href="?action=view&id=<?php echo $emp['id']; ?>">
                                        <?php echo htmlspecialchars($emp['last_name'] . ' ' . $emp['first_name']); ?>
                                    </a>
                                </td>
                                <td data-label="Username"><code><?php echo htmlspecialchars($emp['username']); ?></code></td>
                                <td data-label="CF"><code><?php echo htmlspecialchars($emp['fiscal_code']); ?></code></td>
                                <td data-label="Email"><?php echo htmlspecialchars($emp['email'] ?? '-'); ?></td>
                                <td data-label="Reparto"><?php echo htmlspecialchars($emp['department_name'] ?? '-'); ?></td>
                                <td data-label="Stato">
                                    <span class="badge <?php echo $emp['is_active'] ? 'badge-success' : 'badge-danger'; ?>">
                                        <?php echo $emp['is_active'] ? 'Attivo' : 'Off'; ?>
                                    </span>
                                </td>
                                <td data-label="Azioni" class="actions">
                                    <a href="?action=view&id=<?php echo $emp['id']; ?>" class="btn btn-sm btn-info" title="Visualizza">Vedi</a>
                                    <a href="?action=edit&id=<?php echo $emp['id']; ?>" class="btn btn-sm btn-secondary" title="Modifica">Modifica</a>
                                    <form method="POST" class="inline-form" onsubmit="return confirm('Confermi?')">
                                        <?php echo CSRF::field(); ?>
                                        <input type="hidden" name="action" value="toggle_active">
                                        <input type="hidden" name="id" value="<?php echo $emp['id']; ?>">
                                        <button type="submit" class="btn btn-sm btn-<?php echo $emp['is_active'] ? 'warning' : 'success'; ?>">
                                            <?php echo $emp['is_active'] ? 'Off' : 'On'; ?>
                                        </button>
                                    </form>
                                    <form method="POST" class="inline-form" onsubmit="return confirm('Eliminare definitivamente <?php echo e($emp['username']); ?>? I documenti associati verranno rimossi. Operazione IRREVERSIBILE.')">
                                        <?php echo CSRF::field(); ?>
                                        <input type="hidden" name="action" value="delete">
                                        <input type="hidden" name="id" value="<?php echo $emp['id']; ?>">
                                        <button type="submit" class="btn btn-sm btn-danger">Elimina</button>
                                    </form>
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

                <?php if ($action === 'edit'): ?>
                <div class="form-group" style="margin-top: 1.5rem; border-top: 1px solid #e2e8f0; padding-top: 1rem;">
                    <label class="checkbox-label">
                        <input type="checkbox" name="is_active" <?php echo $employee['is_active'] ? 'checked' : ''; ?>>
                        Dipendente attivo
                    </label>
                </div>
                <?php endif; ?>
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
        <!-- Dettaglio Dipendente con Layout Sidebar -->
        <?php
        // Calcola statistiche documenti
        $docStats = [
            'payslip' => 0,
            'cud' => 0,
            'other' => 0,
            'total' => count($documents ?? [])
        ];
        foreach ($documents ?? [] as $doc) {
            if (isset($docStats[$doc['type']])) {
                $docStats[$doc['type']]++;
            }
        }

        // Raggruppa documenti per anno
        $documentsByYear = [];
        foreach ($documents ?? [] as $doc) {
            $year = $doc['year'];
            if (!isset($documentsByYear[$year])) {
                $documentsByYear[$year] = [];
            }
            $documentsByYear[$year][] = $doc;
        }
        krsort($documentsByYear);
        ?>

        <div class="employee-view-layout">
            <!-- Top: Profile + Stats + Actions -->
            <div class="employee-top">
                <div class="profile-compact">
                    <?php echo employeeAvatarHtml($employee, 'profile-avatar-sm'); ?>
                    <div class="profile-info">
                        <h2><?php echo htmlspecialchars($employee['first_name'] . ' ' . $employee['last_name']); ?>
                            <span class="badge <?php echo $employee['is_active'] ? 'badge-success' : 'badge-danger'; ?>"><?php echo $employee['is_active'] ? 'Attivo' : 'Off'; ?></span>
                        </h2>
                        <span class="meta"><?php echo htmlspecialchars($employee['position'] ?? ''); ?><?php if ($employee['position'] && $employee['department_name']): ?> · <?php endif; ?><?php echo htmlspecialchars($employee['department_name'] ?? ''); ?></span>
                    </div>
                </div>
                <div class="stats-inline">
                    <div class="stat-item"><span class="num"><?php echo $docStats['total']; ?></span><span class="lbl">Doc</span></div>
                    <div class="stat-item"><span class="num"><?php echo $docStats['payslip']; ?></span><span class="lbl">Buste</span></div>
                    <div class="stat-item"><span class="num"><?php echo $docStats['cud']; ?></span><span class="lbl">CUD</span></div>
                </div>
                <div class="actions-inline">
                    <a href="?action=edit&id=<?php echo $employee['id']; ?>" class="btn btn-primary"><svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor"><path d="M3 17.25V21h3.75L17.81 9.94l-3.75-3.75L3 17.25zM20.71 7.04c.39-.39.39-1.02 0-1.41l-2.34-2.34c-.39-.39-1.02-.39-1.41 0l-1.83 1.83 3.75 3.75 1.83-1.83z"/></svg>Modifica</a>
                    <form method="POST" onsubmit="return confirm('Reset password?')"><?php echo CSRF::field(); ?><input type="hidden" name="action" value="reset_password"><input type="hidden" name="id" value="<?php echo $employee['id']; ?>"><button type="submit" class="btn btn-warning"><svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor"><path d="M12.65 10C11.83 7.67 9.61 6 7 6c-3.31 0-6 2.69-6 6s2.69 6 6 6c2.61 0 4.83-1.67 5.65-4H17v4h4v-4h2v-4H12.65zM7 14c-1.1 0-2-.9-2-2s.9-2 2-2 2 .9 2 2-.9 2-2 2z"/></svg>Reset</button></form>
                    <form method="POST" onsubmit="return confirm('Eliminare?')"><?php echo CSRF::field(); ?><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?php echo $employee['id']; ?>"><button type="submit" class="btn btn-danger"><svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor"><path d="M6 19c0 1.1.9 2 2 2h8c1.1 0 2-.9 2-2V7H6v12zM19 4h-3.5l-1-1h-5l-1 1H5v2h14V4z"/></svg></button></form>
                </div>
            </div>

            <!-- Info Grid -->
            <div class="info-grid">
                <div class="info-cell"><svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor"><path d="M12 12c2.21 0 4-1.79 4-4s-1.79-4-4-4-4 1.79-4 4 1.79 4 4 4zm0 2c-2.67 0-8 1.34-8 4v2h16v-2c0-2.66-5.33-4-8-4z"/></svg><span class="label">Username</span><code><?php echo htmlspecialchars($employee['username']); ?></code></div>
                <div class="info-cell"><svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor"><path d="M14 2H6c-1.1 0-2 .9-2 2v16c0 1.1.9 2 2 2h12c1.1 0 2-.9 2-2V8l-6-6z"/></svg><span class="label">CF</span><code><?php echo htmlspecialchars($employee['fiscal_code']); ?></code></div>
                <div class="info-cell"><svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor"><path d="M20 4H4c-1.1 0-2 .9-2 2v12c0 1.1.9 2 2 2h16c1.1 0 2-.9 2-2V6c0-1.1-.9-2-2-2zm0 4l-8 5-8-5V6l8 5 8-5v2z"/></svg><span class="label">Email</span><?php echo $employee['email'] ? '<a href="mailto:'.htmlspecialchars($employee['email']).'">'.htmlspecialchars($employee['email']).'</a>' : '<span class="text-muted">-</span>'; ?></div>
                <div class="info-cell"><svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor"><path d="M6.62 10.79c1.44 2.83 3.76 5.14 6.59 6.59l2.2-2.2c.27-.27.67-.36 1.02-.24 1.12.37 2.33.57 3.57.57.55 0 1 .45 1 1V20c0 .55-.45 1-1 1-9.39 0-17-7.61-17-17 0-.55.45-1 1-1h3.5c.55 0 1 .45 1 1 0 1.25.2 2.45.57 3.57.11.35.03.74-.25 1.02l-2.2 2.2z"/></svg><span class="label">Tel</span><?php echo $employee['phone'] ? '<a href="tel:'.htmlspecialchars($employee['phone']).'">'.htmlspecialchars($employee['phone']).'</a>' : '<span class="text-muted">-</span>'; ?></div>
                <div class="info-cell"><svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor"><path d="M19 3h-1V1h-2v2H8V1H6v2H5c-1.1 0-2 .9-2 2v14c0 1.1.9 2 2 2h14c1.1 0 2-.9 2-2V5c0-1.1-.9-2-2-2zm0 16H5V8h14v11z"/></svg><span class="label">Assunz.</span><span class="value"><?php echo $employee['hire_date'] ? formatDate($employee['hire_date']) : '-'; ?></span></div>
                <div class="info-cell"><svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor"><path d="M11.99 2C6.47 2 2 6.48 2 12s4.47 10 9.99 10C17.52 22 22 17.52 22 12S17.52 2 11.99 2zM12 20c-4.42 0-8-3.58-8-8s3.58-8 8-8 8 3.58 8 8-3.58 8-8 8zm.5-13H11v6l5.25 3.15.75-1.23-4.5-2.67z"/></svg><span class="label">Accesso</span><span class="value <?php echo $employee['last_login'] ? '' : 'text-muted'; ?>"><?php echo $employee['last_login'] ? formatDateTime($employee['last_login']) : 'Mai'; ?></span></div>
            </div>

            <!-- Documents -->
            <div class="docs-section">
                <div class="docs-header">
                    <h3><svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor"><path d="M14 2H6c-1.1 0-2 .9-2 2v16c0 1.1.9 2 2 2h12c1.1 0 2-.9 2-2V8l-6-6z"/></svg>Documenti</h3>
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
            </div>
        </div>

        <style>
        /* Employee View - Compact Layout */
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

        .filter-btn {
            padding: 0.3rem 0.6rem;
            border: 1px solid #e2e8f0;
            background: white;
            border-radius: 4px;
            font-size: 0.7rem;
            cursor: pointer;
            transition: all 0.2s;
        }

        .filter-btn:hover { border-color: #3182ce; color: #3182ce; }
        .filter-btn.active { background: #3182ce; border-color: #3182ce; color: white; }

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
