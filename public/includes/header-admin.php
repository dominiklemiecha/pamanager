<?php
/**
 * Header Admin/Commercialista/Consulente
 * PAManager — markup Factorial-blue (allineato a mockups/)
 */

$currentUser = Auth::getUser();
$currentPage = basename($_SERVER['PHP_SELF'], '.php');
$baseUrl = PUBLIC_URL;
$isAdmin = Auth::isAdmin();
$isConsulente = ($currentUser['role'] ?? '') === 'consulente_lavoro';
$isAccountant = ($currentUser['role'] ?? '') === 'accountant';
$area = $isAdmin ? 'admin' : ($isConsulente ? 'consulente-lavoro' : 'accountant');
$pageTitle = isset($pageTitle) ? htmlspecialchars($pageTitle) : 'PAManager';
$userName = htmlspecialchars($currentUser['name']);

// Conteggi badge (riusati anche per bottom-nav)
$__cid = class_exists('Tenant') ? Tenant::currentCompanyId() : 1;
$pendingLeaveAdmin = 0;
if ($isAdmin) {
    $pendingLeaveAdmin = (int) Database::fetchColumn(
        "SELECT COUNT(*) FROM leave_requests WHERE status = 'pending' AND company_id = ?",
        [$__cid]
    );
}
$pendingResets = $isAdmin ? (int) Auth::countPendingResetRequests() : 0;
$unreadChats = class_exists('Chat')
    ? (int) Chat::countUnread($isAdmin ? 'admin' : ($isConsulente ? 'consulente_lavoro' : 'accountant'), $currentUser['id'])
    : 0;

// Iniziali utente per avatar
$__userInitials = '';
foreach (preg_split('/\s+/', trim($currentUser['name'] ?? '')) as $p) {
    if ($p !== '') $__userInitials .= mb_substr($p, 0, 1);
    if (mb_strlen($__userInitials) >= 2) break;
}
$__userInitials = mb_strtoupper($__userInitials ?: 'U');

// Tenant data (mockup-style)
$__tenants = (class_exists('Tenant') && Tenant::canSwitch()) ? Tenant::getAccessibleCompanies() : [];
$__currentTenant = (class_exists('Tenant')) ? Tenant::currentCompany() : null;
$__tenantMark = '';
if (!empty($__currentTenant['name'])) {
    foreach (preg_split('/\s+/', trim($__currentTenant['name'])) as $p) {
        if ($p !== '') $__tenantMark .= mb_substr($p, 0, 1);
        if (mb_strlen($__tenantMark) >= 2) break;
    }
    $__tenantMark = mb_strtoupper($__tenantMark);
}
?>
<!DOCTYPE html>
<html lang="it">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="robots" content="noindex, nofollow">
    <title><?php echo $pageTitle; ?></title>

    <!-- PWA -->
    <link rel="manifest" href="<?php echo $baseUrl; ?>/manifest.json.php">
    <link rel="apple-touch-icon" href="<?php echo $baseUrl; ?>/assets/images/icon.php?size=180&v=4">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
    <meta name="theme-color" content="#2563eb">

    <!-- CSS: tokens + componenti Factorial-blue -->
    <link rel="stylesheet" href="https://rsms.me/inter/inter.css">
    <link rel="stylesheet" href="<?php echo $baseUrl; ?>/assets/css/theme.css?v=<?php echo time(); ?>">
    <link rel="stylesheet" href="<?php echo $baseUrl; ?>/assets/css/components.css?v=<?php echo time(); ?>">
    <link rel="stylesheet" href="<?php echo $baseUrl; ?>/assets/css/style.css?v=<?php echo time(); ?>">
    <link rel="stylesheet" href="<?php echo $baseUrl; ?>/assets/css/mobile-staff.css?v=<?php echo time(); ?>">

    <!-- CSRF Token -->
    <?php echo CSRF::metaTag(); ?>

    <!-- Base URL for JS -->
    <script>window.PAM = { baseUrl: '<?php echo $baseUrl; ?>' };</script>
