import { CanActivate, ExecutionContext, ForbiddenException, HttpException, Injectable, UnauthorizedException } from '@nestjs/common';
import { Reflector } from '@nestjs/core';
import type { Response } from 'express';
import { timingSafeEqual } from 'crypto';
import { DataSource } from 'typeorm';
import { impostaAziendaRichiesta, senzaIsolamento } from '../../common/database/isolamento';
import { AuditService } from '../audit/audit.service';
import { COOKIE_CSRF, COOKIE_SESSIONE, RichiestaAutenticata, Ruolo } from './auth.types';
import { CHIAVE_CAMBIO_PASSWORD, CHIAVE_MFA_IN_CORSO, CHIAVE_PUBBLICO, CHIAVE_RUOLI, CHIAVE_SENZA_CSRF } from './decoratori';
import { SessioniService } from './sessioni.service';
import { TenantService } from './tenant.service';

const METODI_SICURI = ['GET', 'HEAD', 'OPTIONS'];

/**
 * Controllo di ogni richiesta (globale): sessione, scadenze, dispositivo,
 * CSRF, ruoli e azienda di lavoro. Equivale a Auth::init + requireUser /
 * requireEmployee + CSRF::verifyOrDie del PHP.
 */
@Injectable()
export class SessioneGuard implements CanActivate {
  constructor(
    private readonly reflector: Reflector,
    private readonly ds: DataSource,
    private readonly sessioni: SessioniService,
    private readonly tenant: TenantService,
    private readonly audit: AuditService,
  ) {}

  async canActivate(ctx: ExecutionContext): Promise<boolean> {
    const req = ctx.switchToHttp().getRequest<RichiestaAutenticata>();
    const res = ctx.switchToHttp().getResponse<Response>();
    const meta = <T>(k: string) => this.reflector.getAllAndOverride<T>(k, [ctx.getHandler(), ctx.getClass()]);
    const pubblico = meta<boolean>(CHIAVE_PUBBLICO);
    const userAgent = String(req.headers['user-agent'] ?? '');
    const contesto = { ip: req.ip, userAgent };

    // CSRF a doppio invio: il cookie leggibile dal frontend deve coincidere con
    // l'header. Vale anche per il login (niente login forzato da altri siti).
    if (!METODI_SICURI.includes(req.method) && !meta<boolean>(CHIAVE_SENZA_CSRF)) {
      const cookie = String(req.cookies?.[COOKIE_CSRF] ?? '');
      const header = String(req.headers['x-csrf-token'] ?? '');
      if (!cookie || cookie.length !== header.length || !timingSafeEqual(Buffer.from(cookie), Buffer.from(header))) {
        throw new HttpException({ statusCode: 403, message: 'Token di sicurezza non valido. Ricarica la pagina.' }, 403);
      }
    }

    const esito = await this.sessioni.verifica(req.cookies?.[COOKIE_SESSIONE], userAgent);
    if (esito.stato === 'scaduta') {
      await this.sessioni.chiudi(esito.sessione.id, 'scaduta');
      await this.audit.log('session_expired', esito.sessione.role, esito.sessione.subject_id, { ...contesto, sessioneId: esito.sessione.id },
        null, null, null, { timeout: esito.timeout, elapsed: esito.trascorsi });
      res.clearCookie(COOKIE_SESSIONE, { path: '/' });
    } else if (esito.stato === 'dispositivo-cambiato') {
      // Stesso cookie da un altro browser: possibile furto di sessione.
      await this.sessioni.chiudi(esito.sessione.id, 'dispositivo_cambiato');
      await this.audit.log('session_hijack_attempt', esito.sessione.role, esito.sessione.subject_id, { ...contesto, sessioneId: esito.sessione.id },
        null, null, null, { original_ua: esito.sessione.user_agent, current_ua: userAgent, current_ip: req.ip }, 'critical');
      res.clearCookie(COOKIE_SESSIONE, { path: '/' });
    }

    if (esito.stato !== 'valida') {
      if (pubblico) return true;
      throw new UnauthorizedException({ statusCode: 401, message: 'Sessione scaduta', session_expired: true });
    }

    const s = esito.sessione;
    if (s.mfa_pending && !meta<boolean>(CHIAVE_MFA_IN_CORSO)) {
      if (pubblico) return true;
      throw new UnauthorizedException({ statusCode: 401, message: 'Verifica del codice richiesta', mfa_required: true });
    }

    // Account ancora attivo e dati aggiornati a ogni richiesta (un dipendente
    // disattivato o un utente rimosso non resta dentro fino alla scadenza).
    const tabella = s.subject_type === 'user' ? 'users' : 'employees';
    const [a] = await senzaIsolamento(() => this.ds.query(`SELECT * FROM ${tabella} WHERE id = $1 AND is_active = true`, [s.subject_id]));
    if (!a || (s.subject_type === 'user' && a.role !== s.role)) {
      await this.sessioni.chiudi(s.id, 'account_non_attivo');
      res.clearCookie(COOKIE_SESSIONE, { path: '/' });
      if (pubblico) return true;
      throw new UnauthorizedException({ statusCode: 401, message: 'Sessione scaduta', session_expired: true });
    }

    let companyId: number;
    if (s.subject_type === 'employee') {
      companyId = a.company_id;
    } else {
      companyId = s.mfa_pending ? 0 : await this.tenant.corrente({ id: a.id, role: a.role, companyId: a.company_id }, s.selected_company_id);
      if (companyId && companyId !== s.selected_company_id) await this.sessioni.scegliAzienda(s.id, companyId);
    }

    req.utente = {
      sessioneId: s.id,
      sessioneCreata: Math.floor(new Date(s.created_at).getTime() / 1000),
      tipo: s.subject_type,
      id: a.id,
      ruolo: (s.subject_type === 'user' ? a.role : 'employee') as Ruolo,
      companyId,
      companyIdAccount: a.company_id ?? null,
      departmentId: a.department_id ?? null,
      username: a.username,
      nome: s.subject_type === 'user' ? a.name : `${a.first_name} ${a.last_name}`,
      email: a.email ?? null,
      mfaInAttesa: s.mfa_pending,
      deveCambiarePassword: !!a.must_change_password,
    };
    await this.sessioni.tocca(s.id);
    if (companyId) impostaAziendaRichiesta(companyId);

    // Password temporanea: il PHP la imponeva solo con un redirect nelle pagine del
    // dipendente (le azioni POST passavano lo stesso, lo staff mai). Qui vale
    // per tutti e su ogni rotta tranne cambio password, sessione e logout.
    if (req.utente.deveCambiarePassword && !meta<boolean>(CHIAVE_CAMBIO_PASSWORD)) {
      throw new ForbiddenException({ statusCode: 403, message: 'Devi cambiare la password prima di continuare', must_change_password: true });
    }

    const ruoli = meta<Ruolo[]>(CHIAVE_RUOLI);
    if (ruoli?.length && !ruoli.includes(req.utente.ruolo)) {
      await this.audit.log('unauthorized_access', req.utente.ruolo, req.utente.id, { ...contesto, sessioneId: s.id }, null, null, null,
        { resource: req.originalUrl, required_role: ruoli.length === 1 ? ruoli[0] : ruoli, actual_role: req.utente.ruolo }, 'critical');
      throw new ForbiddenException({ statusCode: 403, message: 'Accesso non autorizzato' });
    }
    return true;
  }
}
