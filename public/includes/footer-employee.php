        </main>
        <div class="powered">
            Powered by
            <a href="https://www.connecteed.com" target="_blank" rel="noopener">
                <img src="https://www.connecteed.com/assets/Logon-BsucV_4E.svg" alt="Connecteed">
            </a>
        </div>
    </div>
</div>

<!-- Bottom nav (mobile only) -->
<?php $__baseUrl = PUBLIC_URL; $__cp = basename($_SERVER['PHP_SELF'], '.php'); ?>
<nav class="bottom-nav emp-bottom-nav" aria-label="Menu principale">
    <div class="bottom-nav-grid">
        <a class="bn-item <?php echo $__cp === 'index' ? 'active' : ''; ?>" href="<?php echo $__baseUrl; ?>/employee/">
            <div class="bn-icon"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="3" width="7" height="9" rx="1"/><rect x="14" y="3" width="7" height="5" rx="1"/><rect x="14" y="12" width="7" height="9" rx="1"/><rect x="3" y="16" width="7" height="5" rx="1"/></svg></div>
            <span class="bn-label">Home</span>
        </a>
        <a class="bn-item <?php echo $__cp === 'documents' ? 'active' : ''; ?>" href="<?php echo $__baseUrl; ?>/employee/documents.php">
            <div class="bn-icon"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/></svg></div>
            <span class="bn-label">Documenti</span>
            <?php if (!empty($unreadDocsCount) && $unreadDocsCount > 0): ?><span class="bn-badge"><?php echo (int)$unreadDocsCount; ?></span><?php endif; ?>
        </a>
        <a class="bn-item <?php echo $__cp === 'leave-requests' ? 'active' : ''; ?>" href="<?php echo $__baseUrl; ?>/employee/leave-requests.php">
            <div class="bn-icon"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="4" width="18" height="18" rx="2"/><path d="M16 2v4M8 2v4M3 10h18"/></svg></div>
            <span class="bn-label">Ferie</span>
        </a>
        <a class="bn-item <?php echo $__cp === 'communications' ? 'active' : ''; ?>" href="<?php echo $__baseUrl; ?>/employee/communications.php">
            <div class="bn-icon"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="m3 11 18-5v12L3 14v-3z"/><path d="M11.6 16.8a3 3 0 1 1-5.8-1.6"/></svg></div>
            <span class="bn-label">Notizie</span>
            <?php if (!empty($unreadCommCount) && $unreadCommCount > 0): ?><span class="bn-badge"><?php echo (int)$unreadCommCount; ?></span><?php endif; ?>
        </a>
        <a class="bn-item <?php echo $__cp === 'chat' ? 'active' : ''; ?>" href="<?php echo $__baseUrl; ?>/employee/chat.php">
            <div class="bn-icon"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/></svg></div>
            <span class="bn-label">Chat</span>
            <?php if (!empty($unreadChats) && $unreadChats > 0): ?><span class="bn-badge"><?php echo (int)$unreadChats; ?></span><?php endif; ?>
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
    const menuBtn = document.getElementById('mobileMenuBtn');
    const sidebar = document.getElementById('appSidebar');
    const overlay = document.getElementById('appOverlay');
    menuBtn?.addEventListener('click', (e) => { e.stopPropagation(); sidebar?.classList.toggle('open'); });
    overlay?.addEventListener('click', () => sidebar?.classList.remove('open'));
})();
</script>
</body>
</html>
