import { Injectable, Logger } from '@nestjs/common';
import { DataSource } from 'typeorm';
import { senzaIsolamento } from '../../common/database/isolamento';
import { erroriPassword, hashPassword } from '../../common/sicurezza/password';
import { AuditService, ContestoAudit } from '../audit/audit.service';

export const RUOLI_STAFF = ['admin', 'accountant', 'admin_reparto', 'consulente_lavoro', 'formatore'];

export interface DatiUtente {
  username: string;
  password: string;
  name: string;
  email?: string | null;
  role: string;
  department_id?: number | null;
  is_global?: boolean;
}

export type EsitoUtente = { success: true; id: number } | { success: false; error: string };

/**
 * Account dello staff (User.php). Gli utenti non sono isolati per azienda
 * nel database: ogni lettura e scrittura indica l'azienda esplicitamente.
 */
@Injectable()
export class UtentiService {
  private readonly logger = new Logger('Utenti');
  constructor(private readonly ds: DataSource, private readonly audit: AuditService) {}

  /**
   * Nuovo account (User::create). Stessi controlli e messaggi del PHP;
   * correzione: la password segue anche la politica comune (12 caratteri,
   * come il cambio password), non solo gli 8 caratteri del PHP.
   */
  async crea(d: DatiUtente, companyId: number, ctx: ContestoAudit & { tipo: string; id: number }): Promise<EsitoUtente> {
    const username = String(d.username ?? '');
    const password = String(d.password ?? '');
    if (username === '' || Buffer.byteLength(username) < 3) return { success: false, error: 'Username deve avere almeno 3 caratteri' };
    if (password === '' || Buffer.byteLength(password) < 8) return { success: false, error: 'Password deve avere almeno 8 caratteri' };
    if (!d.name) return { success: false, error: 'Nome obbligatorio' };
    if (!RUOLI_STAFF.includes(d.role)) return { success: false, error: 'Ruolo non valido' };
    if (!/^[A-Za-z0-9_.]{3,50}$/.test(username)) return { success: false, error: 'Username non valido (lettere, numeri, punto e underscore, max 50)' };
    return senzaIsolamento(async () => {
      if ((await this.ds.query('SELECT 1 FROM users WHERE username = $1', [username])).length) return { success: false, error: 'Username già esistente' };
      const email = String(d.email ?? '').trim();
      if (email !== '' && (await this.ds.query('SELECT 1 FROM users WHERE lower(email) = lower($1)', [email])).length) return { success: false, error: 'EMAIL_EXISTS' };
      if (!/^(?=.*[a-z])(?=.*[A-Z])(?=.*\d)(?=.*[\W_]).{8,}$/su.test(password)) {
        return { success: false, error: 'Password troppo debole. Deve contenere almeno una maiuscola, una minuscola, un numero e un carattere speciale.' };
      }
      const errori = erroriPassword(password);
      if (errori.length) return { success: false, error: errori.join(' ') };
      if (d.role === 'admin_reparto' && !d.department_id) return { success: false, error: 'Per admin reparto è obbligatorio specificare un reparto' };
      if (d.department_id) {
        const [rep] = await this.ds.query('SELECT 1 FROM departments WHERE id = $1 AND company_id = $2', [d.department_id, companyId]);
        if (!rep) return { success: false, error: 'Reparto non trovato' };
      }
      if (String(d.name).length > 100 || email.length > 100) return { success: false, error: 'Nome o email troppo lunghi (max 100 caratteri)' };
      try {
        const [r] = await this.ds.query(
          `INSERT INTO users (company_id, username, password_hash, role, name, email, department_id, is_active)
           VALUES ($1, $2, $3, $4, $5, $6, $7, true) RETURNING id`,
          [d.role === 'admin' && d.is_global ? null : companyId, username, await hashPassword(password), d.role, d.name, email || null, d.department_id || null]);
        const { password: _p, ...registrati } = d;
        await this.audit.log('user_created', ctx.tipo, ctx.id, ctx, 'user', r.id, null, registrati as Record<string, unknown>);
        return { success: true, id: r.id };
      } catch (e: any) {
        this.logger.error(`creazione utente non riuscita: ${e?.message ?? e}`);
        return { success: false, error: "Errore durante la creazione dell'utente" };
      }
    });
  }
}
