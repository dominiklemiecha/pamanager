import { Injectable } from '@nestjs/common';
import { DataSource } from 'typeorm';
import { AuditService } from '../../audit/audit.service';
import { ContestoPagina, PaginaPhp, Pagina, redirect, vista } from '../pagina';

type Esito = { success: true } | { success: false; error: string };
type Ctx = ContestoPagina['audit'];

/** Reparti dell'azienda (Department.php): ogni operazione resta nell'azienda attiva. */
@Injectable()
export class RepartiService {
  constructor(private readonly ds: DataSource, private readonly audit: AuditService) {}

  elenco(cid: number, soloAttivi = true) {
    return this.ds.query(
      `SELECT d.*, (SELECT COUNT(*) FROM employees e WHERE e.department_id = d.id AND e.is_active = true)::int AS employee_count
         FROM departments d WHERE d.company_id = $1 ${soloAttivi ? 'AND d.is_active = true' : ''} ORDER BY d.name ASC`, [cid]);
  }

  async perId(id: number, cid: number) {
    const [d] = await this.ds.query(
      `SELECT d.*, (SELECT COUNT(*) FROM employees e WHERE e.department_id = d.id)::int AS employee_count
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
 * Dettaglio di un reparto (admin/department-detail.php): dipendenti del reparto.
 * Correzione: reparti e dipendenti solo dell'azienda attiva. I responsabili di
 * reparto non esistono piu' nel nuovo stack.
 */
@Injectable()
@PaginaPhp('admin/department-detail')
export class RepartoDettaglioPagina implements Pagina {
  readonly ruoli = ['admin'];
  constructor(private readonly ds: DataSource, private readonly reparti: RepartiService) {}

  async azione(c: ContestoPagina, corpo: Record<string, string>) {
    const cid = c.u.companyId!;
    const id = Number.parseInt(String(c.query.id ?? ''), 10) || 0;
    if (!id) return redirect('/admin/departments.php');
    if (!(await this.reparti.perId(id, cid))) return redirect('/admin/departments.php?error=not_found');
    const torna = (m: string) => redirect(`/admin/department-detail.php?id=${id}&message=${m}`);
    const eid = Number.parseInt(corpo.employee_id ?? '', 10) || 0;
    let r: { success: boolean; error?: string } | null = null;
    switch (corpo.action ?? '') {
      case 'assign_employee':
        if (eid) { r = await this.reparti.assegnaDipendente(eid, id, cid, c.audit); if (r.success) return torna('employee_assigned'); }
        break;
      case 'remove_employee':
        if (eid) { r = await this.reparti.assegnaDipendente(eid, null, cid, c.audit); if (r.success) return torna('employee_removed'); }
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
      employee_assigned: 'Dipendente assegnato al reparto', employee_removed: 'Dipendente rimosso dal reparto',
    };
    const deptEmployees = await this.ds.query(
      `SELECT id, first_name, last_name, fiscal_code, email, photo_path, department_id, is_active FROM employees
        WHERE department_id = $1 AND company_id = $2 ORDER BY last_name, first_name`, [id, cid]);
    const tutti = await this.ds.query(
      `SELECT e.id, e.first_name, e.last_name, e.department_id, d.name AS department_name FROM employees e
         LEFT JOIN departments d ON e.department_id = d.id WHERE e.company_id = $1 AND e.is_active = true ORDER BY e.last_name ASC, e.first_name ASC`, [cid]);
    return vista(`Reparto: ${department.name}`, {
      message: c.query.message !== undefined ? MESSAGGI[String(c.query.message)] ?? '' : '', error,
      department, deptId: id, deptEmployees,
      unassignedEmployees: tutti.filter((e: any) => !e.department_id || e.department_id !== id),
    });
  }
}
