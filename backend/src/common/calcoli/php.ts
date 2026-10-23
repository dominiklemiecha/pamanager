/**
 * Piccoli equivalenti di funzioni PHP usate nei calcoli, perche' i risultati
 * restino identici al vecchio codice (stesso fuso Europe/Rome, stessi
 * arrotondamenti).
 */

const FUSO = 'Europe/Rome';
const fmt = new Intl.DateTimeFormat('en-GB', {
  timeZone: FUSO, year: 'numeric', month: '2-digit', day: '2-digit', hour: '2-digit', minute: '2-digit', second: '2-digit', weekday: 'short', hourCycle: 'h23',
});
const GIORNI_N: Record<string, number> = { Mon: 1, Tue: 2, Wed: 3, Thu: 4, Fri: 5, Sat: 6, Sun: 7 };

/** round() di PHP: meta' lontano da zero, dopo aver tolto il rumore dei float. */
export function phpRound(v: number, decimali = 0): number {
  const f = 10 ** decimali;
  const x = Number((v * f).toPrecision(15));
  return (Math.sign(x) * Math.round(Math.abs(x))) / f;
}

/** Scomposizione di un istante (secondi) nell'ora di Roma, come date() di PHP. */
export function romaParti(epoch: number): { ymd: string; ora: string; n: number } {
  const p = Object.fromEntries(fmt.formatToParts(new Date(epoch * 1000)).map((x) => [x.type, x.value]));
  return { ymd: `${p.year}-${p.month}-${p.day}`, ora: `${p.hour}:${p.minute}:${p.second}`, n: GIORNI_N[p.weekday] };
}

/**
 * strtotime("AAAA-MM-GG [hh:mm:ss]") di PHP con fuso Europe/Rome: l'istante
 * (secondi) di quell'ora locale. Ora inesistente (salto dell'ora legale): PHP
 * la sposta avanti di un'ora, qui lo stesso.
 */
export function strtotimeRoma(ymd: string, hms = '00:00:00'): number {
  const [h, m, s] = hms.split(':').map(Number);
  const base = Date.UTC(Number(ymd.slice(0, 4)), Number(ymd.slice(5, 7)) - 1, Number(ymd.slice(8, 10)), h, m, s || 0) / 1000;
  for (const ore of [1, 2]) {
    const prova = base - ore * 3600;
    const r = romaParti(prova);
    if (r.ymd === ymd && r.ora === `${String(h).padStart(2, '0')}:${String(m).padStart(2, '0')}:${String(s || 0).padStart(2, '0')}`) return prova;
  }
  return base - 3600;
}

/** date('N', ...) di PHP: 1 = lunedi ... 7 = domenica. */
export function giornoSettimana(ymd: string): number {
  return romaParti(strtotimeRoma(ymd, '12:00:00')).n;
}

/** Oggi in ora di Roma (AAAA-MM-GG). */
export function oggiRoma(adesso = Date.now()): string {
  return romaParti(Math.floor(adesso / 1000)).ymd;
}

/** Giorni del mese (date('t')). */
export function giorniNelMese(anno: number, mese: number): number {
  return new Date(Date.UTC(anno, mese, 0)).getUTCDate();
}
