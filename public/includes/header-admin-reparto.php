<?php
/**
 * Header Admin Reparto — markup Factorial-blue (allineato a mockups/)
 */

$currentUser = Auth::getUser();
$currentPage = basename($_SERVER['PHP_SELF'], '.php');
$baseUrl = PUBLIC_URL;
$pageTitle = isset($pageTitle) ? htmlspecialchars($pageTitle) : 'PAManager';
$userName = htmlspecialchars($currentUser['name']);
$departmentId = $currentUser['department_id'] ?? null;
$department = $departmentId ? Department::getById($departmentId) : null;
$departmentName = $department ? htmlspecialchars($department['name']) : 'Nessun reparto';

// Iniziali utente
$__userInitials = '';
foreach (preg_split('/\s+/', trim($currentUser['name'] ?? '')) as $p) {
    if ($p !== '') $__userInitials .= mb_substr($p, 0, 1);
    if (mb_strlen($__userInitials) >= 2) break;
}
$__userInitials = mb_strtoupper($__userInitials ?: 'U');

// Conteggi badge
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
    } catch (Exception $e) {}
}
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
} catch (Exception $e) {}
$pendingDeptResets = Auth::countPendingResetRequestsByDepartment($departmentId);
?>
<!DOCTYPE html>
<html lang="it">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="robots" content="noindex, nofollow">
    <title><?php echo $pageTitle; ?></title>

    <link rel="manifest" href="<?php echo $baseUrl; ?>/manifest.json.php">
    <link rel="apple-touch-icon" href="<?php echo $baseUrl; ?>/assets/images/icon.php?size=180&v=4">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
    <meta name="theme-color" content="#2563eb">

    <link rel="stylesheet" href="https://rsms.me/inter/inter.css">
    <link rel="stylesheet" href="<?php echo $baseUrl; ?>/assets/css/theme.css?v=<?php echo time(); ?>">
    <link rel="stylesheet" href="<?php echo $baseUrl; ?>/assets/css/components.css?v=<?php echo time(); ?>">
    <link rel="stylesheet" href="<?php echo $baseUrl; ?>/assets/css/style.css?v=<?php echo time(); ?>">
    <link rel="stylesheet" href="<?php echo $baseUrl; ?>/assets/css/mobile-staff.css?v=<?php echo time(); ?>">

    <?php echo CSRF::metaTag(); ?>
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
                    <div style="font-size:var(--text-xs); color:var(--muted)"><?php echo $departmentName; ?></div>
                </div>
            </div>
            <button class="sidebar-collapse-btn" type="button" id="sidebar-collapse" aria-label="Comprimi/espandi menu" title="Comprimi menu">
                <svg viewBox="0 0 24 24" fill="currentColor"><path d="M15.41 16.59L10.83 12l4.58-4.59L14 6l-6 6 6 6 1.41-1.41z"/></svg>
            </button>
        </div>
        <nav class="nav">
            <div class="nav-section">Generale</div>
            <a href="<?php echo $baseUrl; ?>/admin-reparto/" class="nav-item <?php echo $currentPage === 'index' ? 'active' : ''; ?>" data-tooltip="Dashboard">
                <svg class="nav-icon" viewBox="0 0 24 24" fill="currentColor"><path d="M3 13h8V3H3v10zm0 8h8v-6H3v6zm10 0h8V11h-8v10zm0-18v6h8V3h-8z"/></svg>
                <span class="nav-label">Dashboard</span>
            </a>
            <a href="<?php echo $baseUrl; ?>/admin-reparto/employees.php" class="nav-item <?php echo $currentPage === 'employees' ? 'active' : ''; ?>" data-tooltip="Dipendenti">
                <svg class="nav-icon" viewBox="0 0 24 24" fill="currentColor"><path d="M16 11c1.66 0 2.99-1.34 2.99-3S17.66 5 16 5c-1.66 0-3 1.34-3 3s1.34 3 3 3zm-8 0c1.66 0 2.99-1.34 2.99-3S9.66 5 8 5C6.34 5 5 6.34 5 8s1.34 3 3 3zm0 2c-2.33 0-7 1.17-7 3.5V19h14v-2.5c0-2.33-4.67-3.5-7-3.5z"/></svg>
                <span class="nav-label">Dipendenti</span>
            </a>
            <a href="<?php echo $baseUrl; ?>/admin-reparto/leave-requests.php" class="nav-item <?php echo $currentPage === 'leave-requests' ? 'active' : ''; ?>" data-tooltip="Ferie/Permessi">
                <svg class="nav-icon" viewBox="0 0 24 24" fill="currentColor"><path d="M19 3h-4.18C14.4 1.84 13.3 1 12 1c-1.3 0-2.4.84-2.82 2H5c-1.1 0-2 .9-2 2v14c0 1.1.9 2 2 2h14c1.1 0 2-.9 2-2V5c0-1.1-.9-2-2-2z"/></svg>
                <span class="nav-label">Ferie/Permessi</span>
                <?php if ($pendingLeaveRequests > 0): ?><span class="nav-badge"><?php echo $pendingLeaveRequests; ?></span><?php endif; ?>
            </a>
            <a href="<?php echo $baseUrl; ?>/admin-reparto/communications.php" class="nav-item <?php echo $currentPage === 'communications' ? 'active' : ''; ?>" data-tooltip="Comunicazioni">
                <svg class="nav-icon" viewBox="0 0 24 24" fill="currentColor"><path d="M20 2H4c-1.1 0-1.99.9-1.99 2L2 22l4-4h14c1.1 0 2-.9 2-2V4c0-1.1-.9-2-2-2z"/></svg>
                <span class="nav-label">Comunicazioni</span>
            </a>
            <a href="<?php echo $baseUrl; ?>/admin-reparto/chat.php" class="nav-item <?php echo $currentPage === 'chat' ? 'active' : ''; ?>" data-tooltip="Chat">
                <svg class="nav-icon" viewBox="0 0 24 24" fill="currentColor"><path d="M20 2H4c-1.1 0-2 .9-2 2v18l4-4h14c1.1 0 2-.9 2-2V4c0-1.1-.9-2-2-2z"/></svg>
                <span class="nav-label">Chat</span>
                <?php if ($unreadMessages > 0): ?><span class="nav-badge"><?php echo $unreadMessages; ?></span><?php endif; ?>
            </a>

            <div class="nav-section">Sistema</div>
            <a href="<?php echo $baseUrl; ?>/admin-reparto/password-resets.php" class="nav-item <?php echo $currentPage === 'password-resets' ? 'active' : ''; ?>" data-tooltip="Reset Password">
                <svg class="nav-icon" viewBox="0 0 24 24" fill="currentColor"><path d="M12.65 10C11.83 7.67 9.61 6 7 6c-3.31 0-6 2.69-6 6s2.69 6 6 6c2.61 0 4.83-1.67 5.65-4H17v4h4v-4h2v-4H12.65zM7 14c-1.1 0-2-.9-2-2s.9-2 2-2 2 .9 2 2-.9 2-2 2z"/></svg>
                <span class="nav-label">Reset Password</span>
                <?php if ($pendingDeptResets > 0): ?><span class="nav-badge"><?php echo $pendingDeptResets; ?></span><?php endif; ?>
            </a>
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
                <input type="search" placeholder="Cerca dipendenti, richieste...">
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
