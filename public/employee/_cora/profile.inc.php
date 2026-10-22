<?php
/**
 * Profilo — interfaccia Cora (Figma 994:12717). Si apre dall'avatar in alto.
 * Inclusa da employee/profile.php dopo i POST (dati, foto, notifiche):
 * i form inviano le stesse action dell'interfaccia classica.
 */
// Accesso diretto al file: niente
if (!class_exists('CoraUi', false)) { http_response_code(404); exit; }
$coraPage = true;
$pageTitle = 'Il mio profilo';
$__b = PUBLIC_URL;

$__company = '';
try {
    $__company = (string) Database::fetchColumn("SELECT name FROM companies WHERE id = ?", [(int) $employee['company_id']]);
} catch (Throwable $__e) {}

$__first = CoraUi::name($employee['first_name']);
$__last = CoraUi::name($employee['last_name']);
$__role = trim((string) ($employee['position'] ?? ''));
$__subtitle = implode(' - ', array_filter([$__role, $__company]));

// IBAN mascherato: prime 8 cifre e ultime 4 ("LT71 3250 •••• •••• 9309")
$__iban = strtoupper(preg_replace('/\s+/', '', (string) ($employee['iban'] ?? '')));
$__ibanMasked = $__iban === '' ? '—'
    : (strlen($__iban) > 12 ? substr($__iban, 0, 4) . ' ' . substr($__iban, 4, 4) . ' •••• •••• ' . substr($__iban, -4) : $__iban);

$__availability = $employee['availability_status'] ?? 'operative';
$__availLabels = ['operative' => 'Operativo', 'in_call' => 'In chiamata', 'in_meeting' => 'In riunione'];

$__flash = [
    'updated'               => 'Dati aggiornati',
    'photo_updated'         => 'Foto aggiornata',
    'photo_removed'         => 'Foto rimossa',
    'notifications_updated' => 'Preferenze salvate',
][$_GET['message'] ?? ''] ?? null;

include dirname(__DIR__, 2) . '/includes/header-employee.php';
?>
<?php if ($__flash): ?><span data-cora-flash data-title="<?= htmlspecialchars($__flash) ?>" hidden></span><?php endif; ?>

<div class="cora-reveal" style="--i:0"><?= CoraUi::topbar('Il mio profilo', 'Dati personali, foto e preferenze di notifica.') ?></div>

<?php if ($error): ?><div class="cora-alert is-error"><?= htmlspecialchars($error) ?></div><?php endif; ?>

