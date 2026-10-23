/*
 * Script della cornice dello staff, copiati da public/includes/header-admin.php
 * (finestra Export presenze) e public/includes/footer-admin.php (menu, selettore
 * azienda, foglio Altro).
 */
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
        
// Sidebar collapse (desktop) + open su mobile
(function(){
    // Sidebar collapse rimosso: la sidebar è sempre estesa
    document.documentElement.classList.remove('sidebar-mini');
    try { localStorage.removeItem('pam.sidebarMini'); } catch (e) {}
    const sidebar = document.getElementById('appSidebar');
    const overlay = document.getElementById('appOverlay');
    const mobileBtn = document.getElementById('mobileMenuBtn');
    if (sidebar && mobileBtn) {
        const syncBodyLock = () => {
            const open = sidebar.classList.contains('open');
            document.body.classList.toggle('sidebar-open', open);
            document.documentElement.classList.toggle('sidebar-open', open);
        };
        mobileBtn.addEventListener('click', () => { sidebar.classList.toggle('open'); syncBodyLock(); });
        if (overlay) overlay.addEventListener('click', () => { sidebar.classList.remove('open'); syncBodyLock(); });
        const closeBtn = document.getElementById('sidebarClose');
        if (closeBtn) closeBtn.addEventListener('click', () => { sidebar.classList.remove('open'); syncBodyLock(); });
    }
    // Tenant switcher
    const tenant = document.getElementById('sidebar-tenant');
    const tenantRow = document.getElementById('sidebar-tenant-row');
    if (tenant && tenantRow) {
        tenantRow.addEventListener('click', (e) => { e.stopPropagation(); tenant.classList.toggle('open'); });
        document.addEventListener('click', (e) => { if (!tenant.contains(e.target)) tenant.classList.remove('open'); });
    }
    // User menu dropdown
    const userMenu = document.getElementById('userMenu');
    const userToggle = document.getElementById('userMenuToggle');
    if (userMenu && userToggle) {
        userToggle.addEventListener('click', (e) => {
            e.stopPropagation();
            const open = userMenu.classList.toggle('open');
            userToggle.setAttribute('aria-expanded', open ? 'true' : 'false');
        });
        document.addEventListener('click', (e) => {
            if (!userMenu.contains(e.target)) {
                userMenu.classList.remove('open');
                userToggle.setAttribute('aria-expanded', 'false');
            }
        });
        document.addEventListener('keydown', (e) => {
            if (e.key === 'Escape') {
                userMenu.classList.remove('open');
                userToggle.setAttribute('aria-expanded', 'false');
            }
        });
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
