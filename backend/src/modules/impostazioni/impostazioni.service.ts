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

export const CAMPI_ANAGRAFICI: Record<string, string> = {
  username: 'Username', fiscal_code: 'Codice fiscale', first_name: 'Nome', last_name: 'Cognome', email: 'Email', phone: 'Telefono',
  address: 'Indirizzo di residenza', birth_date: 'Data di nascita', department_id: 'Reparto', position: 'Posizione', hire_date: 'Data assunzione',
  job_level: 'Livello', ral_amount: 'RAL annua', monthly_salary: 'Retribuzione mensile', iban: 'IBAN', medical_certs: 'Certificati medici',
};
export const RUOLI_VISIBILITA: Record<string, string> = { admin: 'Amministratore', accountant: 'Commercialista', admin_reparto: 'Admin reparto' };
const PREDEFINITI: Record<string, string[]> = {
  username: ['admin', 'accountant', 'admin_reparto'], fiscal_code: ['admin', 'accountant'], first_name: ['admin', 'accountant', 'admin_reparto'],
  last_name: ['admin', 'accountant', 'admin_reparto'], email: ['admin', 'accountant', 'admin_reparto'], phone: ['admin', 'accountant', 'admin_reparto'],
  address: ['admin', 'accountant'], birth_date: ['admin', 'accountant', 'admin_reparto'], department_id: ['admin', 'accountant', 'admin_reparto'],
  position: ['admin', 'accountant', 'admin_reparto'], hire_date: ['admin', 'accountant', 'admin_reparto'], job_level: ['admin', 'accountant'],
  ral_amount: ['admin', 'accountant'], monthly_salary: ['admin', 'accountant'], iban: ['admin', 'accountant'], medical_certs: ['admin'],
};
const VISIBILI_AI_COLLEGHI = ['first_name', 'last_name', 'position', 'department_id', 'birth_date'];
const CHIAVE = 'employee_field_visibility';

/** Visibilita' dei campi anagrafici per ruolo (FieldVisibility.php). */
@Injectable()
export class VisibilitaCampiService {
  constructor(private readonly impostazioni: ImpostazioniService) {}

  async configurazione(cid: number): Promise<Record<string, string[]>> {
    const raw = await this.impostazioni.leggi(cid, CHIAVE);
    let letto: Record<string, unknown> = {};
    try { letto = raw ? JSON.parse(raw) ?? {} : {}; } catch { letto = {}; }
    const out: Record<string, string[]> = {};
    for (const f of Object.keys(CAMPI_ANAGRAFICI)) {
      const v = letto[f];
      out[f] = Array.isArray(v) ? Object.keys(RUOLI_VISIBILITA).filter((r) => v.includes(r)) : PREDEFINITI[f];
    }
    return out;
  }

  async salva(cid: number, cfg: Record<string, unknown>, userId: number) {
    const pulito: Record<string, string[]> = {};
    for (const f of Object.keys(CAMPI_ANAGRAFICI)) {
      const v = cfg[f];
      pulito[f] = Array.isArray(v) ? Object.keys(RUOLI_VISIBILITA).filter((r) => v.includes(r)) : [];
    }
    await this.impostazioni.scrivi(cid, CHIAVE, JSON.stringify(pulito), userId);
  }

  /** FieldVisibility::canView. */
  async puoVedere(cid: number, campo: string, tipo: string, viewerId: number | null, targetId: number, repViewer: number | null = null, repTarget: number | null = null) {
    if (tipo === 'admin') return true;
    if (tipo === 'employee') return viewerId === targetId || VISIBILI_AI_COLLEGHI.includes(campo);
    if (tipo === 'admin_reparto') {
      if (repViewer === null || repTarget === null || repViewer !== repTarget) return false;
      return (await this.configurazione(cid))[campo]?.includes('admin_reparto') ?? false;
    }
    if (tipo === 'accountant') return (await this.configurazione(cid))[campo]?.includes('accountant') ?? false;
    return false;
  }
}
