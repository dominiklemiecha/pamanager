import { Body, Controller, Get, HttpCode, HttpException, Param, ParseIntPipe, Post, Query, Req } from '@nestjs/common';
import { Ruoli, Utente } from '../auth/decoratori';
import type { RichiestaAutenticata, UtenteCorrente } from '../auth/auth.types';
import { ChatService } from '../chat/chat.service';
import { FerieService } from '../ferie/ferie.service';
import { HrService } from './hr.service';

/** Vista Cora dell'HR: cornice, dashboard e azioni rapide della dashboard. */
@Controller('hr')
@Ruoli('admin')
export class HrController {
  constructor(private readonly hr: HrService, private readonly ferie: FerieService, private readonly chat: ChatService) {}

  private ctx(u: UtenteCorrente, req: RichiestaAutenticata) {
    return { ip: req.ip ?? null, userAgent: String(req.headers['user-agent'] ?? ''), sessioneId: u.sessioneId, tipo: u.ruolo, id: u.id };
  }

  @Get('cornice')
  cornice(@Utente() u: UtenteCorrente) {
    return this.hr.cornice(u);
  }

  @Get('home')
  home(@Query('w') w: string, @Utente() u: UtenteCorrente) {
    return this.hr.home(u, String(w ?? ''));
  }

  @Get('settimana')
  settimana(@Query('w') w: string, @Utente() u: UtenteCorrente) {
    return this.hr.settimana(u.companyId!, String(w ?? ''));
  }

  @Get('dipendenti')
  dipendenti(@Utente() u: UtenteCorrente) {
    return this.hr.dipendenti(u.companyId!);
  }

  @Get('assunzioni')
  assunzioni(@Utente() u: UtenteCorrente) {
    return this.hr.assunzioni(u.companyId!);
  }

  @Get('presenze')
  presenze(@Query('d') d: string, @Utente() u: UtenteCorrente) {
    return this.hr.presenze(u.companyId!, String(d ?? ''));
  }

  @Get('reparti')
  reparti(@Utente() u: UtenteCorrente) {
    return this.hr.reparti(u.companyId!);
  }

  @Get('ferie')
  elencoFerie(@Utente() u: UtenteCorrente) {
    return this.hr.ferie(u.companyId!);
  }

  @Get('comunicazioni')
  comunicazioni(@Utente() u: UtenteCorrente) {
    return this.hr.comunicazioni(u.companyId!);
  }

  @Get('documenti-aziendali')
  documentiAziendali(@Utente() u: UtenteCorrente) {
    return this.hr.documentiAziendali(u.companyId!);
  }

  @Get('accessi')
  accessi(@Query('ruolo') ruolo: string, @Utente() u: UtenteCorrente) {
    return this.hr.accessi(u, ruolo === 'formatore' ? 'formatore' : 'consulente_lavoro');
  }

  @Get('buoni-pasto')
  buoniPasto(@Utente() u: UtenteCorrente) {
    return this.hr.anteprimaBuoni(u.companyId!);
  }

  @Get('richieste-reset')
  richiesteReset(@Utente() u: UtenteCorrente) {
    return this.hr.richiesteReset(u.companyId!);
  }

  /** Approvazione dalla dashboard (quick_approve di admin/index.php). */
  @Post('richieste/:id/approva')
  @HttpCode(200)
  async approva(@Param('id', ParseIntPipe) id: number, @Req() req: RichiestaAutenticata, @Utente() u: UtenteCorrente) {
    const r = await this.ferie.approva(id, u.companyId!, this.ctx(u, req));
    if (!r.success) throw new HttpException({ statusCode: 400, message: r.error }, 400);
    return { ok: true };
  }

  /** Rifiuto dalla dashboard (quick_reject di admin/index.php), motivo facoltativo. */
  @Post('richieste/:id/rifiuta')
  @HttpCode(200)
  async rifiuta(@Param('id', ParseIntPipe) id: number, @Body() b: { motivo?: string }, @Req() req: RichiestaAutenticata, @Utente() u: UtenteCorrente) {
    const r = await this.ferie.rifiuta(id, u.companyId!, String(b?.motivo ?? '').trim(), this.ctx(u, req));
    if (!r.success) throw new HttpException({ statusCode: 400, message: r.error }, 400);
    return { ok: true };
  }

  /** "Messaggio" sulla card di un collega: apre (o crea) la chat diretta con il dipendente. */
  @Post('chat/:dipendente')
  @HttpCode(200)
  async chatCon(@Param('dipendente', ParseIntPipe) id: number, @Utente() u: UtenteCorrente) {
    if (!(await this.chat.puoContattare(u, 'employee', id))) throw new HttpException({ statusCode: 404, message: 'Dipendente non trovato' }, 404);
    const r = await this.chat.conversazioneDiretta(u, 'employee', id);
    if (!r.success || !r.id) throw new HttpException({ statusCode: 400, message: r.error ?? 'Chat non disponibile' }, 400);
    return { conversazione: r.id };
  }
}
