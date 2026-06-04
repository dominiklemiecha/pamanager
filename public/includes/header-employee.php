<?php
/**
 * Header Dipendente — sidebar admin-style (desktop) + bottom nav (mobile)
 * Allineato al design system ConnecteedHR navy.
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

// Forza la firma del contratto se ce n'e uno in attesa (blocca la navigazione)
if (!in_array($currentPage, ['contract-sign','change-password','logout'], true)) {
    try {
        $__pending = Database::fetchOne(
            "SELECT id FROM hire_requests WHERE employee_id = ? AND status = 'contract_pending' ORDER BY id DESC LIMIT 1",
            [(int)$currentEmployee['id']]
        );
        if ($__pending) {
            header('Location: ' . $baseUrl . '/employee/contract-sign.php?id=' . (int)$__pending['id']);
            exit;
        }
    } catch (Throwable $__e) {}
}

$employeeDepartmentId = $currentEmployee['department_id'] ?? null;
$unreadCommCount = Communication::countUnread($currentEmployee['id'], $employeeDepartmentId);
$unreadDocsCount = Document::getUnreadCountForEmployee($currentEmployee['id'])
    + (class_exists('EmployeeDocument') ? EmployeeDocument::getUnreadCountForEmployee($currentEmployee['id']) : 0);
$unreadChats = class_exists('Chat') ? (int) Chat::countUnread('employee', $currentEmployee['id']) : 0;
$pendingInvites = class_exists('CalendarEvent') ? CalendarEvent::countPendingInvitations('employee', (int)$currentEmployee['id']) : 0;
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
    <meta name="theme-color" content="#0b3aa4">

    <!-- CSS -->
    <link rel="stylesheet" href="https://rsms.me/inter/inter.css">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Space+Grotesk:wght@500;600;700&family=Host+Grotesk:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="<?php echo $baseUrl; ?>/assets/css/theme.css?v=<?php echo time(); ?>">
    <link rel="stylesheet" href="<?php echo $baseUrl; ?>/assets/css/components.css?v=<?php echo time(); ?>">
    <link rel="stylesheet" href="<?php echo $baseUrl; ?>/assets/css/style.css?v=<?php echo time(); ?>">
    <link rel="stylesheet" href="<?php echo $baseUrl; ?>/assets/css/mobile-staff.css?v=<?php echo time(); ?>">

    <?php echo CSRF::metaTag(); ?>
    <script>window.PAM = { baseUrl: '<?php echo $baseUrl; ?>' };</script>

    <style>
    /* Bottom nav: solo su mobile, sidebar fissa su desktop */
    body.employee-body .bottom-nav { display: none; }
    body.employee-body { background: #f8f9fc; }

    /* Sidebar fissa al viewport (evita problemi con overflow-x:hidden) */
    @media (min-width: 821px) {
        body.employee-body .app {
            display: block !important;
            grid-template-columns: none !important;
        }
        body.employee-body .app-sidebar {
            position: fixed;
            top: 0; left: 0;
            height: 100vh;
            width: var(--sidebar-w, 220px);
            overflow-y: auto;
            z-index: 30;
        }
        body.employee-body .app-main {
            margin-left: var(--sidebar-w, 220px);
            min-height: 100vh;
        }
    }
    @media (max-width: 820px) {
        body.employee-body .bottom-nav { display: block; }
        body.employee-body .app-sidebar { display: none; }
        body.employee-body .app { display: block !important; }
        body.employee-body .app-main { margin-left: 0; padding-bottom: 80px; }
    }
    /* Powered: assicura visibilità (alcune pagine hanno padding scarso) */
    body.employee-body .powered { display: flex !important; }
    body.employee-body .powered img { height: 22px; opacity: 0.85; }

    /* Brand visibile in topbar solo quando la sidebar non c'è (mobile/tablet) */
    .emp-mobile-brand { display: none; text-decoration: none; align-items: center; }
    .emp-mobile-brand-text {
        font-family: 'Host Grotesk', sans-serif;
        font-weight: 700; font-size: 16px;
        color: #000; letter-spacing: -0.02em;
    }
    @media (max-width: 820px) {
        body.employee-body .emp-mobile-brand { display: inline-flex; }
    }
    </style>
</head>
<body class="employee-body admin-body">
<div class="app">
    <aside class="app-sidebar" id="appSidebar">
        <div class="brand">
            <div class="brand-text">
                <div class="brand-wordmark">Connecteed<span class="brand-wordmark-accent">HR</span></div>
            </div>
        </div>

        <nav class="nav">
            <a href="<?php echo $baseUrl; ?>/employee/" class="nav-item <?php echo $currentPage === 'index' ? 'active' : ''; ?>" data-tooltip="Home">
                <svg class="nav-icon" viewBox="0 0 24 24"><rect x="3" y="3" width="7" height="9" rx="1"/><rect x="14" y="3" width="7" height="5" rx="1"/><rect x="14" y="12" width="7" height="9" rx="1"/><rect x="3" y="16" width="7" height="5" rx="1"/></svg>
                <span class="nav-content">
                    <span class="nav-title">Home</span>
                    <span class="nav-sub">Panoramica</span>
                </span>
            </a>
            <a href="<?php echo $baseUrl; ?>/employee/documents.php" class="nav-item <?php echo $currentPage === 'documents' ? 'active' : ''; ?>" data-tooltip="Documenti">
                <svg class="nav-icon" viewBox="0 0 24 24"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/></svg>
                <span class="nav-content">
                    <span class="nav-title">Documenti</span>
                    <span class="nav-sub"><?php echo $unreadDocsCount > 0 ? $unreadDocsCount . ' nuovi' : 'Buste paga e CU'; ?></span>
                </span>
                <?php if ($unreadDocsCount > 0): ?><span class="nav-pulse"></span><?php endif; ?>
            </a>
            <a href="<?php echo $baseUrl; ?>/employee/leave-requests.php" class="nav-item <?php echo $currentPage === 'leave-requests' ? 'active' : ''; ?>" data-tooltip="Ferie e Permessi">
                <svg class="nav-icon" viewBox="0 0 24 24"><rect x="3" y="4" width="18" height="18" rx="2"/><path d="M16 2v4M8 2v4M3 10h18"/></svg>
                <span class="nav-content">
                    <span class="nav-title">Ferie/Permessi</span>
                    <span class="nav-sub">Le tue richieste</span>
                </span>
            </a>
            <a href="<?php echo $baseUrl; ?>/employee/communications.php" class="nav-item <?php echo $currentPage === 'communications' ? 'active' : ''; ?>" data-tooltip="Comunicazioni">
                <svg class="nav-icon" viewBox="0 0 24 24"><path d="m3 11 18-5v12L3 14v-3z"/><path d="M11.6 16.8a3 3 0 1 1-5.8-1.6"/></svg>
                <span class="nav-content">
                    <span class="nav-title">Comunicazioni</span>
                    <span class="nav-sub"><?php echo $unreadCommCount > 0 ? $unreadCommCount . ' da leggere' : 'Avvisi e news'; ?></span>
                </span>
                <?php if ($unreadCommCount > 0): ?><span class="nav-pulse"></span><?php endif; ?>
            </a>
            <a href="<?php echo $baseUrl; ?>/employee/calendar.php" class="nav-item <?php echo $currentPage === 'calendar' ? 'active' : ''; ?>" data-tooltip="Calendario">
                <svg class="nav-icon" viewBox="0 0 24 24"><rect x="3" y="4" width="18" height="18" rx="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg>
                <span class="nav-content">
                    <span class="nav-title">Calendario</span>
                    <span class="nav-sub"><?php echo $pendingInvites > 0 ? $pendingInvites . ' ' . ($pendingInvites === 1 ? 'invito' : 'inviti') . ' da confermare' : 'Eventi e riunioni'; ?></span>
                </span>
                <?php if ($pendingInvites > 0): ?><span class="nav-pulse" title="<?php echo $pendingInvites; ?>"></span><?php endif; ?>
            </a>
            <a href="<?php echo $baseUrl; ?>/employee/chat.php" class="nav-item <?php echo $currentPage === 'chat' ? 'active' : ''; ?>" data-tooltip="Chat">
                <svg class="nav-icon" viewBox="0 0 24 24"><path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/></svg>
                <span class="nav-content">
                    <span class="nav-title">Chat</span>
                    <span class="nav-sub"><?php echo $unreadChats > 0 ? $unreadChats . ' non letti' : 'Parla con il team'; ?></span>
                </span>
                <?php if ($unreadChats > 0): ?><span class="nav-pulse"></span><?php endif; ?>
            </a>
        </nav>
    </aside>
    <div class="app-overlay" id="appOverlay"></div>

    <div class="app-main">
        <header class="app-header">
            <a href="<?php echo $baseUrl; ?>/employee/" class="emp-mobile-brand" aria-label="Home">
                <span class="emp-mobile-brand-text">ConnecteedHR</span>
            </a>
            <div class="header-spacer" style="flex:1;"></div>
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
                <a href="<?php echo $baseUrl; ?>/employee/profile.php" class="user-chip user-chip-photo-only" title="<?php echo $employeeName; ?>">
                    <div class="user-chip-avatar">
                        <?php if (!empty($employeePhoto)): ?>
                            <img src="<?php echo htmlspecialchars($employeePhoto); ?>" alt="<?php echo $employeeName; ?>" loading="lazy" decoding="async">
                        <?php else: ?>
                            <?php echo $employeeInitials; ?>
                        <?php endif; ?>
                    </div>
                </a>
            </div>
        </header>

        <main class="app-content">
