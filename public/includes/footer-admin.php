<?php
/**
 * Footer Admin/Commercialista/Consulente
 * Chiude app-content/app-main/app, aggiunge bottom-nav mobile + sheet
 */
$baseUrl = PUBLIC_URL;
$__role = Auth::isAdmin() ? 'admin' : ((Auth::getUser()['role'] ?? '') === 'consulente_lavoro' ? 'consulente-lavoro' : 'accountant');
$__currPage = basename($_SERVER['PHP_SELF'], '.php');
?>
        </main>
        <div class="powered">
            Powered by
            <a href="https://www.connecteed.com" target="_blank" rel="noopener">
                <img src="https://www.connecteed.com/assets/Logon-BsucV_4E.svg" alt="Connecteed">
            </a>
        </div>
    </div>
</div>

<!-- Bottom nav mobile -->
<nav class="bottom-nav" aria-label="Menu principale">
    <div class="bottom-nav-grid">
        <a class="bn-item <?php echo $__currPage === 'index' ? 'active' : ''; ?>" href="<?php echo $baseUrl; ?>/<?php echo $__role; ?>/">
            <div class="bn-icon"><svg viewBox="0 0 24 24" fill="currentColor"><path d="M13 3v6h8V3h-8zM3 21h8V11H3v10zM3 9h8V3H3v6zm10 12h8V11h-8v10z"/></svg></div>
            <span class="bn-label">Home</span>
        </a>
        <a class="bn-item <?php echo $__currPage === 'employees' ? 'active' : ''; ?>" href="<?php echo $baseUrl; ?>/<?php echo $__role; ?>/employees.php">
            <div class="bn-icon"><svg viewBox="0 0 24 24" fill="currentColor"><path d="M16 11c1.66 0 2.99-1.34 2.99-3S17.66 5 16 5c-1.66 0-3 1.34-3 3s1.34 3 3 3zm-8 0c1.66 0 2.99-1.34 2.99-3S9.66 5 8 5C6.34 5 5 6.34 5 8s1.34 3 3 3zm0 2c-2.33 0-7 1.17-7 3.5V19h14v-2.5c0-2.33-4.67-3.5-7-3.5z"/></svg></div>
            <span class="bn-label">Dipendenti</span>
        </a>
        <a class="bn-item <?php echo $__currPage === 'leave-requests' ? 'active' : ''; ?>" href="<?php echo $baseUrl; ?>/<?php echo $__role; ?>/leave-requests.php">
            <div class="bn-icon"><svg viewBox="0 0 24 24" fill="currentColor"><path d="M19 3h-4.18C14.4 1.84 13.3 1 12 1c-1.3 0-2.4.84-2.82 2H5c-1.1 0-2 .9-2 2v14c0 1.1.9 2 2 2h14c1.1 0 2-.9 2-2V5c0-1.1-.9-2-2-2z"/></svg></div>
            <span class="bn-label">Ferie</span>
            <?php if (!empty($pendingLeaveAdmin) && $pendingLeaveAdmin > 0): ?>
                <span class="bn-badge"><?php echo $pendingLeaveAdmin; ?></span>
            <?php endif; ?>
        </a>
        <a class="bn-item <?php echo $__currPage === 'chat' ? 'active' : ''; ?>" href="<?php echo $baseUrl; ?>/<?php echo $__role; ?>/chat.php">
            <div class="bn-icon"><svg viewBox="0 0 24 24" fill="currentColor"><path d="M20 2H4c-1.1 0-2 .9-2 2v18l4-4h14c1.1 0 2-.9 2-2V4c0-1.1-.9-2-2-2z"/></svg></div>
            <span class="bn-label">Chat</span>
            <?php if (!empty($unreadChats) && $unreadChats > 0): ?>
                <span class="bn-badge"><?php echo $unreadChats; ?></span>
            <?php endif; ?>
        </a>
        <button class="bn-item" id="bn-more" type="button">
            <div class="bn-icon"><svg viewBox="0 0 24 24" fill="currentColor"><path d="M4 6h16v2H4zm0 5h16v2H4zm0 5h16v2H4z"/></svg></div>
            <span class="bn-label">Altro</span>
        </button>
    </div>
