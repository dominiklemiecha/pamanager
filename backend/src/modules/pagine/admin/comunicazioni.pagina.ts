import { Body, Controller, HttpCode, Post, Req, UploadedFiles, UseInterceptors } from '@nestjs/common';
import { Injectable } from '@nestjs/common';
import { AnyFilesInterceptor } from '@nestjs/platform-express';
import { memoryStorage } from 'multer';
import { DataSource } from 'typeorm';
import { oggiRoma } from '../../../common/calcoli/php';
import { sanitizzaHtmlRicco } from '../../../common/html/sanitizza';
import type { FileCaricato } from '../../../common/file/caricamenti';
import { Ruoli, Utente } from '../../auth/decoratori';
import type { RichiestaAutenticata, UtenteCorrente } from '../../auth/auth.types';
import { ComunicazioniAdminService } from '../../comunicazioni/comunicazioni-admin.service';
import { ContestoPagina, PaginaPhp, Pagina, redirect, vista } from '../pagina';

const MESSAGGI: Record<string, string> = {
  created: 'Comunicazione creata con successo', updated: 'Comunicazione aggiornata con successo', deleted: 'Comunicazione eliminata con successo',
};

/**
 * Comunicazioni dell'HR (admin/communications.php): elenco con letture,
 * creazione e modifica con editor e allegati, dettaglio con statistiche.
 *
 * Correzioni: dettaglio, modifica e allegati solo dell'azienda attiva (il
 * PHP apriva comunicazioni e cancellava allegati di altre aziende); una
 * bozza resta bozza; il testo arriva al browser gia' ripulito.
 */
@Injectable()
@PaginaPhp('admin/communications')
export class ComunicazioniAdminPagina implements Pagina {
  readonly ruoli = ['admin'];
  constructor(private readonly ds: DataSource, private readonly com: ComunicazioniAdminService) {}

  private async allegati(id: number, file: Record<string, FileCaricato[]>) {
    for (const f of [...(file['attachments[]'] ?? []), ...(file.attachments ?? [])]) {
      if (f.originalname) await this.com.allega(f, id, false);
    }
  }

  async azione(c: ContestoPagina, corpo: Record<string, string>, file: Record<string, FileCaricato[]>) {
    const cid = c.u.companyId!;
    const id = Number.parseInt(String(c.query.id ?? corpo.id ?? ''), 10) || 0;
    const contenuto = sanitizzaHtmlRicco(String(corpo.content ?? ''));
    const dati = {
      title: corpo.title ?? '', content: contenuto, priority: corpo.priority ?? 'normal', is_published: corpo.is_published !== undefined,
      publish_date: corpo.publish_date ?? '', expire_date: corpo.expire_date || null,
    };
    const indietro = (azione: string, errore: string) => this.dati(c, { azione, errore, post: { ...corpo, content: contenuto } });
    switch (corpo.action ?? '') {
      case 'create': {
        const r = await this.com.crea({ ...dati, publish_date: corpo.publish_date ?? oggiRoma() }, cid, c.audit);
        if (!r.success) return indietro('new', r.error);
        await this.allegati(r.id!, file);
        return redirect(`/admin/communications.php?action=edit&id=${r.id}&message=created`);
      }
      case 'update': {
        if (!id) break;
        const r = await this.com.aggiorna(id, dati, cid, c.audit);
        if (!r.success) return indietro('edit', r.error);
        await this.allegati(id, file);
        return redirect(`/admin/communications.php?action=edit&id=${id}&message=updated`);
      }
      case 'delete_attachment': {
        const att = Number.parseInt(corpo.attachment_id ?? '', 10) || 0;
        if (att && id) {
          await this.com.eliminaAllegato(att, id, cid);
          return redirect(`/admin/communications.php?action=edit&id=${id}&message=updated`);
        }
        break;
      }
      case 'delete': {
        if (!id) break;
        const r = await this.com.elimina(id, cid, c.audit);
        if (r.success) return redirect('/admin/communications.php?message=deleted');
        return this.dati(c, { errore: r.error });
      }
      case 'toggle_publish': {
        if (!id) break;
        const r = await this.com.pubblicazione(id, cid, c.audit);
        if (r.success) return redirect('/admin/communications.php?message=updated');
        return this.dati(c, { errore: r.error });
      }
    }
    return this.dati(c);
  }

