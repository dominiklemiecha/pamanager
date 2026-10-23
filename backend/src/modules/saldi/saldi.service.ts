import { Injectable, Logger } from '@nestjs/common';
import { DataSource } from 'typeorm';
import { contaGiorniLavorativi, oreEffettive } from '../../common/calcoli/orario';
import { oggiRoma, phpRound } from '../../common/calcoli/php';

export const TIPI_SALDO = ['ferie', 'permesso'] as const;
export type TipoSaldo = (typeof TIPI_SALDO)[number];

export interface Saldo {
  type: TipoSaldo;
  unit: 'giorni' | 'ore';
  entitled: number;
  carried: number;
  manual_used: number;
  auto_used: number;
  total: number;
  used: number;
  residual: number;
  percent: number;
  notes: string | null;
  balance_set_at: string | null;
  has_row: boolean;
}

export interface Orario { days: string[]; hours: number; lunch_start: string | null; lunch_end: string | null }

const num = (v: unknown, d = 0): number => (v === null || v === undefined ? d : Number(v));

/**
 * Saldi di ferie (giorni) e permessi (ore), come LeaveBalance.php.
 *
 *  residuo = maturato + riportato - (usato a mano + usato da richieste approvate)
 *
 * Le richieste future non contano ancora come usate. La maturazione mensile
 * si applica alla prima lettura del mese (niente cron), come nel PHP.
 * Le query passano dall'isolamento: dentro una richiesta si vedono solo i
 * dipendenti dell'azienda di chi chiama.
 */
@Injectable()
export class SaldiService {
  private readonly logger = new Logger('Saldi');

  constructor(private readonly ds: DataSource) {}

  async perDipendente(employeeId: number, anno?: number): Promise<Record<TipoSaldo, Saldo>> {
    const y = anno ?? Number(oggiRoma().slice(0, 4));
    try {
      const [e] = await this.ds.query('SELECT company_id FROM employees WHERE id = $1', [employeeId]);
      if (e?.company_id) await this.maturazioneAnnoCorrente(employeeId, Number(e.company_id));
    } catch (err: any) {
      this.logger.error(`maturazione automatica non riuscita: ${err?.message ?? err}`);
    }
    return { ferie: await this.uno(employeeId, y, 'ferie'), permesso: await this.uno(employeeId, y, 'permesso') };
  }

  async uno(employeeId: number, anno: number, tipo: TipoSaldo): Promise<Saldo> {
    const [r] = await this.ds.query(
      `SELECT entitled, carried_over, balance_set_at, manual_used, notes FROM employee_leave_balances
        WHERE employee_id = $1 AND year = $2 AND leave_type = $3`, [employeeId, anno, tipo]);
    const entitled = num(r?.entitled);
    const carried = num(r?.carried_over);
    const manualUsed = num(r?.manual_used);
    const setAt: string | null = r?.balance_set_at ?? null;
    const autoUsed = await this.usatoAutomatico(employeeId, anno, tipo, setAt);
    const total = entitled + carried;
    const used = manualUsed + autoUsed;
    const residual = total - used;
    return {
      type: tipo,
      unit: tipo === 'ferie' ? 'giorni' : 'ore',
      entitled,
      carried,
      manual_used: manualUsed,
      auto_used: autoUsed,
      total,
      used,
      residual,
      percent: total > 0 ? Math.max(0, Math.min(100, (used / total) * 100)) : 0,
      notes: r?.notes ?? null,
      balance_set_at: setAt,
      has_row: !!r,
    };
  }

  /** Usato dalle richieste approvate dell'anno, dalla data dell'istantanea a oggi. */
  async usatoAutomatico(employeeId: number, anno: number, tipo: TipoSaldo, istantanea: string | null): Promise<number> {
    if (!TIPI_SALDO.includes(tipo)) return 0;
    const inizio = istantanea && istantanea > `${anno}-01-01` ? istantanea : `${anno}-01-01`;
    const oggi = oggiRoma();
    const fine = `${anno}-12-31` < oggi ? `${anno}-12-31` : oggi;
    const righe = await this.ds.query(
      `SELECT start_date, end_date, is_full_day, start_time, end_time FROM leave_requests
        WHERE employee_id = $1 AND leave_type = $2 AND status = 'approved' AND start_date <= $3 AND end_date >= $4`,
      [employeeId, tipo, fine, inizio]);
    const o = await this.orario(employeeId);
    let tot = 0;
    for (const r of righe) {
      const rs = r.start_date > inizio ? r.start_date : inizio;
      const re = r.end_date < fine ? r.end_date : fine;
      if (tipo === 'ferie') {
        tot += contaGiorniLavorativi(rs, re, o.days);
      } else if (r.is_full_day) {
        tot += contaGiorniLavorativi(rs, re, o.days) * o.hours;
      } else if (r.start_time && r.end_time) {
        const h = oreEffettive(r.start_time, r.end_time, o.lunch_start, o.lunch_end);
        tot += h * Math.max(1, contaGiorniLavorativi(rs, re, o.days));
      }
    }
    return phpRound(tot, 2);
  }

