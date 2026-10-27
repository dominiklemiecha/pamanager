import { Injectable, Logger } from '@nestjs/common';
import { createHash, randomBytes } from 'crypto';
import * as fs from 'fs';
import * as path from 'path';
import { DataSource } from 'typeorm';
import { cartellaStorage, urlPubblico } from '../../config/app';
import { AuditService, ContestoAudit } from '../audit/audit.service';
import type { UtenteCorrente } from '../auth/auth.types';
import { TenantService } from '../auth/tenant.service';
import { ContattiService } from '../contatti/contatti.service';
import { MailerService, escapeHtml } from '../notifiche/mailer.service';
import { NotificheService } from '../notifiche/notifiche.service';
import { PushService } from '../notifiche/push.service';
import { WrikeService } from '../wrike/wrike.service';
import { RealtimeService } from './realtime.service';

export const MINUTI_MODIFICA = 15;
const PRESENZA_OFFLINE = 120;
const PRESENZA_ASSENTE = 300;
const STAFF = ['admin', 'consulente_lavoro'];
export const ETICHETTE_RUOLO: Record<string, string> = {
  admin: 'HR', consulente_lavoro: 'Consulente del lavoro', employee: 'Dipendente',
};
const URL_CHAT: Record<string, string> = {
  admin: '/admin/chat.php',
  consulente_lavoro: '/consulente-lavoro/chat.php', employee: '/employee/chat.php',
};

export interface Chi { tipo: string; id: number }
export interface Persona { id: string; name: string; avatarUrl: string | null; role: string }

/** Data come date('c') del PHP: "2026-10-22T11:00:00+02:00" (ora di Roma). */
export function isoRoma(v: unknown): string | null {
  if (!v) return null;
  const d = v instanceof Date ? v : new Date(String(v));
  if (Number.isNaN(d.getTime())) return null;
  const p = Object.fromEntries(new Intl.DateTimeFormat('en-GB', { timeZone: 'Europe/Rome', year: 'numeric', month: '2-digit', day: '2-digit', hour: '2-digit', minute: '2-digit', second: '2-digit', hourCycle: 'h23', timeZoneName: 'longOffset' }).formatToParts(d).map((x) => [x.type, x.value]));
  const off = String(p.timeZoneName).replace('GMT', '') || '+00:00';
  return `${p.year}-${p.month}-${p.day}T${p.hour}:${p.minute}:${p.second}${off}`;
}

