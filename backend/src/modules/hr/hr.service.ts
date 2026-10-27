import { Injectable } from '@nestjs/common';
import { DataSource } from 'typeorm';
import { contaGiorniLavorativi } from '../../common/calcoli/orario';
import { oggiRoma } from '../../common/calcoli/php';
import { senzaIsolamento } from '../../common/database/isolamento';
import type { UtenteCorrente } from '../auth/auth.types';
import { TenantService } from '../auth/tenant.service';
import { STATI as STATI_ASSUNZIONE } from '../assunzioni/assunzioni.service';
import { TIPI } from '../ferie/ferie.service';
import { ComunicazioniAdminService } from '../comunicazioni/comunicazioni-admin.service';
import { DocumentiPersonaliService } from '../documenti/documenti-personali.service';
import { NotificheService } from '../notifiche/notifiche.service';
import { PresenzeExportService } from '../presenze/presenze-export.service';
import { compleanni } from '../pagine/widget/compleanni';
import { HeatmapService } from '../pagine/widget/heatmap.service';
import { ProvaService } from '../prova/prova.service';
import { StaffService } from '../staff/staff.service';

const foto = (p: string | null | undefined) => (p ? '/' + String(p).replace(/^\/+/, '') : '');
const iso = (d: unknown) => (d ? (d instanceof Date ? d : new Date(String(d))).toISOString() : '');

/** Stato di un collega in un giorno, come nella legenda delle presenze settimanali. */
export type StatoGiorno = 'disponibile' | 'occupato' | 'ferie' | 'permesso' | 'malattia' | 'assente' | 'smart';

/** Assenza approvata che copre il giorno: ferie, permessi e malattia hanno il loro colore, il resto "assente". */
function statoAssenza(tipo: string): StatoGiorno {
  if (tipo === 'ferie') return 'ferie';
  if (tipo === 'permesso' || tipo === 'permesso_104') return 'permesso';
  if (tipo === 'malattia') return 'malattia';
  if (tipo === 'smart_working') return 'smart';
  return 'assente';
}

/**
 * Dati della vista Cora dell'HR (Figma "Modulo Cora · HR"): cornice con
 * campanella e azienda, dashboard con presenze, richieste da approvare,
 * presenze della settimana e attivita' recente. Solo dati che esistono gia'
 * nel gestionale: nessun contatore o funzione inventati.
 */
@Injectable()
export class HrService {
  constructor(private readonly ds: DataSource, private readonly staff: StaffService, private readonly notifiche: NotificheService,
    private readonly heatmap: HeatmapService, private readonly prova: ProvaService,
    private readonly comunicazioniAdmin: ComunicazioniAdminService, private readonly personali: DocumentiPersonaliService,
    private readonly tenant: TenantService, private readonly presenzeExport: PresenzeExportService) {}

  /** Vista Cora attiva per l'HR (HR_CORA_UI=1). */
  attiva(): boolean {
    return ['1', 'true', 'all'].includes(String(process.env.HR_CORA_UI ?? '').toLowerCase());
  }

  async cornice(u: UtenteCorrente) {
    const cid = u.companyId!;
    const base = await this.staff.cornice(u);
    const notifiche = await this.notifiche.perUtente(cid, 'admin', u.id, false, 20);
    const [dip] = await this.ds.query('SELECT COUNT(*)::int AS n FROM employees WHERE company_id = $1 AND is_active = true', [cid]);
    return {
      ...base,
      coraHr: this.attiva(),
      dipendentiAttivi: dip.n,
      notifiche: notifiche.map((n: any) => ({ id: n.id, titolo: n.title, messaggio: n.message, link: n.link ?? '', letta: !!n.is_read, creata: iso(n.created_at) })),
      notificheNonLette: await this.notifiche.nonLette(cid, 'admin', u.id),
    };
  }

