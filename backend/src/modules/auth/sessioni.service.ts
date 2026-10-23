import { Injectable } from '@nestjs/common';
import { createHash, randomBytes } from 'crypto';
import { DataSource } from 'typeorm';
import { senzaIsolamento } from '../../common/database/isolamento';
import { DURATA_MASSIMA, INATTIVITA, INATTIVITA_DEFAULT } from './auth.types';

export interface Sessione {
  id: string;
  subject_type: 'user' | 'employee';
  subject_id: number;
  role: string;
  selected_company_id: number | null;
  mfa_pending: boolean;
  user_agent: string;
  created_at: Date;
  last_activity_at: Date;
  expires_at: Date;
}

export type EsitoSessione =
  | { stato: 'valida'; sessione: Sessione }
  | { stato: 'scaduta'; sessione: Sessione; timeout: number; trascorsi: number }
  | { stato: 'dispositivo-cambiato'; sessione: Sessione }
  | { stato: 'assente' };

const impronta = (token: string) => createHash('sha256').update(token).digest('hex');

/**
 * Sessioni lato server. Il codice nel cookie non si salva mai: solo l'impronta.
 * Le sessioni non si leggono mai con l'isolamento attivo (si cercano prima di
 * sapere l'azienda), quindi tutte le query girano senza.
 */
@Injectable()
export class SessioniService {
  constructor(private readonly ds: DataSource) {}

  async crea(dati: { tipo: 'user' | 'employee'; id: number; ruolo: string; companyId: number | null; mfa: boolean; userAgent: string; ip: string }): Promise<{ token: string; sessione: Sessione }> {
    const token = randomBytes(32).toString('base64url');
    const [s] = await senzaIsolamento(() => this.ds.query(
      `INSERT INTO auth_sessions (token_hash, subject_type, subject_id, role, selected_company_id, mfa_pending, user_agent, ip_address, expires_at)
       VALUES ($1, $2, $3, $4, $5, $6, $7, $8, CURRENT_TIMESTAMP + make_interval(secs => $9)) RETURNING *`,
      [impronta(token), dati.tipo, dati.id, dati.ruolo, dati.companyId, dati.mfa, dati.userAgent, dati.ip, DURATA_MASSIMA],
    ));
    return { token, sessione: s };
  }

  /** Cerca la sessione del cookie e controlla scadenze e dispositivo, come Auth::init del PHP. */
  async verifica(token: string | undefined, userAgent: string): Promise<EsitoSessione> {
    if (!token || token.length > 100) return { stato: 'assente' };
    const [s] = await senzaIsolamento(() => this.ds.query(
      'SELECT * FROM auth_sessions WHERE token_hash = $1 AND revoked_at IS NULL', [impronta(token)]));
    if (!s) return { stato: 'assente' };
    const ora = Date.now();
    const timeout = INATTIVITA[s.role] ?? INATTIVITA_DEFAULT;
    const trascorsi = Math.floor((ora - new Date(s.last_activity_at).getTime()) / 1000);
    if (trascorsi > timeout || ora >= new Date(s.expires_at).getTime()) {
      return { stato: 'scaduta', sessione: s, timeout, trascorsi };
    }
    if (s.user_agent !== userAgent) return { stato: 'dispositivo-cambiato', sessione: s };
    return { stato: 'valida', sessione: s };
  }

  async tocca(id: string): Promise<void> {
    await senzaIsolamento(() => this.ds.query('UPDATE auth_sessions SET last_activity_at = CURRENT_TIMESTAMP WHERE id = $1', [id]));
  }

  async chiudi(id: string, motivo: string): Promise<void> {
    await senzaIsolamento(() => this.ds.query(
      'UPDATE auth_sessions SET revoked_at = CURRENT_TIMESTAMP, revoked_reason = $2 WHERE id = $1 AND revoked_at IS NULL', [id, motivo]));
  }

  /** Chiude tutte le sessioni di un account (password cambiata, account disattivato). */
  async chiudiTutte(tipo: 'user' | 'employee', id: number, motivo: string, tranne?: string): Promise<void> {
    await senzaIsolamento(() => this.ds.query(
      `UPDATE auth_sessions SET revoked_at = CURRENT_TIMESTAMP, revoked_reason = $3
        WHERE subject_type = $1 AND subject_id = $2 AND revoked_at IS NULL AND ($4::uuid IS NULL OR id <> $4::uuid)`,
      [tipo, id, motivo, tranne ?? null]));
  }

  async completaMfa(id: string): Promise<void> {
    await senzaIsolamento(() => this.ds.query('UPDATE auth_sessions SET mfa_pending = false, last_activity_at = CURRENT_TIMESTAMP WHERE id = $1', [id]));
  }

  async scegliAzienda(id: string, companyId: number): Promise<void> {
    await senzaIsolamento(() => this.ds.query('UPDATE auth_sessions SET selected_company_id = $2 WHERE id = $1', [id, companyId]));
  }
}
