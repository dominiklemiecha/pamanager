import { Controller, Get, HttpCode, Post } from '@nestjs/common';
import { Ruoli, Utente } from '../auth/decoratori';
import type { UtenteCorrente } from '../auth/auth.types';
import { NotificheService } from '../notifiche/notifiche.service';
import { DipendenteService } from './dipendente.service';

/** Area del dipendente: solo il dipendente collegato, solo i suoi dati. */
@Controller('dipendente')
@Ruoli('employee')
export class DipendenteController {
  constructor(private readonly dip: DipendenteService, private readonly notifiche: NotificheService) {}

  @Get('cornice')
  cornice(@Utente() u: UtenteCorrente) {
    return this.dip.cornice(u.id, u.companyId, u.sessioneCreata);
  }

  @Get('home')
  home(@Utente() u: UtenteCorrente) {
    return this.dip.home(u.id, u.companyId);
  }

  /** La campanella aperta segna lette le notifiche (api/notifications-read.php). */
  @Post('notifiche/lette')
  @HttpCode(200)
  async notificheLette(@Utente() u: UtenteCorrente) {
    await this.notifiche.segnaTutteLette('employee', u.id);
    return { success: true };
  }
}
