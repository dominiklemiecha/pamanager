import { Injectable } from '@nestjs/common';
import type { Response } from 'express';
import { DataSource } from 'typeorm';
import { senzaIsolamento } from '../../../common/database/isolamento';
import type { FileCaricato } from '../../../common/file/caricamenti';
import { ChatAzioniService } from '../../chat/chat-azioni.service';
import { ChatService } from '../../chat/chat.service';
import { ContattiService } from '../../contatti/contatti.service';
import { NotificheService } from '../../notifiche/notifiche.service';
import { normalizza } from '../normalizza';
import { ContestoPagina, json, PaginaPhp, Pagina, vista } from '../pagina';

const ETICHETTE = { admin: 'Amministratori', consulente_lavoro: 'Consulenti del lavoro', employee: 'Dipendenti' };
const SINGOLARI = { admin: 'Amministratore', consulente_lavoro: 'Consulente del lavoro', employee: 'Dipendente' };
const URL_CHAT: Record<string, string> = {
  admin: '/admin/chat.php', consulente_lavoro: '/consulente-lavoro/chat.php', employee: '/employee/chat.php',
};
/** Il template guarda solo se c'e' un allegato: il percorso sul server non esce. */
const senzaPercorso = (m: any) => ({ ...m, attachment_path: m.attachment_path ? '1' : null });

/**
 * Chat dello staff (_chat-page.inc.php per admin, commercialista e
 * consulente): elenco, conversazione aperta, note interne, partecipanti
 * aggiunti, allegati. Le azioni dello script (POST alla pagina) sono quelle
 * della chat del dipendente piu' get_messages e partecipanti aggiunti.
 *
 * Correzione: si puo' far intervenire solo un admin o un consulente tra i
 * contatti dell'azienda (il PHP accettava utenti di qualsiasi azienda).
 */
@Injectable()
abstract class ChatStaffPagina implements Pagina {
  abstract readonly ruoli: string[];
  constructor(protected readonly ds: DataSource, protected readonly chat: ChatService, protected readonly azioni: ChatAzioniService,
    protected readonly contatti: ContattiService, protected readonly notifiche: NotificheService) {}

  async file(c: ContestoPagina, res: Response) {
    if (c.query.download_attachment === undefined && c.query.group_avatar === undefined) return false;
    await this.azioni.file(c.query.download_attachment, c.query.group_avatar, c.query.inline, c.audit, res, c.u);
    return true;
  }

  async azione(c: ContestoPagina, corpo: Record<string, string>, file: Record<string, FileCaricato[]>) {
    const io = this.chat.chi(c.u);
    const conv = Number.parseInt(corpo.conversation_id ?? '', 10) || 0;
    switch (corpo.action ?? '') {
      case 'get_messages': {
        if (!(await this.chat.sePartecipa(conv, io))) return json({ success: false, error: 'Accesso non autorizzato' });
        await this.chat.segnaLetta(conv, io);
        const messaggi = await this.messaggi(conv, true);
        const nomi = new Map<string, string>();
        for (const m of messaggi) {
          const k = `${m.sender_type}:${m.sender_id}`;
          if (!nomi.has(k)) nomi.set(k, await this.chat.nome(m.sender_type, m.sender_id));
          m.sender_name = nomi.get(k);
        }
        return json({ success: true, messages: normalizza(messaggi.map(senzaPercorso)) });
      }
      case 'add_participant': return json(await this.aggiungi(c, conv, String(corpo.new_type ?? ''), Number.parseInt(corpo.new_id ?? '', 10) || 0));
      case 'remove_participant': return json(await this.togli(c, conv, String(corpo.rem_type ?? ''), Number.parseInt(corpo.rem_id ?? '', 10) || 0));
      default: {
        let codice = 200;
        const r = await this.azioni.esegui(corpo, { attachment: file.attachment, avatar: file.avatar }, c.u, (x) => { codice = x; });
        return json(r, codice);
      }
    }
  }

  /** Chat::getMessages: gli ultimi 50 in ordine cronologico (lo staff vede le note interne). */
  private async messaggi(conv: number, interni: boolean) {
    const righe = await this.ds.query(
      `SELECT * FROM chat_messages WHERE conversation_id = $1 ${interni ? '' : 'AND is_internal = false'} ORDER BY created_at DESC, id DESC LIMIT 50`, [conv]);
    return righe.reverse();
  }

