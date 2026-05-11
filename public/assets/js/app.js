/**
 * PAManager - JavaScript Principale
 * Area Dipendente
 */

(function() {
    'use strict';

    // ================================
    // Configuration
    // ================================
    const config = {
        csrfTokenName: 'csrf_token',
        apiBase: (window.PAM && window.PAM.baseUrl) ? window.PAM.baseUrl + '/api' : '/api'
    };

    // ================================
    // Utility Functions
    // ================================

    /**
     * Get CSRF token from meta tag
     */
    function getCsrfToken() {
        const meta = document.querySelector('meta[name="csrf-token"]');
        return meta ? meta.content : '';
    }

    /**
     * Format file size
     */
    function formatFileSize(bytes) {
        const units = ['B', 'KB', 'MB', 'GB'];
        let i = 0;
        while (bytes >= 1024 && i < units.length - 1) {
            bytes /= 1024;
            i++;
        }
        return bytes.toFixed(2) + ' ' + units[i];
    }

    /**
     * Format date
     */
    function formatDate(dateString) {
        const date = new Date(dateString);
        return date.toLocaleDateString('it-IT');
    }

    /**
     * Show toast notification
     */
    function showToast(message, type = 'info') {
        // Remove existing toasts
        const existing = document.querySelector('.toast');
        if (existing) {
            existing.remove();
        }

        const toast = document.createElement('div');
        toast.className = `toast toast-${type}`;
        toast.textContent = message;

        document.body.appendChild(toast);

        // Animate in
        setTimeout(() => toast.classList.add('show'), 10);

        // Remove after 3 seconds
        setTimeout(() => {
            toast.classList.remove('show');
            setTimeout(() => toast.remove(), 300);
        }, 3000);
    }

    // ================================
    // Navigation Toggle (Mobile)
    // ================================
    function initNavigation() {
        const toggle = document.getElementById('navToggle');
        const menu = document.getElementById('navMenu');

        if (toggle && menu) {
            toggle.addEventListener('click', function() {
                menu.classList.toggle('active');
                toggle.classList.toggle('active');
            });

            // Close menu when clicking outside
            document.addEventListener('click', function(e) {
                if (!toggle.contains(e.target) && !menu.contains(e.target)) {
                    menu.classList.remove('active');
                    toggle.classList.remove('active');
                }
            });
        }

        // Admin sidebar toggle
        const sidebarToggle = document.getElementById('sidebarToggle');
        const sidebar = document.querySelector('.admin-sidebar');
        const content = document.querySelector('.admin-content');
        const overlay = document.getElementById('sidebarOverlay');

        function closeSidebar() {
            sidebar.classList.remove('open');
            if (overlay) overlay.classList.remove('active');
        }

        function openSidebar() {
            sidebar.classList.add('open');
            if (overlay) overlay.classList.add('active');
        }

        if (sidebarToggle && sidebar) {
            sidebarToggle.addEventListener('click', function(e) {
                e.preventDefault();
                e.stopPropagation();

                // Mobile: toggle open class
                if (window.innerWidth <= 991) {
                    if (sidebar.classList.contains('open')) {
                        closeSidebar();
                    } else {
                        openSidebar();
                    }
                } else {
                    // Desktop: toggle collapsed
                    sidebar.classList.toggle('collapsed');
                    if (content) {
                        content.classList.toggle('expanded');
                    }
                    localStorage.setItem('sidebarCollapsed', sidebar.classList.contains('collapsed'));
                }
            });

            // Close sidebar when clicking overlay
            if (overlay) {
                overlay.addEventListener('click', closeSidebar);
            }

            // Close button (mobile X)
            const sidebarClose = document.getElementById('sidebarClose');
            if (sidebarClose) {
                sidebarClose.addEventListener('click', function(e) {
                    e.preventDefault();
                    e.stopPropagation();
                    closeSidebar();
                });
            }

            // Close on nav-item tap (mobile)
            sidebar.querySelectorAll('.nav-item').forEach(function(item) {
                item.addEventListener('click', function() {
                    if (window.innerWidth <= 991) {
                        closeSidebar();
                    }
                });
            });

            // ESC key to close
            document.addEventListener('keydown', function(e) {
                if (e.key === 'Escape' && sidebar.classList.contains('open')) {
                    closeSidebar();
                }
            });

            // Close on window resize to desktop
            window.addEventListener('resize', function() {
                if (window.innerWidth > 991) {
                    closeSidebar();
                }
            });

            // Restore desktop preference
            if (window.innerWidth > 991) {
                const savedState = localStorage.getItem('sidebarCollapsed');
                if (savedState === 'true') {
                    sidebar.classList.add('collapsed');
                    if (content) {
                        content.classList.add('expanded');
                    }
                }
            }
        }
    }

    // ================================
    // Form Enhancements
    // ================================
    function initForms() {
        // Uppercase inputs
        document.querySelectorAll('input.uppercase').forEach(function(input) {
            input.addEventListener('input', function() {
                this.value = this.value.toUpperCase();
            });
        });

        // Confirm before delete
        document.querySelectorAll('form[data-confirm]').forEach(function(form) {
            form.addEventListener('submit', function(e) {
                const message = this.dataset.confirm || 'Sei sicuro?';
                if (!confirm(message)) {
                    e.preventDefault();
                }
            });
        });

        // Add CSRF token to forms
        document.querySelectorAll('form').forEach(function(form) {
            if (!form.querySelector('input[name="' + config.csrfTokenName + '"]')) {
                const input = document.createElement('input');
                input.type = 'hidden';
                input.name = config.csrfTokenName;
                input.value = getCsrfToken();
                form.appendChild(input);
            }
        });
    }

    // ================================
    // Communication Read Tracking
    // ================================
    function initCommunicationTracking() {
        // Mark communications as read when viewed
        const commDetail = document.querySelector('.communication-detail');
        if (commDetail) {
            const commId = new URLSearchParams(window.location.search).get('id');
            if (commId) {
                // Already handled by PHP, but could add AJAX call here
            }
        }
    }

    // ================================
    // Offline Detection
    // ================================
    function initOfflineDetection() {
        function updateOnlineStatus() {
            if (navigator.onLine) {
                document.body.classList.remove('offline');
                showToast('Connessione ripristinata', 'success');
            } else {
                document.body.classList.add('offline');
                showToast('Sei offline', 'warning');
            }
        }

        window.addEventListener('online', updateOnlineStatus);
        window.addEventListener('offline', updateOnlineStatus);
    }

    // ================================
    // PWA Install Prompt
    // ================================
    let deferredPrompt;

    function initPWAPrompt() {
        window.addEventListener('beforeinstallprompt', function(e) {
            e.preventDefault();
            deferredPrompt = e;

            // Show install button if exists
            const installBtn = document.getElementById('installApp');
            if (installBtn) {
                installBtn.style.display = 'block';
                installBtn.addEventListener('click', promptInstall);
            }
        });
    }

    function promptInstall() {
        if (deferredPrompt) {
            deferredPrompt.prompt();
            deferredPrompt.userChoice.then(function(choice) {
                if (choice.outcome === 'accepted') {
                    showToast('App installata!', 'success');
                }
                deferredPrompt = null;
            });
        }
    }

    // ================================
    // Push Notifications
    // ================================
    function initPushNotifications() {
        if ('Notification' in window && 'serviceWorker' in navigator) {
            // Request permission
            if (Notification.permission === 'default') {
                // Could show a prompt to the user
            }
        }
    }

    // ================================
    // AJAX Helpers
    // ================================
    function fetchAPI(endpoint, options = {}) {
        const defaultOptions = {
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-Token': getCsrfToken()
            }
        };

        return fetch(config.apiBase + endpoint, { ...defaultOptions, ...options })
            .then(function(response) {
                if (!response.ok) {
                    throw new Error('Network response was not ok');
                }
                return response.json();
            });
    }

    // ================================
    // Document Download Tracking
    // ================================
    function initDownloadTracking() {
        document.querySelectorAll('a[href*="download="]').forEach(function(link) {
            link.addEventListener('click', function() {
                // Could track download analytics here
            });
        });
    }

    // ================================
    // Auto-refresh for Communications
    // ================================
    function initAutoRefresh() {
        // Refresh unread count every 5 minutes
        const badge = document.querySelector('.badge-notification');
        if (badge) {
            setInterval(function() {
                fetchAPI('/communications.php')
                    .then(function(data) {
                        if (data.unread_count !== undefined) {
                            badge.textContent = data.unread_count;
                            if (data.unread_count > 0) {
                                badge.style.display = 'flex';
                            } else {
                                badge.style.display = 'none';
                            }
                        }
                    })
                    .catch(function() {
                        // Silently fail
                    });
            }, 5 * 60 * 1000);
        }
    }

    // ================================
    // Toast Styles (inject if not present)
    // ================================
    function injectToastStyles() {
        if (!document.getElementById('toast-styles')) {
            const style = document.createElement('style');
            style.id = 'toast-styles';
            style.textContent = `
                .toast {
                    position: fixed;
                    bottom: 20px;
                    left: 50%;
                    transform: translateX(-50%) translateY(100px);
                    padding: 12px 24px;
                    border-radius: 8px;
                    color: white;
                    font-weight: 500;
                    z-index: 9999;
                    opacity: 0;
                    transition: all 0.3s ease;
                }
                .toast.show {
                    transform: translateX(-50%) translateY(0);
                    opacity: 1;
                }
                .toast-info { background: #3182ce; }
                .toast-success { background: #38a169; }
                .toast-warning { background: #d69e2e; }
                .toast-error { background: #e53e3e; }
                .offline .toast-warning { display: block; }
            `;
            document.head.appendChild(style);
        }
    }

    // ================================
    // Initialize
    // ================================
    function init() {
        injectToastStyles();
        initNavigation();
        initForms();
        initCommunicationTracking();
        initOfflineDetection();
        initPWAPrompt();
        initPushNotifications();
        initDownloadTracking();
        initAutoRefresh();
    }

    // Run on DOM ready
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }

    // Expose to global scope if needed
    window.GestionalePA = {
        showToast: showToast,
        fetchAPI: fetchAPI,
        promptInstall: promptInstall
    };

})();
