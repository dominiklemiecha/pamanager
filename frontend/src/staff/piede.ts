/** Barra in basso e foglio "Altro" su telefono, da public/includes/footer-admin.php. */
import type { CorniceStaff } from './tipi';

export function htmlPiede(d: CorniceStaff, p: string): string {
  const c = d.contatori;
  return `<!-- Bottom nav mobile -->
<nav class="bottom-nav" aria-label="Menu principale">
    <div class="bottom-nav-grid">
        ${d.area === 'formatore' ? `
        <a class="bn-item ${p === 'academy' ? 'active' : ''}" href="/formatore/academy.php">
            <div class="bn-icon"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M22 10 12 5 2 10l10 5 10-5z"/><path d="M6 12v5c3 2 9 2 12 0v-5"/></svg></div>
            <span class="bn-label">Academy</span>
            ${c.attestati ? `<span class="bn-badge">${c.attestati}</span>` : ''}
        </a>
        <a class="bn-item ${p === 'academy-templates' ? 'active' : ''}" href="/formatore/academy-templates.php">
            <div class="bn-icon"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="8" r="6"/><path d="M15.5 13 17 22l-5-3-5 3 1.5-9"/></svg></div>
            <span class="bn-label">Modelli</span>
        </a>
        <a class="bn-item ${p === 'profile' ? 'active' : ''}" href="/formatore/profile.php">
            <div class="bn-icon"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="8" r="4"/><path d="M4 21v-1a7 7 0 0 1 14 0v1"/></svg></div>
            <span class="bn-label">Profilo</span>
        </a>
        ` : `
        <a class="bn-item ${p === 'index' ? 'active' : ''}" href="/${d.area}/">
            <div class="bn-icon"><svg viewBox="0 0 24 24" fill="currentColor"><path d="M13 3v6h8V3h-8zM3 21h8V11H3v10zM3 9h8V3H3v6zm10 12h8V11h-8v10z"/></svg></div>
            <span class="bn-label">Home</span>
        </a>
        <a class="bn-item ${p === 'employees' ? 'active' : ''}" href="/${d.area}/employees.php">
            <div class="bn-icon"><svg viewBox="0 0 24 24" fill="currentColor"><path d="M16 11c1.66 0 2.99-1.34 2.99-3S17.66 5 16 5c-1.66 0-3 1.34-3 3s1.34 3 3 3zm-8 0c1.66 0 2.99-1.34 2.99-3S9.66 5 8 5C6.34 5 5 6.34 5 8s1.34 3 3 3zm0 2c-2.33 0-7 1.17-7 3.5V19h14v-2.5c0-2.33-4.67-3.5-7-3.5z"/></svg></div>
            <span class="bn-label">Dipendenti</span>
        </a>
        <a class="bn-item ${p === 'leave-requests' ? 'active' : ''}" href="/${d.area}/leave-requests.php">
            <div class="bn-icon"><svg viewBox="0 0 24 24" fill="currentColor"><path d="M19 3h-4.18C14.4 1.84 13.3 1 12 1c-1.3 0-2.4.84-2.82 2H5c-1.1 0-2 .9-2 2v14c0 1.1.9 2 2 2h14c1.1 0 2-.9 2-2V5c0-1.1-.9-2-2-2z"/></svg></div>
            <span class="bn-label">Ferie</span>
            ${c.ferie > 0 ? `
                <span class="bn-badge">${c.ferie}</span>
            ` : ''}
        </a>
        <a class="bn-item ${p === 'chat' ? 'active' : ''}" href="/${d.area}/chat.php">
            <div class="bn-icon"><svg viewBox="0 0 24 24" fill="currentColor"><path d="M20 2H4c-1.1 0-2 .9-2 2v18l4-4h14c1.1 0 2-.9 2-2V4c0-1.1-.9-2-2-2z"/></svg></div>
            <span class="bn-label">Chat</span>
            ${c.chat > 0 ? `
                <span class="bn-badge">${c.chat}</span>
            ` : ''}
        </a>
        `}
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
        <a class="sheet-item" href="/auth/logout.php">
            <div class="ic"><svg viewBox="0 0 24 24" fill="currentColor"><path d="M17 7l-1.41 1.41L18.17 11H8v2h10.17l-2.58 2.58L17 17l5-5zM4 5h8V3H4c-1.1 0-2 .9-2 2v14c0 1.1.9 2 2 2h8v-2H4V5z"/></svg></div>
            <span>Esci</span>
        </a>
    </div>
</div>

`;
}
