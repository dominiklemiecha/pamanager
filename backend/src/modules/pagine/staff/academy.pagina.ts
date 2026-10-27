import { Injectable } from '@nestjs/common';
import type { Response } from 'express';
import { DataSource } from 'typeorm';
import { senzaIsolamento } from '../../../common/database/isolamento';
import type { FileCaricato } from '../../../common/file/caricamenti';
import { AcademyStaffService, MATERIALE_MAX } from '../../academy/academy-staff.service';
import { Attore } from '../../academy/academy.service';
import { AttestatiService, layoutPredefinito, layoutSicuro } from '../../academy/attestati.service';
import { ContestoPagina, EsitoPagina, filePagina, json, Pagina, PaginaPhp, redirect, vista } from '../pagina';

const AREE: Record<string, string> = { admin: 'admin', consulente_lavoro: 'consulente-lavoro', formatore: 'formatore' };

/**
 * Messaggi che la pagina mette nell'indirizzo (?ok=...): solo questi si
 * mostrano. Correzione: il PHP mostrava qualsiasi testo scritto
 * nell'indirizzo; gli errori ora si mostrano senza passare dall'indirizzo.
 */
const OK_ACADEMY = [/^Corso aggiornato$/, /^Corso creato: ora aggiungi i materiali$/, /^Stato del corso aggiornato$/, /^Corso eliminato$/, /^\d+ file caricat[oi]$/,
  /^Link aggiunto$/, /^Titolo aggiornato$/, /^Materiale rimosso$/, /^\d+ dipendent[ei] assegnat[oi]( \(\d+ già assegnati o non attivi\))?$/, /^Assegnazione rimossa$/,
  /^Sollecito inviato$/, /^Sollecito inviato a \d+ dipendent[ei]$/];
const OK_MODELLI = [/^Modello salvato$/, /^Modello duplicato$/, /^Modello eliminato$/];

const attore = (c: ContestoPagina): Attore => ({ tipo: c.u.ruolo, id: c.u.id, ip: c.audit.ip, userAgent: c.audit.userAgent });
const intero = (v: unknown) => Number.parseInt(String(v ?? ''), 10) || 0;
const lista = (v: unknown): string[] => (Array.isArray(v) ? v.map(String) : v === undefined || v === null || v === '' ? [] : [String(v)]);
const ammesso = (testo: string, regole: RegExp[]) => (regole.some((r) => r.test(testo)) ? testo : '');

/**
 * Academy dello staff (includes/_academy-staff.inc.php, admin/academy.php,
 * consulente-lavoro/academy.php, formatore/academy.php): elenco corsi, corso
 * con schede info/materiali/partecipanti/registro, esportazioni e attestati.
 */
@Injectable()
export abstract class AcademyStaffPagina implements Pagina {
  abstract readonly ruoli: string[];
  readonly caricamenti = { dimensione: MATERIALE_MAX, file: 30, suDisco: true };
  constructor(protected readonly ds: DataSource, protected readonly staff: AcademyStaffService, protected readonly attestati: AttestatiService) {}

  private ambito(c: ContestoPagina) {
    const area = AREE[c.u.ruolo];
    return { area, self: `/${area}/academy.php` };
  }

  /** Esportazione del registro (?export=csv|pdf) e ZIP degli attestati (?zip=1). */
  async file(c: ContestoPagina, res: Response): Promise<boolean> {
    const q = c.query;
    const corso = intero(q.course);
    if (corso <= 0 || (q.export === undefined && q.zip === undefined)) return false;
    const cid = c.u.companyId!;
    if (q.export !== undefined) {
      const co = await this.staff.corso(corso, cid);
      if (!co) { res.status(404).type('text/plain').send('Corso non trovato'); return true; }
      const eventi = await this.staff.eventi(cid, corso, q.assignment !== undefined ? intero(q.assignment) : null);
      const base = `registro_${co.title}`.replace(/[^A-Za-z0-9_-]/g, '_');
      if (q.export === 'pdf') {
        const pdf = await this.staff.pdfEventi(eventi, `Registro frequenza - ${co.title}`, cid);
        res.set({ 'Content-Type': 'application/pdf', 'Content-Disposition': `attachment; filename="${base}.pdf"`, 'Content-Length': String(pdf.length) }).send(pdf);
      } else {
        const csv = this.staff.csvEventi(eventi);
        res.set({ 'Content-Type': 'text/csv; charset=utf-8', 'Content-Disposition': `attachment; filename="${base}.csv"` }).send(csv);
      }
      return true;
    }
    const z = await this.attestati.zip(corso, String(q.ids ?? '').split(',').map(intero), cid);
    if ('errore' in z) { res.status(404).type('text/plain').send(z.errore); return true; }
    res.set({ 'Content-Type': 'application/zip', 'Content-Disposition': `attachment; filename="${z.nome}"`, 'Content-Length': String(z.dati.length) }).send(z.dati);
    return true;
  }

