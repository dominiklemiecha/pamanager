import { Body, Controller, Get, HttpCode, HttpException, Post, Query, Req, Res } from '@nestjs/common';
import type { Response } from 'express';
import { DataSource } from 'typeorm';
import { senzaIsolamento } from '../../common/database/isolamento';
import { LimitiDbService } from '../../common/sicurezza/limiti-db.service';
import { Pubblico } from '../auth/decoratori';
import type { RichiestaAutenticata } from '../auth/auth.types';
import { SuperadminService } from './superadmin.service';

const COOKIE = 'cora_sa';
const RUMORE = ['login_success', 'login_failed', 'logout', 'session_expired', 'session_hijack_attempt', 'unauthorized_access', 'suspicious_activity', 'mfa_verify'];
const ETICHETTE: Record<string, string> = {
  employee_created: 'Nuovo dipendente', employee_updated: 'Salva dipendente', employee_deleted: 'Elimina dipendente', employee_assigned: 'Assegna dipendente',
  leave_request_approved: 'Approva ferie/permesso', leave_request_rejected: 'Rifiuta ferie/permesso', leave_request_cancelled: 'Annulla ferie/permesso',
  leave_request_deleted: 'Elimina ferie/permesso', leave_request_admin_update: 'Modifica ferie/permesso', leave_request_created: 'Inserisci ferie/permesso',
  leave_sick_docs_admin_updated: 'Documenti malattia', leave_sick_docs_updated: 'Documenti malattia', document_upload: 'Carica documento', document_uploaded: 'Carica documento',
  document_updated: 'Modifica documento', document_deleted: 'Elimina documento', document_download: 'Scarica documento',
  employee_document_uploaded: 'Carica documento dipendente', employee_document_updated: 'Modifica documento dipendente', employee_document_deleted: 'Elimina documento dipendente',
  employee_document_downloaded: 'Scarica documento dipendente', employee_document_bulk_uploaded: 'Carica documenti aziendali', employee_document_bulk_deleted: 'Elimina documenti aziendali',
  communication_created: 'Nuova comunicazione', communication_updated: 'Modifica comunicazione', communication_deleted: 'Elimina comunicazione',
  communication_toggled: 'Attiva/disattiva comunicazione', department_created: 'Nuovo reparto', department_updated: 'Modifica reparto', department_deleted: 'Elimina reparto',
  admin_assigned: 'Assegna responsabile reparto', admin_removed: 'Rimuovi responsabile reparto', user_created: 'Nuovo utente (consulente/commercialista)',
  user_updated: 'Modifica utente', user_deleted: 'Elimina utente', password_reset: 'Reimposta password', admin_password_reset: 'Reimposta password',
  password_reset_approved: 'Approva reset password', password_reset_rejected: 'Rifiuta reset password', password_change: 'Cambia la propria password',
  smtp_settings_updated: 'Salva impostazioni email', hire_request_deleted: 'Elimina richiesta assunzione', leave_sick_cert_waived: 'Esonera documenti malattia',
  leave_sick_cert_required: 'Richiedi documenti malattia', hr_granted_to_employee: 'Rendi HR', hr_revoked_from_employee: 'Revoca HR', user_left_company: 'Esci da azienda',
};
const etichetta = (a: string) => ETICHETTE[a] ?? (a.replace(/_/g, ' ').charAt(0).toUpperCase() + a.replace(/_/g, ' ').slice(1));
const nomePagina = (p: string) => { const b = String(p).replace(/\/+$/, '').split('/').pop()!.replace(/\.php$/, ''); return b === '' || b === 'index' || b === 'admin' ? 'dashboard' : b; };
const ORA = (c: string) => `to_char(${c} AT TIME ZONE 'Europe/Rome', 'YYYY-MM-DD HH24:MI:SS')`;

/**
 * Superadmin (public/superadmin/): accesso con password e codice TOTP,
 * gestione dei tenant e report sull'uso dell'utenza HR. Sessione propria
 * (cookie cora_sa), separata da quella degli utenti; controllo CSRF come le
 * altre rotte.
 */
@Controller('superadmin')
@Pubblico()
export class SuperadminController {
  constructor(private readonly sa: SuperadminService, private readonly ds: DataSource, private readonly limiti: LimitiDbService) {}