  /** Orario del dipendente: il suo se impostato, altrimenti quello dell'azienda. */
  async orario(employeeId: number): Promise<Orario> {
    const [r] = await this.ds.query(
      `SELECT e.working_days AS e_days, e.hours_per_day AS e_hours, c.working_days AS c_days, c.hours_per_day AS c_hours,
              c.lunch_break_start AS c_lunch_s, c.lunch_break_end AS c_lunch_e
         FROM employees e LEFT JOIN companies c ON c.id = e.company_id WHERE e.id = $1`, [employeeId]);
    if (!r) return { days: ['mon', 'tue', 'wed', 'thu', 'fri'], hours: 8, lunch_start: null, lunch_end: null };
    const csv: string = r.e_days ? r.e_days : (r.c_days ?? 'mon,tue,wed,thu,fri');
    return {
      days: csv.split(',').map((d) => d.trim()).filter(Boolean),
      hours: r.e_hours !== null ? num(r.e_hours) : num(r.c_hours, 8),
      lunch_start: r.c_lunch_s ?? null,
      lunch_end: r.c_lunch_e ?? null,
    };
  }

  /** Diritto annuo: override del dipendente, poi CCNL del dipendente, poi CCNL dell'azienda. */
  async annuo(employeeId: number, tipo: TipoSaldo): Promise<number> {
    const [r] = await this.ds.query(
      `SELECT e.ferie_year_override, e.permessi_year_override, ec.ferie_days_year AS emp_ferie, ec.permessi_hours_year AS emp_perm,
              cc.ferie_days_year AS comp_ferie, cc.permessi_hours_year AS comp_perm
         FROM employees e
         LEFT JOIN companies cmp ON cmp.id = e.company_id
         LEFT JOIN ccnl_templates ec ON ec.id = e.ccnl_id
         LEFT JOIN ccnl_templates cc ON cc.id = cmp.default_ccnl_id
        WHERE e.id = $1`, [employeeId]);
    if (!r) return 0;
    const [ov, emp, comp] = tipo === 'ferie'
      ? [r.ferie_year_override, r.emp_ferie, r.comp_ferie]
      : [r.permessi_year_override, r.emp_perm, r.comp_perm];
    for (const v of [ov, emp, comp]) if (v !== null && v !== undefined) return num(v);
    return 0;
  }

  /**
   * Maturazione fino al mese corrente (idempotente con accrual_last_month).
   * Nuovo anno: riporta il residuo dell'anno prima. Neoassunto: matura dal
   * mese di assunzione e non riporta nulla.
   */
  async maturazioneAnnoCorrente(employeeId: number, companyId: number): Promise<void> {
    const oggi = oggiRoma();
    const anno = Number(oggi.slice(0, 4));
    const mese = Number(oggi.slice(5, 7));
    const chiaveMese = oggi.slice(0, 7);
    const [e] = await this.ds.query('SELECT hire_date FROM employees WHERE id = $1', [employeeId]);
    const assunzione: string | null = e?.hire_date ?? null;
    const assuntoQuestAnno = !!assunzione && Number(assunzione.slice(0, 4)) === anno;
    const mesiMaturati = assuntoQuestAnno ? Math.max(0, mese - Number(assunzione!.slice(5, 7))) : mese;

    for (const tipo of TIPI_SALDO) {
      const annuo = await this.annuo(employeeId, tipo);
      if (annuo <= 0) continue;
      const [r] = await this.ds.query(
        `SELECT id, entitled, carried_over, balance_set_at, accrual_last_month FROM employee_leave_balances
          WHERE employee_id = $1 AND year = $2 AND leave_type = $3`, [employeeId, anno, tipo]);
      if (!r) {
        const prec = await this.uno(employeeId, anno - 1, tipo);
        await this.ds.query(
          `INSERT INTO employee_leave_balances (employee_id, company_id, year, leave_type, entitled, carried_over, manual_used, balance_set_at, accrual_last_month)
           VALUES ($1, $2, $3, $4, $5, $6, 0, $7, $8)`,
          [employeeId, companyId, anno, tipo, phpRound((annuo / 12) * mesiMaturati, 2), assuntoQuestAnno ? 0 : Math.max(0, prec.residual),
            assuntoQuestAnno ? assunzione : null, chiaveMese]);
        continue;
      }
      if (r.accrual_last_month === chiaveMese) continue;
      let maturato: number;
      if (r.balance_set_at) {
        const s: string = r.balance_set_at;
        const mesiDa = Math.max(0, (anno - Number(s.slice(0, 4))) * 12 + (mese - Number(s.slice(5, 7))));
        maturato = phpRound((annuo / 12) * mesiDa, 2);
      } else {
        maturato = phpRound((annuo / 12) * mesiMaturati, 2);
      }
      await this.ds.query('UPDATE employee_leave_balances SET entitled = $2, accrual_last_month = $3 WHERE id = $1', [r.id, maturato, chiaveMese]);
    }
  }

