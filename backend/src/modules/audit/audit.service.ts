import { Injectable, Logger } from '@nestjs/common';
import { DataSource } from 'typeorm';
import { senzaIsolamento } from '../../common/database/isolamento';
import { phpJson } from '../../common/utils/php-json';

export type Gravita = 'info' | 'warning' | 'critical';

/** Chi ha fatto l'azione e da dove: arriva dalla richiesta HTTP. */
export interface ContestoAudit {
  ip?: string | null;
  userAgent?: string | null;
  sessioneId?: string | null;
}

/**
 * Registro delle attivita' sensibili, come AuditLog.php: stessa tabella,
 * stesse azioni, stessi campi. I dati sensibili (password, token, segreti)
 * vengono mascherati prima di essere salvati.
 *
 * Differenze volute rispetto al PHP:
 *  - l'IP e' quello visto dal proxy (Traefik), non quello dichiarato dal client
 *    negli header X-Forwarded-For / CF-Connecting-IP, che si potevano falsificare;
 *  - il "backup su file" diventa il log del container.
 */
@Injectable()
export class AuditService {
  private readonly logger = new Logger('Audit');

  constructor(private readonly ds: DataSource) {}

  async log(
    azione: string,
    tipoUtente: string,
    idUtente: number,
    ctx: ContestoAudit,
    entita: string | null = null,
    idEntita: number | null = null,
    vecchi: Record<string, unknown> | null = null,
    nuovi: Record<string, unknown> | null = null,
    gravita: Gravita = 'info',
  ): Promise<void> {
    const dati = {
      user_type: tipoUtente,
      user_id: idUtente,
      action: azione,
      entity_type: entita,
      entity_id: idEntita,
      old_values: vecchi && Object.keys(vecchi).length ? phpJson(maschera(vecchi)) : null,
      new_values: nuovi && Object.keys(nuovi).length ? phpJson(maschera(nuovi)) : null,
      ip_address: ctx.ip || '0.0.0.0',
      user_agent: ctx.userAgent ?? null,
      severity: gravita,
      session_id: ctx.sessioneId ?? null,
    };
    try {
      // Il registro e' di sistema (audit_log non ha azienda): sempre scritto, anche
      // quando la richiesta e' isolata su un'azienda.
      await senzaIsolamento(async () => {
        await this.ds.query(
          `INSERT INTO audit_log (user_type, user_id, action, entity_type, entity_id, old_values, new_values, ip_address, user_agent, severity, session_id)
           VALUES ($1, $2, $3, $4, $5, $6, $7, $8, $9, $10, $11)`,
          [dati.user_type, dati.user_id, dati.action, dati.entity_type, dati.entity_id, dati.old_values, dati.new_values,
            dati.ip_address, dati.user_agent, dati.severity, dati.session_id],
        );
        if (gravita === 'critical') {
          await this.ds.query(
            `INSERT INTO security_alerts (alert_type, user_type, user_id, details, ip_address, is_resolved) VALUES ($1, $2, $3, $4, $5, false)`,
            [azione, tipoUtente, idUtente, phpJson(dati), dati.ip_address],
          );
        }
      });
      this.logger.log(`${azione} | ${tipoUtente}:${idUtente} | IP: ${dati.ip_address} | ${entita ?? '-'}:${idEntita ?? '-'}`);
    } catch (e: any) {
      this.logger.error(`Registro non scritto (${azione}): ${e?.message ?? e}`);
    }
  }

  /** Quanti eventi di un'azione per un utente negli ultimi minuti (pattern sospetti). */
  async conta(azione: string, tipoUtente: string, idUtente: number, minuti: number): Promise<number> {
    const [r] = await senzaIsolamento(() => this.ds.query(
      `SELECT COUNT(*)::int AS n FROM audit_log WHERE action = $1 AND user_id = $2 AND user_type = $3
         AND created_at > CURRENT_TIMESTAMP - make_interval(mins => $4)`,
      [azione, idUtente, tipoUtente, minuti],
    ));
    return r?.n ?? 0;
  }
}

const SENSIBILI = ['password', 'password_hash', 'token', 'secret', 'mfa_secret', 'api_key'];

function maschera(dati: Record<string, unknown>): Record<string, unknown> {
  const out: Record<string, unknown> = {};
  for (const [k, v] of Object.entries(dati)) {
    if (v && typeof v === 'object' && !Array.isArray(v)) out[k] = maschera(v as Record<string, unknown>);
    else out[k] = SENSIBILI.includes(k.toLowerCase()) ? '***MASKED***' : v;
  }
  return out;
}