</head>
<body class="admin-body">
<div class="app">
    <aside class="app-sidebar" id="appSidebar">
        <div class="brand">
            <div class="brand-mark">P</div>
            <div class="brand-text">
                <div>
                    <div class="brand-name">PAManager</div>
                    <?php if (!empty($__currentTenant['name'])): ?>
                        <div style="font-size:var(--text-xs); color:var(--muted)"><?php echo htmlspecialchars($__currentTenant['name']); ?></div>
                    <?php else: ?>
                        <div style="font-size:var(--text-xs); color:var(--muted)"><?php echo $isAdmin ? 'Amministratore' : ($isConsulente ? 'Consulente lavoro' : 'Commercialista'); ?></div>
                    <?php endif; ?>
                </div>
            </div>
            <button class="sidebar-collapse-btn" type="button" id="sidebar-collapse" aria-label="Comprimi/espandi menu" title="Comprimi menu">
                <svg viewBox="0 0 24 24" fill="currentColor"><path d="M15.41 16.59L10.83 12l4.58-4.59L14 6l-6 6 6 6 1.41-1.41z"/></svg>
            </button>
        </div>

        <?php if (count($__tenants) > 1): ?>
        <div class="tenant-switcher" id="sidebar-tenant">
            <div class="tenant-switcher-row" id="sidebar-tenant-row">
                <div class="tenant-mark"><?php echo htmlspecialchars($__tenantMark); ?></div>
                <div class="tenant-info">
                    <div class="tenant-label">Azienda</div>
                    <div class="tenant-name"><?php echo htmlspecialchars($__currentTenant['name'] ?? 'Azienda'); ?></div>
                </div>
                <svg class="tenant-caret" viewBox="0 0 24 24" fill="currentColor"><path d="M7.41 8.59L12 13.17l4.59-4.58L18 10l-6 6-6-6 1.41-1.41z"/></svg>
            </div>
            <div class="tenant-menu">
                <form method="POST" action="<?php echo $baseUrl; ?>/auth/switch-tenant.php" style="display:contents;">
                    <?php echo CSRF::field(); ?>
                    <?php foreach ($__tenants as $__t):
                        $__tm = '';
                        foreach (preg_split('/\s+/', trim($__t['name'])) as $p) { if ($p !== '') $__tm .= mb_substr($p, 0, 1); if (mb_strlen($__tm) >= 2) break; }
                        $__tm = mb_strtoupper($__tm);
                        $__isActive = (int)$__t['id'] === (int)($__currentTenant['id'] ?? 0);
                    ?>
                        <button type="submit" name="id" value="<?php echo (int)$__t['id']; ?>" class="tenant-menu-item <?php echo $__isActive ? 'active' : ''; ?>" style="width:100%; border:0; cursor:pointer; text-align:left; background:transparent;">
                            <div class="tenant-mark"><?php echo htmlspecialchars($__tm); ?></div>
                            <div class="info">
                                <div class="n"><?php echo htmlspecialchars($__t['name']); ?></div>
                            </div>
                            <?php if ($__isActive): ?>
                                <svg class="check" viewBox="0 0 24 24" fill="currentColor"><path d="M9 16.17L4.83 12l-1.42 1.41L9 19 21 7l-1.41-1.41z"/></svg>
                            <?php endif; ?>
                        </button>
                    <?php endforeach; ?>
                </form>
                <?php if ($isAdmin): ?>
                    <a href="<?php echo $baseUrl; ?>/admin/companies.php" class="tenant-menu-item" style="border-top:1px solid var(--border);">
                        <div class="tenant-mark" style="background:var(--slate-100); color:var(--ink-2);">⚙</div>
                        <div class="info"><div class="n">Gestisci aziende</div></div>
                    </a>
                <?php endif; ?>
                <div class="tenant-menu-footer"><?php echo count($__tenants); ?> aziende assegnate</div>
            </div>
        </div>
        <?php endif; ?>

        <nav class="nav">
            <?php if ($isAdmin): ?>
                <div class="nav-section">Generale</div>
                <a href="<?php echo $baseUrl; ?>/admin/" class="nav-item <?php echo $currentPage === 'index' ? 'active' : ''; ?>" data-tooltip="Dashboard">
                    <svg class="nav-icon" viewBox="0 0 24 24" fill="currentColor"><path d="M13 3v6h8V3h-8zM3 21h8V11H3v10zM3 9h8V3H3v6zm10 12h8V11h-8v10z"/></svg>
                    <span class="nav-label">Dashboard</span>
                </a>
                <a href="<?php echo $baseUrl; ?>/admin/employees.php" class="nav-item <?php echo $currentPage === 'employees' ? 'active' : ''; ?>" data-tooltip="Dipendenti">
                    <svg class="nav-icon" viewBox="0 0 24 24" fill="currentColor"><path d="M16 11c1.66 0 2.99-1.34 2.99-3S17.66 5 16 5c-1.66 0-3 1.34-3 3s1.34 3 3 3zm-8 0c1.66 0 2.99-1.34 2.99-3S9.66 5 8 5C6.34 5 5 6.34 5 8s1.34 3 3 3zm0 2c-2.33 0-7 1.17-7 3.5V19h14v-2.5c0-2.33-4.67-3.5-7-3.5zm8 0c-.29 0-.62.02-.97.05 1.16.84 1.97 1.97 1.97 3.45V19h6v-2.5c0-2.33-4.67-3.5-7-3.5z"/></svg>
                    <span class="nav-label">Dipendenti</span>
                </a>
                <a href="<?php echo $baseUrl; ?>/admin/leave-requests.php" class="nav-item <?php echo $currentPage === 'leave-requests' ? 'active' : ''; ?>" data-tooltip="Ferie e Permessi">
                    <svg class="nav-icon" viewBox="0 0 24 24" fill="currentColor"><path d="M19 3h-4.18C14.4 1.84 13.3 1 12 1c-1.3 0-2.4.84-2.82 2H5c-1.1 0-2 .9-2 2v14c0 1.1.9 2 2 2h14c1.1 0 2-.9 2-2V5c0-1.1-.9-2-2-2zm-7 0c.55 0 1 .45 1 1s-.45 1-1 1-1-.45-1-1 .45-1 1-1zm2 14H7v-2h7v2zm3-4H7v-2h10v2zm0-4H7V7h10v2z"/></svg>
                    <span class="nav-label">Ferie/Permessi</span>
                    <?php if ($pendingLeaveAdmin > 0): ?><span class="nav-badge"><?php echo $pendingLeaveAdmin; ?></span><?php endif; ?>
                </a>
                <a href="<?php echo $baseUrl; ?>/admin/communications.php" class="nav-item <?php echo $currentPage === 'communications' ? 'active' : ''; ?>" data-tooltip="Comunicazioni">
                    <svg class="nav-icon" viewBox="0 0 24 24" fill="currentColor"><path d="M20 2H4c-1.1 0-1.99.9-1.99 2L2 22l4-4h14c1.1 0 2-.9 2-2V4c0-1.1-.9-2-2-2zm-2 12H6v-2h12v2zm0-3H6V9h12v2zm0-3H6V6h12v2z"/></svg>
                    <span class="nav-label">Comunicazioni</span>
                </a>
                <a href="<?php echo $baseUrl; ?>/admin/chat.php" class="nav-item <?php echo $currentPage === 'chat' ? 'active' : ''; ?>" data-tooltip="Chat">
                    <svg class="nav-icon" viewBox="0 0 24 24" fill="currentColor"><path d="M20 2H4c-1.1 0-2 .9-2 2v18l4-4h14c1.1 0 2-.9 2-2V4c0-1.1-.9-2-2-2z"/></svg>
                    <span class="nav-label">Chat</span>
                    <?php if ($unreadChats > 0): ?><span class="nav-badge"><?php echo $unreadChats; ?></span><?php endif; ?>
                </a>
                <a href="<?php echo $baseUrl; ?>/admin/presenze-export.php" class="nav-item <?php echo $currentPage === 'presenze-export' ? 'active' : ''; ?>" data-tooltip="Export presenze">
                    <svg class="nav-icon" viewBox="0 0 24 24" fill="currentColor"><path d="M14 2H6c-1.1 0-2 .9-2 2v16c0 1.1.9 2 2 2h12c1.1 0 2-.9 2-2V8l-6-6zm-1 7V3.5L18.5 9H13zM8 13l4 4 4-4-1.41-1.41L13 13.17V10h-2v3.17l-1.59-1.58L8 13z"/></svg>
                    <span class="nav-label">Export presenze</span>
                </a>

                <div class="nav-section">Organizzazione</div>
                <a href="<?php echo $baseUrl; ?>/admin/departments.php" class="nav-item <?php echo $currentPage === 'departments' ? 'active' : ''; ?>" data-tooltip="Reparti">
                    <svg class="nav-icon" viewBox="0 0 24 24" fill="currentColor"><path d="M12 7V3H2v18h20V7H12zM6 19H4v-2h2v2zm0-4H4v-2h2v2zm0-4H4V9h2v2zm0-4H4V5h2v2zm14 8h-8v-2h8v2zm0-4h-8v-2h8v2zm0-4h-8V9h8v2zm0-4h-8V5h8v2z"/></svg>
                    <span class="nav-label">Reparti</span>
                </a>
                <a href="<?php echo $baseUrl; ?>/admin/accountant.php" class="nav-item <?php echo $currentPage === 'accountant' ? 'active' : ''; ?>" data-tooltip="Commercialisti">
                    <svg class="nav-icon" viewBox="0 0 24 24" fill="currentColor"><path d="M19 3H5c-1.1 0-2 .9-2 2v14c0 1.1.9 2 2 2h14c1.1 0 2-.9 2-2V5c0-1.1-.9-2-2-2zM9 17H7v-7h2v7zm4 0h-2V7h2v10zm4 0h-2v-4h2v4z"/></svg>
                    <span class="nav-label">Commercialisti</span>
                </a>
                <a href="<?php echo $baseUrl; ?>/admin/consulente-lavoro.php" class="nav-item <?php echo $currentPage === 'consulente-lavoro' ? 'active' : ''; ?>" data-tooltip="Consulenti lavoro">
                    <svg class="nav-icon" viewBox="0 0 24 24" fill="currentColor"><path d="M20 6h-4V4c0-1.11-.89-2-2-2h-4c-1.11 0-2 .89-2 2v2H4c-1.11 0-1.99.89-1.99 2L2 19c0 1.11.89 2 2 2h16c1.11 0 2-.89 2-2V8c0-1.11-.89-2-2-2zm-6 0h-4V4h4v2z"/></svg>
                    <span class="nav-label">Consulenti lavoro</span>
                </a>

                <div class="nav-section">Sistema</div>
                <a href="<?php echo $baseUrl; ?>/admin/password-resets.php" class="nav-item <?php echo $currentPage === 'password-resets' ? 'active' : ''; ?>" data-tooltip="Reset Password">
                    <svg class="nav-icon" viewBox="0 0 24 24" fill="currentColor"><path d="M12.65 10C11.83 7.67 9.61 6 7 6c-3.31 0-6 2.69-6 6s2.69 6 6 6c2.61 0 4.83-1.67 5.65-4H17v4h4v-4h2v-4H12.65zM7 14c-1.1 0-2-.9-2-2s.9-2 2-2 2 .9 2 2-.9 2-2 2z"/></svg>
                    <span class="nav-label">Reset Password</span>
                    <?php if ($pendingResets > 0): ?><span class="nav-badge"><?php echo $pendingResets; ?></span><?php endif; ?>
                </a>
                <a href="<?php echo $baseUrl; ?>/admin/smtp-settings.php" class="nav-item <?php echo $currentPage === 'smtp-settings' ? 'active' : ''; ?>" data-tooltip="Email / SMTP">
                    <svg class="nav-icon" viewBox="0 0 24 24" fill="currentColor"><path d="M20 4H4c-1.1 0-1.99.9-1.99 2L2 18c0 1.1.9 2 2 2h16c1.1 0 2-.9 2-2V6c0-1.1-.9-2-2-2zm0 4l-8 5-8-5V6l8 5 8-5v2z"/></svg>
                    <span class="nav-label">Email / SMTP</span>
                </a>
                <a href="<?php echo $baseUrl; ?>/admin/work-schedule.php" class="nav-item <?php echo $currentPage === 'work-schedule' ? 'active' : ''; ?>" data-tooltip="Orario lavorativo">
                    <svg class="nav-icon" viewBox="0 0 24 24" fill="currentColor"><path d="M12 2C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12 2zm4.2 14.2L11 13V7h1.5v5.2l4.5 2.7-.8 1.3z"/></svg>
                    <span class="nav-label">Orario lavorativo</span>
                </a>
            <?php elseif ($isConsulente): ?>
                <div class="nav-section">Generale</div>
                <a href="<?php echo $baseUrl; ?>/consulente-lavoro/" class="nav-item <?php echo $currentPage === 'index' ? 'active' : ''; ?>" data-tooltip="Dashboard">
                    <svg class="nav-icon" viewBox="0 0 24 24" fill="currentColor"><path d="M13 3v6h8V3h-8zM3 21h8V11H3v10zM3 9h8V3H3v6zm10 12h8V11h-8v10z"/></svg>
                    <span class="nav-label">Dashboard</span>
                </a>
                <a href="<?php echo $baseUrl; ?>/consulente-lavoro/employees.php" class="nav-item <?php echo $currentPage === 'employees' ? 'active' : ''; ?>" data-tooltip="Anagrafica">
                    <svg class="nav-icon" viewBox="0 0 24 24" fill="currentColor"><path d="M16 11c1.66 0 2.99-1.34 2.99-3S17.66 5 16 5c-1.66 0-3 1.34-3 3s1.34 3 3 3zm-8 0c1.66 0 2.99-1.34 2.99-3S9.66 5 8 5C6.34 5 5 6.34 5 8s1.34 3 3 3zm0 2c-2.33 0-7 1.17-7 3.5V19h14v-2.5c0-2.33-4.67-3.5-7-3.5z"/></svg>
                    <span class="nav-label">Anagrafica</span>
                </a>
                <a href="<?php echo $baseUrl; ?>/consulente-lavoro/documents.php" class="nav-item <?php echo $currentPage === 'documents' ? 'active' : ''; ?>" data-tooltip="Buste paga/CUD">
                    <svg class="nav-icon" viewBox="0 0 24 24" fill="currentColor"><path d="M14 2H6c-1.1 0-1.99.9-1.99 2L4 20c0 1.1.89 2 1.99 2H18c1.1 0 2-.9 2-2V8l-6-6z"/></svg>
                    <span class="nav-label">Buste paga/CUD</span>
                </a>
                <a href="<?php echo $baseUrl; ?>/consulente-lavoro/employee-documents.php" class="nav-item <?php echo $currentPage === 'employee-documents' ? 'active' : ''; ?>" data-tooltip="Documenti dipendente">
                    <svg class="nav-icon" viewBox="0 0 24 24" fill="currentColor"><path d="M19 3H5c-1.1 0-2 .9-2 2v14c0 1.1.9 2 2 2h14c1.1 0 2-.9 2-2V5c0-1.1-.9-2-2-2z"/></svg>
                    <span class="nav-label">Documenti dipendente</span>
                </a>
                <a href="<?php echo $baseUrl; ?>/consulente-lavoro/leave-requests.php" class="nav-item <?php echo $currentPage === 'leave-requests' ? 'active' : ''; ?>" data-tooltip="Ferie/Permessi">
                    <svg class="nav-icon" viewBox="0 0 24 24" fill="currentColor"><path d="M19 3h-4.18C14.4 1.84 13.3 1 12 1c-1.3 0-2.4.84-2.82 2H5c-1.1 0-2 .9-2 2v14c0 1.1.9 2 2 2h14c1.1 0 2-.9 2-2V5c0-1.1-.9-2-2-2z"/></svg>
                    <span class="nav-label">Ferie/Permessi</span>
                </a>
                <a href="<?php echo $baseUrl; ?>/consulente-lavoro/presenze-export.php" class="nav-item <?php echo $currentPage === 'presenze-export' ? 'active' : ''; ?>" data-tooltip="Export presenze">
                    <svg class="nav-icon" viewBox="0 0 24 24" fill="currentColor"><path d="M19 3H5c-1.11 0-2 .9-2 2v14c0 1.1.89 2 2 2h14c1.11 0 2-.9 2-2V5c0-1.1-.89-2-2-2z"/></svg>
                    <span class="nav-label">Export presenze</span>
                </a>
                <a href="<?php echo $baseUrl; ?>/consulente-lavoro/chat.php" class="nav-item <?php echo $currentPage === 'chat' ? 'active' : ''; ?>" data-tooltip="Chat">
                    <svg class="nav-icon" viewBox="0 0 24 24" fill="currentColor"><path d="M20 2H4c-1.1 0-2 .9-2 2v18l4-4h14c1.1 0 2-.9 2-2V4c0-1.1-.9-2-2-2z"/></svg>
                    <span class="nav-label">Chat</span>
                    <?php if ($unreadChats > 0): ?><span class="nav-badge"><?php echo $unreadChats; ?></span><?php endif; ?>
                </a>
            <?php else: ?>
                <div class="nav-section">Generale</div>
                <a href="<?php echo $baseUrl; ?>/accountant/" class="nav-item <?php echo $currentPage === 'index' ? 'active' : ''; ?>" data-tooltip="Dashboard">
                    <svg class="nav-icon" viewBox="0 0 24 24" fill="currentColor"><path d="M13 3v6h8V3h-8zM3 21h8V11H3v10zM3 9h8V3H3v6zm10 12h8V11h-8v10z"/></svg>
                    <span class="nav-label">Dashboard</span>
                </a>
                <a href="<?php echo $baseUrl; ?>/accountant/documents.php" class="nav-item <?php echo $currentPage === 'documents' ? 'active' : ''; ?>" data-tooltip="Carica Documenti">
                    <svg class="nav-icon" viewBox="0 0 24 24" fill="currentColor"><path d="M14 2H6c-1.1 0-1.99.9-1.99 2L4 20c0 1.1.89 2 1.99 2H18c1.1 0 2-.9 2-2V8l-6-6z"/></svg>
                    <span class="nav-label">Carica Documenti</span>
                </a>
                <a href="<?php echo $baseUrl; ?>/accountant/leave-requests.php" class="nav-item <?php echo $currentPage === 'leave-requests' ? 'active' : ''; ?>" data-tooltip="Ferie/Permessi">
                    <svg class="nav-icon" viewBox="0 0 24 24" fill="currentColor"><path d="M19 3h-4.18C14.4 1.84 13.3 1 12 1c-1.3 0-2.4.84-2.82 2H5c-1.1 0-2 .9-2 2v14c0 1.1.9 2 2 2h14c1.1 0 2-.9 2-2V5c0-1.1-.9-2-2-2z"/></svg>
                    <span class="nav-label">Ferie/Permessi</span>
                </a>
                <a href="<?php echo $baseUrl; ?>/accountant/chat.php" class="nav-item <?php echo $currentPage === 'chat' ? 'active' : ''; ?>" data-tooltip="Chat">
                    <svg class="nav-icon" viewBox="0 0 24 24" fill="currentColor"><path d="M20 2H4c-1.1 0-2 .9-2 2v18l4-4h14c1.1 0 2-.9 2-2V4c0-1.1-.9-2-2-2z"/></svg>
                    <span class="nav-label">Chat</span>
                    <?php if ($unreadChats > 0): ?><span class="nav-badge"><?php echo $unreadChats; ?></span><?php endif; ?>
                </a>
            <?php endif; ?>
        </nav>
    </aside>
    <div class="app-overlay" id="appOverlay"></div>

    <div class="app-main">
        <header class="app-header">
            <button class="header-btn mobile-menu-btn" id="mobileMenuBtn" aria-label="Apri menu">
                <svg viewBox="0 0 24 24" fill="currentColor"><path d="M3 18h18v-2H3v2zm0-5h18v-2H3v2zm0-7v2h18V6H3z"/></svg>
            </button>
            <div class="header-search">
                <svg viewBox="0 0 24 24" fill="currentColor"><path d="M15.5 14h-.79l-.28-.27C15.41 12.59 16 11.11 16 9.5 16 5.91 13.09 3 9.5 3S3 5.91 3 9.5 5.91 16 9.5 16c1.61 0 3.09-.59 4.23-1.57l.27.28v.79l5 4.99L20.49 19l-4.99-5zm-6 0C7.01 14 5 11.99 5 9.5S7.01 5 9.5 5 14 7.01 14 9.5 11.99 14 9.5 14z"/></svg>
                <input type="search" placeholder="Cerca dipendenti, documenti, comunicazioni...">
            </div>
            <div class="header-actions">
                <button id="enableNotifications" class="header-btn" title="Attiva notifiche" style="display:none;">
                    <svg viewBox="0 0 24 24" fill="currentColor"><path d="M12 22c1.1 0 2-.9 2-2h-4c0 1.1.89 2 2 2zm6-6v-5c0-3.07-1.64-5.64-4.5-6.32V4c0-.83-.67-1.5-1.5-1.5s-1.5.67-1.5 1.5v.68C7.63 5.36 6 7.92 6 11v5l-2 2v1h16v-1l-2-2z"/></svg>
                </button>
                <a href="<?php echo $baseUrl; ?>/auth/logout.php" class="header-btn" title="Esci">
                    <svg viewBox="0 0 24 24" fill="currentColor"><path d="M17 7l-1.41 1.41L18.17 11H8v2h10.17l-2.58 2.58L17 17l5-5zM4 5h8V3H4c-1.1 0-2 .9-2 2v14c0 1.1.9 2 2 2h8v-2H4V5z"/></svg>
                </a>
                <div class="user-chip">
                    <div class="user-chip-avatar"><?php echo $__userInitials; ?></div>
                    <span class="user-chip-name"><?php echo $userName; ?></span>
                </div>
            </div>
        </header>

        <main class="app-content">
