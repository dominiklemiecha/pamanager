import { Body, Controller, Get, HttpCode, HttpException, Post, Req, UploadedFile, UseInterceptors } from '@nestjs/common';
import { FileInterceptor, NoFilesInterceptor } from '@nestjs/platform-express';
import { memoryStorage } from 'multer';
import type { FileCaricato } from '../../common/file/caricamenti';
import { Ruoli, Utente } from '../auth/decoratori';
import type { RichiestaAutenticata, UtenteCorrente } from '../auth/auth.types';
import { ProfiloService } from './profilo.service';

const errore = (messaggio: string | null) => {
  if (messaggio) throw new HttpException({ statusCode: 400, message: messaggio }, 400);
  return { ok: true };
};

/** Profilo del dipendente (employee/profile.php). */
@Controller('dipendente/profilo')
@Ruoli('employee')
export class ProfiloController {
  constructor(private readonly profilo: ProfiloService) {}

  @Get()
  dati(@Utente() u: UtenteCorrente) {
    return this.profilo.dati(u.id);
  }

  // I moduli della pagina arrivano come multipart (FormData), senza file.
  @Post('dati')
  @HttpCode(200)
  @UseInterceptors(NoFilesInterceptor())
  async aggiornaDati(@Body() b: Record<string, string>, @Req() req: RichiestaAutenticata, @Utente() u: UtenteCorrente) {
    return errore(await this.profilo.aggiornaDati(u.id, b.first_name, b.last_name, { ip: req.ip, userAgent: String(req.headers['user-agent'] ?? ''), sessioneId: u.sessioneId }));
  }

  @Post('foto')
  @HttpCode(200)
  // Oltre i 3 MB il messaggio lo da' la validazione, come nel PHP.
  @UseInterceptors(FileInterceptor('photo', { storage: memoryStorage(), limits: { fileSize: 12 * 1024 * 1024, files: 1, fields: 10 } }))
  async foto(@UploadedFile() f: FileCaricato | undefined, @Utente() u: UtenteCorrente) {
    return errore(await this.profilo.caricaFoto(u.id, u.companyId!, f));
  }

  @Post('foto/rimuovi')
  @HttpCode(200)
  async rimuoviFoto(@Utente() u: UtenteCorrente) {
    await this.profilo.rimuoviFoto(u.id);
    return { ok: true };
  }

  @Post('notifiche')
  @HttpCode(200)
  @UseInterceptors(NoFilesInterceptor())
  async notifiche(@Body() b: Record<string, string>, @Utente() u: UtenteCorrente) {
    await this.profilo.notifiche(u.id, !!b.notify_email, !!b.notify_push);
    return { ok: true };
  }
}
