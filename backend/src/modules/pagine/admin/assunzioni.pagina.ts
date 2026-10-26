import { Injectable } from '@nestjs/common';
import type { Response } from 'express';
import * as fs from 'fs';
import { DataSource } from 'typeorm';
import { senzaIsolamento } from '../../../common/database/isolamento';
import type { FileCaricato } from '../../../common/file/caricamenti';
import { mimeDaContenuto } from '../../../common/file/caricamenti';
import { AssunzioniService, EsitoAssunzione, EsitoInvio } from '../../assunzioni/assunzioni.service';
import { ContestoPagina, PaginaPhp, Pagina, redirect, vista } from '../pagina';

/** Parametri dell'indirizzo che nel PHP portavano testo libero (errori, password): ora li mette solo il backend. */
const TESTO_LIBERO = ['err', 'bypass_err', 'del_err', 'email_err', 'notify_err', 'pwd'];
const PAGINA = '/admin/hire-requests.php';

type Opzioni = { azione?: string; id?: number; errore?: string; form?: Record<string, any>; get?: Record<string, string> };

/**
 * Richieste di assunzione dell'HR (admin/hire-requests.php): elenco,
 * dettaglio, nuova richiesta/bozza, modifica, invio al consulente,
 * approvazione o rifiuto dei prospetti, assunzione diretta, eliminazione,
 * file della pratica.
 *
 * Correzioni: la password temporanea e i messaggi di errore non passano
 * piu' nell'indirizzo (il PHP li metteva nella query string, finendo in
 * cronologia e log, e mostrava qualsiasi testo scritto nell'indirizzo); ogni
 * richiesta solo dell'azienda attiva; i file si scaricano con il tipo letto
 * dal contenuto, non quello dichiarato da chi li ha caricati.
 */
@Injectable()
@PaginaPhp('admin/hire-requests')
export class AssunzioniPagina implements Pagina {
  readonly ruoli = ['admin'];
  constructor(private readonly ds: DataSource, private readonly assunzioni: AssunzioniService) {}

  /** ?action=file&id=..&file_id=..: file della pratica, mostrato nel browser (PDF e immagini). */
  async file(c: ContestoPagina, res: Response): Promise<boolean> {
    if (c.query.action !== 'file') return false;
    const id = Number.parseInt(String(c.query.id ?? ''), 10) || 0;
    if (id <= 0) return false;
    const testoSemplice = (codice: number, t: string) => { res.status(codice).type('text/plain; charset=utf-8').send(t); return true; };
    const hr = await this.assunzioni.perId(id, c.u.companyId!);
    if (!hr) return testoSemplice(404, 'Richiesta non trovata');
    const [f] = await this.ds.query('SELECT * FROM hire_request_files WHERE id = $1 AND hire_request_id = $2', [Number.parseInt(String(c.query.file_id ?? ''), 10) || 0, id]);
    if (!f) return testoSemplice(404, 'File non trovato');
    const p = this.assunzioni.percorso(f.file_path);
    if (!p) return testoSemplice(404, 'File non disponibile sul filesystem');
    const nome = String(f.original_name ?? '').replace(/[^a-zA-Z0-9._-]/g, '_');
    res.setHeader('Content-Type', mimeDaContenuto(p, f.original_name ?? ''));
    res.setHeader('Content-Disposition', `inline; filename="${nome}"`);
    res.setHeader('Content-Length', String(fs.statSync(p).size));
    res.setHeader('X-Content-Type-Options', 'nosniff');
    res.setHeader('X-Frame-Options', 'SAMEORIGIN');
    fs.createReadStream(p).pipe(res);
    return true;
  }

  private async esitoAssunzione(id: number, r: Extract<EsitoAssunzione, { success: true }>, c: ContestoPagina, base: Record<string, string>) {
    if (r.email_sent) return redirect(`${PAGINA}?id=${id}&${new URLSearchParams(base)}`);
    const get: Record<string, string> = { ...base, email_err: r.email_error ?? 'Credenziali non inviate via email' };
    if (r.temp_password) get.pwd = r.temp_password;
    return this.dati(c, { azione: 'list', id, get });
  }

  private esitoInvio(id: number, r: Extract<EsitoInvio, { success: true }>, c: ContestoPagina, base: Record<string, string>) {
    if (r.notified) return redirect(`${PAGINA}?id=${id}&${new URLSearchParams({ ...base, notified: '1' })}`);
    return this.dati(c, { azione: 'list', id, get: { ...base, notified: '0', ...(r.notify_error ? { notify_err: r.notify_error } : {}) } });
  }