  async home(u: UtenteCorrente, settimana: string) {
    const cid = u.companyId!;
    const oggi = oggiRoma();
    const [me] = await senzaIsolamento(() => this.ds.query('SELECT name FROM users WHERE id = $1', [u.id]));
    const n = async (sql: string, p: unknown[]) => Number((await this.ds.query(sql, p))[0]?.n ?? 0);

    const attivi = await n('SELECT COUNT(*)::int AS n FROM employees WHERE company_id = $1 AND is_active = true', [cid]);
    const assenti = await n(`SELECT COUNT(DISTINCT lr.employee_id)::int AS n FROM leave_requests lr JOIN employees e ON e.id = lr.employee_id
      WHERE lr.company_id = $1 AND e.is_active = true AND lr.status = 'approved' AND lr.start_date <= $2 AND lr.end_date >= $2`, [cid, oggi]);

    const [az] = await this.ds.query('SELECT working_days FROM companies WHERE id = $1', [cid]);
    const lavorativi = String(az?.working_days ?? 'mon,tue,wed,thu,fri').split(',').map((x) => x.trim()).filter(Boolean);
    const inAttesa = await n(`SELECT COUNT(*)::int AS n FROM leave_requests WHERE company_id = $1 AND status = 'pending'`, [cid]);
    const richieste = (await this.ds.query(
      `SELECT lr.id, lr.leave_type, lr.start_date::text AS start_date, lr.end_date::text AS end_date, lr.is_full_day,
              to_char(lr.start_time, 'HH24:MI') AS start_time, to_char(lr.end_time, 'HH24:MI') AS end_time,
              e.first_name, e.last_name, e.photo_path
         FROM leave_requests lr JOIN employees e ON e.id = lr.employee_id
        WHERE lr.company_id = $1 AND lr.status = 'pending' ORDER BY lr.created_at DESC, lr.id DESC LIMIT 3`, [cid]))
      .map((r: any) => ({
        id: r.id, tipo: r.leave_type, etichetta: TIPI[r.leave_type] ?? r.leave_type, nome: r.first_name, cognome: r.last_name, foto: foto(r.photo_path),
        da: r.start_date, a: r.end_date, giornata: r.is_full_day !== false, oraDa: r.start_time, oraA: r.end_time,
        giorni: contaGiorniLavorativi(r.start_date, r.end_date, lavorativi),
      }));

    const { todayOthers } = await compleanni(this.ds, cid);
    const festeggiati = todayOthers.length
      ? (await this.ds.query(
        `SELECT id, first_name, last_name, photo_path FROM employees WHERE company_id = $1 AND is_active = true AND birth_date IS NOT NULL
            AND to_char(birth_date, 'MM-DD') = $2 ORDER BY id`, [cid, oggi.slice(5)]))
        .map((p: any) => ({ id: p.id, nome: p.first_name, cognome: p.last_name, foto: foto(p.photo_path) }))
      : [];

    return {
      nome: String(me?.name ?? '').split(/\s+/)[0] ?? '',
      oggi, presenze: { attivi, assenti, presenti: Math.max(0, attivi - assenti) },
      richieste, inAttesa,
      prove: (await this.prova.daDecidere(cid)).length,
      compleanni: festeggiati,
      settimana: await this.settimana(cid, settimana),
      attivita: await this.attivita(cid),
    };
  }

  /** Presenze della settimana (widget-availability-heatmap.php): uno stato per collega e per giorno. */
  async settimana(cid: number, rif: string) {
    const h = await this.heatmap.dati(cid, {
      departmentId: null, baseUrl: '/admin/', scopeToggle: false, mioReparto: null, scopePredefinito: 'mine', ruolo: 'admin', employeeId: 0,
    }, { w: rif });
    const giorni = h.days.map((g: string, i: number) => ({ data: g, etichetta: h.dayLabels[i], lavorativo: h.dayIsWorking[i], festa: h.dayHolidayName[i] }));
    const persone = h.employees.map((e: any) => {
      const assenze = (h.leavesByEmp[e.id] ?? []).filter((l: any) => l.status === 'approved');
      const stati = h.days.map((g: string) => {
        const l = assenze.find((x: any) => String(x.start_date).slice(0, 10) <= g && String(x.end_date).slice(0, 10) >= g);
        if (l) return { stato: statoAssenza(l.leave_type), etichetta: (h.leaveTypeLabels as Record<string, string>)[l.leave_type] ?? TIPI[l.leave_type] ?? l.leave_type };
        // "Occupato" solo per oggi: lo stato di disponibilita' e' quello attuale
        if (g === h.today && ['in_call', 'in_meeting'].includes(e.availability_status)) {
          return { stato: 'occupato' as StatoGiorno, etichetta: e.availability_status === 'in_call' ? 'In chiamata' : 'In riunione' };
        }
        return { stato: 'disponibile' as StatoGiorno, etichetta: 'Disponibile' };
      });
      return { id: e.id, nome: e.first_name, cognome: e.last_name, foto: foto(e.photo_path), reparto: h.deptMap[e.department_id] ?? '', stati };
    });
    return { inizio: h.weekStart, fine: h.weekEnd, prima: h.prevWeek, dopo: h.nextWeek, oggi: h.today, giorni, persone };
  }

