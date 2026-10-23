import { DataSource } from 'typeorm';
import { Body, Controller, Get, HttpCode, HttpException, Post, Req, Res } from '@nestjs/common';
import { IsInt, IsOptional, IsString, MaxLength } from 'class-validator';
import { randomBytes } from 'crypto';
import type { CookieOptions, Response } from 'express';
import { AuditService } from '../audit/audit.service';
import { AuthService } from './auth.service';
import { COOKIE_CSRF, COOKIE_SESSIONE, DURATA_MASSIMA, RichiestaAutenticata, UtenteCorrente } from './auth.types';
import { DuranteCambioPassword, DuranteMfa, Pubblico, Ruoli, Utente } from './decoratori';
import { PasswordService } from './password.service';
import { SessioniService } from './sessioni.service';
import { TenantService } from './tenant.service';

class LoginDto {
  @IsString() @MaxLength(255) username!: string;
  @IsString() @MaxLength(1024) password!: string;
  @IsOptional() @IsString() @MaxLength(500) ritorno?: string;
}
class MfaDto {
  @IsString() @MaxLength(32) codice!: string;
}
class AziendaDto {
  @IsInt() azienda!: number;
}
class CambioPasswordDto {
  @IsString() @MaxLength(1024) attuale!: string;
  @IsString() @MaxLength(1024) nuova!: string;
  @IsString() @MaxLength(1024) conferma!: string;
}
class RecuperoDto {
  @IsString() @MaxLength(255) identificativo!: string;
}
class TokenDto {
  @IsString() @MaxLength(128) token!: string;
}
class ResetDto {
  @IsString() @MaxLength(128) token!: string;
  @IsString() @MaxLength(1024) password!: string;
  @IsString() @MaxLength(1024) conferma!: string;
}

/** Pagina iniziale per ruolo, come login_redirect_for() del PHP. */
const CASA: Record<string, string> = {
  employee: '/employee/',
  admin: '/admin/',
  admin_reparto: '/admin-reparto/',
  consulente_lavoro: '/consulente-lavoro/',
  accountant: '/accountant/',
  formatore: '/formatore/academy.php',
};

/**
 * Indirizzo di ritorno dopo il login: solo percorsi interni. Il PHP accettava
 * anche "//sito-esterno" (redirect aperto verso un altro dominio): qui no.
 */
function destinazione(ruolo: string, ritorno?: string): string {
  if (ritorno && /^\/(?![/\\])[a-zA-Z0-9/_\-.?=&%]*$/.test(ritorno)) return ritorno;
  return CASA[ruolo] ?? '/';
}

@Controller('auth')
export class AuthController {
  constructor(
    private readonly auth: AuthService,
    private readonly sessioni: SessioniService,
    private readonly tenant: TenantService,
    private readonly audit: AuditService,
    private readonly password: PasswordService,
    private readonly ds: DataSource,
  ) {}

  private opzioni(req: RichiestaAutenticata, httpOnly: boolean): CookieOptions {
    // Lax e non Strict per la sessione: le PWA su iOS riaperte dalla schermata
    // Home trattano la prima richiesta come esterna e perderebbero il cookie.
    return { httpOnly, secure: req.secure, sameSite: httpOnly ? 'lax' : 'strict', path: '/' };
  }

  /** Token CSRF nel cookie leggibile dal frontend: uno nuovo a ogni login. */
  private nuovoCsrf(req: RichiestaAutenticata, res: Response): void {
    res.cookie(COOKIE_CSRF, randomBytes(24).toString('base64url'), this.opzioni(req, false));
  }

  private apriSessione(req: RichiestaAutenticata, res: Response, token: string): void {
    res.cookie(COOKIE_SESSIONE, token, { ...this.opzioni(req, true), maxAge: DURATA_MASSIMA * 1000 });
    this.nuovoCsrf(req, res);
  }

  /** Stato della sessione per il frontend. Prepara anche il cookie CSRF se manca. */
  @Get('sessione')
  @Pubblico()
  @DuranteMfa()
  @DuranteCambioPassword()
  async sessione(@Req() req: RichiestaAutenticata, @Res({ passthrough: true }) res: Response, @Utente() u?: UtenteCorrente) {
    if (!req.cookies?.[COOKIE_CSRF]) this.nuovoCsrf(req, res);
    if (!u) return { autenticato: false };
    // Sessione MFA in attesa: si sa solo che serve il codice.
    if (u.mfaInAttesa) return { autenticato: false, mfa: true };
    return {
      autenticato: true,
      deveCambiarePassword: u.deveCambiarePassword,
      utente: { tipo: u.tipo, id: u.id, ruolo: u.ruolo, nome: u.nome, username: u.username, email: u.email, azienda: u.companyId, reparto: u.departmentId },
      destinazione: destinazione(u.ruolo),
    };
  }

  @Post('login')
  @Pubblico()
  @HttpCode(200)
  async login(@Body() dto: LoginDto, @Req() req: RichiestaAutenticata, @Res({ passthrough: true }) res: Response) {
    const username = dto.username.trim();
    if (!username || !dto.password) throw new HttpException({ statusCode: 400, message: 'Inserisci le credenziali di accesso' }, 400);
    // Chi era gia' dentro e rifa' login: la sessione precedente si chiude.
    if (req.utente) await this.sessioni.chiudi(req.utente.sessioneId, 'nuovo_login');
    const esito = await this.auth.login(username, dto.password, { ip: req.ip ?? '', userAgent: String(req.headers['user-agent'] ?? '') });
    if (!esito.ok) throw new HttpException({ statusCode: 401, message: esito.errore }, 401);
    this.apriSessione(req, res, esito.token);
    if (esito.mfa) return { mfa: true };
    return { destinazione: destinazione(esito.ruolo, dto.ritorno) };
  }

