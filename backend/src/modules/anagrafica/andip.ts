/**
 * Lettura del tracciato ANDIP di TeamSystem (anagrafica e dati contrattuali
 * dei dipendenti, CSV separato da ";"). Funzioni pure: niente database.
 *
 * Formato osservato: UTF-8 con BOM, prima riga di intestazione, date
 * gg/mm/aaaa con 00/00/0000 per "nessuna data", decimali con la virgola,
 * CAP numerico senza zeri iniziali (165 = 00165).
 */

export interface RigaAndip {
  riga: number;
  azienda: string;
  filiale: number | null;
  descrFiliale: string;
  matricola: string;
  cognome: string;
  nome: string;
  codiceFiscale: string;
  dataNascita: string | null;
  dataAssunzione: string | null;
  dataCessazione: string | null;
  indirizzo: string | null;
  email: string | null;
  telefono: string | null;
  iban: string | null;
  partTime: number | null;
  qualifica: string;
  /** Tutte le colonne del tracciato (intestazione -> valore), anche quelle che Cora non usa. */
  dati: Record<string, string>;
  errori: string[];
}

/** Colonne lette, per nome di intestazione. */
const COLONNE = {
  azienda: 'Azienda', filiale: 'Filiale', descrFiliale: 'Descr.filiale', matricola: 'Matricola', cognome: 'Cognome', nome: 'Nome',
  dataNascita: 'Data nascita', codiceFiscale: 'Codice fiscale', dataAssunzione: 'Data assunzione', dataCessazione: 'Data licenziamento',
  via: 'Indirizzo residenza', cap: 'C.A.P. residenza', comune: 'Comune residenza', provincia: 'Provincia residenza',
  email: 'E-mail', telefono: 'Telefono', cellulare: 'Cellulare', iban: 'IBAN 1° accredito', partTime: '% part-time',
  qualifica1: 'Desc.qualifica Inps 1', qualifica2: 'Desc.qualifica Inps 2',
} as const;

const OBBLIGATORIE: Array<keyof typeof COLONNE> = ['azienda', 'filiale', 'matricola', 'cognome', 'nome', 'codiceFiscale'];

/** Righe e campi di un CSV con ";" (virgolette doppie gestite). */
export function leggiCsv(testo: string, sep = ';'): string[][] {
  const t = testo.replace(/^﻿/, '');
  const righe: string[][] = [];
  let riga: string[] = [];
  let campo = '';
  let virgolette = false;
  for (let i = 0; i < t.length; i++) {
    const ch = t[i];
    if (virgolette) {
      if (ch === '"') {
        if (t[i + 1] === '"') { campo += '"'; i++; } else virgolette = false;
      } else campo += ch;
    } else if (ch === '"' && campo === '') virgolette = true;
    else if (ch === sep) { riga.push(campo); campo = ''; }
    else if (ch === '\n' || ch === '\r') {
      if (ch === '\r' && t[i + 1] === '\n') i++;
      riga.push(campo); righe.push(riga); riga = []; campo = '';
    } else campo += ch;
  }
  if (campo !== '' || riga.length) { riga.push(campo); righe.push(riga); }
  return righe;
}

/** "18/11/1994" -> "1994-11-18"; vuoto o 00/00/0000 -> null; data inesistente -> undefined. */
export function dataAndip(v: string): string | null | undefined {
  const s = v.trim();
  if (s === '' || /^0{1,2}\/0{1,2}\/0{4}$/.test(s)) return null;
  const m = /^(\d{1,2})\/(\d{1,2})\/(\d{4})$/.exec(s);
  if (!m) return undefined;
  const ymd = `${m[3]}-${m[2].padStart(2, '0')}-${m[1].padStart(2, '0')}`;
  const d = new Date(`${ymd}T00:00:00Z`);
  return Number.isNaN(d.getTime()) || d.toISOString().slice(0, 10) !== ymd ? undefined : ymd;
}

/** "37,5" -> 37.5; vuoto o 0 -> null. */
function decimale(v: string): number | null | undefined {
  const s = v.trim();
  if (s === '') return null;
  if (!/^\d+([.,]\d+)?$/.test(s)) return undefined;
  const n = Number(s.replace(',', '.'));
  return n === 0 ? null : n;
}

