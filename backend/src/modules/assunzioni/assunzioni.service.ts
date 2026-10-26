import { Injectable, Logger } from '@nestjs/common';
import { randomBytes } from 'crypto';
import * as fs from 'fs';
import * as path from 'path';
import { DataSource, EntityManager } from 'typeorm';
import { oggiRoma } from '../../common/calcoli/php';
import { senzaIsolamento } from '../../common/database/isolamento';
import { contenutoCoerente, estensione, FileCaricato } from '../../common/file/caricamenti';
import { cartellaUpload, urlPubblico } from '../../config/app';
import { AnagraficaService, codiceFiscaleValido } from '../anagrafica/anagrafica.service';
import { AuditService, ContestoAudit } from '../audit/audit.service';
import { DocumentiService } from '../documenti/documenti.service';
import { escapeHtml } from '../notifiche/mailer.service';
import { NotificheService } from '../notifiche/notifiche.service';
import { WrikeService } from '../wrike/wrike.service';

type Ctx = ContestoAudit & { tipo: string; id: number };
type Dati = Record<string, any>;
export type Allegati = Record<string, FileCaricato[]>;
type Errore = { success: false; error: string };
export type EsitoAssunzione = { success: true; id: number; employee_id: number; email_sent: boolean; email_error: string | null; temp_password: string | null } | (Errore & { id?: number });
export type EsitoInvio = { success: true; id: number; notified: boolean; notify_error: string | null } | Errore;

export const STATI: Record<string, string> = {
  draft: 'Bozza', awaiting_prospects: 'In attesa prospetti', prospects_review: 'Prospetti da approvare', approved: 'Da contrattualizzare',
  contract_pending: 'Contratto da firmare', contract_signed: 'Contratto firmato', direct_hire: 'Assunzione diretta', rejected: 'Rifiutata', cancelled: 'Annullata',
};
/** Stati in cui l'admin puo' ancora modificare (e inviare) la richiesta. */
export const MODIFICABILI = ['draft', 'awaiting_prospects', 'prospects_review'];
/** Stati da cui l'admin puo' assumere subito senza aspettare il consulente. */
export const BYPASSABILI = ['draft', 'awaiting_prospects', 'prospects_review'];
export const etichettaStato = (s: string) => STATI[s] ?? s;

