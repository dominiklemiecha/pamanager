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
