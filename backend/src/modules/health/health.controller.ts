import { Controller, Get } from '@nestjs/common';
import { DataSource } from 'typeorm';
import { Pubblico } from '../auth/decoratori';

/** Stato del servizio per il controllo di Docker e Dokploy: niente dati, solo "risponde". */
@Controller('health')
export class HealthController {
  constructor(private readonly ds: DataSource) {}

  @Get()
  @Pubblico()
  async stato() {
    await this.ds.query('SELECT 1');
    return { ok: true };
  }
}