  /** Chat::addStaffParticipant. */
  private async aggiungi(c: ContestoPagina, convId: number, tipo: string, id: number) {
    const io = this.chat.chi(c.u);
    const conv = await this.chat.sePartecipa(convId, io);
    if (!conv) return { success: false, error: 'Accesso non autorizzato' };
    if (!['admin', 'consulente_lavoro'].includes(io.tipo)) return { success: false, error: 'Solo admin e consulente del lavoro possono aggiungere partecipanti' };
    if (!['admin', 'consulente_lavoro'].includes(tipo)) return { success: false, error: 'Puoi aggiungere solo un admin o un consulente del lavoro' };
    const parti = await this.chat.partecipanti(conv);
    if (parti.some((p) => p.tipo === tipo && p.id === id)) return { success: false, error: 'Utente già presente nella conversazione' };
    if (!parti.some((p) => p.tipo === 'employee')) return { success: false, error: 'Puoi aggiungere partecipanti solo nelle chat con un dipendente' };
    const [u] = await senzaIsolamento(() => this.ds.query('SELECT id, is_active, role FROM users WHERE id = $1', [id]));
    if (!u || !u.is_active || u.role !== tipo) return { success: false, error: 'Utente non valido' };
    if (!(await this.invitabile(c, tipo, id))) return { success: false, error: 'Utente non valido' };
    await this.ds.query(
      'INSERT INTO chat_extra_participants (conversation_id, participant_type, participant_id, added_by_type, added_by_id) VALUES ($1, $2, $3, $4, $5)',
      [convId, tipo, id, io.tipo, io.id]);
    try {
      const dip = parti.find((p) => p.tipo === 'employee');
      await this.notifiche.crea(conv.company_id, {
        recipient_type: tipo, recipient_id: id, type: 'new_message', title: `${await this.chat.nome(io.tipo, io.id)} ti ha aggiunto a una conversazione`,
        message: `Chat con ${dip ? await this.chat.nome('employee', dip.id) : ''}`, link: `${URL_CHAT[tipo]}?conv=${convId}`,
      });
    } catch { /* best effort */ }
    return { success: true };
  }

  /** Un admin o consulente tra i contatti dell'azienda (o l'admin stesso che invita). */
  private async invitabile(c: ContestoPagina, tipo: string, id: number) {
    if (await this.contatti.consentito(c.u, tipo, id)) return true;
    const [r] = await senzaIsolamento(() => this.ds.query(
      `SELECT 1 FROM users u WHERE u.id = $1 AND u.role = $2 AND (u.company_id = $3 OR EXISTS (SELECT 1 FROM user_companies x WHERE x.user_id = u.id AND x.company_id = $3))`,
      [id, tipo, c.u.companyId]));
    return !!r;
  }

  /** Chat::removeStaffParticipant. */
  private async togli(c: ContestoPagina, convId: number, tipo: string, id: number) {
    const io = this.chat.chi(c.u);
    const conv = await this.chat.sePartecipa(convId, io);
    if (!conv) return { success: false, error: 'Accesso non autorizzato' };
    if (!['admin', 'consulente_lavoro'].includes(io.tipo)) return { success: false, error: 'Solo admin e consulente del lavoro possono rimuovere partecipanti' };
    const [e] = await this.ds.query('SELECT 1 FROM chat_extra_participants WHERE conversation_id = $1 AND participant_type = $2 AND participant_id = $3', [convId, tipo, id]);
    if (!e) return { success: false, error: 'Puoi rimuovere solo i partecipanti aggiunti alla conversazione' };
    await this.ds.query('DELETE FROM chat_extra_participants WHERE conversation_id = $1 AND participant_type = $2 AND participant_id = $3', [convId, tipo, id]);
    const ioStesso = tipo === io.tipo && id === io.id;
    if (!ioStesso) {
      try {
        await this.notifiche.crea(conv.company_id, {
          recipient_type: tipo, recipient_id: id, type: 'new_message', title: `${await this.chat.nome(io.tipo, io.id)} ti ha rimosso da una conversazione`,
          message: 'Non riceverai più notifiche da questa chat', link: URL_CHAT[tipo],
        });
      } catch { /* best effort */ }
    }
    return { success: true, left: ioStesso };
  }

