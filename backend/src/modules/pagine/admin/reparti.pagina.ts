import { Injectable } from '@nestjs/common';
import { DataSource } from 'typeorm';
import { senzaIsolamento } from '../../../common/database/isolamento';
import { AuditService } from '../../audit/audit.service';
import { SessioniService } from '../../auth/sessioni.service';
import { UtentiService } from '../../utenti/utenti.service';
import { ContestoPagina, PaginaPhp, Pagina, redirect, vista } from '../pagina';

type Esito = { success: true } | { success: false; error: string };
type Ctx = ContestoPagina['audit'];

/** Reparti dell'azienda (Department.php): ogni operazione resta nell'azienda attiva. */
@Injectable()
export class RepartiService {
  constructor(private readonly ds: DataSource, private readonly audit: AuditService, private readonly sessioni: SessioniService) {}

  elenco(cid: number, soloAttivi = true) {
    return this.ds.query(
      `SELECT d.*, (SELECT COUNT(*) FROM employees e WHERE e.department_id = d.id AND e.is_active = true)::int AS employee_count,
              (SELECT COUNT(*) FROM users u WHERE u.department_id = d.id AND u.role = 'admin_reparto' AND u.is_active = true)::int AS admin_count
         FROM departments d WHERE d.company_id = $1 ${soloAttivi ? 'AND d.is_active = true' : ''} ORDER BY d.name ASC`, [cid]);
  }

  async perId(id: number, cid: number) {
    const [d] = await this.ds.query(
      `SELECT d.*, (SELECT COUNT(*) FROM employees e WHERE e.department_id = d.id)::int AS employee_count,
              (SELECT COUNT(*) FROM users u WHERE u.department_id = d.id AND u.role = 'admin_reparto')::int AS admin_count
         FROM departments d WHERE d.id = $1 AND d.company_id = $2`, [id, cid]);
    return d ?? null;
  }

  private async esiste(campo: 'name' | 'code', valore: string, cid: number, escludi = 0) {
    return (await this.ds.query(`SELECT 1 FROM departments WHERE ${campo} = $1 AND company_id = $2 AND id <> $3`, [valore, cid, escludi])).length > 0;
  }

  async crea(d: { name: string; code: string; description: string }, cid: number, ctx: Ctx): Promise<Esito> {
    const nome = String(d.name ?? '').trim();
    if (Buffer.byteLength(nome) < 2) return { success: false, error: 'Nome reparto obbligatorio (min. 2 caratteri)' };
    if (Buffer.byteLength(String(d.code ?? '').trim()) < 2) return { success: false, error: 'Codice reparto obbligatorio (min. 2 caratteri)' };
    const codice = String(d.code).trim().toUpperCase();
    if (!/^[A-Z0-9_]+$/.test(codice)) return { success: false, error: 'Codice può contenere solo lettere maiuscole, numeri e underscore' };
    if (nome.length > 100 || codice.length > 20) return { success: false, error: 'Nome (max 100) o codice (max 20) troppo lunghi' };
    if (await this.esiste('name', nome, cid)) return { success: false, error: 'Nome reparto già esistente' };
    if (await this.esiste('code', codice, cid)) return { success: false, error: 'Codice reparto già esistente' };
    try {
      const [r] = await this.ds.query(`INSERT INTO departments (company_id, name, code, description, is_active) VALUES ($1, $2, $3, $4, true) RETURNING id`,
        [cid, nome, codice, String(d.description ?? '').trim() || null]);
      await this.audit.log('department_created', ctx.tipo, ctx.id, ctx, 'department', r.id, null, d);
      return { success: true };
    } catch {
      return { success: false, error: 'Errore durante la creazione del reparto' };
    }
  }

  async aggiorna(id: number, d: { name: string; code: string; description: string }, cid: number, ctx: Ctx): Promise<Esito> {
    const rep = await this.perId(id, cid);
    if (!rep) return { success: false, error: 'Reparto non trovato' };
    const campi: Record<string, unknown> = {};
    if (d.name) {
      const nome = String(d.name).trim();
      if (nome.length > 100) return { success: false, error: 'Nome reparto troppo lungo (max 100)' };
      if (await this.esiste('name', nome, cid, id)) return { success: false, error: 'Nome reparto già esistente' };
      campi.name = nome;
    }
    if (d.code) {
      const codice = String(d.code).trim().toUpperCase();
      if (!/^[A-Z0-9_]+$/.test(codice)) return { success: false, error: 'Codice può contenere solo lettere maiuscole, numeri e underscore' };
      if (codice.length > 20) return { success: false, error: 'Codice troppo lungo (max 20)' };
      if (await this.esiste('code', codice, cid, id)) return { success: false, error: 'Codice reparto già esistente' };
      campi.code = codice;
    }
    campi.description = String(d.description ?? '').trim() || null;
    try {
      const k = Object.keys(campi);
      await this.ds.query(`UPDATE departments SET ${k.map((x, i) => `${x} = $${i + 2}`).join(', ')} WHERE id = $1`, [id, ...k.map((x) => campi[x])]);
      await this.audit.log('department_updated', ctx.tipo, ctx.id, ctx, 'department', id, rep, campi);
      return { success: true };
    } catch {
      return { success: false, error: "Errore durante l'aggiornamento" };
    }
  }

