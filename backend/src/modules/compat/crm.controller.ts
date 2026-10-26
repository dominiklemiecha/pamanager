import { Body, Controller, Get, HttpCode, HttpException, Logger, Post, Query, Req, Res } from '@nestjs/common';
import { createHmac, randomBytes, timingSafeEqual } from 'crypto';
import type { Response } from 'express';
import { DataSource } from 'typeorm';
import { oggiRoma } from '../../common/calcoli/php';
import { senzaIsolamento } from '../../common/database/isolamento';
import { LimitiDbService } from '../../common/sicurezza/limiti-db.service';
import { verificaPassword } from '../../common/sicurezza/password';
import { Pubblico, SenzaCsrf } from '../auth/decoratori';
import type { RichiestaAutenticata } from '../auth/auth.types';

const TIPI: Record<string, string> = {
  ferie: 'Ferie', permesso: 'Permesso', malattia: 'Malattia', permesso_104: 'Permesso L.104', congedo_parentale: 'Congedo Parentale', congedo_separazione: 'Congedo Separazione',
  congedo_mestruale: 'Congedo Mestruale', altro: 'Altro', chiusura: 'Chiusura aziendale', smart_working: 'Smart working',
};
const b64 = (b: Buffer | string) => Buffer.from(b).toString('base64').replace(/\+/g, '-').replace(/\//g, '_').replace(/=+$/, '');
const deb64 = (s: string) => Buffer.from(s.replace(/-/g, '+').replace(/_/g, '/'), 'base64');
const errore = (messaggio: string, status = 400) => new HttpException({ error: true, message: messaggio }, status);

/** Segreto dei token: JWT_SECRET; fuori produzione, se manca, uno casuale per processo. */
let segretoLocale: string | null = null;
function segreto(): string {
  const s = process.env.JWT_SECRET || '';
  if (s) return s;
  if (process.env.NODE_ENV === 'production') throw errore('Server configuration error', 500);
  segretoLocale ??= randomBytes(32).toString('hex');
  return segretoLocale;
}
const scadenza = () => Number.parseInt(process.env.JWT_EXPIRY ?? '', 10) || 3600;

/** Token HS256 compatibile con quello del PHP (classe JWT di api/index.php). */
export function firmaToken(payload: Record<string, unknown>): string {
  const ora = Math.floor(Date.now() / 1000);
  const testa = b64(JSON.stringify({ typ: 'JWT', alg: 'HS256' }));
  const corpo = b64(JSON.stringify({ ...payload, iat: ora, exp: ora + scadenza() }));
  return `${testa}.${corpo}.${b64(createHmac('sha256', segreto()).update(`${testa}.${corpo}`).digest())}`;
}
export function leggiToken(token: string): Record<string, any> | null {
  const p = token.split('.');
  if (p.length !== 3) return null;
  const atteso = createHmac('sha256', segreto()).update(`${p[0]}.${p[1]}`).digest();
  const firma = deb64(p[2]);
  if (firma.length !== atteso.length || !timingSafeEqual(firma, atteso)) return null;
  try {
    const intestazione = JSON.parse(deb64(p[0]).toString());
    if (intestazione?.alg !== 'HS256') return null;
    const x = JSON.parse(deb64(p[1]).toString());
    if (!x || (x.exp !== undefined && x.exp < Math.floor(Date.now() / 1000))) return null;
    return x;
  } catch {
    return null;
  }
}

/**
 * API a token per i sistemi esterni (api/auth.php e api/attendance.php del
 * PHP), usate dal CRM: login a token, verifica, rinnovo e presenze/assenze
 * di una sola azienda (CRM_COMPANY_SLUG).
 *
 * Correzioni: niente token per gli account con verifica in due passaggi
 * (l'API la scavalcava) e tentativi falliti contati anche per lo staff,
 * con blocco temporaneo come nel login; il token di un account disattivato
 * non vale piu' (si controlla a ogni chiamata); nessun troncamento a 500
 * timbrature nell'intervallo; senza JWT_SECRET in produzione le API non
 * partono (nessun segreto di ripiego prevedibile).
 */
@Controller('api')
@Pubblico()
@SenzaCsrf()
export class CrmController {
  private readonly logger = new Logger('ApiToken');
  constructor(private readonly ds: DataSource, private readonly limiti: LimitiDbService) {}

  @Post('auth.php')
  @HttpCode(200)
  async auth(@Body() b: Record<string, any>, @Req() req: RichiestaAutenticata) {
    if (!b || typeof b !== 'object' || Array.isArray(b)) throw errore('Dati JSON non validi');
    const azione = String(b.action ?? 'login');
    if (azione === 'verify') {
      const t = String(b.token ?? this.tokenHeader(req) ?? '');
      if (!t) throw errore('Token mancante');
      const x = leggiToken(t);
      if (!x) throw errore('Token non valido o scaduto', 401);
      return { valid: true, payload: x, expires_in: x.exp - Math.floor(Date.now() / 1000) };
    }
    if (azione === 'refresh') {
      const x = await this.richiedi(req);
      return { success: true, token: firmaToken({ user_id: x.user_id ?? null, employee_id: x.employee_id ?? null, user_type: x.user_type, role: x.role ?? null, username: x.username ?? null, fiscal_code: x.fiscal_code ?? null }) };
    }
    if (azione !== 'login' && azione !== 'login_employee') throw errore('Azione non valida');
    const username = String(b.username ?? '');
    const password = String(b.password ?? '');
    if (!username || !password) throw errore('Username e password richiesti');
    if (!(await this.limiti.consenti(String(req.ip ?? 'unknown'), azione === 'login' ? 'api_login' : 'api_login_employee', 5, 300))) throw errore('Troppi tentativi', 429);
    const tabella = azione === 'login' ? 'users' : 'employees';
    const [a] = await senzaIsolamento(() => this.ds.query(`SELECT * FROM ${tabella} WHERE username = $1 AND is_active = true`, [username]));
    if (!a) throw errore('Credenziali non valide', 401);
    if (a.locked_until && new Date(a.locked_until).getTime() > Date.now()) throw errore('Account bloccato temporaneamente', 403);
    if (!(await verificaPassword(password, a.password_hash))) {
      const tentativi = (a.failed_attempts ?? 0) + 1;
      await senzaIsolamento(() => this.ds.query(
        `UPDATE ${tabella} SET failed_attempts = $2, locked_until = CASE WHEN $2 >= 5 THEN CURRENT_TIMESTAMP + interval '15 minutes' ELSE locked_until END WHERE id = $1`, [a.id, tentativi]));
      throw errore('Credenziali non valide', 401);
    }
    if (azione === 'login' && a.role === 'formatore') throw errore('Questo profilo non ha accesso alle API', 403);
    if (a.mfa_enabled) throw errore('Account con verifica in due passaggi: per le API usa un account di servizio senza MFA', 403);
    await senzaIsolamento(() => this.ds.query(`UPDATE ${tabella} SET last_login = CURRENT_TIMESTAMP, failed_attempts = 0, locked_until = NULL WHERE id = $1`, [a.id]));
    if (azione === 'login') {
      return { success: true, token: firmaToken({ user_id: a.id, user_type: 'user', role: a.role, username: a.username }), user: { id: a.id, username: a.username, name: a.name, role: a.role } };
    }
    return {
      success: true, token: firmaToken({ employee_id: a.id, user_type: 'employee', username: a.username, fiscal_code: a.fiscal_code }),
      employee: { id: a.id, username: a.username, fiscal_code: a.fiscal_code, first_name: a.first_name, last_name: a.last_name, email: a.email },
    };
  }

  private tokenHeader(req: RichiestaAutenticata): string | null {
    const h = String(req.headers.authorization ?? '');
    return /^Bearer\s+(\S+)$/i.exec(h)?.[1] ?? null;
  }

  private async richiedi(req: RichiestaAutenticata) {
    const t = this.tokenHeader(req);
    if (!t) throw errore('Token mancante', 401);
    const x = leggiToken(t);
    if (!x) throw errore('Token non valido o scaduto', 401);
    return x;
  }

  /** Presenze e assenze approvate dei dipendenti attivi dell'azienda del CRM. */
  @Get('attendance.php')
  async presenze(@Query() q: Record<string, string>, @Req() req: RichiestaAutenticata, @Res() res: Response) {
    res.set('Cache-Control', 'no-store, no-cache, must-revalidate');
    const x = await this.richiedi(req);
    if (x.user_type !== 'user' || !['admin', 'accountant'].includes(String(x.role ?? ''))) throw errore('Non autorizzato', 403);
    const slug = String(process.env.CRM_COMPANY_SLUG ?? '').trim() || 'connecteed';
    const [az] = await senzaIsolamento(() => this.ds.query(
      `SELECT id, name, slug, timbratura_enabled FROM companies WHERE is_active = true AND (slug = $1 OR name ILIKE $1) ORDER BY (slug = $1) DESC, id ASC LIMIT 1`, [slug]));
    if (!az) throw errore(`Azienda non trovata per slug "${slug}"`, 404);
    const [u] = await senzaIsolamento(() => this.ds.query('SELECT id, company_id, role FROM users WHERE id = $1 AND is_active = true', [Number(x.user_id) || 0]));
    if (!u) throw errore('Utente non valido', 401);
    const extra: number[] = (await senzaIsolamento(() => this.ds.query('SELECT company_id FROM user_companies WHERE user_id = $1', [u.id]))).map((r: any) => r.company_id);
    const globale = u.company_id === null && !extra.length;
    if (!globale && u.company_id !== az.id && !extra.includes(az.id)) throw errore('Non autorizzato su questa azienda', 403);
    const ruolo = u.role;

    const oggi = oggiRoma();
    const data = (s: unknown) => typeof s === 'string' && /^\d{4}-\d{2}-\d{2}$/.test(s) && new Date(`${s}T12:00:00Z`).toISOString().slice(0, 10) === s;
    const giorno = (d: string, n: number) => { const t = new Date(`${d}T12:00:00Z`); t.setUTCDate(t.getUTCDate() + n); return t.toISOString().slice(0, 10); };
    const range = String(q.range ?? '').trim().toLowerCase();
    let da: string;
    let a: string;
    if (range === 'week') {
      const dow = new Date(`${oggi}T12:00:00Z`).getUTCDay() || 7;
      da = giorno(oggi, 1 - dow);
      a = giorno(da, 6);
    } else if (range === 'today' || range === '') {
      da = data(q.from) ? q.from : oggi;
      a = data(q.to) ? q.to : da;
    } else throw errore('Parametro range non valido (ammessi: today, week)');
    if (da > a) [da, a] = [a, da];
    if ((Date.parse(a) - Date.parse(da)) / 86400000 > 92) throw errore('Intervallo troppo ampio (max 92 giorni)');
    const filtro = q.employee_id !== undefined ? Number.parseInt(q.employee_id, 10) || null : null;
    const giorni: string[] = [];
    for (let d = da; d <= a; d = giorno(d, 1)) giorni.push(d);

    const p: unknown[] = [az.id];
    if (filtro) p.push(filtro);
    const dipendenti = await senzaIsolamento(() => this.ds.query(
      `SELECT e.id, e.fiscal_code, e.email, e.first_name, e.last_name, d.name AS department_name FROM employees e LEFT JOIN departments d ON d.id = e.department_id
        WHERE e.company_id = $1 AND e.is_active = true${filtro ? ' AND e.id = $2' : ''} ORDER BY e.last_name, e.first_name, e.id`, p));
    const ORA = `to_char(p.punch_at AT TIME ZONE 'Europe/Rome', 'YYYY-MM-DD HH24:MI:SS')`;
    const timbrature = await senzaIsolamento(() => this.ds.query(
      `SELECT p.employee_id, p.kind, ${ORA} AS punch_at FROM attendance_punches p WHERE p.company_id = $1
          AND (p.punch_at AT TIME ZONE 'Europe/Rome')::date BETWEEN $2 AND $3${filtro ? ' AND p.employee_id = $4' : ''} ORDER BY p.punch_at, p.id`,
      filtro ? [az.id, da, a, filtro] : [az.id, da, a]));
    const perGiorno = new Map<string, Array<{ kind: string; at: string; time: string }>>();
    for (const t of timbrature) {
      const k = `${t.employee_id}|${t.punch_at.slice(0, 10)}`;
      if (!perGiorno.has(k)) perGiorno.set(k, []);
      perGiorno.get(k)!.push({ kind: t.kind, at: t.punch_at, time: t.punch_at.slice(11, 16) });
    }
    const assenze = await senzaIsolamento(() => this.ds.query(
      `SELECT lr.employee_id, lr.leave_type, lr.start_date, lr.end_date, lr.is_full_day, lr.start_time, lr.end_time FROM leave_requests lr JOIN employees e ON e.id = lr.employee_id
        WHERE e.company_id = $1 AND e.is_active = true AND lr.status = 'approved' AND lr.start_date <= $2 AND lr.end_date >= $3${filtro ? ' AND lr.employee_id = $4' : ''}
        ORDER BY lr.start_date ASC, lr.id ASC`, filtro ? [az.id, a, da, filtro] : [az.id, a, da]));
    const maschera = !['admin', 'accountant'].includes(ruolo);
    const assenzaGiorno = new Map<string, any>();
    for (const l of assenze) {
      const ini = String(l.start_date) > da ? String(l.start_date) : da;
      const fin = String(l.end_date) < a ? String(l.end_date) : a;
      for (let d = ini; d <= fin; d = giorno(d, 1)) if (!assenzaGiorno.has(`${l.employee_id}|${d}`)) assenzaGiorno.set(`${l.employee_id}|${d}`, l);
    }
    const adesso = Math.floor(Date.now() / 1000);
    const secondi = (s: string) => Math.floor(new Date(`${s.replace(' ', 'T')}${this.offsetRoma(s)}`).getTime() / 1000);
    const risultato = dipendenti.map((e: any) => {
      let totale = 0;
      let presenti = 0;
      let assenti = 0;
      const celle = giorni.map((g) => {
        const lista = perGiorno.get(`${e.id}|${g}`) ?? [];
        const l = assenzaGiorno.get(`${e.id}|${g}`) ?? null;
        let sec = 0;
        let aperta: number | null = null;
        let primo: string | null = null;
        let ultimo: string | null = null;
        for (const t of lista) {
          if (t.kind === 'in') { aperta = secondi(t.at); if (primo === null) primo = t.time; } else if (t.kind === 'out') { ultimo = t.time; if (aperta !== null) { sec += secondi(t.at) - aperta; aperta = null; } }
        }
        const dentro = aperta !== null && g === oggi;
        if (dentro && adesso > aperta!) sec += adesso - aperta!;
        sec = Math.max(0, sec);
        let tipo: string | null = l?.leave_type ?? null;
        let etichetta: string | null = null;
        if (tipo !== null) { if (maschera && tipo === 'permesso_104') tipo = 'permesso'; etichetta = TIPI[tipo] ?? tipo; }
        const stato = tipo !== null && tipo !== 'smart_working' ? 'absent' : lista.length || tipo === 'smart_working' ? 'present' : 'none';
        if (stato === 'present') presenti++;
        if (stato === 'absent') assenti++;
        totale += sec;
        return {
          date: g, status: stato, leave_type: tipo, leave_label: etichetta, leave_full_day: l ? !!l.is_full_day : null,
          leave_from: l?.start_time ? String(l.start_time).slice(0, 5) : null, leave_to: l?.end_time ? String(l.end_time).slice(0, 5) : null,
          first_in: primo, last_out: ultimo, open_now: dentro, worked_seconds: sec, worked_hours: Math.round((sec / 3600) * 100) / 100, punches: lista,
        };
      });
      return {
        id: e.id, fiscal_code: e.fiscal_code, email: e.email, full_name: `${e.first_name} ${e.last_name}`.trim(), first_name: e.first_name, last_name: e.last_name,
        department: e.department_name, today: celle.find((c) => c.date === oggi) ?? null,
        totals: { worked_seconds: totale, worked_hours: Math.round((totale / 3600) * 100) / 100, days_present: presenti, days_absent: assenti }, days: celle,
      };
    });
    res.json({
      success: true, company: { id: az.id, name: az.name, slug: az.slug, timbratura_enabled: !!az.timbratura_enabled },
      range: { from: da, to: a, today: oggi, days: giorni }, count: risultato.length, employees: risultato,
    });
  }

  /** "+01:00" o "+02:00": scostamento di Roma per quell'ora locale. */
  private offsetRoma(s: string): string {
    const d = new Date(`${s.replace(' ', 'T')}Z`);
    const parti = new Intl.DateTimeFormat('en-GB', { timeZone: 'Europe/Rome', timeZoneName: 'shortOffset' }).formatToParts(d);
    const o = parti.find((x) => x.type === 'timeZoneName')?.value ?? 'GMT+1';
    const m = /GMT([+-])(\d+)/.exec(o);
    return m ? `${m[1]}${m[2].padStart(2, '0')}:00` : '+01:00';
  }
}
