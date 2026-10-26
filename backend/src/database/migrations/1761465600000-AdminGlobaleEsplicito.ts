import { MigrationInterface, QueryRunner } from 'typeorm';

/**
 * Admin globale esplicito (users.is_global).
 *
 * Il PHP considerava "globale" ogni admin con company_id NULL e nessuna riga
 * in user_companies: un admin rimasto senza aziende (tenant eliminato dal
 * superadmin, elenco aziende svuotato) diventava di colpo admin di tutte.
 * Qui serve il flag; le righe gia' presenti lo ricevono con la regola di
 * prima, i dati copiati dal PHP lo ricevono da scripts/converti-dati.js.
 */
export class AdminGlobaleEsplicito1761465600000 implements MigrationInterface {
  name = 'AdminGlobaleEsplicito1761465600000';

  public async up(q: QueryRunner): Promise<void> {
    await q.query(`ALTER TABLE "users" ADD COLUMN "is_global" boolean NOT NULL DEFAULT false`);
    await q.query(`UPDATE "users" u SET "is_global" = true WHERE u."role" = 'admin' AND u."company_id" IS NULL
      AND NOT EXISTS (SELECT 1 FROM "user_companies" uc WHERE uc."user_id" = u."id")`);
    await q.query(`ALTER TABLE "users" ADD CONSTRAINT "users__is_global__solo_admin" CHECK (NOT "is_global" OR ("role" = 'admin' AND "company_id" IS NULL))`);
  }

  public async down(q: QueryRunner): Promise<void> {
    await q.query(`ALTER TABLE "users" DROP CONSTRAINT "users__is_global__solo_admin"`);
    await q.query(`ALTER TABLE "users" DROP COLUMN "is_global"`);
  }
}