  @Post('mfa')
  @DuranteMfa()
  @HttpCode(200)
  async mfa(@Body() dto: MfaDto, @Req() req: RichiestaAutenticata, @Res({ passthrough: true }) res: Response, @Utente() u: UtenteCorrente) {
    const ctx = { ip: req.ip ?? '', userAgent: String(req.headers['user-agent'] ?? ''), sessioneId: u.sessioneId };
    // Come il PHP: al massimo 5 codici sbagliati in 5 minuti per account.
    if ((await this.audit.conta('mfa_failed', 'admin', u.id, 5)) >= 5) {
      await this.audit.log('rate_limit_exceeded', 'admin', u.id, ctx, 'users', u.id, null, { action: 'mfa_verify' }, 'warning');
      throw new HttpException({ statusCode: 429, message: 'Troppi tentativi. Riprova tra 5 minuti.' }, 429);
    }
    const esito = await this.auth.verificaMfa(u.sessioneId, u.id, dto.codice, ctx);
    if (!esito.ok) throw new HttpException({ statusCode: 401, message: esito.errore }, 401);
    this.apriSessione(req, res, esito.token);
    return { destinazione: destinazione(esito.ruolo) };
  }

  @Post('logout')
  @Pubblico()
  @DuranteMfa()
  @DuranteCambioPassword()
  @HttpCode(200)
  async logout(@Req() req: RichiestaAutenticata, @Res({ passthrough: true }) res: Response, @Utente() u?: UtenteCorrente) {
    if (u) {
      await this.audit.log('logout', u.ruolo, u.id, { ip: req.ip, userAgent: String(req.headers['user-agent'] ?? ''), sessioneId: u.sessioneId });
      await this.sessioni.chiudi(u.sessioneId, 'logout');
    }
    res.clearCookie(COOKIE_SESSIONE, { path: '/' });
    this.nuovoCsrf(req, res);
    return { ok: true };
  }

  /** Aziende tra cui lo staff puo' scegliere (selettore in alto). */
  @Get('aziende')
  @Ruoli('admin', 'accountant', 'consulente_lavoro', 'admin_reparto', 'formatore')
  async aziende(@Utente() u: UtenteCorrente) {
    const acc = { id: u.id, role: u.ruolo, companyId: u.companyIdAccount };
    return { corrente: u.companyId, puoCambiare: await this.tenant.puoCambiare(acc), accessibili: await this.tenant.accessibili(acc) };
  }

  @Post('azienda')
  @Ruoli('admin', 'accountant', 'consulente_lavoro')
  @HttpCode(200)
  async cambiaAzienda(@Body() dto: AziendaDto, @Utente() u: UtenteCorrente) {
    if (!(await this.tenant.verificaCambio({ id: u.id, role: u.ruolo, companyId: u.companyIdAccount }, dto.azienda))) {
      throw new HttpException({ statusCode: 403, message: 'Azienda non accessibile' }, 403);
    }
    await this.sessioni.scegliAzienda(u.sessioneId, dto.azienda);
    return { ok: true, azienda: dto.azienda };
  }

  /** Cambio della propria password (anche quello obbligatorio dopo un reset dell'HR). */
  @Post('password')
  @DuranteCambioPassword()
  @HttpCode(200)
  async cambiaPassword(@Body() dto: CambioPasswordDto, @Req() req: RichiestaAutenticata, @Utente() u: UtenteCorrente) {
    const esito = await this.password.cambia(u.tipo, u.id, u.ruolo, dto.attuale, dto.nuova, dto.conferma,
      { ip: req.ip, userAgent: String(req.headers['user-agent'] ?? ''), sessioneId: u.sessioneId });
    if (!esito.ok) throw new HttpException({ statusCode: 400, message: esito.errore ?? esito.errori?.join(' '), errori: esito.errori }, 400);
    // Contratto da firmare in attesa: si va subito li' (change-password.php).
    if (u.tipo === 'employee') {
      const [c] = await this.ds.query(`SELECT id FROM hire_requests WHERE employee_id = $1 AND status = 'contract_pending' ORDER BY id DESC LIMIT 1`, [u.id]);
      if (c) return { ok: true, destinazione: `/employee/contract-sign.php?id=${c.id}` };
    }
    return { ok: true };
  }

  /** Recupero password: risposta identica che l'account esista o no. */
  @Post('recupero')
  @Pubblico()
  @HttpCode(200)
  async recupero(@Body() dto: RecuperoDto, @Req() req: RichiestaAutenticata) {
    const id = dto.identificativo.trim();
    if (!id) throw new HttpException({ statusCode: 400, message: 'Inserisci username o email' }, 400);
    await this.password.richiedi(id, { ip: req.ip, userAgent: String(req.headers['user-agent'] ?? '') });
    return { message: "Se l'account esiste, riceverai a breve un'email con il link per reimpostare la password." };
  }

  @Post('reset/verifica')
  @Pubblico()
  @HttpCode(200)
  async verificaReset(@Body() dto: TokenDto) {
    const t = await this.password.verificaToken(dto.token);
    return t ? { valido: true, nome: t.nome, username: t.username } : { valido: false };
  }

  @Post('reset')
  @Pubblico()
  @HttpCode(200)
  async reset(@Body() dto: ResetDto, @Req() req: RichiestaAutenticata) {
    const esito = await this.password.completa(dto.token, dto.password, dto.conferma, { ip: req.ip, userAgent: String(req.headers['user-agent'] ?? '') });
    if (!esito.ok) throw new HttpException({ statusCode: 400, message: esito.errore ?? esito.errori?.join(', '), errori: esito.errori }, 400);
    return { ok: true, message: 'Password aggiornata con successo' };
  }
}