  async dati(c: ContestoPagina, o: { azione?: string; errore?: string; post?: Record<string, string> } = {}) {
    const cid = c.u.companyId!;
    const q = c.query;
    const action = o.azione ?? (q.action !== undefined ? String(q.action) : 'list');
    const id = q.id !== undefined ? Number.parseInt(String(q.id), 10) || 0 : o.post?.id ? Number.parseInt(o.post.id, 10) || 0 : null;
    const search = q.search !== undefined ? String(q.search) : '';
    let communication: any = null;
    let communications: any[] = [];
    let attachments: any[] | undefined;
    let readStats: any;
    if (action === 'list') {
      communications = await this.com.elenco(cid, search);
    } else if ((action === 'edit' || action === 'view') && id) {
      communication = await this.com.perId(id, cid);
      if (!communication) return redirect('/admin/communications.php?error=not_found');
      attachments = await this.com.allegati(id);
      if (action === 'view') readStats = await this.com.statistiche(id, communication);
    }
    const oggi = oggiRoma();
    const n = async (sql: string, p: unknown[]) => Number((await this.ds.query(sql, p))[0]?.n ?? 0);
    return vista(action === 'new' ? 'Nuova Comunicazione' : action === 'edit' ? 'Modifica Comunicazione' : action === 'view' ? 'Dettaglio Comunicazione' : 'Gestione Comunicazioni', {
      action, id, search, communication, communications, attachments, readStats,
      message: q.message !== undefined ? MESSAGGI[String(q.message)] ?? '' : '', error: o.errore ?? '',
      _POST: o.post ?? {},
      __commPublished: await n('SELECT COUNT(*)::int AS n FROM communications WHERE company_id = $1 AND is_published = true', [cid]),
      __commDraft: await n('SELECT COUNT(*)::int AS n FROM communications WHERE company_id = $1 AND is_published = false', [cid]),
      __commActive: await n(`SELECT COUNT(*)::int AS n FROM communications WHERE company_id = $1 AND is_published = true AND publish_date <= $2
                              AND (expire_date IS NULL OR expire_date >= $2)`, [cid, oggi]),
    });
  }
}

/**
 * admin/communications_upload.php: immagine nel testo o allegato caricati
 * dall'editor (risposta JSON come il PHP). Correzione: solo comunicazioni
 * dell'azienda attiva.
 */
@Controller('admin')
export class ComunicazioniUploadController {
  constructor(private readonly com: ComunicazioniAdminService) {}

  @Post('communications_upload.php')
  @Ruoli('admin')
  @HttpCode(200)
  @UseInterceptors(AnyFilesInterceptor({ storage: memoryStorage(), limits: { fileSize: 10 * 1024 * 1024, files: 1 } }))
  async carica(@Body() corpo: Record<string, string>, @UploadedFiles() file: Array<FileCaricato & { fieldname: string }>, @Req() _req: RichiestaAutenticata,
    @Utente() u: UtenteCorrente) {
    const id = Number.parseInt(corpo.communication_id ?? '', 10) || 0;
    if (!id) return { success: false, error: 'Comunicazione non specificata' };
    if (!(await this.com.perId(id, u.companyId!))) return { success: false, error: 'Comunicazione non trovata' };
    const f = (file ?? []).find((x) => x.fieldname === 'file');
    if (!f) return { success: false, error: 'File mancante' };
    const inline = (corpo.type ?? 'attachment') === 'image';
    const r = await this.com.allega(f, id, inline);
    if (!r.success) return r;
    return { success: true, id: r.id, url: r.url + (inline ? '&inline=1' : ''), name: f.originalname, size: f.size, mime: f.mimetype };
  }
}
