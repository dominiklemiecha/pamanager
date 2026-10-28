import { Injectable } from '@nestjs/common';
import { DataSource } from 'typeorm';
import { contaGiorniLavorativi } from '../../common/calcoli/orario';
import { oggiRoma } from '../../common/calcoli/php';
import { senzaIsolamento } from '../../common/database/isolamento';
import type { UtenteCorrente } from '../auth/auth.types';
import { TIPI } from '../ferie/ferie.service';
import { NotificheService } from '../notifiche/notifiche.service';
import { compleanni } from '../pagine/widget/compleanni';
import { StaffService } from '../staff/staff.service';

const foto = (p: string | null | undefined) => (p ? '/' + String(p).replace(/^\/+/, '') : '');
const iso = (d: unknown) => (d ? (d instanceof Date ? d : new Date(String(d))).toISOString() : '');
export const TIPI_DOCUMENTO: Record<string, string> = { payslip: 'Busta paga', cud: 'CU', other: 'Documento' };

/**
 * Dati della vista Cora del consulente del lavoro (Figma "Modulo Cora · HR",
 * sezione 3): cornice con campanella e azienda, dashboard con buste paga del
 * mese, richieste in attesa dell'HR, ultimi documenti caricati e notifiche.
 * Solo dati che il gestionale ha gia' (stessi conteggi di consulente-lavoro/index.php).
 */
@Injectable()
export class ConsulenteService {
  constructor(private readonly ds: DataSource, private readonly staff: StaffService, private readonly notifiche: NotificheService) {}

  /** Vista Cora attiva per il consulente (CONSULENTE_CORA_UI=1). */
  attiva(): boolean {
    return ['1', 'true', 'all'].includes(String(process.env.CONSULENTE_CORA_UI ?? '').toLowerCase());
  }

  /** Stessa forma della cornice HR: la cornice Cora del frontend e' una sola. */
  async cornice(u: UtenteCorrente) {
    const cid = u.companyId!;
    const base = await this.staff.cornice(u);
    const notifiche = await this.notifiche.perUtente(cid, 'consulente_lavoro', u.id, false, 20);
    const [dip] = await this.ds.query('SELECT COUNT(*)::int AS n FROM employees WHERE company_id = $1 AND is_active = true', [cid]);
    return {
      ...base,
      coraHr: this.attiva(),
      dipendentiAttivi: dip.n,
      notifiche: notifiche.map((n: any) => ({ id: n.id, titolo: n.title, messaggio: n.message, link: n.link ?? '', letta: !!n.is_read, creata: iso(n.created_at) })),
      notificheNonLette: await this.notifiche.nonLette(cid, 'consulente_lavoro', u.id),
    };
  }

  /**
   * Anagrafica in sola lettura (consulente-lavoro/employees.php): dipendenti
   * attivi con gli stessi campi della pagina PHP, scelti uno per uno.
   */
  async dipendenti(cid: number) {
    const righe = await this.ds.query(
      `SELECT e.id, e.first_name, e.last_name, e.photo_path, e.fiscal_code, e.birth_date::text AS birth_date, e.address, e.email, e.phone,
              e.hire_date::text AS hire_date, e.job_level, e.position, e.ral_amount, e.monthly_salary, e.iban, d.name AS department_name
         FROM employees e LEFT JOIN departments d ON d.id = e.department_id
        WHERE e.company_id = $1 AND e.is_active = true ORDER BY e.last_name ASC, e.first_name ASC, e.id ASC`, [cid]);
    const numero = (v: unknown) => (v === null || v === undefined || v === '' ? null : Number(v));
    return {
      dipendenti: righe.map((e: any) => ({
        id: e.id, nome: e.first_name, cognome: e.last_name, foto: foto(e.photo_path), cf: e.fiscal_code ?? '',
        nascita: e.birth_date ?? null, indirizzo: e.address ?? '', email: e.email ?? '', telefono: e.phone ?? '',
        assunzione: e.hire_date ?? null, livello: e.job_level ?? '', posizione: e.position ?? '', reparto: e.department_name ?? '',
        ral: numero(e.ral_amount), mensile: numero(e.monthly_salary), iban: e.iban ?? '',
      })),
    };
  }

