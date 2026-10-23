/** Formati dei testi di Cora, come i metodi di CoraUi.php. */

/** Nomi salvati in maiuscolo ("LUCA") mostrati come "Luca". */
export function nome(s: string | null | undefined): string {
  const t = String(s ?? '').trim();
  if (t === '' || t !== t.toUpperCase()) return t;
  return t.toLowerCase().replace(/(^|[\s'-])(\p{L})/gu, (_m, a, b) => a + b.toUpperCase());
}

/** 13.5 -> "13,5", 15 -> "15" (number_format con 1 decimale, zeri tolti). */
export function num(n: number): string {
  const f = (Math.round(Math.abs(n) * 10 + 1e-9) / 10) * Math.sign(n || 1);
  const [int, dec] = Math.abs(f).toFixed(1).split('.');
  const conPunti = int.replace(/\B(?=(\d{3})+(?!\d))/g, '.');
  const s = (f < 0 ? '-' : '') + conPunti + ',' + dec;
  return s.replace(/0+$/, '').replace(/,$/, '');
}

const MESI_BREVI = ['', 'gen', 'feb', 'mar', 'apr', 'mag', 'giu', 'lug', 'ago', 'set', 'ott', 'nov', 'dic'];
const MESI = ['', 'gennaio', 'febbraio', 'marzo', 'aprile', 'maggio', 'giugno', 'luglio', 'agosto', 'settembre', 'ottobre', 'novembre', 'dicembre'];

function parti(d: string | Date): { g: number; m: number; a: number } {
  if (typeof d === 'string' && /^\d{4}-\d{2}-\d{2}$/.test(d)) return { a: +d.slice(0, 4), m: +d.slice(5, 7), g: +d.slice(8, 10) };
  const x = new Date(d);
  const p = new Intl.DateTimeFormat('en-CA', { timeZone: 'Europe/Rome', year: 'numeric', month: '2-digit', day: '2-digit' }).format(x);
  return { a: +p.slice(0, 4), m: +p.slice(5, 7), g: +p.slice(8, 10) };
}

/** "23 ott 2026" o "23 ott". */
export function dataBreve(d: string | Date | null | undefined, conAnno = true): string {
  if (!d) return '';
  const { g, m, a } = parti(d);
  return `${g} ${MESI_BREVI[m]}${conAnno ? ` ${a}` : ''}`;
}

/** "12 ottobre 2026". */
export function dataLunga(d: string | Date | null | undefined): string {
  if (!d) return '';
  const { g, m, a } = parti(d);
  return `${g} ${MESI[m]} ${a}`;
}

/** "6 giorni fa", "2 settimane fa"... */
export function fa(dt: string | Date | null | undefined): string {
  if (!dt) return '';
  const diff = Math.max(0, Math.floor((Date.now() - new Date(dt).getTime()) / 1000));
  if (diff < 3600) return 'pochi minuti fa';
  if (diff < 86400) { const h = Math.floor(diff / 3600); return h + (h === 1 ? ' ora fa' : ' ore fa'); }
  const d = Math.floor(diff / 86400);
  if (d < 7) return d === 1 ? '1 giorno fa' : `${d} giorni fa`;
  if (d < 30) { const w = Math.floor(d / 7); return w === 1 ? '1 settimana fa' : `${w} settimane fa`; }
  if (d < 365) { const mo = Math.floor(d / 30); return mo === 1 ? '1 mese fa' : `${mo} mesi fa`; }
  const y = Math.floor(d / 365);
  return y === 1 ? '1 anno fa' : `${y} anni fa`;
}

/** "846 KB", "1,2 MB". */
export function dimensione(bytes: number | null | undefined): string {
  const b = Number(bytes ?? 0);
  if (b >= 1048576) return num(Math.round((b / 1048576) * 10) / 10) + ' MB';
  return Math.max(1, Math.round(b / 1024)) + ' KB';
}

/** Iniziali maiuscole (mb_strtoupper delle prime lettere). */
export function iniziali(n: string | null | undefined, c: string | null | undefined): string {
  return ((n ?? '').slice(0, 1) + (c ?? '').slice(0, 1)).toUpperCase();
}

export const TIPI_ASSENZA: Record<string, string> = {
  ferie: 'Ferie', permesso: 'Permesso', malattia: 'Malattia', permesso_104: 'Permesso L.104', congedo_parentale: 'Congedo Parentale',
  congedo_separazione: 'Congedo Separazione', congedo_mestruale: 'Congedo Mestruale', altro: 'Altro', chiusura: 'Chiusura aziendale', smart_working: 'Smart working',
};

/** Ora di Roma di un istante: { g, m, a, ore, min } (date() del PHP). */
export function partiRoma(dt: string | Date) {
  const p = Object.fromEntries(new Intl.DateTimeFormat('en-GB', { timeZone: 'Europe/Rome', year: 'numeric', month: '2-digit', day: '2-digit', hour: '2-digit', minute: '2-digit', hourCycle: 'h23' })
    .formatToParts(new Date(dt)).map((x) => [x.type, x.value]));
  return { g: p.day, m: p.month, a: p.year, ore: p.hour, min: p.minute };
}

/** Giorni lun-ven tra due date (LeaveRequest::calculateWorkingDays). */
export function giorniLunVen(da: string, a: string): number {
  let n = 0;
  const d = new Date(`${da}T00:00:00Z`);
  const f = new Date(`${a}T00:00:00Z`);
  while (d <= f) { const g = d.getUTCDay(); if (g !== 0 && g !== 6) n++; d.setUTCDate(d.getUTCDate() + 1); }
  return n;
}
