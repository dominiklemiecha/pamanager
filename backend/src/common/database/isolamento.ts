import { AsyncLocalStorage } from 'async_hooks';
import { Logger } from '@nestjs/common';
import type { NextFunction, Request, Response } from 'express';
import { DataSource } from 'typeorm';
// eslint-disable-next-line @typescript-eslint/no-var-requires
const { PostgresQueryRunner } = require('typeorm/driver/postgres/PostgresQueryRunner');

/**
 * Isolamento tra aziende anche nel database (Row Level Security di Postgres),
 * sullo stesso schema di crm-connecteed (common/database/isolamento.ts).
 *
 * Il codice filtra gia' ogni query per azienda: questa e' la rete di sicurezza
 * se una query un giorno dimenticasse il filtro.
 *
 *  - all'avvio si crea il ruolo `cora_app` (senza privilegi speciali) e su
 *    ogni tabella con `company_id` una regola: quel ruolo vede e scrive solo
 *    le righe con `company_id = app.company_id`;
 *  - durante la richiesta di un utente collegato ogni query gira come
 *    `cora_app` con l'azienda dell'utente;
 *  - fuori dalle richieste (avvio, job, link pubblici, superadmin) e nei
 *    controlli che devono vedere tutto si torna al ruolo del backend.
 *
 * DB_RLS=off lo spegne (senza toccare il resto).
 */
interface Contesto { companyId?: number; libero?: boolean }
const contesto = new AsyncLocalStorage<Contesto>();
const RUOLO = 'cora_app';
let attivo = false;
const logger = new Logger('Isolamento');

/**
 * Tabelle dove company_id NULL vuol dire "di tutti": modelli CCNL di sistema.
 * Si leggono da ogni azienda, ma si scrivono solo con la propria.
 */
const CONDIVISE_SE_NULL = new Set(['ccnl_templates']);

/**
 * Tabelle con company_id ma senza isolamento automatico:
 *  - companies: e' la tabella delle aziende stesse;
 *  - users: lo staff (commercialisti, consulenti) lavora per piu' aziende
 *    tramite user_companies, l'accesso si controlla nel codice.
 */
const ESCLUSE = new Set(['companies', 'users', 'user_companies']);

/** Middleware Express: ogni richiesta ha il suo contesto (l'azienda arriva col login). */
export function contestoRichiesta(_req: Request, _res: Response, next: NextFunction): void {
  contesto.run({}, () => next());
}

/** Chiamato quando si sa chi e' l'utente: da qui le query vedono solo la sua azienda. */
export function impostaAziendaRichiesta(companyId: number | null | undefined): void {
  const c = contesto.getStore();
  if (c && companyId) c.companyId = companyId;
}

/** Azienda della richiesta in corso, se c'e'. */
export function aziendaCorrente(): number | undefined {
  return contesto.getStore()?.companyId;
}

/** Esegue `fn` con l'isolamento di un'azienda fuori da una richiesta HTTP (job, socket). */
export function conAzienda<T>(companyId: number, fn: () => Promise<T>): Promise<T> {
  return contesto.run({ companyId }, fn);
}

/** Esegue `fn` senza isolamento (controlli che devono vedere tutte le aziende). */
export function senzaIsolamento<T>(fn: () => Promise<T>): Promise<T> {
  const c = contesto.getStore();
  if (!c) return fn();
  return contesto.run({ ...c, libero: true }, fn);
}

