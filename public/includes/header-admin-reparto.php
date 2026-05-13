<?php
/**
 * Header Admin Reparto
 * PAManager - Comune
 */

$currentUser = Auth::getUser();
$currentPage = basename($_SERVER['PHP_SELF'], '.php');
$baseUrl = PUBLIC_URL;
$pageTitle = isset($pageTitle) ? htmlspecialchars($pageTitle) : 'PAManager';
$userName = htmlspecialchars($currentUser['name']);
$departmentId = $currentUser['department_id'] ?? null;

// Carica info reparto
$department = $departmentId ? Department::getById($departmentId) : null;
$departmentName = $department ? htmlspecialchars($department['name']) : 'Nessun reparto';

// Conta richieste ferie pending
$pendingLeaveRequests = 0;
if ($departmentId) {
    try {
        $__arCid = class_exists('Tenant') ? Tenant::currentCompanyId() : 1;
        $pendingLeaveRequests = (int) Database::fetchColumn(
            "SELECT COUNT(*) FROM leave_requests lr
             JOIN employees e ON lr.employee_id = e.id
             WHERE e.company_id = ? AND e.department_id = ? AND lr.status = 'pending'",
            [$__arCid, $departmentId]
        );
    } catch (Exception $e) {
        // Tabella potrebbe non esistere ancora
    }
}

