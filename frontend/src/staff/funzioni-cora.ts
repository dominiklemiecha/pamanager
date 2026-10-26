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

P.estendi('FieldVisibility::FIELDS', () => P.assoc([
  ['username', 'Username'], ['fiscal_code', 'Codice fiscale'], ['first_name', 'Nome'], ['last_name', 'Cognome'], ['email', 'Email'], ['phone', 'Telefono'],
  ['address', 'Indirizzo di residenza'], ['birth_date', 'Data di nascita'], ['department_id', 'Reparto'], ['position', 'Posizione'],
  ['hire_date', 'Data assunzione'], ['job_level', 'Livello'], ['ral_amount', 'RAL annua'], ['monthly_salary', 'Retribuzione mensile'], ['iban', 'IBAN'],
  ['medical_certs', 'Certificati medici'],
]));
P.estendi('FieldVisibility::ROLES', () => P.assoc([['admin', 'Amministratore'], ['accountant', 'Commercialista'], ['admin_reparto', 'Admin reparto']]));

// Scheda dipendente: dati calcolati dal backend (variabili __* della pagina).
const daV = (chiave: string) => (...a: any[]) => a[a.length - 1]?.V?.[chiave] ?? null;
P.estendi('Tenant::currentCompanyId', daV('__cid'));
P.estendi('LeaveBalance::companyDefaults', daV('__compDefaults'));
P.estendi('LeaveBalance::getForEmployee', daV('__saldi'));
P.estendi('LeaveBalance::availableCcnls', daV('__ccnls'));
P.estendi('EmployeeDocument::getByEmployee', daV('__edDocs'));
P.estendi('LeaveBalance::TYPES', () => ['ferie', 'permesso']);
P.estendi('Probation::ALERT_LEAD_DAYS', () => 14);
const MESI_IT = ['', 'Gennaio', 'Febbraio', 'Marzo', 'Aprile', 'Maggio', 'Giugno', 'Luglio', 'Agosto', 'Settembre', 'Ottobre', 'Novembre', 'Dicembre'];
P.estendi('getMonthName', (m: any) => MESI_IT[P.int(m)] ?? '');

/**
 * EmployeeDocument::statusMessage. Correzione: per gli errori si mostrano solo
 * i messaggi che produce la pagina, non testo libero passato nell'indirizzo.
 */
const ERRORI_DOCUMENTI = ['Documento non trovato', 'Il nome non puo essere vuoto', 'Data scadenza non valida', 'Nessun dato da aggiornare', 'Errore durante il salvataggio',
  'Errore upload (codice 4)', 'File troppo grande. Massimo 30MB', 'Tipo MIME non consentito: il contenuto non corrisponde al tipo di file', 'Dipendente non trovato',
  'Nome documento obbligatorio', 'unknown'];
P.estendi('EmployeeDocument::statusMessage', (s: any) => {
  const st = P.s(s);
  if (st === '') return null;
  if (st.startsWith('uploaded')) { const n = P.int(st.slice(9)); return P.assoc([['type', 'success'], ['text', n > 1 ? `${n} documenti caricati.` : 'Documento caricato.']]); }
  if (st.startsWith('partial_')) {
    const [ok, tot, ...resto] = st.slice(8).split('_');
    const primo = resto.join('_').trim();
    const noto = ERRORI_DOCUMENTI.some((x) => primo.endsWith(`: ${x}`) || primo.includes(': Estensione non consentita'));
    return P.assoc([['type', 'warning'], ['text', `Caricati ${P.int(ok)} documenti su ${P.int(tot)}.${primo !== '' && noto ? ` Primo errore: ${primo}` : ''}`]]);
  }
  if (st.startsWith('error')) {
    const t = st.slice(5).replace(/^_+/, '');
    const noto = ERRORI_DOCUMENTI.some((x) => t === x || t.endsWith(`: ${x}`)) || /: Estensione non consentita: \.[a-z0-9]{0,10} \(permesse: [a-z, ]+\)$/.test(t);
    return P.assoc([['type', 'danger'], ['text', noto ? `Errore: ${t}` : "Errore durante l'operazione."]]);
  }
  const fissi: Record<string, [string, string]> = {
    updated: ['success', 'Documento aggiornato.'], deleted: ['success', 'Documento eliminato.'], no_file: ['danger', 'Nessun file selezionato.'],
    invalid: ['danger', 'Richiesta non valida.'], notfound: ['danger', 'Documento non trovato.'],
  };
  return fissi[st] ? P.assoc([['type', fissi[st][0]], ['text', fissi[st][1]]]) : null;
});

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