<div class="cora-profile">
    <div class="cora-profile-col">
        <section class="cora-card cora-profile-card cora-reveal" style="--i:1">
            <span class="cora-profile-photo">
                <?php if ($photoUrl): ?>
                    <img src="<?= htmlspecialchars($photoUrl) ?>?v=<?= time() ?>" alt="">
                <?php else: ?>
                    <span><?= htmlspecialchars(mb_strtoupper(mb_substr($__first, 0, 1) . mb_substr($__last, 0, 1))) ?></span>
                <?php endif; ?>
            </span>
            <span class="cora-profile-name">
                <strong><?= htmlspecialchars($__first . ' ' . $__last) ?></strong>
                <?php if ($__subtitle !== ''): ?><span><?= htmlspecialchars($__subtitle) ?></span><?php endif; ?>
            </span>

            <div class="cora-avail availability-toggle" data-availability-toggle data-tour="availability" data-current="<?= htmlspecialchars($__availability) ?>">
                <button type="button" class="availability-pill cora-avail-pill" aria-haspopup="true" aria-expanded="false">
                    <i class="cora-avail-dot"></i>
                    <span class="availability-label"><?= $__availLabels[$__availability] ?? 'Operativo' ?></span>
                    <?= CoraUi::svg('ic-chev-d.svg', true) ?>
                </button>
                <div class="availability-menu cora-avail-menu" role="menu">
                    <a href="#" data-status="operative" role="menuitem"><i class="cora-avail-dot is-operative"></i>Operativo</a>
                    <a href="#" data-status="in_call" role="menuitem"><i class="cora-avail-dot is-in_call"></i>In chiamata</a>
                    <a href="#" data-status="in_meeting" role="menuitem"><i class="cora-avail-dot is-in_meeting"></i>In riunione</a>
                </div>
            </div>

            <div class="cora-profile-photo-actions">
                <form method="POST" enctype="multipart/form-data">
                    <?= CSRF::field() ?>
                    <input type="hidden" name="action" value="upload_photo">
                    <label class="cora-btn cora-btn-secondary">
                        <?= CoraUi::svg('ic-up.svg') ?>Carica foto
                        <input type="file" name="photo" accept="image/jpeg,image/png,image/webp,image/gif" hidden data-cora-drop-target onchange="this.form.submit()">
                    </label>
                </form>
                <?php if ($photoUrl): ?>
                    <form method="POST" onsubmit="return confirm('Rimuovere la foto profilo?')">
                        <?= CSRF::field() ?>
                        <input type="hidden" name="action" value="remove_photo">
                        <button type="submit" class="cora-btn cora-btn-secondary cora-btn-danger-text">Rimuovi</button>
                    </form>
                <?php endif; ?>
            </div>
            <span class="cora-hint">JPG, PNG, WEBP o GIF - max 3 MB</span>
        </section>

        <section class="cora-card cora-profile-box cora-reveal" style="--i:2">
            <h2 class="cora-box-h"><?= CoraUi::svg('ic-lock.svg') ?>Dati contrattuali</h2>
            <dl class="cora-detail">
                <div><dt>Codice fiscale</dt><dd><?= htmlspecialchars((string) ($employee['fiscal_code'] ?? '—')) ?></dd></div>
                <div><dt>Posizione</dt><dd><?= htmlspecialchars($__role !== '' ? $__role : '—') ?></dd></div>
                <div><dt>Data assunzione</dt><dd><?= !empty($employee['hire_date']) ? date('d/m/Y', strtotime($employee['hire_date'])) : '—' ?></dd></div>
                <div><dt>IBAN</dt><dd><?= htmlspecialchars($__ibanMasked) ?></dd></div>
            </dl>
            <span class="cora-hint">Per modificare questi dati scrivi all’HR.</span>
            <a class="cora-btn cora-btn-secondary" href="<?= $__b ?>/employee/chat.php?with_admin=1"><?= CoraUi::svg('ic-chat.svg') ?>Scrivi all’HR</a>
        </section>
    </div>

    <div class="cora-profile-col is-wide">
        <form class="cora-card cora-profile-box cora-reveal" style="--i:1" method="POST">
            <?= CSRF::field() ?>
            <input type="hidden" name="action" value="update_info">
            <h2 class="cora-box-h">Dati personali</h2>
            <div class="cora-grid-2">
                <label class="cora-field"><span class="cora-label">Nome<span class="req">*</span></span>
                    <input class="cora-input" type="text" name="first_name" value="<?= htmlspecialchars((string) $employee['first_name']) ?>" required maxlength="50"></label>
                <label class="cora-field"><span class="cora-label">Cognome<span class="req">*</span></span>
                    <input class="cora-input" type="text" name="last_name" value="<?= htmlspecialchars((string) $employee['last_name']) ?>" required maxlength="50"></label>
                <label class="cora-field"><span class="cora-label">Email</span>
                    <span class="cora-locked"><input class="cora-input" type="text" value="<?= htmlspecialchars((string) ($employee['email'] ?? '')) ?>" readonly><?= CoraUi::svg('ic-lock-sm.svg') ?></span></label>
                <label class="cora-field"><span class="cora-label">Username</span>
                    <span class="cora-locked"><input class="cora-input" type="text" value="<?= htmlspecialchars((string) ($employee['username'] ?? '')) ?>" readonly><?= CoraUi::svg('ic-lock-sm.svg') ?></span></label>
            </div>
            <div><button type="submit" class="cora-btn cora-btn-primary"><?= CoraUi::svg('logo-cora-white.svg') ?>Salva modifiche</button></div>
        </form>

        <div class="cora-profile-pair">
            <form class="cora-card cora-profile-box cora-reveal" style="--i:2" method="POST" data-tour="profile-notify">
                <?= CSRF::field() ?>
                <input type="hidden" name="action" value="update_notifications">
                <h2 class="cora-box-h"><?= CoraUi::svg('ic-bell-sm.svg') ?>Notifiche</h2>
                <label class="cora-toggle-row">
                    <span><strong>Email</strong><span>Nuovi documenti, comunicazioni, esiti delle richieste</span></span>
                    <input type="checkbox" name="notify_email" value="1"<?= (int) ($employee['notify_email'] ?? 1) === 1 ? ' checked' : '' ?> onchange="this.form.submit()">
                    <i class="cora-switch"></i>
                </label>
                <label class="cora-toggle-row">
                    <span><strong>Push</strong><span>Notifiche sul browser e sul telefono</span></span>
                    <input type="checkbox" name="notify_push" value="1"<?= (int) ($employee['notify_push'] ?? 1) === 1 ? ' checked' : '' ?> onchange="this.form.submit()">
                    <i class="cora-switch"></i>
                </label>
            </form>

            <section class="cora-card cora-profile-box cora-reveal" style="--i:2" data-tour="profile-guide">
                <h2 class="cora-box-h"><?= CoraUi::svg('ic-book.svg') ?>Guida interattiva</h2>
                <p class="cora-box-p">Rivedi passo per passo come usare Cora: menu, richieste di ferie e permessi, documenti, calendario e chat.</p>
                <div><button type="button" class="cora-btn cora-btn-secondary" data-tour-restart>Rivedi la guida</button></div>
            </section>
        </div>

        <section class="cora-card cora-profile-box cora-session cora-reveal" style="--i:3">
            <div class="cora-session-txt">
                <h2 class="cora-box-h">Sessione</h2>
                <p class="cora-box-p">Esci da questa sessione. Dovrai inserire di nuovo le credenziali al prossimo accesso.</p>
            </div>
            <div><a class="cora-btn cora-btn-danger" href="<?= $__b ?>/auth/logout.php"><?= CoraUi::svg('ic-logout.svg') ?>Esci dall’account</a></div>
        </section>
    </div>
</div>
<?php include dirname(__DIR__, 2) . '/includes/footer-employee.php'; ?>
