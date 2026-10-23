import { Injectable, Logger } from '@nestjs/common';
import { createDecipheriv, createHash } from 'crypto';
import { DataSource } from 'typeorm';
import { senzaIsolamento } from '../../common/database/isolamento';
import { escapeHtml } from '../notifiche/mailer.service';

const HOSTS = ['https://www.wrike.com', 'https://app-eu.wrike.com', 'https://app-us2.wrike.com'];

interface Integrazione { id: number; user_id: number; is_active: boolean; token: string | null; config: Record<string, any> }

/**
 * Integrazione Wrike del consulente del lavoro (Wrike.php): attivita' sulla
 * sua board per i messaggi in chat (qui) e per le assunzioni (con l'area
 * consulente). Il token salvato dal PHP (AES-256-GCM) resta leggibile:
 * stessa chiave, da APP_ENCRYPTION_KEY o, come nel PHP, dalle credenziali
 * del vecchio database (LEGACY_DB_PASSWORD e LEGACY_DB_NAME).
 */
@Injectable()
export class WrikeService {
  private readonly logger = new Logger('Wrike');
  constructor(private readonly ds: DataSource) {}

  private chiave(): Buffer {
    let segreto = process.env.APP_ENCRYPTION_KEY || '';
    if (!segreto) segreto = `${process.env.LEGACY_DB_PASSWORD ?? ''}|${process.env.LEGACY_DB_NAME ?? ''}|wrike-v1`;
    return createHash('sha256').update(segreto).digest();
  }

  decifra(salvato: string): string | null {
    if (salvato.startsWith('plain:')) return Buffer.from(salvato.slice(6), 'base64').toString() || null;
    if (!salvato.startsWith('gcm:')) return null;
    const raw = Buffer.from(salvato.slice(4), 'base64');
    if (raw.length < 29) return null;
    try {
      const d = createDecipheriv('aes-256-gcm', this.chiave(), raw.subarray(0, 12));
      d.setAuthTag(raw.subarray(12, 28));
      return Buffer.concat([d.update(raw.subarray(28)), d.final()]).toString();
    } catch {
      return null;
    }
  }

  async perUtente(userId: number): Promise<Integrazione | null> {
    const [r] = await senzaIsolamento(() => this.ds.query(`SELECT * FROM user_integrations WHERE user_id = $1 AND provider = 'wrike'`, [userId]));
    if (!r) return null;
    let config: Record<string, any> = {};
    try { config = r.config ? JSON.parse(r.config) || {} : {}; } catch { config = {}; }
    return { id: r.id, user_id: r.user_id, is_active: !!r.is_active, token: r.access_token ? this.decifra(r.access_token) : null, config };
  }

  private host(i: Integrazione) { return i.config.host ?? HOSTS[0]; }

  /** Chiamata all'API v4; errore se la risposta non e' valida. */
  private async api(token: string, host: string, metodo: string, percorso: string, parametri: Record<string, string> = {}): Promise<any> {
    let url = host.replace(/\/+$/, '') + '/api/v4' + percorso;
    const init: RequestInit = { method: metodo, headers: { Authorization: `Bearer ${token}` }, redirect: 'manual', signal: AbortSignal.timeout(10_000) };
    if (metodo === 'GET') {
      if (Object.keys(parametri).length) url += '?' + new URLSearchParams(parametri).toString();
    } else {
      init.body = new URLSearchParams(parametri).toString();
      (init.headers as Record<string, string>)['Content-Type'] = 'application/x-www-form-urlencoded';
    }
    const r = await fetch(url, init);
    const json = await r.json().catch(() => null);
    if (r.status >= 400 || !json || typeof json !== 'object') throw new Error(json?.errorDescription ?? json?.error ?? `HTTP ${r.status}`);
    return json;
  }