  /** Attivita' recente: nuovi dipendenti, documenti, comunicazioni, richieste (stesse fonti della dashboard). */
  private async attivita(cid: number) {
    const voci: Array<{ tipo: 'dipendente' | 'documento' | 'comunicazione' | 'richiesta'; titolo: string; meta: string; data: string }> = [];
    for (const x of await this.ds.query('SELECT first_name, last_name, created_at FROM employees WHERE company_id = $1 ORDER BY created_at DESC, id DESC LIMIT 4', [cid])) {
      voci.push({ tipo: 'dipendente', titolo: `Nuovo dipendente: ${x.first_name} ${x.last_name}`, meta: 'Aggiunto al sistema', data: iso(x.created_at) });
    }
    for (const d of await this.ds.query(
      `SELECT d.title, d.created_at, e.first_name, e.last_name, u.name AS uploader FROM documents d JOIN employees e ON d.employee_id = e.id
         JOIN users u ON d.uploaded_by = u.id WHERE d.company_id = $1 ORDER BY d.created_at DESC, d.id DESC LIMIT 6`, [cid])) {
      voci.push({ tipo: 'documento', titolo: `${d.title} per ${d.first_name} ${d.last_name}`, meta: `Caricato da ${d.uploader}`, data: iso(d.created_at) });
    }
    for (const x of await this.ds.query(
      `SELECT c.title, c.created_at, u.name AS author FROM communications c JOIN users u ON c.created_by = u.id WHERE c.company_id = $1 ORDER BY c.created_at DESC, c.id DESC LIMIT 4`, [cid])) {
      voci.push({ tipo: 'comunicazione', titolo: `Avviso: ${x.title}`, meta: `Da ${x.author}`, data: iso(x.created_at) });
    }
    for (const lr of await this.ds.query(
      `SELECT lr.leave_type, lr.status, lr.created_at, lr.approved_at, lr.created_by_admin, e.first_name, e.last_name
         FROM leave_requests lr JOIN employees e ON lr.employee_id = e.id
        WHERE lr.company_id = $1 ORDER BY GREATEST(lr.created_at, COALESCE(lr.approved_at, lr.created_at)) DESC, lr.id DESC LIMIT 8`, [cid])) {
      const tipo = (TIPI[lr.leave_type] ?? lr.leave_type).toLowerCase();
      const chi = `${lr.first_name} ${lr.last_name}`;
      if (lr.approved_at && ['approved', 'rejected'].includes(lr.status)) {
        voci.push({ tipo: 'richiesta', titolo: `${lr.status === 'approved' ? 'Approvata' : 'Rifiutata'} richiesta di ${tipo}`, meta: chi, data: iso(lr.approved_at) });
      }
      if (!lr.created_by_admin) voci.push({ tipo: 'richiesta', titolo: `Nuova richiesta di ${tipo}`, meta: chi, data: iso(lr.created_at) });
    }
    return voci.sort((a, b) => (a.data < b.data ? 1 : a.data > b.data ? -1 : 0)).slice(0, 8);
  }

  /** Stato di oggi di ogni dipendente: assenza approvata o smart working. */
  private async statiOggi(cid: number): Promise<Map<number, { stato: string; etichetta: string }>> {
    const oggi = oggiRoma();
    const out = new Map<number, { stato: string; etichetta: string }>();
    for (const r of await this.ds.query(
      `SELECT DISTINCT ON (employee_id) employee_id, leave_type FROM leave_requests
        WHERE company_id = $1 AND status = 'approved' AND start_date <= $2 AND end_date >= $2 ORDER BY employee_id, id`, [cid, oggi])) {
      out.set(r.employee_id, r.leave_type === 'smart_working'
        ? { stato: 'smart', etichetta: 'Smart working' }
        : { stato: 'assente', etichetta: TIPI[r.leave_type] ?? 'Assente' });
    }
    return out;
  }

