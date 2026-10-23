import { Body, Controller, Get, HttpCode, Post, Query, Req, Res, UploadedFiles, UseInterceptors } from '@nestjs/common';
import { FileFieldsInterceptor } from '@nestjs/platform-express';
import type { Response } from 'express';
import { memoryStorage } from 'multer';
import { Ruoli, Utente } from '../auth/decoratori';
import type { RichiestaAutenticata, UtenteCorrente } from '../auth/auth.types';
import { ChatAzioniService, FileChat } from './chat-azioni.service';
import { ChatService, MINUTI_MODIFICA } from './chat.service';
import { RealtimeService } from './realtime.service';

export const FILE_CHAT = FileFieldsInterceptor([{ name: 'attachment', maxCount: 1 }, { name: 'avatar', maxCount: 1 }], {
  storage: memoryStorage(),
  limits: { fileSize: 21 * 1024 * 1024, files: 1, fields: 40 },
});
const intero = (v: unknown) => Number.parseInt(String(v ?? ''), 10) || 0;

/**
 * Chat (employee/chat.php e _chat-page.inc.php): configurazione della pagina,
 * azioni dello script cora-chat.js (stesse risposte del PHP) e file.
 */
@Controller('chat')
@Ruoli('employee', 'admin', 'admin_reparto', 'accountant', 'consulente_lavoro')
export class ChatController {
  constructor(private readonly chat: ChatService, private readonly realtime: RealtimeService, private readonly azioni: ChatAzioniService) {}

  /** window.CORA_CHAT della pagina (_cora/chat.inc.php). */
  @Get()
  async pagina(@Query('conv') conv: string, @Query('with_admin') conAdmin: string, @Utente() u: UtenteCorrente) {
    const c = this.chat.chi(u);
    // "Scrivi all'HR": chat con un admin dell'azienda (prima chi e' collegato all'azienda).
    if (conAdmin && ['employee', 'admin_reparto'].includes(c.tipo)) {
      const a = await this.chat.adminPerHR(u.companyId!);
      if (a && (await this.chat.puoContattare(u, 'admin', a))) {
        const r = await this.chat.conversazioneDiretta(u, 'admin', a);
        if (r.success) return { vaiA: r.id };
      }
    }
    let aperta = intero(conv);
    if (aperta && !(await this.chat.sePartecipa(aperta, c))) aperta = 0;
    // Come il PHP: l'elenco e' quello di prima della lettura della chat aperta.
    const elenco = await this.chat.elenco(c, u.companyId!);
    if (aperta) await this.chat.segnaLetta(aperta, c);
    const token = this.realtime.token(c.tipo, c.id, u.companyId!);
    return {
      me: await this.chat.persona(c.tipo, c.id),
      open: aperta,
      conversations: elenco,
      contacts: await this.chat.contattiCora(u),
      presence: await this.chat.presenze(u.companyId!),
      editMinutes: MINUTI_MODIFICA,
      assets: '/assets/images/cora-chat/',
      realtime: token ? { path: '/realtime/socket.io', token } : null,
    };
  }

  /** Azioni dello script (POST alla pagina nel PHP). */
  @Post('azione')
  @HttpCode(200)
  @UseInterceptors(FILE_CHAT)
  async azione(@Body() b: Record<string, string>, @UploadedFiles() f: FileChat, @Res({ passthrough: true }) res: Response, @Utente() u: UtenteCorrente) {
    return this.azioni.esegui(b, f, u, (codice) => res.status(codice));
  }

  /** Allegati e foto dei gruppi (?download_attachment= e ?group_avatar= del PHP). */
  @Get('file')
  async file(@Query('download_attachment') allegato: string, @Query('group_avatar') gruppo: string, @Query('inline') inline: string,
    @Req() req: RichiestaAutenticata, @Res() res: Response, @Utente() u: UtenteCorrente) {
    return this.azioni.file(allegato, gruppo, inline, { ip: req.ip, userAgent: String(req.headers['user-agent'] ?? ''), sessioneId: u.sessioneId }, res, u);
  }
}
