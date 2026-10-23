import { Injectable, Logger } from '@nestjs/common';
import * as fs from 'fs';
import * as path from 'path';
import { DataSource } from 'typeorm';
import * as webpush from 'web-push';
import { cartellaStorage } from '../../config/app';
import { senzaIsolamento } from '../../common/database/isolamento';

export interface Avviso { title: string; body: string; url?: string; tag?: string; icon?: string }
interface Iscrizione { id: number; endpoint: string; p256dh: string; auth: string }

/**
 * Notifiche push (Web Push con VAPID), come PushNotification.php.
 *
 * Le chiavi sono le stesse del PHP (storage/vapid_keys.json): cambiarle
 * renderebbe inutili tutte le iscrizioni gia' fatte dai telefoni.
 * Iscrizioni scadute (404/410) vengono tolte, ogni invio va in push_logs.
 */
@Injectable()
export class PushService {
  private readonly logger = new Logger('Push');
  private chiavi: { public: string; private: string } | null = null;

  constructor(private readonly ds: DataSource) {}

  chiaviVapid(): { public: string; private: string } {
    if (this.chiavi) return this.chiavi;
    const file = cartellaStorage('vapid_keys.json');
    if (fs.existsSync(file)) {
      const k = JSON.parse(fs.readFileSync(file, 'utf8'));
      if (k?.public && k?.private) return (this.chiavi = k);
    }
    const nuove = webpush.generateVAPIDKeys();
    fs.mkdirSync(path.dirname(file), { recursive: true });
    fs.writeFileSync(file, JSON.stringify({ public: nuove.publicKey, private: nuove.privateKey }), { mode: 0o600 });
    this.logger.warn('Chiavi VAPID nuove generate: le iscrizioni push precedenti non funzioneranno piu\'');
    return (this.chiavi = { public: nuove.publicKey, private: nuove.privateKey });
  }

  chiavePubblica(): string {
    return this.chiaviVapid().public;
  }

  private soggetto(): string {
    const da = process.env.SMTP_FROM_EMAIL || '';
    if (/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(da)) return `mailto:${da}`;
    try {
      const host = new URL(process.env.APP_URL || '').hostname;
      if (host && !/^(localhost|127\.|192\.168\.|10\.|172\.)/.test(host)) return `mailto:noreply@${host}`;
    } catch { /* APP_URL assente */ }
    return 'mailto:noreply@example.com';
  }

  /** Salva o aggiorna l'iscrizione del dispositivo; l'azienda viene sempre dal server. */
  async salvaIscrizione(tipo: string, id: number, companyId: number, dati: { endpoint?: string; keys?: { p256dh?: string; auth?: string } }, userAgent: string | null) {
    if (!dati?.endpoint || !dati.keys?.p256dh || !dati.keys?.auth) return { success: false, error: 'Dati sottoscrizione non validi' };
    if (!/^https:\/\//.test(dati.endpoint) || dati.endpoint.length > 1000) return { success: false, error: 'Dati sottoscrizione non validi' };
    try {
      return await senzaIsolamento(async () => {
        const [esiste] = await this.ds.query('SELECT id FROM push_subscriptions WHERE endpoint = $1', [dati.endpoint]);
        if (esiste) {
          await this.ds.query(
            'UPDATE push_subscriptions SET company_id = $2, user_type = $3, user_id = $4, p256dh = $5, auth = $6, user_agent = $7 WHERE id = $1',
            [esiste.id, companyId, tipo, id, dati.keys!.p256dh, dati.keys!.auth, userAgent]);
          return { success: true, id: esiste.id };
        }
        const [r] = await this.ds.query(
          `INSERT INTO push_subscriptions (company_id, user_type, user_id, endpoint, p256dh, auth, user_agent) VALUES ($1, $2, $3, $4, $5, $6, $7) RETURNING id`,
          [companyId, tipo, id, dati.endpoint, dati.keys!.p256dh, dati.keys!.auth, userAgent]);
        return { success: true, id: r.id };
      });
    } catch (e: any) {
      // Il PHP restituiva al browser il messaggio del database: qui resta nel log.
      this.logger.error(`iscrizione non salvata: ${e?.message ?? e}`);
      return { success: false, error: 'Errore salvataggio' };
    }
  }

  /** Toglie un'iscrizione, solo se e' di chi lo chiede. */
  async rimuoviIscrizione(endpoint: string, tipo: string, id: number) {
    await senzaIsolamento(() => this.ds.query('DELETE FROM push_subscriptions WHERE endpoint = $1 AND user_type = $2 AND user_id = $3', [endpoint, tipo, id]));
    return { success: true };
  }

  /** Invia a tutti i dispositivi di un utente (rispetta il "no push" del dipendente). */
  async aUtente(tipo: string, id: number, avviso: Avviso) {
    return senzaIsolamento(async () => {
      if (tipo === 'employee') {
        const [p] = await this.ds.query('SELECT notify_push FROM employees WHERE id = $1', [id]);
        if (p && p.notify_push === false) return { success: true, sent: 0, message: 'Push disattivate dal dipendente' };
      }
      const iscrizioni: Iscrizione[] = await this.ds.query('SELECT id, endpoint, p256dh, auth FROM push_subscriptions WHERE user_type = $1 AND user_id = $2', [tipo, id]);
      if (!iscrizioni.length) return { success: true, sent: 0, message: 'Nessuna sottoscrizione attiva' };
      let sent = 0;
      let failed = 0;
      const errors: string[] = [];
      for (const s of iscrizioni) {
        const r = await this.manda(s, avviso);
        await this.registra(s.id, r.ok ? 'sent' : 'failed', r.errore);
        if (r.ok) sent++; else { failed++; errors.push(r.errore ?? 'Errore sconosciuto'); }
      }
      return { success: true, sent, failed, errors };
    });
  }

  /** Invia a una lista di iscrizioni gia' filtrate per azienda (broadcast, admin). */
  async aIscrizioni(sql: string, parametri: unknown[], avviso: Avviso) {
    return senzaIsolamento(async () => {
      const iscrizioni: Iscrizione[] = await this.ds.query(sql, parametri);
      let sent = 0;
      for (const s of iscrizioni) if ((await this.manda(s, avviso)).ok) sent++;
      return { success: true, sent };
    });
  }

  private async manda(s: Iscrizione, avviso: Avviso): Promise<{ ok: boolean; errore?: string }> {
    const k = this.chiaviVapid();
    try {
      await webpush.sendNotification(
        { endpoint: s.endpoint, keys: { p256dh: s.p256dh, auth: s.auth } },
        JSON.stringify(avviso),
        { TTL: 2419200, urgency: 'high', contentEncoding: 'aes128gcm', vapidDetails: { subject: this.soggetto(), publicKey: k.public, privateKey: k.private } },
      );
      return { ok: true };
    } catch (e: any) {
      const codice = e?.statusCode ?? 0;
      if (codice === 404 || codice === 410) await this.ds.query('DELETE FROM push_subscriptions WHERE id = $1', [s.id]);
      return { ok: false, errore: e?.body || e?.message || `HTTP ${codice}` };
    }
  }

  private async registra(idIscrizione: number, stato: string, errore?: string) {
    await this.ds.query('INSERT INTO push_logs (subscription_id, notification_id, status, error_message) VALUES ($1, NULL, $2, $3)', [idIscrizione, stato, errore ?? null])
      .catch(() => undefined);
  }
}
