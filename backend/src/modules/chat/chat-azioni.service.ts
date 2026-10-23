import { Injectable } from '@nestjs/common';
import { randomBytes } from 'crypto';
import type { Response } from 'express';
import * as fs from 'fs';
import * as path from 'path';
import { contenutoCoerente, estensione, FileCaricato } from '../../common/file/caricamenti';
import { cartellaStorage } from '../../config/app';
import type { ContestoAudit } from '../audit/audit.service';
import type { UtenteCorrente } from '../auth/auth.types';
import { ChatService } from './chat.service';
import { RealtimeService } from './realtime.service';

const ESTENSIONI = ['pdf', 'jpg', 'jpeg', 'png', 'gif', 'webp', 'doc', 'docx', 'xls', 'xlsx', 'csv', 'txt', 'odt', 'ods', 'zip', 'webm', 'ogg', 'm4a', 'mp3', 'mp4', 'wav'];
const MIME: Record<string, string> = {
  pdf: 'application/pdf', jpg: 'image/jpeg', jpeg: 'image/jpeg', png: 'image/png', gif: 'image/gif', webp: 'image/webp',
  doc: 'application/msword', docx: 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
  xls: 'application/vnd.ms-excel', xlsx: 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
  csv: 'text/csv', txt: 'text/plain', odt: 'application/vnd.oasis.opendocument.text', ods: 'application/vnd.oasis.opendocument.spreadsheet',
  zip: 'application/zip', webm: 'video/webm', ogg: 'audio/ogg', m4a: 'audio/mp4', mp3: 'audio/mpeg', mp4: 'video/mp4', wav: 'audio/x-wav',
};
export type FileChat = { attachment?: FileCaricato[]; avatar?: FileCaricato[] };
const intero = (v: unknown) => Number.parseInt(String(v ?? ''), 10) || 0;

/**
 * Azioni della chat (_chat-page.inc.php e cora-chat.js) e file, condivise tra
 * la pagina del dipendente (/chat/azione) e le pagine chat dello staff.
 */
@Injectable()
export class ChatAzioniService {
  constructor(private readonly chat: ChatService, private readonly realtime: RealtimeService) {}

