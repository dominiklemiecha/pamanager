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
$pendingInvites = class_exists('CalendarEvent')
    ? CalendarEvent::countPendingInvitations($isAdmin ? 'admin' : ($isConsulente ? 'consulente_lavoro' : 'accountant'), (int)$currentUser['id'])
    : 0;

// Conteggio richieste di assunzione che richiedono azione del viewer
$pendingHires = 0;
if (class_exists('HireRequest')) {
    try {
        if ($isAdmin) {
            // admin: stato 'prospects_review' (consulente ha caricato, admin deve approvare)
            $pendingHires = (int) Database::fetchColumn(
                "SELECT COUNT(*) FROM hire_requests WHERE company_id = ? AND status = 'prospects_review'",
                [$__cid]
            );
        } elseif ($isConsulente) {
            // consulente: 'awaiting_prospects' (carica prospetti) + 'approved' (carica contratto = "Da contrattualizzare")
            $pendingHires = (int) Database::fetchColumn(
                "SELECT COUNT(*) FROM hire_requests
                 WHERE company_id = ? AND status IN ('awaiting_prospects','approved')
                   AND (assigned_consulente_user_id = ? OR assigned_consulente_user_id IS NULL)",
                [$__cid, (int)$currentUser['id']]
            );
        }
    } catch (Throwable $e) {}
}

// Sublabel data per sidebar (admin)
$__sb = ['emp' => '', 'comm' => '', 'pres' => '', 'dept' => ''];
if ($isAdmin) {
    try {
        $__totEmp = (int) Database::fetchColumn("SELECT COUNT(*) FROM employees WHERE company_id = ? AND is_active = TRUE", [$__cid]);
        $__newEmp = (int) Database::fetchColumn("SELECT COUNT(*) FROM employees WHERE company_id = ? AND is_active = TRUE AND created_at >= DATE_FORMAT(CURDATE(),'%Y-%m-01')", [$__cid]);
        $__sb['emp'] = $__totEmp . ' attivi' . ($__newEmp > 0 ? ' · +' . $__newEmp . ' questo mese' : '');
        $__commAct = (int) Database::fetchColumn("SELECT COUNT(*) FROM communications WHERE company_id = ? AND is_published = TRUE AND publish_date <= CURDATE() AND (expire_date IS NULL OR expire_date >= CURDATE())", [$__cid]);
        $__sb['comm'] = $__commAct . ' attive';
        $__today = date('Y-m-d');
        $__onLeave = (int) Database::fetchColumn("SELECT COUNT(DISTINCT employee_id) FROM leave_requests WHERE company_id = ? AND status='approved' AND start_date <= ? AND end_date >= ?", [$__cid, $__today, $__today]);
        $__inOffice = max(0, $__totEmp - $__onLeave);
        $__sb['pres'] = $__inOffice . ' di ' . $__totEmp . ' in ufficio';
        $__deptCnt = (int) Database::fetchColumn("SELECT COUNT(*) FROM departments WHERE company_id = ? AND is_active = TRUE", [$__cid]);
        $__sb['dept'] = $__deptCnt . ' reparti';
    } catch (Throwable $e) {}
}

// Iniziali utente per avatar
$__userInitials = '';
foreach (preg_split('/\s+/', trim($currentUser['name'] ?? '')) as $p) {
    if ($p !== '') $__userInitials .= mb_substr($p, 0, 1);
    if (mb_strlen($__userInitials) >= 2) break;
}
$__userInitials = mb_strtoupper($__userInitials ?: 'U');

