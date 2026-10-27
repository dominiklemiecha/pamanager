/**
 * Testata dell'area staff, generata da public/includes/header-admin.php
 * (dal <body> a <main>): stesso markup per ruolo, stessi gestori onclick
 * (le funzioni stanno in /assets/js/staff-cornice.js). Le espressioni PHP
 * sono tradotte una per una dallo script di conversione.
 */
import type { CorniceStaff } from './tipi';

const e = (s: unknown) => String(s ?? '').replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;').replace(/'/g, '&#039;');
const MESI = ['', 'Gennaio', 'Febbraio', 'Marzo', 'Aprile', 'Maggio', 'Giugno', 'Luglio', 'Agosto', 'Settembre', 'Ottobre', 'Novembre', 'Dicembre'];

export function htmlTestata(d: CorniceStaff, p: string): string {
  const c = d.contatori;
  const ora = new Intl.DateTimeFormat('en-CA', { timeZone: 'Europe/Rome', year: 'numeric', month: 'numeric' }).formatToParts(new Date());
  const meseOra = Number(ora.find((x) => x.type === 'month')?.value);
  const annoOra = Number(ora.find((x) => x.type === 'year')?.value);
  let h = '';
  h += `<div class="app">
    <aside class="app-sidebar" id="appSidebar">
        <div class="brand">
            <div class="brand-text">
                <div class="brand-wordmark">Connecteed<span class="brand-wordmark-accent">HR</span></div>
            </div>
            <button type="button" class="sidebar-close" id="sidebarClose" aria-label="Chiudi menu">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
            </button>
        </div>

        <nav class="nav">
            `;
  if (d.ruolo === 'admin') {
  h += `
                <a href="`;
  h += String('');
  h += `/admin/" class="nav-item `;
  h += String((p === 'index' ? 'active' : ''));
  h += `" data-tooltip="Dashboard">
                    <svg class="nav-icon" viewBox="0 0 24 24"><rect x="3" y="3" width="7" height="9" rx="1"/><rect x="14" y="3" width="7" height="5" rx="1"/><rect x="14" y="12" width="7" height="9" rx="1"/><rect x="3" y="16" width="7" height="5" rx="1"/></svg>
                    <span class="nav-content">
                        <span class="nav-title">Dashboard</span>
                        <span class="nav-sub">Panoramica attivit&agrave;</span>
                    </span>
                </a>
                <a href="`;
  h += String('');
  h += `/admin/employees.php" class="nav-item `;
  h += String((p === 'employees' ? 'active' : ''));
  h += `" data-tooltip="Dipendenti">
                    <svg class="nav-icon" viewBox="0 0 24 24"><path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M22 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg>
                    <span class="nav-content">
                        <span class="nav-title">Dipendenti</span>
                        <span class="nav-sub">`;
  h += String((c.prove > 0 ? c.prove + ' ' + (c.prove === 1 ? 'prova da decidere' : 'prove da decidere') : (d.sottotitoli.emp || 'Anagrafica')));
  h += `</span>
                    </span>
                    `;
  if (c.prove > 0) {
  h += `<span class="nav-pulse" title="`;
  h += String(c.prove);
  h += ` periodi di prova da decidere"></span>`;
  }
  h += `
                </a>
                <a href="`;
  h += String('');
  h += `/admin/hire-requests.php" class="nav-item `;
  h += String((p === 'hire-requests' ? 'active' : ''));
  h += `" data-tooltip="Assunzioni">
                    <svg class="nav-icon" viewBox="0 0 24 24"><path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><line x1="20" y1="8" x2="20" y2="14"/><line x1="23" y1="11" x2="17" y2="11"/></svg>
                    <span class="nav-content">
                        <span class="nav-title">Assunzioni</span>
                        <span class="nav-sub">`;
  h += String((c.assunzioni > 0 ? c.assunzioni + ' da approvare' : 'Nuove richieste'));
  h += `</span>
                    </span>
                    `;
  if (c.assunzioni > 0) {
  h += `<span class="nav-pulse" title="`;
  h += String(c.assunzioni);
  h += ` prospetti da approvare"></span>`;
  }
  h += `
                </a>
                <a href="`;
  h += String('');
  h += `/admin/leave-requests.php" class="nav-item `;
  h += String((p === 'leave-requests' ? 'active' : ''));
  h += `" data-tooltip="Ferie e Permessi">
                    <svg class="nav-icon" viewBox="0 0 24 24"><rect x="3" y="4" width="18" height="18" rx="2"/><path d="M16 2v4M8 2v4M3 10h18"/></svg>
                    <span class="nav-content">
                        <span class="nav-title">Ferie/Permessi</span>
                        <span class="nav-sub">`;
  h += String((c.ferie > 0 ? c.ferie + ' in attesa di approvazione' : 'Tutto approvato'));
  h += `</span>
                    </span>
                    `;
  if (c.ferie > 0) {
  h += `<span class="nav-pulse" title="`;
  h += String(c.ferie);
  h += `"></span>`;
  }
  h += `
                </a>
                <a href="`;
  h += String('');
  h += `/admin/communications.php" class="nav-item `;
  h += String((p === 'communications' ? 'active' : ''));
  h += `" data-tooltip="Comunicazioni">
                    <svg class="nav-icon" viewBox="0 0 24 24"><path d="m3 11 18-5v12L3 14v-3z"/><path d="M11.6 16.8a3 3 0 1 1-5.8-1.6"/></svg>
                    <span class="nav-content">
                        <span class="nav-title">Comunicazioni</span>
                        <span class="nav-sub">`;
  h += String((d.sottotitoli.comm || 'Bacheca aziendale'));
  h += `</span>
                    </span>
                </a>
                <a href="`;
  h += String('');
  h += `/admin/documenti-aziendali.php" class="nav-item `;
  h += String((p === 'documenti-aziendali' ? 'active' : ''));
  h += `" data-tooltip="Documenti aziendali">
                    <svg class="nav-icon" viewBox="0 0 24 24"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><path d="M14 2v6h6"/><path d="M9 15h6"/></svg>
                    <span class="nav-content">
                        <span class="nav-title">Documenti aziendali</span>
                        <span class="nav-sub">Invio a tutti i dipendenti</span>
                    </span>
                </a>
                <a href="`;
  h += String('');
  h += `/admin/academy.php" class="nav-item `;
  h += String((['academy', 'academy-templates', 'formatori'].includes(p) ? 'active' : ''));
  h += `" data-tooltip="Academy">
                    <svg class="nav-icon" viewBox="0 0 24 24"><path d="M22 10 12 5 2 10l10 5 10-5z"/><path d="M6 12v5c3 2 9 2 12 0v-5"/></svg>
                    <span class="nav-content">
                        <span class="nav-title">Academy</span>
                        <span class="nav-sub">`;
  h += String((c.attestati > 0 ? c.attestati + ' ' + (c.attestati === 1 ? 'attestato' : 'attestati') + ' da generare' : 'Corsi e attestati'));
  h += `</span>
                    </span>
                    `;
  if (c.attestati > 0) {
  h += `<span class="nav-pulse" title="`;
  h += String(c.attestati);
  h += ` attestati da generare"></span>`;
  }
  h += `
                </a>
                <a href="`;
  h += String('');
  h += `/admin/chat.php" class="nav-item `;
  h += String((p === 'chat' ? 'active' : ''));
  h += `" data-tooltip="Chat">
                    <svg class="nav-icon" viewBox="0 0 24 24"><path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/></svg>
                    <span class="nav-content">
                        <span class="nav-title">Chat</span>
                        <span class="nav-sub">`;
  h += String((c.chat > 0 ? c.chat + ' non letti' : 'Nessun nuovo messaggio'));
  h += `</span>
                    </span>
                    `;
  if (c.chat > 0) {
  h += `<span class="nav-pulse"></span>`;
  }
  h += `
                </a>
                <a href="`;
  h += String('');
  h += `/admin/calendar.php" class="nav-item `;
  h += String((p === 'calendar' ? 'active' : ''));
  h += `" data-tooltip="Calendario">
                    <svg class="nav-icon" viewBox="0 0 24 24"><rect x="3" y="4" width="18" height="18" rx="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg>
                    <span class="nav-content">
                        <span class="nav-title">Calendario</span>
                        <span class="nav-sub">`;
  h += String((c.inviti > 0 ? c.inviti + ' ' + (c.inviti === 1 ? 'invito' : 'inviti') + ' da confermare' : 'Eventi e riunioni'));
  h += `</span>
                    </span>
                    `;
  if (c.inviti > 0) {
  h += `<span class="nav-pulse" title="`;
  h += String(c.inviti);
  h += `"></span>`;
  }
  h += `
                </a>
                <a href="#" class="nav-item" data-tooltip="Presenze" id="navPresenzeBtn" onclick="event.preventDefault(); openPresenzeModal();">
                    <svg class="nav-icon" viewBox="0 0 24 24"><path d="M3 3v18h18"/><path d="M7 16V8m5 8V4m5 12v-6"/></svg>
                    <span class="nav-content">
                        <span class="nav-title">Presenze</span>
                        <span class="nav-sub">`;
  h += String((d.sottotitoli.pres || 'Export mensile'));
  h += `</span>
                    </span>
                </a>
                <a href="`;
  h += String('');
  h += `/admin/departments.php" class="nav-item `;
  h += String((p === 'departments' ? 'active' : ''));
  h += `" data-tooltip="Reparti">
                    <svg class="nav-icon" viewBox="0 0 24 24"><path d="M3 21h18M5 21V7l8-4v18M19 21V11l-6-4"/></svg>
                    <span class="nav-content">
                        <span class="nav-title">Reparti</span>
                        <span class="nav-sub">`;
  h += String((d.sottotitoli.dept || 'Struttura aziendale'));
  h += `</span>
                    </span>
                </a>
                <a href="`;
  h += String('');
  h += `/admin/consulente-lavoro.php" class="nav-item `;
  h += String((p === 'consulente-lavoro' ? 'active' : ''));
  h += `" data-tooltip="Consulenti lavoro">
                    <svg class="nav-icon" viewBox="0 0 24 24"><rect x="2" y="7" width="20" height="14" rx="2"/><path d="M8 7V5a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"/></svg>
                    <span class="nav-content">
                        <span class="nav-title">Consulenti lavoro</span>
                        <span class="nav-sub">Accessi paghe</span>
                    </span>
                </a>
                `;
  h += `
                <a href="`;
  h += String('');
  h += `/admin/profile.php" class="nav-item `;
  h += String((['password-resets','smtp-settings','work-schedule','profile'].includes(p) ? 'active' : ''));
  h += `" data-tooltip="Configurazione">
                    <svg class="nav-icon" viewBox="0 0 24 24"><path d="M20 7h-9M14 17H5"/><circle cx="17" cy="17" r="3"/><circle cx="7" cy="7" r="3"/></svg>
                    <span class="nav-content">
                        <span class="nav-title">Configurazione</span>
                        <span class="nav-sub">Sistema, email, orario</span>
                    </span>
                </a>
            `;
  } else if (d.ruolo === 'formatore') {
  h += `
                <a href="`;
  h += String('');
  h += `/formatore/academy.php" class="nav-item `;
  h += String((p === 'academy' ? 'active' : ''));
  h += `" data-tooltip="Academy">
                    <svg class="nav-icon" viewBox="0 0 24 24"><path d="M22 10 12 5 2 10l10 5 10-5z"/><path d="M6 12v5c3 2 9 2 12 0v-5"/></svg>
                    <span class="nav-content">
                        <span class="nav-title">Academy</span>
                        <span class="nav-sub">`;
  h += String((c.attestati > 0 ? c.attestati + ' ' + (c.attestati === 1 ? 'attestato' : 'attestati') + ' da generare' : 'Corsi e partecipanti'));
  h += `</span>
                    </span>
                    `;
  if (c.attestati > 0) {
  h += `<span class="nav-pulse" title="`;
  h += String(c.attestati);
  h += ` attestati da generare"></span>`;
  }
  h += `
                </a>
                <a href="`;
  h += String('');
  h += `/formatore/academy-templates.php" class="nav-item `;
  h += String((p === 'academy-templates' ? 'active' : ''));
  h += `" data-tooltip="Modelli attestato">
                    <svg class="nav-icon" viewBox="0 0 24 24"><circle cx="12" cy="8" r="6"/><path d="M15.5 13 17 22l-5-3-5 3 1.5-9"/></svg>
                    <span class="nav-content">
                        <span class="nav-title">Modelli attestato</span>
                        <span class="nav-sub">Grafica degli attestati</span>
                    </span>
                </a>
                <a href="`;
  h += String('');
  h += `/formatore/profile.php" class="nav-item `;
  h += String((p === 'profile' ? 'active' : ''));
  h += `" data-tooltip="Profilo">
                    <svg class="nav-icon" viewBox="0 0 24 24"><circle cx="12" cy="8" r="4"/><path d="M4 21v-1a7 7 0 0 1 14 0v1"/></svg>
                    <span class="nav-content">
                        <span class="nav-title">Profilo</span>
                        <span class="nav-sub">Le mie info</span>
                    </span>
                </a>
            `;
  } else if (d.ruolo === 'consulente_lavoro') {
  h += `
                <a href="`;
  h += String('');
  h += `/consulente-lavoro/" class="nav-item `;
  h += String((p === 'index' ? 'active' : ''));
  h += `" data-tooltip="Dashboard">
                    <svg class="nav-icon" viewBox="0 0 24 24"><rect x="3" y="3" width="7" height="9" rx="1"/><rect x="14" y="3" width="7" height="5" rx="1"/><rect x="14" y="12" width="7" height="9" rx="1"/><rect x="3" y="16" width="7" height="5" rx="1"/></svg>
                    <span class="nav-content">
                        <span class="nav-title">Dashboard</span>
                        <span class="nav-sub">Panoramica</span>
                    </span>
                </a>
                <a href="`;
  h += String('');
  h += `/consulente-lavoro/employees.php" class="nav-item `;
  h += String((p === 'employees' ? 'active' : ''));
  h += `" data-tooltip="Anagrafica">
                    <svg class="nav-icon" viewBox="0 0 24 24"><path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M22 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg>
                    <span class="nav-content">
                        <span class="nav-title">Anagrafica</span>
                        <span class="nav-sub">Dipendenti</span>
                    </span>
                </a>
                <a href="`;
  h += String('');
  h += `/consulente-lavoro/hire-requests.php" class="nav-item `;
  h += String((p === 'hire-requests' ? 'active' : ''));
  h += `" data-tooltip="Assunzioni">
                    <svg class="nav-icon" viewBox="0 0 24 24"><path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><line x1="20" y1="8" x2="20" y2="14"/><line x1="23" y1="11" x2="17" y2="11"/></svg>
                    <span class="nav-content">
                        <span class="nav-title">Assunzioni</span>
                        <span class="nav-sub">`;
  h += String((c.assunzioni > 0 ? c.assunzioni + ' da gestire' : 'Richieste in corso'));
  h += `</span>
                    </span>
                    `;
  if (c.assunzioni > 0) {
  h += `<span class="nav-pulse" title="`;
  h += String(c.assunzioni);
  h += ` richieste da gestire"></span>`;
  }
  h += `
                </a>
                <a href="`;
  h += String('');
  h += `/consulente-lavoro/documents.php" class="nav-item `;
  h += String((p === 'documents' ? 'active' : ''));
  h += `" data-tooltip="Buste paga/CUD">
                    <svg class="nav-icon" viewBox="0 0 24 24"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/></svg>
                    <span class="nav-content">
                        <span class="nav-title">Buste paga/CU</span>
                        <span class="nav-sub">Carica documenti</span>
                    </span>
                </a>
                <a href="`;
  h += String('');
  h += `/consulente-lavoro/employee-documents.php" class="nav-item `;
  h += String((p === 'employee-documents' ? 'active' : ''));
  h += `" data-tooltip="Documenti dipendente">
                    <svg class="nav-icon" viewBox="0 0 24 24"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="9" y1="13" x2="15" y2="13"/><line x1="9" y1="17" x2="13" y2="17"/></svg>
                    <span class="nav-content">
                        <span class="nav-title">Documenti</span>
                        <span class="nav-sub">Caricati dal dipendente</span>
                    </span>
                </a>
                <a href="`;
  h += String('');
  h += `/consulente-lavoro/academy.php" class="nav-item `;
  h += String((['academy', 'academy-templates'].includes(p) ? 'active' : ''));
  h += `" data-tooltip="Academy">
                    <svg class="nav-icon" viewBox="0 0 24 24"><path d="M22 10 12 5 2 10l10 5 10-5z"/><path d="M6 12v5c3 2 9 2 12 0v-5"/></svg>
                    <span class="nav-content">
                        <span class="nav-title">Academy</span>
                        <span class="nav-sub">`;
  h += String((c.attestati > 0 ? c.attestati + ' ' + (c.attestati === 1 ? 'attestato' : 'attestati') + ' da generare' : 'Corsi e attestati'));
  h += `</span>
                    </span>
                    `;
  if (c.attestati > 0) {
  h += `<span class="nav-pulse" title="`;
  h += String(c.attestati);
  h += ` attestati da generare"></span>`;
  }
  h += `
                </a>
                <a href="`;
  h += String('');
  h += `/consulente-lavoro/leave-requests.php" class="nav-item `;
  h += String((p === 'leave-requests' ? 'active' : ''));
  h += `" data-tooltip="Ferie e Permessi">
                    <svg class="nav-icon" viewBox="0 0 24 24"><rect x="3" y="4" width="18" height="18" rx="2"/><path d="M16 2v4M8 2v4M3 10h18"/></svg>
                    <span class="nav-content">
                        <span class="nav-title">Ferie/Permessi</span>
                        <span class="nav-sub">Storico richieste</span>
                    </span>
                </a>
                <a href="`;
  h += String('');
  h += `/consulente-lavoro/presenze-export.php" class="nav-item `;
  h += String((p === 'presenze-export' ? 'active' : ''));
  h += `" data-tooltip="Presenze">
                    <svg class="nav-icon" viewBox="0 0 24 24"><path d="M3 3v18h18"/><path d="M7 16V8m5 8V4m5 12v-6"/></svg>
                    <span class="nav-content">
                        <span class="nav-title">Presenze</span>
                        <span class="nav-sub">Export mensile</span>
                    </span>
                </a>
                <a href="`;
  h += String('');
  h += `/consulente-lavoro/chat.php" class="nav-item `;
  h += String((p === 'chat' ? 'active' : ''));
  h += `" data-tooltip="Chat">
                    <svg class="nav-icon" viewBox="0 0 24 24"><path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/></svg>
                    <span class="nav-content">
                        <span class="nav-title">Chat</span>
                        <span class="nav-sub">`;
  h += String((c.chat > 0 ? c.chat + ' non letti' : 'Messaggi'));
  h += `</span>
                    </span>
                    `;
  if (c.chat > 0) {
  h += `<span class="nav-pulse"></span>`;
  }
  h += `
                </a>
                <a href="`;
  h += String('');
  h += `/consulente-lavoro/calendar.php" class="nav-item `;
  h += String((p === 'calendar' ? 'active' : ''));
  h += `" data-tooltip="Calendario">
                    <svg class="nav-icon" viewBox="0 0 24 24"><rect x="3" y="4" width="18" height="18" rx="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg>
                    <span class="nav-content">
                        <span class="nav-title">Calendario</span>
                        <span class="nav-sub">`;
  h += String((c.inviti > 0 ? c.inviti + ' ' + (c.inviti === 1 ? 'invito' : 'inviti') + ' da confermare' : 'Eventi e riunioni'));
  h += `</span>
                    </span>
                    `;
  if (c.inviti > 0) {
  h += `<span class="nav-pulse" title="`;
  h += String(c.inviti);
  h += `"></span>`;
  }
  h += `
                </a>
                <a href="`;
  h += String('');
  h += `/consulente-lavoro/profile.php" class="nav-item `;
  h += String((p === 'profile' ? 'active' : ''));
  h += `" data-tooltip="Profilo">
                    <svg class="nav-icon" viewBox="0 0 24 24"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>
                    <span class="nav-content">
                        <span class="nav-title">Profilo</span>
                        <span class="nav-sub">Le mie info</span>
                    </span>
                </a>
            `;
  }
  h += `
        </nav>
    </aside>
    <div class="app-overlay" id="appOverlay"></div>

    <div class="app-main">
        <header class="app-header">
            <button class="header-btn mobile-menu-btn" id="mobileMenuBtn" aria-label="Apri menu">
                <svg viewBox="0 0 24 24" fill="currentColor"><path d="M3 18h18v-2H3v2zm0-5h18v-2H3v2zm0-7v2h18V6H3z"/></svg>
            </button>
            `;
  if (d.aziende.length >= 1) {
    const attiva = !!d.azienda?.is_active;
  h += `
            <div class="tenant-switcher tenant-switcher-top" id="sidebar-tenant">
                <button type="button" class="tenant-switcher-row `;
  h += String((d.altrove > 0 ? 'has-other-activity' : ''));
  h += `" id="sidebar-tenant-row" aria-haspopup="true" aria-expanded="false" title="`;
  h += String((d.altrove > 0 ? d.altrove + ' richiest' + (d.altrove === 1 ? 'a' : 'e') + ' in attesa in altre aziende' : (attiva ? 'Attiva' : 'Non attiva')));
  h += `">
                    <span class="tenant-status-dot `;
  h += String((attiva ? 'is-active' : 'is-inactive'));
  h += ` `;
  h += String((d.altrove > 0 ? 'is-pulsing' : ''));
  h += `"></span>
                    <span class="tenant-label">Azienda:</span>
                    <span class="tenant-name">`;
  h += String(e(d.azienda?.name ?? 'Azienda'));
  h += `</span>
                    `;
  if (d.altrove > 0) {
  h += `<span class="tenant-other-badge">`;
  h += String(d.altrove);
  h += `</span>`;
  }
  h += `
                    <svg class="tenant-caret" viewBox="0 0 24 24" fill="currentColor"><path d="M7.41 8.59L12 13.17l4.59-4.58L18 10l-6 6-6-6 1.41-1.41z"/></svg>
                </button>
                <div class="tenant-menu">
                    `;
  for (const t of d.aziende) {
    const corrente = t.id === (d.azienda?.id ?? 0);
    const altra = !corrente && t.attivita > 0;
  h += `
                        <div class="tenant-menu-row" style="display:flex; align-items:stretch;">
                            <form method="POST" action="`;
  h += String('');
  h += `/auth/switch-tenant.php" style="flex:1; min-width:0;">
                                `;
  h += String('');
  h += `
                                <button type="submit" name="id" value="`;
  h += String(t.id);
  h += `" class="tenant-menu-item `;
  h += String((corrente ? 'active' : ''));
  h += `" style="width:100%; border:0; cursor:pointer; text-align:left; background:transparent;">
                                    <span class="tenant-status-dot `;
  h += String((t.is_active ? 'is-active' : 'is-inactive'));
  h += ` `;
  h += String((altra ? 'is-pulsing-red' : ''));
  h += `" title="`;
  h += String((altra ? t.attivita + ' notifiche in attesa' : (t.is_active ? 'Attiva' : 'Non attiva')));
  h += `"></span>
                                    <div class="info">
                                        <div class="n">`;
  h += String(e(t.name));
  h += `</div>
                                        `;
  if (!t.is_active) {
  h += `<div class="s">Sospesa</div>`;
  }
  h += `
                                    </div>
                                    `;
  if (altra) {
  h += `
                                        <span class="tenant-row-badge" title="`;
  h += String(t.attivita);
  h += ` notifiche">`;
  h += String(t.attivita);
  h += `</span>
                                    `;
  }
  h += `
                                    `;
  if (corrente) {
  h += `
                                        <svg class="check" viewBox="0 0 24 24" fill="currentColor"><path d="M9 16.17L4.83 12l-1.42 1.41L9 19 21 7l-1.41-1.41z"/></svg>
                                    `;
  }
  h += `
                                </button>
                            </form>
                            `;
  if (d.puoUscire) {
  h += `
                                <form method="POST" action="`;
  h += String('');
  h += `/auth/leave-tenant.php" style="display:flex;" onsubmit="return confirm('Rimuoverti dall\\'azienda &quot;`;
  h += String(e(t.name.replace(/[\\'"]/g, (x) => '\\' + x)));
  h += `&quot;? Perderai l\\'accesso ai dati. L\\'admin potra reinvitarti in futuro.');">
                                    `;
  h += String('');
  h += `
                                    <input type="hidden" name="company_id" value="`;
  h += String(t.id);
  h += `">
                                    <button type="submit" class="tenant-leave-btn" title="Esci da questa azienda (fine mandato)" aria-label="Esci da `;
  h += String(e(t.name));
  h += `">
                                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="18" y1="6" x2="6" y2="18"></line><line x1="6" y1="6" x2="18" y2="18"></line></svg>
                                    </button>
                                </form>
                            `;
  }
  h += `
                        </div>
                    `;
  }
  h += `
                    `;
  if (d.ruolo === 'admin') {
  h += `
                        <a href="`;
  h += String('');
  h += `/admin/companies.php" class="tenant-menu-item" style="border-top:1px solid var(--border);">
                            <svg viewBox="0 0 24 24" fill="currentColor" style="width:16px;height:16px;color:var(--muted);margin-right:var(--sp-2);"><path d="M19.14 12.94c.04-.3.06-.61.06-.94 0-.32-.02-.64-.07-.94l2.03-1.58c.18-.14.23-.41.12-.61l-1.92-3.32c-.12-.22-.37-.29-.59-.22l-2.39.96c-.5-.38-1.03-.7-1.62-.94l-.36-2.54c-.04-.24-.24-.41-.48-.41h-3.84c-.24 0-.43.17-.47.41l-.36 2.54c-.59.24-1.13.57-1.62.94l-2.39-.96c-.22-.08-.47 0-.59.22L2.74 8.87c-.12.21-.08.47.12.61l2.03 1.58c-.05.3-.09.63-.09.94s.02.64.07.94l-2.03 1.58c-.18.14-.23.41-.12.61l1.92 3.32c.12.22.37.29.59.22l2.39-.96c.5.38 1.03.7 1.62.94l.36 2.54c.05.24.24.41.48.41h3.84c.24 0 .44-.17.47-.41l.36-2.54c.59-.24 1.13-.56 1.62-.94l2.39.96c.22.08.47 0 .59-.22l1.92-3.32c.12-.22.07-.47-.12-.61l-2.01-1.58zM12 15.6c-1.98 0-3.6-1.62-3.6-3.6s1.62-3.6 3.6-3.6 3.6 1.62 3.6 3.6-1.62 3.6-3.6 3.6z"/></svg>
                            <div class="info"><div class="n">Gestisci aziende</div></div>
                        </a>
                    `;
  }
  h += `
                    <div class="tenant-menu-footer">`;
  h += String(d.aziende.length);
  h += ` aziende assegnate</div>
                </div>
            </div>
            `;
  }
  h += `
            <div class="header-spacer" style="flex:1;"></div>
            <div class="header-actions">
                <button id="enableNotifications" class="header-btn" title="Attiva notifiche" style="display:none;">
                    <svg viewBox="0 0 24 24" fill="currentColor"><path d="M12 22c1.1 0 2-.9 2-2h-4c0 1.1.89 2 2 2zm6-6v-5c0-3.07-1.64-5.64-4.5-6.32V4c0-.83-.67-1.5-1.5-1.5s-1.5.67-1.5 1.5v.68C7.63 5.36 6 7.92 6 11v5l-2 2v1h16v-1l-2-2z"/></svg>
                </button>
                <a href="`;
  h += String('');
  h += `/`;
  h += String(d.area);
  h += `/profile.php" class="user-chip user-chip-link user-chip-photo-only" title="`;
  h += String(e(d.nome));
  h += `">
                    <div class="user-chip-avatar">
                        `;
  if (d.foto) {
  h += `
                            <img src="`;
  h += String(e(d.foto));
  h += `" alt="`;
  h += String(e(d.nome));
  h += `" loading="lazy" decoding="async">
                        `;
  } else {
  h += `
                            `;
  h += String(e(d.iniziali));
  h += `
                        `;
  }
  h += `
                    </div>
                </a>
            </div>
        </header>

        <!-- Modal Export Presenze -->
        <div id="presenzeModalOverlay" class="pres-modal-overlay" hidden>
            <div class="pres-modal" role="dialog" aria-labelledby="presModalTitle" aria-modal="true">
                <div class="pres-modal-h">
                    <div>
                        <h3 id="presModalTitle">Export presenze</h3>
                        <p>Scarica il file Excel mensile (ferie, ROL, malattia).</p>
                    </div>
                    <button type="button" class="pres-close" onclick="closePresenzeModal()" aria-label="Chiudi">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
                    </button>
                </div>
                <form method="get" action="`;
  h += String('');
  h += `/admin/presenze-export.php" class="pres-modal-form" onsubmit="closePresenzeModal();">
                    <input type="hidden" name="action" value="download">
                    <div class="pres-grid">
                        <label>
                            <span>Mese</span>
                            <select name="month" id="presMonth">
                                `;
  for (let m = 1; m <= 12; m++) {
  h += `
                                    <option value="`;
  h += String(m);
  h += `" `;
  h += String((m === meseOra ? 'selected' : ''));
  h += `>`;
  h += String(MESI[m]);
  h += `</option>
                                `;
  }
  h += `
                            </select>
                        </label>
                        <label>
                            <span>Anno</span>
                            <select name="year" id="presYear">
                                `;
  for (let y = annoOra - 1; y <= annoOra + 1; y++) {
  h += `
                                    <option value="`;
  h += String(y);
  h += `" `;
  h += String((y === annoOra ? 'selected' : ''));
  h += `>`;
  h += String(y);
  h += `</option>
                                `;
  }
  h += `
                            </select>
                        </label>
                    </div>
                    <div class="pres-actions">
                        <button type="button" class="pres-btn pres-btn-ghost" onclick="closePresenzeModal()">Annulla</button>
                        <button type="submit" class="pres-btn pres-btn-primary">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" width="14" height="14"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/></svg>
                            Scarica Excel
                        </button>
                    </div>
                </form>
            </div>
        </div>

        <style>
        .pres-modal-overlay {
            position: fixed; inset: 0; z-index: 2000;
            background: rgba(15,23,42,0.45);
            backdrop-filter: blur(4px);
            display: flex; align-items: center; justify-content: center;
            padding: 16px;
            animation: presFade .15s ease;
        }
        .pres-modal-overlay[hidden] { display: none !important; }
        @keyframes presFade { from { opacity: 0; } to { opacity: 1; } }
        .pres-modal {
            background: white;
            border-radius: 16px;
            width: 100%; max-width: 460px;
            box-shadow: 0 24px 64px rgba(15,23,42,0.25);
            overflow: hidden;
            animation: presPop .18s ease;
        }
        @keyframes presPop { from { transform: scale(0.96); opacity: 0; } to { transform: scale(1); opacity: 1; } }
        .pres-modal-h {
            display: flex; justify-content: space-between; align-items: flex-start;
            padding: 22px 24px 8px;
            gap: 12px;
        }
        .pres-modal-h h3 {
            margin: 0 0 4px;
            font-family: 'Host Grotesk','Inter',sans-serif;
            font-size: 18px; font-weight: 700; color: #0f172a;
            letter-spacing: -0.01em;
        }
        .pres-modal-h p { margin: 0; font-size: 13px; color: #64748b; }
        .pres-close {
            width: 32px; height: 32px;
            border: none; background: transparent;
            color: #94a3b8; border-radius: 8px;
            cursor: pointer; flex-shrink: 0;
            display: flex; align-items: center; justify-content: center;
        }
        .pres-close:hover { background: #f1f5f9; color: #0f172a; }
        .pres-close svg { width: 18px; height: 18px; }
        .pres-modal-form { padding: 16px 24px 22px; }
        .pres-grid {
            display: grid; grid-template-columns: 1fr 120px; gap: 12px;
            margin-bottom: 20px;
        }
        .pres-grid label { display: flex; flex-direction: column; gap: 6px; }
        .pres-grid label span {
            font-size: 11px; font-weight: 600; color: #64748b;
            text-transform: uppercase; letter-spacing: 0.04em;
        }
        .pres-grid select {
            padding: 10px 12px;
            border: 1px solid #e2e8f0;
            border-radius: 8px;
            font-family: inherit; font-size: 14px;
            background: white;
            transition: border-color .12s ease, box-shadow .12s ease;
        }
        .pres-grid select:focus {
            outline: none; border-color: #0b3aa4;
            box-shadow: 0 0 0 3px rgba(11,58,164,0.10);
        }
        .pres-actions { display: flex; gap: 10px; justify-content: flex-end; }
        .pres-btn {
            display: inline-flex; align-items: center; gap: 8px;
            padding: 9px 16px; border-radius: 8px;
            font-family: inherit; font-size: 13px; font-weight: 600;
            border: 1px solid transparent; cursor: pointer;
            transition: all .12s ease;
        }
        .pres-btn-primary { background: #0b3aa4; color: white; border-color: #0b3aa4; }
        .pres-btn-primary:hover { background: #0b3aa4; border-color: #0b3aa4; }
        .pres-btn-ghost { background: white; color: #475569; border-color: #e2e8f0; }
        .pres-btn-ghost:hover { border-color: #475569; color: #0f172a; }
        </style>

        <script>
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
        </script>

`;
  return h;
}
