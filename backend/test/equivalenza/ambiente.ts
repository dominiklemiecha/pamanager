import { execFileSync } from 'child_process';
import { existsSync, mkdirSync, readFileSync, writeFileSync } from 'fs';
import { join } from 'path';

/**
 * Ambiente dei test di equivalenza: il PHP della baseline (MariaDB) e il nuovo
 * backend (Postgres) con gli stessi dati sintetici.
 *
 * Prima di ogni gruppo di test i due database tornano allo stato iniziale da
 * un'istantanea, cosi' i test che scrivono (blocchi, richieste, ...) partono
 * sempre dagli stessi dati da tutte e due le parti.
 */
const DATI = join(__dirname, '.dati');
const MARIADB = { container: 'cora-baseline-db-1', utente: 'root', password: 'baseline_root', db: 'baseline' };
const POSTGRES = { container: 'cora-dev-postgres-1', utente: 'cora', db: 'cora' };

export const PHP_URL = process.env.PHP_URL || 'http://localhost:8899';
export const NODE_URL = process.env.NODE_URL || 'http://localhost:3100';

function docker(args: string[], input?: string): string {
  return execFileSync('docker', args, { input, maxBuffer: 256 * 1024 * 1024 }).toString();
}

/** Crea le istantanee la prima volta (dopo seed e conversione). */
export function preparaIstantanee(): void {
  mkdirSync(DATI, { recursive: true });
  const mdb = join(DATI, 'baseline.sql');
  const pg = join(DATI, 'cora.sql');
  if (!existsSync(mdb)) {
    writeFileSync(mdb, docker(['exec', MARIADB.container, 'mariadb-dump', `-u${MARIADB.utente}`, `-p${MARIADB.password}`, '--single-transaction', MARIADB.db]));
  }
  if (!existsSync(pg)) {
    writeFileSync(pg, docker(['exec', POSTGRES.container, 'pg_dump', '-U', POSTGRES.utente, '--clean', '--if-exists', POSTGRES.db]));
  }
}

/** Riporta entrambi i database allo stato dell'istantanea. */
export function ripristina(): void {
  preparaIstantanee();
  docker(['exec', '-i', MARIADB.container, 'mariadb', `-u${MARIADB.utente}`, `-p${MARIADB.password}`, MARIADB.db], readFileSync(join(DATI, 'baseline.sql'), 'utf8'));
  // Le sessioni aperte dai test precedenti non devono sopravvivere al ripristino.
  docker(['exec', '-i', POSTGRES.container, 'psql', '-q', '-v', 'ON_ERROR_STOP=1', '-U', POSTGRES.utente, POSTGRES.db], readFileSync(join(DATI, 'cora.sql'), 'utf8'));
}

/** Query di controllo sul MariaDB del PHP. */
export function mariadb(sql: string): Array<Record<string, string>> {
  const out = docker(['exec', MARIADB.container, 'mariadb', `-u${MARIADB.utente}`, `-p${MARIADB.password}`, '-B', '-e', sql, MARIADB.db]).trim();
  if (!out) return [];
  const [testa, ...righe] = out.split('\n');
  const col = testa.split('\t');
  // In modalita' -B il client scrive \\, \t, \n e \0 come sequenze: si tolgono.
  const testo = (v: string) => v.replace(/\\([\\tn0])/g, (_m, c) => ({ '\\': '\\', t: '\t', n: '\n', 0: '\0' })[c as '\\']);
  return righe.map((r) => Object.fromEntries(r.split('\t').map((v, i) => [col[i], v === 'NULL' ? null : testo(v)])) as Record<string, string>);
}

/** Query di controllo sul Postgres del nuovo backend. */
export function postgres(sql: string): Array<Record<string, string>> {
  const out = docker(['exec', POSTGRES.container, 'psql', '-U', POSTGRES.utente, '-d', POSTGRES.db, '-A', '-F', '\t', '-P', 'footer=off', '-P', 'null=NULL', '-c', sql]).trim();
  if (!out) return [];
  const [testa, ...righe] = out.split('\n');
  const col = testa.split('\t');
  return righe.map((r) => Object.fromEntries(r.split('\t').map((v, i) => [col[i], v === 'NULL' ? null : v])) as Record<string, string>);
}
