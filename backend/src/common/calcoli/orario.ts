import { giorniNelMese, giornoSettimana, phpRound, romaParti, strtotimeRoma } from './php';
import { isFestivo } from './festivita';

export const CHIAVI_GIORNI = ['mon', 'tue', 'wed', 'thu', 'fri', 'sat', 'sun'] as const;
export type ChiaveGiorno = (typeof CHIAVI_GIORNI)[number];

/**
 * Giorni lavorativi tra due date incluse, come LeaveBalance::countWorkingDays.
 *
 * ATTENZIONE: riproduce anche il comportamento del PHP sui cambi dell'ora
 * legale (avanza di 86400 secondi dalla mezzanotte locale): un intervallo che
 * attraversa l'ultima domenica di marzo o di ottobre conta un giorno in meno.
 * E' segnalato come anomalia da decidere; finche' non si decide resta uguale.
 */
export function contaGiorniLavorativi(da: string, a: string, giorni: string[]): number {
  if (da > a) return 0;
  const set = new Set(giorni);
  let n = 0;
  let cur = strtotimeRoma(da);
  const fine = strtotimeRoma(a);
  while (cur <= fine) {
    if (set.has(CHIAVI_GIORNI[romaParti(cur).n - 1])) n++;
    cur += 86400;
  }
  return n;
}

/**
 * Giorni lun-ven tra due date, come LeaveRequest::calculateWorkingDays
 * (non guarda ne' l'orario dell'azienda ne' le festivita').
 */
export function contaGiorniLunVen(da: string, a: string): number {
  let n = 0;
  const d = new Date(`${da}T00:00:00Z`);
  const f = new Date(`${a}T00:00:00Z`);
  while (d <= f) {
    const g = d.getUTCDay();
    if (g !== 0 && g !== 6) n++;
    d.setUTCDate(d.getUTCDate() + 1);
  }
  return n;
}

const secondi = (hms: string): number | null => {
  const m = /^(\d{1,2}):(\d{2})(?::(\d{2}))?$/.exec(hms.trim());
  if (!m) return null;
  return strtotimeRoma('1970-01-01', `${m[1].padStart(2, '0')}:${m[2]}:${m[3] ?? '00'}`);
};

/**
 * Ore di un permesso al netto della pausa pranzo aziendale, come
 * LeaveBalance::effectiveHours: pausa assente o incompleta = niente da togliere.
 */
export function oreEffettive(inizio: string, fine: string, pausaInizio?: string | null, pausaFine?: string | null): number {
  const s = secondi(inizio);
  const e = secondi(fine);
  if (s === null || e === null || e <= s) return 0;
  let sec = e - s;
  if (pausaInizio && pausaFine) {
    const ls = secondi(pausaInizio);
    const le = secondi(pausaFine);
    if (ls !== null && le !== null && le > ls) sec -= Math.max(0, Math.min(e, le) - Math.max(s, ls));
  }
  return phpRound(sec / 3600, 2);
}

export interface ConfigBuoni {
  enabled: boolean;
  excluded: boolean;
  workingDays: string[];
  hoursPerDay: number;
  smartWorkingDays: string[];
  minHoursEnabled?: boolean;
  minHours: number;
  swEligible: boolean;
}
export type AssenzeGiorno = Record<string, { full?: boolean; hours?: number; sw?: boolean }>;

/** Buoni pasto del mese, come MealVoucher::monthlyCount. */
export function buoniPastoMese(cfg: ConfigBuoni, anno: number, mese: number, assenze: AssenzeGiorno = {}): number {
  if (!cfg.enabled || cfg.excluded) return 0;
  const lavorativi = new Set(cfg.workingDays);
  const sw = new Set(cfg.smartWorkingDays);
  const sogliaAttiva = cfg.minHoursEnabled === undefined || cfg.minHoursEnabled;
  let n = 0;
  for (let g = 1; g <= giorniNelMese(anno, mese); g++) {
    const ymd = `${anno}-${String(mese).padStart(2, '0')}-${String(g).padStart(2, '0')}`;
    const chiave = CHIAVI_GIORNI[giornoSettimana(ymd) - 1];
    if (!lavorativi.has(chiave)) continue;
    if (isFestivo(ymd)) continue;
    const a = assenze[ymd];
    const giornoSw = sw.has(chiave) || !!a?.sw;
    if (giornoSw && !cfg.swEligible) continue;
    if (a?.full) continue;
    const ore = cfg.hoursPerDay - (a ? Number(a.hours ?? 0) : 0);
    if (sogliaAttiva ? ore < cfg.minHours : ore <= 0) continue;
    n++;
  }
  return n;
}
