/**
 * HTML del calendario condiviso, come lo scriveva public/includes/_calendar.inc.php:
 * stesso markup (anche gli spazi tra gli elementi in linea) e stessi gestori
 * onclick, usati dallo script copiato in /assets/js/cora-calendario.js.
 */

export interface Persona { name?: string; photo_path?: string | null; user_type?: string; user_id?: number; status?: string }
export interface Evento {
  id: number; owner_type: string; owner_id: number; title: string; start_at: string; end_at: string;
  my_status: string | null; participants: Persona[]; owner: { name: string; photo: string | null }; can_drag: boolean;
}
export interface Contatto { id: number; name?: string; first_name?: string; last_name?: string; photo_path: string | null }
export interface DatiCalendario {
  vista: 'week' | 'day'; rif: string; oggi: string; giorni: string[]; festivita: Record<string, string>; etichetta: string;
  prima: string; dopo: string; eventi: Evento[]; contatti: Record<string, Contatto[]>; chiamante: { tipo: string; id: number };
}

const e = (s: unknown) => String(s ?? '').replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;').replace(/'/g, '&#039;');
const iniziale = (s: string | undefined) => [...(s ?? '?')].slice(0, 1).join('').toUpperCase();
const foto = (p: string) => '/' + p.replace(/^\/+/, '');

const PALETTE = [
  { bg: '#fef3c7', border: '#f59e0b', text: '#92400e' }, { bg: '#dbeafe', border: '#3b82f6', text: '#1e40af' },
  { bg: '#dcfce7', border: '#22c55e', text: '#166534' }, { bg: '#fce7f3', border: '#ec4899', text: '#9d174d' },
  { bg: '#ede9fe', border: '#8b5cf6', text: '#5b21b6' }, { bg: '#ffedd5', border: '#f97316', text: '#9a3412' },
  { bg: '#cffafe', border: '#06b6d4', text: '#155e75' }, { bg: '#fee2e2', border: '#ef4444', text: '#991b1b' },
  { bg: '#fef9c3', border: '#eab308', text: '#854d0e' }, { bg: '#d1fae5', border: '#10b981', text: '#065f46' },
];
const PALETTE_CORA = [
  { bg: '#e3f6f8', border: '#22a9b8', text: '#0e6b75' }, { bg: '#efe9fb', border: '#6a3fd1', text: '#4a2a99' },
  { bg: '#fff1de', border: '#ff9400', text: '#a24a08' }, { bg: '#e5fff5', border: '#008753', text: '#006640' },
  { bg: '#ffedec', border: '#c9123b', text: '#9c0e2e' }, { bg: '#eef3f2', border: '#4f5c59', text: '#2f3836' },
  { bg: '#fde8f3', border: '#d6338a', text: '#9c1f63' }, { bg: '#e8effd', border: '#3a6fe0', text: '#234a9e' },
];
const DOW = ['', 'Lun', 'Mar', 'Mer', 'Gio', 'Ven', 'Sab', 'Dom'];

/** "2026-10-28 10:00:00" -> minuti dalla mezzanotte e parti. */
const parti = (s: string) => ({ giorno: s.slice(0, 10), h: +s.slice(11, 13), m: +s.slice(14, 16), hm: s.slice(11, 16) });
const minutiAssoluti = (s: string) => Date.UTC(+s.slice(0, 4), +s.slice(5, 7) - 1, +s.slice(8, 10), +s.slice(11, 13), +s.slice(14, 16), +s.slice(17, 19)) / 60000;
const dow = (ymd: string) => new Date(`${ymd}T12:00:00Z`).getUTCDay() || 7;

function oraRoma(): { h: number; m: number } {
  const p = Object.fromEntries(new Intl.DateTimeFormat('en-GB', { timeZone: 'Europe/Rome', hour: '2-digit', minute: '2-digit', hourCycle: 'h23' }).formatToParts(new Date()).map((x) => [x.type, x.value]));
  return { h: +p.hour, m: +p.minute };
}