  async elimina(id: number, cid: number, ctx: Ctx): Promise<Esito> {
    const rep = await this.perId(id, cid);
    if (!rep) return { success: false, error: 'Reparto non trovato' };
    if (rep.employee_count > 0) return { success: false, error: 'Impossibile eliminare: ci sono dipendenti assegnati a questo reparto' };
    if (rep.admin_count > 0) return { success: false, error: 'Impossibile eliminare: ci sono admin assegnati a questo reparto' };
    try {
      await this.ds.query('DELETE FROM departments WHERE id = $1', [id]);
      await this.audit.log('department_deleted', ctx.tipo, ctx.id, ctx, 'department', id, rep, null);
      return { success: true };
    } catch {
      return { success: false, error: "Errore durante l'eliminazione" };
    }
  }

  /** Department::assignEmployee: dipendente e reparto dell'azienda attiva. */
  async assegnaDipendente(employeeId: number, repartoId: number | null, cid: number, ctx: Ctx): Promise<Esito> {
    const [e] = await this.ds.query('SELECT id, first_name, last_name FROM employees WHERE id = $1 AND company_id = $2', [employeeId, cid]);
    if (!e) return { success: false, error: 'Dipendente non trovato' };
    if (repartoId !== null && !(await this.perId(repartoId, cid))) return { success: false, error: 'Reparto non trovato' };
    try {
      await this.ds.query('UPDATE employees SET department_id = $2 WHERE id = $1', [employeeId, repartoId]);
      await this.audit.log('employee_assigned', ctx.tipo, ctx.id, ctx, 'department', repartoId ?? 0, null,
        { employee_id: employeeId, employee_name: `${e.first_name} ${e.last_name}` });
      return { success: true };
    } catch {
      return { success: false, error: "Errore durante l'assegnazione" };
    }
  }

  /**
   * Department::removeAdmin: il responsabile torna senza reparto (ruolo
   * "accountant", come nel PHP). Correzioni: solo un responsabile di questo
   * reparto (il PHP cambiava il ruolo di qualsiasi utente, anche di altre
   * aziende); l'account viene disattivato e le sue sessioni chiuse, perche'
   * da commercialista vedrebbe buste paga e certificati di tutta l'azienda
   * invece del solo reparto. L'HR puo' riattivarlo dagli utenti esterni.
   */
  async rimuoviResponsabile(userId: number, repartoId: number, cid: number, ctx: Ctx): Promise<Esito> {
    const [u] = await senzaIsolamento(() => this.ds.query(
      `SELECT u.id, u.name, u.department_id FROM users u JOIN departments d ON d.id = u.department_id
        WHERE u.id = $1 AND u.role = 'admin_reparto' AND u.department_id = $2 AND d.company_id = $3`, [userId, repartoId, cid]));
    if (!u) return { success: false, error: 'Utente non trovato' };
    try {
      await senzaIsolamento(() => this.ds.query(`UPDATE users SET role = 'accountant', department_id = NULL, is_active = false WHERE id = $1`, [userId]));
      await this.sessioni.chiudiTutte('user', userId, 'accesso_revocato');
      await this.audit.log('admin_removed', ctx.tipo, ctx.id, ctx, 'department', u.department_id ?? 0, null, { user_id: userId, user_name: u.name });
      return { success: true };
    } catch {
      return { success: false, error: 'Errore durante la rimozione' };
    }
  }
}

/**
 * Reparti (admin/departments.php): elenco, creazione, modifica, eliminazione.
 * Correzione: modifica ed eliminazione solo dei reparti dell'azienda attiva.
 */
@Injectable()
@PaginaPhp('admin/departments')
export class RepartiPagina implements Pagina {
  readonly ruoli = ['admin'];
  constructor(private readonly reparti: RepartiService) {}

  async azione(c: ContestoPagina, corpo: Record<string, string>) {
    const cid = c.u.companyId!;
    const id = Number.parseInt(corpo.department_id ?? '', 10) || 0;
    const d = { name: corpo.name ?? '', code: corpo.code ?? '', description: corpo.description ?? '' };
    let r: Esito | null = null;
    let fatto = '';
    if (corpo.action === 'create') { r = await this.reparti.crea(d, cid, c.audit); fatto = 'created'; }
    if (corpo.action === 'update' && id) { r = await this.reparti.aggiorna(id, d, cid, c.audit); fatto = 'updated'; }
    if (corpo.action === 'delete' && id) { r = await this.reparti.elimina(id, cid, c.audit); fatto = 'deleted'; }
    if (r?.success) return redirect(`/admin/departments.php?message=${fatto}`);
    return this.dati(c, r && !r.success ? r.error : '');
  }

