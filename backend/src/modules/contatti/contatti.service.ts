import { Injectable } from '@nestjs/common';
import { DataSource } from 'typeorm';
import type { UtenteCorrente } from '../auth/auth.types';
import { TenantService } from '../auth/tenant.service';

export type TipoContatto = 'admin' | 'consulente_lavoro' | 'employee';
export interface Contatto { id: number; name?: string; first_name?: string; last_name?: string; photo_path: string | null; company_id: number | null; department_id: number | null }
export type Contatti = Partial<Record<TipoContatto, Contatto[]>>;

/** Gruppi visibili per ruolo, nello stesso ordine di Chat::getAvailableContacts. */
const GRUPPI: Record<string, TipoContatto[]> = {
  admin: ['consulente_lavoro', 'employee'],
  consulente_lavoro: ['admin', 'employee'],
  employee: ['admin', 'consulente_lavoro', 'employee'],
};

/**
 * Persone che un utente puo' contattare in chat o invitare in calendario
 * (Chat::getAvailableContacts, User::getAll, Employee::getAll): solo
 * l'azienda corrente, piu' lo staff condiviso collegato con user_companies.
 * Correzione: lo staff disattivato non compare (il PHP lo elencava).
 */
@Injectable()
export class ContattiService {
  constructor(private readonly ds: DataSource, private readonly tenant: TenantService) {}

  async perUtente(u: UtenteCorrente): Promise<Contatti> {
    const cid = u.companyId!;
    const tipo = u.tipo === 'employee' ? 'employee' : u.ruolo;
    const out: Contatti = {};
    for (const g of GRUPPI[tipo] ?? []) {
      if (g === 'employee') {
        const tutti = await this.dipendenti(cid, null);
        out.employee = tipo === 'employee' ? tutti.filter((e) => e.id !== u.id) : tutti;
      } else {
        out[g] = await this.staff(g, cid, u);
      }
    }
    return out;
  }

  /** Il contatto e' tra quelli consentiti? (inviti e chat accettano solo questi). */
  async consentito(u: UtenteCorrente, tipo: string, id: number): Promise<boolean> {
    const c = await this.perUtente(u);
    return !!c[tipo as TipoContatto]?.some((x) => x.id === id);
  }

  private async dipendenti(cid: number, reparto: number | null): Promise<Contatto[]> {
    const p: unknown[] = [cid];
    let sql = `SELECT e.id, e.first_name, e.last_name, e.photo_path, e.company_id, e.department_id
                 FROM employees e WHERE e.company_id = $1 AND e.is_active = true`;
    if (reparto !== null) { p.push(reparto); sql += ' AND e.department_id = $2'; }
    return this.ds.query(sql + ' ORDER BY e.last_name ASC, e.first_name ASC, e.id ASC', p);
  }

  private async staff(ruolo: TipoContatto, cid: number, u: UtenteCorrente): Promise<Contatto[]> {
    const p: unknown[] = [cid, ruolo];
    let filtro = 'u.company_id = $1';
    if (ruolo === 'admin') {
      // Admin senza azienda: solo il globale "vero" o chi e' collegato all'azienda corrente.
      filtro += ` OR u.is_global OR EXISTS (SELECT 1 FROM user_companies uc1 WHERE uc1.user_id = u.id AND uc1.company_id = $1)`;
    }
    if (ruolo === 'consulente_lavoro') {
      // Staff condiviso tra aziende: collegato a una di quelle accessibili a chi guarda.
      const accessibili = u.tipo === 'employee' ? [cid] : await this.tenant.accessibili({ id: u.id, role: u.ruolo, companyId: u.companyIdAccount });
      p.push(accessibili.length ? accessibili : [cid]);
      filtro += ' OR u.id IN (SELECT user_id FROM user_companies WHERE company_id = ANY($3::int[]))';
    }
    const righe: Contatto[] = await this.ds.query(
      `SELECT DISTINCT u.id, u.name, u.photo_path, u.company_id, u.department_id
         FROM users u WHERE (${filtro}) AND u.role = $2 AND u.is_active = true ORDER BY u.name ASC, u.id ASC`, p);
    if (ruolo === 'admin') return righe;
    // Filtro finale di Chat::getAvailableContacts: azienda corrente, nessuna o collegata.
    const collegati = new Set<number>((await this.ds.query('SELECT user_id FROM user_companies WHERE company_id = $1', [cid])).map((r: any) => Number(r.user_id)));
    return righe.filter((x) => !x.company_id || x.company_id === cid || collegati.has(x.id));
  }
}
