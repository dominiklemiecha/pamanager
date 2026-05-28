<?php
/**
 * Admin -> gestione aziende.
 * Admin globale: vede tutte le aziende del sistema.
 * Admin tenant: vede e gestisce solo le proprie (company_id primaria + link in user_companies).
 */

require_once dirname(__DIR__, 2) . '/config/config.php';

Auth::init();
setSecurityHeaders();
Auth::requireUser('admin');

$user = Auth::getUser();
$isGlobalAdmin = ($user['role'] === 'admin' && array_key_exists('company_id', $user) && $user['company_id'] === null);
$accessibleCids = Tenant::accessibleCompanyIdsForCurrentUser();

$message = $error = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    CSRF::verifyOrDie();
    $action = $_POST['action'] ?? '';

    if ($action === 'create') {
        $name = trim($_POST['name'] ?? '');
        if ($name === '') { $error = 'Nome obbligatorio.'; }
        else {
            try {
                $newId = Database::insert('companies', [
                    'name' => $name,
                    'slug' => slugifyCompany($name),
                    'is_active' => 1,
                    'needs_setup' => 0,
                ]);
                // Tenant admin: aggancia la nuova azienda a se stesso via user_companies
                if (!$isGlobalAdmin) {
                    try {
                        Database::insert('user_companies', ['user_id' => (int)$user['id'], 'company_id' => $newId]);
                    } catch (Throwable $e) { /* gia' presente, OK */ }
                }
                header('Location: companies.php?msg=created'); exit;
            } catch (Throwable $e) { $error = 'Errore: ' . $e->getMessage(); }
        }
    } elseif ($action === 'update') {
        $id = (int)($_POST['id'] ?? 0);
        $name = trim($_POST['name'] ?? '');
        if (!$isGlobalAdmin && !in_array($id, $accessibleCids, true)) {
            $error = 'Non puoi modificare questa azienda.';
        } elseif ($id && $name !== '') {
            Database::update('companies', ['name' => $name, 'needs_setup' => 0], 'id = ?', [$id]);
            header('Location: companies.php?msg=updated'); exit;
        }
    } elseif ($action === 'toggle') {
        $id = (int)($_POST['id'] ?? 0);
        if (!$isGlobalAdmin && !in_array($id, $accessibleCids, true)) {
            $error = 'Non puoi modificare questa azienda.';
        } else {
            $row = Database::fetchOne("SELECT is_active FROM companies WHERE id = ?", [$id]);
            if ($row && (int)$id !== 1) { // non disattivare l'azienda 1
                Database::update('companies', ['is_active' => $row['is_active'] ? 0 : 1], 'id = ?', [$id]);
                header('Location: companies.php?msg=toggled'); exit;
            }
        }
    } elseif ($action === 'switch') {
        $id = (int)($_POST['id'] ?? 0);
        if (Tenant::switchCompany($id)) {
            header('Location: ' . PUBLIC_URL . '/admin/'); exit;
        }
        $error = 'Impossibile passare a questa azienda.';
    } elseif ($action === 'delete') {
        $id = (int)($_POST['id'] ?? 0);
        $confirmName = trim($_POST['confirm_name'] ?? '');
        $row = Database::fetchOne("SELECT id, name FROM companies WHERE id = ?", [$id]);

        if (!$row) {
            $error = 'Azienda non trovata.';
        } elseif (!$isGlobalAdmin && !in_array($id, $accessibleCids, true)) {
            $error = 'Non puoi eliminare questa azienda.';
        } elseif ($id === 1 && $isGlobalAdmin) {
            $error = 'Non puoi eliminare l\'azienda principale.';
        } elseif ($id === Tenant::currentCompanyId()) {
            $error = 'Non puoi eliminare l\'azienda corrente. Cambia azienda dal switcher prima di eliminarla.';
        } elseif ($confirmName !== $row['name']) {
            $error = 'Conferma fallita: il nome inserito non corrisponde a "' . htmlspecialchars($row['name']) . '".';
        } else {
            try {
                // Cascade manuale di tutti i dati dell'azienda
                Database::execute("DELETE FROM chat_messages WHERE company_id = ?", [$id]);
                Database::execute("DELETE FROM chat_conversations WHERE company_id = ?", [$id]);
                Database::execute("DELETE FROM notifications WHERE company_id = ?", [$id]);
                Database::execute("DELETE FROM push_subscriptions WHERE company_id = ?", [$id]);
                Database::execute("DELETE FROM app_settings WHERE company_id = ?", [$id]);
                Database::execute("DELETE FROM communications WHERE company_id = ?", [$id]);
                // Employees + cascade: documents, leaves, medical_certs via FK
                Database::execute("DELETE FROM employees WHERE company_id = ?", [$id]);
                Database::execute("DELETE FROM departments WHERE company_id = ?", [$id]);
                Database::execute("DELETE FROM users WHERE company_id = ?", [$id]);
                Database::execute("DELETE FROM companies WHERE id = ?", [$id]);
                header('Location: companies.php?msg=deleted'); exit;
            } catch (Throwable $e) {
                $error = 'Errore eliminazione: ' . $e->getMessage();
            }
        }
    }
}

