<?php
/**
 * Header Admin/Commercialista
 * PAManager - Comune
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
    <meta name="theme-color" content="#1a365d">

    <!-- CSS -->
    <link rel="stylesheet" href="<?php echo $baseUrl; ?>/assets/css/style.css?v=<?php echo time(); ?>">
    <link rel="stylesheet" href="<?php echo $baseUrl; ?>/assets/css/theme.css?v=<?php echo time(); ?>">

    <!-- CSRF Token -->
    <?php echo CSRF::metaTag(); ?>

    <!-- Base URL for JS -->
    <script>window.PAM = { baseUrl: '<?php echo $baseUrl; ?>' };</script>
</head>
<body class="admin-body">
    <div class="sidebar-overlay" id="sidebarOverlay"></div>
    <aside class="admin-sidebar">
        <button type="button" class="sidebar-close" id="sidebarClose" aria-label="Chiudi menu">
            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor"><path d="M19 6.41 17.59 5 12 10.59 6.41 5 5 6.41 10.59 12 5 17.59 6.41 19 12 13.41 17.59 19 19 17.59 13.41 12z"/></svg>
        </button>
        <div class="sidebar-header">
            <a href="<?php echo $baseUrl; ?>/<?php echo $area; ?>/" class="sidebar-brand">
                <div class="brand-icon">
                    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor" width="32" height="32">
                        <path d="M12 2L2 7v10l10 5 10-5V7L12 2zm0 18.5l-7-3.5V9l7 3.5 7-3.5v8l-7 3.5z"/>
                    </svg>
                </div>
                <div class="brand-text">
                    <span class="brand-name">PAManager</span>
                    <span class="brand-role"><?php echo $isAdmin ? 'Amministratore' : 'Commercialista'; ?></span>
                </div>
            </a>
        </div>

        <?php
        // Company switcher (solo admin globali)
        if (class_exists('Tenant') && Tenant::canSwitch()):
            $__companies = Tenant::getAccessibleCompanies();
            $__current   = Tenant::currentCompany();
        ?>
            <div class="tenant-switcher">
                <details class="tenant-popover">
                    <summary>
                        <span class="tenant-label">Azienda</span>
                        <span class="tenant-name"><?php echo htmlspecialchars($__current['name'] ?? 'Azienda'); ?></span>
                        <svg viewBox="0 0 24 24" width="14" height="14" fill="currentColor"><path d="M7.41 8.59L12 13.17l4.59-4.58L18 10l-6 6-6-6 1.41-1.41z"/></svg>
                    </summary>
                    <div class="tenant-list">
                        <form method="POST" action="<?php echo $baseUrl; ?>/admin/companies.php">
                            <?php echo CSRF::field(); ?>
                            <input type="hidden" name="action" value="switch">
                            <?php foreach ($__companies as $__c): ?>
                                <button type="submit" name="id" value="<?php echo $__c['id']; ?>"
                                        class="tenant-item <?php echo (int)$__c['id'] === (int)($__current['id'] ?? 0) ? 'is-active' : ''; ?>">
                                    <span class="tenant-dot"></span>
                                    <span class="tenant-item-label"><?php echo htmlspecialchars($__c['name']); ?></span>
                                    <?php if ((int)$__c['id'] === (int)($__current['id'] ?? 0)): ?>
                                        <svg viewBox="0 0 24 24" width="14" height="14" fill="currentColor"><path d="M9 16.17L4.83 12l-1.42 1.41L9 19 21 7l-1.41-1.41z"/></svg>
                                    <?php endif; ?>
                                </button>
                            <?php endforeach; ?>
                        </form>
                        <a href="<?php echo $baseUrl; ?>/admin/companies.php" class="tenant-item tenant-manage-item">
                            <svg viewBox="0 0 24 24" width="14" height="14" fill="currentColor" style="flex-shrink:0;"><path d="M19.14 12.94c.04-.3.06-.61.06-.94 0-.32-.02-.64-.07-.94l2.03-1.58c.18-.14.23-.41.12-.61l-1.92-3.32c-.12-.22-.37-.29-.59-.22l-2.39.96c-.5-.38-1.03-.7-1.62-.94l-.36-2.54c-.04-.24-.24-.41-.48-.41h-3.84c-.24 0-.43.17-.47.41l-.36 2.54c-.59.24-1.13.57-1.62.94l-2.39-.96c-.22-.08-.47 0-.59.22L2.74 8.87c-.12.21-.08.47.12.61l2.03 1.58c-.05.3-.09.63-.09.94s.02.64.07.94l-2.03 1.58c-.18.14-.23.41-.12.61l1.92 3.32c.12.22.37.29.59.22l2.39-.96c.5.38 1.03.7 1.62.94l.36 2.54c.05.24.24.41.48.41h3.84c.24 0 .44-.17.47-.41l.36-2.54c.59-.24 1.13-.56 1.62-.94l2.39.96c.22.08.47 0 .59-.22l1.92-3.32c.12-.22.07-.47-.12-.61l-2.01-1.58zM12 15.6c-1.98 0-3.6-1.62-3.6-3.6s1.62-3.6 3.6-3.6 3.6 1.62 3.6 3.6-1.62 3.6-3.6 3.6z"/></svg>
                            <span class="tenant-item-label">Gestisci aziende</span>
                            <svg viewBox="0 0 24 24" width="12" height="12" fill="currentColor" style="opacity:0.5;"><path d="M9 6l6 6-6 6 1.41 1.41L17.83 12 10.41 4.59z"/></svg>
                        </a>
                    </div>
                </details>
            </div>
        <?php endif; ?>

        <nav class="sidebar-nav">
            <?php if ($isAdmin): ?>
                <a href="<?php echo $baseUrl; ?>/admin/" class="nav-item <?php echo $currentPage === 'index' ? 'active' : ''; ?>">
                    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor" width="20" height="20">
                        <path d="M3 13h8V3H3v10zm0 8h8v-6H3v6zm10 0h8V11h-8v10zm0-18v6h8V3h-8z"/>
                    </svg>
                    <span>Dashboard</span>
                </a>
                <a href="<?php echo $baseUrl; ?>/admin/employees.php" class="nav-item <?php echo $currentPage === 'employees' ? 'active' : ''; ?>">
                    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor" width="20" height="20">
                        <path d="M16 11c1.66 0 2.99-1.34 2.99-3S17.66 5 16 5c-1.66 0-3 1.34-3 3s1.34 3 3 3zm-8 0c1.66 0 2.99-1.34 2.99-3S9.66 5 8 5C6.34 5 5 6.34 5 8s1.34 3 3 3zm0 2c-2.33 0-7 1.17-7 3.5V19h14v-2.5c0-2.33-4.67-3.5-7-3.5zm8 0c-.29 0-.62.02-.97.05 1.16.84 1.97 1.97 1.97 3.45V19h6v-2.5c0-2.33-4.67-3.5-7-3.5z"/>
                    </svg>
                    <span>Dipendenti</span>
                </a>
                <a href="<?php echo $baseUrl; ?>/admin/communications.php" class="nav-item <?php echo $currentPage === 'communications' ? 'active' : ''; ?>">
                    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor" width="20" height="20">
                        <path d="M20 2H4c-1.1 0-1.99.9-1.99 2L2 22l4-4h14c1.1 0 2-.9 2-2V4c0-1.1-.9-2-2-2zm-2 12H6v-2h12v2zm0-3H6V9h12v2zm0-3H6V6h12v2z"/>
                    </svg>
                    <span>Comunicazioni</span>
                </a>
                <a href="<?php echo $baseUrl; ?>/admin/accountant.php" class="nav-item <?php echo $currentPage === 'accountant' ? 'active' : ''; ?>">
                    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor" width="20" height="20">
                        <path d="M19 3H5c-1.1 0-2 .9-2 2v14c0 1.1.9 2 2 2h14c1.1 0 2-.9 2-2V5c0-1.1-.9-2-2-2zM9 17H7v-7h2v7zm4 0h-2V7h2v10zm4 0h-2v-4h2v4z"/>
                    </svg>
                    <span>Commercialisti</span>
                </a>
                <a href="<?php echo $baseUrl; ?>/admin/consulente-lavoro.php" class="nav-item <?php echo $currentPage === 'consulente-lavoro' ? 'active' : ''; ?>">
                    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor" width="20" height="20">
                        <path d="M20 6h-4V4c0-1.11-.89-2-2-2h-4c-1.11 0-2 .89-2 2v2H4c-1.11 0-1.99.89-1.99 2L2 19c0 1.11.89 2 2 2h16c1.11 0 2-.89 2-2V8c0-1.11-.89-2-2-2zm-6 0h-4V4h4v2z"/>
                    </svg>
                    <span>Consulenti lavoro</span>
                </a>
                <a href="<?php echo $baseUrl; ?>/admin/departments.php" class="nav-item <?php echo $currentPage === 'departments' ? 'active' : ''; ?>">
                    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor" width="20" height="20">
                        <path d="M12 7V3H2v18h20V7H12zM6 19H4v-2h2v2zm0-4H4v-2h2v2zm0-4H4V9h2v2zm0-4H4V5h2v2zm4 12H8v-2h2v2zm0-4H8v-2h2v2zm0-4H8V9h2v2zm0-4H8V5h2v2zm10 12h-8v-2h2v-2h-2v-2h2v-2h-2V9h8v10zm-2-8h-2v2h2v-2zm0 4h-2v2h2v-2z"/>
                    </svg>
                    <span>Reparti</span>
                </a>
                <?php
                    $__cid = class_exists('Tenant') ? Tenant::currentCompanyId() : 1;
                    $pendingLeaveAdmin = (int) Database::fetchColumn(
                        "SELECT COUNT(*) FROM leave_requests WHERE status = 'pending' AND company_id = ?",
                        [$__cid]
                    );
                ?>
                <a href="<?php echo $baseUrl; ?>/admin/leave-requests.php" class="nav-item <?php echo $currentPage === 'leave-requests' ? 'active' : ''; ?>">
                    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor" width="20" height="20">
                        <path d="M19 3h-4.18C14.4 1.84 13.3 1 12 1c-1.3 0-2.4.84-2.82 2H5c-1.1 0-2 .9-2 2v14c0 1.1.9 2 2 2h14c1.1 0 2-.9 2-2V5c0-1.1-.9-2-2-2zm-7 0c.55 0 1 .45 1 1s-.45 1-1 1-1-.45-1-1 .45-1 1-1zm2 14H7v-2h7v2zm3-4H7v-2h10v2zm0-4H7V7h10v2z"/>
                    </svg>
                    <span>Ferie/Permessi</span>
                    <?php if ($pendingLeaveAdmin > 0): ?>
                        <span class="nav-badge"><?php echo $pendingLeaveAdmin; ?></span>
                    <?php endif; ?>
                </a>

                <a href="<?php echo $baseUrl; ?>/admin/presenze-export.php" class="nav-item <?php echo $currentPage === 'presenze-export' ? 'active' : ''; ?>">
                    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor" width="20" height="20">
                        <path d="M14 2H6c-1.1 0-2 .9-2 2v16c0 1.1.9 2 2 2h12c1.1 0 2-.9 2-2V8l-6-6zm-1 7V3.5L18.5 9H13zM8 13l4 4 4-4-1.41-1.41L13 13.17V10h-2v3.17l-1.59-1.58L8 13z"/>
                    </svg>
                    <span>Export presenze</span>
                </a>


                <div class="nav-divider"></div>

                <?php
                    $pendingResets = Auth::countPendingResetRequests();
                ?>
                <a href="<?php echo $baseUrl; ?>/admin/password-resets.php" class="nav-item <?php echo $currentPage === 'password-resets' ? 'active' : ''; ?>">
                    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor" width="20" height="20">
                        <path d="M12.65 10C11.83 7.67 9.61 6 7 6c-3.31 0-6 2.69-6 6s2.69 6 6 6c2.61 0 4.83-1.67 5.65-4H17v4h4v-4h2v-4H12.65zM7 14c-1.1 0-2-.9-2-2s.9-2 2-2 2 .9 2 2-.9 2-2 2z"/>
                    </svg>
                    <span>Reset Password</span>
                    <?php if ($pendingResets > 0): ?>
                        <span class="nav-badge"><?php echo $pendingResets; ?></span>
                    <?php endif; ?>
                </a>
                <a href="<?php echo $baseUrl; ?>/admin/smtp-settings.php" class="nav-item <?php echo $currentPage === 'smtp-settings' ? 'active' : ''; ?>">
                    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor" width="20" height="20">
                        <path d="M20 4H4c-1.1 0-1.99.9-1.99 2L2 18c0 1.1.9 2 2 2h16c1.1 0 2-.9 2-2V6c0-1.1-.9-2-2-2zm0 4l-8 5-8-5V6l8 5 8-5v2z"/>
                    </svg>
                    <span>Email / SMTP</span>
                </a>
                <?php
                    $unreadChatsAdmin = class_exists('Chat') ? Chat::countUnread('admin', $currentUser['id']) : 0;
                ?>
                <a href="<?php echo $baseUrl; ?>/admin/chat.php" class="nav-item <?php echo $currentPage === 'chat' ? 'active' : ''; ?>">
                    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor" width="20" height="20">
                        <path d="M20 2H4c-1.1 0-2 .9-2 2v18l4-4h14c1.1 0 2-.9 2-2V4c0-1.1-.9-2-2-2z"/>
                    </svg>
                    <span>Chat</span>
                    <?php if ($unreadChatsAdmin > 0): ?>
                        <span class="nav-badge"><?php echo $unreadChatsAdmin; ?></span>
                    <?php endif; ?>
                </a>
            <?php elseif ($isConsulente): ?>
                <a href="<?php echo $baseUrl; ?>/consulente-lavoro/" class="nav-item <?php echo $currentPage === 'index' ? 'active' : ''; ?>">
                    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor" width="20" height="20">
                        <path d="M3 13h8V3H3v10zm0 8h8v-6H3v6zm10 0h8V11h-8v10zm0-18v6h8V3h-8z"/>
                    </svg>
                    <span>Dashboard</span>
                </a>
                <a href="<?php echo $baseUrl; ?>/consulente-lavoro/employees.php" class="nav-item <?php echo $currentPage === 'employees' ? 'active' : ''; ?>">
                    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor" width="20" height="20">
                        <path d="M16 11c1.66 0 2.99-1.34 2.99-3S17.66 5 16 5c-1.66 0-3 1.34-3 3s1.34 3 3 3zm-8 0c1.66 0 2.99-1.34 2.99-3S9.66 5 8 5C6.34 5 5 6.34 5 8s1.34 3 3 3zm0 2c-2.33 0-7 1.17-7 3.5V19h14v-2.5c0-2.33-4.67-3.5-7-3.5zm8 0c-.29 0-.62.02-.97.05 1.16.84 1.97 1.97 1.97 3.45V19h6v-2.5c0-2.33-4.67-3.5-7-3.5z"/>
                    </svg>
                    <span>Anagrafica</span>
                </a>
                <a href="<?php echo $baseUrl; ?>/consulente-lavoro/documents.php" class="nav-item <?php echo $currentPage === 'documents' ? 'active' : ''; ?>">
                    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor" width="20" height="20">
                        <path d="M14 2H6c-1.1 0-1.99.9-1.99 2L4 20c0 1.1.89 2 1.99 2H18c1.1 0 2-.9 2-2V8l-6-6zm4 18H6V4h7v5h5v11zM8 15.01l1.41 1.41L11 14.84V19h2v-4.16l1.59 1.59L16 15.01 12.01 11 8 15.01z"/>
                    </svg>
                    <span>Buste paga/CUD</span>
                </a>
                <a href="<?php echo $baseUrl; ?>/consulente-lavoro/employee-documents.php" class="nav-item <?php echo $currentPage === 'employee-documents' ? 'active' : ''; ?>">
                    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor" width="20" height="20">
                        <path d="M19 3H5c-1.1 0-2 .9-2 2v14c0 1.1.9 2 2 2h14c1.1 0 2-.9 2-2V5c0-1.1-.9-2-2-2zm-5 14H7v-2h7v2zm3-4H7v-2h10v2zm0-4H7V7h10v2z"/>
                    </svg>
                    <span>Documenti dipendente</span>
                </a>
                <a href="<?php echo $baseUrl; ?>/consulente-lavoro/leave-requests.php" class="nav-item <?php echo $currentPage === 'leave-requests' ? 'active' : ''; ?>">
                    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor" width="20" height="20">
                        <path d="M19 3h-4.18C14.4 1.84 13.3 1 12 1c-1.3 0-2.4.84-2.82 2H5c-1.1 0-2 .9-2 2v14c0 1.1.9 2 2 2h14c1.1 0 2-.9 2-2V5c0-1.1-.9-2-2-2zm-7 0c.55 0 1 .45 1 1s-.45 1-1 1-1-.45-1-1 .45-1 1-1zm2 14H7v-2h7v2zm3-4H7v-2h10v2zm0-4H7V7h10v2z"/>
                    </svg>
                    <span>Ferie/Permessi</span>
                </a>
                <a href="<?php echo $baseUrl; ?>/consulente-lavoro/presenze-export.php" class="nav-item <?php echo $currentPage === 'presenze-export' ? 'active' : ''; ?>">
                    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor" width="20" height="20">
                        <path d="M19 3H5c-1.11 0-2 .9-2 2v14c0 1.1.89 2 2 2h14c1.11 0 2-.9 2-2V5c0-1.1-.89-2-2-2zm-9 14H5v-2h5v2zm0-4H5v-2h5v2zm0-4H5V7h5v2zm4.5 7l-3.5-3.5 1.41-1.41 2.09 2.08 4.59-4.58L19.5 10l-6 6z"/>
                    </svg>
                    <span>Export presenze</span>
                </a>
                <?php $unreadChatsCons = Chat::countUnread('consulente_lavoro', $currentUser['id']); ?>
                <a href="<?php echo $baseUrl; ?>/consulente-lavoro/chat.php" class="nav-item <?php echo $currentPage === 'chat' ? 'active' : ''; ?>">
                    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor" width="20" height="20">
                        <path d="M20 2H4c-1.1 0-2 .9-2 2v18l4-4h14c1.1 0 2-.9 2-2V4c0-1.1-.9-2-2-2z"/>
                    </svg>
                    <span>Chat</span>
                    <?php if ($unreadChatsCons > 0): ?>
                        <span class="nav-badge"><?php echo $unreadChatsCons; ?></span>
                    <?php endif; ?>
                </a>
            <?php else: ?>
                <a href="<?php echo $baseUrl; ?>/accountant/" class="nav-item <?php echo $currentPage === 'index' ? 'active' : ''; ?>">
                    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor" width="20" height="20">
                        <path d="M3 13h8V3H3v10zm0 8h8v-6H3v6zm10 0h8V11h-8v10zm0-18v6h8V3h-8z"/>
                    </svg>
                    <span>Dashboard</span>
                </a>
                <a href="<?php echo $baseUrl; ?>/accountant/documents.php" class="nav-item <?php echo $currentPage === 'documents' ? 'active' : ''; ?>">
                    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor" width="20" height="20">
                        <path d="M14 2H6c-1.1 0-1.99.9-1.99 2L4 20c0 1.1.89 2 1.99 2H18c1.1 0 2-.9 2-2V8l-6-6zm4 18H6V4h7v5h5v11zM8 15.01l1.41 1.41L11 14.84V19h2v-4.16l1.59 1.59L16 15.01 12.01 11 8 15.01z"/>
                    </svg>
                    <span>Carica Documenti</span>
                </a>
                <a href="<?php echo $baseUrl; ?>/accountant/leave-requests.php" class="nav-item <?php echo $currentPage === 'leave-requests' ? 'active' : ''; ?>">
                    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor" width="20" height="20">
                        <path d="M19 3h-4.18C14.4 1.84 13.3 1 12 1c-1.3 0-2.4.84-2.82 2H5c-1.1 0-2 .9-2 2v14c0 1.1.9 2 2 2h14c1.1 0 2-.9 2-2V5c0-1.1-.9-2-2-2zm-7 0c.55 0 1 .45 1 1s-.45 1-1 1-1-.45-1-1 .45-1 1-1zm2 14H7v-2h7v2zm3-4H7v-2h10v2zm0-4H7V7h10v2z"/>
                    </svg>
                    <span>Ferie/Permessi</span>
                </a>
                <?php
                    $unreadChats = Chat::countUnread('accountant', $currentUser['id']);
                ?>
                <a href="<?php echo $baseUrl; ?>/accountant/chat.php" class="nav-item <?php echo $currentPage === 'chat' ? 'active' : ''; ?>">
                    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor" width="20" height="20">
                        <path d="M20 2H4c-1.1 0-2 .9-2 2v18l4-4h14c1.1 0 2-.9 2-2V4c0-1.1-.9-2-2-2z"/>
                    </svg>
                    <span>Chat</span>
                    <?php if ($unreadChats > 0): ?>
                        <span class="nav-badge"><?php echo $unreadChats; ?></span>
                    <?php endif; ?>
                </a>
            <?php endif; ?>
        </nav>

        <div class="sidebar-footer">
            <div class="user-info">
                <div class="user-avatar">
                    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor" width="24" height="24">
                        <path d="M12 12c2.21 0 4-1.79 4-4s-1.79-4-4-4-4 1.79-4 4 1.79 4 4 4zm0 2c-2.67 0-8 1.34-8 4v2h16v-2c0-2.66-5.33-4-8-4z"/>
                    </svg>
                </div>
                <div class="user-details">
                    <span class="user-name"><?php echo $userName; ?></span>
                    <span class="user-role"><?php echo $isAdmin ? 'Admin' : 'Commercialista'; ?></span>
                </div>
            </div>
        </div>
    </aside>

    <div class="admin-content">
        <header class="admin-header">
            <button class="sidebar-toggle" id="sidebarToggle" aria-label="Toggle menu">
                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor" width="24" height="24">
                    <path d="M3 18h18v-2H3v2zm0-5h18v-2H3v2zm0-7v2h18V6H3z"/>
                </svg>
            </button>
            <h1 class="page-title"><?php echo $pageTitle; ?></h1>
            <div class="header-actions" style="margin-left: auto; display: flex; gap: 0.5rem; align-items: center;">
                <button id="enableNotifications" class="btn btn-sm btn-secondary" style="display: none;">
                    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor" width="16" height="16">
                        <path d="M12 22c1.1 0 2-.9 2-2h-4c0 1.1.89 2 2 2zm6-6v-5c0-3.07-1.64-5.64-4.5-6.32V4c0-.83-.67-1.5-1.5-1.5s-1.5.67-1.5 1.5v.68C7.63 5.36 6 7.92 6 11v5l-2 2v1h16v-1l-2-2z"/>
                    </svg>
                    <span>Attiva Notifiche</span>
                </button>
                <a href="<?php echo $baseUrl; ?>/auth/logout.php" class="btn btn-sm btn-logout-top" title="Esci">
                    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor" width="16" height="16">
                        <path d="M17 7l-1.41 1.41L18.17 11H8v2h10.17l-2.58 2.58L17 17l5-5zM4 5h8V3H4c-1.1 0-2 .9-2 2v14c0 1.1.9 2 2 2h8v-2H4V5z"/>
                    </svg>
                    <span>Esci</span>
                </a>
            </div>
        </header>

        <main class="admin-main">