  /** Azioni dello script (POST alla pagina nel PHP). */
  async esegui(b: Record<string, string>, f: FileChat | undefined, u: UtenteCorrente, stato: (codice: number) => void = () => undefined) {
    const c = this.chat.chi(u);
    const conv = intero(b.conversation_id);
    switch (b.action) {
      case 'send': {
        if (!(await this.chat.sePartecipa(conv, c))) return { success: false, error: 'Accesso non autorizzato' };
        let allegato = null;
        const a = f?.attachment?.[0];
        if (a) {
          const r = this.salvaAllegato(a, conv);
          if ('error' in r) return { success: false, error: r.error };
          allegato = r;
        }
        const testo = String(b.message ?? '');
        if ([...testo].length > 20000) return { success: false, error: 'Il messaggio supera i 20.000 caratteri' };
        const esito: any = await this.chat.invia(u, conv, testo, {
          allegato, interno: !!b.is_internal && ['admin', 'consulente_lavoro'].includes(c.tipo), risposta: intero(b.reply_to_id) || null,
          clientId: b.client_id ? String(b.client_id) : null, menzioni: String(b.mention_ids ?? '').split(',').filter(Boolean),
        });
        if (esito.success && b.cora) esito.message = await this.chat.messaggioFormattato(esito.id, c);
        return esito;
      }
      case 'start_conversation': {
        if (!(await this.chat.puoContattare(u, String(b.other_type ?? ''), intero(b.other_id)))) return { success: false, error: 'Non puoi contattare questo utente' };
        const r = await this.chat.conversazioneDiretta(u, String(b.other_type), intero(b.other_id));
        return r.success ? { success: true, conversation: r.conversation } : r;
      }
      case 'cora_list': return this.chat.elenco(c, u.companyId!);
      case 'cora_messages': {
        if (!(await this.chat.sePartecipa(conv, c))) { stato(403); return { success: false, error: 'Accesso non autorizzato' }; }
        return this.chat.messaggi(u, conv, Math.max(1, Math.min(100, intero(b.limit) || 50)), intero(b.before) || null, intero(b.after) || null);
      }
      case 'cora_read':
        if (await this.chat.sePartecipa(conv, c)) await this.chat.segnaLetta(conv, c);
        return { success: true };
      case 'cora_read_all':
        await this.chat.segnaTutteLette(c, u.companyId!);
        return { success: true };
      case 'cora_react': return this.chat.reazione(u, intero(b.message_id), String(b.emoji ?? ''));
      case 'cora_edit': return this.chat.modifica(u, intero(b.message_id), String(b.message ?? ''));
      case 'cora_delete': return this.chat.elimina(u, intero(b.message_id));
      case 'cora_forward': return this.chat.inoltra(u, intero(b.message_id), String(b.conversation_ids ?? '').split(',').map(intero).filter(Boolean));
      case 'cora_pin':
      case 'cora_hide': {
        if (!(await this.chat.sePartecipa(conv, c))) return { success: false, error: 'Accesso non autorizzato' };
        if (b.action === 'cora_pin') {
          const fissata = !!(await this.chat.preferenze(c)).get(conv)?.pinned_at;
          await this.chat.impostaPreferenza(conv, c, 'pinned_at', fissata ? null : 'adesso');
        } else {
          // "Elimina": sparisce dal mio elenco, torna con il prossimo messaggio.
          await this.chat.segnaLetta(conv, c);
          await this.chat.impostaPreferenza(conv, c, 'hidden_at', 'adesso');
          await this.chat.impostaPreferenza(conv, c, 'pinned_at', null);
        }
        await this.realtime.emit(u.companyId!, 'chat.conversation.changed', [`${c.tipo}:${c.id}`], { conversationId: conv });
        return { success: true };
      }
      case 'cora_direct': {
        const [t, i] = String(b.user ?? '').split(':');
        if (!t || !(await this.chat.puoContattare(u, t, intero(i)))) return { success: false, error: 'Non puoi contattare questo utente' };
        const r = await this.chat.conversazioneDiretta(u, t, intero(i));
        if (!r.success) return r;
        await this.chat.impostaPreferenza(r.id!, c, 'hidden_at', null);
        return { success: true, id: r.id };
      }
      case 'cora_group_create': return this.chat.creaGruppo(u, String(b.name ?? ''), String(b.members ?? '').split(',').filter(Boolean));
      case 'cora_group_add': return this.chat.aggiungiAlGruppo(u, conv, String(b.members ?? '').split(',').filter(Boolean));
      case 'cora_group_remove':
      case 'cora_group_leave': {
        const chi = b.action === 'cora_group_leave' ? `${c.tipo}:${c.id}` : String(b.member ?? '');
        const [t, i] = chi.split(':');
        return this.chat.togliDalGruppo(u, conv, String(t ?? ''), intero(i));
      }
      case 'cora_group_avatar': return this.chat.fotoGruppo(u, conv, f?.avatar?.[0]);
      case 'cora_info': return this.chat.info(u, conv);
      case 'cora_contacts': return this.chat.contattiCora(u);
      case 'cora_presence_ping': return this.chat.segnale(u, !!b.attivo && b.attivo !== '0');
      case 'cora_presence_status': return this.chat.statoManuale(u, String(b.stato ?? '') === '' ? null : String(b.stato));
      case 'cora_presence_all': return this.chat.presenze(u.companyId!);
      case 'cora_rt_token': return { token: this.realtime.token(c.tipo, c.id, u.companyId!) };
      case 'cora_gifs': return this.gif(String(b.kind ?? 'gif'), String(b.q ?? ''));
      case 'cora_send_gif': {
        const url = String(b.url ?? '');
        if (!(await this.chat.sePartecipa(conv, c)) || !/^https:\/\/media\d*\.giphy\.com\/[A-Za-z0-9/._?=&%-]+$/.test(url)) return { success: false, error: 'GIF non valida' };
        const descrizione = [...String(b.description ?? 'GIF')].slice(0, 200).join('') || 'GIF';
        const esito: any = await this.chat.invia(u, conv, '', { allegato: { path: `giphy:${url}`, name: descrizione, size: 0, mime: 'image/gif' }, clientId: b.client_id ? String(b.client_id) : null });
        if (esito.success) esito.message = await this.chat.messaggioFormattato(esito.id, c);
        return esito;
      }
      default: return { success: false, error: 'Azione non valida' };
    }
  }

