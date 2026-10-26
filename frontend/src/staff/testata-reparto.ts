/**
 * Testata del responsabile di reparto, da public/includes/header-admin-reparto.php
 * (dal <body> a <main>): stesso markup e stesse voci di menu.
 */
import type { CorniceStaff } from './tipi';

const e = (s: unknown) => String(s ?? '').replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;').replace(/'/g, '&#039;');

export function htmlTestataReparto(d: CorniceStaff, p: string): string {
  const r = d.reparto ?? { nome: 'Nessun reparto', ferie: 0, reset: 0 };
  const voce = (pagina: string, href: string, titolo: string, icona: string, badge = 0) =>
    `<a href="/admin-reparto/${href}" class="nav-item ${p === pagina ? 'active' : ''}" data-tooltip="${titolo}">
                ${icona}
                <span class="nav-label">${titolo}</span>${badge > 0 ? `
                <span class="nav-badge">${badge}</span>` : ''}
            </a>`;
  return `<div class="app">
    <aside class="app-sidebar" id="appSidebar">
        <div class="brand">
            <div class="brand-mark">P</div>
            <div class="brand-text">
                <div>
                    <div class="brand-name">PAManager</div>
                    <div style="font-size:var(--text-xs); color:var(--muted)">${e(r.nome)}</div>
                </div>
            </div>
            <button class="sidebar-collapse-btn" type="button" id="sidebar-collapse" aria-label="Comprimi/espandi menu" title="Comprimi menu">
                <svg viewBox="0 0 24 24" fill="currentColor"><path d="M15.41 16.59L10.83 12l4.58-4.59L14 6l-6 6 6 6 1.41-1.41z"/></svg>
            </button>
        </div>
        <nav class="nav">
            <div class="nav-section">Generale</div>
            ${voce('index', '', 'Dashboard', '<svg class="nav-icon" viewBox="0 0 24 24" fill="currentColor"><path d="M3 13h8V3H3v10zm0 8h8v-6H3v6zm10 0h8V11h-8v10zm0-18v6h8V3h-8z"/></svg>')}
            ${voce('employees', 'employees.php', 'Dipendenti', '<svg class="nav-icon" viewBox="0 0 24 24" fill="currentColor"><path d="M16 11c1.66 0 2.99-1.34 2.99-3S17.66 5 16 5c-1.66 0-3 1.34-3 3s1.34 3 3 3zm-8 0c1.66 0 2.99-1.34 2.99-3S9.66 5 8 5C6.34 5 5 6.34 5 8s1.34 3 3 3zm0 2c-2.33 0-7 1.17-7 3.5V19h14v-2.5c0-2.33-4.67-3.5-7-3.5z"/></svg>')}
            ${voce('leave-requests', 'leave-requests.php', 'Ferie/Permessi', '<svg class="nav-icon" viewBox="0 0 24 24" fill="currentColor"><path d="M19 3h-4.18C14.4 1.84 13.3 1 12 1c-1.3 0-2.4.84-2.82 2H5c-1.1 0-2 .9-2 2v14c0 1.1.9 2 2 2h14c1.1 0 2-.9 2-2V5c0-1.1-.9-2-2-2z"/></svg>', r.ferie)}
            ${voce('communications', 'communications.php', 'Comunicazioni', '<svg class="nav-icon" viewBox="0 0 24 24" fill="currentColor"><path d="M20 2H4c-1.1 0-1.99.9-1.99 2L2 22l4-4h14c1.1 0 2-.9 2-2V4c0-1.1-.9-2-2-2z"/></svg>')}
            ${voce('academy', 'academy.php', 'Academy', '<svg class="nav-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M22 10 12 5 2 10l10 5 10-5z"/><path d="M6 12v5c3 2 9 2 12 0v-5"/></svg>')}
            ${voce('chat', 'chat.php', 'Chat', '<svg class="nav-icon" viewBox="0 0 24 24" fill="currentColor"><path d="M20 2H4c-1.1 0-2 .9-2 2v18l4-4h14c1.1 0 2-.9 2-2V4c0-1.1-.9-2-2-2z"/></svg>', d.contatori.chat)}
            ${voce('calendar', 'calendar.php', 'Calendario', '<svg class="nav-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="4" width="18" height="18" rx="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg>', d.contatori.inviti)}

            <div class="nav-section">Sistema</div>
            ${voce('password-resets', 'password-resets.php', 'Reset Password', '<svg class="nav-icon" viewBox="0 0 24 24" fill="currentColor"><path d="M12.65 10C11.83 7.67 9.61 6 7 6c-3.31 0-6 2.69-6 6s2.69 6 6 6c2.61 0 4.83-1.67 5.65-4H17v4h4v-4h2v-4H12.65zM7 14c-1.1 0-2-.9-2-2s.9-2 2-2 2 .9 2 2-.9 2-2 2z"/></svg>', r.reset)}
        </nav>
    </aside>
    <div class="app-overlay" id="appOverlay"></div>

    <div class="app-main">
        <header class="app-header">
            <button class="header-btn mobile-menu-btn" id="mobileMenuBtn" aria-label="Apri menu">
                <svg viewBox="0 0 24 24" fill="currentColor"><path d="M3 18h18v-2H3v2zm0-5h18v-2H3v2zm0-7v2h18V6H3z"/></svg>
            </button>
            <div class="header-search">
                <svg viewBox="0 0 24 24" fill="currentColor"><path d="M15.5 14h-.79l-.28-.27C15.41 12.59 16 11.11 16 9.5 16 5.91 13.09 3 9.5 3S3 5.91 3 9.5 5.91 16 9.5 16c1.61 0 3.09-.59 4.23-1.57l.27.28v.79l5 4.99L20.49 19l-4.99-5zm-6 0C7.01 14 5 11.99 5 9.5S7.01 5 9.5 5 14 7.01 14 9.5 11.99 14 9.5 14z"/></svg>
                <input type="search" placeholder="Cerca dipendenti, richieste...">
            </div>
            <div class="header-actions">
                <button id="enableNotifications" class="header-btn" title="Attiva notifiche" style="display:none;">
                    <svg viewBox="0 0 24 24" fill="currentColor"><path d="M12 22c1.1 0 2-.9 2-2h-4c0 1.1.89 2 2 2zm6-6v-5c0-3.07-1.64-5.64-4.5-6.32V4c0-.83-.67-1.5-1.5-1.5s-1.5.67-1.5 1.5v.68C7.63 5.36 6 7.92 6 11v5l-2 2v1h16v-1l-2-2z"/></svg>
                </button>
                <a href="/auth/logout.php" class="header-btn" title="Esci">
                    <svg viewBox="0 0 24 24" fill="currentColor"><path d="M17 7l-1.41 1.41L18.17 11H8v2h10.17l-2.58 2.58L17 17l5-5zM4 5h8V3H4c-1.1 0-2 .9-2 2v14c0 1.1.9 2 2 2h8v-2H4V5z"/></svg>
                </a>
                <div class="user-chip">
                    <div class="user-chip-avatar">${e(d.iniziali)}</div>
                    <span class="user-chip-name">${e(d.nome)}</span>
                </div>
            </div>
        </header>
`;
}
