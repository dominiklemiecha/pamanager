import { Injectable } from '@nestjs/common';
import { DataSource } from 'typeorm';
import { nomeFestivita } from '../../../common/calcoli/festivita';
import { oggiRoma } from '../../../common/calcoli/php';

const CHIAVI = ['', 'mon', 'tue', 'wed', 'thu', 'fri', 'sat', 'sun'];
const ETICHETTE = ['', 'Lun', 'Mar', 'Mer', 'Gio', 'Ven', 'Sab', 'Dom'];
const MESI = ['', 'gen', 'feb', 'mar', 'apr', 'mag', 'giu', 'lug', 'ago', 'set', 'ott', 'nov', 'dic'];
const giorno = (ymd: string, delta: number) => { const d = new Date(`${ymd}T12:00:00Z`); d.setUTCDate(d.getUTCDate() + delta); return d.toISOString().slice(0, 10); };
const dow = (ymd: string) => new Date(`${ymd}T12:00:00Z`).getUTCDay() || 7;

export interface OpzioniHeatmap {
  departmentId: number | null;
  baseUrl: string;
  scopeToggle: boolean;
  mioReparto: number | null;
  scopePredefinito: 'mine' | 'all';
  /** Ruolo di chi guarda (la causale L.104 la vede solo l'HR). */
  ruolo: string;
  employeeId: number;
}

/**
 * Dati del widget presenze settimanali (widget-availability-heatmap.php, parte
 * che leggeva il database): settimana, giorni lavorativi e festivi,
 * dipendenti, assenze. Il disegno (ordine, conteggi, avatar) e' nel template
 * convertito, come nel PHP.
 */
@Injectable()
export class HeatmapService {
  constructor(private readonly ds: DataSource) {}

  async dati(cid: number, o: OpzioniHeatmap, query: Record<string, string>) {
    const oggi = oggiRoma();
    const w = String(query.w ?? '');
    const rif = /^\d{4}-\d{2}-\d{2}$/.test(w) && !Number.isNaN(Date.parse(`${w}T12:00:00Z`)) ? w : oggi;
    const inizio = giorno(rif, -(dow(rif) - 1));
    const fine = giorno(inizio, 6);

    const [az] = await this.ds.query('SELECT working_days FROM companies WHERE id = $1', [cid]);
    let lavorativi = String(az?.working_days ?? 'mon,tue,wed,thu,fri').split(',').map((x) => x.trim()).filter(Boolean);
    if (!lavorativi.length) lavorativi = ['mon', 'tue', 'wed', 'thu', 'fri'];
    const days: string[] = [];
    const dayLabels: string[] = [];
    const dayIsWorking: boolean[] = [];
    const dayHolidayName: Array<string | null> = [];
    for (let i = 0; i < 7; i++) {
      const g = giorno(inizio, i);
      const festa = nomeFestivita(g);
      days.push(g);
      dayLabels.push(ETICHETTE[dow(g)]);
      dayHolidayName.push(festa);
      dayIsWorking.push(festa === null && lavorativi.includes(CHIAVI[dow(g)]));
    }
    const [a, b] = [new Date(`${inizio}T12:00:00Z`), new Date(`${fine}T12:00:00Z`)];
    const rangeLabel = `${a.getUTCDate()} ${MESI[a.getUTCMonth() + 1]} - ${b.getUTCDate()} ${MESI[b.getUTCMonth() + 1]} ${b.getUTCFullYear()}`;

    const scope = String(query.scope ?? o.scopePredefinito);
    const filtroReparto = o.departmentId !== null && (!o.scopeToggle || scope === 'mine');
    const p: unknown[] = [cid];
    let sql = `SELECT id, first_name, last_name, photo_path, department_id, availability_status, availability_set_at
                 FROM employees WHERE is_active = true AND company_id = $1`;
    if (filtroReparto) { p.push(o.departmentId); sql += ' AND department_id = $2'; }
    const employees = await this.ds.query(sql + ' ORDER BY last_name, first_name, id', p);

    const deptMap: Record<number, string> = {};
    for (const d of await this.ds.query('SELECT id, name FROM departments WHERE company_id = $1', [cid])) deptMap[d.id] = d.name;

    const leavesByEmp: Record<number, any[]> = {};
    if (employees.length) {
      const righe = await this.ds.query(
        `SELECT employee_id, leave_type, start_date, end_date, start_time, end_time, status FROM leave_requests
          WHERE employee_id = ANY($1::int[]) AND status IN ('approved', 'pending') AND start_date <= $2 AND end_date >= $3 ORDER BY id`,
        [employees.map((e: any) => e.id), fine, inizio]);
      for (const r of righe) (leavesByEmp[r.employee_id] ??= []).push(r);
    }

    return {
      heatmapDepartmentId: o.departmentId, heatmapBaseUrl: o.baseUrl, heatmapShowScopeToggle: o.scopeToggle,
      heatmapMyDepartmentId: o.mioReparto, heatmapDefaultScope: o.scopePredefinito,
      weekStart: inizio, weekEnd: fine, prevWeek: giorno(inizio, -7), nextWeek: giorno(inizio, 7), today: oggi,
      days, dayLabels, dayIsWorking, dayHolidayName, rangeLabel, employees, deptMap, leavesByEmp,
      leaveTypeLabels: { ferie: 'Ferie', permesso: 'Permesso', malattia: 'Malattia', permesso_104: 'Permesso L.104', congedo_parentale: 'Congedo Parentale', smart_working: 'Smart working', altro: 'Assenza' },
      __hmCurrentRole: o.ruolo, __hmCurrentEmpId: o.ruolo === 'employee' ? o.employeeId : 0,
      __hmMaskL104: o.ruolo !== 'admin',
    };
  }
}