  /** Allegato: estensione ammessa, contenuto coerente, al massimo 20 MB (Chat::handleAttachmentUpload). */
  private salvaAllegato(a: FileCaricato, conv: number): { path: string; name: string; size: number; mime: string } | { error: string } {
    if (a.size > 20 * 1024 * 1024) return { error: 'File troppo grande (max 20MB)' };
    const ext = estensione(a.originalname);
    if (!ESTENSIONI.includes(ext)) return { error: `Estensione non consentita (.${ext})` };
    if (!contenutoCoerente(ext, a.buffer)) return { error: 'Il contenuto del file non corrisponde al tipo' };
    const dir = cartellaStorage('chat-attachments', String(conv));
    fs.mkdirSync(dir, { recursive: true, mode: 0o755 });
    const dest = path.join(dir, `${randomBytes(12).toString('hex')}.${ext}`);
    fs.writeFileSync(dest, a.buffer, { mode: 0o640 });
    return { path: dest, name: a.originalname, size: a.size, mime: MIME[ext] ?? 'application/octet-stream' };
  }

  /** Catalogo Giphy: la chiave resta sul server. */
  private async gif(tipo: string, q: string) {
    const chiave = process.env.GIPHY_API_KEY || '';
    if (!chiave) return { enabled: false, results: [] };
    const cosa = tipo === 'sticker' ? 'stickers' : 'gifs';
    const testo = q.trim();
    const url = `https://api.giphy.com/v1/${cosa}/${testo === '' ? 'trending' : 'search'}?` + new URLSearchParams({ api_key: chiave, q: testo, limit: '24', rating: 'g', lang: 'it' });
    let dati: any = null;
    try { dati = await (await fetch(url, { signal: AbortSignal.timeout(6000) })).json(); } catch { dati = null; }
    const out = [];
    for (const g of dati?.data ?? []) {
      const img = g.images ?? {};
      const pieno = img.downsized?.url ?? img.original?.url ?? null;
      const anteprima = img.fixed_height_small?.url ?? pieno;
      if (!pieno || !/^https:\/\/media\d*\.giphy\.com\//.test(pieno)) continue;
      out.push({ id: String(g.id), preview: anteprima, url: pieno, description: String(g.title ?? 'GIF') });
    }
    return { enabled: true, results: out };
  }

  /** Allegati e foto dei gruppi (?download_attachment= e ?group_avatar= del PHP). */
  async file(allegato: string | undefined, gruppo: string | undefined, inline: string | undefined, ctx: ContestoAudit, res: Response, u: UtenteCorrente) {
    const c = this.chat.chi(u);
    if (gruppo) {
      const conv = await this.chat.sePartecipa(intero(gruppo), c);
      const p = conv ? this.chat.fileAllegato(conv.avatar_path) : null;
      if (!p) { res.status(404).end(); return; }
      const ext = path.extname(p).slice(1);
      res.set({ 'X-Content-Type-Options': 'nosniff', 'Content-Type': MIME[ext] ?? 'image/jpeg', 'Cache-Control': 'private, max-age=86400' });
      res.sendFile(p);
      return;
    }
    const id = intero(allegato);
    const m = await this.chat.messaggio(id);
    if (!m) { res.status(403).type('text/plain').send('Messaggio non trovato'); return; }
    const p = this.chat.fileAllegato(m.attachment_path);
    if (!p) { res.status(403).type('text/plain').send('Allegato non disponibile'); return; }
    let partecipa = !!(await this.chat.sePartecipa(m.conversation_id, c));
    if (partecipa && m.is_internal && c.tipo === 'employee') partecipa = false;
    if (!partecipa) {
      await this.chat.accessoNegato(c, ctx, { message_id: id, user: `${c.tipo}:${c.id}` });
      res.status(403).type('text/plain').send('Accesso non autorizzato');
      return;
    }
    const nome = String(m.attachment_name ?? path.basename(p)).replace(/[^a-zA-Z0-9._-]/g, '_');
    const mime = String(m.attachment_mime ?? 'application/octet-stream');
    if (inline && /^(image\/(jpeg|png|gif|webp)|audio\/[a-z0-9.+-]+|video\/(webm|mp4))$/.test(mime)) {
      res.set({ 'X-Content-Type-Options': 'nosniff', 'Content-Type': mime, 'Content-Disposition': `inline; filename="${nome}"`, 'Cache-Control': 'private, max-age=86400' });
    } else {
      res.set({
        'X-Content-Type-Options': 'nosniff', 'X-Frame-Options': 'DENY', 'Content-Disposition': `attachment; filename="${nome}"`, 'Content-Type': mime,
        'Cache-Control': 'private, no-cache, no-store, must-revalidate', Pragma: 'no-cache', Expires: '0', 'Content-Security-Policy': "default-src 'none'",
      });
    }
    res.sendFile(p);
  }
}
