import { Body, Controller, Get, HttpCode, HttpException, Post, Req } from '@nestjs/common';
import { DataSource } from 'typeorm';
import { senzaIsolamento } from '../../common/database/isolamento';
import { AuditService } from '../audit/audit.service';
import { Ruoli, Utente } from '../auth/decoratori';
import type { RichiestaAutenticata, UtenteCorrente } from '../auth/auth.types';
import { StaffService } from './staff.service';

/** Cornice dell'area staff e uscita da un'azienda (auth/leave-tenant.php). */
@Controller('staff')
@Ruoli('admin', 'consulente_lavoro', 'formatore')
export class StaffController {
  constructor(private readonly staff: StaffService, private readonly ds: DataSource, private readonly audit: AuditService) {}

  @Get('cornice')
  cornice(@Utente() u: UtenteCorrente) {
    return this.staff.cornice(u);
  }

  /** Consulente a fine mandato: si toglie da un'azienda (non dall'ultima). */
  @Post('lascia-azienda')
  @HttpCode(200)
  @Ruoli('consulente_lavoro')
  async lascia(@Body() b: { azienda?: number }, @Req() req: RichiestaAutenticata, @Utente() u: UtenteCorrente) {
    // "azienda", non company_id: bodyGuard toglie sempre company_id dai corpi.
    const cid = Number(b?.azienda) || 0;
    if (cid <= 0) throw new HttpException({ statusCode: 400, message: 'Azienda non valida' }, 400);
    const ids: number[] = (await senzaIsolamento(() => this.ds.query('SELECT company_id FROM user_companies WHERE user_id = $1', [u.id]))).map((r: any) => r.company_id);
    if (!ids.includes(cid)) throw new HttpException({ statusCode: 400, message: 'Non sei collegato a questa azienda' }, 400);
    if (ids.length <= 1) throw new HttpException({ statusCode: 400, message: "Non puoi rimuoverti dall'ultima azienda: contatta l'admin per la disattivazione totale" }, 400);
    await senzaIsolamento(() => this.ds.query('DELETE FROM user_companies WHERE user_id = $1 AND company_id = $2', [u.id, cid]));
    await this.audit.log('user_left_company', 'user', u.id, { ip: req.ip, userAgent: String(req.headers['user-agent'] ?? ''), sessioneId: u.sessioneId }, null, null, null, { company_id: cid });
    return { ok: true };
  }
}
