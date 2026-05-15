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
<?php $__baseUrl = PUBLIC_URL; $__cp = basename($_SERVER['PHP_SELF'], '.php'); ?>
<nav class="bottom-nav" aria-label="Menu principale">
    <div class="bottom-nav-grid">
        <a class="bn-item <?php echo $__cp === 'index' ? 'active' : ''; ?>" href="<?php echo $__baseUrl; ?>/employee/">
            <div class="bn-icon"><svg viewBox="0 0 24 24" fill="currentColor"><path d="M10 20v-6h4v6h5v-8h3L12 3 2 12h3v8z"/></svg></div>
            <span class="bn-label">Home</span>
        </a>
        <a class="bn-item <?php echo $__cp === 'documents' ? 'active' : ''; ?>" href="<?php echo $__baseUrl; ?>/employee/documents.php">
            <div class="bn-icon"><svg viewBox="0 0 24 24" fill="currentColor"><path d="M14 2H6c-1.1 0-2 .9-2 2v16c0 1.1.9 2 2 2h12c1.1 0 2-.9 2-2V8l-6-6z"/></svg></div>
            <span class="bn-label">Documenti</span>
        </a>
        <a class="bn-item <?php echo $__cp === 'leave-requests' ? 'active' : ''; ?>" href="<?php echo $__baseUrl; ?>/employee/leave-requests.php">
            <div class="bn-icon"><svg viewBox="0 0 24 24" fill="currentColor"><path d="M19 3h-4.18C14.4 1.84 13.3 1 12 1c-1.3 0-2.4.84-2.82 2H5c-1.1 0-2 .9-2 2v14c0 1.1.9 2 2 2h14c1.1 0 2-.9 2-2V5c0-1.1-.9-2-2-2z"/></svg></div>
            <span class="bn-label">Ferie</span>
        </a>
        <a class="bn-item <?php echo $__cp === 'chat' ? 'active' : ''; ?>" href="<?php echo $__baseUrl; ?>/employee/chat.php">
            <div class="bn-icon"><svg viewBox="0 0 24 24" fill="currentColor"><path d="M20 2H4c-1.1 0-2 .9-2 2v18l4-4h14c1.1 0 2-.9 2-2V4c0-1.1-.9-2-2-2z"/></svg></div>
            <span class="bn-label">Chat</span>
        </a>
        <a class="bn-item <?php echo $__cp === 'profile' ? 'active' : ''; ?>" href="<?php echo $__baseUrl; ?>/employee/profile.php">
            <div class="bn-icon"><svg viewBox="0 0 24 24" fill="currentColor"><path d="M12 12c2.21 0 4-1.79 4-4s-1.79-4-4-4-4 1.79-4 4 1.79 4 4 4zm0 2c-2.67 0-8 1.34-8 4v2h16v-2c0-2.66-5.33-4-8-4z"/></svg></div>
            <span class="bn-label">Profilo</span>
        </a>
    </div>
</nav>

<!-- JavaScript -->
<script src="<?php echo $__baseUrl; ?>/assets/js/app.js?v=<?php echo time(); ?>"></script>
<script src="<?php echo $__baseUrl; ?>/assets/js/push.js?v=<?php echo time(); ?>"></script>
<script src="<?php echo $__baseUrl; ?>/assets/js/availability-toggle.js?v=<?php echo time(); ?>"></script>
<script src="<?php echo $__baseUrl; ?>/assets/js/availability-heatmap.js?v=<?php echo time(); ?>"></script>

<script>
(function() {
    // Mobile menu toggle
    const menuBtn = document.getElementById('mobileMenuBtn');
    const sidebar = document.getElementById('appSidebar');
    const overlay = document.getElementById('appOverlay');
    const closeMenu = () => { sidebar?.classList.remove('open'); };
    menuBtn?.addEventListener('click', (e) => { e.stopPropagation(); sidebar?.classList.toggle('open'); });
    overlay?.addEventListener('click', closeMenu);
    // Sidebar collapse desktop
    const collapseBtn = document.getElementById('sidebar-collapse');
    collapseBtn?.addEventListener('click', () => {
        document.documentElement.classList.toggle('sidebar-mini');
        try { localStorage.setItem('pam_sidebar_mini', document.documentElement.classList.contains('sidebar-mini') ? '1' : '0'); } catch(e){}
    });
    try {
        if (localStorage.getItem('pam_sidebar_mini') === '1') document.documentElement.classList.add('sidebar-mini');
    } catch(e){}
})();
</script>
</body>
</html>
