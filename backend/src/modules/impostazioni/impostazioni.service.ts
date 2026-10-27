import { Injectable } from '@nestjs/common';
import { DataSource } from 'typeorm';

/** Impostazioni chiave/valore per azienda (Settings.php, tabella app_settings). */
@Injectable()
export class ImpostazioniService {
  constructor(private readonly ds: DataSource) {}

  async leggi(cid: number, chiave: string): Promise<string | null> {
    const [r] = await this.ds.query('SELECT setting_value FROM app_settings WHERE company_id = $1 AND setting_key = $2', [cid, chiave]);
    return r?.setting_value ?? null;
  }

  async scrivi(cid: number, chiave: string, valore: string | null, userId: number | null) {
    await this.ds.query(
      `INSERT INTO app_settings (setting_key, company_id, setting_value, updated_by) VALUES ($1, $2, $3, $4)
       ON CONFLICT (setting_key, company_id) DO UPDATE SET setting_value = EXCLUDED.setting_value, updated_by = EXCLUDED.updated_by`,
      [chiave, cid, valore, userId]);
  }
}