/** "LUCA BIANCHI" -> "Luca Bianchi" (CoraUi::name). */
export function nomeLeggibile(s: string | null | undefined): string {
  const t = String(s ?? '').trim();
  if (t === '' || t !== t.toUpperCase()) return t;
  return t.toLowerCase().replace(/(^|[\s'-])(\p{L})/gu, (_m, a, b) => a + b.toUpperCase());
}

const ms = (v: unknown) => (v ? new Date(v as any).getTime() : 0);
const chiave = (t: string, i: number) => `${t}:${i}`;

/**
 * Chat (Chat.php, CoraChat.php e le azioni di _chat-page.inc.php): conversazioni
 * dirette e di gruppo, messaggi, letture, reazioni, modifiche, inoltri,
 * preferenze, presenza e notifiche. Dati nella stessa forma usata dallo
 * script della pagina (cora-chat.js).
 *
 * Correzioni rispetto al PHP:
 *  - si avvia una chat, si crea un gruppo o si aggiunge qualcuno solo tra le
 *    persone dell'azienda corrente (prima admin e consulenti
 *    potevano scrivere a chiunque conoscendo l'id, anche di altre aziende);
 *  - gli allegati sono controllati anche nel contenuto, non solo nell'estensione;
 *  - gli errori del database non arrivano piu' al browser.
 */
@Injectable()
export class ChatService {
  private readonly logger = new Logger('Chat');
  constructor(
    private readonly ds: DataSource,
    private readonly contatti: ContattiService,
    private readonly tenant: TenantService,
    private readonly notifiche: NotificheService,
    private readonly push: PushService,
    private readonly mailer: MailerService,
    private readonly wrike: WrikeService,
    private readonly realtime: RealtimeService,
    private readonly audit: AuditService,
  ) {}

  chi(u: UtenteCorrente): Chi { return { tipo: u.tipo === 'employee' ? 'employee' : u.ruolo, id: u.id }; }

  // ─── Persone ───────────────────────────────────────────────────────────────

  async nome(tipo: string, id: number): Promise<string> {
    if (tipo === 'employee') {
      const [e] = await this.ds.query('SELECT first_name, last_name FROM employees WHERE id = $1', [id]);
      return e ? `${e.first_name} ${e.last_name}` : `Dipendente #${id}`;
    }
    const [x] = await this.ds.query('SELECT name FROM users WHERE id = $1', [id]);
    return x ? x.name : `Utente #${id}`;
  }

  async fotoDipendente(tipo: string, id: number): Promise<string | null> {
    if (tipo !== 'employee') return null;
    const [e] = await this.ds.query('SELECT photo_path FROM employees WHERE id = $1', [id]);
    return e?.photo_path || null;
  }

  /** Nome, foto e ruolo (CoraChat::person), con una cache per richiesta. */
  async persona(tipo: string, id: number, cache: Map<string, Persona> = new Map()): Promise<Persona> {
    const k = chiave(tipo, id);
    const c = cache.get(k);
    if (c) return c;
    let name = await this.nome(tipo, id);
    let foto: string | null = null;
    let role = ETICHETTE_RUOLO[tipo] ?? '';
    if (tipo === 'employee') {
      const [e] = await this.ds.query(
        'SELECT e.first_name, e.last_name, e.photo_path, e.position, d.name AS department_name FROM employees e LEFT JOIN departments d ON e.department_id = d.id WHERE e.id = $1', [id]);
      if (e) {
        name = nomeLeggibile(`${e.first_name ?? ''} ${e.last_name ?? ''}`.trim());
        if (e.photo_path) foto = '/' + String(e.photo_path).replace(/^\/+/, '');
        if (e.position) role = nomeLeggibile(String(e.position));
        else if (e.department_name) role = String(e.department_name);
      }
    }
    const p = { id: k, name, avatarUrl: foto, role };
    cache.set(k, p);
    return p;
  }

  // ─── Conversazioni ─────────────────────────────────────────────────────────

  /** I due posti fissi (non nei gruppi) e gli altri partecipanti, senza doppioni. */
  async partecipanti(conv: any): Promise<Chi[]> {
    const out: Chi[] = conv.conv_type === 'group' ? [] : [
      { tipo: conv.participant1_type, id: conv.participant1_id }, { tipo: conv.participant2_type, id: conv.participant2_id },
    ];
    const visti = new Set(out.map((p) => chiave(p.tipo, p.id)));
    const extra = await this.ds.query('SELECT participant_type, participant_id FROM chat_extra_participants WHERE conversation_id = $1 ORDER BY id', [conv.id]);
    for (const e of extra) {
      const k = chiave(e.participant_type, e.participant_id);
      if (visti.has(k)) continue;
      visti.add(k);
      out.push({ tipo: e.participant_type, id: e.participant_id });
    }
    return out;
  }

  /** La conversazione, se la persona ne fa parte (Chat::isParticipant). */
  async sePartecipa(convId: number, c: Chi): Promise<any | null> {
    const [r] = await this.ds.query(
      `SELECT * FROM chat_conversations cc WHERE cc.id = $1 AND (
         (cc.conv_type <> 'group' AND ((cc.participant1_type = $2 AND cc.participant1_id = $3) OR (cc.participant2_type = $2 AND cc.participant2_id = $3)))
         OR EXISTS (SELECT 1 FROM chat_extra_participants ep WHERE ep.conversation_id = cc.id AND ep.participant_type = $2 AND ep.participant_id = $3))`,
      [convId, c.tipo, c.id]);
    return r ?? null;
  }

  private nonLetto(m: string, r: string) {
    return `((${r}.last_read_at IS NULL AND ${m}.is_read = false) OR (${r}.last_read_at IS NOT NULL AND ${m}.created_at > ${r}.last_read_at))`;
  }

  /** Conversazioni della persona, come Chat::getConversations. */
  async conversazioni(c: Chi, companyId: number) {
    const interni = c.tipo === 'employee' ? ' AND cm.is_internal = false' : '';
    const righe = await this.ds.query(
      `SELECT cc.*,
         (SELECT COUNT(*)::int FROM chat_messages cm WHERE cm.conversation_id = cc.id AND ${this.nonLetto('cm', 'cr')}
            AND NOT (cm.sender_type = $1 AND cm.sender_id = $2)${interni}) AS unread_count,
         (SELECT COUNT(*)::int FROM chat_messages cm WHERE cm.conversation_id = cc.id AND ${this.nonLetto('cm', 'cr')}
            AND cm.mentions LIKE $4 AND NOT (cm.sender_type = $1 AND cm.sender_id = $2)${interni}) AS mention_count,
         (SELECT cm.attachment_name FROM chat_messages cm WHERE cm.conversation_id = cc.id${interni} ORDER BY cm.created_at DESC, cm.id DESC LIMIT 1) AS last_attachment,
         (SELECT cm.message FROM chat_messages cm WHERE cm.conversation_id = cc.id${interni} ORDER BY cm.created_at DESC, cm.id DESC LIMIT 1) AS last_message,
         (SELECT cm.created_at FROM chat_messages cm WHERE cm.conversation_id = cc.id${interni} ORDER BY cm.created_at DESC, cm.id DESC LIMIT 1) AS last_message_time
       FROM chat_conversations cc
       LEFT JOIN chat_reads cr ON cr.conversation_id = cc.id AND cr.user_type = $1 AND cr.user_id = $2
       WHERE cc.company_id = $3
         AND ((cc.conv_type <> 'group' AND ((cc.participant1_type = $1 AND cc.participant1_id = $2) OR (cc.participant2_type = $1 AND cc.participant2_id = $2)))
              OR EXISTS (SELECT 1 FROM chat_extra_participants ep WHERE ep.conversation_id = cc.id AND ep.participant_type = $1 AND ep.participant_id = $2))
       ORDER BY COALESCE(cc.last_message_at, cc.created_at) DESC, cc.id DESC`,
      [c.tipo, c.id, companyId, `%"${c.tipo}:${c.id}"%`]);

    for (const conv of righe) {
      const altri = (await this.partecipanti(conv)).filter((p) => !(p.tipo === c.tipo && p.id === c.id));
      let primo = altri[0] ?? { tipo: conv.participant1_type, id: conv.participant1_id };
      for (const p of altri) if (p.tipo === 'employee') { primo = p; break; }
      conv.other_type = primo.tipo;
      conv.other_id = primo.id;
      conv.others = altri;
    }
    const pref = await this.preferenze(c);
    let out = righe.map((conv: any) => ({ ...conv, pinned_at: pref.get(conv.id)?.pinned_at ?? null, hidden_at: pref.get(conv.id)?.hidden_at ?? null }))
      .filter((conv: any) => !conv.hidden_at || (conv.last_message_time && ms(conv.last_message_time) > ms(conv.hidden_at)));
    out.sort((a: any, b: any) => {
      if (a.pinned_at && !b.pinned_at) return -1;
      if (b.pinned_at && !a.pinned_at) return 1;
      if (a.pinned_at && b.pinned_at) return ms(b.pinned_at) - ms(a.pinned_at);
      return ms(b.last_message_at ?? b.created_at) - ms(a.last_message_at ?? a.created_at);
    });
    // Chat con dipendenti disattivati: nascoste finche' non tornano attivi.
    const filtrate = [];
    for (const conv of out) {
      if (conv.conv_type === 'group' || conv.other_type !== 'employee') { filtrate.push(conv); continue; }
      const [e] = await this.ds.query('SELECT is_active FROM employees WHERE id = $1', [conv.other_id]);
      if (e?.is_active) filtrate.push(conv);
    }
    out = filtrate;
    return out;
  }

  /** Riga dell'elenco (CoraChat::conversation). */
  async rigaElenco(conv: any, c: Chi, cache: Map<string, Persona>) {
    const membri: Persona[] = [];
    for (const p of conv.others ?? []) membri.push(await this.persona(p.tipo, p.id, cache));
    const gruppo = conv.conv_type === 'group' || membri.length > 1;
    const primo = membri[0] ?? await this.persona(conv.other_type, conv.other_id, cache);
    let titolo = primo.name;
    let avatar = primo.avatarUrl;
    if (gruppo) {
      titolo = String(conv.name ?? '').trim() !== '' ? String(conv.name) : membri.map((m) => m.name.split(' ')[0]).join(', ');
      avatar = conv.avatar_path ? this.urlAvatar(conv) : null;
    }
    let anteprima = String(conv.last_message ?? '').trim();
    if (anteprima === '' && conv.last_attachment) anteprima = /\.(jpe?g|png|gif|webp)$/i.test(conv.last_attachment) ? '🖼️ Foto' : `📎 ${conv.last_attachment}`;
    return {
      id: conv.id, type: gruppo ? 'group' : 'direct', title: titolo, avatarUrl: avatar,
      members: gruppo ? membri : [primo], memberCount: membri.length + 1,
      lastMessagePreview: anteprima !== '' ? [...anteprima.replace(/\s+/g, ' ')].slice(0, 300).join('') : null,
      lastMessageAt: isoRoma(conv.last_message_time), unread: Number(conv.unread_count ?? 0), mentioned: Number(conv.mention_count ?? 0) > 0,
      pinned: !!conv.pinned_at, createdBy: conv.created_by ?? null, canEdit: this.gestisceGruppo(conv, c),
    };
  }

  urlAvatar(conv: any): string {
    const h = createHash('md5').update(String(conv.avatar_path)).digest('hex').slice(0, 8);
    return `/api/v1/chat/file?group_avatar=${conv.id}&v=${h}`;
  }

  async elenco(c: Chi, companyId: number) {
    const cache = new Map<string, Persona>();
    const out = [];
    for (const conv of await this.conversazioni(c, companyId)) out.push(await this.rigaElenco(conv, c, cache));
    return out;
  }

  // ─── Contatti ──────────────────────────────────────────────────────────────

  /**
   * Chi si puo' contattare (Chat::canContact) limitato all'azienda corrente:
   * dipendenti attivi dell'azienda, staff dell'azienda o collegato.
   */
  async puoContattare(u: UtenteCorrente, tipo: string, id: number): Promise<boolean> {
    const me = this.chi(u);
    if (!id || (tipo === me.tipo && id === me.id)) return false;
    if (tipo === 'employee') {
      const [e] = await this.ds.query('SELECT is_active, company_id, department_id FROM employees WHERE id = $1', [id]);
      if (!e || !e.is_active || e.company_id !== u.companyId) return false;
      return true;
    }
    if (!STAFF.includes(tipo)) return false;
    if (me.tipo === 'employee' && tipo === 'employee') return true;
    const [x] = await this.ds.query(
      `SELECT u.id FROM users u WHERE u.id = $1 AND u.role = $2 AND u.is_active = true AND (u.company_id = $3
         OR u.is_global
         OR EXISTS (SELECT 1 FROM user_companies c WHERE c.user_id = u.id AND c.company_id = $3))`, [id, tipo, u.companyId]);
    return !!x;
  }

  /** Contatti per "Nuova chat" (CoraChat::contacts): ordine alfabetico. */
  async contattiCora(u: UtenteCorrente) {
    const gruppi = await this.contatti.perUtente(u);
    const cache = new Map<string, Persona>();
    const out: Persona[] = [];
    for (const [tipo, lista] of Object.entries(gruppi)) for (const x of lista ?? []) out.push(await this.persona(tipo, x.id, cache));
    return out.sort((a, b) => a.name.toLowerCase().localeCompare(b.name.toLowerCase(), 'en', { sensitivity: 'base' }) || 0);
  }

  // ─── Messaggi ──────────────────────────────────────────────────────────────

  async letture(convId: number): Promise<Map<string, Date>> {
    const out = new Map<string, Date>();
    for (const r of await this.ds.query('SELECT user_type, user_id, last_read_at FROM chat_reads WHERE conversation_id = $1', [convId])) {
      out.set(chiave(String(r.user_type), r.user_id), r.last_read_at);
    }
    return out;
  }

  /** Chi, oltre al mittente, ha letto il messaggio (Chat::seenBy). */
  async vistoDa(m: any, conv: any, letture: Map<string, Date>) {
    const out: Array<{ tipo: string; id: number; seen_at: unknown }> = [];
    const creato = ms(m.created_at);
    for (const p of await this.partecipanti(conv)) {
      if (p.tipo === m.sender_type && p.id === m.sender_id) continue;
      if (m.is_internal && p.tipo === 'employee') continue;
      const k = chiave(p.tipo, p.id);
      if (letture.has(k)) {
        if (ms(letture.get(k)) >= creato) out.push({ tipo: p.tipo, id: p.id, seen_at: letture.get(k) });
      } else if (m.is_read && conv.conv_type !== 'group') {
        out.push({ tipo: p.tipo, id: p.id, seen_at: m.read_at ?? null });
      }
    }
    return out;
  }

  /** Messaggi nella forma usata da cora-chat.js (CoraChat::messages). */
  async formatta(righe: any[], c: Chi, cache: Map<string, Persona> = new Map()) {
    if (!righe.length) return [];
    const convId = righe[0].conversation_id;
    const [conv] = await this.ds.query('SELECT * FROM chat_conversations WHERE id = $1', [convId]);
    const letture = await this.letture(convId);
    const idRisposte = [...new Set(righe.map((m) => Number(m.reply_to_id ?? 0)).filter(Boolean))];
    const risposte = new Map<number, any>();
    if (idRisposte.length) for (const r of await this.ds.query('SELECT * FROM chat_messages WHERE id = ANY($1::int[])', [idRisposte])) risposte.set(r.id, r);
    const me = chiave(c.tipo, c.id);
    const out = [];
    for (const m of righe) {
      const mitt = await this.persona(m.sender_type, m.sender_id, cache);
      const mio = mitt.id === me;
      let risposta = null;
      if (m.reply_to_id) {
        const r = risposte.get(m.reply_to_id);
        if (r && !(c.tipo === 'employee' && r.is_internal)) {
          const rp = await this.persona(r.sender_type, r.sender_id, cache);
          const corpo = String(r.message).trim() !== '' ? String(r.message) : `📎 ${r.attachment_name || 'Allegato'}`;
          risposta = { id: m.reply_to_id, senderId: rp.id, senderName: rp.name, body: [...corpo].slice(0, 120).join('') };
        } else {
          risposta = { id: m.reply_to_id, senderId: null, senderName: '', body: 'Messaggio eliminato' };
        }
      }
      const reazioni = [];
      let r: Record<string, string[]> = {};
      try { r = m.reactions ? JSON.parse(m.reactions) || {} : {}; } catch { r = {}; }
      for (const [emoji, chi] of Object.entries(r)) {
        if (!chi?.length) continue;
        const utenti = [];
        for (const k of chi) { const [t, i] = String(k).split(':'); utenti.push((await this.persona(t, Number(i) || 0, cache)).name); }
        reazioni.push({ emoji, count: chi.length, mine: chi.includes(me), users: utenti });
      }
      let allegato = null;
      if (m.attachment_path) {
        const p = String(m.attachment_path);
        let mime = String(m.attachment_mime ?? '');
        if (p.startsWith('giphy:')) {
          allegato = { name: String(m.attachment_name || 'GIF'), type: 'image/gif', size: 0, isImage: true, url: p.slice(6) };
        } else {
          if (/^Messaggio vocale/i.test(String(m.attachment_name ?? '')) && !mime.startsWith('audio/')) mime = `audio/${mime.includes('mp4') ? 'mp4' : 'webm'}`;
          allegato = {
            name: String(m.attachment_name || 'Allegato'), type: mime || null, size: Number(m.attachment_size ?? 0) || null,
            isImage: /^image\/(jpeg|png|gif|webp)$/.test(mime), url: `/api/v1/chat/file?download_attachment=${m.id}`,
          };
        }
      }
      const visto = [];
      if (mio && conv) {
        for (const s of await this.vistoDa(m, conv, letture)) {
          const sp = await this.persona(s.tipo, s.id, cache);
          visto.push({ id: sp.id, name: sp.name, avatarUrl: sp.avatarUrl, seenAt: isoRoma(s.seen_at) });
        }
      }
      let menzioni: unknown = [];
      try { menzioni = m.mentions ? JSON.parse(m.mentions) || [] : []; } catch { menzioni = []; }
      out.push({
        id: m.id, body: String(m.message), senderId: mitt.id, senderName: mitt.name, senderAvatarUrl: mitt.avatarUrl, mine: mio,
        createdAt: isoRoma(m.created_at), editedAt: isoRoma(m.edited_at), forwarded: !!m.forwarded, internal: !!m.is_internal,
        mentions: menzioni, replyTo: risposta, attachment: allegato, kind: 'testo', reactions: reazioni, seenBy: visto,
      });
    }
    return out;
  }

  /** Pagina di messaggi in ordine cronologico (Chat::getMessagesPage). */
  async pagina(convId: number, interni: boolean, prima: number | null, dopo: number | null, limite: number) {
    let where = 'conversation_id = $1';
    const p: unknown[] = [convId];
    if (!interni) where += ' AND is_internal = false';
    if (dopo) {
      p.push(dopo);
      return this.ds.query(`SELECT * FROM chat_messages WHERE ${where} AND id > $2 ORDER BY id ASC LIMIT 200`, p);
    }
    if (prima) { p.push(prima); where += ` AND id < $${p.length}`; }
    p.push(limite);
    return (await this.ds.query(`SELECT * FROM chat_messages WHERE ${where} ORDER BY id DESC LIMIT $${p.length}`, p)).reverse();
  }

  async messaggi(u: UtenteCorrente, convId: number, limite: number, prima: number | null, dopo: number | null) {
    const c = this.chi(u);
    let dopoValido = dopo;
    if (dopo) {
      const [x] = await this.ds.query('SELECT 1 FROM chat_messages WHERE id = $1 AND conversation_id = $2', [dopo, convId]);
      if (!x) dopoValido = null;
    }
    let righe = await this.pagina(convId, c.tipo !== 'employee', prima, dopoValido, limite);
    if (dopoValido) {
      // Insieme ai nuovi, i messaggi corretti negli ultimi 20 minuti.
      const corretti = await this.ds.query(
        `SELECT * FROM chat_messages WHERE conversation_id = $1 AND id <= $2 AND edited_at IS NOT NULL AND edited_at > CURRENT_TIMESTAMP - INTERVAL '20 minutes'
           ${c.tipo === 'employee' ? 'AND is_internal = false' : ''} ORDER BY id`, [convId, dopoValido]);
      righe = [...corretti, ...righe];
    }
    return this.formatta(righe, c);
  }

  async messaggio(id: number): Promise<any | null> {
    const [m] = await this.ds.query('SELECT * FROM chat_messages WHERE id = $1', [id]);
    return m ?? null;
  }

  /** Messaggio appena salvato nella forma della pagina. */
  async messaggioFormattato(id: number, c: Chi) {
    const m = await this.messaggio(id);
    return m ? (await this.formatta([m], c))[0] ?? null : null;
  }

  /** Admin per "Scrivi all'HR": prima chi e' collegato all'azienda, poi il primo attivo. */
  async adminPerHR(companyId: number): Promise<number | null> {
    const [a] = await this.ds.query(
      `SELECT u.id FROM users u LEFT JOIN user_companies uc ON uc.user_id = u.id AND uc.company_id = $1
        WHERE u.role = 'admin' AND u.is_active = true ORDER BY (uc.user_id IS NOT NULL) DESC, u.id ASC LIMIT 1`, [companyId]);
    return a?.id ?? null;
  }

  async messaggioAccessibile(id: number, c: Chi): Promise<any | null> {
    const [m] = await this.ds.query('SELECT * FROM chat_messages WHERE id = $1', [id]);
    if (!m || !(await this.sePartecipa(m.conversation_id, c))) return null;
    if (m.is_internal && c.tipo === 'employee') return null;
    return m;
  }

  // ─── Letture ───────────────────────────────────────────────────────────────

  async segnaLetta(convId: number, c: Chi) {
    const [pr] = await this.ds.query('SELECT last_read_at FROM chat_reads WHERE conversation_id = $1 AND user_type = $2 AND user_id = $3', [convId, c.tipo, c.id]);
    const [ul] = await this.ds.query('SELECT MAX(created_at) AS m FROM chat_messages WHERE conversation_id = $1 AND NOT (sender_type = $2 AND sender_id = $3)', [convId, c.tipo, c.id]);
    const [{ adesso }] = await this.ds.query('SELECT CURRENT_TIMESTAMP AS adesso');
    await this.ds.query(
      `INSERT INTO chat_reads (conversation_id, user_type, user_id, last_read_at) VALUES ($1, $2, $3, $4)
       ON CONFLICT (conversation_id, user_type, user_id) DO UPDATE SET last_read_at = EXCLUDED.last_read_at`, [convId, c.tipo, c.id, adesso]);
    if (ul?.m && (!pr || ms(ul.m) > ms(pr.last_read_at))) {
      await this.emitConversazione(convId, 'chat.conversation.read', () => ({ conversationId: convId, userId: chiave(c.tipo, c.id), readAt: isoRoma(adesso) }));
    }
    await this.ds.query(
      `UPDATE chat_messages SET is_read = true, read_at = CURRENT_TIMESTAMP WHERE conversation_id = $1 AND NOT (sender_type = $2 AND sender_id = $3)
         AND is_read = false${c.tipo === 'employee' ? ' AND is_internal = false' : ''}`, [convId, c.tipo, c.id]);
  }

  async segnaTutteLette(c: Chi, companyId: number) {
    for (const conv of await this.conversazioni(c, companyId)) if (Number(conv.unread_count) > 0) await this.segnaLetta(conv.id, c);
  }

  // ─── Preferenze ────────────────────────────────────────────────────────────

  async preferenze(c: Chi): Promise<Map<number, { pinned_at: Date | null; hidden_at: Date | null }>> {
    const out = new Map();
    for (const r of await this.ds.query('SELECT conversation_id, pinned_at, hidden_at FROM chat_conversation_prefs WHERE user_type = $1 AND user_id = $2', [c.tipo, c.id])) {
      out.set(r.conversation_id, r);
    }
    return out;
  }

  async impostaPreferenza(convId: number, c: Chi, campo: 'pinned_at' | 'hidden_at', valore: 'adesso' | null) {
    await this.ds.query(
      `INSERT INTO chat_conversation_prefs (conversation_id, user_type, user_id, ${campo}) VALUES ($1, $2, $3, ${valore ? 'CURRENT_TIMESTAMP' : 'NULL'})
       ON CONFLICT (conversation_id, user_type, user_id) DO UPDATE SET ${campo} = EXCLUDED.${campo}`, [convId, c.tipo, c.id]);
  }

  // ─── Invio ─────────────────────────────────────────────────────────────────

  async conversazioneDiretta(u: UtenteCorrente, tipo: string, id: number): Promise<{ success: boolean; id?: number; conversation?: any; error?: string }> {
    let [t1, i1, t2, i2] = [this.chi(u).tipo, u.id, tipo, id];
    if (t1 > t2 || (t1 === t2 && i1 > i2)) [t1, i1, t2, i2] = [t2, i2, t1, i1];
    const [esiste] = await this.ds.query(
      `SELECT * FROM chat_conversations WHERE company_id = $1 AND participant1_type = $2 AND participant1_id = $3 AND participant2_type = $4 AND participant2_id = $5
         AND conv_type <> 'group' ORDER BY id LIMIT 1`, [u.companyId, t1, i1, t2, i2]);
    if (esiste) return { success: true, id: esiste.id, conversation: esiste };
    try {
      const [nuova] = await this.ds.query(
        `INSERT INTO chat_conversations (company_id, participant1_type, participant1_id, participant2_type, participant2_id) VALUES ($1, $2, $3, $4, $5) RETURNING *`,
        [u.companyId, t1, i1, t2, i2]);
      return { success: true, id: nuova.id, conversation: nuova };
    } catch {
      return { success: false, error: 'Errore nella creazione della conversazione' };
    }
  }

  async invia(u: UtenteCorrente, convId: number, testo: string, opz: {
    allegato?: { path: string; name: string; size: number; mime: string } | null; interno?: boolean; risposta?: number | null; inoltrato?: boolean; clientId?: string | null; menzioni?: string[];
  } = {}): Promise<{ success: boolean; id?: number; duplicate?: boolean; error?: string }> {
    const c = this.chi(u);
    if (String(testo ?? '').trim() === '' && !opz.allegato) return { success: false, error: 'Messaggio vuoto' };
    const [conv] = await this.ds.query('SELECT * FROM chat_conversations WHERE id = $1', [convId]);
    if (!conv) return { success: false, error: 'Conversazione non trovata' };
    const parti = await this.partecipanti(conv);
    if (!parti.some((p) => p.tipo === c.tipo && p.id === c.id)) return { success: false, error: 'Non sei un partecipante di questa conversazione' };
    // Le note interne sono dello staff: un dipendente non puo' scriverne.
    const interno = c.tipo !== 'employee' && !!opz.interno;
    let clientId = opz.clientId ?? null;
    if (clientId !== null && !/^[A-Za-z0-9_-]{8,64}$/.test(clientId)) clientId = null;
    if (clientId) {
      const [gia] = await this.ds.query('SELECT id FROM chat_messages WHERE conversation_id = $1 AND sender_type = $2 AND sender_id = $3 AND client_id = $4', [convId, c.tipo, c.id, clientId]);
      if (gia) return { success: true, id: gia.id, duplicate: true };
    }
    let risposta = opz.risposta ?? null;
    if (risposta) {
      const [ok] = await this.ds.query('SELECT 1 FROM chat_messages WHERE id = $1 AND conversation_id = $2', [risposta, convId]);
      if (!ok) risposta = null;
    }
    const validi = new Set(parti.map((p) => chiave(p.tipo, p.id)));
    const menzioni = [...new Set((opz.menzioni ?? []).filter((k) => validi.has(k) && k !== chiave(c.tipo, c.id)))];
    let id: number;
    try {
      [{ id }] = await this.ds.query(
        `INSERT INTO chat_messages (company_id, conversation_id, sender_type, sender_id, message, attachment_path, attachment_name, attachment_size, attachment_mime,
            is_read, is_internal, reply_to_id, forwarded, client_id, mentions)
         VALUES ($1, $2, $3, $4, $5, $6, $7, $8, $9, false, $10, $11, $12, $13, $14) RETURNING id`,
        [conv.company_id, convId, c.tipo, c.id, String(testo ?? '').trim(), opz.allegato?.path ?? null, opz.allegato?.name ?? null, opz.allegato?.size ?? null,
          opz.allegato?.mime ?? null, interno, risposta, !!opz.inoltrato, clientId, menzioni.length ? JSON.stringify(menzioni) : null]);
    } catch (e: any) {
      this.logger.error(`invio non riuscito: ${e?.message ?? e}`);
      return { success: false, error: 'Errore durante l\'invio del messaggio' };
    }
    await this.ds.query('UPDATE chat_conversations SET last_message_at = CURRENT_TIMESTAMP WHERE id = $1', [convId]);
    await this.ds.query(
      `INSERT INTO chat_reads (conversation_id, user_type, user_id, last_read_at) VALUES ($1, $2, $3, CURRENT_TIMESTAMP)
       ON CONFLICT (conversation_id, user_type, user_id) DO UPDATE SET last_read_at = CURRENT_TIMESTAMP`, [convId, c.tipo, c.id]).catch(() => undefined);

    if (this.realtime.attivo()) {
      try {
        const [riga] = await this.ds.query('SELECT * FROM chat_messages WHERE id = $1', [id]);
        const per: Record<string, unknown> = {};
        for (const p of parti) {
          if (interno && p.tipo === 'employee') continue;
          const mio = p.tipo === c.tipo && p.id === c.id;
          per[chiave(p.tipo, p.id)] = { conversationId: convId, clientId: mio ? clientId : null, message: (await this.formatta([riga], p))[0] ?? null };
        }
        await this.realtime.emitEach(conv.company_id, 'chat.message.created', per);
      } catch (e: any) { this.logger.warn(`realtime: ${e?.message ?? e}`); }
    }
    this.notificaMessaggio(conv, parti, c, String(testo ?? ''), interno, menzioni).catch((e) => this.logger.warn(`notifiche: ${e?.message ?? e}`));
    return { success: true, id };
  }

  /** Notifica, push, email e (consulente) Wrike a chi riceve il messaggio. */
  private async notificaMessaggio(conv: any, parti: Chi[], da: Chi, testo: string, interno: boolean, menzioni: string[]) {
    const destinatari = parti.filter((p) => !(p.tipo === da.tipo && p.id === da.id) && !(interno && p.tipo === 'employee'));
    const guardano = new Set<string>();
    if (this.realtime.attivo()) {
      const st = await this.realtime.guardano(conv.company_id, conv.id, destinatari.map((r) => chiave(r.tipo, r.id)));
      for (const [k, v] of Object.entries(st)) if (v?.guarda) guardano.add(k);
    }
    const mittente = await this.nome(da.tipo, da.id);
    const anteprima = [...testo].length > 50 ? [...testo].slice(0, 47).join('') + '...' : testo;
    for (const r of destinatari) {
      try {
        const k = chiave(r.tipo, r.id);
        const link = `${URL_CHAT[r.tipo] ?? '/'}?conv=${conv.id}`;
        let titolo = (interno ? 'Nota interna da ' : 'Nuovo messaggio da ') + mittente;
        if (menzioni.includes(k)) titolo = `${mittente} ti ha menzionato`;
        if (conv.conv_type === 'group' && conv.name) titolo += ` · ${conv.name}`;
        await this.notifiche.crea(conv.company_id, { recipient_type: r.tipo, recipient_id: r.id, type: 'new_message', title: titolo, message: anteprima, link });
        if (r.tipo === 'consulente_lavoro') await this.wrike.taskPerMessaggioChat(r.id, conv.id, mittente, anteprima, urlPubblico(link));
        if (!guardano.has(k)) {
          await this.push.aUtente(r.tipo, r.id, { title: titolo, body: anteprima, url: link, tag: `chat-${conv.id}`, icon: '/assets/images/icon.php?size=192' }).catch(() => undefined);
        }
        await this.emailMessaggio(r, mittente, anteprima, titolo, interno);
      } catch (e: any) {
        this.logger.warn(`notifica a ${r.tipo}:${r.id}: ${e?.message ?? e}`);
      }
    }
  }

  private async emailMessaggio(r: Chi, mittente: string, anteprima: string, titolo: string, interno: boolean) {
    const [d] = r.tipo === 'employee'
      ? await this.ds.query(`SELECT TRIM(first_name || ' ' || last_name) AS name, email FROM employees WHERE id = $1`, [r.id])
      : await this.ds.query('SELECT name, email FROM users WHERE id = $1', [r.id]);
    if (!d?.email) return;
    const area = r.tipo === 'employee' ? 'employee' : r.tipo === 'consulente_lavoro' ? 'consulente-lavoro' : 'admin';
    const link = urlPubblico(`/${area}/chat.php`);
    const cosa = interno ? 'una <strong>nota interna</strong> (invisibile al dipendente) da' : 'un nuovo messaggio da';
    const html = `<p>Ciao ${escapeHtml(d.name)},</p><p>Hai ricevuto ${cosa} <strong>${escapeHtml(mittente)}</strong>:</p>`
      + `<blockquote style="border-left:3px solid #ccc;padding-left:1em;color:#444;">${escapeHtml(anteprima).replace(/\r?\n/g, '<br />\n')}</blockquote>`
      + `<p><a href="${link}">Apri la chat per rispondere</a></p>`;
    const testo = `Ciao ${d.name},\n\n${titolo}:\n\n${anteprima}\n\nApri la chat: ${link}`;
    if (r.tipo === 'employee') await this.mailer.inviaADipendente(r.id, titolo, html, testo);
    else await this.mailer.invia(d.email, d.name, titolo, html, testo);
  }

  // ─── Reazioni, modifica, eliminazione, inoltro ─────────────────────────────

  async reazione(u: UtenteCorrente, id: number, emoji: string) {
    const c = this.chi(u);
    const e = String(emoji ?? '').trim();
    if (e === '' || [...e].length > 16) return { success: false, error: 'Reazione non valida' };
    const m = await this.messaggioAccessibile(id, c);
    if (!m) return { success: false, error: 'Messaggio non trovato' };
    const me = chiave(c.tipo, c.id);
    let r: Record<string, string[]> = {};
    try { r = m.reactions ? JSON.parse(m.reactions) || {} : {}; } catch { r = {}; }
    const lista = r[e] ?? [];
    const nuova = lista.includes(me) ? lista.filter((x) => x !== me) : [...lista, me];
    if (nuova.length) r[e] = nuova; else delete r[e];
    await this.ds.query('UPDATE chat_messages SET reactions = $2 WHERE id = $1', [id, Object.keys(r).length ? JSON.stringify(r) : null]);
    await this.emitAggiornato(id);
    const [riga] = await this.ds.query('SELECT * FROM chat_messages WHERE id = $1', [id]);
    return { success: true, reactions: (await this.formatta([riga], c))[0]?.reactions ?? [] };
  }

  async modifica(u: UtenteCorrente, id: number, testo: string) {
    const c = this.chi(u);
    const t = String(testo ?? '').trim();
    if (t === '') return { success: false, error: 'Il messaggio non può essere vuoto' };
    const m = await this.messaggioAccessibile(id, c);
    if (!m || m.sender_type !== c.tipo || m.sender_id !== c.id) return { success: false, error: 'Puoi modificare solo i tuoi messaggi' };
    if (Date.now() - ms(m.created_at) > MINUTI_MODIFICA * 60_000) return { success: false, error: 'Il tempo per modificare il messaggio è scaduto' };
    if ([...t].length > 20000) return { success: false, error: 'Il messaggio supera i 20.000 caratteri' };
    await this.ds.query('UPDATE chat_messages SET message = $2, edited_at = CURRENT_TIMESTAMP WHERE id = $1', [id, t]);
    await this.emitAggiornato(id);
    const [riga] = await this.ds.query('SELECT * FROM chat_messages WHERE id = $1', [id]);
    return { success: true, message: (await this.formatta([riga], c))[0] ?? null };
  }

  async elimina(u: UtenteCorrente, id: number) {
    const c = this.chi(u);
    const m = await this.messaggioAccessibile(id, c);
    if (!m || m.sender_type !== c.tipo || m.sender_id !== c.id) return { success: false, error: 'Puoi eliminare solo i tuoi messaggi' };
    const [conv] = await this.ds.query('SELECT * FROM chat_conversations WHERE id = $1', [m.conversation_id]);
    if (m.is_read || (conv && (await this.vistoDa(m, conv, await this.letture(m.conversation_id))).length > 0)) return { success: false, error: 'Il messaggio è già stato letto' };
    await this.ds.query('DELETE FROM chat_messages WHERE id = $1', [id]);
    const p = this.fileAllegato(m.attachment_path);
    if (p) fs.rmSync(p, { force: true });
    await this.emitConversazione(m.conversation_id, 'chat.message.deleted', () => ({ conversationId: m.conversation_id, messageId: id }));
    return { success: true };
  }

  async inoltra(u: UtenteCorrente, id: number, conversazioni: number[]) {
    const c = this.chi(u);
    const m = await this.messaggioAccessibile(id, c);
    if (!m) return { success: false, error: 'Messaggio non trovato' };
    let inviati = 0;
    for (const convId of [...new Set(conversazioni)]) {
      if (!convId || !(await this.sePartecipa(convId, c))) continue;
      let all: { path: string; name: string; size: number; mime: string } | null = null;
      if (String(m.attachment_path ?? '').startsWith('giphy:')) {
        all = { path: m.attachment_path, name: m.attachment_name, size: Number(m.attachment_size ?? 0), mime: m.attachment_mime };
      } else {
        const sorgente = this.fileAllegato(m.attachment_path);
        if (sorgente) {
          const dir = cartellaStorage('chat-attachments', String(convId));
          fs.mkdirSync(dir, { recursive: true });
          const dest = path.join(dir, `${randomBytes(12).toString('hex')}${path.extname(sorgente).toLowerCase()}`);
          try { fs.copyFileSync(sorgente, dest); all = { path: dest, name: m.attachment_name, size: Number(m.attachment_size ?? 0), mime: m.attachment_mime }; } catch { all = null; }
        }
      }
      const r = await this.invia(u, convId, String(m.message), { allegato: all, inoltrato: true });
      if (r.success) inviati++;
    }
    return inviati ? { success: true, sent: inviati } : { success: false, error: 'Nessuna chat valida' };
  }

  /** Percorso dell'allegato solo se sta dentro lo storage della chat. */
  fileAllegato(salvato: unknown): string | null {
    const s = String(salvato ?? '');
    if (!s || s.startsWith('giphy:')) return null;
    const legacy = (process.env.STORAGE_LEGACY_DIR || '/var/www/html/storage').replace(/\/+$/, '') + '/';
    const radice = cartellaStorage('chat-attachments');
    const p = s.startsWith(legacy) ? path.resolve(cartellaStorage(), ...s.slice(legacy.length).split('/')) : path.resolve(s);
    if (!p.startsWith(radice + path.sep)) return null;
    return fs.existsSync(p) && fs.statSync(p).isFile() ? p : null;
  }

  // ─── Gruppi ────────────────────────────────────────────────────────────────

  gestisceGruppo(conv: any, c: Chi): boolean {
    if (conv.conv_type !== 'group') return false;
    return conv.created_by === chiave(c.tipo, c.id) || c.tipo === 'admin';
  }

  async creaGruppo(u: UtenteCorrente, nome: string, membri: string[]) {
    const c = this.chi(u);
    const n = [...String(nome ?? '')].slice(0, 120).join('').trim();
    if (n === '') return { success: false, error: 'Dai un nome al gruppo' };
    const validi = new Map<string, Chi>();
    for (const m of membri) {
      const [t, i] = String(m).split(':');
      const id = Number.parseInt(i ?? '', 10) || 0;
      if (!t || !id || (t === c.tipo && id === c.id)) continue;
      if (!(await this.puoContattare(u, t, id))) continue;
      validi.set(chiave(t, id), { tipo: t, id });
    }
    if (!validi.size) return { success: false, error: 'Scegli almeno una persona' };
    const [{ id }] = await this.ds.query(
      `INSERT INTO chat_conversations (company_id, participant1_type, participant1_id, participant2_type, participant2_id, conv_type, name, created_by, group_key, last_message_at)
       VALUES ($1, $2, $3, $2, $3, 'group', $4, $5, $6, CURRENT_TIMESTAMP) RETURNING id`,
      [u.companyId, c.tipo, c.id, n, chiave(c.tipo, c.id), randomBytes(10).toString('hex')]);
    for (const p of [c, ...validi.values()]) {
      await this.ds.query('INSERT INTO chat_extra_participants (conversation_id, participant_type, participant_id, added_by_type, added_by_id) VALUES ($1, $2, $3, $4, $5)', [id, p.tipo, p.id, c.tipo, c.id]);
    }
    await this.emitCambiata(id);
    return { success: true, id };
  }

  async aggiungiAlGruppo(u: UtenteCorrente, convId: number, membri: string[]) {
    const c = this.chi(u);
    const conv = await this.sePartecipa(convId, c);
    if (!conv || conv.conv_type !== 'group') return { success: false, error: 'Gruppo non trovato' };
    const gia = new Set((await this.partecipanti(conv)).map((p) => chiave(p.tipo, p.id)));
    let n = 0;
    for (const m of membri) {
      const [t, i] = String(m).split(':');
      const id = Number.parseInt(i ?? '', 10) || 0;
      if (!t || !id || gia.has(chiave(t, id))) continue;
      if (!(await this.puoContattare(u, t, id))) continue;
      await this.ds.query('INSERT INTO chat_extra_participants (conversation_id, participant_type, participant_id, added_by_type, added_by_id) VALUES ($1, $2, $3, $4, $5)', [convId, t, id, c.tipo, c.id]);
      gia.add(chiave(t, id));
      n++;
    }
    if (!n) return { success: false, error: 'Nessuna persona da aggiungere' };
    await this.emitCambiata(convId);
    return { success: true };
  }

  async togliDalGruppo(u: UtenteCorrente, convId: number, tipo: string, id: number) {
    const c = this.chi(u);
    const conv = await this.sePartecipa(convId, c);
    if (!conv || conv.conv_type !== 'group') return { success: false, error: 'Gruppo non trovato' };
    const se = tipo === c.tipo && id === c.id;
    if (!se && !this.gestisceGruppo(conv, c)) return { success: false, error: 'Solo chi ha creato il gruppo può togliere persone' };
    if (!se && conv.created_by === chiave(tipo, id)) return { success: false, error: 'Chi ha creato il gruppo non si può togliere' };
    const prima = (await this.partecipanti(conv)).map((p) => chiave(p.tipo, p.id));
    await this.ds.query('DELETE FROM chat_extra_participants WHERE conversation_id = $1 AND participant_type = $2 AND participant_id = $3', [convId, tipo, id]);
    const [{ n }] = await this.ds.query('SELECT COUNT(*)::int AS n FROM chat_extra_participants WHERE conversation_id = $1', [convId]);
    if (n === 0) {
      await this.ds.query('DELETE FROM chat_conversations WHERE id = $1', [convId]);
      return { success: true };
    }
    await this.emitCambiata(convId, prima);
    return { success: true };
  }

  async fotoGruppo(u: UtenteCorrente, convId: number, f: { buffer: Buffer; size: number } | undefined) {
    const c = this.chi(u);
    const conv = await this.sePartecipa(convId, c);
    if (!conv || !this.gestisceGruppo(conv, c)) return { success: false, error: 'Non puoi cambiare la foto di questo gruppo' };
    if (!f || !f.size || f.size > 8 * 1024 * 1024) return { success: false, error: 'Immagine troppo grande (max 8MB)' };
    const b = f.buffer;
    const ext = b[0] === 0xff && b[1] === 0xd8 && b[2] === 0xff ? 'jpg'
      : b.subarray(0, 8).equals(Buffer.from([0x89, 0x50, 0x4e, 0x47, 0x0d, 0x0a, 0x1a, 0x0a])) ? 'png'
        : b.subarray(0, 4).toString('latin1') === 'RIFF' && b.subarray(8, 12).toString('latin1') === 'WEBP' ? 'webp' : null;
    if (!ext) return { success: false, error: 'Seleziona un\'immagine' };
    const dir = cartellaStorage('chat-attachments', String(convId));
    fs.mkdirSync(dir, { recursive: true });
    const dest = path.join(dir, `gruppo-${randomBytes(8).toString('hex')}.${ext}`);
    fs.writeFileSync(dest, b, { mode: 0o640 });
    const vecchia = this.fileAllegato(conv.avatar_path);
    if (vecchia) fs.rmSync(vecchia, { force: true });
    await this.ds.query('UPDATE chat_conversations SET avatar_path = $2 WHERE id = $1', [convId, dest]);
    await this.emitCambiata(convId);
    return { success: true };
  }

  async info(u: UtenteCorrente, convId: number) {
    const c = this.chi(u);
    const conv = await this.sePartecipa(convId, c);
    if (!conv) return { success: false };
    const gruppo = conv.conv_type === 'group';
    const cache = new Map<string, Persona>();
    const membri = [];
    for (const p of await this.partecipanti(conv)) membri.push({ ...(await this.persona(p.tipo, p.id, cache)), isCreator: (conv.created_by ?? '') === chiave(p.tipo, p.id) });
    return {
      id: convId, type: gruppo || membri.length > 2 ? 'group' : 'direct', name: conv.name ?? null,
      avatarUrl: conv.avatar_path ? this.urlAvatar(conv) : null, createdBy: conv.created_by ?? null,
      canEdit: this.gestisceGruppo(conv, c), realGroup: gruppo, members: membri,
    };
  }

  // ─── Presenza ──────────────────────────────────────────────────────────────

  statoPresenza(p: any, adesso = Date.now()): string {
    if (!p || !p.last_seen_at || adesso - ms(p.last_seen_at) > PRESENZA_OFFLINE * 1000) return 'offline';
    if (p.manual_status === 'non_disturbare') return 'non_disturbare';
    if (p.in_call_until && ms(p.in_call_until) > adesso) return 'in_chiamata';
    if (p.manual_status) return p.manual_status;
    if (!p.last_active_at || adesso - ms(p.last_active_at) > PRESENZA_ASSENTE * 1000) return 'assente';
    return 'online';
  }

  private async rigaPresenza(cid: number, c: Chi) {
    const [r] = await this.ds.query('SELECT * FROM chat_presence WHERE company_id = $1 AND user_type = $2 AND user_id = $3', [cid, c.tipo, c.id]);
    return r ?? null;
  }

  async segnale(u: UtenteCorrente, attivo: boolean) {
    const c = this.chi(u);
    const cid = u.companyId!;
    const prima = this.statoPresenza(await this.rigaPresenza(cid, c));
    let inChiamata = false;
    if (c.tipo === 'employee') {
      const [e] = await this.ds.query('SELECT availability_status FROM employees WHERE id = $1', [c.id]);
      inChiamata = e?.availability_status === 'in_call';
    }
    await this.ds.query(
      `INSERT INTO chat_presence (company_id, user_type, user_id, last_seen_at, last_active_at, in_call_until)
       VALUES ($1, $2, $3, CURRENT_TIMESTAMP, ${attivo || inChiamata ? 'CURRENT_TIMESTAMP' : 'NULL'}, ${inChiamata ? "CURRENT_TIMESTAMP + INTERVAL '90 seconds'" : 'NULL'})
       ON CONFLICT (company_id, user_type, user_id) DO UPDATE SET last_seen_at = EXCLUDED.last_seen_at,
         last_active_at = COALESCE(EXCLUDED.last_active_at, chat_presence.last_active_at), in_call_until = EXCLUDED.in_call_until`, [cid, c.tipo, c.id]);
    const dopo = await this.rigaPresenza(cid, c);
    const stato = this.statoPresenza(dopo);
    if (stato !== prima) await this.realtime.emitAzienda(cid, 'presence.changed', { userId: chiave(c.tipo, c.id), stato, ultimoAccesso: isoRoma(dopo?.last_seen_at) });
    return { stato, manuale: dopo?.manual_status ?? null };
  }

  async statoManuale(u: UtenteCorrente, stato: string | null) {
    if (stato !== null && !['occupato', 'non_disturbare', 'assente'].includes(stato)) return { success: false, error: 'Stato non valido' };
    const c = this.chi(u);
    const cid = u.companyId!;
    const prima = this.statoPresenza(await this.rigaPresenza(cid, c));
    await this.ds.query(
      `INSERT INTO chat_presence (company_id, user_type, user_id, last_seen_at, last_active_at, manual_status) VALUES ($1, $2, $3, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP, $4)
       ON CONFLICT (company_id, user_type, user_id) DO UPDATE SET manual_status = EXCLUDED.manual_status`, [cid, c.tipo, c.id, stato]);
    const dopo = await this.rigaPresenza(cid, c);
    const ora = this.statoPresenza(dopo);
    if (ora !== prima) await this.realtime.emitAzienda(cid, 'presence.changed', { userId: chiave(c.tipo, c.id), stato: ora, ultimoAccesso: isoRoma(dopo?.last_seen_at) });
    return { success: true, stato: ora, manuale: stato };
  }

  async presenze(companyId: number) {
    const out: Record<string, { stato: string; ultimoAccesso: string | null }> = {};
    const adesso = Date.now();
    for (const p of await this.ds.query('SELECT * FROM chat_presence WHERE company_id = $1', [companyId])) {
      out[chiave(String(p.user_type), p.user_id)] = { stato: this.statoPresenza(p, adesso), ultimoAccesso: isoRoma(p.last_seen_at) };
    }
    return out;
  }

  // ─── Tempo reale ───────────────────────────────────────────────────────────

  async emitConversazione(convId: number, tipo: string, dati: (p: Chi) => unknown | null | Promise<unknown | null>) {
    if (!this.realtime.attivo()) return;
    try {
      const [conv] = await this.ds.query('SELECT * FROM chat_conversations WHERE id = $1', [convId]);
      if (!conv) return;
      const per: Record<string, unknown> = {};
      for (const p of await this.partecipanti(conv)) {
        const d = await dati(p);
        if (d !== null && d !== undefined) per[chiave(p.tipo, p.id)] = d;
      }
      await this.realtime.emitEach(conv.company_id, tipo, per);
    } catch (e: any) { this.logger.warn(`realtime: ${e?.message ?? e}`); }
  }

  async emitAggiornato(id: number) {
    const [riga] = await this.ds.query('SELECT * FROM chat_messages WHERE id = $1', [id]);
    if (!riga) return;
    await this.emitConversazione(riga.conversation_id, 'chat.message.updated', async (p) =>
      riga.is_internal && p.tipo === 'employee' ? null : { conversationId: riga.conversation_id, message: (await this.formatta([riga], p))[0] ?? null });
  }

  async emitCambiata(convId: number, altri: string[] = []) {
    if (!this.realtime.attivo()) return;
    const [conv] = await this.ds.query('SELECT * FROM chat_conversations WHERE id = $1', [convId]);
    if (!conv) return;
    const subs = [...altri, ...(await this.partecipanti(conv)).map((p) => chiave(p.tipo, p.id))];
    await this.realtime.emit(conv.company_id, 'chat.conversation.changed', subs, { conversationId: convId });
  }

  // ─── Accessi negati ────────────────────────────────────────────────────────

  async accessoNegato(c: Chi, ctx: ContestoAudit, dettagli: Record<string, unknown>) {
    await this.audit.log('unauthorized_access', c.tipo, c.id, ctx, null, null, null, { resource: 'chat_attachment', ...dettagli }, 'critical');
  }
}