  /** Anagrafica (admin/employees.php, elenco): attivi e cessati, con lo stato di oggi. */
  async dipendenti(cid: number) {
    const stati = await this.statiOggi(cid);
    const righe = await this.ds.query(
      `SELECT e.id, e.first_name, e.last_name, e.email, e.username, e.fiscal_code, e.photo_path, e.is_active, e.availability_status,
              e.department_id, d.name AS reparto
         FROM employees e LEFT JOIN departments d ON d.id = e.department_id
        WHERE e.company_id = $1 ORDER BY e.is_active DESC, e.last_name, e.first_name, e.id`, [cid]);
    const reparti = await this.ds.query('SELECT id, name FROM departments WHERE company_id = $1 ORDER BY name', [cid]);
    return {
      reparti,
      dipendenti: righe.map((e: any) => {
        let s = stati.get(e.id) ?? { stato: 'presente', etichetta: 'In presenza' };
        if (!stati.has(e.id) && e.availability_status === 'in_call') s = { stato: 'occupato', etichetta: 'In chiamata' };
        if (!stati.has(e.id) && e.availability_status === 'in_meeting') s = { stato: 'occupato', etichetta: 'In riunione' };
        if (!e.is_active) s = { stato: 'cessato', etichetta: 'Non attivo' };
        return {
          id: e.id, nome: e.first_name, cognome: e.last_name, email: e.email ?? '', username: e.username ?? '', cf: e.fiscal_code ?? '',
          foto: foto(e.photo_path), attivo: !!e.is_active, repartoId: e.department_id, reparto: e.reparto ?? '', ...s,
        };
      }),
    };
  }

  /** Assunzioni (admin/hire-requests.php, elenco). */
  async assunzioni(cid: number) {
    const righe = await this.ds.query(
      `SELECT h.id, h.status, h.employee_first_name, h.employee_last_name, h.start_date::text AS start_date, h.created_at,
              h.contract_indeterminato, h.contract_determinato, h.contract_apprendistato, h.contract_tirocinio, h.role_description, u.name AS consulente
         FROM hire_requests h LEFT JOIN users u ON u.id = h.assigned_consulente_user_id
        WHERE h.company_id = $1 ORDER BY h.created_at DESC, h.id DESC`, [cid]);
    const contratto = (h: any) => [h.contract_indeterminato && 'Indeterminato', h.contract_determinato && 'Determinato',
      h.contract_apprendistato && 'Apprendistato', h.contract_tirocinio && 'Tirocinio'].filter(Boolean).join(', ');
    return righe.map((h: any) => ({
      id: h.id, stato: h.status, etichetta: STATI_ASSUNZIONE[h.status] ?? h.status, nome: h.employee_first_name ?? '', cognome: h.employee_last_name ?? '',
      inizio: h.start_date, contratto: contratto(h), ruolo: h.role_description ?? '', consulente: h.consulente ?? '', creata: iso(h.created_at),
    }));
  }