  /** Contatti con i campi che il template mostra (Chat::getAvailableContacts). */
  private async rubrica(c: ContestoPagina) {
    const gruppi: Record<string, any[]> = await this.contatti.perUtente(c.u) as any;
    const out: Record<string, any[]> = {};
    for (const [g, lista] of Object.entries(gruppi)) {
      const ids = lista.map((x: any) => x.id);
      if (!ids.length) { out[g] = []; continue; }
      const extra = g === 'employee'
        ? await this.ds.query(`SELECT e.id, e.email, e.fiscal_code, d.name AS department_name FROM employees e LEFT JOIN departments d ON d.id = e.department_id WHERE e.id = ANY($1::int[])`, [ids])
        : await senzaIsolamento(() => this.ds.query('SELECT id, username, email FROM users WHERE id = ANY($1::int[])', [ids]));
      const per = new Map(extra.map((x: any) => [x.id, x]));
      out[g] = lista.map((x: any) => ({ ...x, ...(per.get(x.id) ?? {}) }));
    }
    return out;
  }

  async dati(c: ContestoPagina) {
    const io = this.chat.chi(c.u);
    const conversazioni = await this.chat.conversazioni(io, c.u.companyId!);
    for (const conv of conversazioni) {
      conv.other_name = await this.chat.nome(conv.other_type, conv.other_id);
      conv.other_photo = await this.chat.fotoDipendente(conv.other_type, conv.other_id);
    }
    const contatti = await this.rubrica(c);
    let aperta = Number.parseInt(String(c.query.conv ?? '0'), 10) || 0;
    let messaggi: any[] = [];
    let selezionata: any = null;
    if (aperta) {
      if (!(await this.chat.sePartecipa(aperta, io))) aperta = 0;
      else {
        await this.chat.segnaLetta(aperta, io);
        messaggi = await this.messaggi(aperta, true);
        selezionata = conversazioni.find((x: any) => x.id === aperta) ?? null;
      }
    }
    const staff = ['admin', 'consulente_lavoro'].includes(io.tipo);
    let conDipendente = false;
    const extra: any[] = [];
    const candidati: any[] = [];
    if (selezionata) {
      const parti = await this.chat.partecipanti(selezionata);
      conDipendente = parti.some((p) => p.tipo === 'employee');
      for (const p of parti.slice(2)) extra.push({ type: p.tipo, id: p.id, name: await this.chat.nome(p.tipo, p.id) });
      if (staff && conDipendente) {
        const presenti = new Set(parti.map((p) => `${p.tipo}:${p.id}`));
        for (const g of ['admin', 'consulente_lavoro']) {
          for (const u of contatti[g] ?? []) {
            if (presenti.has(`${g}:${u.id}`)) continue;
            candidati.push({ type: g, id: u.id, name: u.name ?? `Utente #${u.id}` });
          }
        }
      }
    }
    const nomi: Record<string, string> = {};
    for (const m of messaggi) {
      const k = `${m.sender_type}:${m.sender_id}`;
      nomi[k] ??= await this.chat.nome(m.sender_type, m.sender_id);
    }
    const attivi: Record<string, boolean> = {};
    for (const conv of conversazioni) attivi[`${conv.other_type}:${conv.other_id}`] = true;
    const condivisi = aperta ? await this.ds.query(
      `SELECT id, attachment_name, attachment_size, attachment_mime, created_at FROM chat_messages
        WHERE conversation_id = $1 AND attachment_path IS NOT NULL AND attachment_path <> '' ORDER BY created_at DESC, id DESC LIMIT 20`, [aperta]) : [];
    return vista('Chat', {
      userType: io.tipo, userId: io.id, user: { id: c.u.id, department_id: c.u.departmentId ?? null },
      conversations: conversazioni, contacts: contatti, selectedConvId: aperta, selectedMessages: messaggi.map(senzaPercorso), selectedConv: selezionata,
      __isStaff: staff, __convHasEmployee: conDipendente, __extraParticipants: extra, __isThreeWay: extra.length > 0, __inviteCandidates: candidati,
      __senderNames: nomi, groupLabels: ETICHETTE, singularLabels: SINGOLARI, activeContactIds: attivi, sharedFiles: condivisi,
      __chatLayout: 'admin',
    });
  }
}

@Injectable()
@PaginaPhp('admin/chat')
export class ChatAdminPagina extends ChatStaffPagina { readonly ruoli = ['admin']; }

@Injectable()
@PaginaPhp('consulente-lavoro/chat')
export class ChatConsulentePagina extends ChatStaffPagina { readonly ruoli = ['consulente_lavoro']; }
