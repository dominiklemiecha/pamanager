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
    'password-resets' => 'Reset Password',
    'smtp-settings'   => 'Email / SMTP',
    'work-schedule'   => 'Orario lavorativo',
];
$__cfgSubs = [
    'profile'         => 'Le tue informazioni personali e impostazioni account',
    'password-resets' => 'Gestisci richieste di reset e password manuali',
    'smtp-settings'   => 'Server SMTP per l\'invio delle notifiche email',
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

<nav class="cfg-tabs" aria-label="Configurazione">
    <a href="<?= $__cfgBaseUrl ?>/profile.php"
       class="cfg-tab <?= $__cfgPage === 'profile' ? 'active' : '' ?>">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>
        Profilo
    </a>
    <a href="<?= $__cfgBaseUrl ?>/password-resets.php"
       class="cfg-tab <?= $__cfgPage === 'password-resets' ? 'active' : '' ?>">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="11" width="18" height="11" rx="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>
        Reset Password
    </a>
    <a href="<?= $__cfgBaseUrl ?>/smtp-settings.php"
       class="cfg-tab <?= $__cfgPage === 'smtp-settings' ? 'active' : '' ?>">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M4 4h16c1.1 0 2 .9 2 2v12c0 1.1-.9 2-2 2H4c-1.1 0-2-.9-2-2V6c0-1.1.9-2 2-2z"/><polyline points="22,6 12,13 2,6"/></svg>
        Email / SMTP
    </a>
    <a href="<?= $__cfgBaseUrl ?>/work-schedule.php"
       class="cfg-tab <?= $__cfgPage === 'work-schedule' ? 'active' : '' ?>">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>
        Orario lavorativo
    </a>
</nav>

<style>
.cfg-hero { margin-bottom: 16px; }
.cfg-tabs {
    display: flex; gap: 4px;
    margin-bottom: 22px;
    background: white;
    border: 1px solid #e2e8f0;
    border-radius: 12px;
    padding: 6px;
    overflow-x: auto;
}
.cfg-tab {
    display: inline-flex; align-items: center; gap: 8px;
    padding: 9px 16px;
    font-size: 13px; font-weight: 600;
    color: #64748b;
    text-decoration: none;
    border-radius: 8px;
    transition: all .12s ease;
    white-space: nowrap;
}
.cfg-tab:hover { background: #f1f5f9; color: #0f172a; text-decoration: none; }
.cfg-tab.active { background: #0b3aa4; color: white; }
.cfg-tab.active:hover { background: #0b3aa4; color: white; }
.cfg-tab svg { width: 16px; height: 16px; }

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