  /** Timbrature del giorno (admin/attendance.php): entrata, uscita, ore lavorate, stato. */
  async presenze(cid: number, giorno: string) {
    const oggi = oggiRoma();
    const day = /^\d{4}-\d{2}-\d{2}$/.test(giorno) && !Number.isNaN(Date.parse(`${giorno}T12:00:00Z`)) ? giorno : oggi;
    const [az] = await senzaIsolamento(() => this.ds.query('SELECT timbratura_enabled FROM companies WHERE id = $1', [cid]));
    if (az && az.timbratura_enabled === false) return { attive: false, giorno: day, oggi };
    const dip = await this.ds.query(
      `SELECT e.id, e.first_name, e.last_name, e.email, e.photo_path, e.department_id, d.name AS reparto FROM employees e
         LEFT JOIN departments d ON d.id = e.department_id WHERE e.company_id = $1 AND e.is_active = true ORDER BY e.last_name, e.first_name`, [cid]);
    const timbrature = await this.ds.query(
      `SELECT employee_id, kind, to_char(punch_at AT TIME ZONE 'Europe/Rome', 'HH24:MI') AS ora, punch_at FROM attendance_punches
        WHERE company_id = $1 AND (punch_at AT TIME ZONE 'Europe/Rome')::date = $2 ORDER BY punch_at, id`, [cid, day]);
    const assenze = new Map<number, string>();
    for (const r of await this.ds.query(
      `SELECT DISTINCT ON (employee_id) employee_id, leave_type FROM leave_requests WHERE company_id = $1 AND status = 'approved'
          AND start_date <= $2 AND end_date >= $2 ORDER BY employee_id, id`, [cid, day])) assenze.set(r.employee_id, TIPI[r.leave_type] ?? r.leave_type);
    const per = new Map<number, any[]>();
    for (const t of timbrature) {
      if (!per.has(t.employee_id)) per.set(t.employee_id, []);
      per.get(t.employee_id)!.push(t);
    }
    let presenti = 0;
    let senza = 0;
    let ore = 0;
    const righe = dip.map((e: any) => {
      const lista = per.get(e.id) ?? [];
      let aperta: number | null = null;
      let secondi = 0;
      for (const t of lista) {
        const s = new Date(t.punch_at).getTime() / 1000;
        if (t.kind === 'in') aperta = s;
        else if (t.kind === 'out' && aperta !== null) { secondi += s - aperta; aperta = null; }
      }
      const dentro = aperta !== null;
      if (aperta !== null && day === oggi) secondi += Date.now() / 1000 - aperta;
      ore += secondi / 3600;
      const entrata = lista.find((t: any) => t.kind === 'in')?.ora ?? null;
      const uscita = dentro ? null : [...lista].reverse().find((t: any) => t.kind === 'out')?.ora ?? null;
      let stato = 'uscito';
      let etichetta = 'Uscito';
      if (!lista.length) {
        if (assenze.has(e.id)) { stato = 'assente'; etichetta = assenze.get(e.id)!; } else { stato = 'senza'; etichetta = 'Senza timbratura'; senza++; }
      } else if (dentro) { stato = 'dentro'; etichetta = 'In sede'; presenti++; }
      return {
        id: e.id, nome: e.first_name, cognome: e.last_name, email: e.email ?? '', foto: foto(e.photo_path), repartoId: e.department_id, reparto: e.reparto ?? '',
        stato, etichetta, entrata, uscita, minuti: lista.length ? Math.round(secondi / 60) : null,
      };
    });
    const reparti = await this.ds.query('SELECT id, name FROM departments WHERE company_id = $1 ORDER BY name', [cid]);
    return { attive: true, giorno: day, oggi, totale: dip.length, presenti, senza, ore: Math.round(ore), righe, reparti };
  }

  /** Reparti con i loro dipendenti (admin/departments.php e department-detail.php). */
  async reparti(cid: number) {
    const reparti = await this.ds.query('SELECT id, name, code, description, is_active FROM departments WHERE company_id = $1 ORDER BY name', [cid]);
    const dip = await this.ds.query(
      `SELECT id, first_name, last_name, email, fiscal_code, photo_path, department_id FROM employees
        WHERE company_id = $1 AND is_active = true ORDER BY last_name, first_name`, [cid]);
    const persona = (e: any) => ({ id: e.id, nome: e.first_name, cognome: e.last_name, email: e.email ?? '', cf: e.fiscal_code ?? '', foto: foto(e.photo_path), repartoId: e.department_id });
    return {
      reparti: reparti.map((r: any) => ({
        id: r.id, nome: r.name, codice: r.code, descrizione: r.description ?? '', attivo: !!r.is_active,
        dipendenti: dip.filter((e: any) => e.department_id === r.id).map(persona),
      })),
      dipendenti: dip.map(persona),
    };
  }