  async azione(c: ContestoPagina, corpo: Record<string, any>, file: Record<string, FileCaricato[]>) {
    const { self } = this.ambito(c);
    const cid = c.u.companyId!;
    const corso = intero(c.query.course ?? corpo.course_id);
    const a = attore(c);
    const torna = `course=${corso}`;
    const ok = (query: string, messaggio?: string) => redirect(`${self}${query || messaggio ? '?' : ''}${query}${messaggio ? `${query ? '&' : ''}ok=${encodeURIComponent(messaggio).replace(/%20/g, '+')}` : ''}`);
    const errore = (q: Record<string, string>, err: string, ok2?: string) => this.dati(c, { query: q, errore: err, ok: ok2 });
    switch (String(corpo.action ?? '')) {
      case 'notify_batch': return json(await this.staff.inviaNotifiche(corso, intero(corpo.limit) || 4, cid, a));
      case 'generate_batch': return json(await this.attestati.generaBlocco(corso, String(corpo.ids ?? '').split(',').map(intero), intero(corpo.limit) || 5, cid, a));
      case 'save_course': {
        const r = await this.staff.salvaCorso(corpo, corso > 0 ? corso : null, cid, a);
        if (!r.success) return errore(corso > 0 ? { course: String(corso), tab: 'info' } : { new: '1' }, r.error);
        return ok(`course=${r.id}&tab=${corso > 0 ? 'info' : 'materials'}`, corso > 0 ? 'Corso aggiornato' : 'Corso creato: ora aggiungi i materiali');
      }
      case 'set_status': {
        const r = await this.staff.statoCorso(corso, String(corpo.status ?? ''), cid, a);
        const tab = String(corpo.return_tab ?? 'people');
        return r.success ? ok(`${torna}&tab=${encodeURIComponent(tab)}`, 'Stato del corso aggiornato') : errore({ course: String(corso), tab }, r.error);
      }
      case 'delete_course': {
        const r = await this.staff.eliminaCorso(corso, cid);
        return r.success ? ok('', 'Corso eliminato') : errore({ course: String(corso), tab: 'info' }, r.error);
      }
      case 'add_files': {
        const r = await this.staff.aggiungiFile(corso, [...(file['files[]'] ?? []), ...(file.files ?? [])], cid, a);
        const msg = r.success ? `${r.added}${r.added === 1 ? ' file caricato' : ' file caricati'}` : undefined;
        if (r.success && !r.errors.length) return ok(`${torna}&tab=materials`, msg);
        return errore({ course: String(corso), tab: 'materials' }, r.success ? `Alcuni file non sono stati caricati: ${r.errors.join(' - ')}` : r.error ?? 'Errore', msg);
      }
      case 'add_link': {
        const r = await this.staff.aggiungiLink(corso, String(corpo.title ?? ''), String(corpo.url ?? ''), cid, a);
        return r.success ? ok(`${torna}&tab=materials`, 'Link aggiunto') : errore({ course: String(corso), tab: 'materials' }, r.error);
      }
      case 'rename_material': {
        const r = await this.staff.rinominaMateriale(intero(corpo.material_id), String(corpo.title ?? ''), cid);
        return r.success ? ok(`${torna}&tab=materials`, 'Titolo aggiornato') : errore({ course: String(corso), tab: 'materials' }, r.error);
      }
      case 'move_material':
        await this.staff.spostaMateriale(intero(corpo.material_id), String(corpo.dir ?? ''), cid);
        return ok(`${torna}&tab=materials`);
      case 'delete_material': {
        const r = await this.staff.eliminaMateriale(intero(corpo.material_id), cid, a);
        return r.success ? ok(`${torna}&tab=materials`, 'Materiale rimosso') : errore({ course: String(corso), tab: 'materials' }, r.error);
      }
      case 'assign': {
        const modo = String(corpo.mode ?? 'selected');
        let ids: unknown[];
        if (modo === 'all' || modo === 'department') {
          const tutti = await this.staff.dipendentiAttivi(cid);
          ids = (modo === 'all' ? tutti : tutti.filter((e: any) => e.department_id === intero(corpo.department_id))).map((e: any) => e.id);
        } else ids = lista(corpo['employee_ids[]'] ?? corpo.employee_ids);
        const r = await this.staff.assegna(corso, ids, !!corpo.send_email && corpo.send_email !== '0', cid, a);
        if (!r.success) return errore({ course: String(corso), tab: 'people' }, r.error);
        const msg = `${r.count}${r.count === 1 ? ' dipendente assegnato' : ' dipendenti assegnati'}${r.skipped > 0 ? ` (${r.skipped} già assegnati o non attivi)` : ''}`;
        return ok(`${torna}&tab=people&notify=1`, msg);
      }
      case 'unassign': {
        const r = await this.staff.rimuoviAssegnazione(intero(corpo.assignment_id), cid, a);
        return r.success ? ok(`${torna}&tab=people`, 'Assegnazione rimossa') : errore({ course: String(corso), tab: 'people' }, r.error);
      }
      case 'remind': {
        const r = await this.staff.sollecita(intero(corpo.assignment_id), cid, a);
        return r.success ? ok(`${torna}&tab=people`, 'Sollecito inviato') : errore({ course: String(corso), tab: 'people' }, r.error);
      }
      case 'remind_all': {
        const n = await this.staff.sollecitaTutti(corso, cid, a);
        return ok(`${torna}&tab=people`, `Sollecito inviato a ${n}${n === 1 ? ' dipendente' : ' dipendenti'}`);
      }
    }
    return redirect(corso > 0 ? `${self}?${torna}` : self);
  }