  async dati(c: ContestoPagina, error = '') {
    const cid = c.u.companyId!;
    const q = c.query;
    const MESSAGGI: Record<string, string> = {
      created: 'Reparto creato con successo', updated: 'Reparto aggiornato con successo', deleted: 'Reparto eliminato con successo', toggled: 'Stato reparto aggiornato',
    };
    const departments = await this.reparti.elenco(cid, false);
    const edit = q.edit ? await this.reparti.perId(Number.parseInt(String(q.edit), 10) || 0, cid) : null;
    const showForm = !!q.edit || q.action === 'new';
    let attivi = 0;
    let dipendenti = 0;
    for (const d of departments) { if (d.is_active) attivi++; dipendenti += Number(d.employee_count ?? 0); }
    return vista(edit ? 'Modifica Reparto' : showForm ? 'Nuovo Reparto' : 'Gestione Reparti', {
      message: q.message !== undefined ? MESSAGGI[String(q.message)] ?? '' : '', error,
      departments, editDepartment: edit, showForm,
      __dpTotal: departments.length, __dpActive: attivi, __dpEmployees: dipendenti,
    });
  }
}

/**
 * Dettaglio di un reparto (admin/department-detail.php): responsabili e dipendenti.
 * Correzione: reparti, dipendenti e responsabili solo dell'azienda attiva.
 */
@Injectable()
@PaginaPhp('admin/department-detail')
export class RepartoDettaglioPagina implements Pagina {
  readonly ruoli = ['admin'];
  constructor(private readonly ds: DataSource, private readonly reparti: RepartiService, private readonly utenti: UtentiService) {}

  async azione(c: ContestoPagina, corpo: Record<string, string>) {
    const cid = c.u.companyId!;
    const id = Number.parseInt(String(c.query.id ?? ''), 10) || 0;
    if (!id) return redirect('/admin/departments.php');
    if (!(await this.reparti.perId(id, cid))) return redirect('/admin/departments.php?error=not_found');
    const torna = (m: string) => redirect(`/admin/department-detail.php?id=${id}&message=${m}`);
    const eid = Number.parseInt(corpo.employee_id ?? '', 10) || 0;
    const uid = Number.parseInt(corpo.user_id ?? '', 10) || 0;
    let r: { success: boolean; error?: string } | null = null;
    switch (corpo.action ?? '') {
      case 'create_admin':
        r = await this.utenti.crea({ username: corpo.admin_username ?? '', password: corpo.admin_password ?? '', name: corpo.admin_name ?? '',
          email: corpo.admin_email ?? '', role: 'admin_reparto', department_id: id }, cid, c.audit);
        if (r.success) return torna('admin_created');
        break;
      case 'assign_employee':
        if (eid) { r = await this.reparti.assegnaDipendente(eid, id, cid, c.audit); if (r.success) return torna('employee_assigned'); }
        break;
      case 'remove_employee':
        if (eid) { r = await this.reparti.assegnaDipendente(eid, null, cid, c.audit); if (r.success) return torna('employee_removed'); }
        break;
      case 'remove_admin':
        if (uid) { r = await this.reparti.rimuoviResponsabile(uid, id, cid, c.audit); if (r.success) return torna('admin_removed'); }
        break;
    }
    return this.dati(c, r?.error ?? '');
  }

  async dati(c: ContestoPagina, error = '') {
    const cid = c.u.companyId!;
    const id = Number.parseInt(String(c.query.id ?? ''), 10) || 0;
    if (!id) return redirect('/admin/departments.php');
    const department = await this.reparti.perId(id, cid);
    if (!department) return redirect('/admin/departments.php?error=not_found');
    const MESSAGGI: Record<string, string> = {
      admin_created: 'Admin reparto creato con successo', employee_assigned: 'Dipendente assegnato al reparto',
      employee_removed: 'Dipendente rimosso dal reparto', admin_removed: 'Admin rimosso',
    };
    const deptEmployees = await this.ds.query(
      `SELECT id, first_name, last_name, fiscal_code, email, photo_path, department_id, is_active FROM employees
        WHERE department_id = $1 AND company_id = $2 ORDER BY last_name, first_name`, [id, cid]);
    const deptAdmins = await senzaIsolamento(() => this.ds.query(
      `SELECT id, username, name, email FROM users WHERE department_id = $1 AND role = 'admin_reparto' AND is_active = true ORDER BY name`, [id]));
    const tutti = await this.ds.query(
      `SELECT e.id, e.first_name, e.last_name, e.department_id, d.name AS department_name FROM employees e
         LEFT JOIN departments d ON e.department_id = d.id WHERE e.company_id = $1 AND e.is_active = true ORDER BY e.last_name ASC, e.first_name ASC`, [cid]);
    return vista(`Reparto: ${department.name}`, {
      message: c.query.message !== undefined ? MESSAGGI[String(c.query.message)] ?? '' : '', error,
      department, deptId: id, deptEmployees, deptAdmins,
      unassignedEmployees: tutti.filter((e: any) => !e.department_id || e.department_id !== id),
    });
  }
}
