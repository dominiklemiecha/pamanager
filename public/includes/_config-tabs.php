<?php
/**
 * Tab nav per pagine di Configurazione admin.
 * Includere subito dopo l'header-admin.php nelle pagine:
 *   - password-resets.php  (tab "password")
 *   - smtp-settings.php    (tab "email")
 *   - work-schedule.php    (tab "orario")
 */
$__cfgPage = basename($_SERVER['PHP_SELF'], '.php');
$__cfgBaseUrl = PUBLIC_URL . '/admin';

$__cfgTitles = [
    'profile'         => 'Profilo Admin',
    'punch-settings'  => 'Timbrature NFC',
    'door-settings'   => 'Apertura porta',
    'door-log'        => 'Log apertura porta',
    'password-resets' => 'Reset Password',
    'work-schedule'   => 'Orario lavorativo',
];
$__cfgSubs = [
    'profile'         => 'Le tue informazioni personali e impostazioni account',
    'punch-settings'  => 'Abilita/disabilita timbrature e configura la carta NFC',
    'door-settings'   => 'Apri la porta con badge NFC tramite ESP32 + RC522',
    'door-log'        => 'Storico tentativi di apertura della porta',
    'password-resets' => 'Gestisci richieste di reset e password manuali',
    'work-schedule'   => 'Giorni e ore lavorative usate dal saldo ferie',
];
$__cfgCurrentTitle = $__cfgTitles[$__cfgPage] ?? 'Configurazione';
$__cfgCurrentSub   = $__cfgSubs[$__cfgPage]   ?? '';
?>

<div class="welcome-card cfg-hero">
    <div>
        <h2>Configurazione · <?= htmlspecialchars($__cfgCurrentTitle) ?></h2>
        <p><?= htmlspecialchars($__cfgCurrentSub) ?></p>
    </div>
</div>

<?php include __DIR__ . '/_cd-tabs.inc.php'; ?>
<nav class="cd-tabs" aria-label="Configurazione" style="margin-bottom: 18px;">
    <a href="<?= $__cfgBaseUrl ?>/profile.php"
       class="cd-tab <?= $__cfgPage === 'profile' ? 'active' : '' ?>">
        Profilo
    </a>
    <a href="<?= $__cfgBaseUrl ?>/punch-settings.php"
       class="cd-tab <?= $__cfgPage === 'punch-settings' ? 'active' : '' ?>">
        Timbrature
    </a>
    <a href="<?= $__cfgBaseUrl ?>/door-settings.php"
       class="cd-tab <?= in_array($__cfgPage, ['door-settings', 'door-log']) ? 'active' : '' ?>">
        Apertura porta
    </a>
    <a href="<?= $__cfgBaseUrl ?>/password-resets.php"
       class="cd-tab <?= $__cfgPage === 'password-resets' ? 'active' : '' ?>">
        Reset Password
    </a>
    <a href="<?= $__cfgBaseUrl ?>/work-schedule.php"
       class="cd-tab <?= $__cfgPage === 'work-schedule' ? 'active' : '' ?>">
        Orario lavorativo
    </a>
</nav>

<style>
.cfg-hero { margin-bottom: 16px; }

/* Shared form/card patterns for config pages */
.cfg-card {
    background: white;
    border: 1px solid #e2e8f0;
    border-radius: 14px;
    padding: 24px;
    margin-bottom: 16px;
}
.cfg-card h3 {
    font-family: 'Host Grotesk','Inter',sans-serif;
    margin: 0 0 6px;
    font-size: 16px; font-weight: 700;
    color: #0f172a; letter-spacing: -0.01em;
}
.cfg-card .desc {
    color: #64748b; font-size: 13px;
    margin: 0 0 18px;
}
.cfg-fg { display: flex; flex-direction: column; gap: 6px; margin-bottom: 14px; }
.cfg-fg:last-of-type { margin-bottom: 0; }
.cfg-fg label {
    font-size: 11px; font-weight: 600; color: #475569;
    text-transform: uppercase; letter-spacing: 0.04em;
}
.cfg-fg input[type=text], .cfg-fg input[type=email], .cfg-fg input[type=number],
.cfg-fg input[type=password], .cfg-fg select {
    width: 100%; padding: 10px 12px;
    border: 1px solid #e2e8f0; border-radius: 8px;
    font-family: inherit; font-size: 14px;
    background: white;
    transition: all .12s ease;
}
.cfg-fg input:focus, .cfg-fg select:focus {
    outline: none; border-color: #0b3aa4;
    box-shadow: 0 0 0 3px rgba(11,58,164,0.10);
}
.cfg-fg small { color: #94a3b8; font-size: 11px; }

.cfg-grid { display: grid; gap: 14px; }
.cfg-grid.cols-2 { grid-template-columns: 1fr 1fr; }
.cfg-grid.cols-3 { grid-template-columns: 2fr 1fr 1fr; }

.cfg-toggle {
    display: inline-flex; align-items: center; gap: 10px;
    padding: 10px 14px;
    border: 1px solid #e2e8f0; border-radius: 8px;
    background: #fafbfc; cursor: pointer;
    font-size: 13px; color: #0f172a;
}
.cfg-toggle:hover { border-color: rgba(11,58,164,0.30); }
.cfg-toggle input { accent-color: #0b3aa4; width: 16px; height: 16px; }

.cfg-day-chips { display: flex; gap: 8px; flex-wrap: wrap; }
.cfg-day-chip {
    display: inline-flex; align-items: center; gap: 6px;
    padding: 8px 14px;
    border: 1px solid #e2e8f0; border-radius: 999px;
    background: white;
    font-size: 13px; color: #475569;
    cursor: pointer; transition: all .12s ease;
}
.cfg-day-chip input { display: none; }
.cfg-day-chip:has(input:checked) {
    background: #0b3aa4; color: white; border-color: #0b3aa4;
    font-weight: 600;
}

.cfg-actions {
    display: flex; gap: 10px; justify-content: flex-end;
    border-top: 1px solid #e2e8f0;
    padding-top: 18px; margin-top: 18px;
}
.cfg-btn {
    display: inline-flex; align-items: center; gap: 8px;
    padding: 9px 16px; border-radius: 8px;
    font-family: inherit; font-size: 13px; font-weight: 600;
    border: 1px solid transparent; cursor: pointer;
    text-decoration: none; transition: all .12s ease;
}
.cfg-btn svg { width: 14px; height: 14px; flex-shrink: 0; }
.cfg-btn-primary { background: #0b3aa4; color: white; border-color: #0b3aa4; }
.cfg-btn-primary:hover { background: #0b3aa4; border-color: #0b3aa4; color: white; }
.cfg-btn-ghost { background: white; color: #475569; border-color: #e2e8f0; }
.cfg-btn-ghost:hover { border-color: #0b3aa4; color: #0b3aa4; }

@media (max-width: 720px) {
    .cfg-grid.cols-2, .cfg-grid.cols-3 { grid-template-columns: 1fr; }
}
</style>
