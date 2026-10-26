/**
 * Periodo di prova letto dal testo di un contratto (ProbationParser.php): e'
 * un suggerimento che l'HR conferma o corregge. Cerca "prova" e poco dopo
 * una durata ("3 mesi", "sei mesi", "90 giorni", "mesi 3", "giorni novanta").
 */
const PAROLE: Record<string, number> = {
  uno: 1, una: 1, due: 2, tre: 3, quattro: 4, cinque: 5, sei: 6, sette: 7, otto: 8, nove: 9, dieci: 10, undici: 11, dodici: 12, tredici: 13,
  quattordici: 14, quindici: 15, venti: 20, trenta: 30, quaranta: 40, quarantacinque: 45, sessanta: 60, novanta: 90, centoventi: 120, centottanta: 180,
};

export interface EsitoProva { end_date: string | null; months: number | null; days: number | null; weeks: number | null; raw: string | null }

const unita = (u: string) => (u.startsWith('mes') ? 'month' : u.startsWith('settiman') ? 'week' : 'day');
const intero = (t: string) => (/^\d+$/.test(t.trim()) ? Number.parseInt(t, 10) : PAROLE[t.trim().toLowerCase()] ?? 0);

function durata(finestra: string): { n: number; unit: string; raw: string } | null {
  const unitaRe = 'mes[ei]|giorn[oi]|settiman[ae]';
  const numeri = Object.keys(PAROLE).join('|');
  // \b del PHP con /u: confine tra caratteri di parola Unicode
  const b = '(?<![\\p{L}\\p{N}_])';
  const e = '(?![\\p{L}\\p{N}_])';
  let m = new RegExp(`${b}(\\d{1,3}|${numeri})${e}[\\s()a-z]{0,20}?${b}(${unitaRe})${e}`, 'u').exec(finestra);
  if (m) {
    const n = intero(m[1]);
    if (n > 0 && n <= 730) return { n, unit: unita(m[2]), raw: m[0] };
  }
  m = new RegExp(`${b}(${unitaRe})${e}[\\s()a-z]{0,10}?${b}(\\d{1,3}|${numeri})${e}`, 'u').exec(finestra);
  if (m) {
    const n = intero(m[2]);
    if (n > 0 && n <= 730) return { n, unit: unita(m[1]), raw: m[0] };
  }
  return null;
}

/** Fine prova = inizio + durata (DateTime::add del PHP: mesi di calendario). */
function fine(inizio: string | null, unit: string, n: number): string | null {
  if (!inizio || !/^\d{4}-\d{2}-\d{2}/.test(inizio)) return null;
  const [a, m, g] = inizio.slice(0, 10).split('-').map(Number);
  const d = unit === 'month' ? new Date(Date.UTC(a, m - 1 + n, g)) : new Date(Date.UTC(a, m - 1, g + (unit === 'week' ? n * 7 : n)));
  return Number.isNaN(d.getTime()) ? null : d.toISOString().slice(0, 10);
}

export function periodoDiProva(testo: string, inizio: string | null): EsitoProva {
  const vuoto: EsitoProva = { end_date: null, months: null, days: null, weeks: null, raw: null };
  if (testo.trim() === '') return vuoto;
  const piatto = testo.toLowerCase().replace(/\s+/gu, ' ');
  let trovato: { n: number; unit: string; raw: string } | null = null;
  for (let pos = piatto.indexOf('prova'); pos !== -1 && !trovato; pos = piatto.indexOf('prova', pos + 5)) {
    trovato = durata([...piatto.slice(pos)].slice(0, 160).join(''));
  }
  if (!trovato) return vuoto;
  return {
    end_date: fine(inizio, trovato.unit, trovato.n),
    months: trovato.unit === 'month' ? trovato.n : null, days: trovato.unit === 'day' ? trovato.n : null, weeks: trovato.unit === 'week' ? trovato.n : null,
    raw: trovato.raw.trim(),
  };
}