  async dati(c: ContestoPagina, o: { query?: Record<string, string>; errore?: string; ok?: string } = {}): Promise<EsitoPagina> {
    const { area, self } = this.ambito(c);
    const cid = c.u.companyId!;
    const q = o.query ?? c.query;
    const _GET: Record<string, string> = { ...q };
    delete _GET.ok;
    delete _GET.err;
    const corsoId = intero(q.course);
    let tab = String(q.tab ?? 'people');
    const V: Record<string, unknown> = {
      __user: { id: c.u.id, role: c.u.ruolo, department_id: c.u.departmentId ?? null }, __role: c.u.ruolo, readOnly: false, deptScope: null, area, self,
      courseId: corsoId, _GET, okMessage: o.ok ?? ammesso(String(c.query.ok ?? ''), OK_ACADEMY), errMessage: o.errore ?? '', course: null,
    };
    if (corsoId > 0) {
      const corso = await this.staff.corso(corsoId, cid);
      if (!corso) return this.dati(c, { query: {}, errore: 'Corso non trovato' });
      const assegnazioni = await this.staff.assegnazioni(corsoId, cid);
      Object.assign(V, {
        course: corso, materials: await this.staff.materiali(corsoId, cid), assignments: assegnazioni,
        pendingNotify: await this.staff.notificheInCoda(corsoId, cid),
        missingCerts: assegnazioni.filter((x: any) => x.completed_at && !x.certificate_id).length, withCerts: assegnazioni.filter((x: any) => x.certificate_id).length,
      });
      if (tab === 'log') {
        const la = q.assignment !== undefined ? intero(q.assignment) : null;
        V.logAssignment = la;
        V.events = await this.staff.eventi(cid, corsoId, la);
        V.chain = await this.staff.catena(cid);
        V.__logAssignmentRow = la !== null ? await this.staff.assegnazione(la, cid) : null;
      }
      if (tab === 'info' || tab === 'people') V.templates = await this.attestati.modelli(cid);
      if (tab === 'people') {
        const assegnati = new Set(assegnazioni.map((x: any) => x.employee_id));
        V.assignable = (await this.staff.dipendentiAttivi(cid)).filter((e: any) => !assegnati.has(e.id));
        V.departments = await this.ds.query('SELECT id, name FROM departments WHERE company_id = $1 ORDER BY name', [cid]);
      }
    } else {
      V.courses = await this.staff.corsi(cid);
      V.templates = await this.attestati.modelli(cid);
      V.formatoriCount = c.u.ruolo === 'admin' ? await this.staff.formatori(cid) : 0;
    }
    V.tab = tab;
    return vista(V.course ? `Academy - ${(V.course as any).title}` : 'Academy', V);
  }
}

@Injectable()
@PaginaPhp('admin/academy')
export class AcademyAdminPagina extends AcademyStaffPagina { readonly ruoli = ['admin']; }

@Injectable()
@PaginaPhp('consulente-lavoro/academy')
export class AcademyConsulentePagina extends AcademyStaffPagina { readonly ruoli = ['consulente_lavoro']; }

@Injectable()
@PaginaPhp('formatore/academy')
export class AcademyFormatorePagina extends AcademyStaffPagina { readonly ruoli = ['formatore']; }

/**
 * Modelli di attestato (includes/_academy-templates.inc.php): elenco ed
 * editor visuale, immagini, anteprima PDF, duplica ed elimina.
 */