/** Crea ruolo e regole (idempotente) e attiva il cambio di ruolo sulle connessioni. */
export async function attivaIsolamento(ds: DataSource): Promise<void> {
  if (String(process.env.DB_RLS ?? 'on').toLowerCase() === 'off') {
    logger.warn('Isolamento nel database spento (DB_RLS=off)');
    return;
  }
  try {
    await ds.query(`DO $$ BEGIN
      IF NOT EXISTS (SELECT 1 FROM pg_roles WHERE rolname = '${RUOLO}') THEN CREATE ROLE ${RUOLO} NOLOGIN; END IF;
    END $$`);
    await ds.query(`GRANT ${RUOLO} TO CURRENT_USER`).catch(() => undefined);
    await ds.query(`GRANT USAGE ON SCHEMA public TO ${RUOLO}`);
    await ds.query(`GRANT SELECT, INSERT, UPDATE, DELETE ON ALL TABLES IN SCHEMA public TO ${RUOLO}`);
    await ds.query(`GRANT USAGE, SELECT, UPDATE ON ALL SEQUENCES IN SCHEMA public TO ${RUOLO}`);
    const tabelle: Array<{ table_name: string; is_nullable: string }> = await ds.query(
      `SELECT c.table_name, c.is_nullable FROM information_schema.columns c
         JOIN information_schema.tables t ON t.table_name = c.table_name AND t.table_schema = c.table_schema
        WHERE c.table_schema = 'public' AND c.column_name = 'company_id' AND t.table_type = 'BASE TABLE'`,
    );
    let n = 0;
    for (const { table_name } of tabelle) {
      if (ESCLUSE.has(table_name)) continue;
      const t = `"${table_name.replace(/"/g, '')}"`;
      const condivisa = CONDIVISE_SE_NULL.has(table_name);
      if (!condivisa) {
        // Righe senza azienda diventerebbero invisibili a tutti: su quella
        // tabella l'isolamento non si accende (i dati vengono prima).
        const [senza] = await ds.query(`SELECT EXISTS (SELECT 1 FROM ${t} WHERE company_id IS NULL) AS c`);
        if (senza?.c) {
          await ds.query(`ALTER TABLE ${t} DISABLE ROW LEVEL SECURITY`);
          logger.warn(`Isolamento NON attivo su ${table_name}: ci sono righe senza azienda`);
          continue;
        }
      }
      const proprie = `company_id = NULLIF(current_setting('app.company_id', true), '')::int`;
      await ds.query(`ALTER TABLE ${t} ENABLE ROW LEVEL SECURITY`);
      await ds.query(`DROP POLICY IF EXISTS cora_azienda ON ${t}`);
      await ds.query(
        `CREATE POLICY cora_azienda ON ${t} TO ${RUOLO}
           USING (${condivisa ? `company_id IS NULL OR ${proprie}` : proprie})
           WITH CHECK (${proprie})`,
      );
      n++;
    }
    installaCambioRuolo();
    attivo = true;
    logger.log(`Isolamento nel database attivo su ${n} tabelle`);
  } catch (e: any) {
    // Senza permessi per creare ruolo e regole si resta coi soli filtri nel codice.
    logger.error(`Isolamento nel database NON attivo: ${e?.message ?? e}`);
  }
}

let installato = false;
function installaCambioRuolo(): void {
  if (installato) return;
  installato = true;
  const query = PostgresQueryRunner.prototype.query;
  PostgresQueryRunner.prototype.query = async function (this: any, sql: string, parametri?: any[], strutturato?: boolean) {
    if (attivo && !/^\s*ROLLBACK/i.test(sql)) {
      const c = contesto.getStore();
      const voluto = c && !c.libero && c.companyId ? String(c.companyId) : '';
      const conn = await this.connect();
      if (conn.__coraAzienda !== voluto) {
        if (voluto) {
          await conn.query(`SET ROLE ${RUOLO}`);
          await conn.query(`SELECT set_config('app.company_id', $1, false)`, [voluto]);
        } else {
          await conn.query('RESET ROLE');
          await conn.query(`SELECT set_config('app.company_id', '', false)`);
        }
        conn.__coraAzienda = voluto;
      }
    }
    return query.call(this, sql, parametri, strutturato);
  };
  // Un ROLLBACK annulla anche SET ROLE fatti dentro la transazione: alla
  // prossima query il ruolo si reimposta da capo.
  const rollback = PostgresQueryRunner.prototype.rollbackTransaction;
  PostgresQueryRunner.prototype.rollbackTransaction = async function (this: any) {
    try {
      return await rollback.call(this);
    } finally {
      if (this.databaseConnection) this.databaseConnection.__coraAzienda = undefined;
    }
  };
}
