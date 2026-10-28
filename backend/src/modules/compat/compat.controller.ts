import { Body, Controller, Get, HttpCode, HttpException, Post, Query, Req } from '@nestjs/common';
import { decodificaCodiceFiscale } from '../../common/calcoli/codice-fiscale';
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

/** Ultima chiamata a Nominatim: al massimo una al secondo (regole d'uso del servizio). */
let ultimaRicercaIndirizzo = 0;

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
  @Ruoli('employee', 'admin', 'consulente_lavoro')
  @HttpCode(200)
  async notificheLette(@Utente() u: UtenteCorrente) {
    await this.notifiche.segnaTutteLette(u.tipo === 'employee' ? 'employee' : u.ruolo, u.id);
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

  /**
   * Autocompilazione dei moduli di assunzione (api/lookup.php): dati di
   * nascita dal codice fiscale, CAP/comune/provincia da un indirizzo.
   * Correzioni: solo lo staff (prima bastava un qualsiasi accesso, anche da
   * dipendente) e al massimo una ricerca di indirizzo al secondo verso
   * Nominatim; gli errori restano { error } con codice 200 come nel PHP.
   */
  @Get('lookup.php')
  @Ruoli('admin', 'consulente_lavoro', 'formatore')
  async ricerca(@Query('action') azione = '', @Query('cf') cf = '', @Query('q') q = '') {
    if (azione === 'cf') {
      const v = String(cf).trim();
      if (v.length !== 16) return { error: 'CF deve essere 16 caratteri' };
      return decodificaCodiceFiscale(v) ?? { error: 'Codice fiscale non valido' };
    }
    if (azione === 'address') {
      const testo = String(q).trim().slice(0, 300);
      if ([...testo].length < 4) return { error: 'Indirizzo troppo corto' };
      if (Date.now() - ultimaRicercaIndirizzo < 1000) return { error: 'Servizio non disponibile' };
      ultimaRicercaIndirizzo = Date.now();
      const url = 'https://nominatim.openstreetmap.org/search?' + new URLSearchParams({ q: testo, format: 'jsonv2', countrycodes: 'it', addressdetails: '1', limit: '1', 'accept-language': 'it' });
      let dati: any;
      try {
        const r = await fetch(url, { headers: { 'User-Agent': 'PAManager/1.0 (info@connecteed.com)' }, redirect: 'error', signal: AbortSignal.timeout(8000) });
        dati = await r.json();
      } catch {
        return { error: 'Servizio non disponibile' };
      }
      if (!Array.isArray(dati) || !dati[0]) return { error: 'Indirizzo non trovato' };
      const a = dati[0].address ?? {};
      const via = String(a.road ?? '');
      const civico = String(a.house_number ?? '');
      return {
        street: `${via}${civico ? `, ${civico}` : ''}`.trim(), cap: a.postcode ?? '', city: a.city ?? a.town ?? a.village ?? a.hamlet ?? a.municipality ?? '',
        province: String(a.county ?? a.state_district ?? '').replace(/^Provincia\s+di\s+/i, ''), display: dati[0].display_name ?? '',
      };
    }
    return { error: 'Azione non riconosciuta' };
  }
}