@Injectable()
export abstract class ModelliAttestatoPagina implements Pagina {
  abstract readonly ruoli: string[];
  readonly caricamenti = { dimensione: 6 * 1024 * 1024, file: 1 };
  constructor(protected readonly ds: DataSource, protected readonly staff: AcademyStaffService, protected readonly attestati: AttestatiService) {}

  private self(c: ContestoPagina) { return `/${AREE[c.u.ruolo] ?? 'admin'}/academy-templates.php`; }

  async azione(c: ContestoPagina, corpo: Record<string, any>, file: Record<string, FileCaricato[]>) {
    const cid = c.u.companyId!;
    const self = this.self(c);
    const ok = (q: string) => redirect(`${self}?${q}`);
    switch (String(corpo.action ?? '')) {
      case 'upload_image': return json(this.attestati.caricaImmagine(file.image?.[0], cid));
      case 'preview': {
        const pdf = await this.attestati.anteprima(String(corpo.orientation ?? 'landscape'), String(corpo.layout_json ?? '[]'), cid);
        return filePagina({ dati: pdf, tipo: 'application/pdf', nome: 'anteprima-attestato.pdf', inLinea: true });
      }
      case 'save': {
        const id = intero(corpo.id);
        const r = await this.attestati.salvaModello(String(corpo.name ?? ''), String(corpo.orientation ?? 'landscape'), String(corpo.layout_json ?? '[]'), id > 0 ? id : null, cid, c.u.id);
        if (r.success) return ok(`edit=${r.id}&ok=Modello+salvato`);
        return this.dati(c, { query: id > 0 ? { edit: String(id) } : { new: '1' }, errore: r.error });
      }
      case 'duplicate': {
        const r = await this.attestati.duplicaModello(intero(corpo.id), cid, c.u.id);
        return r.success ? ok(`edit=${r.id}&ok=Modello+duplicato`) : this.dati(c, { query: {}, errore: r.error });
      }
      case 'delete': {
        const r = await this.attestati.eliminaModello(intero(corpo.id), cid);
        return r.success ? ok('ok=Modello+eliminato') : this.dati(c, { query: {}, errore: r.error });
      }
    }
    return redirect(self);
  }

  async dati(c: ContestoPagina, o: { query?: Record<string, string>; errore?: string } = {}) {
    const cid = c.u.companyId!;
    const q = o.query ?? c.query;
    let edit: any = null;
    let editMode = q.new !== undefined;
    if (q.edit !== undefined) {
      edit = await this.attestati.modello(intero(q.edit), cid);
      editMode = edit !== null;
    }
    const esempio = await this.attestati.valoriEsempio(cid);
    const anteprime: Array<{ label: string; values: Record<string, string> }> = [];
    if (editMode) {
      anteprime.push({ label: 'Dati di esempio', values: esempio });
      const oggi = this.staff.oggi();
      for (const e of (await this.staff.dipendentiAttivi(cid)).slice(0, 300)) {
        anteprime.push({
          label: `${e.last_name} ${e.first_name}`,
          values: this.attestati.valori({ ...e, company_name: esempio['{AZIENDA}'], course_title: esempio['{CORSO}'], duration_hours: 4, started_day: oggi, completed_day: oggi },
            esempio['{NUMERO_ATTESTATO}'], oggi, '0'.repeat(32)),
        });
      }
    }
    const [az] = await senzaIsolamento(() => this.ds.query('SELECT name FROM companies WHERE id = $1', [cid]));
    return vista('Modelli attestato', {
      __role: c.u.ruolo, area: AREE[c.u.ruolo] ?? 'admin', self: this.self(c), _GET: { ...q, ok: undefined, err: undefined },
      okMessage: ammesso(String(c.query.ok ?? ''), OK_MODELLI), errMessage: o.errore ?? '', edit, editMode,
      layoutJson: edit ? layoutSicuro(edit.layout_json) : JSON.stringify(layoutPredefinito()), orientation: edit?.orientation ?? 'landscape', previewSets: anteprime,
      __templates: await this.attestati.modelli(cid), __sampleValues: esempio, __defaultLayout: layoutPredefinito(), __companyName: az?.name ?? '',
    });
  }
}

@Injectable()
@PaginaPhp('admin/academy-templates')
export class ModelliAttestatoAdminPagina extends ModelliAttestatoPagina { readonly ruoli = ['admin']; }

@Injectable()
@PaginaPhp('consulente-lavoro/academy-templates')
export class ModelliAttestatoConsulentePagina extends ModelliAttestatoPagina { readonly ruoli = ['consulente_lavoro']; }

@Injectable()
@PaginaPhp('formatore/academy-templates')
export class ModelliAttestatoFormatorePagina extends ModelliAttestatoPagina { readonly ruoli = ['formatore']; }