  private token(req: RichiestaAutenticata) { return req.cookies?.[COOKIE] as string | undefined; }
  private imposta(res: Response, t: string) { res.cookie(COOKIE, t, { httpOnly: true, sameSite: 'strict', secure: process.env.NODE_ENV === 'production', path: '/' }); }
  private richiedi(req: RichiestaAutenticata) {
    if (!this.sa.configurato()) throw new HttpException({ statusCode: 503, message: 'Superadmin non configurato.' }, 503);
    if (this.sa.fase(this.token(req)) !== 'completa') throw new HttpException({ statusCode: 401, message: 'Accesso richiesto', fase: this.sa.fase(this.token(req)) ?? 'nessuna' }, 401);
  }

  @Get('stato')
  stato(@Req() req: RichiestaAutenticata) {
    return { configurato: this.sa.configurato(), fase: this.sa.fase(this.token(req)) ?? 'nessuna' };
  }

  @Post('login')
  @HttpCode(200)
  async login(@Body() b: { username?: string; password?: string }, @Req() req: RichiestaAutenticata, @Res({ passthrough: true }) res: Response) {
    if (!this.sa.configurato()) throw new HttpException({ statusCode: 503, message: 'Superadmin non configurato.' }, 503);
    // Contano solo i tentativi falliti: 10 ogni 15 minuti per indirizzo
    if (await this.limiti.superato(String(req.ip ?? ''), 'superadmin_login', 10, 900)) return { ok: false, error: 'Troppi tentativi: riprova tra qualche minuto' };
    if (await this.sa.controllaPassword(String(b?.username ?? ''), String(b?.password ?? ''))) {
      this.imposta(res, this.sa.apri('password', this.token(req)));
      return { ok: true, fase: 'password' };
    }
    await this.limiti.consenti(String(req.ip ?? ''), 'superadmin_login', 10, 900);
    await new Promise((r) => setTimeout(r, 800));
    return { ok: false, error: 'Credenziali non valide' };
  }

  @Post('totp')
  @HttpCode(200)
  async totp(@Body() b: { code?: string }, @Req() req: RichiestaAutenticata, @Res({ passthrough: true }) res: Response) {
    if (this.sa.fase(this.token(req)) !== 'password') return { ok: false, fase: this.sa.fase(this.token(req)) ?? 'nessuna' };
    if (await this.limiti.superato(String(req.ip ?? ''), 'superadmin_totp', 10, 900)) return { ok: false, error: 'Troppi tentativi: riprova tra qualche minuto' };
    if (this.sa.controllaCodice(String(b?.code ?? ''))) {
      this.imposta(res, this.sa.apri('completa', this.token(req)));
      return { ok: true, fase: 'completa' };
    }
    await this.limiti.consenti(String(req.ip ?? ''), 'superadmin_totp', 10, 900);
    await new Promise((r) => setTimeout(r, 800));
    return { ok: false, error: 'Codice non valido o scaduto' };
  }

  @Post('esci')
  @HttpCode(200)
  esci(@Req() req: RichiestaAutenticata, @Res({ passthrough: true }) res: Response) {
    this.sa.chiudi(this.token(req));
    res.clearCookie(COOKIE, { path: '/' });
    return { ok: true };
  }

  @Get('tenant')
  async tenant(@Req() req: RichiestaAutenticata) {
    this.richiedi(req);
    return { V: { message: '', error: '', tenants: await this.sa.elencoAdmin(), allCompanies: await this.sa.tutteLeAziende() } };
  }

  @Post('tenant')
  @HttpCode(200)
  async azione(@Body() b: Record<string, any>, @Req() req: RichiestaAutenticata) {
    this.richiedi(req);
    const ip = req.ip ?? null;
    const ua = String(req.headers['user-agent'] ?? '');
    const uid = Number.parseInt(String(b.user_id ?? ''), 10) || 0;
    let message = '';
    let error = '';
    const esito = (r: { success: boolean; error?: string }, ok: string) => { if (r.success) message = ok; else error = r.error ?? 'Errore'; };
    switch (String(b.action ?? '')) {
      case 'create': {
        const r = await this.sa.creaTenant(b, ip, ua);
        if (r.success) message = r.email_sent ? 'Tenant creato. Email di benvenuto inviata.' : 'Tenant creato. <em>Email NON inviata: SMTP non configurato o non raggiungibile</em> — comunica manualmente il link primo accesso.';
        else error = r.error;
        break;
      }
      case 'deactivate': esito(await this.sa.stato(uid, false), 'Tenant disattivato'); break;
      case 'activate': esito(await this.sa.stato(uid, true), 'Tenant riattivato'); break;
      case 'delete':
        if (String(b.confirm ?? '') !== 'ELIMINA') error = 'Conferma mancante o errata';
        else esito(await this.sa.elimina(uid), 'Tenant eliminato definitivamente');
        break;
      case 'resend_welcome': esito(await this.sa.reinviaBenvenuto(uid, ip, ua), 'Email primo accesso reinviata'); break;
      case 'set_companies': {
        const ids = b['company_ids[]'] ?? b.company_ids ?? [];
        esito(await this.sa.assegnaAziende(uid, Array.isArray(ids) ? ids : [ids]), 'Aziende assegnate aggiornate');
        break;
      }
    }
    return { V: { message, error, tenants: await this.sa.elencoAdmin(), allCompanies: await this.sa.tutteLeAziende() } };
  }