const testo = (v: string | undefined) => (v ?? '').trim();
const oNull = (v: string) => (v === '' ? null : v);

/** Via, CAP comune (provincia) in un solo campo, come l'indirizzo dell'anagrafica. */
function indirizzo(via: string, cap: string, comune: string, prov: string): string | null {
  const c = /^\d{1,5}$/.test(cap) && Number(cap) > 0 ? cap.padStart(5, '0') : cap === '0' ? '' : cap;
  const luogo = [c, comune].filter(Boolean).join(' ') + (prov ? ` (${prov})` : '');
  return oNull([via, luogo.trim()].filter(Boolean).join(', '));
}

export type EsitoAndip = { ok: true; righe: RigaAndip[] } | { ok: false; errore: string };

/** Legge il file ANDIP: intestazione obbligatoria, una riga per dipendente. */
export function leggiAndip(contenuto: string): EsitoAndip {
  const righe = leggiCsv(contenuto).filter((r) => r.some((c) => c.trim() !== ''));
  if (righe.length < 2) return { ok: false, errore: 'Il file non contiene dipendenti' };
  // Nomi originali delle colonne; un nome ripetuto riceve " (2)", " (3)"...
  const nomi: string[] = [];
  for (const c of righe[0]) {
    const base = c.trim() || `Colonna ${nomi.length + 1}`;
    let nome = base;
    for (let n = 2; nomi.includes(nome); n++) nome = `${base} (${n})`;
    nomi.push(nome);
  }
  const intest = righe[0].map((c) => c.trim().toLowerCase());
  const pos = {} as Record<keyof typeof COLONNE, number>;
  for (const [k, nome] of Object.entries(COLONNE) as Array<[keyof typeof COLONNE, string]>) pos[k] = intest.indexOf(nome.toLowerCase());
  const mancanti = OBBLIGATORIE.filter((k) => pos[k] < 0).map((k) => COLONNE[k]);
  if (mancanti.length) return { ok: false, errore: `Il file non è un tracciato ANDIP: mancano le colonne ${mancanti.join(', ')}` };
  const v = (r: string[], k: keyof typeof COLONNE) => (pos[k] < 0 ? '' : testo(r[pos[k]]));
  const out: RigaAndip[] = [];
  righe.slice(1).forEach((r, i) => {
    const errori: string[] = [];
    const data = (k: keyof typeof COLONNE, etichetta: string) => {
      const d = dataAndip(v(r, k));
      if (d === undefined) { errori.push(`${etichetta} non valida`); return null; }
      return d;
    };
    const fil = v(r, 'filiale');
    const pt = decimale(v(r, 'partTime'));
    if (pt === undefined || (pt !== null && pt > 100)) errori.push('% part-time non valida');
    const cf = v(r, 'codiceFiscale').toUpperCase();
    if (!cf) errori.push('Codice fiscale mancante');
    if (!v(r, 'matricola')) errori.push('Matricola mancante');
    out.push({
      riga: i + 2,
      azienda: v(r, 'azienda'),
      filiale: /^\d+$/.test(fil) ? Number(fil) : null,
      descrFiliale: v(r, 'descrFiliale'),
      matricola: v(r, 'matricola'),
      cognome: v(r, 'cognome'),
      nome: v(r, 'nome'),
      codiceFiscale: cf,
      dataNascita: data('dataNascita', 'Data di nascita'),
      dataAssunzione: data('dataAssunzione', 'Data di assunzione'),
      dataCessazione: data('dataCessazione', 'Data di cessazione'),
      indirizzo: indirizzo(v(r, 'via'), v(r, 'cap'), v(r, 'comune'), v(r, 'provincia')),
      email: oNull(v(r, 'email')),
      telefono: oNull(v(r, 'cellulare')) ?? oNull(v(r, 'telefono')),
      iban: oNull(v(r, 'iban').replace(/\s+/g, '').toUpperCase()),
      partTime: pt ?? null,
      qualifica: [v(r, 'qualifica1'), v(r, 'qualifica2')].filter(Boolean).join(' · '),
      dati: Object.fromEntries(nomi.map((n, j) => [n, testo(r[j])])),
      errori,
    });
  });
  return { ok: true, righe: out };
}