  /**
   * Richieste di assunzione visibili al consulente (stesso filtro di
   * AssunzioniService.elencoConsulente: sue o senza consulente, mai bozze),
   * con le fasi del Figma: richiesta HR, prospetto, approvazione, firma.
   */
  async assunzioni(cid: number, uid: number) {
    const righe = await this.ds.query(
      `SELECT hr.id, hr.status, hr.employee_first_name, hr.employee_last_name, hr.role_description, hr.start_date::text AS start_date,
              hr.end_date::text AS end_date, hr.contract_indeterminato, hr.contract_determinato, hr.contract_apprendistato, hr.contract_tirocinio,
              hr.contract_agevolata, hr.last_sent_at, hr.created_at, hr.decided_at,
              (SELECT f.id FROM hire_request_files f WHERE f.hire_request_id = hr.id AND f.category = 'prospect' ORDER BY f.uploaded_at DESC, f.id DESC LIMIT 1) AS prospetto_id,
              (SELECT max(f.uploaded_at) FROM hire_request_files f WHERE f.hire_request_id = hr.id AND f.category = 'prospect') AS prospetto_il,
              (SELECT max(f.uploaded_at) FROM hire_request_files f WHERE f.hire_request_id = hr.id AND f.category = 'contract') AS contratto_il
         FROM hire_requests hr
        WHERE hr.company_id = $1 AND hr.status <> 'draft' AND (hr.assigned_consulente_user_id = $2 OR hr.assigned_consulente_user_id IS NULL)
        ORDER BY hr.created_at DESC, hr.id DESC`, [cid, uid]);
    const contratto = (r: any) => [
      r.contract_indeterminato && 'Tempo indeterminato', r.contract_determinato && 'Tempo determinato', r.contract_apprendistato && 'Apprendistato',
      r.contract_tirocinio && 'Tirocinio/Stage', r.contract_agevolata && 'Agevolata',
    ].filter(Boolean).join(', ');
    return {
      assunzioni: righe.map((r: any) => ({
        id: r.id, stato: r.status, nome: r.employee_first_name ?? '', cognome: r.employee_last_name ?? '',
        ruolo: r.role_description ?? '', contratto: contratto(r), inizio: r.start_date ?? null, fine: r.end_date ?? null,
        ricevuta: iso(r.last_sent_at ?? r.created_at), prospettoId: r.prospetto_id ?? null, prospettoIl: iso(r.prospetto_il),
        decisa: iso(r.decided_at), contrattoIl: iso(r.contratto_il),
      })),
    };
  }

  /**
   * Buste paga, CU e altri documenti dell'azienda (consulente-lavoro/documents.php,
   * stesso elenco), con la prima lettura del dipendente (primo download).
   */
  async documenti(cid: number) {
    const righe = await this.ds.query(
      `SELECT d.id, d.type, d.title, d.month, d.year, d.created_at, d.employee_id, e.first_name, e.last_name, e.photo_path,
              (SELECT min(dd.downloaded_at) FROM document_downloads dd WHERE dd.document_id = d.id AND dd.user_type = 'employee') AS letto
         FROM documents d JOIN employees e ON e.id = d.employee_id
        WHERE d.company_id = $1 ORDER BY d.created_at DESC, d.id DESC LIMIT 500`, [cid]);
    const dipendenti = await this.ds.query(
      `SELECT id, first_name, last_name, fiscal_code FROM employees WHERE company_id = $1 AND is_active = true ORDER BY last_name, first_name, id`, [cid]);
    return {
      documenti: righe.map((d: any) => ({
        id: d.id, tipo: d.type, etichetta: TIPI_DOCUMENTO[d.type] ?? 'Documento', titolo: d.title, mese: d.month, anno: d.year,
        caricato: iso(d.created_at), dipendenteId: d.employee_id, nome: d.first_name, cognome: d.last_name, letto: iso(d.letto),
      })),
      dipendenti: dipendenti.map((e: any) => ({ id: e.id, nome: e.first_name, cognome: e.last_name, cf: e.fiscal_code ?? '' })),
    };
  }

