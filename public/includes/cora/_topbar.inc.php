<?php
/**
 * Barra in alto delle pagine Cora. Variabili da CoraUi::topbar():
 * $title, $subtitle, $actionsHtml (HTML gia' sicuro), $centerHtml (idem).
 */
// Accesso diretto al file: niente
if (!class_exists('CoraUi', false)) { http_response_code(404); exit; }
$__s = CoraUi::$shell;
$__base = PUBLIC_URL;
?>
<header class="cora-topbar">
    <a href="<?= $__base ?>/employee/" class="cora-topbar-logo" aria-label="Cora · Home"><?= CoraUi::svg('logo-cora-mobile.svg') ?></a>
    <div class="cora-topbar-title">
        <h1><?= htmlspecialchars($title) ?></h1>
        <?php if ($subtitle !== ''): ?><p><?= htmlspecialchars($subtitle) ?></p><?php endif; ?>
    </div>
    <?php if ($centerHtml !== ''): ?><div class="cora-topbar-center"><?= $centerHtml ?></div><?php endif; ?>
    <?php if ($actionsHtml !== ''): ?><div class="cora-topbar-actions"><?= $actionsHtml ?></div><?php endif; ?>
    <div class="cora-topbar-user">
        <div class="cora-bell" data-cora-bell>
            <button type="button" class="cora-icon-btn" aria-haspopup="true" aria-expanded="false" aria-label="Notifiche">
                <?= CoraUi::svg('ic-bell.svg') ?>
                <?php if (($__s['notifUnread'] ?? 0) > 0): ?><span class="cora-bell-dot" data-cora-bell-dot></span><?php endif; ?>
            </button>
            <div class="cora-bell-menu" role="menu">
                <div class="cora-bell-head">Notifiche</div>
                <?php if (empty($__s['notifs'])): ?>
                    <div class="cora-bell-empty">Nessuna notifica</div>
                <?php else: foreach ($__s['notifs'] as $__n):
                    $__link = Notification::url($__n['link'] ?? '');
                ?>
                    <a class="cora-bell-item<?= empty($__n['is_read']) ? ' is-unread' : '' ?>" href="<?= htmlspecialchars($__link !== '' ? $__link : '#') ?>">
                        <span class="t"><?= htmlspecialchars((string) ($__n['title'] ?? '')) ?></span>
                        <?php if (!empty($__n['message'])): ?><span class="m"><?= htmlspecialchars((string) $__n['message']) ?></span><?php endif; ?>
                        <span class="d"><?= htmlspecialchars(CoraUi::ago($__n['created_at'] ?? null)) ?></span>
                    </a>
                <?php endforeach; endif; ?>
            </div>
        </div>
        <a href="<?= $__base ?>/employee/profile.php" class="cora-avatar" data-tour="profile-link" title="<?= $__s['name'] ?? '' ?>">
            <?php if (!empty($__s['photo'])): ?>
                <img src="<?= htmlspecialchars($__s['photo']) ?>" alt="" decoding="async">
            <?php else: ?>
                <span><?= htmlspecialchars($__s['initials'] ?? '') ?></span>
            <?php endif; ?>
        </a>
    </div>
</header>