// Tenant data: per admin sempre la lista (anche con 1 sola azienda, cosi' compare
// il menu "Gestisci aziende"). Per altri ruoli solo se canSwitch.
$__tenants = [];
$__activityByCompany = [];
$__otherActivity = 0;
$__canLeaveTenant = false;
if (class_exists('Tenant')) {
    $__u = Auth::getUser();
    $__role = $__u['role'] ?? '';
    if (in_array($__role, ['admin', 'accountant', 'consulente_lavoro'], true)) {
        $__tenants = Tenant::getAccessibleCompanies();
        $__activityByCompany = Tenant::activityByCompany();
        $__otherActivity = Tenant::otherCompaniesActivity();
        $__canLeaveTenant = in_array($__role, ['accountant', 'consulente_lavoro'], true) && count($__tenants) > 1;
    }
}
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
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Space+Grotesk:wght@500;600;700&family=Host+Grotesk:wght@400;500;600;700&display=swap" rel="stylesheet">
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
            <div class="brand-text">
                <div class="brand-wordmark">Connecteed<span class="brand-wordmark-accent">HR</span></div>
            </div>
        </div>

        <nav class="nav">
            <?php if ($isAdmin): ?>
                <a href="<?php echo $baseUrl; ?>/admin/" class="nav-item <?php echo $currentPage === 'index' ? 'active' : ''; ?>" data-tooltip="Dashboard">
                    <svg class="nav-icon" viewBox="0 0 24 24"><rect x="3" y="3" width="7" height="9" rx="1"/><rect x="14" y="3" width="7" height="5" rx="1"/><rect x="14" y="12" width="7" height="9" rx="1"/><rect x="3" y="16" width="7" height="5" rx="1"/></svg>
                    <span class="nav-content">
                        <span class="nav-title">Dashboard</span>
                        <span class="nav-sub">Panoramica attivit&agrave;</span>
                    </span>
                </a>
                <a href="<?php echo $baseUrl; ?>/admin/employees.php" class="nav-item <?php echo $currentPage === 'employees' ? 'active' : ''; ?>" data-tooltip="Dipendenti">
                    <svg class="nav-icon" viewBox="0 0 24 24"><path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M22 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg>
                    <span class="nav-content">
                        <span class="nav-title">Dipendenti</span>
                        <span class="nav-sub"><?php echo $__sb['emp'] ?: 'Anagrafica'; ?></span>
                    </span>
                </a>
                <a href="<?php echo $baseUrl; ?>/admin/hire-requests.php" class="nav-item <?php echo $currentPage === 'hire-requests' ? 'active' : ''; ?>" data-tooltip="Assunzioni">
                    <svg class="nav-icon" viewBox="0 0 24 24"><path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><line x1="20" y1="8" x2="20" y2="14"/><line x1="23" y1="11" x2="17" y2="11"/></svg>
                    <span class="nav-content">
                        <span class="nav-title">Assunzioni</span>
                        <span class="nav-sub"><?php echo $pendingHires > 0 ? $pendingHires . ' da approvare' : 'Nuove richieste'; ?></span>
                    </span>
                    <?php if ($pendingHires > 0): ?><span class="nav-pulse" title="<?php echo $pendingHires; ?> prospetti da approvare"></span><?php endif; ?>
                </a>
                <a href="<?php echo $baseUrl; ?>/admin/leave-requests.php" class="nav-item <?php echo $currentPage === 'leave-requests' ? 'active' : ''; ?>" data-tooltip="Ferie e Permessi">
                    <svg class="nav-icon" viewBox="0 0 24 24"><rect x="3" y="4" width="18" height="18" rx="2"/><path d="M16 2v4M8 2v4M3 10h18"/></svg>
                    <span class="nav-content">
                        <span class="nav-title">Ferie/Permessi</span>
                        <span class="nav-sub"><?php echo $pendingLeaveAdmin > 0 ? $pendingLeaveAdmin . ' in attesa di approvazione' : 'Tutto approvato'; ?></span>
                    </span>
                    <?php if ($pendingLeaveAdmin > 0): ?><span class="nav-pulse" title="<?php echo $pendingLeaveAdmin; ?>"></span><?php endif; ?>
                </a>
                <a href="<?php echo $baseUrl; ?>/admin/communications.php" class="nav-item <?php echo $currentPage === 'communications' ? 'active' : ''; ?>" data-tooltip="Comunicazioni">
                    <svg class="nav-icon" viewBox="0 0 24 24"><path d="m3 11 18-5v12L3 14v-3z"/><path d="M11.6 16.8a3 3 0 1 1-5.8-1.6"/></svg>
                    <span class="nav-content">
                        <span class="nav-title">Comunicazioni</span>
                        <span class="nav-sub"><?php echo $__sb['comm'] ?: 'Bacheca aziendale'; ?></span>
                    </span>
                </a>
                <a href="<?php echo $baseUrl; ?>/admin/chat.php" class="nav-item <?php echo $currentPage === 'chat' ? 'active' : ''; ?>" data-tooltip="Chat">
                    <svg class="nav-icon" viewBox="0 0 24 24"><path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/></svg>
                    <span class="nav-content">
                        <span class="nav-title">Chat</span>
                        <span class="nav-sub"><?php echo $unreadChats > 0 ? $unreadChats . ' non letti' : 'Nessun nuovo messaggio'; ?></span>
                    </span>
                    <?php if ($unreadChats > 0): ?><span class="nav-pulse"></span><?php endif; ?>
                </a>
                <a href="<?php echo $baseUrl; ?>/admin/calendar.php" class="nav-item <?php echo $currentPage === 'calendar' ? 'active' : ''; ?>" data-tooltip="Calendario">
                    <svg class="nav-icon" viewBox="0 0 24 24"><rect x="3" y="4" width="18" height="18" rx="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg>
                    <span class="nav-content">
                        <span class="nav-title">Calendario</span>
                        <span class="nav-sub"><?php echo $pendingInvites > 0 ? $pendingInvites . ' ' . ($pendingInvites === 1 ? 'invito' : 'inviti') . ' da confermare' : 'Eventi e riunioni'; ?></span>
                    </span>
                    <?php if ($pendingInvites > 0): ?><span class="nav-pulse" title="<?php echo $pendingInvites; ?>"></span><?php endif; ?>
                </a>
                <a href="#" class="nav-item" data-tooltip="Presenze" id="navPresenzeBtn" onclick="event.preventDefault(); openPresenzeModal();">
                    <svg class="nav-icon" viewBox="0 0 24 24"><path d="M3 3v18h18"/><path d="M7 16V8m5 8V4m5 12v-6"/></svg>
                    <span class="nav-content">
                        <span class="nav-title">Presenze</span>
                        <span class="nav-sub"><?php echo $__sb['pres'] ?: 'Export mensile'; ?></span>
                    </span>
                </a>
                <a href="<?php echo $baseUrl; ?>/admin/departments.php" class="nav-item <?php echo $currentPage === 'departments' ? 'active' : ''; ?>" data-tooltip="Reparti">
                    <svg class="nav-icon" viewBox="0 0 24 24"><path d="M3 21h18M5 21V7l8-4v18M19 21V11l-6-4"/></svg>
                    <span class="nav-content">
                        <span class="nav-title">Reparti</span>
                        <span class="nav-sub"><?php echo $__sb['dept'] ?: 'Struttura aziendale'; ?></span>
                    </span>
                </a>
                <a href="<?php echo $baseUrl; ?>/admin/accountant.php" class="nav-item <?php echo $currentPage === 'accountant' ? 'active' : ''; ?>" data-tooltip="Commercialisti">
                    <svg class="nav-icon" viewBox="0 0 24 24"><rect x="3" y="4" width="18" height="16" rx="2"/><path d="M7 10v6M12 7v9M17 13v3"/></svg>
                    <span class="nav-content">
                        <span class="nav-title">Commercialisti</span>
                        <span class="nav-sub">Accessi contabili</span>
                    </span>
                </a>
                <a href="<?php echo $baseUrl; ?>/admin/consulente-lavoro.php" class="nav-item <?php echo $currentPage === 'consulente-lavoro' ? 'active' : ''; ?>" data-tooltip="Consulenti lavoro">
                    <svg class="nav-icon" viewBox="0 0 24 24"><rect x="2" y="7" width="20" height="14" rx="2"/><path d="M8 7V5a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"/></svg>
                    <span class="nav-content">
                        <span class="nav-title">Consulenti lavoro</span>
                        <span class="nav-sub">Accessi paghe</span>
                    </span>
                </a>
                <?php $__inConfig = in_array($currentPage, ['password-resets','smtp-settings','work-schedule','profile'], true); ?>
                <a href="<?php echo $baseUrl; ?>/admin/profile.php" class="nav-item <?php echo $__inConfig ? 'active' : ''; ?>" data-tooltip="Configurazione">
                    <svg class="nav-icon" viewBox="0 0 24 24"><path d="M20 7h-9M14 17H5"/><circle cx="17" cy="17" r="3"/><circle cx="7" cy="7" r="3"/></svg>
                    <span class="nav-content">
                        <span class="nav-title">Configurazione</span>
                        <span class="nav-sub">Sistema, email, orario</span>
                    </span>
                </a>
            <?php elseif ($isConsulente): ?>
                <a href="<?php echo $baseUrl; ?>/consulente-lavoro/" class="nav-item <?php echo $currentPage === 'index' ? 'active' : ''; ?>" data-tooltip="Dashboard">
                    <svg class="nav-icon" viewBox="0 0 24 24"><rect x="3" y="3" width="7" height="9" rx="1"/><rect x="14" y="3" width="7" height="5" rx="1"/><rect x="14" y="12" width="7" height="9" rx="1"/><rect x="3" y="16" width="7" height="5" rx="1"/></svg>
                    <span class="nav-content">
                        <span class="nav-title">Dashboard</span>
                        <span class="nav-sub">Panoramica</span>
                    </span>
                </a>
                <a href="<?php echo $baseUrl; ?>/consulente-lavoro/employees.php" class="nav-item <?php echo $currentPage === 'employees' ? 'active' : ''; ?>" data-tooltip="Anagrafica">
                    <svg class="nav-icon" viewBox="0 0 24 24"><path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M22 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg>
                    <span class="nav-content">
                        <span class="nav-title">Anagrafica</span>
                        <span class="nav-sub">Dipendenti</span>
                    </span>
                </a>
                <a href="<?php echo $baseUrl; ?>/consulente-lavoro/hire-requests.php" class="nav-item <?php echo $currentPage === 'hire-requests' ? 'active' : ''; ?>" data-tooltip="Assunzioni">
                    <svg class="nav-icon" viewBox="0 0 24 24"><path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><line x1="20" y1="8" x2="20" y2="14"/><line x1="23" y1="11" x2="17" y2="11"/></svg>
                    <span class="nav-content">
                        <span class="nav-title">Assunzioni</span>
                        <span class="nav-sub"><?php echo $pendingHires > 0 ? $pendingHires . ' da gestire' : 'Richieste in corso'; ?></span>
                    </span>
                    <?php if ($pendingHires > 0): ?><span class="nav-pulse" title="<?php echo $pendingHires; ?> richieste da gestire"></span><?php endif; ?>
                </a>
                <a href="<?php echo $baseUrl; ?>/consulente-lavoro/documents.php" class="nav-item <?php echo $currentPage === 'documents' ? 'active' : ''; ?>" data-tooltip="Buste paga/CUD">
                    <svg class="nav-icon" viewBox="0 0 24 24"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/></svg>
                    <span class="nav-content">
                        <span class="nav-title">Buste paga/CU</span>
                        <span class="nav-sub">Carica documenti</span>
                    </span>
                </a>
                <a href="<?php echo $baseUrl; ?>/consulente-lavoro/employee-documents.php" class="nav-item <?php echo $currentPage === 'employee-documents' ? 'active' : ''; ?>" data-tooltip="Documenti dipendente">
                    <svg class="nav-icon" viewBox="0 0 24 24"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="9" y1="13" x2="15" y2="13"/><line x1="9" y1="17" x2="13" y2="17"/></svg>
                    <span class="nav-content">
                        <span class="nav-title">Documenti</span>
                        <span class="nav-sub">Caricati dal dipendente</span>
                    </span>
                </a>
                <a href="<?php echo $baseUrl; ?>/consulente-lavoro/leave-requests.php" class="nav-item <?php echo $currentPage === 'leave-requests' ? 'active' : ''; ?>" data-tooltip="Ferie e Permessi">
                    <svg class="nav-icon" viewBox="0 0 24 24"><rect x="3" y="4" width="18" height="18" rx="2"/><path d="M16 2v4M8 2v4M3 10h18"/></svg>
                    <span class="nav-content">
                        <span class="nav-title">Ferie/Permessi</span>
                        <span class="nav-sub">Storico richieste</span>
                    </span>
                </a>
                <a href="<?php echo $baseUrl; ?>/consulente-lavoro/presenze-export.php" class="nav-item <?php echo $currentPage === 'presenze-export' ? 'active' : ''; ?>" data-tooltip="Presenze">
                    <svg class="nav-icon" viewBox="0 0 24 24"><path d="M3 3v18h18"/><path d="M7 16V8m5 8V4m5 12v-6"/></svg>
                    <span class="nav-content">
                        <span class="nav-title">Presenze</span>
                        <span class="nav-sub">Export mensile</span>
                    </span>
                </a>
                <a href="<?php echo $baseUrl; ?>/consulente-lavoro/chat.php" class="nav-item <?php echo $currentPage === 'chat' ? 'active' : ''; ?>" data-tooltip="Chat">
                    <svg class="nav-icon" viewBox="0 0 24 24"><path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/></svg>
                    <span class="nav-content">
                        <span class="nav-title">Chat</span>
                        <span class="nav-sub"><?php echo $unreadChats > 0 ? $unreadChats . ' non letti' : 'Messaggi'; ?></span>
                    </span>
                    <?php if ($unreadChats > 0): ?><span class="nav-pulse"></span><?php endif; ?>
                </a>
                <a href="<?php echo $baseUrl; ?>/consulente-lavoro/calendar.php" class="nav-item <?php echo $currentPage === 'calendar' ? 'active' : ''; ?>" data-tooltip="Calendario">
                    <svg class="nav-icon" viewBox="0 0 24 24"><rect x="3" y="4" width="18" height="18" rx="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg>
                    <span class="nav-content">
                        <span class="nav-title">Calendario</span>
                        <span class="nav-sub"><?php echo $pendingInvites > 0 ? $pendingInvites . ' ' . ($pendingInvites === 1 ? 'invito' : 'inviti') . ' da confermare' : 'Eventi e riunioni'; ?></span>
                    </span>
                    <?php if ($pendingInvites > 0): ?><span class="nav-pulse" title="<?php echo $pendingInvites; ?>"></span><?php endif; ?>
                </a>
                <a href="<?php echo $baseUrl; ?>/consulente-lavoro/profile.php" class="nav-item <?php echo $currentPage === 'profile' ? 'active' : ''; ?>" data-tooltip="Profilo">
                    <svg class="nav-icon" viewBox="0 0 24 24"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>
                    <span class="nav-content">
                        <span class="nav-title">Profilo</span>
                        <span class="nav-sub">Le mie info</span>
                    </span>
                </a>
            <?php else: ?>
                <a href="<?php echo $baseUrl; ?>/accountant/" class="nav-item <?php echo $currentPage === 'index' ? 'active' : ''; ?>" data-tooltip="Dashboard">
                    <svg class="nav-icon" viewBox="0 0 24 24"><rect x="3" y="3" width="7" height="9" rx="1"/><rect x="14" y="3" width="7" height="5" rx="1"/><rect x="14" y="12" width="7" height="9" rx="1"/><rect x="3" y="16" width="7" height="5" rx="1"/></svg>
                    <span class="nav-content">
                        <span class="nav-title">Dashboard</span>
                        <span class="nav-sub">Panoramica</span>
                    </span>
                </a>
                <a href="<?php echo $baseUrl; ?>/accountant/employees.php" class="nav-item <?php echo $currentPage === 'employees' ? 'active' : ''; ?>" data-tooltip="Anagrafica">
                    <svg class="nav-icon" viewBox="0 0 24 24"><path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M22 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg>
                    <span class="nav-content">
                        <span class="nav-title">Anagrafica</span>
                        <span class="nav-sub">Dipendenti</span>
                    </span>
                </a>
                <a href="<?php echo $baseUrl; ?>/accountant/documents.php" class="nav-item <?php echo $currentPage === 'documents' ? 'active' : ''; ?>" data-tooltip="Buste paga/CU">
                    <svg class="nav-icon" viewBox="0 0 24 24"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/></svg>
                    <span class="nav-content">
                        <span class="nav-title">Buste paga/CU</span>
                        <span class="nav-sub">Carica documenti</span>
                    </span>
                </a>
                <a href="<?php echo $baseUrl; ?>/accountant/employee-documents.php" class="nav-item <?php echo $currentPage === 'employee-documents' ? 'active' : ''; ?>" data-tooltip="Documenti dipendente">
                    <svg class="nav-icon" viewBox="0 0 24 24"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="9" y1="13" x2="15" y2="13"/><line x1="9" y1="17" x2="13" y2="17"/></svg>
                    <span class="nav-content">
                        <span class="nav-title">Documenti</span>
                        <span class="nav-sub">Caricati dal dipendente</span>
                    </span>
                </a>
                <a href="<?php echo $baseUrl; ?>/accountant/leave-requests.php" class="nav-item <?php echo $currentPage === 'leave-requests' ? 'active' : ''; ?>" data-tooltip="Ferie e Permessi">
                    <svg class="nav-icon" viewBox="0 0 24 24"><rect x="3" y="4" width="18" height="18" rx="2"/><path d="M16 2v4M8 2v4M3 10h18"/></svg>
                    <span class="nav-content">
                        <span class="nav-title">Ferie/Permessi</span>
                        <span class="nav-sub">Storico richieste</span>
                    </span>
                </a>
                <a href="<?php echo $baseUrl; ?>/accountant/presenze-export.php" class="nav-item <?php echo $currentPage === 'presenze-export' ? 'active' : ''; ?>" data-tooltip="Presenze">
                    <svg class="nav-icon" viewBox="0 0 24 24"><path d="M3 3v18h18"/><path d="M7 16V8m5 8V4m5 12v-6"/></svg>
                    <span class="nav-content">
                        <span class="nav-title">Presenze</span>
                        <span class="nav-sub">Export mensile</span>
                    </span>
                </a>
                <a href="<?php echo $baseUrl; ?>/accountant/chat.php" class="nav-item <?php echo $currentPage === 'chat' ? 'active' : ''; ?>" data-tooltip="Chat">
                    <svg class="nav-icon" viewBox="0 0 24 24"><path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/></svg>
                    <span class="nav-content">
                        <span class="nav-title">Chat</span>
                        <span class="nav-sub"><?php echo $unreadChats > 0 ? $unreadChats . ' non letti' : 'Messaggi'; ?></span>
                    </span>
                    <?php if ($unreadChats > 0): ?><span class="nav-pulse"></span><?php endif; ?>
                </a>
                <a href="<?php echo $baseUrl; ?>/accountant/calendar.php" class="nav-item <?php echo $currentPage === 'calendar' ? 'active' : ''; ?>" data-tooltip="Calendario">
                    <svg class="nav-icon" viewBox="0 0 24 24"><rect x="3" y="4" width="18" height="18" rx="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg>
                    <span class="nav-content">
                        <span class="nav-title">Calendario</span>
                        <span class="nav-sub"><?php echo $pendingInvites > 0 ? $pendingInvites . ' ' . ($pendingInvites === 1 ? 'invito' : 'inviti') . ' da confermare' : 'Eventi e riunioni'; ?></span>
                    </span>
                    <?php if ($pendingInvites > 0): ?><span class="nav-pulse" title="<?php echo $pendingInvites; ?>"></span><?php endif; ?>
                </a>
                <a href="<?php echo $baseUrl; ?>/accountant/profile.php" class="nav-item <?php echo $currentPage === 'profile' ? 'active' : ''; ?>" data-tooltip="Profilo">
                    <svg class="nav-icon" viewBox="0 0 24 24"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>
                    <span class="nav-content">
                        <span class="nav-title">Profilo</span>
                        <span class="nav-sub">Le mie info</span>
                    </span>
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
            <?php if (count($__tenants) >= 1):
                $__currentActive = !empty($__currentTenant['is_active']);
            ?>
            <div class="tenant-switcher tenant-switcher-top" id="sidebar-tenant">
                <button type="button" class="tenant-switcher-row <?php echo $__otherActivity > 0 ? 'has-other-activity' : ''; ?>" id="sidebar-tenant-row" aria-haspopup="true" aria-expanded="false" title="<?php echo $__otherActivity > 0 ? $__otherActivity . ' richiest' . ($__otherActivity===1?'a':'e') . ' in attesa in altre aziende' : ($__currentActive ? 'Attiva' : 'Non attiva'); ?>">
                    <span class="tenant-status-dot <?php echo $__currentActive ? 'is-active' : 'is-inactive'; ?> <?php echo $__otherActivity > 0 ? 'is-pulsing' : ''; ?>"></span>
                    <span class="tenant-label">Azienda:</span>
                    <span class="tenant-name"><?php echo htmlspecialchars($__currentTenant['name'] ?? 'Azienda'); ?></span>
                    <?php if ($__otherActivity > 0): ?><span class="tenant-other-badge"><?php echo (int)$__otherActivity; ?></span><?php endif; ?>
                    <svg class="tenant-caret" viewBox="0 0 24 24" fill="currentColor"><path d="M7.41 8.59L12 13.17l4.59-4.58L18 10l-6 6-6-6 1.41-1.41z"/></svg>
                </button>
                <div class="tenant-menu">
                    <?php foreach ($__tenants as $__t):
                        $__isActive = (int)$__t['id'] === (int)($__currentTenant['id'] ?? 0);
                        $__tActive = !empty($__t['is_active']);
                        $__tActivity = (int)($__activityByCompany[(int)$__t['id']] ?? 0);
                        $__hasOtherAct = !$__isActive && $__tActivity > 0;
                    ?>
                        <div class="tenant-menu-row" style="display:flex; align-items:stretch;">
                            <form method="POST" action="<?php echo $baseUrl; ?>/auth/switch-tenant.php" style="flex:1; min-width:0;">
                                <?php echo CSRF::field(); ?>
                                <button type="submit" name="id" value="<?php echo (int)$__t['id']; ?>" class="tenant-menu-item <?php echo $__isActive ? 'active' : ''; ?>" style="width:100%; border:0; cursor:pointer; text-align:left; background:transparent;">
                                    <span class="tenant-status-dot <?php echo $__tActive ? 'is-active' : 'is-inactive'; ?> <?php echo $__hasOtherAct ? 'is-pulsing-red' : ''; ?>" title="<?php echo $__hasOtherAct ? $__tActivity . ' notifiche in attesa' : ($__tActive ? 'Attiva' : 'Non attiva'); ?>"></span>
                                    <div class="info">
                                        <div class="n"><?php echo htmlspecialchars($__t['name']); ?></div>
                                        <?php if (!$__tActive): ?><div class="s">Sospesa</div><?php endif; ?>
                                    </div>
                                    <?php if ($__hasOtherAct): ?>
                                        <span class="tenant-row-badge" title="<?php echo $__tActivity; ?> notifiche"><?php echo $__tActivity; ?></span>
                                    <?php endif; ?>
                                    <?php if ($__isActive): ?>
                                        <svg class="check" viewBox="0 0 24 24" fill="currentColor"><path d="M9 16.17L4.83 12l-1.42 1.41L9 19 21 7l-1.41-1.41z"/></svg>
                                    <?php endif; ?>
                                </button>
                            </form>
                            <?php if ($__canLeaveTenant): ?>
                                <form method="POST" action="<?php echo $baseUrl; ?>/auth/leave-tenant.php" style="display:flex;" onsubmit="return confirm('Rimuoverti dall\'azienda &quot;<?php echo htmlspecialchars(addslashes($__t['name']), ENT_QUOTES); ?>&quot;? Perderai l\'accesso ai dati. L\'admin potra reinvitarti in futuro.');">
                                    <?php echo CSRF::field(); ?>
                                    <input type="hidden" name="company_id" value="<?php echo (int)$__t['id']; ?>">
                                    <button type="submit" class="tenant-leave-btn" title="Esci da questa azienda (fine mandato)" aria-label="Esci da <?php echo htmlspecialchars($__t['name']); ?>">
                                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="18" y1="6" x2="6" y2="18"></line><line x1="6" y1="6" x2="18" y2="18"></line></svg>
                                    </button>
                                </form>
                            <?php endif; ?>
                        </div>
                    <?php endforeach; ?>
                    <?php if ($isAdmin): ?>
                        <a href="<?php echo $baseUrl; ?>/admin/companies.php" class="tenant-menu-item" style="border-top:1px solid var(--border);">
                            <svg viewBox="0 0 24 24" fill="currentColor" style="width:16px;height:16px;color:var(--muted);margin-right:var(--sp-2);"><path d="M19.14 12.94c.04-.3.06-.61.06-.94 0-.32-.02-.64-.07-.94l2.03-1.58c.18-.14.23-.41.12-.61l-1.92-3.32c-.12-.22-.37-.29-.59-.22l-2.39.96c-.5-.38-1.03-.7-1.62-.94l-.36-2.54c-.04-.24-.24-.41-.48-.41h-3.84c-.24 0-.43.17-.47.41l-.36 2.54c-.59.24-1.13.57-1.62.94l-2.39-.96c-.22-.08-.47 0-.59.22L2.74 8.87c-.12.21-.08.47.12.61l2.03 1.58c-.05.3-.09.63-.09.94s.02.64.07.94l-2.03 1.58c-.18.14-.23.41-.12.61l1.92 3.32c.12.22.37.29.59.22l2.39-.96c.5.38 1.03.7 1.62.94l.36 2.54c.05.24.24.41.48.41h3.84c.24 0 .44-.17.47-.41l.36-2.54c.59-.24 1.13-.56 1.62-.94l2.39.96c.22.08.47 0 .59-.22l1.92-3.32c.12-.22.07-.47-.12-.61l-2.01-1.58zM12 15.6c-1.98 0-3.6-1.62-3.6-3.6s1.62-3.6 3.6-3.6 3.6 1.62 3.6 3.6-1.62 3.6-3.6 3.6z"/></svg>
                            <div class="info"><div class="n">Gestisci aziende</div></div>
                        </a>
                    <?php endif; ?>
                    <div class="tenant-menu-footer"><?php echo count($__tenants); ?> aziende assegnate</div>
                </div>
            </div>
            <?php endif; ?>
            <div class="header-spacer" style="flex:1;"></div>
            <div class="header-actions">
                <button id="enableNotifications" class="header-btn" title="Attiva notifiche" style="display:none;">
                    <svg viewBox="0 0 24 24" fill="currentColor"><path d="M12 22c1.1 0 2-.9 2-2h-4c0 1.1.89 2 2 2zm6-6v-5c0-3.07-1.64-5.64-4.5-6.32V4c0-.83-.67-1.5-1.5-1.5s-1.5.67-1.5 1.5v.68C7.63 5.36 6 7.92 6 11v5l-2 2v1h16v-1l-2-2z"/></svg>
                </button>
                <a href="<?php echo $baseUrl; ?>/<?php echo $area; ?>/profile.php" class="user-chip user-chip-link user-chip-photo-only" title="<?php echo $userName; ?>">
                    <div class="user-chip-avatar">
                        <?php if (!empty($currentUser['photo_path'])): ?>
                            <img src="<?php echo $baseUrl . '/' . ltrim($currentUser['photo_path'], '/'); ?>" alt="<?php echo $userName; ?>" loading="lazy" decoding="async">
                        <?php else: ?>
                            <?php echo $__userInitials; ?>
                        <?php endif; ?>
                    </div>
                </a>
            </div>
        </header>

        <!-- Modal Export Presenze -->
        <div id="presenzeModalOverlay" class="pres-modal-overlay" hidden>
            <div class="pres-modal" role="dialog" aria-labelledby="presModalTitle" aria-modal="true">
                <div class="pres-modal-h">
                    <div>
                        <h3 id="presModalTitle">Export presenze</h3>
                        <p>Scarica il file Excel mensile (ferie, ROL, malattia).</p>
                    </div>
                    <button type="button" class="pres-close" onclick="closePresenzeModal()" aria-label="Chiudi">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
                    </button>
                </div>
                <form method="get" action="<?php echo $baseUrl; ?>/admin/presenze-export.php" class="pres-modal-form" onsubmit="closePresenzeModal();">
                    <input type="hidden" name="action" value="download">
                    <div class="pres-grid">
                        <label>
                            <span>Mese</span>
                            <select name="month" id="presMonth">
                                <?php
                                $__pmList = ['','Gennaio','Febbraio','Marzo','Aprile','Maggio','Giugno','Luglio','Agosto','Settembre','Ottobre','Novembre','Dicembre'];
                                $__pmNow  = (int) date('n');
                                for ($__m = 1; $__m <= 12; $__m++): ?>
                                    <option value="<?= $__m ?>" <?= $__m === $__pmNow ? 'selected' : '' ?>><?= $__pmList[$__m] ?></option>
                                <?php endfor; ?>
                            </select>
                        </label>
                        <label>
                            <span>Anno</span>
                            <select name="year" id="presYear">
                                <?php
                                $__pyNow = (int) date('Y');
                                for ($__y = $__pyNow - 1; $__y <= $__pyNow + 1; $__y++): ?>
                                    <option value="<?= $__y ?>" <?= $__y === $__pyNow ? 'selected' : '' ?>><?= $__y ?></option>
                                <?php endfor; ?>
                            </select>
                        </label>
                    </div>
                    <div class="pres-actions">
                        <button type="button" class="pres-btn pres-btn-ghost" onclick="closePresenzeModal()">Annulla</button>
                        <button type="submit" class="pres-btn pres-btn-primary">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" width="14" height="14"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/></svg>
                            Scarica Excel
                        </button>
                    </div>
                </form>
            </div>
        </div>

        <style>
        .pres-modal-overlay {
            position: fixed; inset: 0; z-index: 2000;
            background: rgba(15,23,42,0.45);
            backdrop-filter: blur(4px);
            display: flex; align-items: center; justify-content: center;
            padding: 16px;
            animation: presFade .15s ease;
        }
        .pres-modal-overlay[hidden] { display: none !important; }
        @keyframes presFade { from { opacity: 0; } to { opacity: 1; } }
        .pres-modal {
            background: white;
            border-radius: 16px;
            width: 100%; max-width: 460px;
            box-shadow: 0 24px 64px rgba(15,23,42,0.25);
            overflow: hidden;
            animation: presPop .18s ease;
        }
        @keyframes presPop { from { transform: scale(0.96); opacity: 0; } to { transform: scale(1); opacity: 1; } }
        .pres-modal-h {
            display: flex; justify-content: space-between; align-items: flex-start;
            padding: 22px 24px 8px;
            gap: 12px;
        }
        .pres-modal-h h3 {
            margin: 0 0 4px;
            font-family: 'Host Grotesk','Inter',sans-serif;
            font-size: 18px; font-weight: 700; color: #0f172a;
            letter-spacing: -0.01em;
        }
        .pres-modal-h p { margin: 0; font-size: 13px; color: #64748b; }
        .pres-close {
            width: 32px; height: 32px;
            border: none; background: transparent;
            color: #94a3b8; border-radius: 8px;
            cursor: pointer; flex-shrink: 0;
            display: flex; align-items: center; justify-content: center;
        }
        .pres-close:hover { background: #f1f5f9; color: #0f172a; }
        .pres-close svg { width: 18px; height: 18px; }
        .pres-modal-form { padding: 16px 24px 22px; }
        .pres-grid {
            display: grid; grid-template-columns: 1fr 120px; gap: 12px;
            margin-bottom: 20px;
        }
        .pres-grid label { display: flex; flex-direction: column; gap: 6px; }
        .pres-grid label span {
            font-size: 11px; font-weight: 600; color: #64748b;
            text-transform: uppercase; letter-spacing: 0.04em;
        }
        .pres-grid select {
            padding: 10px 12px;
            border: 1px solid #e2e8f0;
            border-radius: 8px;
            font-family: inherit; font-size: 14px;
            background: white;
            transition: border-color .12s ease, box-shadow .12s ease;
        }
        .pres-grid select:focus {
            outline: none; border-color: #0b3aa4;
            box-shadow: 0 0 0 3px rgba(11,58,164,0.10);
        }
        .pres-actions { display: flex; gap: 10px; justify-content: flex-end; }
        .pres-btn {
            display: inline-flex; align-items: center; gap: 8px;
            padding: 9px 16px; border-radius: 8px;
            font-family: inherit; font-size: 13px; font-weight: 600;
            border: 1px solid transparent; cursor: pointer;
            transition: all .12s ease;
        }
        .pres-btn-primary { background: #0b3aa4; color: white; border-color: #0b3aa4; }
        .pres-btn-primary:hover { background: #0b3aa4; border-color: #0b3aa4; }
        .pres-btn-ghost { background: white; color: #475569; border-color: #e2e8f0; }
        .pres-btn-ghost:hover { border-color: #475569; color: #0f172a; }
        </style>

        <script>
        function openPresenzeModal() {
            const o = document.getElementById('presenzeModalOverlay');
            if (o) { o.hidden = false; document.body.style.overflow = 'hidden'; }
        }
        function closePresenzeModal() {
            const o = document.getElementById('presenzeModalOverlay');
            if (o) { o.hidden = true; document.body.style.overflow = ''; }
        }
        document.addEventListener('keydown', e => { if (e.key === 'Escape') closePresenzeModal(); });
        document.getElementById('presenzeModalOverlay')?.addEventListener('click', e => {
            if (e.target.id === 'presenzeModalOverlay') closePresenzeModal();
        });
        </script>

        <main class="app-content">