  /** Filtri del report (periodo, azienda, utente) come condizioni SQL. */
  private filtri(q: Record<string, string>) {
    let giorni = Number.parseInt(String(q.days ?? '30'), 10);
    if (![0, 1, 7, 30, 90, 365].includes(giorni)) giorni = 30;
    const azienda = Number.parseInt(String(q.company ?? ''), 10) || 0;
    const utente = Number.parseInt(String(q.user ?? ''), 10) || 0;
    const p: unknown[] = [];
    const w = ['1=1'];
    if (giorni > 0) { p.push(giorni); w.push(`l.created_at >= CURRENT_TIMESTAMP - make_interval(days => $${p.length})`); }
    if (azienda > 0) { p.push(azienda); w.push(`l.company_id = $${p.length}`); }
    if (utente > 0) { p.push(utente); w.push(`l.user_id = $${p.length}`); }
    const ap: unknown[] = [RUMORE];
    const aw = [`a.user_type = 'admin'`, 'NOT (a.action = ANY($1::text[]))'];
    if (giorni > 0) { ap.push(giorni); aw.push(`a.created_at >= CURRENT_TIMESTAMP - make_interval(days => $${ap.length})`); }
    if (azienda > 0) { ap.push(azienda); aw.push(`u.company_id = $${ap.length}`); }
    if (utente > 0) { ap.push(utente); aw.push(`a.user_id = $${ap.length}`); }
    return { giorni, azienda, utente, w: w.join(' AND '), p, aw: aw.join(' AND '), ap };
  }

  @Get('clic')
  async clic(@Query() q: Record<string, string>, @Req() req: RichiestaAutenticata) {
    this.richiedi(req);
    const f = this.filtri(q);
    const x = (sql: string, p: unknown[]) => senzaIsolamento(() => this.ds.query(sql, p));
    const [totals] = await x(`SELECT COUNT(*)::int AS clicks, COUNT(DISTINCT l.user_id)::int AS users, COUNT(DISTINCT l.page)::int AS pages FROM ui_click_logs l WHERE ${f.w}`, f.p);
    const topButtons = await x(`SELECT l.label, l.area, l.page, l.kind, COUNT(*)::int AS n, COUNT(DISTINCT l.user_id)::int AS users, ${ORA('MAX(l.created_at)')} AS last_at
      FROM ui_click_logs l WHERE ${f.w} GROUP BY l.label, l.area, l.page, l.kind ORDER BY n DESC, l.label LIMIT 50`, f.p);
    const topPages = await x(`SELECT l.page, COUNT(*)::int AS n, COUNT(DISTINCT l.user_id)::int AS users FROM ui_click_logs l WHERE ${f.w} GROUP BY l.page ORDER BY n DESC, l.page LIMIT 20`, f.p);
    const perUser = await x(`SELECT l.user_id, u.name AS user_name, u.username, c.name AS company, COUNT(*)::int AS n, ${ORA('MAX(l.created_at)')} AS last_at
      FROM ui_click_logs l LEFT JOIN users u ON u.id = l.user_id LEFT JOIN companies c ON c.id = l.company_id WHERE ${f.w}
      GROUP BY l.user_id, u.name, u.username, c.name ORDER BY n DESC LIMIT 50`, f.p);
    const recent = await x(`SELECT ${ORA('l.created_at')} AS created_at, l.page, l.area, l.label, l.target, u.name AS user_name, u.username, c.name AS company
      FROM ui_click_logs l LEFT JOIN users u ON u.id = l.user_id LEFT JOIN companies c ON c.id = l.company_id WHERE ${f.w} ORDER BY l.created_at DESC, l.id DESC LIMIT 100`, f.p);
    const auditTop = await x(`SELECT a.action, COUNT(*)::int AS n, COUNT(DISTINCT a.user_id)::int AS users, ${ORA('MAX(a.created_at)')} AS last_at
      FROM audit_log a LEFT JOIN users u ON u.id = a.user_id WHERE ${f.aw} GROUP BY a.action ORDER BY n DESC LIMIT 80`, f.ap);
    const pulsanti = new Map<string, { label: string; n: number; users: number; last_at: string }>();
    for (const r of auditTop) {
      const k = etichetta(r.action);
      const v = pulsanti.get(k) ?? { label: k, n: 0, users: 0, last_at: r.last_at };
      v.n += r.n;
      v.users = Math.max(v.users, r.users);
      if (r.last_at > v.last_at) v.last_at = r.last_at;
      pulsanti.set(k, v);
    }
    const auditButtons = [...pulsanti.values()].sort((a, b) => b.n - a.n);
    const [since] = await x(`SELECT ${ORA('MIN(created_at)')} AS m FROM audit_log WHERE user_type = 'admin'`, []);
    const auditPerUser = await x(`SELECT a.user_id, u.name AS user_name, u.username, c.name AS company, COUNT(*)::int AS n, ${ORA('MAX(a.created_at)')} AS last_at
      FROM audit_log a LEFT JOIN users u ON u.id = a.user_id LEFT JOIN companies c ON c.id = u.company_id WHERE ${f.aw}
      GROUP BY a.user_id, u.name, u.username, c.name ORDER BY n DESC LIMIT 50`, f.ap);
    return {
      V: {
        _GET: q, days: f.giorni, companyId: f.azienda, userId: f.utente, totals, topButtons, topPages, perUser, recent, auditButtons,
        auditMax: Math.max(1, auditButtons[0]?.n ?? 1), auditTotal: auditButtons.reduce((s, b) => s + b.n, 0), auditSince: since?.m ?? null, auditPerUser,
        companies: await x('SELECT id, name FROM companies ORDER BY name, id', []), hrUsers: await x(`SELECT id, name, username FROM users WHERE role = 'admin' ORDER BY name, id`, []),
        maxN: Math.max(1, topButtons[0]?.n ?? 1),
      },
    };
  }