  /**
   * Documenti che i dipendenti hanno mandato: certificati di malattia (con la
   * richiesta collegata) e documenti caricati durante l'assunzione (solo
   * richieste visibili al consulente: sue o senza consulente).
   */
  async caricatiDipendenti(cid: number, uid: number) {
    const certificati = await this.ds.query(
      `SELECT lr.id, lr.protocol_number, lr.certificate_name, lr.start_date::text AS start_date, lr.end_date::text AS end_date,
              COALESCE(lr.updated_at, lr.created_at) AS caricato, e.id AS employee_id, e.first_name, e.last_name, e.fiscal_code
         FROM leave_requests lr JOIN employees e ON e.id = lr.employee_id
        WHERE e.company_id = $1 AND lr.leave_type = 'malattia' AND lr.certificate_path IS NOT NULL
        ORDER BY caricato DESC, lr.id DESC LIMIT 300`, [cid]);
    const assunzione = await this.ds.query(
      `SELECT f.id, f.hire_request_id, f.category, f.original_name, f.uploaded_at, hr.employee_id, hr.employee_first_name, hr.employee_last_name, hr.fiscal_code
         FROM hire_request_files f JOIN hire_requests hr ON hr.id = f.hire_request_id
        WHERE hr.company_id = $1 AND hr.status <> 'draft' AND f.uploaded_by_employee_id IS NOT NULL
          AND f.category IN ('id_doc', 'fiscal_code_doc', 'permit', 'c2')
          AND (hr.assigned_consulente_user_id = $2 OR hr.assigned_consulente_user_id IS NULL)
        ORDER BY f.uploaded_at DESC, f.id DESC LIMIT 300`, [cid, uid]);
    const NOMI: Record<string, string> = { id_doc: 'Documento di riconoscimento', fiscal_code_doc: 'Codice fiscale', permit: 'Permesso di soggiorno', c2: 'Modello C2' };
    const voci = [
      ...certificati.map((c: any) => ({
        chiave: `m${c.id}`, gruppo: 'malattia', titolo: c.protocol_number ? `Certificato medico - ${c.protocol_number}` : 'Certificato medico',
        caricato: iso(c.caricato), dipendenteId: c.employee_id, nome: c.first_name, cognome: c.last_name, cf: c.fiscal_code ?? '', categoria: 'Malattia',
        collegato: { tipo: 'malattia', da: c.start_date, a: c.end_date }, file: `/api/v1/pagine/consulente-lavoro/leave-requests?download_cert=${c.id}`,
        voce: '/consulente-lavoro/leave-requests',
      })),
      ...assunzione.map((f: any) => ({
        chiave: `a${f.id}`, gruppo: 'assunzione', titolo: NOMI[f.category] ?? f.original_name, caricato: iso(f.uploaded_at), dipendenteId: f.employee_id ?? null,
        nome: f.employee_first_name ?? '', cognome: f.employee_last_name ?? '', cf: f.fiscal_code ?? '', categoria: f.category === 'c2' ? 'Collocamento' : 'Identità',
        collegato: { tipo: 'assunzione' }, file: `/api/v1/pagine/consulente-lavoro/hire-requests?action=file&id=${f.hire_request_id}&file_id=${f.id}`,
        voce: `/consulente-lavoro/hire-requests?id=${f.hire_request_id}`,
      })),
    ].sort((a, b) => (a.caricato < b.caricato ? 1 : a.caricato > b.caricato ? -1 : 0));
    return { documenti: voci };
  }