  /** Inserisce o aggiorna un saldo (modifica dall'HR). */
  async salva(employeeId: number, companyId: number, anno: number, tipo: TipoSaldo, entitled: number, carried: number, manualUsed: number,
    notes: string | null, updatedBy: number): Promise<void> {
    if (!TIPI_SALDO.includes(tipo)) return;
    const [r] = await this.ds.query('SELECT id FROM employee_leave_balances WHERE employee_id = $1 AND year = $2 AND leave_type = $3', [employeeId, anno, tipo]);
    if (r) {
      await this.ds.query(
        'UPDATE employee_leave_balances SET entitled = $2, carried_over = $3, manual_used = $4, notes = $5, updated_by = $6 WHERE id = $1',
        [r.id, entitled, carried, manualUsed, notes, updatedBy]);
    } else {
      await this.ds.query(
        `INSERT INTO employee_leave_balances (employee_id, company_id, year, leave_type, entitled, carried_over, manual_used, notes, updated_by)
         VALUES ($1, $2, $3, $4, $5, $6, $7, $8, $9)`, [employeeId, companyId, anno, tipo, entitled, carried, manualUsed, notes, updatedBy]);
    }
  }

  /** "Residuo ad oggi": maturato 0, riportato = residuo, matura dai mesi successivi. */
  async impostaResiduo(employeeId: number, companyId: number, anno: number, tipo: TipoSaldo, residuo: number, data: string, updatedBy: number | null = null): Promise<void> {
    if (!TIPI_SALDO.includes(tipo)) return;
    const [r] = await this.ds.query('SELECT id FROM employee_leave_balances WHERE employee_id = $1 AND year = $2 AND leave_type = $3', [employeeId, anno, tipo]);
    const mese = data.slice(0, 7);
    if (r) {
      await this.ds.query(
        `UPDATE employee_leave_balances SET entitled = 0, carried_over = $2, balance_set_at = $3, manual_used = 0, accrual_last_month = $4, updated_by = $5 WHERE id = $1`,
        [r.id, residuo, data, mese, updatedBy]);
    } else {
      await this.ds.query(
        `INSERT INTO employee_leave_balances (employee_id, company_id, year, leave_type, entitled, carried_over, balance_set_at, manual_used, accrual_last_month, updated_by)
         VALUES ($1, $2, $3, $4, 0, $5, $6, 0, $7, $8)`, [employeeId, companyId, anno, tipo, residuo, data, mese, updatedBy]);
    }
  }

  /**
   * Allinea i saldi a una busta paga (LeaveBalance::applyFromPayslip): la busta
   * fotografa la fine del mese, quindi il saldo parte dal 1 del mese dopo.
   */
  async daBustaPaga(employeeId: number, companyId: number, saldi: Partial<Record<TipoSaldo, { residuo?: number | null; goduto?: number | null } | null>>,
    annoBusta: number, meseBusta: number, updatedBy: number | null = null, correzioni: Partial<Record<TipoSaldo, number | null>> = {}) {
    const d = new Date(Date.UTC(annoBusta, meseBusta, 1));
    const data = d.toISOString().slice(0, 10);
    const annoSaldo = d.getUTCFullYear();
    const stessoAnno = annoSaldo === annoBusta;
    const fatto: Partial<Record<TipoSaldo, { total: number; used: number; residual: number; set_at: string }>> = {};
    for (const tipo of TIPI_SALDO) {
      const b = saldi[tipo] ?? null;
      let residuo: number | null = b?.residuo ?? null;
      let usato = stessoAnno && b?.goduto !== undefined && b?.goduto !== null ? Number(b.goduto) : 0;
      const c = correzioni[tipo];
      if (c !== undefined && c !== null && (residuo === null || Math.abs(c - residuo) > 0.005)) {
        residuo = c;
        usato = 0;
      }
      if (residuo === null) continue;
      residuo = phpRound(residuo, 2);
      const totale = phpRound(residuo + usato, 2);
      await this.impostaResiduo(employeeId, companyId, annoSaldo, tipo, totale, data, updatedBy);
      await this.ds.query('UPDATE employee_leave_balances SET manual_used = $4, notes = $5 WHERE employee_id = $1 AND year = $2 AND leave_type = $3',
        [employeeId, annoSaldo, tipo, usato, `Allineato alla busta paga ${String(meseBusta).padStart(2, '0')}/${annoBusta}`]);
      fatto[tipo] = { total: totale, used: usato, residual: residuo, set_at: data };
    }
    return fatto;
  }

  /** CCNL utilizzabili dall'azienda: quelli di sistema e i suoi. */
  async ccnlDisponibili(companyId: number) {
    return this.ds.query(
      `SELECT id, code, name, ferie_days_year, permessi_hours_year FROM ccnl_templates
        WHERE company_id IS NULL OR company_id = $1 ORDER BY is_system DESC, name ASC`, [companyId]);
  }
}