// Conta messaggi non letti
$unreadMessages = 0;
try {
    $unreadMessages = (int) Database::fetchColumn(
        "SELECT COUNT(*) FROM chat_messages cm
         JOIN chat_conversations cc ON cm.conversation_id = cc.id
         WHERE ((cc.participant1_type = 'admin_reparto' AND cc.participant1_id = ?)
                OR (cc.participant2_type = 'admin_reparto' AND cc.participant2_id = ?))
           AND cm.sender_type != 'admin_reparto'
           AND cm.sender_id != ?
           AND cm.is_read = FALSE",
        [$currentUser['id'], $currentUser['id'], $currentUser['id']]
    );
} catch (Exception $e) {
    // Tabella potrebbe non esistere ancora
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
    <meta name="theme-color" content="#1a365d">

    <!-- CSS -->
    <link rel="stylesheet" href="<?php echo $baseUrl; ?>/assets/css/style.css?v=<?php echo time(); ?>">
    <link rel="stylesheet" href="<?php echo $baseUrl; ?>/assets/css/theme.css?v=<?php echo time(); ?>">
    <link rel="stylesheet" href="<?php echo $baseUrl; ?>/assets/css/mobile-staff.css?v=<?php echo time(); ?>">
    <link rel="stylesheet" href="<?php echo $baseUrl; ?>/assets/css/theme-v2.css?v=<?php echo time(); ?>">

    <!-- CSRF Token -->
    <?php echo CSRF::metaTag(); ?>

    <!-- Base URL for JS -->
    <script>window.PAM = { baseUrl: '<?php echo $baseUrl; ?>' };</script>

    <?php
    $__v2_companies = [];
    if (class_exists('Tenant')) {
        try {
            $__v2_cur = (int) Tenant::currentCompanyId();
            foreach (Tenant::getAccessibleCompanies() as $__c) {
                $__name = $__c['name'] ?? ('Azienda #' . $__c['id']);
                $__v2_companies[] = [
                    'id' => (int) $__c['id'],
                    'name' => $__name,
                    'code' => strtoupper(mb_substr(preg_replace('/[^a-zA-Z]/', '', $__name), 0, 2)) ?: '??',
                    'is_current' => ((int)$__c['id'] === $__v2_cur),
                    'employee_count' => (int) (Database::fetchColumn("SELECT COUNT(*) FROM employees WHERE company_id = ? AND is_active = TRUE", [$__c['id']]) ?? 0),
                ];
            }
        } catch (Throwable $e) {}
    }
    ?>
    <script type="application/json" id="tenant-data"><?php echo json_encode(['companies' => $__v2_companies, 'switch_url' => $baseUrl . '/auth/switch-tenant.php'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?></script>
</head>
<body class="admin-body has-v2-banner">
    <div class="v2-banner">
        Anteprima nuovo design v2
        <span>· stessi dati di produzione, look in valutazione</span>
    </div>
    <div class="sidebar-overlay" id="sidebarOverlay"></div>
    <aside class="admin-sidebar">
        <button type="button" class="sidebar-close" id="sidebarClose" aria-label="Chiudi menu">
            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor"><path d="M19 6.41 17.59 5 12 10.59 6.41 5 5 6.41 10.59 12 5 17.59 6.41 19 12 13.41 17.59 19 19 17.59 13.41 12z"/></svg>
        </button>
        <div class="sidebar-header">
            <a href="<?php echo $baseUrl; ?>/admin-reparto/" class="sidebar-brand">
                <div class="brand-icon" style="background: rgba(255,255,255,0.1);">
                    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor" width="32" height="32">
                        <path d="M12 7V3H2v18h20V7H12zM6 19H4v-2h2v2zm0-4H4v-2h2v2zm0-4H4V9h2v2zm0-4H4V5h2v2zm4 12H8v-2h2v2zm0-4H8v-2h2v2zm0-4H8V9h2v2zm0-4H8V5h2v2zm10 12h-8v-2h2v-2h-2v-2h2v-2h-2V9h8v10zm-2-8h-2v2h2v-2zm0 4h-2v2h2v-2z"/>
                    </svg>
                </div>
                <div class="brand-text">
                    <span class="brand-name">PAManager</span>
                    <span class="brand-role">Admin Reparto</span>
                </div>
            </a>
        </div>

        <nav class="sidebar-nav">
            <a href="<?php echo $baseUrl; ?>/admin-reparto/" class="nav-item <?php echo $currentPage === 'index' ? 'active' : ''; ?>">
                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor" width="20" height="20">
                    <path d="M3 13h8V3H3v10zm0 8h8v-6H3v6zm10 0h8V11h-8v10zm0-18v6h8V3h-8z"/>
                </svg>
                <span>Dashboard</span>
            </a>

            <a href="<?php echo $baseUrl; ?>/admin-reparto/employees.php" class="nav-item <?php echo $currentPage === 'employees' ? 'active' : ''; ?>">
                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor" width="20" height="20">
                    <path d="M16 11c1.66 0 2.99-1.34 2.99-3S17.66 5 16 5c-1.66 0-3 1.34-3 3s1.34 3 3 3zm-8 0c1.66 0 2.99-1.34 2.99-3S9.66 5 8 5C6.34 5 5 6.34 5 8s1.34 3 3 3zm0 2c-2.33 0-7 1.17-7 3.5V19h14v-2.5c0-2.33-4.67-3.5-7-3.5zm8 0c-.29 0-.62.02-.97.05 1.16.84 1.97 1.97 1.97 3.45V19h6v-2.5c0-2.33-4.67-3.5-7-3.5z"/>
                </svg>
                <span>Dipendenti</span>
            </a>

            <a href="<?php echo $baseUrl; ?>/admin-reparto/leave-requests.php" class="nav-item <?php echo $currentPage === 'leave-requests' ? 'active' : ''; ?>">
                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor" width="20" height="20">
                    <path d="M19 3h-4.18C14.4 1.84 13.3 1 12 1c-1.3 0-2.4.84-2.82 2H5c-1.1 0-2 .9-2 2v14c0 1.1.9 2 2 2h14c1.1 0 2-.9 2-2V5c0-1.1-.9-2-2-2zm-7 0c.55 0 1 .45 1 1s-.45 1-1 1-1-.45-1-1 .45-1 1-1zm2 14H7v-2h7v2zm3-4H7v-2h10v2zm0-4H7V7h10v2z"/>
                </svg>
                <span>Ferie/Permessi</span>
                <?php if ($pendingLeaveRequests > 0): ?>
                    <span class="nav-badge"><?php echo $pendingLeaveRequests; ?></span>
                <?php endif; ?>
            </a>

            <a href="<?php echo $baseUrl; ?>/admin-reparto/communications.php" class="nav-item <?php echo $currentPage === 'communications' ? 'active' : ''; ?>">
                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor" width="20" height="20">
                    <path d="M20 2H4c-1.1 0-1.99.9-1.99 2L2 22l4-4h14c1.1 0 2-.9 2-2V4c0-1.1-.9-2-2-2zm-2 12H6v-2h12v2zm0-3H6V9h12v2zm0-3H6V6h12v2z"/>
                </svg>
                <span>Comunicazioni</span>
            </a>

            <div class="nav-divider"></div>

            <?php
                $pendingDeptResets = Auth::countPendingResetRequestsByDepartment($departmentId);
            ?>
            <a href="<?php echo $baseUrl; ?>/admin-reparto/password-resets.php" class="nav-item <?php echo $currentPage === 'password-resets' ? 'active' : ''; ?>">
                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor" width="20" height="20">
                    <path d="M12.65 10C11.83 7.67 9.61 6 7 6c-3.31 0-6 2.69-6 6s2.69 6 6 6c2.61 0 4.83-1.67 5.65-4H17v4h4v-4h2v-4H12.65zM7 14c-1.1 0-2-.9-2-2s.9-2 2-2 2 .9 2 2-.9 2-2 2z"/>
                </svg>
                <span>Reset Password</span>
                <?php if ($pendingDeptResets > 0): ?>
                    <span class="nav-badge"><?php echo $pendingDeptResets; ?></span>
                <?php endif; ?>
            </a>

            <a href="<?php echo $baseUrl; ?>/admin-reparto/chat.php" class="nav-item <?php echo $currentPage === 'chat' ? 'active' : ''; ?>">
                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor" width="20" height="20">
                    <path d="M20 2H4c-1.1 0-2 .9-2 2v18l4-4h14c1.1 0 2-.9 2-2V4c0-1.1-.9-2-2-2z"/>
                </svg>
                <span>Chat</span>
                <?php if ($unreadMessages > 0): ?>
                    <span class="nav-badge"><?php echo $unreadMessages; ?></span>
                <?php endif; ?>
            </a>
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
                    <span class="user-role"><?php echo $departmentName; ?></span>
                </div>
            </div>
            <a href="<?php echo $baseUrl; ?>/auth/logout.php" class="btn btn-logout" title="Esci">
                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor" width="20" height="20">
                    <path d="M17 7l-1.41 1.41L18.17 11H8v2h10.17l-2.58 2.58L17 17l5-5zM4 5h8V3H4c-1.1 0-2 .9-2 2v14c0 1.1.9 2 2 2h8v-2H4V5z"/>
                </svg>
                <span>Esci</span>
            </a>
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
                <button id="enableNotifications" class="btn btn-sm btn-primary" style="display: none;">
                    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor" width="16" height="16">
                        <path d="M12 22c1.1 0 2-.9 2-2h-4c0 1.1.89 2 2 2zm6-6v-5c0-3.07-1.64-5.64-4.5-6.32V4c0-.83-.67-1.5-1.5-1.5s-1.5.67-1.5 1.5v.68C7.63 5.36 6 7.92 6 11v5l-2 2v1h16v-1l-2-2z"/>
                    </svg>
                    <span>Attiva Notifiche</span>
                </button>
                <?php if ($department): ?>
                    <span class="dept-badge" style="background: var(--primary-color); color: white; padding: 0.25rem 0.75rem; border-radius: 15px; font-size: 0.75rem;">
                        <?php echo htmlspecialchars($department['code']); ?>
                    </span>
                <?php endif; ?>
            </div>
        </header>

        <main class="admin-main">