  /** Ferie e permessi (admin/leave-requests.php): in attesa e storico, con quanto serve per crearne una. */
  async ferie(cid: number) {
    const [az] = await this.ds.query('SELECT working_days FROM companies WHERE id = $1', [cid]);
    const lavorativi = String(az?.working_days ?? 'mon,tue,wed,thu,fri').split(',').map((x) => x.trim()).filter(Boolean);
    const righe = await this.ds.query(
      `SELECT lr.id, lr.leave_type, lr.status, lr.start_date::text AS start_date, lr.end_date::text AS end_date, lr.is_full_day,
              to_char(lr.start_time, 'HH24:MI') AS start_time, to_char(lr.end_time, 'HH24:MI') AS end_time, lr.created_at,
              lr.attachment_path IS NOT NULL AS allegato, lr.certificate_path IS NOT NULL AS certificato, lr.protocol_number, lr.certificate_waived,
              e.id AS employee_id, e.first_name, e.last_name, e.email, e.photo_path, e.department_id
         FROM leave_requests lr JOIN employees e ON e.id = lr.employee_id
        WHERE lr.company_id = $1 ORDER BY lr.created_at DESC, lr.id DESC LIMIT 300`, [cid]);
    const minuti = (a: string | null, b: string | null) => {
      if (!a || !b) return 0;
      const [h1, m1] = a.split(':').map(Number);
      const [h2, m2] = b.split(':').map(Number);
      return Math.max(0, h2 * 60 + m2 - (h1 * 60 + m1));
    };
    const richieste = righe.map((r: any) => {
      const intera = r.is_full_day !== false;
      const giorni = contaGiorniLavorativi(r.start_date, r.end_date, lavorativi);
      const min = intera ? 0 : minuti(r.start_time, r.end_time);
      return {
        id: r.id, tipo: r.leave_type, etichetta: TIPI[r.leave_type] ?? r.leave_type, stato: r.status,
        da: r.start_date, a: r.end_date, giornata: intera, oraDa: r.start_time, oraA: r.end_time,
        durata: intera ? `${giorni} giorn${giorni === 1 ? 'o' : 'i'}` : `${Math.floor(min / 60)}${min % 60 ? `h ${min % 60}m` : ` or${Math.floor(min / 60) === 1 ? 'a' : 'e'}`}`,
        inviata: iso(r.created_at), allegato: !!r.allegato, certificato: !!r.certificato,
        documentiMancanti: r.leave_type === 'malattia' && r.status === 'pending' && (!r.protocol_number || (!r.certificato && !r.certificate_waived)),
        dipendente: { id: r.employee_id, nome: r.first_name, cognome: r.last_name, email: r.email ?? '', foto: foto(r.photo_path), repartoId: r.department_id },
      };
    });
    const reparti = await this.ds.query('SELECT id, name FROM departments WHERE company_id = $1 ORDER BY name', [cid]);
    const dipendenti = (await this.ds.query(
      'SELECT id, first_name, last_name FROM employees WHERE company_id = $1 AND is_active = true ORDER BY last_name, first_name', [cid]))
      .map((e: any) => ({ id: e.id, nome: e.first_name, cognome: e.last_name }));
    return { richieste, reparti, dipendenti, tipi: Object.entries(TIPI).map(([k, v]) => ({ valore: k, etichetta: v })) };
  }

  /** Comunicazioni (admin/communications.php, elenco) con letture e stato. */
  async comunicazioni(cid: number) {
    const oggi = oggiRoma();
    const righe = await this.comunicazioniAdmin.elenco(cid);
    return righe.map((c: any) => {
      const pub = c.publish_date ? String(c.publish_date instanceof Date ? c.publish_date.toISOString() : c.publish_date).slice(0, 10) : null;
      const scad = c.expire_date ? String(c.expire_date instanceof Date ? c.expire_date.toISOString() : c.expire_date).slice(0, 10) : null;
      const stato = !c.is_published ? 'bozza' : scad && scad < oggi ? 'scaduta' : pub && pub > oggi ? 'programmata' : 'pubblicata';
      return {
        id: c.id, titolo: c.title, priorita: c.priority, pubblicazione: pub, scadenza: scad, stato,
        letture: c.read_count, destinatari: c.total_employees, reparto: c.is_global ? '' : (c.department_name ?? ''),
      };
    });
  }

  /** Documenti aziendali (admin/documenti-aziendali.php): una riga per distribuzione. */
  async documentiAziendali(cid: number) {
    const [{ n }] = await this.ds.query('SELECT COUNT(*)::int AS n FROM employees WHERE company_id = $1 AND is_active = true', [cid]);
    const gruppi = await this.personali.gruppi(cid);
    return {
      attivi: n,
      documenti: gruppi.map((g: any) => ({
        gruppo: g.bulk_group, nome: g.name, file: g.original_name, caricato: iso(g.created_at), caricatoDa: g.uploaded_by_name,
        destinatari: g.employee_count, scaricati: g.downloaded_count, scadenza: g.expires_on ? String(g.expires_on instanceof Date ? g.expires_on.toISOString() : g.expires_on).slice(0, 10) : null,
        visibile: !!g.visible_to_employee, notificheInCoda: g.pending_notifications, campione: g.sample_id,
      })),
    };
  }

