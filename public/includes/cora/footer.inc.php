<?php
/**
 * Chiusura shell Cora: dock di navigazione, script.
 * Usa i contatori calcolati da header-employee.php.
 */
// Accesso diretto al file: niente
if (!class_exists('CoraUi', false)) { http_response_code(404); exit; }
$__redesigned = !empty($coraPage);
$__cp = basename($_SERVER['PHP_SELF'], '.php');
$__b = PUBLIC_URL;

$__dock = [
    'index'          => ['Home',       'dock-home.svg',       '/employee/',                    false, 0],
    'documents'      => ['Documenti',  'dock-documenti.svg',  '/employee/documents.php',       !empty($unreadDocsCount), 0],
    'leave-requests' => ['Richieste',  'dock-richieste.svg',  '/employee/leave-requests.php',  false, 0],
    'communications' => ['Avvisi',     'dock-avvisi.svg',     '/employee/communications.php',  !empty($unreadCommCount), 0],
    'academy'        => ['Academy',    'dock-academy.svg',    '/employee/academy.php',         !empty($academyToDo) || !empty($academyNewCerts), 0],
    'calendar'       => ['Calendario', 'dock-calendario.svg', '/employee/calendar.php',        !empty($pendingInvites), 0],
    'chat'           => ['Chat',       'dock-chat.svg',       '/employee/chat.php',            false, (int) ($unreadChats ?? 0)],
];
// Anello dei moduli della suite dietro la voce "Cora" del dock
$__modules = [
    ['Cora', 'cora'], ['Fase', 'fase'], ['Banche', 'lock'], ['Analytics', 'lock'], ['Chat', 'lock'], ['Academy', 'lock'],
];
?>
<?php if (!$__redesigned): ?>
        </div>
<?php endif; ?>
    </main>
</div>

<nav class="cora-dock" data-tour="nav-desktop" aria-label="Menu principale">
    <div class="cora-dock-items">
        <?php foreach ($__dock as $__key => [$__label, $__icon, $__href, $__dot, $__badge]):
            $__active = $__cp === $__key; ?>
            <a class="cora-dock-item<?= $__active ? ' is-active' : '' ?>" href="<?= $__b . $__href ?>"<?= $__active ? ' aria-current="page"' : '' ?>>
                <span class="cora-dock-icon"><?= CoraUi::svg($__icon, true) ?></span>
                <span class="cora-dock-label"><?= $__label ?></span>
                <?php if ($__badge > 0): ?>
                    <span class="cora-dock-badge"><?= $__badge > 9 ? '9+' : $__badge ?></span>
                <?php elseif ($__dot): ?>
                    <span class="cora-dock-dot"></span>
                <?php endif; ?>
            </a>
        <?php endforeach; ?>
    </div>
    <span class="cora-dock-sep" aria-hidden="true"></span>
    <div class="cora-dock-cora" data-cora-modules>
        <button type="button" class="cora-dock-item" aria-expanded="false" aria-label="Moduli Cora">
            <span class="cora-dock-icon cora-dock-logo"><?= CoraUi::svg('dock-cora.svg') ?></span>
            <span class="cora-dock-label">Cora</span>
        </button>
        <div class="cora-modules" aria-hidden="true">
            <?php foreach ($__modules as $__i => [$__mName, $__mKind]):
                $__angle = -90 + $__i * (360 / count($__modules)); ?>
                <<?= $__mKind === 'cora' ? 'a href="' . $__b . '/employee/"' : 'span' ?> class="cora-module is-<?= $__mKind ?>" style="--a: <?= $__angle ?>deg">
                    <span class="cora-module-ic"><?= CoraUi::svg('mod-' . $__mKind . '.svg') ?></span>
                    <span class="cora-module-name"><?= $__mName ?></span>
                </<?= $__mKind === 'cora' ? 'a' : 'span' ?>>
            <?php endforeach; ?>
        </div>
    </div>
</nav>

