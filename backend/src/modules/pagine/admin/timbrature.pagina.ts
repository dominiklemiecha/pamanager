import { Injectable } from '@nestjs/common';
import { DataSource } from 'typeorm';
import { oggiRoma } from '../../../common/calcoli/php';
import { senzaIsolamento } from '../../../common/database/isolamento';
import { ContestoPagina, PaginaPhp, Pagina, redirect, vista } from '../pagina';
import { dataOraRoma } from '../normalizza';

const DATA = /^\d{4}-\d{2}-\d{2}$/;
const ORA = /^([01]\d|2[0-3]):[0-5]\d$/;
const dataValida = (s: string) => DATA.test(s) && new Date(`${s}T00:00:00Z`).toISOString().slice(0, 10) === s;

/**
 * Timbrature dell'HR (admin/attendance.php): elenco del giorno per
 * dipendente con statistiche, timbratura manuale, modifica, eliminazione.
 *
 * Correzione: data e ora non valide danno un errore della pagina invece del
 * messaggio del database.
 */
@Injectable()
@PaginaPhp('admin/attendance')
export class TimbratureAdminPagina implements Pagina {
  readonly ruoli = ['admin'];
  constructor(private readonly ds: DataSource) {}

  private async attive(cid: number) {
    const [r] = await senzaIsolamento(() => this.ds.query('SELECT timbratura_enabled FROM companies WHERE id = $1', [cid]));
    return r ? r.timbratura_enabled !== false : true;
  }

  async azione(c: ContestoPagina, corpo: Record<string, string>) {
    const cid = c.u.companyId!;
    if (!(await this.attive(cid))) return redirect('/admin/punch-settings.php?msg=enable_first');
    const data = String(corpo.punch_date ?? '').trim();
    const ora = String(corpo.punch_time ?? '').trim();
    const tipo = corpo.kind ?? '';
    const note = String(corpo.notes ?? '').trim() || null;
    const valido = () => dataValida(data) && ORA.test(ora);
    const tipoValido = ['in', 'out'].includes(tipo);
    switch (corpo.action ?? '') {
      case 'create_manual': {
        const eid = Number.parseInt(corpo.employee_id ?? '', 10) || 0;
        if (!eid || !data || !ora || !tipo) return this.dati(c, '', 'Compila tutti i campi obbligatori.');
        const [e] = await this.ds.query('SELECT company_id FROM employees WHERE id = $1 AND company_id = $2', [eid, cid]);
        if (!e) return this.dati(c, '', 'Dipendente non valido per la tua azienda.');
        if (!tipoValido) return this.dati(c, '', 'Tipo timbratura non valido');
        if (!valido()) return this.dati(c, '', 'Data o ora non valida.');
        await this.ds.query(
          `INSERT INTO attendance_punches (company_id, employee_id, punch_at, kind, source, notes)
           VALUES ($1, $2, ($3::timestamp AT TIME ZONE 'Europe/Rome'), $4, 'manual', $5)`, [cid, eid, `${data} ${ora}:00`, tipo, note]);
        return this.dati(c, 'Timbratura aggiunta manualmente.');
      }
      case 'update_manual': {
        const id = Number.parseInt(corpo.punch_id ?? '', 10) || 0;
        if (!id || !data || !ora || !tipo) return this.dati(c, '', 'Dati non validi.');
        const [p] = await this.ds.query('SELECT id FROM attendance_punches WHERE id = $1 AND company_id = $2', [id, cid]);
        if (!p) return this.dati(c, '', 'Timbratura non trovata.');
        if (!tipoValido) return this.dati(c, '', 'Tipo non valido');
        if (!valido()) return this.dati(c, '', 'Data o ora non valida.');
        await this.ds.query(`UPDATE attendance_punches SET punch_at = ($2::timestamp AT TIME ZONE 'Europe/Rome'), kind = $3, notes = $4 WHERE id = $1`,
          [id, `${data} ${ora}:00`, tipo, note]);
        return this.dati(c, 'Timbratura aggiornata.');
      }
      case 'delete': {
        const id = Number.parseInt(corpo.punch_id ?? '', 10) || 0;
        const [p] = await this.ds.query('SELECT id FROM attendance_punches WHERE id = $1 AND company_id = $2', [id, cid]);
        if (!p) return this.dati(c, '', 'Timbratura non trovata.');
        await this.ds.query('DELETE FROM attendance_punches WHERE id = $1', [id]);
        return this.dati(c, 'Timbratura eliminata.');
      }
    }
    return this.dati(c);
  }

  async dati(c: ContestoPagina, message = '', error = '') {
    const cid = c.u.companyId!;
    if (!(await this.attive(cid))) return redirect('/admin/punch-settings.php?msg=enable_first');
    const oggi = oggiRoma();
    const day = c.query.d !== undefined ? String(c.query.d) : oggi;
    const employees = await this.ds.query(
      `SELECT e.id, e.first_name, e.last_name, e.photo_path, e.department_id, e.ccnl_id, d.name AS department_name
         FROM employees e LEFT JOIN departments d ON d.id = e.department_id WHERE e.company_id = $1 AND e.is_active = true
        ORDER BY e.last_name, e.first_name`, [cid]);
    const punches = dataValida(day) ? await this.ds.query(
      `SELECT id, company_id, employee_id, punch_at, kind, source, notes, created_at FROM attendance_punches
        WHERE company_id = $1 AND (punch_at AT TIME ZONE 'Europe/Rome')::date = $2 ORDER BY punch_at ASC, id ASC`, [cid, day]) : [];
    const byEmp: Record<number, any[]> = {};
    for (const p of punches) (byEmp[p.employee_id] ??= []).push(p);

    // Statistiche del giorno (come il PHP: coppie entrata/uscita, ancora dentro oggi conta fino ad adesso)
    let conTimbrature = 0;
    let dentro = 0;
    let ore = 0;
    let manuali = 0;
    for (const e of employees) {
      const lista = byEmp[e.id] ?? [];
      if (!lista.length) continue;
      conTimbrature++;
      let aperta: number | null = null;
      for (const p of lista) {
        if (p.source === 'manual') manuali++;
        const t = Math.floor(new Date(p.punch_at).getTime() / 1000);
        if (p.kind === 'in') aperta = t;
        else if (p.kind === 'out' && aperta !== null) { ore += (t - aperta) / 3600; aperta = null; }
      }
      if (aperta !== null) {
        dentro++;
        if (day === oggi) ore += (Date.now() / 1000 - aperta) / 3600;
      }
    }
    for (const lista of Object.values(byEmp)) for (const p of lista) p.punch_at = dataOraRoma(new Date(p.punch_at));
    return vista('Timbrature', {
      message, error, day, employees, byEmp,
      totalEmp: employees.length, empWithPunches: conTimbrature, empCurrentlyIn: dentro, empAbsent: employees.length - conTimbrature,
      totalHours: ore, manualCount: manuali,
    });
  }
}
