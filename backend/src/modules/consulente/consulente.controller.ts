import { Controller, Get, HttpCode, HttpException, Param, ParseIntPipe, Post } from '@nestjs/common';
import { Ruoli, Utente } from '../auth/decoratori';
import type { UtenteCorrente } from '../auth/auth.types';
import { ChatService } from '../chat/chat.service';
import { ConsulenteService } from './consulente.service';

/** Vista Cora del consulente del lavoro: cornice, dashboard e chat dalla dashboard. */
@Controller('consulente')
@Ruoli('consulente_lavoro')
export class ConsulenteController {
  constructor(private readonly consulente: ConsulenteService, private readonly chat: ChatService) {}

  @Get('cornice')
  cornice(@Utente() u: UtenteCorrente) {
    return this.consulente.cornice(u);
  }

  @Get('home')
  home(@Utente() u: UtenteCorrente) {
    return this.consulente.home(u);
  }

  @Get('dipendenti')
  dipendenti(@Utente() u: UtenteCorrente) {
    return this.consulente.dipendenti(u.companyId!);
  }

  @Get('assunzioni')
  assunzioni(@Utente() u: UtenteCorrente) {
    return this.consulente.assunzioni(u.companyId!, u.id);
  }

  @Get('documenti')
  documenti(@Utente() u: UtenteCorrente) {
    return this.consulente.documenti(u.companyId!);
  }

  @Get('caricati-dipendenti')
  caricatiDipendenti(@Utente() u: UtenteCorrente) {
    return this.consulente.caricatiDipendenti(u.companyId!, u.id);
  }

  /** "Contatta HR": chat diretta con l'HR dell'azienda (prima chi e' collegato all'azienda). */
  @Post('chat-hr')
  @HttpCode(200)
  async chatHr(@Utente() u: UtenteCorrente) {
    const a = await this.chat.adminPerHR(u.companyId!);
    if (!a || !(await this.chat.puoContattare(u, 'admin', a))) throw new HttpException({ statusCode: 404, message: 'Nessun HR da contattare' }, 404);
    return this.apri(u, 'admin', a);
  }

  /** "Fai gli auguri": chat diretta con il dipendente. */
  @Post('chat/:dipendente')
  @HttpCode(200)
  async chatCon(@Param('dipendente', ParseIntPipe) id: number, @Utente() u: UtenteCorrente) {
    if (!(await this.chat.puoContattare(u, 'employee', id))) throw new HttpException({ statusCode: 404, message: 'Dipendente non trovato' }, 404);
    return this.apri(u, 'employee', id);
  }

  private async apri(u: UtenteCorrente, tipo: string, id: number) {
    const r = await this.chat.conversazioneDiretta(u, tipo, id);
    if (!r.success || !r.id) throw new HttpException({ statusCode: 400, message: r.error ?? 'Chat non disponibile' }, 400);
    return { conversazione: r.id };
  }
}