</nav>

<div class="sheet-backdrop" id="sheet-backdrop"></div>
<div class="sheet" id="sheet">
    <div class="sheet-handle"></div>
    <div class="sheet-h">
        <h3>Menu</h3>
        <button class="sheet-close" id="sheet-close" aria-label="Chiudi">
            <svg viewBox="0 0 24 24" fill="currentColor"><path d="M19 6.41L17.59 5 12 10.59 6.41 5 5 6.41 10.59 12 5 17.59 6.41 19 12 13.41 17.59 19 19 17.59 13.41 12z"/></svg>
        </button>
    </div>
    <div class="sheet-section">
        <a class="sheet-item" href="<?php echo $baseUrl; ?>/auth/logout.php">
            <div class="ic"><svg viewBox="0 0 24 24" fill="currentColor"><path d="M17 7l-1.41 1.41L18.17 11H8v2h10.17l-2.58 2.58L17 17l5-5zM4 5h8V3H4c-1.1 0-2 .9-2 2v14c0 1.1.9 2 2 2h8v-2H4V5z"/></svg></div>
            <span>Esci</span>
        </a>
    </div>
</div>

<!-- JavaScript -->
<script src="<?php echo $baseUrl; ?>/assets/js/app.js?v=<?php echo time(); ?>"></script>
<script src="<?php echo $baseUrl; ?>/assets/js/push.js?v=<?php echo time(); ?>"></script>
<script src="<?php echo $baseUrl; ?>/assets/js/availability-heatmap.js?v=<?php echo time(); ?>"></script>
<script>
// Sidebar collapse (desktop) + open su mobile
(function(){
    const html = document.documentElement;
    const collapseBtn = document.getElementById('sidebar-collapse');
    if (collapseBtn) {
        if (localStorage.getItem('pam.sidebarMini') === '1') html.classList.add('sidebar-mini');
        collapseBtn.addEventListener('click', () => {
            html.classList.toggle('sidebar-mini');
            localStorage.setItem('pam.sidebarMini', html.classList.contains('sidebar-mini') ? '1' : '0');
        });
    }
    const sidebar = document.getElementById('appSidebar');
    const overlay = document.getElementById('appOverlay');
    const mobileBtn = document.getElementById('mobileMenuBtn');
    if (sidebar && mobileBtn) {
        mobileBtn.addEventListener('click', () => sidebar.classList.toggle('open'));
        if (overlay) overlay.addEventListener('click', () => sidebar.classList.remove('open'));
    }
    // Tenant switcher
    const tenant = document.getElementById('sidebar-tenant');
    const tenantRow = document.getElementById('sidebar-tenant-row');
    if (tenant && tenantRow) {
        tenantRow.addEventListener('click', (e) => { e.stopPropagation(); tenant.classList.toggle('open'); });
        document.addEventListener('click', (e) => { if (!tenant.contains(e.target)) tenant.classList.remove('open'); });
    }
    // Bottom sheet
    const moreBtn = document.getElementById('bn-more');
    const sheet = document.getElementById('sheet');
    const backdrop = document.getElementById('sheet-backdrop');
    const sheetClose = document.getElementById('sheet-close');
    const openSheet = () => { sheet?.classList.add('open'); backdrop?.classList.add('open'); };
    const closeSheet = () => { sheet?.classList.remove('open'); backdrop?.classList.remove('open'); };
    moreBtn?.addEventListener('click', openSheet);
    backdrop?.addEventListener('click', closeSheet);
    sheetClose?.addEventListener('click', closeSheet);
})();
</script>
</body>
</html>