  /** Export CSV del report (?export=csv clic, ?export=storico azioni registrate). */
  @Get('clic/export')
  async esporta(@Query() q: Record<string, string>, @Req() req: RichiestaAutenticata, @Res() res: Response) {
    this.richiedi(req);
    const f = this.filtri(q);
    const cella = (v: unknown) => { const s = String(v ?? ''); return /[;"\n\r\t ]/.test(s) ? `"${s.replace(/"/g, '""')}"` : s; };
    const oggi = new Intl.DateTimeFormat('sv-SE', { timeZone: 'Europe/Rome' }).format(new Date()).replace(/-/g, '');
    let righe: unknown[][];
    let nome: string;
    if (q.export === 'storico') {
      nome = `azioni-hr-${oggi}.csv`;
      const r = await senzaIsolamento(() => this.ds.query(`SELECT ${ORA('a.created_at')} AS created_at, c.name AS company, u.name AS user_name, u.username, a.action, a.entity_type, a.entity_id
        FROM audit_log a LEFT JOIN users u ON u.id = a.user_id LEFT JOIN companies c ON c.id = u.company_id WHERE ${f.aw} ORDER BY a.created_at DESC LIMIT 50000`, f.ap));
      righe = [['Data', 'Azienda', 'Utente', 'Username', 'Pulsante', 'Azione registrata', 'Oggetto', 'ID oggetto'],
        ...r.map((x: any) => [x.created_at, x.company, x.user_name, x.username, etichetta(x.action), x.action, x.entity_type, x.entity_id])];
    } else {
      nome = `clic-hr-${oggi}.csv`;
      const r = await senzaIsolamento(() => this.ds.query(`SELECT ${ORA('l.created_at')} AS created_at, c.name AS company, u.name AS user_name, u.username, l.page, l.area, l.kind, l.label, l.target
        FROM ui_click_logs l LEFT JOIN users u ON u.id = l.user_id LEFT JOIN companies c ON c.id = l.company_id WHERE ${f.w} ORDER BY l.created_at DESC LIMIT 50000`, f.p));
      righe = [['Data', 'Azienda', 'Utente', 'Username', 'Pagina', 'Zona', 'Tipo', 'Pulsante', 'Destinazione'],
        ...r.map((x: any) => [x.created_at, x.company, x.user_name, x.username, nomePagina(x.page), x.area, x.kind, x.label, x.target])];
    }
    res.set({ 'Content-Type': 'text/csv; charset=utf-8', 'Content-Disposition': `attachment; filename="${nome}"`, 'Cache-Control': 'no-store' })
      .send(Buffer.concat([Buffer.from([0xef, 0xbb, 0xbf]), Buffer.from(righe.map((x) => x.map(cella).join(';')).join('\n') + '\n')]));
  }
}
