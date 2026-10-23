/* eslint-disable @typescript-eslint/no-explicit-any */
import { P } from '../php/runtime';

/**
 * Funzioni e costanti delle classi di Cora usate dai template PHP convertiti
 * (helpers/validation.php, helpers/avatar.php, LeaveRequest, LeaveBalance),
 * con lo stesso risultato del PHP.
 */

/** Le estensioni ricevono in coda { V, F }: si tolgono per i parametri facoltativi. */
const argomenti = (a: any[]) => (a.length && a[a.length - 1] && typeof a[a.length - 1] === 'object' && 'V' in a[a.length - 1] ? a.slice(0, -1) : a);

const TIPI_ASSENZA: Array<[string, string]> = [
  ['ferie', 'Ferie'], ['permesso', 'Permesso'], ['malattia', 'Malattia'], ['permesso_104', 'Permesso L.104'],
  ['congedo_parentale', 'Congedo Parentale'], ['congedo_separazione', 'Congedo Separazione'], ['congedo_mestruale', 'Congedo Mestruale'],
  ['altro', 'Altro'], ['chiusura', 'Chiusura aziendale'], ['smart_working', 'Smart working'],
];
const STATI_RICHIESTA: Array<[string, string]> = [['pending', 'In Attesa'], ['approved', 'Approvata'], ['rejected', 'Rifiutata'], ['cancelled', 'Annullata']];

P.estendi('LeaveRequest::LEAVE_TYPES', () => P.assoc(TIPI_ASSENZA));
P.estendi('LeaveRequest::STATUSES', () => P.assoc(STATI_RICHIESTA));

P.estendi('formatDate', (...a: any[]) => {
  const [d, f = 'd/m/Y'] = argomenti(a);
  return P.t(d) ? P.nuovo('DateTime', [d]).format(f) : '-';
});
P.estendi('formatDateTime', (...a: any[]) => {
  const [d, f = 'd/m/Y H:i'] = argomenti(a);
  return P.t(d) ? P.nuovo('DateTime', [d]).format(f) : '-';
});

P.estendi('employeeAvatarHtml', (...a: any[]) => {
  const [emp, classe, extra = ''] = argomenti(a);
  const h = (s: any) => P.fn.htmlspecialchars(s, 3);
  const nome = P.g(emp, 'first_name') ?? '';
  const cognome = P.g(emp, 'last_name') ?? '';
  const iniziali = P.fn.strtoupper(P.fn.mb_substr(nome, 0, 1) + P.fn.mb_substr(cognome, 0, 1));
  const alt = h(P.fn.trim(`${P.s(nome)} ${P.s(cognome)}`));
  const stile = P.t(extra) ? ` style="${h(extra)}"` : '';
  const foto = P.g(emp, 'photo_path');
  if (P.t(foto)) {
    const url = h(`${P.costante('PUBLIC_URL')}/${P.fn.ltrim(foto, '/')}`);
    return `<div class="${h(classe)} has-photo"${stile}><img src="${url}" alt="${alt}" loading="lazy" decoding="async"></div>`;
  }
  return `<div class="${h(classe)}"${stile}>${h(iniziali)}</div>`;
});

const GIORNI: Array<[string, string]> = [['mon', 'Lun'], ['tue', 'Mar'], ['wed', 'Mer'], ['thu', 'Gio'], ['fri', 'Ven'], ['sat', 'Sab'], ['sun', 'Dom']];
P.estendi('LeaveBalance::allDayKeys', () => GIORNI.map(([k]) => k));
P.estendi('LeaveBalance::dayLabel', (k: any) => GIORNI.find(([g]) => g === P.s(k))?.[1] ?? k);

/** Comunicazioni: il testo arriva dal backend gia' ripulito (sanitizzaHtmlRicco). */
P.estendi('sanitizeRichHtml', (h: any) => P.s(h));
P.estendi('Communication::PRIORITIES', () => P.assoc([['low', 'Bassa'], ['normal', 'Normale'], ['high', 'Alta']]));
P.estendi('Communication::attachmentUrl', (id: any) => `/communication_file.php?id=${P.int(id)}`);

/** Admin globale "vero": lo calcola il backend (variabile __veroGlobale). */
P.estendi('Tenant::isCurrentUserTrueGlobalAdmin', (...a: any[]) => P.t(a[a.length - 1]?.V?.__veroGlobale));

/** Giorni dal lunedi' al venerdi' tra due date incluse (LeaveRequest::calculateWorkingDays). */
P.estendi('LeaveRequest::calculateWorkingDays', (inizio: string, fine: string) => {
  const d = new Date(`${String(inizio).slice(0, 10)}T12:00:00Z`);
  const f = new Date(`${String(fine).slice(0, 10)}T12:00:00Z`);
  let giorni = 0;
  while (d <= f) {
    const g = d.getUTCDay();
    if (g !== 0 && g !== 6) giorni++;
    d.setUTCDate(d.getUTCDate() + 1);
  }
  return giorni;
});

/** Ore effettive di un permesso a ore, tolta la pausa pranzo (LeaveBalance::effectiveHours). */
const secondi = (s: any): number | false => {
  const m = /^\s*(\d{1,2}):(\d{2})(?::(\d{2}))?\s*$/.exec(P.s(s));
  if (!m || +m[1] > 24 || +m[2] > 59) return false;
  return +m[1] * 3600 + +m[2] * 60 + +(m[3] ?? 0);
};
P.estendi('LeaveBalance::effectiveHours', (...a: any[]) => {
  const [inizio, fine, pausaDa = null, pausaA = null] = argomenti(a);
  const s = secondi(inizio);
  const e = secondi(fine);
  if (s === false || e === false || e <= s) return 0.0;
  let sec = e - s;
  if (P.t(pausaDa) && P.t(pausaA)) {
    const ls = secondi(pausaDa);
    const le = secondi(pausaA);
    if (ls !== false && le !== false && le > ls) sec -= Math.max(0, Math.min(e, le) - Math.max(s, ls));
  }
  return P.fn.round(sec / 3600, 2);
});
