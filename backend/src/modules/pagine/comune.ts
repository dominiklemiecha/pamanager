import type { DataSource } from 'typeorm';
import { senzaIsolamento } from '../../common/database/isolamento';
import type { ContestoPagina } from './pagina';

/**
 * Admin globale "vero" (Tenant::isCurrentUserTrueGlobalAdmin): ruolo admin,
 * nessuna azienda propria e nessuna azienda collegata.
 */
export async function veroGlobale(ds: DataSource, c: ContestoPagina): Promise<boolean> {
  if (c.u.tipo === 'employee' || c.u.ruolo !== 'admin') return false;
  const [r] = await senzaIsolamento(() => ds.query(
    `SELECT u.company_id, (SELECT COUNT(*) FROM user_companies x WHERE x.user_id = u.id)::int AS collegate FROM users u WHERE u.id = $1`, [c.u.id]));
  return !!r && r.company_id === null && r.collegate === 0;
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
