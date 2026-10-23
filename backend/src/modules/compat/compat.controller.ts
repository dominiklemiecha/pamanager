import { Body, Controller, HttpCode, HttpException, Post } from '@nestjs/common';
import { Ruoli, Utente } from '../auth/decoratori';
import type { UtenteCorrente } from '../auth/auth.types';
import { DipendenteService } from '../dipendente/dipendente.service';

/**
 * Endpoint con gli indirizzi del vecchio PHP (/api/*.php), usati dagli script
 * della vista dipendente (guida, stato di disponibilita'). Stesse risposte:
 * { ok: true } oppure { error: true, message } con il codice HTTP.
 */
const errore = (message: string, status = 400) => new HttpException({ error: true, message }, status);

@Controller('api')
@Ruoli('employee')
export class CompatController {
  constructor(private readonly dip: DipendenteService) {}

  @Post('employee-tour.php')
  @HttpCode(200)
  async guida(@Body() body: { status?: string }, @Utente() u: UtenteCorrente) {
    if (!(await this.dip.statoGuida(u.id, String(body?.status ?? '')))) throw errore('Stato non valido');
    return { ok: true };
  }

  @Post('availability.php')
  @HttpCode(200)
  async disponibilita(@Body() body: { status?: string }, @Utente() u: UtenteCorrente) {
    const stato = String(body?.status ?? '');
    if (!(await this.dip.disponibilita(u.id, stato))) throw errore('Stato non valido');
    return { ok: true, status: stato };
  }
}
