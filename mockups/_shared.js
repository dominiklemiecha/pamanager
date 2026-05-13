/*
 * PAManager — Shared chrome injector
 * Aggiunge automaticamente footer Powered-by e (su mobile/tablet) il
 * bottom-nav fixed con bottom-sheet menu in ogni mockup che lo include.
 */

(function() {
    'use strict';

    // ---- Detect role from page filename or data attribute ----
    function detectRole() {
        if (document.body.dataset.role) return document.body.dataset.role;
        const path = location.pathname.toLowerCase();
        if (path.includes('admin-employees') || path.includes('admin-dashboard')) return 'admin';
        if (path.includes('consulente'))    return 'consulente_lavoro';
        if (path.includes('accountant'))    return 'accountant';
        if (path.includes('employee'))      return 'employee';
        if (path.includes('leave'))         return 'admin';
        if (path.includes('chat'))          return 'admin';
        return 'admin';
    }

    const role = detectRole();
    const isStaff = ['admin', 'admin_reparto', 'accountant', 'consulente_lavoro'].includes(role);

    // ---- Detect current page key (for active state) ----
    function detectPageKey() {
        if (document.body.dataset.page) return document.body.dataset.page;
        const f = location.pathname.split('/').pop().replace('.html', '');
        return f || 'index';
    }
    const pageKey = detectPageKey();

    // ---- Build powered-by footer ----
    function injectFooter() {
        if (document.querySelector('.powered')) return;
        const footer = document.createElement('div');
        footer.className = 'powered';
        footer.innerHTML = 'Powered by <a href="https://www.connecteed.com" target="_blank" rel="noopener"><img src="https://www.connecteed.com/assets/Logon-BsucV_4E.svg" alt="Connecteed"></a>';
        const main = document.querySelector('.app-main');
        if (main) {
            main.appendChild(footer);
        } else {
            document.body.appendChild(footer);
        }
    }

    // ---- Bottom-nav items per role ----
    const NAV_BY_ROLE = {
        admin: [
            { key: 'admin-dashboard', label: 'Home',       href: 'admin-dashboard.html', icon: 'M13 3v6h8V3h-8zM3 21h8V11H3v10zM3 9h8V3H3v6zm10 12h8V11h-8v10z' },
            { key: 'admin-employees', label: 'Dipendenti', href: 'admin-employees.html', icon: 'M16 11c1.66 0 2.99-1.34 2.99-3S17.66 5 16 5c-1.66 0-3 1.34-3 3s1.34 3 3 3zm-8 0c1.66 0 2.99-1.34 2.99-3S9.66 5 8 5C6.34 5 5 6.34 5 8s1.34 3 3 3zm0 2c-2.33 0-7 1.17-7 3.5V19h14v-2.5c0-2.33-4.67-3.5-7-3.5z' },
            { key: 'leave-requests',  label: 'Ferie',      href: 'leave-requests.html',  icon: 'M19 3h-4.18C14.4 1.84 13.3 1 12 1c-1.3 0-2.4.84-2.82 2H5c-1.1 0-2 .9-2 2v14c0 1.1.9 2 2 2h14c1.1 0 2-.9 2-2V5c0-1.1-.9-2-2-2z', badge: 5 },
            { key: 'chat',            label: 'Chat',       href: 'chat.html',            icon: 'M20 2H4c-1.1 0-2 .9-2 2v18l4-4h14c1.1 0 2-.9 2-2V4c0-1.1-.9-2-2-2z', badge: 3 },
        ],
        consulente_lavoro: [
            { key: 'consulente-dashboard', label: 'Home',       href: 'consulente-dashboard.html', icon: 'M13 3v6h8V3h-8zM3 21h8V11H3v10zM3 9h8V3H3v6zm10 12h8V11h-8v10z' },
            { key: 'consulente-anagrafica',label: 'Anagrafica', href: 'consulente-dashboard.html', icon: 'M16 11c1.66 0 2.99-1.34 2.99-3S17.66 5 16 5c-1.66 0-3 1.34-3 3s1.34 3 3 3z' },
            { key: 'docs',                 label: 'Buste',      href: 'consulente-dashboard.html', icon: 'M14 2H6c-1.1 0-1.99.9-1.99 2L4 20c0 1.1.89 2 1.99 2H18c1.1 0 2-.9 2-2V8l-6-6z' },
            { key: 'chat',                 label: 'Chat',       href: 'chat.html',                 icon: 'M20 2H4c-1.1 0-2 .9-2 2v18l4-4h14c1.1 0 2-.9 2-2V4c0-1.1-.9-2-2-2z' },
        ],
        accountant: [
            { key: 'accountant-home', label: 'Home',  href: 'admin-dashboard.html',  icon: 'M13 3v6h8V3h-8zM3 21h8V11H3v10zM3 9h8V3H3v6zm10 12h8V11h-8v10z' },
            { key: 'docs',            label: 'Buste', href: 'employee-payslips.html', icon: 'M14 2H6c-1.1 0-1.99.9-1.99 2L4 20c0 1.1.89 2 1.99 2H18c1.1 0 2-.9 2-2V8l-6-6z' },
            { key: 'leave-requests',  label: 'Ferie', href: 'leave-requests.html',    icon: 'M19 3h-4.18C14.4 1.84 13.3 1 12 1c-1.3 0-2.4.84-2.82 2H5c-1.1 0-2 .9-2 2v14c0 1.1.9 2 2 2h14c1.1 0 2-.9 2-2V5c0-1.1-.9-2-2-2z' },
            { key: 'chat',            label: 'Chat',  href: 'chat.html',              icon: 'M20 2H4c-1.1 0-2 .9-2 2v18l4-4h14c1.1 0 2-.9 2-2V4c0-1.1-.9-2-2-2z' },
        ],
        employee: [
            { key: 'employee-home',     label: 'Home',         href: 'employee-home.html',     icon: 'M10 20v-6h4v6h5v-8h3L12 3 2 12h3v8z' },
            { key: 'employee-payslips', label: 'Documenti',    href: 'employee-payslips.html', icon: 'M14 2H6c-1.1 0-2 .9-2 2v16c0 1.1.9 2 2 2h12c1.1 0 2-.9 2-2V8l-6-6z', badge: 2 },
            { key: 'leave-requests',    label: 'Ferie',        href: 'leave-requests.html',    icon: 'M19 3h-4.18C14.4 1.84 13.3 1 12 1c-1.3 0-2.4.84-2.82 2H5c-1.1 0-2 .9-2 2v14c0 1.1.9 2 2 2h14c1.1 0 2-.9 2-2V5c0-1.1-.9-2-2-2z' },
            { key: 'chat',              label: 'Chat',         href: 'chat.html',              icon: 'M20 2H4c-1.1 0-2 .9-2 2v18l4-4h14c1.1 0 2-.9 2-2V4c0-1.1-.9-2-2-2z', badge: 1 },
        ],
    };

    // ---- Companies for tenant picker ----
    const COMPANIES = [
        { id: 1, code: 'CO', name: 'Connecteed Srl',  count: 48, current: true },
        { id: 2, code: 'EP', name: 'ePrice Italia',   count: 12, current: false },
        { id: 3, code: 'SM', name: 'Studio Marini',   count: 5,  current: false },
    ];

    // ---- Full menu (per role) shown in bottom sheet ----
    const FULL_MENU = {
        admin: {
            'Generale': [
                { label: 'Dashboard',      href: 'admin-dashboard.html',  icon: 'M13 3v6h8V3h-8zM3 21h8V11H3v10z' },
                { label: 'Dipendenti',     href: 'admin-employees.html',  icon: 'M16 11c1.66 0 2.99-1.34 2.99-3S17.66 5 16 5c-1.66 0-3 1.34-3 3s1.34 3 3 3z' },
                { label: 'Ferie e Permessi', href: 'leave-requests.html', icon: 'M19 3h-4.18C14.4 1.84 13.3 1 12 1c-1.3 0-2.4.84-2.82 2H5c-1.1 0-2 .9-2 2v14c0 1.1.9 2 2 2h14c1.1 0 2-.9 2-2V5c0-1.1-.9-2-2-2z' },
                { label: 'Documenti',      href: 'employee-payslips.html', icon: 'M14 2H6c-1.1 0-2 .9-2 2v16c0 1.1.9 2 2 2h12c1.1 0 2-.9 2-2V8l-6-6z' },
                { label: 'Chat',           href: 'chat.html',              icon: 'M20 2H4c-1.1 0-2 .9-2 2v18l4-4h14c1.1 0 2-.9 2-2V4c0-1.1-.9-2-2-2z' },
                { label: 'Comunicazioni',  href: '#',                      icon: 'M20 2H4c-1.1 0-1.99.9-1.99 2L2 22l4-4h14c1.1 0 2-.9 2-2V4c0-1.1-.9-2-2-2z' },
            ],
            'Organizzazione': [
                { label: 'Reparti',           href: '#', icon: 'M12 7V3H2v18h20V7H12z' },
                { label: 'Commercialisti',    href: '#', icon: 'M19 3H5c-1.1 0-2 .9-2 2v14c0 1.1.9 2 2 2h14c1.1 0 2-.9 2-2V5c0-1.1-.9-2-2-2z' },
                { label: 'Consulenti lavoro', href: '#', icon: 'M20 6h-4V4c0-1.11-.89-2-2-2h-4c-1.11 0-2 .89-2 2v2H4c-1.11 0-1.99.89-1.99 2L2 19c0 1.11.89 2 2 2h16c1.11 0 2-.89 2-2V8c0-1.11-.89-2-2-2z' },
            ],
            'Sistema': [
                { label: 'Impostazioni', href: '#', icon: 'M19.14 12.94c.04-.3.06-.61.06-.94 0-.32-.02-.64-.07-.94l2.03-1.58c.18-.14.23-.41.12-.61l-1.92-3.32c-.12-.22-.37-.29-.59-.22l-2.39.96c-.5-.38-1.03-.7-1.62-.94l-.36-2.54c-.04-.24-.24-.41-.48-.41h-3.84c-.24 0-.43.17-.47.41l-.36 2.54c-.59.24-1.13.57-1.62.94l-2.39-.96c-.22-.08-.47 0-.59.22L2.74 8.87c-.12.21-.08.47.12.61l2.03 1.58c-.05.3-.09.63-.09.94s.02.64.07.94l-2.03 1.58c-.18.14-.23.41-.12.61l1.92 3.32c.12.22.37.29.59.22l2.39-.96c.5.38 1.03.7 1.62.94l.36 2.54c.05.24.24.41.48.41h3.84c.24 0 .44-.17.47-.41l.36-2.54c.59-.24 1.13-.56 1.62-.94l2.39.96c.22.08.47 0 .59-.22l1.92-3.32c.12-.22.07-.47-.12-.61l-2.01-1.58zM12 15.6c-1.98 0-3.6-1.62-3.6-3.6s1.62-3.6 3.6-3.6 3.6 1.62 3.6 3.6-1.62 3.6-3.6 3.6z' },
                { label: 'Esci',         href: '#', icon: 'M17 7l-1.41 1.41L18.17 11H8v2h10.17l-2.58 2.58L17 17l5-5zM4 5h8V3H4c-1.1 0-2 .9-2 2v14c0 1.1.9 2 2 2h8v-2H4V5z' },
            ],
        },
        consulente_lavoro: {
            'Generale': [
                { label: 'Dashboard',      href: 'consulente-dashboard.html', icon: 'M13 3v6h8V3h-8z' },
                { label: 'Anagrafica',     href: '#', icon: 'M16 11c1.66 0 2.99-1.34 2.99-3S17.66 5 16 5c-1.66 0-3 1.34-3 3s1.34 3 3 3z' },
                { label: 'Buste paga/CUD', href: '#', icon: 'M14 2H6c-1.1 0-1.99.9-1.99 2L4 20c0 1.1.89 2 1.99 2H18c1.1 0 2-.9 2-2V8l-6-6z' },
                { label: 'Documenti dipendente', href: '#', icon: 'M19 3H5c-1.1 0-2 .9-2 2v14c0 1.1.9 2 2 2h14c1.1 0 2-.9 2-2V5c0-1.1-.9-2-2-2z' },
                { label: 'Ferie/Permessi', href: 'leave-requests.html', icon: 'M19 3h-4.18C14.4 1.84 13.3 1 12 1c-1.3 0-2.4.84-2.82 2H5c-1.1 0-2 .9-2 2v14c0 1.1.9 2 2 2h14c1.1 0 2-.9 2-2V5c0-1.1-.9-2-2-2z' },
                { label: 'Export presenze',href: '#', icon: 'M19 3H5c-1.11 0-2 .9-2 2v14c0 1.1.89 2 2 2h14c1.11 0 2-.9 2-2V5c0-1.1-.89-2-2-2z' },
                { label: 'Chat',           href: 'chat.html', icon: 'M20 2H4c-1.1 0-2 .9-2 2v18l4-4h14c1.1 0 2-.9 2-2V4c0-1.1-.9-2-2-2z' },
            ],
            'Account': [
                { label: 'Profilo', href: '#', icon: 'M12 12c2.21 0 4-1.79 4-4s-1.79-4-4-4-4 1.79-4 4 1.79 4 4 4z' },
                { label: 'Esci',    href: '#', icon: 'M17 7l-1.41 1.41L18.17 11H8v2h10.17l-2.58 2.58L17 17l5-5z' },
            ],
        },
        accountant: {
            'Generale': [
                { label: 'Dashboard',     href: '#', icon: 'M13 3v6h8V3h-8z' },
                { label: 'Carica Documenti', href: '#', icon: 'M14 2H6c-1.1 0-1.99.9-1.99 2L4 20c0 1.1.89 2 1.99 2H18c1.1 0 2-.9 2-2V8l-6-6z' },
                { label: 'Ferie/Permessi',  href: 'leave-requests.html', icon: 'M19 3h-4.18C14.4 1.84 13.3 1 12 1c-1.3 0-2.4.84-2.82 2H5c-1.1 0-2 .9-2 2v14c0 1.1.9 2 2 2h14c1.1 0 2-.9 2-2V5c0-1.1-.9-2-2-2z' },
                { label: 'Chat',            href: 'chat.html', icon: 'M20 2H4c-1.1 0-2 .9-2 2v18l4-4h14c1.1 0 2-.9 2-2V4c0-1.1-.9-2-2-2z' },
            ],
            'Account': [
                { label: 'Profilo', href: '#', icon: 'M12 12c2.21 0 4-1.79 4-4s-1.79-4-4-4-4 1.79-4 4 1.79 4 4 4z' },
                { label: 'Esci',    href: '#', icon: 'M17 7l-1.41 1.41L18.17 11H8v2h10.17l-2.58 2.58L17 17l5-5z' },
            ],
        },
        employee: {
            'Area personale': [
                { label: 'Home',             href: 'employee-home.html',     icon: 'M10 20v-6h4v6h5v-8h3L12 3 2 12h3v8z' },
                { label: 'Documenti',        href: 'employee-payslips.html', icon: 'M14 2H6c-1.1 0-2 .9-2 2v16c0 1.1.9 2 2 2h12c1.1 0 2-.9 2-2V8l-6-6z' },
                { label: 'Comunicazioni',    href: '#',                       icon: 'M20 2H4c-1.1 0-1.99.9-1.99 2L2 22l4-4h14c1.1 0 2-.9 2-2V4c0-1.1-.9-2-2-2z' },
                { label: 'Ferie e Permessi', href: 'leave-requests.html',    icon: 'M19 3h-4.18C14.4 1.84 13.3 1 12 1c-1.3 0-2.4.84-2.82 2H5c-1.1 0-2 .9-2 2v14c0 1.1.9 2 2 2h14c1.1 0 2-.9 2-2V5c0-1.1-.9-2-2-2z' },
                { label: 'Chat',             href: 'chat.html',              icon: 'M20 2H4c-1.1 0-2 .9-2 2v18l4-4h14c1.1 0 2-.9 2-2V4c0-1.1-.9-2-2-2z' },
            ],
            'Account': [
                { label: 'Profilo', href: '#', icon: 'M12 12c2.21 0 4-1.79 4-4s-1.79-4-4-4-4 1.79-4 4 1.79 4 4 4z' },
                { label: 'Esci',    href: '#', icon: 'M17 7l-1.41 1.41L18.17 11H8v2h10.17l-2.58 2.58L17 17l5-5z' },
            ],
        },
    };

    // ---- Build bottom-nav HTML ----
    function injectBottomNav() {
        if (document.querySelector('.bottom-nav')) return;
        const items = NAV_BY_ROLE[role] || NAV_BY_ROLE.admin;

        const nav = document.createElement('nav');
        nav.className = 'bottom-nav';
        nav.setAttribute('aria-label', 'Menu principale');
        let html = '<div class="bottom-nav-grid">';
        items.forEach(it => {
            const active = it.key === pageKey ? ' active' : '';
            const badge = it.badge ? `<span class="bn-badge">${it.badge}</span>` : '';
            html += `<a class="bn-item${active}" href="${it.href}">
                <div class="bn-icon"><svg viewBox="0 0 24 24" fill="currentColor"><path d="${it.icon}"/></svg></div>
                <span class="bn-label">${it.label}</span>
                ${badge}
            </a>`;
        });
        // "Altro" button -> opens sheet
        html += `<button class="bn-item" id="bn-more" type="button">
            <div class="bn-icon"><svg viewBox="0 0 24 24" fill="currentColor"><path d="M4 6h16v2H4zm0 5h16v2H4zm0 5h16v2H4z"/></svg></div>
            <span class="bn-label">Altro</span>
        </button>`;
        html += '</div>';
        nav.innerHTML = html;
        document.body.appendChild(nav);
    }

    // ---- Build bottom-sheet HTML ----
    function injectSheet() {
        if (document.querySelector('.sheet')) return;
        const menu = FULL_MENU[role] || FULL_MENU.admin;

        const backdrop = document.createElement('div');
        backdrop.className = 'sheet-backdrop';
        backdrop.id = 'sheet-backdrop';

        const sheet = document.createElement('div');
        sheet.className = 'sheet';
        sheet.id = 'sheet';
        let html = '<div class="sheet-handle"></div>';
        html += `<div class="sheet-h"><h3>Menu</h3>
            <button class="sheet-close" id="sheet-close" aria-label="Chiudi">
                <svg viewBox="0 0 24 24" fill="currentColor"><path d="M19 6.41L17.59 5 12 10.59 6.41 5 5 6.41 10.59 12 5 17.59 6.41 19 12 13.41 17.59 19 19 17.59 13.41 12z"/></svg>
            </button>
        </div>`;

        // Tenant picker (only staff)
        if (isStaff) {
            const current = COMPANIES.find(c => c.current) || COMPANIES[0];
            html += `<div class="sheet-title">Azienda attiva</div>
            <div class="tenant-pick">
                <div class="ic">${current.code}</div>
                <div class="info">
                    <div class="lbl">${COMPANIES.length} aziende assegnate</div>
                    <div class="name">${current.name}</div>
                </div>
                <button class="swap" id="tenant-swap" type="button" title="Cambia azienda">
                    <svg viewBox="0 0 24 24" fill="currentColor"><path d="M7 11h10v2H7z"/><path d="M16 6l-1.41 1.41L18.17 11H7v2h11.17l-3.58 3.59L16 18l6-6z"/></svg>
                </button>
            </div>
            <div id="tenant-list" style="display:none; margin: 0 var(--sp-3) var(--sp-3);">`;
            COMPANIES.forEach(c => {
                html += `<a class="sheet-item${c.current ? ' active' : ''}">
                    <div class="ic" style="background:${c.current ? 'var(--primary-600)' : 'var(--primary-50)'}; color:${c.current ? 'white' : 'var(--primary-600)'}">${c.code}</div>
                    <div>
                        <div>${c.name}</div>
                        <div style="font-size: 11px; color: var(--muted);">${c.count} dipendenti</div>
                    </div>
                    ${c.current ? '<svg class="arrow" viewBox="0 0 24 24" width="16" height="16" fill="currentColor"><path d="M9 16.17L4.83 12l-1.42 1.41L9 19 21 7l-1.41-1.41z"/></svg>' : ''}
                </a>`;
            });
            html += `</div>`;
        }

        // Menu sections
        Object.entries(menu).forEach(([title, items]) => {
            html += `<div class="sheet-title">${title}</div><div class="sheet-section">`;
            items.forEach(it => {
                html += `<a class="sheet-item" href="${it.href}">
                    <div class="ic"><svg viewBox="0 0 24 24" fill="currentColor"><path d="${it.icon}"/></svg></div>
                    <span>${it.label}</span>
                </a>`;
            });
            html += `</div>`;
        });

        sheet.innerHTML = html;

        document.body.appendChild(backdrop);
        document.body.appendChild(sheet);

        // Bind events
        const moreBtn = document.getElementById('bn-more');
        const closeBtn = document.getElementById('sheet-close');
        function openSheet() { sheet.classList.add('open'); backdrop.classList.add('open'); }
        function closeSheet() { sheet.classList.remove('open'); backdrop.classList.remove('open'); }
        if (moreBtn) moreBtn.addEventListener('click', openSheet);
        if (closeBtn) closeBtn.addEventListener('click', closeSheet);
        backdrop.addEventListener('click', closeSheet);

        const swap = document.getElementById('tenant-swap');
        const tlist = document.getElementById('tenant-list');
        if (swap && tlist) {
            swap.addEventListener('click', () => {
                tlist.style.display = tlist.style.display === 'none' ? 'block' : 'none';
            });
        }
    }

    // ---- Run ----
    document.addEventListener('DOMContentLoaded', () => {
        injectFooter();
        injectBottomNav();
        injectSheet();
    });
})();
