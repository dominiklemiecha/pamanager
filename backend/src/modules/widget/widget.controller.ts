import { Body, Controller, HttpCode, HttpException, Post, Req, Res } from '@nestjs/common';
import type { Response } from 'express';
import { DataSource } from 'typeorm';
import { senzaIsolamento } from '../../common/database/isolamento';
import { SenzaCsrf, Utente } from '../auth/decoratori';
import type { RichiestaAutenticata, UtenteCorrente } from '../auth/auth.types';
import { WidgetService } from './widget.service';

/**
 * Sessione del widget assistenza (api/assistenza-sessione.php): risponde con
 * la dichiarazione firmata di chi e' collegato. Niente token CSRF (il widget
 * non lo conosce): non modifica nulla, non ha CORS e rifiuta le richieste
 * che il browser marca come cross-site.
 */
@Controller('api')
export class WidgetController {
  constructor(private readonly widget: WidgetService, private readonly ds: DataSource) {}

  @Post('assistenza-sessione.php')
  @SenzaCsrf()
  @HttpCode(200)
  async sessione(@Body() b: Record<string, unknown>, @Req() req: RichiestaAutenticata, @Res({ passthrough: true }) res: Response, @Utente() u: UtenteCorrente) {
    res.set('Cache-Control', 'no-store');
    const sito = String(req.headers['sec-fetch-site'] ?? '');
    if (sito !== '' && sito !== 'same-origin') throw new HttpException({ error: 'Origine non ammessa' }, 403);
    if (!this.widget.configurato()) throw new HttpException({ error: 'Assistenza non configurata' }, 503);
    let id: { sub: string; name: string; role: string };
    if (u.tipo === 'employee') {
      const [e] = await this.ds.query('SELECT first_name, last_name, email, username FROM employees WHERE id = $1', [u.id]);
      const nome = `${e?.first_name ?? ''} ${e?.last_name ?? ''}`.trim();
      id = { sub: String(e?.email || e?.username || ''), name: nome || String(e?.username ?? ''), role: 'employee' };
    } else {
      const [x] = await senzaIsolamento(() => this.ds.query('SELECT name, email, username, role FROM users WHERE id = $1', [u.id]));
      id = { sub: String(x?.email || x?.username || ''), name: String(x?.name || x?.username || ''), role: String(x?.role ?? '') };
    }
    if (!id.sub) throw new HttpException({ error: 'Autenticazione richiesta' }, 401);
    const ctx: Record<string, string> = {
      page: [...String(b?.pagina ?? '')].slice(0, 200).join(''),
      path: [...String(b?.percorso ?? '')].slice(0, 200).join(''),
    };
    const [c] = await senzaIsolamento(() => this.ds.query('SELECT name FROM companies WHERE id = $1', [u.companyId]));
    if (c) ctx.company = c.name;
    return { token: this.widget.firma(id, ctx) };
  }
}
