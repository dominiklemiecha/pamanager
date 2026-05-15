<?php
/**
 * Header Dipendente — markup Factorial-blue (allineato a mockups/employee-home.html)
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
    } catch (Exception $__e) {}
}

$employeeDepartmentId = $currentEmployee['department_id'] ?? null;
$unreadCommCount = Communication::countUnread($currentEmployee['id'], $employeeDepartmentId);
$unreadDocsCount = Document::getUnreadCountForEmployee($currentEmployee['id'])
    + (class_exists('EmployeeDocument') ? EmployeeDocument::getUnreadCountForEmployee($currentEmployee['id']) : 0);
$unreadChats = class_exists('Chat') ? (int) Chat::countUnread('employee', $currentEmployee['id']) : 0;
$employeeName = htmlspecialchars($currentEmployee['first_name'] . ' ' . $currentEmployee['last_name']);
$employeeInitials = strtoupper(substr($currentEmployee['first_name'], 0, 1) . substr($currentEmployee['last_name'], 0, 1));

try {
    $__photoRow = Database::fetchOne("SELECT photo_path FROM employees WHERE id = ?", [$currentEmployee['id']]);
    $employeePhoto = !empty($__photoRow['photo_path']) ? $baseUrl . '/' . ltrim($__photoRow['photo_path'], '/') : '';
} catch (Throwable $__e) {
    $employeePhoto = '';
}

$employeeAvailability = 'operative';
try {
    $__avRow = Database::fetchOne("SELECT availability_status FROM employees WHERE id = ?", [$currentEmployee['id']]);
    if (!empty($__avRow['availability_status'])) {
        $employeeAvailability = $__avRow['availability_status'];
    }
} catch (Throwable $__e) {}

$availabilityLabels = [
    'operative'  => 'Operativo',
    'in_call'    => 'In chiamata',
    'in_meeting' => 'In riunione',
];

$__tenantName = '';
try {
    if (class_exists('Tenant')) {
        $__t = Tenant::currentCompany();
        $__tenantName = $__t['name'] ?? '';
    }
} catch (Throwable $__e) {}

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
                    <?php if ($__tenantName !== ''): ?>
                        <div style="font-size:var(--text-xs); color:var(--muted)"><?php echo htmlspecialchars($__tenantName); ?></div>
                    <?php else: ?>
                        <div style="font-size:var(--text-xs); color:var(--muted)">Area Dipendente</div>
                    <?php endif; ?>
                </div>
            </div>
            <button class="sidebar-collapse-btn" type="button" id="sidebar-collapse" aria-label="Comprimi/espandi menu" title="Comprimi menu">
                <svg viewBox="0 0 24 24" fill="currentColor"><path d="M15.41 16.59L10.83 12l4.58-4.59L14 6l-6 6 6 6 1.41-1.41z"/></svg>
            </button>
        </div>

        <nav class="nav">
            <div class="nav-section">Generale</div>
            <a href="<?php echo $baseUrl; ?>/employee/" class="nav-item <?php echo $currentPage === 'index' ? 'active' : ''; ?>" data-tooltip="Home">
                <svg class="nav-icon" viewBox="0 0 24 24" fill="currentColor"><path d="M10 20v-6h4v6h5v-8h3L12 3 2 12h3v8z"/></svg>
                <span class="nav-label">Home</span>
            </a>
            <a href="<?php echo $baseUrl; ?>/employee/documents.php" class="nav-item <?php echo $currentPage === 'documents' ? 'active' : ''; ?>" data-tooltip="Documenti">
                <svg class="nav-icon" viewBox="0 0 24 24" fill="currentColor"><path d="M14 2H6c-1.1 0-2 .9-2 2v16c0 1.1.9 2 2 2h12c1.1 0 2-.9 2-2V8l-6-6z"/></svg>
                <span class="nav-label">Documenti</span>
                <?php if ($unreadDocsCount > 0): ?><span class="nav-badge"><?php echo $unreadDocsCount; ?></span><?php endif; ?>
            </a>
            <a href="<?php echo $baseUrl; ?>/employee/communications.php" class="nav-item <?php echo $currentPage === 'communications' ? 'active' : ''; ?>" data-tooltip="Comunicazioni">
                <svg class="nav-icon" viewBox="0 0 24 24" fill="currentColor"><path d="M20 2H4c-1.1 0-1.99.9-1.99 2L2 22l4-4h14c1.1 0 2-.9 2-2V4c0-1.1-.9-2-2-2z"/></svg>
                <span class="nav-label">Comunicazioni</span>
                <?php if ($unreadCommCount > 0): ?><span class="nav-badge"><?php echo $unreadCommCount; ?></span><?php endif; ?>
            </a>
            <a href="<?php echo $baseUrl; ?>/employee/leave-requests.php" class="nav-item <?php echo $currentPage === 'leave-requests' ? 'active' : ''; ?>" data-tooltip="Ferie e Permessi">
                <svg class="nav-icon" viewBox="0 0 24 24" fill="currentColor"><path d="M19 3h-4.18C14.4 1.84 13.3 1 12 1c-1.3 0-2.4.84-2.82 2H5c-1.1 0-2 .9-2 2v14c0 1.1.9 2 2 2h14c1.1 0 2-.9 2-2V5c0-1.1-.9-2-2-2z"/></svg>
                <span class="nav-label">Ferie e Permessi</span>
            </a>
            <a href="<?php echo $baseUrl; ?>/employee/chat.php" class="nav-item <?php echo $currentPage === 'chat' ? 'active' : ''; ?>" data-tooltip="Chat">
                <svg class="nav-icon" viewBox="0 0 24 24" fill="currentColor"><path d="M20 2H4c-1.1 0-2 .9-2 2v18l4-4h14c1.1 0 2-.9 2-2V4c0-1.1-.9-2-2-2z"/></svg>
                <span class="nav-label">Chat</span>
                <?php if ($unreadChats > 0): ?><span class="nav-badge"><?php echo $unreadChats; ?></span><?php endif; ?>
            </a>

            <div class="nav-section">Account</div>
            <a href="<?php echo $baseUrl; ?>/employee/profile.php" class="nav-item <?php echo $currentPage === 'profile' ? 'active' : ''; ?>" data-tooltip="Il mio profilo">
                <svg class="nav-icon" viewBox="0 0 24 24" fill="currentColor"><path d="M12 12c2.21 0 4-1.79 4-4s-1.79-4-4-4-4 1.79-4 4 1.79 4 4 4zm0 2c-2.67 0-8 1.34-8 4v2h16v-2c0-2.66-5.33-4-8-4z"/></svg>
                <span class="nav-label">Il mio profilo</span>
            </a>
            <a href="<?php echo $baseUrl; ?>/employee/change-password.php" class="nav-item <?php echo $currentPage === 'change-password' ? 'active' : ''; ?>" data-tooltip="Cambia password">
                <svg class="nav-icon" viewBox="0 0 24 24" fill="currentColor"><path d="M18 8h-1V6c0-2.76-2.24-5-5-5S7 3.24 7 6v2H6c-1.1 0-2 .9-2 2v10c0 1.1.9 2 2 2h12c1.1 0 2-.9 2-2V10c0-1.1-.9-2-2-2zM9 6c0-1.66 1.34-3 3-3s3 1.34 3 3v2H9V6zm9 14H6V10h12v10zm-6-3c1.1 0 2-.9 2-2s-.9-2-2-2-2 .9-2 2 .9 2 2 2z"/></svg>
                <span class="nav-label">Cambia password</span>
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
                <input type="search" placeholder="Cerca documenti, comunicazioni...">
            </div>
            <div class="header-actions">
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
                <button id="enableNotifications" class="header-btn" title="Attiva notifiche" style="display:none;">
                    <svg viewBox="0 0 24 24" fill="currentColor"><path d="M12 22c1.1 0 2-.9 2-2h-4c0 1.1.89 2 2 2zm6-6v-5c0-3.07-1.64-5.64-4.5-6.32V4c0-.83-.67-1.5-1.5-1.5s-1.5.67-1.5 1.5v.68C7.63 5.36 6 7.92 6 11v5l-2 2v1h16v-1l-2-2z"/></svg>
                </button>
                <a href="<?php echo $baseUrl; ?>/auth/logout.php" class="header-btn" title="Esci">
                    <svg viewBox="0 0 24 24" fill="currentColor"><path d="M17 7l-1.41 1.41L18.17 11H8v2h10.17l-2.58 2.58L17 17l5-5zM4 5h8V3H4c-1.1 0-2 .9-2 2v14c0 1.1.9 2 2 2h8v-2H4V5z"/></svg>
                </a>
                <a href="<?php echo $baseUrl; ?>/employee/profile.php" class="user-chip" title="Il mio profilo">
                    <div class="user-chip-avatar">
                        <?php if (!empty($employeePhoto)): ?>
                            <img src="<?php echo htmlspecialchars($employeePhoto); ?>" alt="" style="width:100%;height:100%;border-radius:inherit;object-fit:cover;">
                        <?php else: ?>
                            <?php echo $employeeInitials; ?>
                        <?php endif; ?>
                    </div>
                    <span class="user-chip-name"><?php echo htmlspecialchars($currentEmployee['first_name']); ?></span>
                </a>
            </div>
        </header>

        <main class="app-content">