  async home(u: UtenteCorrente) {
    const cid = u.companyId!;
    const oggi = oggiRoma();
    const anno = Number(oggi.slice(0, 4));
    const mese = Number(oggi.slice(5, 7));
    const n = async (sql: string, p: unknown[]) => Number((await this.ds.query(sql, p))[0]?.n ?? 0);
    const [me] = await senzaIsolamento(() => this.ds.query('SELECT name FROM users WHERE id = $1', [u.id]));

    const attivi = await n('SELECT COUNT(*)::int AS n FROM employees WHERE company_id = $1 AND is_active = true', [cid]);
    // Dipendenti attivi con la busta paga del mese gia' caricata (da chiunque).
    const busteMese = await n(`SELECT COUNT(DISTINCT d.employee_id)::int AS n FROM documents d JOIN employees e ON e.id = d.employee_id
      WHERE d.company_id = $1 AND e.is_active = true AND d.type = 'payslip' AND d.month = $2 AND d.year = $3`, [cid, mese, anno]);
    const caricati = await n('SELECT COUNT(*)::int AS n FROM documents WHERE uploaded_by = $1 AND company_id = $2', [u.id, cid]);
    const approvate = await n(`SELECT COUNT(*)::int AS n FROM leave_requests lr JOIN employees e ON lr.employee_id = e.id
      WHERE e.company_id = $1 AND lr.status = 'approved' AND extract(year FROM lr.start_date) = $2`, [cid, anno]);

    const [az] = await this.ds.query('SELECT working_days FROM companies WHERE id = $1', [cid]);
    const lavorativi = String(az?.working_days ?? 'mon,tue,wed,thu,fri').split(',').map((x) => x.trim()).filter(Boolean);
    const inAttesa = await n(`SELECT COUNT(*)::int AS n FROM leave_requests lr JOIN employees e ON lr.employee_id = e.id
      WHERE e.company_id = $1 AND lr.status = 'pending'`, [cid]);
    const richieste = (await this.ds.query(
      `SELECT lr.id, lr.leave_type, lr.start_date::text AS start_date, lr.end_date::text AS end_date, lr.is_full_day,
              to_char(lr.start_time, 'HH24:MI') AS start_time, to_char(lr.end_time, 'HH24:MI') AS end_time,
              e.first_name, e.last_name, e.photo_path
         FROM leave_requests lr JOIN employees e ON e.id = lr.employee_id
        WHERE e.company_id = $1 AND lr.status = 'pending' ORDER BY lr.created_at DESC, lr.id DESC LIMIT 3`, [cid]))
      .map((r: any) => ({
        id: r.id, tipo: r.leave_type, etichetta: TIPI[r.leave_type] ?? r.leave_type, nome: r.first_name, cognome: r.last_name, foto: foto(r.photo_path),
        da: r.start_date, a: r.end_date, giornata: r.is_full_day !== false, oraDa: r.start_time, oraA: r.end_time,
        giorni: contaGiorniLavorativi(r.start_date, r.end_date, lavorativi),
      }));

    // Ultimi documenti caricati dal consulente in questa azienda (come la dashboard PHP).
    const documenti = (await this.ds.query(
      `SELECT d.id, d.type, d.title, d.month, d.year, d.created_at, e.first_name, e.last_name, e.photo_path
         FROM documents d JOIN employees e ON d.employee_id = e.id
        WHERE d.uploaded_by = $1 AND d.company_id = $2 ORDER BY d.created_at DESC, d.id DESC LIMIT 5`, [u.id, cid]))
      .map((d: any) => ({
        id: d.id, tipo: d.type, etichetta: TIPI_DOCUMENTO[d.type] ?? 'Documento', titolo: d.title, mese: d.month, anno: d.year,
        caricato: iso(d.created_at), nome: d.first_name, cognome: d.last_name, foto: foto(d.photo_path),
      }));

    // Inviti del calendario ancora da confermare (accetta / rifiuta dalla dashboard).
    const inviti = (await this.ds.query(
      `SELECT e.id, e.title, e.start_at, e.all_day FROM calendar_event_participants ep JOIN calendar_events e ON e.id = ep.event_id
        WHERE ep.user_type = 'consulente_lavoro' AND ep.user_id = $1 AND ep.status = 'pending' AND e.end_at >= CURRENT_TIMESTAMP
        ORDER BY e.start_at ASC, e.id ASC LIMIT 3`, [u.id]))
      .map((e: any) => ({ id: e.id, titolo: e.title, inizio: iso(e.start_at), giornata: !!e.all_day }));

    const { todayOthers } = await compleanni(this.ds, cid);
    const festeggiati = todayOthers.length
      ? (await this.ds.query(
        `SELECT id, first_name, last_name, photo_path FROM employees WHERE company_id = $1 AND is_active = true AND birth_date IS NOT NULL
            AND to_char(birth_date, 'MM-DD') = $2 ORDER BY id`, [cid, oggi.slice(5)]))
        .map((p: any) => ({ id: p.id, nome: p.first_name, cognome: p.last_name, foto: foto(p.photo_path) }))
      : [];

    return {
      nome: String(me?.name ?? ''), oggi, mese, anno,
      buste: { caricate: busteMese, totale: attivi },
      caricati, approvate, attivi,
      inAttesa, richieste, documenti, inviti, festeggiati,
    };
  }
}
