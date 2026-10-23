import { Body, Controller, Get, HttpCode, Post, Query } from '@nestjs/common';
import { DataSource } from 'typeorm';
import { senzaIsolamento } from '../../common/database/isolamento';
import { Pubblico, SenzaCsrf } from '../auth/decoratori';

type Stato = 'invalid' | 'confirm' | 'already' | 'unsubscribed' | 'error';

/**
 * Disattivazione delle email dal link in fondo ai messaggi
 * (employee/unsubscribe.php): pubblico, vale solo il codice del link.
 */
@Controller('pubblico/disiscrizione')
@Pubblico()
export class DisiscrizioneController {
  constructor(private readonly ds: DataSource) {}

  private async trova(token: unknown) {
    const t = String(token ?? '');
    if (!/^[a-f0-9]{32,64}$/.test(t)) return null;
    // Il dipendente non e' collegato: si cerca in tutte le aziende, solo per codice.
    const [e] = await senzaIsolamento(() => this.ds.query('SELECT id, first_name, last_name, notify_email FROM employees WHERE email_unsubscribe_token = $1', [t]));
    return e ?? null;
  }

  @Get()
  async stato(@Query('token') token: string): Promise<{ stato: Stato; nome?: string }> {
    const e = await this.trova(token);
    if (!e) return { stato: 'invalid' };
    return { stato: e.notify_email ? 'confirm' : 'already', nome: `${e.first_name} ${e.last_name}`.trim() };
  }

  // Link aperto da un'email senza sessione: niente cookie CSRF, protegge il codice.
  @Post()
  @SenzaCsrf()
  @HttpCode(200)
  async conferma(@Body() b: { token?: string }): Promise<{ stato: Stato; nome?: string }> {
    const e = await this.trova(b?.token);
    if (!e) return { stato: 'invalid' };
    const nome = `${e.first_name} ${e.last_name}`.trim();
    if (!e.notify_email) return { stato: 'already', nome };
    try {
      await senzaIsolamento(() => this.ds.query('UPDATE employees SET notify_email = false WHERE id = $1', [e.id]));
      return { stato: 'unsubscribed', nome };
    } catch {
      return { stato: 'error' };
    }
  }
}