  /**
   * Accessi esterni (admin/consulente-lavoro.php, admin/formatori.php): stessi
   * utenti della pagina classica, con le aziende collegate e se sono condivisi
   * con altri tenant (in quel caso dati e password non si toccano).
   */
  async accessi(u: UtenteCorrente, ruolo: 'consulente_lavoro' | 'formatore') {
    const cid = u.companyId!;
    const accessibili = await this.tenant.accessibili({ id: u.id, role: u.ruolo, companyId: u.companyIdAccount ?? null });
    const acc = accessibili.length ? accessibili : [cid];
    const condivisi = ruolo === 'consulente_lavoro';
    const utenti = await senzaIsolamento(() => this.ds.query(
      `SELECT DISTINCT u.id, u.username, u.name, u.email, u.is_active, u.last_login, u.company_id,
              ARRAY(SELECT uc.company_id FROM user_companies uc WHERE uc.user_id = u.id ORDER BY uc.company_id) AS collegate
         FROM users u WHERE (u.company_id = $1 ${condivisi ? 'OR u.id IN (SELECT user_id FROM user_companies WHERE company_id = ANY($3::int[]))' : ''})
          AND u.role = $2 ORDER BY u.name ASC`, condivisi ? [cid, ruolo, acc] : [cid, ruolo]));
    const aziende = accessibili.length ? await senzaIsolamento(() => this.ds.query(
      `SELECT c.id, c.name, (SELECT COUNT(*) FROM employees e WHERE e.company_id = c.id AND e.is_active = true)::int AS dipendenti
         FROM companies c WHERE c.is_active = true AND c.id = ANY($1::int[]) ORDER BY c.name`, [accessibili])) : [];
    return {
      aziende,
      utenti: utenti.map((x: any) => {
        const collegate: number[] = (x.collegate ?? []).map(Number);
        const tutte = [...new Set([...(x.company_id ? [x.company_id] : []), ...collegate])];
        return {
          id: x.id, nome: x.name, username: x.username, email: x.email ?? '', attivo: !!x.is_active, ultimoAccesso: iso(x.last_login),
          aziende: tutte.length, collegate: collegate.filter((c) => accessibili.includes(c)),
          condiviso: tutte.some((c) => !accessibili.includes(c)),
        };
      }),
    };
  }

  /** Anteprima dei buoni pasto del mese in corso (stesso calcolo dell'export presenze). */
  async anteprimaBuoni(cid: number) {
    const oggi = oggiRoma();
    const [anno, mese] = [Number(oggi.slice(0, 4)), Number(oggi.slice(5, 7))];
    const righe = (await this.presenzeExport.buoniMese(cid, mese, anno)).filter((r) => r.buoni !== null);
    const media = righe.length ? Math.round(righe.reduce((s, r) => s + (r.buoni ?? 0), 0) / righe.length) : 0;
    return { mese, anno, attivi: righe.length > 0, media, dipendenti: righe };
  }

  /** Richieste "Password dimenticata?" in attesa, solo di account di questa azienda. */
  async richiesteReset(cid: number) {
    const righe = await senzaIsolamento(() => this.ds.query(
      `SELECT prr.id, prr.user_type, prr.created_at,
              COALESCE(TRIM(e.first_name || ' ' || e.last_name), u.name) AS nome
         FROM password_reset_requests prr
         LEFT JOIN employees e ON prr.user_type = 'employee' AND e.id = prr.user_id
         LEFT JOIN users u ON prr.user_type <> 'employee' AND u.id = prr.user_id
        WHERE prr.status = 'pending' AND ((prr.user_type = 'employee' AND e.company_id = $1) OR (prr.user_type <> 'employee' AND u.company_id = $1))
        ORDER BY prr.created_at DESC, prr.id DESC`, [cid]));
    const TIPO: Record<string, string> = { employee: 'Dipendente', admin: 'Amministratore', consulente_lavoro: 'Consulente del lavoro', formatore: 'Formatore' };
    return righe.map((r: any) => ({ id: r.id, nome: r.nome ?? '', tipo: TIPO[r.user_type] ?? r.user_type, richiesta: iso(r.created_at) }));
  }
}
