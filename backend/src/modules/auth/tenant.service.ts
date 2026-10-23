import { Injectable } from '@nestjs/common';
import { DataSource } from 'typeorm';
import { senzaIsolamento } from '../../common/database/isolamento';

interface Account { id: number; role: string; companyId: number | null }

const RUOLI_CAMBIO = ['admin', 'accountant', 'consulente_lavoro'];

/**
 * Aziende accessibili allo staff, come Tenant.php:
 *  - admin globale "vero" (company_id NULL e nessuna riga in user_companies): tutte le attive;
 *  - admin, commercialista, consulente: azienda dell'account + quelle di user_companies;
 *  - responsabile di reparto e formatore: solo l'azienda dell'account.
 * I dipendenti non passano di qui: la loro azienda e' quella della scheda.
 */
@Injectable()
export class TenantService {
  constructor(private readonly ds: DataSource) {}

  async adminGlobale(u: Account): Promise<boolean> {
    if (u.role !== 'admin' || u.companyId !== null) return false;
    const [r] = await senzaIsolamento(() => this.ds.query('SELECT COUNT(*)::int AS n FROM user_companies WHERE user_id = $1', [u.id]));
    return r.n === 0;
  }

  async accessibili(u: Account): Promise<number[]> {
    return senzaIsolamento(async () => {
      if (await this.adminGlobale(u)) {
        const righe = await this.ds.query('SELECT id FROM companies WHERE is_active = true ORDER BY id');
        return righe.map((r: any) => Number(r.id));
      }
      if (RUOLI_CAMBIO.includes(u.role)) {
        const ids: number[] = [];
        if (u.companyId) ids.push(u.companyId);
        const righe = await this.ds.query(
          `SELECT c.id FROM companies c JOIN user_companies uc ON uc.company_id = c.id
            WHERE uc.user_id = $1 AND c.is_active = true`, [u.id]);
        for (const r of righe) if (!ids.includes(Number(r.id))) ids.push(Number(r.id));
        return ids;
      }
      return u.companyId ? [u.companyId] : [];
    });
  }

  /** Azienda di lavoro: quella scelta se e' ancora accessibile, altrimenti la prima. 0 = nessuna. */
  async corrente(u: Account, scelta: number | null): Promise<number> {
    const ids = await this.accessibili(u);
    if (!ids.length) return 0;
    if (scelta && ids.includes(scelta)) return scelta;
    return ids[0];
  }

  async puoCambiare(u: Account): Promise<boolean> {
    if (!RUOLI_CAMBIO.includes(u.role)) return false;
    if (await this.adminGlobale(u)) return true;
    return (await this.accessibili(u)).length > 1;
  }

  /** true se il cambio e' consentito (azienda attiva e accessibile). */
  async verificaCambio(u: Account, companyId: number): Promise<boolean> {
    if (!RUOLI_CAMBIO.includes(u.role)) return false;
    const globale = await this.adminGlobale(u);
    if (!globale && !(await this.accessibili(u)).includes(companyId)) return false;
    const [c] = await senzaIsolamento(() => this.ds.query('SELECT id FROM companies WHERE id = $1 AND is_active = true', [companyId]));
    return !!c;
  }
}
