import { Injectable } from '@nestjs/common';
import { DataSource } from 'typeorm';
import { senzaIsolamento } from '../../common/database/isolamento';

export const ATTESA_SECONDI = 30;
const ORA = (c: string) => `to_char(${c} AT TIME ZONE 'Europe/Rome', 'YYYY-MM-DD HH24:MI:SS')`;

/**
 * Timbratura del dipendente (AttendancePunch::record e punch.php): entrata
 * o uscita in base all'ultima di oggi, con un intervallo minimo di 30
 * secondi contro le doppie letture della carta.
 */
@Injectable()
export class TimbraturaService {
  constructor(private readonly ds: DataSource) {}

  async registra(employeeId: number, fonte: string, ip: string | null, userAgent: string) {
    const [e] = await this.ds.query('SELECT id, company_id, first_name, last_name, is_active FROM employees WHERE id = $1', [employeeId]);
    if (!e) return { success: false as const, error: 'Dipendente non trovato' };
    if (!e.is_active) return { success: false as const, error: 'Account non attivo' };
    return this.ds.transaction(async (m) => {
      // Una timbratura alla volta per dipendente (due letture ravvicinate non ne creano due)
      await m.query('SELECT pg_advisory_xact_lock(hashtext($1))', [`timbratura_${employeeId}`]);
      const [ultima] = await m.query(
        `SELECT kind, ${ORA('punch_at')} AS punch_at, EXTRACT(EPOCH FROM (CURRENT_TIMESTAMP - punch_at))::int AS trascorsi
           FROM attendance_punches WHERE employee_id = $1 ORDER BY punch_at DESC, id DESC LIMIT 1`, [employeeId]);
      if (ultima && ultima.trascorsi < ATTESA_SECONDI) {
        return { success: false as const, cooldown: true, remaining: ATTESA_SECONDI - ultima.trascorsi, last_kind: ultima.kind, last_at: ultima.punch_at,
          error: 'Attendi qualche secondo prima della prossima timbratura.' };
      }
      const [oggi] = await m.query(
        `SELECT kind FROM attendance_punches WHERE employee_id = $1 AND (punch_at AT TIME ZONE 'Europe/Rome')::date = (CURRENT_TIMESTAMP AT TIME ZONE 'Europe/Rome')::date
          ORDER BY punch_at DESC, id DESC LIMIT 1`, [employeeId]);
      const tipo = oggi?.kind === 'in' ? 'out' : 'in';
      const [r] = await m.query(
        `INSERT INTO attendance_punches (company_id, employee_id, punch_at, kind, source, ip, user_agent) VALUES ($1, $2, CURRENT_TIMESTAMP, $3, $4, $5, $6)
         RETURNING id, ${ORA('punch_at')} AS punch_at`, [e.company_id, employeeId, tipo, fonte, ip ? ip.slice(0, 45) : null, userAgent.slice(0, 255)]);
      return { success: true as const, id: r.id, kind: tipo, punch_at: r.punch_at as string, employee: `${e.first_name ?? ''} ${e.last_name ?? ''}`.trim() };
    });
  }

  /** punch.php: carta dell'azienda del dipendente, timbrature attive, poi registrazione. */
  async daCarta(employeeId: number, slug: string, ip: string | null, userAgent: string): Promise<{ success: boolean; kind?: string | null; ora?: string; employee?: string; error?: string }> {
    const [e] = await this.ds.query('SELECT company_id FROM employees WHERE id = $1', [employeeId]);
    const cid = e?.company_id ?? 0;
    let diversa = false;
    if (slug !== '') {
      const s = slug.toLowerCase();
      let [az] = await senzaIsolamento(() => this.ds.query('SELECT id, name, slug FROM companies WHERE lower(slug) = $1', [s]));
      if (!az && cid) {
        // Slug ricavato dal nome dell'azienda del dipendente, salvato la prima volta
        const [mia] = await senzaIsolamento(() => this.ds.query('SELECT id, name, slug FROM companies WHERE id = $1', [cid]));
        const auto = mia ? String(mia.name).toLowerCase().replace(/[^a-z0-9]+/g, '-').replace(/^-+|-+$/g, '') : '';
        if (mia && auto !== '' && auto === s) {
          await senzaIsolamento(() => this.ds.query('UPDATE companies SET slug = $2 WHERE id = $1', [mia.id, auto]));
          az = { ...mia, slug: auto };
        }
      }
      diversa = !az || az.id !== cid;
    }
    const [abil] = cid ? await senzaIsolamento(() => this.ds.query('SELECT timbratura_enabled FROM companies WHERE id = $1', [cid])) : [];
    const attive = !cid || abil?.timbratura_enabled !== false;
    const hhmm = (s: string | null | undefined) => (s ? s.slice(11, 16) : '');
    if (!attive) return { success: false, error: "Timbrature disabilitate. Contatta l'amministratore." };
    if (diversa) return { success: false, error: 'Carta non valida per la tua azienda.' };
    const r = await this.registra(employeeId, 'nfc', ip, userAgent);
    if (r.success) return { success: true, kind: r.kind, ora: hhmm(r.punch_at), employee: r.employee };
    return { success: false, kind: 'cooldown' in r ? r.last_kind : null, error: r.error };
  }
}
