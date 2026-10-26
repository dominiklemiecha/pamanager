import type { DataSource } from 'typeorm';
import { senzaIsolamento } from '../../common/database/isolamento';
import type { ContestoPagina } from './pagina';

/**
 * Admin globale "vero" (Tenant::isCurrentUserTrueGlobalAdmin): flag esplicito
 * users.is_global (vedi TenantService.adminGlobale).
 */
export async function veroGlobale(ds: DataSource, c: ContestoPagina): Promise<boolean> {
  if (c.u.tipo === 'employee' || c.u.ruolo !== 'admin') return false;
  const [r] = await senzaIsolamento(() => ds.query('SELECT is_global FROM users WHERE id = $1', [c.u.id]));
  return r?.is_global === true;
}

/** Variabili di includes/_config-tabs.php: pagina corrente e scheda "Apertura porta". */
export async function schedeConfigurazione(ds: DataSource, c: ContestoPagina, pagina: string) {
  return { _SERVER: { PHP_SELF: `/admin/${pagina}.php` }, __veroGlobale: await veroGlobale(ds, c) };
}

/** Cast (float) del PHP su una stringa: prefisso numerico o 0. */
export function floatPhp(v: unknown): number {
  const m = /^\s*[+-]?(\d+(\.\d*)?|\.\d+)([eE][+-]?\d+)?/.exec(String(v ?? ''));
  return m ? Number(m[0]) : 0;
}

/** Slug come nel PHP: minuscole, gruppi non alfanumerici -> "-", trattini ai bordi tolti. */
export function slugPhp(s: string): string {
  return s.toLowerCase().replace(/[^a-z0-9]+/g, '-').replace(/^-+|-+$/g, '');
}

/**
 * Email gia' usata da un altro account dello staff. Correzione: il PHP
 * accettava qualsiasi indirizzo nel profilo, anche quello dell'HR, che poi
 * valeva come identita' nel widget di assistenza e rendeva ambiguo l'accesso
 * con l'email.
 */
export async function emailStaffOccupata(ds: DataSource, email: string, userId: number): Promise<boolean> {
  if (email === '') return false;
  const righe = await senzaIsolamento(() => ds.query('SELECT 1 FROM users WHERE lower(email) = lower($1) AND id <> $2 LIMIT 1', [email, userId]));
  return righe.length > 0;
}
