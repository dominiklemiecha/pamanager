import { Injectable } from '@nestjs/common';
import type { Response } from 'express';
import { DataSource } from 'typeorm';
import type { FileCaricato } from '../../../common/file/caricamenti';
import { DocumentiPersonaliService } from '../../documenti/documenti-personali.service';
import { DocumentiService } from '../../documenti/documenti.service';
import { ContestoPagina, json, PaginaPhp, Pagina, redirect, vista } from '../pagina';

/** Messaggi di errore della pagina per codice (il PHP rifletteva il testo passato in ?err=). */
const ERRORI: Record<string, string> = {
  no_file: 'Seleziona un file da caricare.',
  nome: 'Nome documento obbligatorio',
  estensione: 'Estensione non consentita (permesse: pdf, jpg, jpeg, png, gif, doc, docx, xls, xlsx, csv, txt, odt, ods)',
  contenuto: 'Tipo MIME non consentito: il contenuto non corrisponde al tipo di file',
  dimensione: 'File troppo grande. Massimo 30MB',
  scadenza: 'Data scadenza non valida',
  nessuno: 'Nessun dipendente attivo a cui agganciare il documento',
  salvataggio: 'Errore durante il salvataggio',
  gruppo: 'Gruppo non valido',
  non_trovata: 'Distribuzione non trovata',
};
const codice = (errore: string) => {
  if (errore.startsWith('Estensione')) return 'estensione';
  if (errore.startsWith('Tipo MIME')) return 'contenuto';
  if (errore.startsWith('File troppo grande')) return 'dimensione';
  if (errore.startsWith('Errore upload')) return 'no_file';
  return Object.entries(ERRORI).find(([, t]) => t === errore)?.[0] ?? 'salvataggio';
};