  private async salvaConfig(userId: number, mappa: string, chiave: string, valore: string) {
    const i = await this.perUtente(userId);
    if (!i) return;
    const config = { ...i.config, [mappa]: { ...(i.config[mappa] ?? {}), [chiave]: valore } };
    await senzaIsolamento(() => this.ds.query('UPDATE user_integrations SET config = $2 WHERE id = $1', [i.id, JSON.stringify(config)])).catch(() => undefined);
  }

  private async segnaErrore(userId: number, errore: string) {
    this.logger.warn(`utente ${userId}: ${errore}`);
    await senzaIsolamento(() => this.ds.query(`UPDATE user_integrations SET last_error = $2 WHERE user_id = $1 AND provider = 'wrike'`, [userId, errore.slice(0, 250)])).catch(() => undefined);
  }

  /** Id Wrike dell'utente collegato (per assegnargli il task). */
  private async mioId(i: Integrazione): Promise<string | null> {
    if (i.config.me_id) return i.config.me_id;
    try {
      const r = await this.api(i.token!, this.host(i), 'GET', '/contacts', { me: 'true' });
      const id = r?.data?.[0]?.id ?? null;
      if (id) await senzaIsolamento(() => this.ds.query('UPDATE user_integrations SET config = $2 WHERE id = $1', [i.id, JSON.stringify({ ...i.config, me_id: id })]));
      return id;
    } catch {
      return null;
    }
  }

  async creaTask(userId: number, titolo: string, descrizione = '', importanza = 'Normal'): Promise<string | null> {
    const i = await this.perUtente(userId);
    if (!i || !i.token || !i.is_active) return null;
    const host = this.host(i);
    try {
      let cartella = i.config.folder_id ?? null;
      if (!cartella) {
        const r = await this.api(i.token, host, 'GET', '/folders');
        cartella = (r.data ?? []).find((f: any) => f.scope === 'WsRoot')?.id ?? null;
      }
      if (!cartella) throw new Error('Nessuna cartella Wrike disponibile');
      const p: Record<string, string> = { title: [...titolo].slice(0, 250).join(''), description: descrizione, importance: importanza };
      const me = await this.mioId(i);
      if (me) p.responsibles = JSON.stringify([me]);
      const r = await this.api(i.token, host, 'POST', `/folders/${encodeURIComponent(cartella)}/tasks`, p);
      const id = r?.data?.[0]?.id ?? null;
      if (id) await senzaIsolamento(() => this.ds.query('UPDATE user_integrations SET last_success_at = CURRENT_TIMESTAMP, last_error = NULL WHERE id = $1', [i.id])).catch(() => undefined);
      return id;
    } catch (e: any) {
      await this.segnaErrore(userId, String(e?.message ?? e));
      return null;
    }
  }

  /** Un'attivita' per conversazione finche' il task resta aperto (Wrike::taskForChatMessage). */
  async taskPerMessaggioChat(consulenteId: number, conversazione: number, mittente: string, anteprima: string, link: string): Promise<void> {
    try {
      const i = await this.perUtente(consulenteId);
      if (!i || !i.is_active || !i.config.on_chat) return;
      const esistente = i.config.chat_tasks?.[String(conversazione)];
      if (esistente && i.token) {
        try {
          const r = await this.api(i.token, this.host(i), 'GET', `/tasks/${encodeURIComponent(esistente)}`);
          if (['Active', 'Deferred'].includes(r?.data?.[0]?.status)) return;
        } catch { /* task eliminato: si ricrea */ }
      }
      const descrizione = `<b>${escapeHtml(mittente)}</b> ti ha scritto sul gestionale:<br><blockquote>${escapeHtml(anteprima)}</blockquote><a href="${escapeHtml(link)}">Apri la chat per rispondere</a>`;
      const id = await this.creaTask(consulenteId, `Chat gestionale — nuovo messaggio da ${mittente}`, descrizione, 'Normal');
      if (id) await this.salvaConfig(consulenteId, 'chat_tasks', String(conversazione), id);
    } catch (e: any) {
      this.logger.warn(`taskPerMessaggioChat: ${e?.message ?? e}`);
    }
  }
}
