import { Injectable, Logger } from '@nestjs/common';
import { DataSource } from 'typeorm';
import { PushService } from './push.service';

export interface NuovaNotifica {
  recipient_type: string;
  recipient_id: number;
  type: string;
  title: string;
  message?: string;
  link?: string | null;
}

/**
 * Notifiche nel portale (campanella), come Notification.php: una riga in
 * notifications e la stessa notifica come push sui dispositivi.
 *
 * L'azienda della notifica e' quella passata da chi la crea (l'azienda della
 * richiesta o della risorsa), mai un valore del client.
 */
@Injectable()
export class NotificheService {
  private readonly logger = new Logger('Notifiche');

  constructor(private readonly ds: DataSource, private readonly push: PushService) {}

  async crea(companyId: number, n: NuovaNotifica, conPush = true): Promise<{ success: boolean; id?: number; error?: string }> {
    if (!n.recipient_type || !n.recipient_id) return { success: false, error: 'Destinatario obbligatorio' };
    if (!n.type) return { success: false, error: 'Tipo notifica obbligatorio' };
    if (!n.title) return { success: false, error: 'Titolo obbligatorio' };
    try {
      const [r] = await this.ds.query(
        `INSERT INTO notifications (company_id, recipient_type, recipient_id, type, title, message, link, is_read)
         VALUES ($1, $2, $3, $4, $5, $6, $7, false) RETURNING id`,
        [companyId, n.recipient_type, n.recipient_id, n.type, n.title, n.message ?? '', n.link ?? null]);
      if (conPush) try {
        await this.push.aUtente(n.recipient_type, n.recipient_id, { title: n.title, body: n.message ?? '', url: n.link ?? '/', tag: `notification-${r.id}` });
      } catch (e: any) {
        this.logger.warn(`push non inviata: ${e?.message ?? e}`);
      }
      return { success: true, id: r.id };
    } catch (e: any) {
      this.logger.error(`notifica non creata: ${e?.message ?? e}`);
      return { success: false, error: 'Errore nella creazione della notifica' };
    }
  }

  async perUtente(companyId: number, tipo: string, id: number, soloNonLette = false, limite = 20) {
    return this.ds.query(
      `SELECT * FROM notifications WHERE company_id = $1 AND recipient_type = $2 AND recipient_id = $3 ${soloNonLette ? 'AND is_read = false' : ''}
        ORDER BY created_at DESC LIMIT $4`, [companyId, tipo, id, limite]);
  }

  async nonLette(companyId: number, tipo: string, id: number): Promise<number> {
    const [r] = await this.ds.query(
      'SELECT COUNT(*)::int AS n FROM notifications WHERE company_id = $1 AND recipient_type = $2 AND recipient_id = $3 AND is_read = false', [companyId, tipo, id]);
    return r.n;
  }

  /** Segna letta una notifica solo se appartiene a chi la chiede (nel PHP bastava l'id). */
  async segnaLetta(id: number, tipo: string, idUtente: number): Promise<boolean> {
    const [, n] = await this.ds.query('UPDATE notifications SET is_read = true WHERE id = $1 AND recipient_type = $2 AND recipient_id = $3', [id, tipo, idUtente]);
    return n > 0;
  }

  async segnaTutteLette(tipo: string, id: number): Promise<void> {
    await this.ds.query('UPDATE notifications SET is_read = true WHERE recipient_type = $1 AND recipient_id = $2 AND is_read = false', [tipo, id]);
  }

  /**
   * Link della notifica sul percorso di oggi: nel tempo sono stati salvati con
   * prefissi diversi ("/pamanager/public/employee/...", "/employee/...").
   */
  static url(link: string | null | undefined): string {
    const l = String(link ?? '').trim();
    if (!l) return '';
    const m = /\/(employee|admin|consulente-lavoro|superadmin)\/.*$/.exec(l);
    if (m) return m[0];
    if (/^https?:\/\//i.test(l)) return l;
    return '/' + l.replace(/^\/+/, '');
  }
}