function evento(ev: Evento, d: string, palette: typeof PALETTE): string {
  const s = parti(ev.start_at);
  const f = parti(ev.end_at);
  if (d < s.giorno || d > f.giorno) return '';
  // Ritaglio sul giorno: 7:00 = 0 px, 1 minuto = 1 px.
  const ini = s.giorno < d ? { h: 0, m: 0 } : s;
  const fin = f.giorno > d ? { h: 23, m: 59 } : f;
  const startMin = Math.max(0, ini.h * 60 + ini.m - 7 * 60);
  const endMin = Math.max(startMin + 20, fin.h * 60 + fin.m - 7 * 60);
  const col = palette[(ev.id + ev.owner_id) % palette.length];
  const extra = ev.my_status === 'declined' ? 'is-declined' : ev.my_status === 'pending' ? 'is-pending' : '';
  const durata = Math.max(15, Math.round(minutiAssoluti(ev.end_at) - minutiAssoluti(ev.start_at)));
  const owner = ev.owner ?? { name: '', photo: null };
  const ps = ev.participants ?? [];
  let partHtml = '';
  if (ps.length) {
    partHtml = `
                                    <div class="evt-parts">
                                        ${ps.slice(0, 4).map((p) => `
                                            <span class="evt-part-av" title="${e(p.name ?? '')}">
                                                ${p.photo_path ? `
                                                    <img src="${e(foto(p.photo_path))}" alt="">
                                                ` : `
                                                    ${e(iniziale(p.name))}
                                                `}
                                            </span>
                                        `).join('')}
                                        ${ps.length > 4 ? `
                                            <span class="evt-part-more">+${ps.length - 4}</span>
                                        ` : ''}
                                    </div>
                                `;
  }
  return `
                                                <a class="cal-evt ${extra} ${ev.can_drag ? 'is-draggable' : ''}"
                           style="top: ${startMin}px; height: ${endMin - startMin}px;
                                  background: ${col.bg};
                                  color: ${col.text};
                                  border-left-color: ${col.border};"
                           href="#"
                           onclick="calOpenDetail(${ev.id}); return false;"
                           data-event-id="${ev.id}"
                           data-duration="${durata}"
                           data-start="${e(ev.start_at)}"
                           ${ev.can_drag ? 'draggable="true"' : ''}>
                            <span class="evt-owner-av" style="background: ${col.border};">
                                ${owner.photo ? `
                                    <img src="${e(foto(owner.photo))}" alt="">
                                ` : `
                                    ${e(iniziale(owner.name ?? '?'))}
                                `}
                            </span>
                            <div class="evt-body">
                                <div class="evt-title">${e(ev.title)}</div>
                                <div class="evt-meta">
                                    <span class="evt-time">${s.hm}–${f.hm}</span>
                                    <span class="evt-dot">•</span>
                                    <span class="evt-owner">${e(owner.name ?? '')}</span>
                                </div>
                                ${partHtml}
                            </div>
                        </a>
                    `;
}

const FRECCIA_SX = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" width="14" height="14"><polyline points="15 18 9 12 15 6"/></svg>';
const FRECCIA_DX = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" width="14" height="14"><polyline points="9 18 15 12 9 6"/></svg>';

