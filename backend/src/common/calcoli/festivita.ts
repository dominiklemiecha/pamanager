/**
 * Festivita' nazionali italiane, come ItalianHolidays.php: 10 date fisse piu'
 * Pasqua e Pasquetta (algoritmo di Gauss per il calendario gregoriano).
 * Nessun santo patrono.
 */
const FISSE: Array<[string, string]> = [
  ['01-01', 'Capodanno'],
  ['01-06', 'Epifania'],
  ['04-25', 'Festa della Liberazione'],
  ['05-01', 'Festa del Lavoro'],
  ['06-02', 'Festa della Repubblica'],
  ['08-15', 'Ferragosto'],
  ['11-01', 'Ognissanti'],
  ['12-08', 'Immacolata Concezione'],
  ['12-25', 'Natale'],
  ['12-26', 'Santo Stefano'],
];

const cache = new Map<number, Map<string, string>>();

export function dataPasqua(anno: number): [number, number] {
  const a = anno % 19;
  const b = Math.floor(anno / 100);
  const c = anno % 100;
  const d = Math.floor(b / 4);
  const e = b % 4;
  const f = Math.floor((b + 8) / 25);
  const g = Math.floor((b - f + 1) / 3);
  const h = (19 * a + b - d - g + 15) % 30;
  const i = Math.floor(c / 4);
  const k = c % 4;
  const l = (32 + 2 * e + 2 * i - h - k) % 7;
  const m = Math.floor((a + 11 * h + 22 * l) / 451);
  const mese = Math.floor((h + l - 7 * m + 114) / 31);
  const giorno = ((h + l - 7 * m + 114) % 31) + 1;
  return [mese, giorno];
}

/** AAAA-MM-GG -> nome, ordinato per data. */
export function festivitaAnno(anno: number): Map<string, string> {
  const c = cache.get(anno);
  if (c) return c;
  const out: Array<[string, string]> = FISSE.map(([md, nome]) => [`${anno}-${md}`, nome]);
  const [m, g] = dataPasqua(anno);
  const pasqua = new Date(Date.UTC(anno, m - 1, g));
  const lunedi = new Date(pasqua.getTime() + 86400_000);
  out.push([pasqua.toISOString().slice(0, 10), 'Pasqua'], [lunedi.toISOString().slice(0, 10), 'Pasquetta']);
  out.sort((x, y) => (x[0] < y[0] ? -1 : x[0] > y[0] ? 1 : 0));
  const mappa = new Map(out);
  cache.set(anno, mappa);
  return mappa;
}

export function nomeFestivita(ymd: string): string | null {
  if (!/^\d{4}-\d{2}-\d{2}$/.test(ymd)) return null;
  return festivitaAnno(Number(ymd.slice(0, 4))).get(ymd) ?? null;
}

export const isFestivo = (ymd: string): boolean => nomeFestivita(ymd) !== null;