<?php
// Barra del telefono (Figma 1068:3247): Home, Richieste, Cora, Documenti, Altro.
// "Altro" apre il foglio con le sezioni che non ci stanno.
$__tab = ['index', 'leave-requests', 'cora', 'documents', 'altro'];
$__more = ['communications', 'calendar', 'chat', 'academy'];
$__moreActive = in_array($__cp, $__more, true) || $__cp === 'profile';
$__moreDot = false;
foreach ($__more as $__k) { if ($__dock[$__k][3] || $__dock[$__k][4] > 0) $__moreDot = true; }
?>
<nav class="cora-tabbar" data-tour="nav-mobile" aria-label="Menu principale">
    <?php foreach ($__tab as $__key):
        if ($__key === 'cora'): ?>
            <div class="cora-tabbar-cora cora-dock-cora" data-cora-modules>
                <button type="button" class="cora-tabbar-btn" aria-expanded="false" aria-label="Moduli Cora"><?= CoraUi::svg('logo-cora-white-20.svg') ?></button>
                <div class="cora-modules" aria-hidden="true">
                    <?php foreach ($__modules as $__i => [$__mName, $__mKind]):
                        $__angle = -90 + $__i * (360 / count($__modules)); ?>
                        <<?= $__mKind === 'cora' ? 'a href="' . $__b . '/employee/"' : 'span' ?> class="cora-module is-<?= $__mKind ?>" style="--a: <?= $__angle ?>deg">
                            <span class="cora-module-ic"><?= CoraUi::svg('mod-' . $__mKind . '.svg') ?></span>
                            <span class="cora-module-name"><?= $__mName ?></span>
                        </<?= $__mKind === 'cora' ? 'a' : 'span' ?>>
                    <?php endforeach; ?>
                </div>
            </div>
        <?php elseif ($__key === 'altro'): ?>
            <button type="button" class="cora-dock-item<?= $__moreActive ? ' is-active' : '' ?>" data-cora-more aria-haspopup="dialog" aria-expanded="false">
                <span class="cora-dock-icon"><?= CoraUi::svg('dock-altro.svg', true) ?></span>
                <span class="cora-dock-label">Altro</span>
                <?php if ($__moreDot): ?><span class="cora-dock-dot" data-cora-more-dot></span><?php endif; ?>
            </button>
        <?php else:
            [$__label, $__icon, $__href, $__dot, $__badge] = $__dock[$__key];
            $__active = $__cp === $__key; ?>
            <a class="cora-dock-item<?= $__active ? ' is-active' : '' ?>" href="<?= $__b . $__href ?>"<?= $__active ? ' aria-current="page"' : '' ?>>
                <span class="cora-dock-icon"><?= CoraUi::svg($__icon, true) ?></span>
                <span class="cora-dock-label"><?= $__label ?></span>
                <?php if ($__dot): ?><span class="cora-dock-dot"></span><?php endif; ?>
            </a>
        <?php endif;
    endforeach; ?>
</nav>

<div class="cora-more" data-cora-more-sheet hidden>
    <div class="cora-more-box" role="dialog" aria-modal="true" aria-label="Altre sezioni">
        <div class="cora-more-grip" aria-hidden="true"></div>
        <?php foreach (array_merge($__more, ['profile']) as $__key):
            [$__label, $__icon, $__href, $__dot, $__badge] = $__key === 'profile'
                ? ['Profilo', '', '/employee/profile.php', false, 0] : $__dock[$__key];
            $__active = $__cp === $__key; ?>
            <a class="cora-more-item<?= $__active ? ' is-active' : '' ?>" href="<?= $__b . $__href ?>"<?= $__active ? ' aria-current="page"' : '' ?>>
                <span class="cora-more-ic">
                    <?php if ($__key === 'profile'): ?>
                        <span class="cora-avatar"><?php if (!empty(CoraUi::$shell['photo'])): ?><img src="<?= htmlspecialchars(CoraUi::$shell['photo']) ?>" alt=""><?php else: ?><?= htmlspecialchars(CoraUi::$shell['initials'] ?? '') ?><?php endif; ?></span>
                    <?php else: ?>
                        <?= CoraUi::svg($__icon, true) ?>
                    <?php endif; ?>
                </span>
                <span class="cora-more-label"><?= $__label ?></span>
                <?php if ($__badge > 0): ?>
                    <span class="cora-dock-badge"><?= $__badge > 9 ? '9+' : $__badge ?></span>
                <?php elseif ($__dot): ?>
                    <span class="cora-more-dot"></span>
                <?php endif; ?>
                <span class="cora-more-chev"><?= CoraUi::svg('ic-chev-r.svg') ?></span>
            </a>
        <?php endforeach; ?>
    </div>
</div>

<div class="cora-toast-host" id="coraToasts" aria-live="polite"></div>

<script src="<?= $__b ?>/assets/js/app.js?v=<?= time() ?>"></script>
<script src="<?= $__b ?>/assets/js/push.js?v=<?= time() ?>"></script>
<script src="<?= $__b ?>/assets/js/availability-toggle.js?v=<?= time() ?>"></script>
<script src="<?= $__b ?>/assets/js/cora.js?v=<?= time() ?>"></script>
<?php
$__tourEmpId = (int) ($currentEmployee['id'] ?? 0);
$__tourCfg = [
    'employeeId' => $__tourEmpId,
    'session'    => (string) ($_SESSION['login_time'] ?? ''),
    'status'     => EmployeeTour::getStatus($__tourEmpId),
    'page'       => $__cp,
    'figmaHome'  => false,
    'cora'       => true,
    'api'        => $__b . '/api/employee-tour.php',
    'baseUrl'    => $__b . '/employee/',
];
?>
<link rel="stylesheet" href="<?= $__b ?>/assets/css/employee-tour.css?v=<?= time() ?>">
<script>window.PAM_TOUR = <?= json_encode($__tourCfg, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;</script>
<script src="<?= $__b ?>/assets/js/employee-tour.js?v=<?= time() ?>"></script>
<?= ConnecteedWidget::scriptTag() ?>
</body>
</html>