/** Barra, intestazioni dei giorni e griglia con gli eventi (div.cal-wrap). */
export function htmlGriglia(d: DatiCalendario, cora: boolean): string {
  const palette = cora ? PALETTE_CORA : PALETTE;
  const v = e(d.vista);
  const teste = d.vista === 'week' ? `
    <div class="cal-grid" style="border-bottom: 1px solid var(--cal-line);">
        <div class="cal-time-col" style="height: 56px;"></div>
        <div class="cal-days is-week" style="background: white; min-height: 56px;">
            ${d.giorni.map((g) => {
    const hd = d.festivita[g];
    return `
                                <div class="cal-day-head ${g === d.oggi ? 'is-today' : ''} ${hd ? 'is-holiday' : ''}"
                     ${hd ? `title="Festivita: ${e(hd)}"` : ''}>
                    <div class="dow">${DOW[dow(g)]}</div>
                    <div class="dnum">${+g.slice(8, 10)}</div>
                    ${hd ? `
                        <div class="dholiday">${e(hd)}</div>
                    ` : ''}
                </div>
            `;
  }).join('')}
        </div>
    </div>
    ` : '';
  const ora = oraRoma();
  const colonne = d.giorni.map((g) => {
    const hd = d.festivita[g];
    const oggi = g === d.oggi;
    const nowMin = ora.h * 60 + ora.m - 7 * 60;
    return `
                <div class="cal-day-col ${oggi ? 'is-today' : ''} ${hd ? 'is-holiday' : ''}" data-day="${e(g)}">
                    ${hd ? `
                        <div class="cal-day-holiday-banner" title="Festivita nazionale">
                            <svg viewBox="0 0 24 24" fill="currentColor" width="12" height="12" aria-hidden="true"><path d="M12 2l2.39 6.96H22l-6.18 4.49 2.36 6.96L12 16.9l-6.18 4.51 2.36-6.96L2 8.96h7.61z"/></svg>
                            <span>${e(hd)}</span>
                        </div>
                    ` : ''}
                    ${d.eventi.map((ev) => evento(ev, g, palette)).join('')}

                    ${oggi && nowMin >= 0 && nowMin <= 14 * 60 ? `
                        <div class="cal-now-line" style="top: ${nowMin}px;"></div>
                    ` : ''}
                </div>
            `;
  }).join('');
  const ore = Array.from({ length: 14 }, (_, i) => `
                <div class="cal-time-slot">${String(7 + i).padStart(2, '0')}:00</div>
            `).join('');
  return `<div class="cal-wrap">
    <div class="cal-sticky-head">
    <div class="cal-toolbar" data-tour="cal-toolbar">
        <div class="cal-nav">
            <a href="?view=${v}&amp;d=${d.prima}" class="cal-nav-btn" aria-label="Precedente">
                ${FRECCIA_SX}
            </a>
            <span class="cal-nav-label">${e(d.etichetta)}</span>
            <a href="?view=${v}&amp;d=${d.dopo}" class="cal-nav-btn" aria-label="Successivo">
                ${FRECCIA_DX}
            </a>
            <a href="?view=${v}&amp;d=${d.oggi}" class="cal-nav-btn" aria-label="Oggi" style="width:auto; padding:0 12px; font-size:12px; font-weight:600;">Oggi</a>
        </div>
        <div class="cal-view-toggle">
            <a href="?view=day&amp;d=${d.rif}" class="${d.vista === 'day' ? 'active' : ''}">Giorno</a>
            <a href="?view=week&amp;d=${d.rif}" class="${d.vista === 'week' ? 'active' : ''}">Settimana</a>
        </div>
        <button type="button" class="cal-add-btn" onclick="calOpenModal()">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
            Nuovo evento
        </button>
    </div>

    <!-- Day headers (solo week) -->
    ${teste}
    </div><!-- /cal-sticky-head -->

    <div class="cal-grid" id="calGrid">
        <div class="cal-time-col">
            ${ore}
        </div>
        <div class="cal-days ${d.vista === 'week' ? 'is-week' : 'is-day'}" id="calDays">
            ${colonne}
        </div>
    </div>
</div>`;
}

/** Contatti invitabili nella finestra dell'evento (escluso chi sta usando la pagina). */
export function htmlContatti(d: DatiCalendario): string {
  let out = '';
  for (const [gruppo, lista] of Object.entries(d.contatti)) {
    for (const c of lista) {
      if (!c.id) continue;
      if (gruppo === d.chiamante.tipo && c.id === d.chiamante.id) continue;
      const nome = gruppo === 'employee' ? `${c.last_name ?? ''} ${c.first_name ?? ''}`.trim() : (c.name ?? `Utente #${c.id}`);
      out += `
                            <div class="cal-contact-item"
                                 data-type="${e(gruppo)}"
                                 data-id="${c.id}"
                                 data-name="${e(nome)}"
                                 data-search="${e(nome.toLowerCase())}"
                                 data-photo="${e(c.photo_path ?? '')}"
                                 onclick="calToggleContactPick(this)">
                                <span class="av" style="background: #0b3aa4;">
                                    ${c.photo_path ? `
                                        <img src="${e(foto(c.photo_path))}" alt="">
                                    ` : `
                                        ${e(iniziale(nome))}
                                    `}
                                </span>
                                <span>${e(nome)}</span>
                            </div>
                        `;
    }
  }
  return out;
}
