import { Injectable } from '@nestjs/common';
import { DataSource } from 'typeorm';
import { contaGiorniLavorativi } from '../../common/calcoli/orario';
import { oggiRoma } from '../../common/calcoli/php';
import { senzaIsolamento } from '../../common/database/isolamento';
import type { UtenteCorrente } from '../auth/auth.types';
import { TIPI } from '../ferie/ferie.service';
import { NotificheService } from '../notifiche/notifiche.service';
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
    private readonly heatmap: HeatmapService, private readonly prova: ProvaService) {}

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
}
