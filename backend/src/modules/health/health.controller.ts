import { Controller, Get } from '@nestjs/common';
import { DataSource } from 'typeorm';

/** Stato del servizio per il controllo di Docker e Dokploy: niente dati, solo "risponde". */
@Controller('health')
export class HealthController {
  constructor(private readonly ds: DataSource) {}

  @Get()
  async stato() {
    await this.ds.query('SELECT 1');
    return { ok: true };
  }
}
