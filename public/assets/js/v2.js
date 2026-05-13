/*
 * PAManager v2 — Sidebar enhancements: collapsible toggle + tenant switcher.
 * Lavora a fianco di style.css/theme.css esistenti; richiede theme-v2.css caricato.
 *
 * Espone window.PaManagerV2.init() ma si autoinizializza su DOMContentLoaded.
 */

(function() {
    'use strict';

    function $(sel, root) { return (root || document).querySelector(sel); }
    function $$(sel, root) { return Array.from((root || document).querySelectorAll(sel)); }

    function initCollapseToggle() {
        const sidebar = $('.app-sidebar, .admin-sidebar');
        if (!sidebar) return;
        const brand = sidebar.querySelector('.brand, .admin-logo, .sidebar-brand');
        if (!brand) return;
        if (brand.querySelector('.sidebar-collapse-btn')) return;

        const btn = document.createElement('button');
        btn.className = 'sidebar-collapse-btn';
        btn.type = 'button';
        btn.setAttribute('aria-label', 'Comprimi/espandi menu');
        btn.title = 'Comprimi menu';
        btn.innerHTML = '<svg viewBox="0 0 24 24" fill="currentColor" width="14" height="14"><path d="M15.41 16.59L10.83 12l4.58-4.59L14 6l-6 6 6 6 1.41-1.41z"/></svg>';
        brand.appendChild(btn);

        if (localStorage.getItem('sidebarMini') === '1') {
            document.documentElement.classList.add('sidebar-mini');
        }

        btn.addEventListener('click', () => {
            const isMini = document.documentElement.classList.toggle('sidebar-mini');
            try { localStorage.setItem('sidebarMini', isMini ? '1' : '0'); } catch (e) {}
            btn.title = isMini ? 'Espandi menu' : 'Comprimi menu';
        });
    }

    function initTenantSwitcher() {
        // Attivo solo se nel layout admin (server-rendered: cerca tenant data)
        const sidebar = $('.app-sidebar, .admin-sidebar');
        if (!sidebar) return;
        const dataEl = $('#tenant-data');
        if (!dataEl) return; // server non ha emesso dati: skip

        let payload;
        try { payload = JSON.parse(dataEl.textContent); } catch (e) { return; }
        if (!payload || !payload.companies || payload.companies.length === 0) return;

        if (sidebar.querySelector('.tenant-switcher')) return;
        const brand = sidebar.querySelector('.brand, .admin-logo, .sidebar-brand');
        if (!brand) return;

        const current = payload.companies.find(c => c.is_current) || payload.companies[0];
        const wrap = document.createElement('div');
        wrap.className = 'tenant-switcher';
        const codeOf = c => (c.code || (c.name || '').slice(0, 2)).toUpperCase();
        let html = '<div class="tenant-switcher-row">'
            + '<div class="tenant-mark">' + escapeHtml(codeOf(current)) + '</div>'
            + '<div class="tenant-info">'
            + '<div class="tenant-label">Azienda</div>'
            + '<div class="tenant-name">' + escapeHtml(current.name) + '</div>'
            + '</div>'
            + '<svg class="tenant-caret" viewBox="0 0 24 24" fill="currentColor" width="16" height="16"><path d="M7.41 8.59L12 13.17l4.59-4.58L18 10l-6 6-6-6 1.41-1.41z"/></svg>'
            + '</div>'
            + '<div class="tenant-menu">';
        payload.companies.forEach(c => {
            const checkIcon = c.is_current
                ? '<svg class="check" viewBox="0 0 24 24" fill="currentColor" width="14" height="14"><path d="M9 16.17L4.83 12l-1.42 1.41L9 19 21 7l-1.41-1.41z"/></svg>'
                : '';
            const count = c.employee_count !== undefined ? (c.employee_count + ' dipendenti') : '';
            html += '<a class="tenant-menu-item' + (c.is_current ? ' active' : '') + '" '
                + 'data-company-id="' + c.id + '" '
                + 'href="' + escapeAttr(payload.switch_url) + '?id=' + c.id + '">'
                + '<div class="tenant-mark">' + escapeHtml(codeOf(c)) + '</div>'
                + '<div class="info"><div class="n">' + escapeHtml(c.name) + '</div>'
                + (count ? '<div class="s">' + escapeHtml(count) + '</div>' : '')
                + '</div>' + checkIcon + '</a>';
        });
        html += '<div class="tenant-menu-footer">' + payload.companies.length + ' aziende assegnate</div>';
        html += '</div>';
        wrap.innerHTML = html;

        brand.insertAdjacentElement('afterend', wrap);

        wrap.querySelector('.tenant-switcher-row').addEventListener('click', e => {
            e.stopPropagation();
            wrap.classList.toggle('open');
        });
        document.addEventListener('click', e => {
            if (!wrap.contains(e.target)) wrap.classList.remove('open');
        });

        // POST per il submit del cambio azienda (CSRF token via header)
        $$('.tenant-menu-item').forEach(a => {
            a.addEventListener('click', e => {
                if (!payload.switch_url) return;
                e.preventDefault();
                const id = a.getAttribute('data-company-id');
                const csrf = $('meta[name="csrf-token"]')?.content || '';
                const fd = new FormData();
                fd.append('id', id);
                fd.append('csrf_token', csrf);
                fetch(payload.switch_url, { method: 'POST', body: fd, credentials: 'same-origin' })
                    .then(() => location.reload())
                    .catch(() => location.reload());
            });
        });
    }

    function enrichNavTooltips() {
        $$('.nav-item').forEach(a => {
            const label = a.querySelector('.nav-label')?.textContent?.trim();
            if (label && !a.dataset.tooltip) a.dataset.tooltip = label;
        });
    }

    function escapeHtml(s) {
        return String(s || '').replace(/[&<>"']/g, c => ({ '&':'&amp;', '<':'&lt;', '>':'&gt;', '"':'&quot;', "'":'&#39;' })[c]);
    }
    function escapeAttr(s) { return escapeHtml(s); }

    function init() {
        initCollapseToggle();
        initTenantSwitcher();
        enrichNavTooltips();
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }

    window.PaManagerV2 = { init: init };
})();