/** Download di un documento personale o aziendale (?download= delle pagine PHP). */
export async function scaricaDocumentoPersonale(doc: DocumentiService, c: ContestoPagina, res: Response, id: number) {
  const r = await doc.scaricaPersonale(id, c.u, c.audit);
  if (!r.ok) { res.status(403).type('text/plain').send(r.error.replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')); return; }
  res.setHeader('Content-Type', r.mime);
  res.setHeader('Content-Disposition', `attachment; filename="${r.nome}"`);
  res.setHeader('Cache-Control', 'private, no-store');
  res.send(r.contenuto);
}

/**
 * Documenti aziendali (admin/documenti-aziendali.php): un file distribuito a
 * tutti i dipendenti attivi, notifiche smaltite a blocchi dal browser.
 *
 * Correzione: gli avvisi dopo il redirect sono codici della pagina, non
 * testo libero passato nell'indirizzo.
 */
@Injectable()
@PaginaPhp('admin/documenti-aziendali')
export class DocumentiAziendaliPagina implements Pagina {
  readonly ruoli = ['admin'];
  constructor(private readonly ds: DataSource, private readonly personali: DocumentiPersonaliService, private readonly documenti: DocumentiService) {}

  async file(c: ContestoPagina, res: Response) {
    if (c.query.download === undefined) return false;
    await scaricaDocumentoPersonale(this.documenti, c, res, Number.parseInt(String(c.query.download), 10) || 0);
    return true;
  }

  async azione(c: ContestoPagina, corpo: Record<string, string>, file: Record<string, FileCaricato[]>) {
    const cid = c.u.companyId!;
    if (corpo.action === 'notify_batch') {
      return json(await this.personali.lottoNotifiche(String(corpo.bulk_group ?? ''), Number.parseInt(corpo.limit ?? '4', 10), cid));
    }
    if (corpo.action === 'bulk_upload') {
      const f = file.document?.[0];
      if (!f || !f.originalname) return redirect('/admin/documenti-aziendali.php?err=no_file');
      const r = await this.personali.caricaPerTutti(f, { name: corpo.name ?? '', expires_on: corpo.expires_on || null, send_email: !!corpo.send_email }, cid, c.audit);
      return r.success
        ? redirect(`/admin/documenti-aziendali.php?uploaded=${encodeURIComponent(r.bulk_group)}&n=${r.count}`)
        : redirect(`/admin/documenti-aziendali.php?err=${codice(r.error)}`);
    }
    if (corpo.action === 'bulk_delete') {
      const r = await this.personali.eliminaGruppo(String(corpo.bulk_group ?? ''), cid, c.audit);
      return r.success ? redirect(`/admin/documenti-aziendali.php?ok=deleted_${r.count}`) : redirect(`/admin/documenti-aziendali.php?err=${codice(r.error)}`);
    }
    return redirect('/admin/documenti-aziendali.php');
  }

  async dati(c: ContestoPagina) {
    const cid = c.u.companyId!;
    const q = c.query;
    const caricato = q.uploaded !== undefined && /^[0-9a-f]{16}$/.test(String(q.uploaded)) ? String(q.uploaded) : '';
    const ok = /^deleted_(\d+)$/.exec(String(q.ok ?? ''));
    const ripresa = caricato !== '' ? '' : (await this.personali.gruppoInSospeso(cid)) ?? '';
    const sospese = ripresa !== '' ? await this.personali.inSospeso(cid, ripresa) : 0;
    const [{ n: attivi }] = await this.ds.query('SELECT COUNT(*)::int AS n FROM employees WHERE company_id = $1 AND is_active = true', [cid]);
    return vista('Documenti aziendali', {
      uploadedGroup: caricato, uploadedCount: Number.parseInt(String(q.n ?? '0'), 10) || 0,
      okMessage: ok ? `Distribuzione eliminata (${ok[1]} copie rimosse).` : '',
      errMessage: q.err !== undefined ? ERRORI[String(q.err)] ?? '' : '',
      groups: await this.personali.gruppi(cid), activeEmployees: attivi,
      resumeGroup: ripresa, resumePending: sospese,
      jobGroup: caricato !== '' ? caricato : ripresa,
      jobTotal: caricato !== '' ? await this.personali.inSospeso(cid, caricato) : sospese,
    });
  }
}

/**
 * Azioni sui documenti di un dipendente dalla sua scheda
 * (admin/employee-documents.php): caricamento, rinomina, visibilita',
 * scadenza, eliminazione, download. Si torna sempre alla scheda.
 */
@Injectable()
@PaginaPhp('admin/employee-documents')
export class DocumentiDipendenteAdminPagina implements Pagina {
  readonly ruoli = ['admin'];
  constructor(private readonly personali: DocumentiPersonaliService, private readonly documenti: DocumentiService) {}

  async file(c: ContestoPagina, res: Response) {
    if (c.query.download === undefined) { res.status(405).type('text/plain').send('Method not allowed'); return true; }
    await scaricaDocumentoPersonale(this.documenti, c, res, Number.parseInt(String(c.query.download), 10) || 0);
    return true;
  }

  async dati() {
    return json('Method not allowed', 405);
  }

  async azione(c: ContestoPagina, corpo: Record<string, string>, file: Record<string, FileCaricato[]>) {
    const cid = c.u.companyId!;
    const emp = Number.parseInt(corpo.employee_id ?? '', 10) || 0;
    if (!emp) return json('employee_id mancante', 400);
    const torna = (stato: string) => redirect(`/admin/employees.php?action=view&id=${emp}${stato ? `&ed_status=${new URLSearchParams({ x: stato }).toString().slice(2)}` : ''}#docs`);
    const doc = Number.parseInt(corpo.document_id ?? '', 10) || 0;
    switch (corpo.action ?? '') {
      case 'upload': {
        const f = [...(file['documents[]'] ?? []), ...(file.documents ?? []), ...(file.document ?? [])];
        if (!f.length) return torna('no_file');
        return torna(await this.personali.caricaMolti(f, { employee_id: emp, name: corpo.name ?? '', visible_to_employee: !!corpo.visible_to_employee,
          expires_on: corpo.expires_on || null }, cid, c.audit));
      }
      case 'rename':
      case 'toggle_visibility':
      case 'update_expiry': {
        if (!doc) return torna('invalid');
        let dati: { name?: string; visible_to_employee?: boolean; expires_on?: string | null } = {};
        if (corpo.action === 'rename') dati = { name: corpo.name ?? '' };
        else if (corpo.action === 'toggle_visibility') {
          const attuale = await this.personali.perId(doc, cid);
          if (!attuale) return torna('notfound');
          dati = { visible_to_employee: !attuale.visible_to_employee };
        } else dati = { expires_on: corpo.expires_on ?? '' };
        const r = await this.personali.aggiorna(doc, dati, cid, c.audit);
        return torna(r.success ? 'updated' : `error_${r.error ?? 'unknown'}`);
      }
      case 'delete': {
        if (!doc) return torna('invalid');
        const r = await this.personali.elimina(doc, cid, c.audit);
        return torna(r.success ? 'deleted' : `error_${r.error ?? 'unknown'}`);
      }
    }
    return torna('unknown_action');
  }
}
