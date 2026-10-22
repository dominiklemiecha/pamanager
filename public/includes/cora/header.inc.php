<?php
/**
 * Shell "Cora" della vista dipendente: head, contenitore pagina.
 * Incluso da header-employee.php (che ha gia' calcolato contatori, foto e
 * stato) quando CoraUi::enabled(). Il dock e gli script sono nel footer.
 *
 * Le pagine ridisegnate impostano $coraPage = true e disegnano da sole la
 * barra in alto con CoraUi::topbar(). Le altre (Academy, Calendario, Chat...)
 * mantengono il loro contenuto e i CSS classici dentro la nuova shell.
 */
// Accesso diretto al file: niente
if (!class_exists('CoraUi', false)) { http_response_code(404); exit; }

$__coraRedesigned = !empty($coraPage);

$__notifs = [];
$__notifUnread = 0;
if (class_exists('Notification')) {
    try {
        $__notifs = Notification::getByUser('employee', (int) $currentEmployee['id'], false, 8);
        $__notifUnread = Notification::countUnread('employee', (int) $currentEmployee['id']);
    } catch (Throwable $__e) {}
}

CoraUi::$shell = [
    'photo'        => $employeePhoto,
    'initials'     => $employeeInitials,
    'name'         => $employeeName,
    'notifs'       => $__notifs,
    'notifUnread'  => $__notifUnread,
    'availability' => $employeeAvailability,
];

$__title = isset($pageTitle) ? (string) $pageTitle : 'Cora';
?>
<!DOCTYPE html>
<html lang="it">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
    <meta name="robots" content="noindex, nofollow">
    <title><?= htmlspecialchars($__title) ?> · Cora</title>

    <link rel="manifest" href="<?= $baseUrl ?>/manifest-cora.json.php">
    <link rel="icon" href="<?= $baseUrl ?>/assets/images/cora-app/favicon.ico?v=1" sizes="48x48">
    <link rel="icon" href="<?= $baseUrl ?>/assets/images/cora-app/favicon.svg?v=1" type="image/svg+xml">
    <link rel="apple-touch-icon" href="<?= $baseUrl ?>/assets/images/cora-app/apple-touch-icon.png?v=1">
    <meta name="apple-mobile-web-app-title" content="Cora">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-status-bar-style" content="default">
    <meta name="theme-color" content="#f6f8f7">

    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Host+Grotesk:wght@400;500;600;700&display=swap" rel="stylesheet">
    <?php if (!$__coraRedesigned): ?>
    <link rel="stylesheet" href="https://rsms.me/inter/inter.css">
    <link rel="stylesheet" href="<?= $baseUrl ?>/assets/css/theme.css?v=<?= time() ?>">
    <link rel="stylesheet" href="<?= $baseUrl ?>/assets/css/components.css?v=<?= time() ?>">
    <link rel="stylesheet" href="<?= $baseUrl ?>/assets/css/style.css?v=<?= time() ?>">
    <link rel="stylesheet" href="<?= $baseUrl ?>/assets/css/mobile-staff.css?v=<?= time() ?>">
    <?php endif; ?>
    <link rel="stylesheet" href="<?= $baseUrl ?>/assets/css/cora.css?v=<?= time() ?>">
    <link rel="stylesheet" href="<?= $baseUrl ?>/assets/css/cora-pages.css?v=<?= time() ?>">
    <?php foreach ((array) ($coraExtraCss ?? []) as $__css): ?>
    <link rel="stylesheet" href="<?= $baseUrl ?>/assets/css/<?= htmlspecialchars(basename($__css)) ?>?v=<?= time() ?>">
    <?php endforeach; ?>

    <?= CSRF::metaTag() ?>
    <script>window.PAM = { baseUrl: '<?= $baseUrl ?>' };</script>
</head>
<body class="cora-body<?= $__coraRedesigned ? '' : ' cora-legacy employee-body' ?>" data-page="<?= htmlspecialchars($currentPage) ?>">
<div class="cora-app">
<?php if ($__coraRedesigned): ?>
    <main class="cora-main">
<?php else: ?>
    <main class="cora-main">
        <?= CoraUi::topbar($__title) ?>
        <div class="app-content cora-legacy-content">
<?php endif; ?>