  async azione(c: ContestoPagina, corpo: Record<string, any>, file: Record<string, FileCaricato[]>) {
    const cid = c.u.companyId!;
    const id = Number.parseInt(String(corpo.id ?? '0'), 10) || 0;
    const form: Record<string, any> = { ...corpo, work_days: corpo['work_days[]'] ?? corpo.work_days ?? [] };
    delete form.csrf_token;
    switch (corpo.action ?? '') {
      case 'approve': {
        const r = await this.assunzioni.approva(id, corpo, file, cid, c.audit);
        if (r.success) return this.esitoAssunzione(id, r, c, { approved: '1' });
        return this.dati(c, { azione: 'list', id, get: { err: r.error } });
      }
      case 'reject': {
        const r = await this.assunzioni.rifiuta(id, String(corpo.reason ?? ''), cid, c.u.id);
        if (r.success) return redirect(`${PAGINA}?id=${id}&rejected=1`);
        return this.dati(c, { azione: 'list', id, get: { err: r.error } });
      }
      case 'delete': {
        const r = await this.assunzioni.elimina(id, cid, c.audit);
        if (r.success) return redirect(`${PAGINA}?deleted=1`);
        return this.dati(c, { azione: 'list', id, get: { del_err: r.error } });
      }
      case 'hire_direct_existing': {
        const r = await this.assunzioni.assumiDaRichiesta(id, corpo, cid, c.audit);
        if (r.success) return this.esitoAssunzione(id, r, c, { hired: '1' });
        return this.dati(c, { azione: 'list', id, get: { bypass_err: r.error } });
      }
      case 'hire_direct': {
        const r = await this.assunzioni.assumiDiretto(corpo, file, cid, c.audit);
        if (r.success) return this.esitoAssunzione(r.id, r, c, { hired: '1' });
        return this.dati(c, { azione: 'direct', id: 0, errore: r.error, form });
      }
      case 'create': {
        const bozza = (corpo.submit_mode ?? 'send') === 'draft';
        const r = await this.assunzioni.crea(corpo, file, bozza, cid, c.u.id);
        if (!r.success) return this.dati(c, { azione: 'new', id: 0, errore: r.error, form });
        if (bozza) return redirect(`${PAGINA}?id=${r.id}&saved=1`);
        return this.esitoInvio(r.id, r, c, { created: '1' });
      }
      case 'update': {
        const r = await this.assunzioni.aggiorna(id, corpo, file, cid, c.u.id);
        if (!r.success) return this.dati(c, { azione: 'edit', id, errore: r.error, form });
        if ((corpo.submit_mode ?? '') !== 'send') return redirect(`${PAGINA}?id=${id}&saved=1`);
        const s = await this.assunzioni.invia(id, cid);
        if (s.success) return this.esitoInvio(id, s, c, { sent: '1' });
        return this.dati(c, { azione: 'list', id, get: { err: s.error } });
      }
      case 'send': {
        const r = await this.assunzioni.invia(id, cid);
        if (r.success) return this.esitoInvio(id, r, c, { sent: '1' });
        return this.dati(c, { azione: 'list', id, get: { err: r.error } });
      }
    }
    return this.dati(c);
  }

  async dati(c: ContestoPagina, o: Opzioni = {}) {
    const cid = c.u.companyId!;
    const q = Object.fromEntries(Object.entries(c.query).filter(([k]) => !TESTO_LIBERO.includes(k)));
    const action = o.azione ?? (q.action !== undefined ? String(q.action) : 'list');
    const id = o.id ?? (q.id !== undefined ? Number.parseInt(String(q.id), 10) || 0 : 0);
    const _GET = o.get ? { ...(o.azione === 'list' ? {} : q), id: String(id), ...o.get } : q;
    const V: Record<string, unknown> = { action, id, message: '', error: o.errore ?? '', user: { id: c.u.id }, _GET, _POST: {}, __cid: cid };
    if (o.form) V.form = o.form;
    const reparti = () => this.ds.query('SELECT id, name FROM departments WHERE company_id = $1 AND is_active = true ORDER BY name', [cid]);
    const azienda = async () => (await senzaIsolamento(() => this.ds.query('SELECT id, name FROM companies WHERE id = $1', [cid])))[0] ?? null;
    if (id > 0 && !['new', 'edit', 'direct'].includes(action)) {
      const hr = await this.assunzioni.perId(id, cid);
      V.__hr = hr;
      if (hr) {
        V.__files = await this.assunzioni.allegati(id);
        V.__consulente = await this.assunzioni.consulente(cid);
        V.__deptsDb = await reparti();
      }
    } else if (action === 'new' || action === 'edit') {
      if (action === 'edit') V.__hr = await this.assunzioni.perId(id, cid);
      V.__company = await azienda();
      V.__consulente = await this.assunzioni.consulente(cid);
    } else if (action === 'direct') {
      V.__company = await azienda();
      V.__deptsDb = await reparti();
    } else {
      V.__rows = await this.assunzioni.elenco(cid);
    }
    return vista('Richieste di assunzione', V);
  }
}
