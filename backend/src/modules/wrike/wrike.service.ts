import { Injectable, Logger } from '@nestjs/common';
import { createCipheriv, createDecipheriv, createHash, randomBytes } from 'crypto';
import * as fs from 'fs';
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
    if (!segreto) segreto = this.segretoLegacy();
    return createHash('sha256').update(segreto).digest();
  }

  private segretoLegacy(): string {
    return `${process.env.LEGACY_DB_PASSWORD ?? ''}|${process.env.LEGACY_DB_NAME ?? ''}|wrike-v1`;
  }

  /**
   * Chiavi provate in lettura: quella attuale e, se diversa, quella del PHP
   * (in produzione il PHP non aveva APP_ENCRYPTION_KEY e cifrava con le
   * credenziali del database). Il tag GCM scarta la chiave sbagliata.
   */
  private chiaviLettura(): Buffer[] {
    const chiavi = [this.chiave()];
    if (process.env.LEGACY_DB_PASSWORD) {
      const legacy = createHash('sha256').update(this.segretoLegacy()).digest();
      if (!legacy.equals(chiavi[0])) chiavi.push(legacy);
    }
    return chiavi;
  }

  decifra(salvato: string): string | null {
    if (salvato.startsWith('plain:')) return Buffer.from(salvato.slice(6), 'base64').toString() || null;
    if (!salvato.startsWith('gcm:')) return null;
    const raw = Buffer.from(salvato.slice(4), 'base64');
    if (raw.length < 29) return null;
    for (const chiave of this.chiaviLettura()) {
      try {
        const d = createDecipheriv('aes-256-gcm', chiave, raw.subarray(0, 12));
        d.setAuthTag(raw.subarray(12, 28));
        return Buffer.concat([d.update(raw.subarray(28)), d.final()]).toString();
      } catch {
        // chiave successiva
      }
    }
    return null;
  }

  cifra(testo: string): string {
    const iv = randomBytes(12);
    const c = createCipheriv('aes-256-gcm', this.chiave(), iv);
    const ct = Buffer.concat([c.update(testo, 'utf8'), c.final()]);
    return 'gcm:' + Buffer.concat([iv, c.getAuthTag(), ct]).toString('base64');
  }

  /**
   * Collega l'account Wrike del consulente (Wrike::connect): il token si
   * prova sui datacenter e si salva cifrato. Correzione: l'errore del
   * database non esce al browser.
   */
  async collega(userId: number, token: string): Promise<{ success: true } | { success: false; error: string }> {
    const t = token.trim();
    if (t === '') return { success: false, error: 'Token mancante' };
    if (t.length < 20) return { success: false, error: 'Il token sembra troppo corto: copia il "Permanent access token" completo' };
    let trovato: { host: string; account_name: string; me_id: string | null } | null = null;
    let errore = 'Token non valido';
    for (const host of HOSTS) {
      try {
        const r = await this.api(t, host, 'GET', '/contacts', { me: 'true' });
        const c = r?.data?.[0];
        if (c) { trovato = { host, account_name: `${c.firstName ?? ''} ${c.lastName ?? ''}`.trim(), me_id: c.id ?? null }; break; }
      } catch (e: any) {
        errore = String(e?.message ?? e);
      }
    }
    if (!trovato) return { success: false, error: `Connessione fallita: ${errore}` };
    const esistente = await this.perUtente(userId);
    const config: Record<string, any> = { ...(esistente?.config ?? {}), host: trovato.host, account_name: trovato.account_name, me_id: trovato.me_id };
    if (config.on_hire === undefined) config.on_hire = 1;
    if (config.on_chat === undefined) config.on_chat = 0;
    try {
      await senzaIsolamento(() => esistente
        ? this.ds.query(`UPDATE user_integrations SET access_token = $2, config = $3, is_active = true, last_error = NULL, last_success_at = CURRENT_TIMESTAMP WHERE id = $1`,
          [esistente.id, this.cifra(t), JSON.stringify(config)])
        : this.ds.query(`INSERT INTO user_integrations (user_id, provider, access_token, config, is_active, last_error, last_success_at) VALUES ($1, 'wrike', $2, $3, true, NULL, CURRENT_TIMESTAMP)`,
          [userId, this.cifra(t), JSON.stringify(config)]));
    } catch (e: any) {
      this.logger.error(`salvataggio integrazione Wrike: ${e?.message ?? e}`);
      return { success: false, error: 'Errore salvataggio' };
    }
    return { success: true };
  }

  async scollega(userId: number): Promise<void> {
    await senzaIsolamento(() => this.ds.query(`DELETE FROM user_integrations WHERE user_id = $1 AND provider = 'wrike'`, [userId])).catch(() => undefined);
  }

  /** Cartelle dove creare le attivita' (Wrike::getFolders). */
  async cartelle(userId: number): Promise<Array<{ id: string; title: string }>> {
    const i = await this.perUtente(userId);
    if (!i || !i.token) return [];
    try {
      const r = await this.api(i.token, this.host(i), 'GET', '/folders');
      const out: Array<{ id: string; title: string }> = [];
      for (const f of r?.data ?? []) {
        if (f.scope === 'WsRoot') out.push({ id: f.id, title: '(Radice account)' });
        else if (f.scope === 'WsFolder') out.push({ id: f.id, title: f.title ?? f.id });
      }
      return out;
    } catch (e: any) {
      await this.segnaErrore(userId, String(e?.message ?? e));
      return [];
    }
  }

  async impostazioni(userId: number, cartella: string | null, nomeCartella: string | null, assunzioni: boolean, chat: boolean): Promise<{ success: true } | { success: false; error: string }> {
    const i = await this.perUtente(userId);
    if (!i) return { success: false, error: 'Integrazione non configurata' };
    const config = { ...i.config, folder_id: cartella || null, folder_name: nomeCartella || null, on_hire: assunzioni ? 1 : 0, on_chat: chat ? 1 : 0 };
    await senzaIsolamento(() => this.ds.query('UPDATE user_integrations SET config = $2 WHERE id = $1', [i.id, JSON.stringify(config)]));
    return { success: true };
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

  private prefissoAssunzione(stato: string): string {
    return ({
      awaiting_prospects: '🟡 Prospetti da caricare', prospects_review: '🔵 Prospetti da approvare', approved: '🟢 Da contrattualizzare',
      contract_pending: '✍️ Contratto da firmare', contract_signed: '✅ Firmato', rejected: '⛔ Prospetti rifiutati', cancelled: '✖️ Annullata',
    } as Record<string, string>)[stato] ?? 'Assunzione';
  }

  /**
   * Passaggio di una pratica di assunzione sul task del consulente
   * (Wrike::hireStep): titolo aggiornato, commento e, se serve, task chiuso.
   */
  async passaggioAssunzione(consulenteId: number, richiesta: number, commentoHtml: string, chiudi = false, nuovoStato: string | null = null): Promise<void> {
    try {
      const i = await this.perUtente(consulenteId);
      if (!i || !i.is_active || !i.config.on_hire || !i.token) return;
      const task = i.config.hire_tasks?.[String(richiesta)];
      if (!task) return;
      const host = this.host(i);
      if (nuovoStato) {
        const base = i.config.hire_titles?.[String(richiesta)];
        if (base) {
          await this.api(i.token, host, 'PUT', `/tasks/${encodeURIComponent(task)}`, { title: [...`${this.prefissoAssunzione(nuovoStato)} — ${base}`].slice(0, 250).join('') })
            .catch((e) => this.segnaErrore(consulenteId, String(e?.message ?? e)));
        }
      }
      await this.api(i.token, host, 'POST', `/tasks/${encodeURIComponent(task)}/comments`, { plainText: 'false', text: commentoHtml })
        .catch((e) => this.segnaErrore(consulenteId, String(e?.message ?? e)));
      if (chiudi) {
        await this.api(i.token, host, 'PUT', `/tasks/${encodeURIComponent(task)}`, { status: 'Completed' })
          .catch((e) => this.segnaErrore(consulenteId, String(e?.message ?? e)));
      }
    } catch (e: any) {
      this.logger.warn(`passaggioAssunzione: ${e?.message ?? e}`);
    }
  }

  /** Commento su un task; false se il task non esiste piu' (Wrike::addComment). */
  private async commenta(userId: number, task: string, html: string): Promise<boolean> {
    const i = await this.perUtente(userId);
    if (!i || !i.token || !i.is_active) return false;
    try {
      await this.api(i.token, this.host(i), 'POST', `/tasks/${encodeURIComponent(task)}/comments`, { plainText: 'false', text: html });
      return true;
    } catch (e: any) {
      await this.segnaErrore(userId, String(e?.message ?? e));
      return false;
    }
  }

  /**
   * Task per una richiesta di assunzione inviata al consulente
   * (Wrike::taskForHireRequest); al re-invio commenta il task esistente.
   */
  async taskPerAssunzione(consulenteId: number, richiesta: number, hr: Record<string, any>, reinvio: boolean, link: string): Promise<void> {
    try {
      const i = await this.perUtente(consulenteId);
      if (!i || !i.is_active || !i.config.on_hire) return;
      let chi = `${hr.employee_first_name ?? ''} ${hr.employee_last_name ?? ''}`.trim();
      if (chi === '') chi = `richiesta #${richiesta}`;
      const azienda = String(hr.employer_name ?? '');
      const valore = (v: unknown) => escapeHtml(v === null || v === undefined ? '—' : String(v));
      const esistente = i.config.hire_tasks?.[String(richiesta)];
      if (esistente) {
        const commento = `<b>${escapeHtml(azienda || "L'azienda")}</b> ha aggiornato la richiesta per <b>${escapeHtml(chi)}</b>. `
          + `Ore settimanali: ${valore(hr.weekly_hours)} — Inizio: ${valore(hr.start_date)}. <a href="${escapeHtml(link)}">Vedi i dati aggiornati</a>`;
        if (await this.commenta(consulenteId, esistente, commento)) return;
      }
      const base = chi + (azienda ? ` (${azienda})` : '');
      const titolo = `${this.prefissoAssunzione(String(hr.status ?? 'awaiting_prospects'))} — ${base}`;
      const descrizione = `<b>${escapeHtml(azienda)}</b> ha ${reinvio ? 'aggiornato la' : 'inviato una'} richiesta di simulazione/assunzione per <b>${escapeHtml(chi)}</b>.<br>`
        + `Mansioni: ${valore(hr.role_description)}<br>Ore settimanali: ${valore(hr.weekly_hours)} — Inizio: ${valore(hr.start_date)}<br><br>`
        + `<a href="${escapeHtml(link)}">Apri la richiesta sul gestionale</a>`;
      const id = await this.creaTask(consulenteId, titolo, descrizione, 'High');
      if (id) {
        await this.salvaConfig(consulenteId, 'hire_tasks', String(richiesta), id);
        await this.salvaConfig(consulenteId, 'hire_titles', String(richiesta), base);
      }
    } catch (e: any) {
      this.logger.warn(`taskPerAssunzione: ${e?.message ?? e}`);
    }
  }

  /**
   * Prospetti approvati (Wrike::hireApproved): titolo "Da contrattualizzare",
   * commento con i dati completi e documenti allegati al task.
   */
  async assunzioneApprovata(consulenteId: number, richiesta: number, righe: Array<[string, string]>, documenti: Array<{ percorso: string; nome: string }>): Promise<void> {
    try {
      const i = await this.perUtente(consulenteId);
      if (!i || !i.is_active || !i.config.on_hire || !i.token) return;
      const task = i.config.hire_tasks?.[String(richiesta)];
      if (!task) return;
      const host = this.host(i);
      const base = i.config.hire_titles?.[String(richiesta)];
      if (base) {
        await this.api(i.token, host, 'PUT', `/tasks/${encodeURIComponent(task)}`, { title: [...`${this.prefissoAssunzione('approved')} — ${base}`].slice(0, 250).join('') })
          .catch((e) => this.segnaErrore(consulenteId, String(e?.message ?? e)));
      }
      const elenco = righe.filter(([, v]) => String(v ?? '').trim() !== '').map(([k, v]) => `<li><b>${escapeHtml(k)}:</b> ${escapeHtml(String(v).trim())}</li>`).join('');
      await this.commenta(consulenteId, task, `✅ <b>Prospetti approvati dall'azienda — dati completi per il contratto:</b><ul>${elenco}</ul>Carica ora il contratto da firmare.`);
      for (const d of documenti) {
        const dati = fs.readFileSync(d.percorso);
        if (dati.length > 50 * 1024 * 1024) continue;
        const r = await fetch(`${host.replace(/\/+$/, '')}/api/v4/tasks/${encodeURIComponent(task)}/attachments`, {
          method: 'POST', body: dati, redirect: 'manual', signal: AbortSignal.timeout(30_000),
          headers: { Authorization: `Bearer ${i.token}`, 'X-Requested-With': 'XMLHttpRequest', 'X-File-Name': d.nome.replace(/[^\x20-\x7E]/g, '_'), 'Content-Type': 'application/octet-stream' },
        }).catch(() => null);
        if (!r || r.status >= 400) this.logger.warn(`allegato non caricato su Wrike (richiesta ${richiesta})`);
      }
    } catch (e: any) {
      this.logger.warn(`assunzioneApprovata: ${e?.message ?? e}`);
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