const CONTRATTI = ['contract_indeterminato', 'contract_determinato', 'contract_apprendistato', 'contract_tirocinio', 'contract_agevolata'];
const ETICHETTE_CONTRATTI = ['Indeterminato', 'Determinato', 'Apprendistato', 'Tirocinio/Stage', 'Agevolata da discutere'];
const GIORNI: Record<string, string> = { mon: 'Lun', tue: 'Mar', wed: 'Mer', thu: 'Gio', fri: 'Ven', sat: 'Sab', sun: 'Dom' };
const CATEGORIE_ADMIN = ['id_doc', 'fiscal_code_doc', 'permit', 'c2'];
const DOCUMENTI_DIPENDENTE: Record<string, string> = { id_doc: 'Documento di riconoscimento', fiscal_code_doc: 'Codice fiscale', permit: 'Permesso di soggiorno', c2: 'Modello C2' };
const ESTENSIONI = ['pdf', 'jpg', 'jpeg', 'png', 'webp', 'heic', 'heif', 'doc', 'docx'];
const MINIMI_DIRETTA: Record<string, string> = { employee_first_name: 'Nome', employee_last_name: 'Cognome', fiscal_code: 'Codice fiscale', employee_email: 'Email account', start_date: 'Data inizio' };
const EMAIL = /^[A-Za-z0-9.!#$%&'*+/=?^_`{|}~-]+@[A-Za-z0-9](?:[A-Za-z0-9-]{0,61}[A-Za-z0-9])?(?:\.[A-Za-z0-9](?:[A-Za-z0-9-]{0,61}[A-Za-z0-9])?)+$/;

/** empty() del PHP su un valore del modulo. */
const pieno = (v: unknown) => !(v === undefined || v === null || v === '' || v === '0' || v === false || v === 0 || (Array.isArray(v) && v.length === 0));
const testo = (v: unknown) => String(v ?? '').trim();
const decimale = (v: unknown) => Number.parseFloat(String(v ?? '').replace(',', '.')) || 0;
/** Giorni di lavoro dal modulo (work_days[]) o dalla riga salvata ("mon,tue"). */
const giorni = (d: Dati): string[] => {
  const v = d['work_days[]'] ?? d.work_days;
  const lista = Array.isArray(v) ? v.map(String) : typeof v === 'string' && v !== '' ? v.split(',') : [];
  return Object.keys(GIORNI).filter((g) => lista.includes(g));
};
const dataValida = (v: string) => {
  if (!/^\d{4}-\d{2}-\d{2}$/.test(v)) return false;
  const d = new Date(`${v}T00:00:00Z`);
  return !Number.isNaN(d.getTime()) && d.toISOString().slice(0, 10) === v;
};

export function indirizzoCompleto(hr: Dati): string | null {
  const parti: string[] = [];
  if (pieno(hr.residence_address)) parti.push(String(hr.residence_address));
  let luogo = `${hr.residence_cap ?? ''} ${hr.residence_city ?? ''}`.trim();
  if (pieno(hr.residence_province)) luogo = `${luogo} (${hr.residence_province})`.trim();
  if (luogo !== '') parti.push(luogo);
  return parti.length ? parti.join(', ') : null;
}
export const etichetteGiorni = (s: string | null) => String(s ?? '').split(',').map((g) => GIORNI[g.trim()]).filter(Boolean).join(', ');
export const etichetteContratti = (hr: Dati) => CONTRATTI.filter((k) => pieno(hr[k])).map((k) => ETICHETTE_CONTRATTI[CONTRATTI.indexOf(k)]).join(', ');
const maiuscolaIniziale = (s: string) => s.charAt(0).toUpperCase() + s.slice(1);

/**
 * Richieste di assunzione lato HR (HireRequest.php): bozza, invio al
 * consulente, approvazione o rifiuto dei prospetti, assunzione diretta.
 *
 * Correzioni rispetto al PHP: ogni richiesta solo nell'azienda attiva (il PHP
 * apriva anche quelle delle altre aziende collegate e, approvandole, cambiava
 * l'azienda della sessione); gli allegati si controllano tutti (tipo dal
 * contenuto, dimensione) prima di scrivere qualcosa; date e ore validate
 * prima del database; nessun messaggio interno del database al browser;
 * gli allegati passati al dipendente restano anche nella pratica (il PHP li
 * spostava e la pratica restava con file mancanti); avvisati solo gli admin
 * dell'azienda.
 */
@Injectable()
export class AssunzioniService {
  private readonly logger = new Logger('Assunzioni');
  constructor(private readonly ds: DataSource, private readonly anagrafica: AnagraficaService, private readonly documenti: DocumentiService,
    private readonly notifiche: NotificheService, private readonly wrike: WrikeService, private readonly audit: AuditService) {}

  async perId(id: number, cid: number): Promise<Dati | null> {
    if (!id) return null;
    const [r] = await this.ds.query('SELECT * FROM hire_requests WHERE id = $1 AND company_id = $2', [id, cid]);
    return r ?? null;
  }

  elenco(cid: number): Promise<Dati[]> {
    return this.ds.query(
      `SELECT hr.*, u.name AS created_by_name FROM hire_requests hr LEFT JOIN users u ON u.id = hr.created_by_user_id
        WHERE hr.company_id = $1 ORDER BY hr.created_at DESC, hr.id DESC`, [cid]);
  }

  allegati(id: number, categoria?: string): Promise<Dati[]> {
    return categoria
      ? this.ds.query('SELECT * FROM hire_request_files WHERE hire_request_id = $1 AND category = $2 ORDER BY uploaded_at ASC, id ASC', [id, categoria])
      : this.ds.query('SELECT * FROM hire_request_files WHERE hire_request_id = $1 ORDER BY uploaded_at ASC, id ASC', [id]);
  }

  /** Percorso di un file della pratica, solo dentro la cartella delle assunzioni. */
  percorso(rel: string): string | null {
    const radice = cartellaUpload('hire-requests');
    const p = path.resolve(cartellaUpload(), String(rel ?? '').replace(/^\/+/, ''));
    return p.startsWith(radice + path.sep) && fs.existsSync(p) && fs.statSync(p).isFile() ? p : null;
  }

  /** Username "nome.cognome" libero tra dipendenti e richieste dell'azienda (.2, .3... se preso). */
  async username(nome: string, cognome: string, cid: number): Promise<string> {
    const norm = (s: string) => s.trim().toLowerCase().normalize('NFD').replace(/[̀-ͯ]/g, '').replace(/[^a-z0-9]+/g, '') || 'user';
    const base = `${norm(nome)}.${norm(cognome)}`;
    let candidato = base;
    for (let n = 2; n <= 999; n++) {
      const [preso] = await this.ds.query(
        `SELECT 1 FROM employees WHERE username = $1 AND company_id = $2 UNION ALL SELECT 1 FROM hire_requests WHERE generated_username = $1 AND company_id = $2 LIMIT 1`,
        [candidato, cid]);
      if (!preso) break;
      candidato = `${base}.${n}`;
    }
    return candidato;
  }

  /** Consulente del lavoro collegato all'azienda (il primo). */
  async consulente(cid: number): Promise<number | null> {
    const [r] = await senzaIsolamento(() => this.ds.query(
      `SELECT u.id FROM users u JOIN user_companies uc ON uc.user_id = u.id
        WHERE uc.company_id = $1 AND u.role = 'consulente_lavoro' AND u.is_active = true ORDER BY u.id LIMIT 1`, [cid]));
    return r?.id ?? null;
  }

  /** Formato dei campi facoltativi, quando compilati (anche in bozza). */
  validaFormati(d: Dati): string | null {
    const cf = testo(d.fiscal_code).toUpperCase();
    if (cf !== '' && !/^[A-Z]{6}[0-9]{2}[A-Z][0-9]{2}[A-Z][0-9]{3}[A-Z]$/.test(cf)) return 'Codice fiscale non valido';
    const em = testo(d.employee_email);
    if (em !== '' && !EMAIL.test(em)) return 'Email non valida';
    const pe = testo(d.personal_email);
    if (pe !== '' && !EMAIL.test(pe)) return 'Email personale non valida';
    // Correzione: date e ore controllate qui (il PHP le mandava al database e mostrava il suo errore).
    for (const k of ['employee_birth_date', 'start_date', 'end_date']) {
      if (testo(d[k]) !== '' && !dataValida(testo(d[k]))) return 'Data non valida';
    }
    if (decimale(d.weekly_hours) > 168) return 'Ore settimanali non valide';
    return null;
  }

  /** Per l'invio al consulente: tipologia, inizio (e fine se determinato), ore, mansioni. */
  validaInvio(d: Dati): string | null {
    if (!CONTRATTI.some((k) => pieno(d[k]))) return 'Seleziona almeno una tipologia di contratto';
    if (testo(d.start_date) === '') return 'Data inizio contratto obbligatoria';
    if (pieno(d.contract_determinato) && testo(d.end_date) === '') return 'Data fine obbligatoria per il tempo determinato';
    if (decimale(d.weekly_hours) <= 0) return 'Ore settimanali obbligatorie';
    if (testo(d.role_description) === '') return 'Mansioni obbligatorie';
    return this.validaFormati(d);
  }

  /** Per l'approvazione: tutti i dati dell'assunzione. */
  validaCompleta(d: Dati): string | null {
    const obbligatori: Record<string, string> = {
      employee_first_name: 'Nome', employee_last_name: 'Cognome', fiscal_code: 'Codice fiscale', employee_birth_date: 'Data di nascita', birth_state: 'Stato di nascita',
      birth_city: 'Comune di nascita', residence_address: 'Indirizzo di residenza', residence_cap: 'CAP', residence_city: 'Comune di residenza', residence_province: 'Provincia',
      marital_status: 'Stato civile', education_level: 'Livello di istruzione', role_description: 'Mansioni', workplace: 'Sede di lavoro', employee_email: 'Email account',
    };
    const mancanti = Object.entries(obbligatori).filter(([k]) => testo(d[k]) === '').map(([, l]) => l);
    if (!CONTRATTI.some((k) => pieno(d[k]))) mancanti.push('Tipologia contratto');
    if (testo(d.start_date) === '') mancanti.push('Data inizio');
    if (pieno(d.contract_determinato) && testo(d.end_date) === '') mancanti.push('Data fine (determinato)');
    if (decimale(d.weekly_hours) <= 0) mancanti.push('Ore settimanali');
    if (!giorni(d).length) mancanti.push('Giorni di lavoro');
    if (mancanti.length) return `Campi obbligatori mancanti: ${mancanti.join(', ')}`;
    return this.validaFormati(d);
  }

  /** Codice fiscale gia' assunto o in una richiesta ancora viva (le richieste orfane si annullano). */
  private async duplicatoCf(cf: string, cid: number, escludi = 0): Promise<string | null> {
    if (cf === '') return null;
    if ((await this.ds.query('SELECT 1 FROM employees WHERE fiscal_code = $1 AND company_id = $2', [cf, cid])).length) return 'Esiste gia un dipendente con questo codice fiscale';
    const vive = await this.ds.query(
      `SELECT id, employee_id FROM hire_requests WHERE fiscal_code = $1 AND company_id = $2 AND id <> $3 AND status NOT IN ('rejected', 'cancelled', 'contract_signed')`, [cf, cid, escludi]);
    for (const r of vive) {
      const esiste = r.employee_id ? (await this.ds.query('SELECT 1 FROM employees WHERE id = $1', [r.employee_id])).length > 0 : true;
      if (esiste) return 'Esiste gia una richiesta di assunzione in corso per questo codice fiscale';
      await this.ds.query(`UPDATE hire_requests SET status = 'cancelled' WHERE id = $1`, [r.id]);
    }
    return null;
  }

  /** Campi del modulo -> colonne (vuoti -> NULL). */
  private payload(d: Dati): Dati {
    const s = (v: unknown) => (testo(v) === '' ? null : testo(v));
    const scelta = (v: unknown, ammessi: string[]) => (ammessi.includes(testo(v)) ? testo(v) : null);
    const cf = testo(d.fiscal_code).toUpperCase();
    const ore = decimale(d.weekly_hours);
    const g = giorni(d);
    const p: Dati = {
      employee_first_name: s(d.employee_first_name), employee_last_name: s(d.employee_last_name), employee_birth_date: s(d.employee_birth_date),
      birth_state: s(d.birth_state), birth_city: s(d.birth_city), fiscal_code: cf === '' ? null : cf, residence_address: s(d.residence_address),
      residence_cap: s(d.residence_cap), residence_city: s(d.residence_city), residence_province: s(d.residence_province),
      marital_status: scelta(d.marital_status, ['celibe_nubile', 'coniugato', 'divorziato', 'vedovo', 'unione_civile', 'separato']),
      education_level: scelta(d.education_level, ['nessuno', 'licenza_elementare', 'licenza_media', 'diploma', 'laurea_triennale', 'laurea_magistrale', 'dottorato']),
    };
    for (const k of CONTRATTI) p[k] = pieno(d[k]);
    Object.assign(p, {
      start_date: s(d.start_date), end_date: s(d.end_date), role_description: s(d.role_description), weekly_hours: ore > 0 ? ore : null,
      work_days: g.length ? g.join(',') : null, workplace: s(d.workplace), cost_center: s(d.cost_center),
      iban: pieno(d.iban) ? String(d.iban).replace(/\s+/g, '').toUpperCase() : null, employee_email: s(d.employee_email), personal_email: s(d.personal_email), notes: s(d.notes),
    });
    return p;
  }

  /** Allegati dell'HR dal modulo (id_doc[], fiscal_code_doc[], permit[], c2[]), controllati prima di salvare. */
  private controllaAllegati(file: Allegati): { categoria: string; f: FileCaricato }[] | string {
    const lista: { categoria: string; f: FileCaricato }[] = [];
    for (const categoria of CATEGORIE_ADMIN) {
      for (const f of [...(file[`${categoria}[]`] ?? []), ...(file[categoria] ?? [])]) {
        if (!f || !f.size) continue;
        const ext = estensione(f.originalname);
        if (!ESTENSIONI.includes(ext)) return `Estensione non consentita per ${categoria}: .${ext}`;
        if (f.size > 20 * 1024 * 1024) return `File ${categoria} troppo grande (max 20MB)`;
        if (!contenutoCoerente(ext, f.buffer)) return `File ${categoria} non valido: il contenuto non corrisponde al tipo`;
        lista.push({ categoria, f });
      }
    }
    return lista;
  }

  /** Scrive gli allegati gia' controllati in uploads/hire-requests/{id}/{categoria}/. */
  private async salvaAllegati(m: EntityManager, id: number, lista: { categoria: string; f: FileCaricato }[], uid: number, scritti: string[]) {
    for (const { categoria, f } of lista) {
      const dir = cartellaUpload('hire-requests', String(id), categoria);
      fs.mkdirSync(dir, { recursive: true, mode: 0o775 });
      const nome = `${randomBytes(8).toString('hex')}.${estensione(f.originalname)}`;
      const dest = path.join(dir, nome);
      fs.writeFileSync(dest, f.buffer, { mode: 0o640 });
      scritti.push(dest);
      await m.query(
        `INSERT INTO hire_request_files (hire_request_id, category, file_path, original_name, display_name, mime_type, file_size, uploaded_by_user_id)
         VALUES ($1, $2, $3, $4, NULL, $5, $6, $7)`,
        [id, categoria, `hire-requests/${id}/${categoria}/${nome}`, [...f.originalname].slice(0, 255).join(''), f.mimetype ? f.mimetype.slice(0, 120) : null, f.size, uid]);
    }
  }

  /** Scrittura con allegati: se qualcosa fallisce si annulla tutto, file compresi. */
  private async conAllegati<T>(lavoro: (m: EntityManager, scritti: string[]) => Promise<T>): Promise<T | null> {
    const scritti: string[] = [];
    try {
      return await this.ds.transaction((m) => lavoro(m, scritti));
    } catch (e: any) {
      for (const p of scritti) fs.rmSync(p, { force: true });
      this.logger.error(`salvataggio richiesta di assunzione non riuscito: ${e?.message ?? e}`);
      return null;
    }
  }

  private colonne(p: Dati) {
    const k = Object.keys(p);
    return { set: k.map((c, i) => `${c} = $${i + 2}`).join(', '), valori: k.map((c) => p[c]) };
  }

  /** Nuova richiesta: bozza (solo formati) o invio subito al consulente. */
  async crea(d: Dati, file: Allegati, bozza: boolean, cid: number, uid: number): Promise<EsitoInvio> {
    const err = bozza ? this.validaFormati(d) : this.validaInvio(d);
    if (err) return { success: false, error: err };
    const p = this.payload(d);
    if (p.fiscal_code) {
      const dup = await this.duplicatoCf(p.fiscal_code, cid);
      if (dup) return { success: false, error: dup };
    }
    const allegati = this.controllaAllegati(file);
    if (typeof allegati === 'string') return { success: false, error: allegati };
    const consulente = await this.consulente(cid);
    if (!bozza && !consulente) {
      return { success: false, error: "Nessun consulente del lavoro collegato all'azienda: impossibile inviare. Salva come bozza e contatta l'amministratore di sistema." };
    }
    const username = p.employee_first_name && p.employee_last_name ? await this.username(p.employee_first_name, p.employee_last_name, cid) : null;
    const riga: Dati = {
      ...p, company_id: cid, status: bozza ? 'draft' : 'awaiting_prospects', created_by_user_id: uid, assigned_consulente_user_id: consulente,
      employer_name: testo(d.employer_name), generated_username: username, send_count: bozza ? 0 : 1,
    };
    const k = Object.keys(riga);
    const id = await this.conAllegati(async (m, scritti) => {
      const [r] = await m.query(
        `INSERT INTO hire_requests (${k.join(', ')}, last_sent_at) VALUES (${k.map((_, i) => `$${i + 1}`).join(', ')}, ${bozza ? 'NULL' : 'CURRENT_TIMESTAMP'}) RETURNING id`,
        k.map((c) => riga[c]));
      await this.salvaAllegati(m, r.id, allegati, uid, scritti);
      return r.id as number;
    });
    if (!id) return { success: false, error: 'Errore creazione richiesta' };
    if (bozza) return { success: true, id, notified: false, notify_error: null };
    const [notificata, errore] = await this.avvisaConsulente(consulente!, id, { ...riga, id }, false);
    return { success: true, id, notified: notificata, notify_error: errore };
  }

  /** Modifica di una richiesta non ancora approvata; se gia' inviata va re-inviata. */
  async aggiorna(id: number, d: Dati, file: Allegati, cid: number, uid: number): Promise<{ success: true } | Errore> {
    const hr = await this.perId(id, cid);
    if (!hr) return { success: false, error: 'Richiesta non trovata' };
    if (!MODIFICABILI.includes(hr.status)) return { success: false, error: `La richiesta non e piu modificabile in questo stato (${etichettaStato(hr.status)})` };
    const bozza = hr.status === 'draft';
    const err = bozza ? this.validaFormati(d) : this.validaInvio(d);
    if (err) return { success: false, error: err };
    const p = this.payload(d);
    if (p.fiscal_code) {
      const dup = await this.duplicatoCf(p.fiscal_code, cid, id);
      if (dup) return { success: false, error: dup };
    }
    const allegati = this.controllaAllegati(file);
    if (typeof allegati === 'string') return { success: false, error: allegati };
    if (!hr.generated_username && p.employee_first_name && p.employee_last_name) p.generated_username = await this.username(p.employee_first_name, p.employee_last_name, cid);
    if (!bozza) p.updated_after_send = true;
    const { set, valori } = this.colonne(p);
    const ok = await this.conAllegati(async (m, scritti) => {
      await m.query(`UPDATE hire_requests SET ${set}, updated_at = CURRENT_TIMESTAMP WHERE id = $1`, [id, ...valori]);
      await this.salvaAllegati(m, id, allegati, uid, scritti);
      return true;
    });
    return ok ? { success: true } : { success: false, error: 'Errore salvataggio' };
  }

  /** Invio (o re-invio dopo modifiche) al consulente. */
  async invia(id: number, cid: number): Promise<EsitoInvio> {
    const hr = await this.perId(id, cid);
    if (!hr) return { success: false, error: 'Richiesta non trovata' };
    if (!MODIFICABILI.includes(hr.status)) return { success: false, error: `Stato non valido per l'invio (${etichettaStato(hr.status)})` };
    const err = this.validaInvio(hr);
    if (err) return { success: false, error: `Completa la richiesta prima di inviare: ${err}` };
    const consulente = hr.assigned_consulente_user_id || (await this.consulente(cid));
    if (!consulente) return { success: false, error: "Nessun consulente del lavoro collegato all'azienda: impossibile inviare." };
    const reinvio = hr.status !== 'draft';
    const stato = reinvio ? hr.status : 'awaiting_prospects';
    await this.ds.query(
      `UPDATE hire_requests SET status = $2, assigned_consulente_user_id = $3, last_sent_at = CURRENT_TIMESTAMP, send_count = send_count + 1, updated_after_send = false,
              updated_at = CURRENT_TIMESTAMP WHERE id = $1`, [id, stato, consulente]);
    const [notificata, errore] = await this.avvisaConsulente(consulente, id, { ...hr, status: stato }, reinvio);
    return { success: true, id, notified: notificata, notify_error: errore };
  }

  private async avvisaConsulente(consulente: number, id: number, hr: Dati, reinvio: boolean): Promise<[boolean, string | null]> {
    let chi = `${hr.employee_first_name ?? ''} ${hr.employee_last_name ?? ''}`.trim();
    if (chi === '') chi = `una nuova risorsa (richiesta #${id})`;
    const link = `/consulente-lavoro/hire-requests.php?id=${id}`;
    await this.wrike.taskPerAssunzione(consulente, id, hr, reinvio, urlPubblico(link));
    const r = await this.notifiche.crea(hr.company_id, {
      recipient_type: 'consulente_lavoro', recipient_id: consulente, type: reinvio ? 'hire_request_updated' : 'hire_request_new',
      title: reinvio ? 'Richiesta di assunzione aggiornata' : 'Nuova richiesta di assunzione',
      message: reinvio ? `L'admin ha modificato la richiesta per ${chi}. Controlla i dati aggiornati.` : `L'admin ha avviato l'assunzione di ${chi}. Carica i prospetti.`, link,
    });
    return r.success ? [true, null] : [false, r.error ?? 'Notifica non creata'];
  }

  /** Eliminazione (qualsiasi stato): file, cartella e righe. */
  async elimina(id: number, cid: number, ctx: Ctx): Promise<{ success: true } | Errore> {
    const hr = await this.perId(id, cid);
    if (!hr) return { success: false, error: 'Richiesta non trovata' };
    try {
      await this.ds.query('DELETE FROM hire_requests WHERE id = $1 AND company_id = $2', [id, cid]);
    } catch (e: any) {
      this.logger.error(`eliminazione richiesta ${id} non riuscita: ${e?.message ?? e}`);
      return { success: false, error: 'Errore eliminazione' };
    }
    fs.rmSync(cartellaUpload('hire-requests', String(id)), { recursive: true, force: true });
    await this.audit.log('hire_request_deleted', ctx.tipo, ctx.id, ctx, 'hire_request', id, hr, null).catch(() => undefined);
    return { success: true };
  }

  /** Dati extra del modulo di approvazione/assunzione (reparto, posizione, retribuzione). */
  extra(d: Dati) {
    const n = (v: unknown) => (v === '' || v === undefined || v === null ? null : decimale(v));
    return {
      department_id: pieno(d.department_id) ? Number.parseInt(String(d.department_id), 10) || null : null, position: testo(d.position), job_level: testo(d.job_level),
      monthly_salary: n(d.monthly_salary), ral_amount: n(d.ral_amount),
    };
  }

  /** Bypass su una richiesta avviata: completa i dati compilati e assume subito. */
  async assumiDaRichiesta(id: number, d: Dati, cid: number, ctx: Ctx): Promise<EsitoAssunzione> {
    let hr = await this.perId(id, cid);
    if (!hr) return { success: false, error: 'Richiesta non trovata' };
    if (!BYPASSABILI.includes(hr.status)) return { success: false, error: `Bypass non disponibile in stato "${etichettaStato(hr.status)}"` };
    const err = this.validaFormati(d);
    if (err) return { success: false, error: err };
    const p = this.payload(d);
    const patch: Dati = {};
    for (const [k, v] of Object.entries(p)) {
      if (k.startsWith('contract_') && !(k in d)) continue;
      if (v === null || v === '') continue;
      patch[k] = v;
    }
    if (Object.keys(patch).length) {
      const { set, valori } = this.colonne(patch);
      await this.ds.query(`UPDATE hire_requests SET ${set}, updated_at = CURRENT_TIMESTAMP WHERE id = $1`, [id, ...valori]);
      hr = (await this.perId(id, cid))!;
    }
    const mancanti = Object.entries(MINIMI_DIRETTA).filter(([k]) => testo(hr![k]) === '').map(([, l]) => l);
    if (mancanti.length) return { success: false, error: `Campi obbligatori mancanti: ${mancanti.join(', ')}` };
    if (!codiceFiscaleValido(hr.fiscal_code)) return { success: false, error: 'Codice fiscale non valido (carattere di controllo errato)' };
    const dup = await this.duplicatoCf(hr.fiscal_code, cid, id);
    if (dup) return { success: false, error: dup };
    let username = testo(d.username) || testo(hr.generated_username);
    if (username === '') username = await this.username(hr.employee_first_name, hr.employee_last_name, cid);
    if (username !== hr.generated_username) {
      await this.ds.query('UPDATE hire_requests SET generated_username = $2 WHERE id = $1', [id, username]);
      hr.generated_username = username;
    }
    const r = await this.creaDipendente(id, hr, { ...this.extra(d), password: testo(d.password) || null }, cid, ctx);
    if (!r.success) return r;
    await this.ds.query(
      `UPDATE hire_requests SET status = 'direct_hire', employee_id = $2, decided_at = CURRENT_TIMESTAMP, decided_by_user_id = $3, updated_at = CURRENT_TIMESTAMP WHERE id = $1`,
      [id, r.employee_id, ctx.id]);
    if (hr.assigned_consulente_user_id) {
      await this.notifiche.crea(cid, {
        recipient_type: 'consulente_lavoro', recipient_id: hr.assigned_consulente_user_id, type: 'hire_direct', title: 'Assunzione diretta',
        message: `L'azienda ha assunto direttamente ${hr.employee_first_name} ${hr.employee_last_name}: la richiesta non attende piu' i prospetti.`, link: `/consulente-lavoro/hire-requests.php?id=${id}`,
      });
    }
    return { ...r, id };
  }

  /** Assunzione diretta senza passare dal consulente (resta una richiesta "direct_hire"). */
  async assumiDiretto(d: Dati, file: Allegati, cid: number, ctx: Ctx): Promise<EsitoAssunzione> {
    const mancanti = Object.entries(MINIMI_DIRETTA).filter(([k]) => testo(d[k]) === '').map(([, l]) => l);
    if (mancanti.length) return { success: false, error: `Campi obbligatori mancanti: ${mancanti.join(', ')}` };
    const err = this.validaFormati(d);
    if (err) return { success: false, error: err };
    if (!codiceFiscaleValido(String(d.fiscal_code))) return { success: false, error: 'Codice fiscale non valido (carattere di controllo errato)' };
    const p = this.payload(d);
    const dup = await this.duplicatoCf(p.fiscal_code, cid);
    if (dup) return { success: false, error: dup };
    const allegati = this.controllaAllegati(file);
    if (typeof allegati === 'string') return { success: false, error: allegati };
    const username = testo(d.username) || (await this.username(p.employee_first_name, p.employee_last_name, cid));
    const consulente = await this.consulente(cid);
    const riga: Dati = {
      ...p, company_id: cid, status: 'direct_hire', created_by_user_id: ctx.id, assigned_consulente_user_id: consulente, employer_name: testo(d.employer_name),
      generated_username: username, decided_by_user_id: ctx.id,
    };
    const k = Object.keys(riga);
    const id = await this.conAllegati(async (m, scritti) => {
      const [r] = await m.query(
        `INSERT INTO hire_requests (${k.join(', ')}, decided_at) VALUES (${k.map((_, i) => `$${i + 1}`).join(', ')}, CURRENT_TIMESTAMP) RETURNING id`, k.map((c) => riga[c]));
      await this.salvaAllegati(m, r.id, allegati, ctx.id, scritti);
      return r.id as number;
    });
    if (!id) return { success: false, error: 'Errore creazione assunzione' };
    const hr = (await this.perId(id, cid))!;
    const r = await this.creaDipendente(id, hr, { ...this.extra(d), password: testo(d.password) || null }, cid, ctx);
    if (!r.success) {
      // Niente richieste orfane che bloccherebbero il codice fiscale
      await this.ds.query(`UPDATE hire_requests SET status = 'cancelled' WHERE id = $1`, [id]);
      return { success: false, error: r.error, id };
    }
    await this.ds.query('UPDATE hire_requests SET employee_id = $2 WHERE id = $1', [id, r.employee_id]);
    if (consulente) {
      await this.notifiche.crea(cid, {
        recipient_type: 'consulente_lavoro', recipient_id: consulente, type: 'hire_direct', title: 'Assunzione diretta',
        message: `Nuovo dipendente assunto direttamente dall'azienda: ${hr.employee_first_name} ${hr.employee_last_name}`, link: `/consulente-lavoro/hire-requests.php?id=${id}`,
      });
    }
    return { ...r, id };
  }

  /** Dipendente + utenza dalla richiesta, con i documenti dell'HR nel suo profilo. */
  private async creaDipendente(id: number, hr: Dati, extra: Dati, cid: number, ctx: Ctx): Promise<EsitoAssunzione> {
    const giorniLavoro = String(hr.work_days ?? '');
    const quanti = giorniLavoro.split(',').filter(Boolean).length;
    const orePerGiorno = quanti > 0 ? Math.round((Number(hr.weekly_hours ?? 0) / quanti) * 100) / 100 : null;
    let reparto = extra.department_id;
    if (!reparto) {
      const [r] = await this.ds.query('SELECT id FROM departments WHERE company_id = $1 AND is_active = true ORDER BY id LIMIT 1', [cid]);
      reparto = r?.id ?? null;
    }
    if (!reparto) return { success: false, error: "Devi prima creare almeno un reparto nell'azienda" };
    const creato = await this.anagrafica.crea({
      username: hr.generated_username, first_name: hr.employee_first_name, last_name: hr.employee_last_name, fiscal_code: hr.fiscal_code, email: hr.employee_email,
      birth_date: hr.employee_birth_date, address: indirizzoCompleto(hr), iban: hr.iban ?? null, hire_date: hr.start_date, position: extra.position ?? hr.role_description,
      department_id: reparto, job_level: extra.job_level ?? null, ral_amount: extra.ral_amount ?? null, monthly_salary: extra.monthly_salary ?? null, password: extra.password ?? null,
    }, cid, ctx);
    if (!creato.success) return { success: false, error: `Errore creazione dipendente: ${creato.error}` };
    const eid = creato.id;
    await this.ds.query('UPDATE employees SET working_days = $2, hours_per_day = $3 WHERE id = $1', [eid, giorniLavoro || null, orePerGiorno]).catch(() => undefined);

    const documenti: { categoria: string; riga: Dati; percorso: string }[] = [];
    for (const categoria of CATEGORIE_ADMIN) {
      for (const f of await this.allegati(id, categoria)) {
        const p = this.percorso(f.file_path);
        if (p) documenti.push({ categoria, riga: f, percorso: p });
      }
    }
    if (hr.assigned_consulente_user_id) {
      const righe: Array<[string, string]> = [
        ['Nome', `${hr.employee_first_name ?? ''} ${hr.employee_last_name ?? ''}`.trim()], ['Codice fiscale', hr.fiscal_code ?? ''], ['Data di nascita', hr.employee_birth_date ?? ''],
        ['Nato a', `${hr.birth_city ?? ''} ${pieno(hr.birth_state) ? `(${hr.birth_state})` : ''}`.trim()], ['Residenza', indirizzoCompleto(hr) ?? ''],
        ['Stato civile', pieno(hr.marital_status) ? maiuscolaIniziale(String(hr.marital_status).replace(/_/g, ' ')) : ''],
        ['Istruzione', pieno(hr.education_level) ? maiuscolaIniziale(String(hr.education_level).replace(/_/g, ' ')) : ''], ['Email', hr.employee_email ?? ''],
        ['Tipologia contratto', etichetteContratti(hr)], ['Inizio', hr.start_date ?? ''], ['Fine', hr.end_date ?? ''], ['Ore settimanali', hr.weekly_hours ?? ''],
        ['Giorni', etichetteGiorni(hr.work_days)], ['Mansioni', hr.role_description ?? ''], ['Sede di lavoro', hr.workplace ?? ''], ['IBAN', hr.iban ?? ''],
      ];
      await this.wrike.assunzioneApprovata(hr.assigned_consulente_user_id, id, righe, documenti.map((x) => ({ percorso: x.percorso, nome: x.riga.original_name ?? 'documento' })));
    }
    const oggi = oggiRoma();
    for (const x of documenti) {
      const r = await this.documenti.caricaDaPercorso(x.percorso, {
        employee_id: eid, type: 'other', month: Number(oggi.slice(5, 7)), year: Number(oggi.slice(0, 4)), title: DOCUMENTI_DIPENDENTE[x.categoria],
        description: `Caricato in fase di assunzione (richiesta #${id})`, original_name: x.riga.original_name, uploaded_by: ctx.id,
      });
      if (r.success && r.id) await this.ds.query('UPDATE documents SET notify_employee = false WHERE id = $1', [r.id]).catch(() => undefined);
      else if (!r.success) this.logger.warn(`documento ${x.categoria} della richiesta ${id} non passato al dipendente: ${r.error}`);
    }
    return { success: true, id, employee_id: eid, email_sent: creato.email_sent, email_error: creato.email_error, temp_password: creato.temp_password };
  }

  /** Approvazione dei prospetti con il modulo completo: crea il dipendente, sblocca il contratto. */
  async approva(id: number, d: Dati, file: Allegati, cid: number, ctx: Ctx): Promise<EsitoAssunzione> {
    let hr = await this.perId(id, cid);
    if (!hr) return { success: false, error: 'Richiesta non trovata' };
    if (hr.status !== 'prospects_review') return { success: false, error: 'La richiesta non e in stato di approvazione' };
    const err = this.validaCompleta(d);
    if (err) return { success: false, error: err };
    const p = this.payload(d);
    if (p.fiscal_code) {
      const dup = await this.duplicatoCf(p.fiscal_code, cid, id);
      if (dup) return { success: false, error: dup };
    }
    const allegati = this.controllaAllegati(file);
    if (typeof allegati === 'string') return { success: false, error: allegati };
    if (!hr.generated_username && p.employee_first_name && p.employee_last_name) p.generated_username = await this.username(p.employee_first_name, p.employee_last_name, cid);
    const { set, valori } = this.colonne(p);
    const ok = await this.conAllegati(async (m, scritti) => {
      await m.query(`UPDATE hire_requests SET ${set}, updated_at = CURRENT_TIMESTAMP WHERE id = $1`, [id, ...valori]);
      await this.salvaAllegati(m, id, allegati, ctx.id, scritti);
      return true;
    });
    if (!ok) return { success: false, error: 'Errore salvataggio dati' };
    hr = (await this.perId(id, cid))!;

    const docMancanti = [];
    if (!(await this.allegati(id, 'id_doc')).length) docMancanti.push('Documento di riconoscimento');
    if (!(await this.allegati(id, 'fiscal_code_doc')).length) docMancanti.push('Codice fiscale (documento)');
    if (docMancanti.length) return { success: false, error: `Allegati obbligatori mancanti: ${docMancanti.join(', ')}. Caricali prima di approvare.` };
    const necessari: Record<string, string> = { employee_first_name: 'Nome', employee_last_name: 'Cognome', fiscal_code: 'Codice fiscale', employee_email: 'Email account', employee_birth_date: 'Data di nascita' };
    const mancanti = Object.entries(necessari).filter(([k]) => testo(hr![k]) === '').map(([, l]) => l);
    if (mancanti.length) return { success: false, error: `Dati incompleti per creare il dipendente: ${mancanti.join(', ')}.` };
    if (!hr.generated_username) {
      hr.generated_username = await this.username(hr.employee_first_name, hr.employee_last_name, cid);
      await this.ds.query('UPDATE hire_requests SET generated_username = $2 WHERE id = $1', [id, hr.generated_username]);
    }
    const r = await this.creaDipendente(id, hr, this.extra(d), cid, ctx);
    if (!r.success) return r;
    await this.ds.query(
      `UPDATE hire_requests SET status = 'approved', employee_id = $2, decided_at = CURRENT_TIMESTAMP, decided_by_user_id = $3, updated_at = CURRENT_TIMESTAMP WHERE id = $1`,
      [id, r.employee_id, ctx.id]);
    if (hr.assigned_consulente_user_id) {
      await this.notifiche.crea(cid, {
        recipient_type: 'consulente_lavoro', recipient_id: hr.assigned_consulente_user_id, type: 'hire_approved', title: 'Assunzione approvata',
        message: `Carica il contratto per ${hr.employee_first_name} ${hr.employee_last_name}`, link: `/consulente-lavoro/hire-requests.php?id=${id}`,
      });
    }
    return r;
  }

  /** Rifiuto dei prospetti con motivazione. */
  async rifiuta(id: number, motivo: string, cid: number, uid: number): Promise<{ success: true } | Errore> {
    const hr = await this.perId(id, cid);
    if (!hr) return { success: false, error: 'Richiesta non trovata' };
    if (hr.status !== 'prospects_review') return { success: false, error: 'Stato non valido per rifiuto' };
    const m = motivo.trim();
    if (m === '') return { success: false, error: 'Motivazione obbligatoria' };
    await this.ds.query(
      `UPDATE hire_requests SET status = 'rejected', rejection_reason = $2, decided_at = CURRENT_TIMESTAMP, decided_by_user_id = $3, updated_at = CURRENT_TIMESTAMP WHERE id = $1`,
      [id, m, uid]);
    if (hr.assigned_consulente_user_id) {
      await this.notifiche.crea(cid, {
        recipient_type: 'consulente_lavoro', recipient_id: hr.assigned_consulente_user_id, type: 'hire_rejected', title: 'Prospetti respinti',
        message: `Motivo: ${[...m].slice(0, 200).join('')}`, link: `/consulente-lavoro/hire-requests.php?id=${id}`,
      });
      await this.wrike.passaggioAssunzione(hr.assigned_consulente_user_id, id,
        `⛔ <b>Prospetti respinti</b> dall'azienda. Motivo: ${escapeHtml([...m].slice(0, 300).join(''))}`, false, 'rejected');
    }
    return { success: true };
  }
}
