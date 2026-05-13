<?php
/**
 * Header Dipendente con Sidebar
 * PAManager - Comune
 */

$currentEmployee = Auth::getEmployee();
$currentPage = basename($_SERVER['PHP_SELF'], '.php');
$baseUrl = PUBLIC_URL;

// Forza il cambio password al primo accesso
if ($currentPage !== 'change-password') {
    try {
        $__pwdRow = Database::fetchOne("SELECT must_change_password FROM employees WHERE id = ?", [$currentEmployee['id']]);
        if (!empty($__pwdRow['must_change_password'])) {
            header('Location: ' . $baseUrl . '/employee/change-password.php');
            exit;
        }
    } catch (Exception $__e) {
        // Migrazione non ancora eseguita: ignora
    }
}
$employeeDepartmentId = $currentEmployee['department_id'] ?? null;
$unreadCommCount = Communication::countUnread($currentEmployee['id'], $employeeDepartmentId);
$unreadDocsCount = Document::getUnreadCountForEmployee($currentEmployee['id']);
$employeeName = htmlspecialchars($currentEmployee['first_name'] . ' ' . $currentEmployee['last_name']);
$employeeInitials = strtoupper(substr($currentEmployee['first_name'], 0, 1) . substr($currentEmployee['last_name'], 0, 1));
// Carica eventuale foto profilo (colonna photo_path aggiunta con migration 010)
try {
    $__photoRow = Database::fetchOne("SELECT photo_path FROM employees WHERE id = ?", [$currentEmployee['id']]);
    $employeePhoto = !empty($__photoRow['photo_path']) ? $baseUrl . '/' . ltrim($__photoRow['photo_path'], '/') : '';
} catch (Throwable $__e) {
    $employeePhoto = '';
}
// Stato disponibilita (migration 012)
$employeeAvailability = 'operative';
try {
    $__avRow = Database::fetchOne("SELECT availability_status FROM employees WHERE id = ?", [$currentEmployee['id']]);
    if (!empty($__avRow['availability_status'])) {
        $employeeAvailability = $__avRow['availability_status'];
    }
} catch (Throwable $__e) {
    // Migrazione 012 non ancora eseguita: ignora
}
$availabilityLabels = [
    'operative'  => 'Operativo',
    'in_call'    => 'In chiamata',
    'in_meeting' => 'In riunione',
];
$pageTitle = isset($pageTitle) ? htmlspecialchars($pageTitle) : 'PAManager';
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
            <a href="<?php echo $baseUrl; ?>/employee/" class="sidebar-brand">
                <div class="brand-icon">
                    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor" width="32" height="32">
                        <path d="M12 2L2 7v10l10 5 10-5V7L12 2zm0 18.5l-7-3.5V9l7 3.5 7-3.5v8l-7 3.5z"/>
                    </svg>
                </div>
                <div class="brand-text">
                    <span class="brand-name">PAManager</span>
                    <span class="brand-role">Area Dipendente</span>
                </div>
            </a>
        </div>

        <nav class="sidebar-nav">
            <a href="<?php echo $baseUrl; ?>/employee/" class="nav-item <?php echo $currentPage === 'index' ? 'active' : ''; ?>">
                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor" width="20" height="20">
                    <path d="M10 20v-6h4v6h5v-8h3L12 3 2 12h3v8z"/>
                </svg>
                <span>Dashboard</span>
            </a>
            <a href="<?php echo $baseUrl; ?>/employee/documents.php" class="nav-item <?php echo $currentPage === 'documents' ? 'active' : ''; ?>">
                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor" width="20" height="20">
                    <path d="M14 2H6c-1.1 0-2 .9-2 2v16c0 1.1.9 2 2 2h12c1.1 0 2-.9 2-2V8l-6-6zm2 16H8v-2h8v2zm0-4H8v-2h8v2zm-3-5V3.5L18.5 9H13z"/>
                </svg>
                <span>Documenti</span>
                <?php if ($unreadDocsCount > 0): ?>
                    <span class="nav-badge"><?php echo $unreadDocsCount; ?></span>
                <?php endif; ?>
            </a>
            <a href="<?php echo $baseUrl; ?>/employee/my-documents.php" class="nav-item <?php echo $currentPage === 'my-documents' ? 'active' : ''; ?>">
                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor" width="20" height="20">
                    <path d="M19 3H5c-1.1 0-2 .9-2 2v14c0 1.1.9 2 2 2h14c1.1 0 2-.9 2-2V5c0-1.1-.9-2-2-2zm-5 14H7v-2h7v2zm3-4H7v-2h10v2zm0-4H7V7h10v2z"/>
                </svg>
                <span>I miei documenti</span>
            </a>
            <a href="<?php echo $baseUrl; ?>/employee/communications.php" class="nav-item <?php echo $currentPage === 'communications' ? 'active' : ''; ?>">
                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor" width="20" height="20">
                    <path d="M20 2H4c-1.1 0-2 .9-2 2v18l4-4h14c1.1 0 2-.9 2-2V4c0-1.1-.9-2-2-2zm0 14H6l-2 2V4h16v12z"/>
                </svg>
                <span>Comunicazioni</span>
                <?php if ($unreadCommCount > 0): ?>
                    <span class="nav-badge"><?php echo $unreadCommCount; ?></span>
                <?php endif; ?>
            </a>
            <a href="<?php echo $baseUrl; ?>/employee/leave-requests.php" class="nav-item <?php echo $currentPage === 'leave-requests' ? 'active' : ''; ?>">
                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor" width="20" height="20">
                    <path d="M19 3h-4.18C14.4 1.84 13.3 1 12 1c-1.3 0-2.4.84-2.82 2H5c-1.1 0-2 .9-2 2v14c0 1.1.9 2 2 2h14c1.1 0 2-.9 2-2V5c0-1.1-.9-2-2-2zm-7 0c.55 0 1 .45 1 1s-.45 1-1 1-1-.45-1-1 .45-1 1-1zm2 14H7v-2h7v2zm3-4H7v-2h10v2zm0-4H7V7h10v2z"/>
                </svg>
                <span>Ferie/Permessi</span>
            </a>
            <a href="<?php echo $baseUrl; ?>/employee/medical-certificate.php" class="nav-item <?php echo $currentPage === 'medical-certificate' ? 'active' : ''; ?>">
                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor" width="20" height="20">
                    <path d="M19 8h-2v3h-3v2h3v3h2v-3h3v-2h-3V8zM4 6H2v14c0 1.1.9 2 2 2h14v-2H4V6zm16-4H8c-1.1 0-2 .9-2 2v12c0 1.1.9 2 2 2h12c1.1 0 2-.9 2-2V4c0-1.1-.9-2-2-2zm0 14H8V4h12v12z"/>
                </svg>
                <span>Certificato medico</span>
            </a>
            <?php
                $unreadChats = Chat::countUnread('employee', $currentEmployee['id']);
            ?>
            <a href="<?php echo $baseUrl; ?>/employee/chat.php" class="nav-item <?php echo $currentPage === 'chat' ? 'active' : ''; ?>">
                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor" width="20" height="20">
                    <path d="M20 2H4c-1.1 0-2 .9-2 2v18l4-4h14c1.1 0 2-.9 2-2V4c0-1.1-.9-2-2-2z"/>
                </svg>
                <span>Chat</span>
                <?php if ($unreadChats > 0): ?>
                    <span class="nav-badge"><?php echo $unreadChats; ?></span>
                <?php endif; ?>
            </a>
            <a href="<?php echo $baseUrl; ?>/employee/change-password.php" class="nav-item <?php echo $currentPage === 'change-password' ? 'active' : ''; ?>">
                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor" width="20" height="20">
                    <path d="M18 8h-1V6c0-2.76-2.24-5-5-5S7 3.24 7 6v2H6c-1.1 0-2 .9-2 2v10c0 1.1.9 2 2 2h12c1.1 0 2-.9 2-2V10c0-1.1-.9-2-2-2zM9 6c0-1.66 1.34-3 3-3s3 1.34 3 3v2H9V6zm9 14H6V10h12v10zm-6-3c1.1 0 2-.9 2-2s-.9-2-2-2-2 .9-2 2 .9 2 2 2z"/>
                </svg>
                <span>Cambia Password</span>
            </a>
        </nav>

        <div class="sidebar-footer">
            <a href="<?php echo $baseUrl; ?>/employee/profile.php" class="user-info user-info-link" title="Modifica profilo">
                <div class="user-avatar user-avatar-photo">
                    <?php if (!empty($employeePhoto)): ?>
                        <img src="<?php echo htmlspecialchars($employeePhoto); ?>" alt="">
                    <?php else: ?>
                        <?php echo $employeeInitials; ?>
                    <?php endif; ?>
                </div>
                <div class="user-details">
                    <span class="user-name"><?php echo $employeeName; ?></span>
                    <span class="user-role">Modifica profilo</span>
                </div>
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
                <div class="availability-toggle" data-availability-toggle data-current="<?php echo htmlspecialchars($employeeAvailability); ?>">
                    <button type="button" class="availability-pill" aria-haspopup="true" aria-expanded="false" title="Cambia stato">
                        <span class="availability-dot"></span>
                        <span class="availability-label"><?php echo htmlspecialchars($availabilityLabels[$employeeAvailability] ?? 'Operativo'); ?></span>
                        <svg viewBox="0 0 24 24" width="12" height="12" fill="currentColor"><path d="M7 10l5 5 5-5z"/></svg>
                    </button>
                    <div class="availability-menu" role="menu">
                        <a href="#" data-status="operative" role="menuitem"><span class="availability-dot is-operative"></span>Operativo</a>
                        <a href="#" data-status="in_call" role="menuitem"><span class="availability-dot is-in_call"></span>In chiamata</a>
                        <a href="#" data-status="in_meeting" role="menuitem"><span class="availability-dot is-in_meeting"></span>In riunione</a>
                    </div>
                </div>
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
