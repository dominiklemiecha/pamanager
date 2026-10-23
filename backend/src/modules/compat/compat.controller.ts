import { Body, Controller, Get, HttpCode, HttpException, Post, Query, Req } from '@nestjs/common';
import { Pubblico, Ruoli, Utente } from '../auth/decoratori';
import type { RichiestaAutenticata, UtenteCorrente } from '../auth/auth.types';
import { DipendenteService } from '../dipendente/dipendente.service';
import { NotificheService } from '../notifiche/notifiche.service';
import { PushService } from '../notifiche/push.service';

/**
 * Endpoint con gli indirizzi del vecchio PHP (/api/*.php), usati dagli script
 * delle pagine (guida, stato di disponibilita', notifiche, push). Stesse
 * risposte: { ok/success... } oppure { error: true, message } con il codice HTTP.
 * Tutti passano da sessione e CSRF come le altre rotte.
 */
const errore = (message: string, status = 400) => new HttpException({ error: true, message }, status);

@Controller('api')
export class CompatController {
  constructor(private readonly dip: DipendenteService, private readonly notifiche: NotificheService, private readonly pushService: PushService) {}

  @Post('employee-tour.php')
  @Ruoli('employee')
  @HttpCode(200)
  async guida(@Body() body: { status?: string }, @Utente() u: UtenteCorrente) {
    if (!(await this.dip.statoGuida(u.id, String(body?.status ?? '')))) throw errore('Stato non valido');
    return { ok: true };
  }

  @Post('availability.php')
  @Ruoli('employee')
  @HttpCode(200)
  async disponibilita(@Body() body: { status?: string }, @Utente() u: UtenteCorrente) {
    const stato = String(body?.status ?? '');
    if (!(await this.dip.disponibilita(u.id, stato))) throw errore('Stato non valido');
    return { ok: true, status: stato };
  }

  @Post('notifications-read.php')
  @Ruoli('employee')
  @HttpCode(200)
  async notificheLette(@Utente() u: UtenteCorrente) {
    await this.notifiche.segnaTutteLette('employee', u.id);
    return { success: true };
  }

  /** Chiave pubblica VAPID per l'iscrizione alle notifiche push (nessun dato riservato). */
  @Get('push.php')
  @Pubblico()
  chiavePush(@Query('action') action = 'public_key') {
    if (action !== 'public_key') throw errore('Azione non valida');
    return { publicKey: this.pushService.chiavePubblica() };
  }

  /**
   * Iscrizione, disiscrizione e prova delle push.
   * Correzioni rispetto al PHP: serve sempre la sessione (prima la
   * disiscrizione era aperta a chiunque) e il controllo CSRF; si toglie solo
   * un'iscrizione propria; gli errori del database non escono al browser.
   */
  @Post('push.php')
  @HttpCode(200)
  async push(@Body() body: { action?: string; subscription?: any; endpoint?: string }, @Req() req: RichiestaAutenticata, @Utente() u: UtenteCorrente) {
    const azione = body?.action ?? 'subscribe';
    const tipo = u.tipo === 'employee' ? 'employee' : u.ruolo;
    switch (azione) {
      case 'subscribe':
      case 'subscribe_employee': {
        if ((azione === 'subscribe') !== (u.tipo === 'user')) throw errore('Autenticazione richiesta', 401);
        if (!body.subscription) throw errore('Dati sottoscrizione mancanti');
        const r = await this.pushService.salvaIscrizione(tipo, u.id, u.companyId, body.subscription, String(req.headers['user-agent'] ?? '') || null);
        if (!r.success) throw errore((r as { error?: string }).error ?? "Errore salvataggio");
        return { success: true, message: 'Sottoscrizione salvata' };
      }
      case 'unsubscribe':
        if (!body.endpoint) throw errore('Endpoint mancante');
        await this.pushService.rimuoviIscrizione(body.endpoint, tipo, u.id);
        return { success: true, message: 'Sottoscrizione rimossa' };
      case 'test':
        return this.pushService.aUtente(tipo, u.id, { title: 'Test Notifica', body: 'Le notifiche push funzionano correttamente!', url: '/', tag: `test-${Math.floor(Date.now() / 1000)}` });
      default:
        throw errore('Azione non valida');
    }
  }
}