/** Richieste di assunzione (HireRequest.php): letture dal backend, etichette qui. */
P.estendi('HireRequest::getById', daV('__hr'));
P.estendi('HireRequest::getFiles', daV('__files'));
P.estendi('HireRequest::listForCurrent', daV('__rows'));
P.estendi('HireRequest::findConsulenteForCompany', daV('__consulente'));
P.estendi('Tenant::currentCompany', daV('__company'));
const STATI_ASSUNZIONE: Array<[string, string]> = [
  ['draft', 'Bozza'], ['awaiting_prospects', 'In attesa prospetti'], ['prospects_review', 'Prospetti da approvare'], ['approved', 'Da contrattualizzare'],
  ['contract_pending', 'Contratto da firmare'], ['contract_signed', 'Contratto firmato'], ['direct_hire', 'Assunzione diretta'], ['rejected', 'Rifiutata'], ['cancelled', 'Annullata'],
];
P.estendi('HireRequest::statuses', () => P.assoc(STATI_ASSUNZIONE));
P.estendi('HireRequest::statusLabel', (s: any) => STATI_ASSUNZIONE.find(([k]) => k === P.s(s))?.[1] ?? s);
P.estendi('HireRequest::EDITABLE_STATUSES', () => ['draft', 'awaiting_prospects', 'prospects_review']);
P.estendi('HireRequest::BYPASSABLE_STATUSES', () => ['draft', 'awaiting_prospects', 'prospects_review']);
P.estendi('HireRequest::composeAddress', (hr: any) => {
  const parti: string[] = [];
  if (P.t(P.g(hr, 'residence_address'))) parti.push(P.s(P.g(hr, 'residence_address')));
  let luogo = `${P.s(P.g(hr, 'residence_cap'))} ${P.s(P.g(hr, 'residence_city'))}`.trim();
  if (P.t(P.g(hr, 'residence_province'))) luogo = `${luogo} (${P.s(P.g(hr, 'residence_province'))})`.trim();
  if (luogo !== '') parti.push(luogo);
  return parti.length ? parti.join(', ') : null;
});
P.estendi('HireRequest::workDaysLabels', (s: any) => P.s(s).split(',').map((g) => GIORNI.find(([k]) => k === g.trim())?.[1]).filter(Boolean).join(', '));
const CONTRATTI_ASSUNZIONE: Array<[string, string]> = [['contract_indeterminato', 'Indeterminato'], ['contract_determinato', 'Determinato'], ['contract_apprendistato', 'Apprendistato'],
  ['contract_tirocinio', 'Tirocinio/Stage'], ['contract_agevolata', 'Agevolata da discutere']];
P.estendi('HireRequest::contractTypesLabels', (hr: any) => CONTRATTI_ASSUNZIONE.filter(([k]) => P.t(P.g(hr, k))).map(([, l]) => l).join(', '));
/** footer-admin incluso a meta' pagina prima di exit: il layout lo disegna gia' PaginaStaff. */
P.registraWidget('footer-admin', () => undefined);