// Helper slug inline (la classe non ce l'ha)
function slugifyCompany(string $s): string {
    $s = strtolower($s);
    $s = preg_replace('/[^a-z0-9]+/', '-', $s);
    return trim($s, '-');
}

// Stats per azienda - scoped al tenant admin se non globale
if ($isGlobalAdmin) {
    $rows = Database::fetchAll("
        SELECT c.*,
            (SELECT COUNT(*) FROM employees WHERE company_id = c.id AND is_active = TRUE) AS emp_count,
            (SELECT COUNT(*) FROM departments WHERE company_id = c.id) AS dept_count
        FROM companies c
        ORDER BY c.is_active DESC, c.id
    ");
} elseif (!empty($accessibleCids)) {
    $ph = implode(',', array_fill(0, count($accessibleCids), '?'));
    $rows = Database::fetchAll("
        SELECT c.*,
            (SELECT COUNT(*) FROM employees WHERE company_id = c.id AND is_active = TRUE) AS emp_count,
            (SELECT COUNT(*) FROM departments WHERE company_id = c.id) AS dept_count
        FROM companies c
        WHERE c.id IN ($ph)
        ORDER BY c.is_active DESC, c.id
    ", array_map('intval', $accessibleCids));
} else {
    $rows = [];
}

if (isset($_GET['msg'])) {
    $messages = [
        'created' => 'Azienda creata',
        'updated' => 'Azienda aggiornata',
        'toggled' => 'Stato modificato',
        'deleted' => 'Azienda eliminata definitivamente',
    ];
    $message = $messages[$_GET['msg']] ?? '';
}

$currentCid = Tenant::currentCompanyId();
$pageTitle = 'Gestione aziende';
include dirname(__DIR__) . '/includes/header-admin.php';
?>

<div class="hero-inbox">
    <div>
        <h2>Gestione aziende</h2>
        <p>Crea, rinomina e gestisci le aziende del sistema. Ogni azienda ha i suoi dipendenti, documenti, comunicazioni — completamente separati.</p>
    </div>
</div>

<?php if ($message): ?><div class="hero-inbox is-clear" style="background:#dcfce7;border-color:#86efac;"><div><h2 style="color:#15803d;"><?= htmlspecialchars($message) ?></h2></div></div><?php endif; ?>
<?php if ($error): ?><div class="hero-inbox" style="background:#fee2e2;border-color:#fca5a5;"><div><h2 style="color:#991b1b;"><?= htmlspecialchars($error) ?></h2></div></div><?php endif; ?>

<section class="card" style="margin-bottom: 1.5rem;">
    <div class="card-body">
        <h3 style="margin-top:0;">Nuova azienda</h3>
        <form method="POST" style="display:flex; gap:0.6rem; align-items:flex-end; flex-wrap:wrap;">
            <?= CSRF::field() ?>
            <input type="hidden" name="action" value="create">
            <label style="flex:1; min-width:240px;">
                <span style="display:block; font-size:0.75rem; font-weight:600; color:#64748b; margin-bottom:4px;">Nome azienda *</span>
                <input type="text" name="name" required maxlength="120"
                       style="width:100%; padding:0.5rem 0.7rem; border:1px solid #e2e8f0; border-radius:6px; font-size:0.88rem;"
                       placeholder="Es. Connecteed S.r.l.">
            </label>
            <button type="submit" class="btn btn-primary" style="padding:0.55rem 1.2rem;">+ Crea azienda</button>
        </form>
    </div>
</section>

<section class="card">
    <div class="card-header"><h3>Aziende esistenti</h3></div>
    <div class="card-body" style="padding:0;">
        <table style="width:100%; border-collapse:collapse; font-size:0.9rem;">
            <thead>
                <tr style="background:#f8fafc; border-bottom:2px solid #e2e8f0;">
                    <th style="padding:0.7rem 1rem; text-align:left;">Azienda</th>
                    <th style="padding:0.7rem; text-align:center;">Dipendenti</th>
                    <th style="padding:0.7rem; text-align:center;">Reparti</th>
                    <th style="padding:0.7rem; text-align:center;">Stato</th>
                    <th style="padding:0.7rem 1rem; text-align:right;">Azioni</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($rows as $r): $isCurrent = ((int)$r['id'] === $currentCid); $rid = (int)$r['id']; ?>
                    <tr data-row-id="<?= $rid ?>" style="border-bottom:1px solid #f1f5f9; <?= $isCurrent ? 'background:#eff6ff;' : '' ?>">
                        <td style="padding:0.7rem 1rem;">
                            <div class="comp-view" id="view-<?= $rid ?>">
                                <strong style="font-size:0.95rem;"><?= htmlspecialchars($r['name']) ?></strong>
                                <?php if ($isCurrent): ?><span style="font-size:0.7rem; color:#1e40af; font-weight:600; margin-left:0.5rem;">[CORRENTE]</span><?php endif; ?>
                                <?php if (!empty($r['needs_setup'])): ?><span style="font-size:0.7rem; color:#b45309; margin-left:0.5rem;">[da configurare]</span><?php endif; ?>
                            </div>
                            <form method="POST" class="comp-edit" id="edit-<?= $rid ?>" style="display:none;">
                                <?= CSRF::field() ?>
                                <input type="hidden" name="action" value="update">
                                <input type="hidden" name="id" value="<?= $rid ?>">
                                <div style="display:flex; gap:0.4rem; align-items:center;">
                                    <input type="text" name="name" required maxlength="120"
                                           value="<?= htmlspecialchars($r['name']) ?>"
                                           style="flex:1; min-width:160px; padding:0.45rem 0.6rem; border:1px solid #93c5fd; border-radius:6px; font-size:0.9rem;">
                                    <button type="submit" class="btn btn-sm btn-primary">Salva</button>
                                    <button type="button" class="btn btn-sm btn-secondary" onclick="toggleEdit(<?= $rid ?>, false)">Annulla</button>
                                </div>
                            </form>
                        </td>
                        <td style="padding:0.7rem; text-align:center; font-variant-numeric:tabular-nums;"><?= (int)$r['emp_count'] ?></td>
                        <td style="padding:0.7rem; text-align:center; font-variant-numeric:tabular-nums;"><?= (int)$r['dept_count'] ?></td>
                        <td style="padding:0.7rem; text-align:center;">
                            <?php if ($r['is_active']): ?>
                                <span style="background:#dcfce7; color:#15803d; padding:2px 8px; border-radius:999px; font-size:0.72rem; font-weight:600;">Attiva</span>
                            <?php else: ?>
                                <span style="background:#fee2e2; color:#991b1b; padding:2px 8px; border-radius:999px; font-size:0.72rem; font-weight:600;">Disattiva</span>
                            <?php endif; ?>
                        </td>
                        <td style="padding:0.7rem 1rem; text-align:right; white-space:nowrap;">
                            <?php if (!$isCurrent && $r['is_active']): ?>
                                <form method="POST" style="display:inline;">
                                    <?= CSRF::field() ?>
                                    <input type="hidden" name="action" value="switch">
                                    <input type="hidden" name="id" value="<?= $rid ?>">
                                    <button type="submit" class="btn btn-sm btn-primary">Entra</button>
                                </form>
                            <?php endif; ?>

                            <button type="button" class="btn btn-sm btn-secondary" onclick="toggleEdit(<?= $rid ?>, true)">
                                <svg viewBox="0 0 24 24" width="14" height="14" fill="currentColor" style="vertical-align:middle;"><path d="M3 17.25V21h3.75L17.81 9.94l-3.75-3.75L3 17.25zM20.71 7.04c.39-.39.39-1.02 0-1.41l-2.34-2.34a.9959.9959 0 0 0-1.41 0l-1.83 1.83 3.75 3.75 1.83-1.83z"/></svg>
                                Modifica
                            </button>

                            <?php if ($rid !== 1): ?>
                                <form method="POST" style="display:inline;" onsubmit="return confirm('Confermi <?= $r['is_active'] ? 'la disattivazione' : 'la riattivazione' ?> di \'<?= htmlspecialchars(addslashes($r['name'])) ?>\'?\nL\'azienda <?= $r['is_active'] ? 'non sara piu accessibile ma i dati resteranno' : 'tornera accessibile' ?>.')">
                                    <?= CSRF::field() ?>
                                    <input type="hidden" name="action" value="toggle">
                                    <input type="hidden" name="id" value="<?= $rid ?>">
                                    <button type="submit" class="btn btn-sm <?= $r['is_active'] ? 'btn-warning' : 'btn-success' ?>"
                                            style="background:<?= $r['is_active'] ? '#fef3c7' : '#dcfce7' ?>; color:<?= $r['is_active'] ? '#854d0e' : '#15803d' ?>; border:0;">
                                        <?= $r['is_active'] ? 'Disattiva' : 'Riattiva' ?>
                                    </button>
                                </form>

                                <?php if (!$isCurrent): ?>
                                <button type="button" class="btn btn-sm btn-danger"
                                        style="background:#fee2e2; color:#991b1b; border:0;"
                                        onclick="confirmDelete(<?= $rid ?>, <?= htmlspecialchars(json_encode($r['name']), ENT_QUOTES) ?>)">
                                    <svg viewBox="0 0 24 24" width="14" height="14" fill="currentColor" style="vertical-align:middle;"><path d="M6 19c0 1.1.9 2 2 2h8c1.1 0 2-.9 2-2V7H6v12zM19 4h-3.5l-1-1h-5l-1 1H5v2h14V4z"/></svg>
                                    Elimina
                                </button>
                                <?php endif; ?>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</section>

<!-- Hidden form per delete con conferma nome -->
<form method="POST" id="deleteForm" style="display:none;">
    <?= CSRF::field() ?>
    <input type="hidden" name="action" value="delete">
    <input type="hidden" name="id" id="deleteId">
    <input type="hidden" name="confirm_name" id="deleteConfirmName">
</form>

<script>
function toggleEdit(id, show) {
    var view = document.getElementById('view-' + id);
    var edit = document.getElementById('edit-' + id);
    if (!view || !edit) return;
    view.style.display = show ? 'none' : '';
    edit.style.display = show ? '' : 'none';
    if (show) {
        var input = edit.querySelector('input[name="name"]');
        if (input) { input.focus(); input.select(); }
    }
}

function confirmDelete(id, name) {
    var msg = 'ATTENZIONE: stai per eliminare DEFINITIVAMENTE l\'azienda "' + name + '"\n\n' +
              'Tutti i dati associati verranno cancellati per sempre:\n' +
              '• Dipendenti, reparti, utenti\n' +
              '• Documenti, certificati medici\n' +
              '• Richieste ferie/permessi\n' +
              '• Comunicazioni, chat, notifiche\n' +
              '• Impostazioni SMTP e branding\n\n' +
              'Questa azione NON e reversibile.\n\n' +
              'Per confermare, scrivi esattamente il nome dell\'azienda:';
    var typed = window.prompt(msg, '');
    if (typed === null) return;
    if (typed.trim() !== name) {
        alert('Il nome inserito non corrisponde. Eliminazione annullata.');
        return;
    }
    document.getElementById('deleteId').value = id;
    document.getElementById('deleteConfirmName').value = typed.trim();
    document.getElementById('deleteForm').submit();
}
</script>

<?php include dirname(__DIR__) . '/includes/footer-admin.php'; ?>