/** Academy (Academy.php, AcademyCertificate.php): letture dal backend, costanti ed etichette qui. */
P.estendi('Academy::getAssignment', daV('__logAssignmentRow'));
P.estendi('AcademyCertificate::getTemplates', daV('__templates'));
P.estendi('AcademyCertificate::sampleValues', daV('__sampleValues'));
P.estendi('AcademyCertificate::defaultLayout', daV('__defaultLayout'));
P.estendi('Academy::COURSE_STATUSES', () => P.assoc([['draft', 'Bozza'], ['published', 'Pubblicato'], ['archived', 'Archiviato']]));
P.estendi('Academy::DUE_REMINDER_DAYS', () => 3);
P.estendi('Academy::STATUS_LABELS', () => P.assoc([['not_started', 'Da iniziare'], ['in_progress', 'In corso'], ['completed', 'Completato'], ['overdue', 'Scaduto']]));
P.estendi('Academy::EVENT_LABELS', () => P.assoc([
  ['course_created', 'Corso creato'], ['course_published', 'Corso pubblicato'], ['course_archived', 'Corso archiviato'], ['material_added', 'Materiale aggiunto'],
  ['material_removed', 'Materiale rimosso'], ['assigned', 'Corso assegnato'], ['unassigned', 'Assegnazione rimossa'], ['notified', 'Notifica inviata'], ['started', 'Corso iniziato'],
  ['material_opened', 'Materiale aperto'], ['completed', 'Corso completato'], ['reminded', 'Sollecito inviato'], ['due_reminder', 'Promemoria scadenza inviato'],
  ['certificate_generated', 'Attestato generato'], ['certificate_downloaded', 'Attestato scaricato'],
]));
P.estendi('AcademyCertificate::PLACEHOLDERS', () => P.assoc([
  ['Dipendente', ['{NOME}', '{COGNOME}', '{NOME_COGNOME}', '{COGNOME_NOME}', '{CF}', '{DATA_NASCITA}', '{LUOGO_NASCITA}', '{MANSIONE}', '{REPARTO}']],
  ['Corso', ['{CORSO}', '{ORE}', '{DATA_INIZIO}', '{DATA_COMPLETAMENTO}', '{AZIENDA}']],
  ['Attestato', ['{NUMERO_ATTESTATO}', '{DATA_EMISSIONE}', '{CODICE_VERIFICA}', '{URL_VERIFICA}']],
]));
/** Stato di un'assegnazione rispetto alla scadenza (oggi a Roma). */
P.estendi('Academy::statusOf', (a: any, scadenza: any) => {
  if (P.t(P.g(a, 'completed_at'))) return 'completed';
  const oggi = new Intl.DateTimeFormat('sv-SE', { timeZone: 'Europe/Rome' }).format(new Date());
  if (P.t(scadenza) && P.s(scadenza) < oggi) return 'overdue';
  return P.t(P.g(a, 'started_at')) ? 'in_progress' : 'not_started';
});
const ATTORI_ACADEMY: Record<string, string> = { admin: 'HR', consulente_lavoro: 'Consulente', admin_reparto: 'Resp. reparto', accountant: 'Commercialista', formatore: 'Resp. formazione' };
P.estendi('Academy::actorLabel', (ev: any) => {
  const tipo = P.s(P.g(ev, 'actor_type'));
  if (tipo === 'employee') return `${P.s(P.g(ev, 'first_name'))} ${P.s(P.g(ev, 'last_name'))}`.trim() || `Dipendente #${P.s(P.g(ev, 'actor_id'))}`;
  if (tipo === 'system') return 'Sistema';
  const u = P.g(ev, 'actor_username');
  return (ATTORI_ACADEMY[tipo] ?? tipo) + (P.t(u) ? ` (${P.s(u)})` : '');
});

/** Area del responsabile di reparto. */
P.estendi('LeaveRequest::countPendingByDepartment', daV('__pendingCountDept'));
const GIORNI_IT = ['Domenica', 'Lunedì', 'Martedì', 'Mercoledì', 'Giovedì', 'Venerdì', 'Sabato'];
/** getItalianDate (helpers/validation.php): "Lunedì 26 Ottobre 2026", ora di Roma. */
P.estendi('getItalianDate', (...a: any[]) => {
  const [d = null] = argomenti(a);
  const giorno = P.t(d) ? P.s(d).slice(0, 10) : new Intl.DateTimeFormat('sv-SE', { timeZone: 'Europe/Rome' }).format(new Date());
  const x = new Date(`${giorno}T12:00:00Z`);
  return `${GIORNI_IT[x.getUTCDay()]} ${x.getUTCDate()} ${MESI_IT[x.getUTCMonth() + 1]} ${x.getUTCFullYear()}`;
});
