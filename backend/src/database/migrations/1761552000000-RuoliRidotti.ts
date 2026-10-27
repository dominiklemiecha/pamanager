import { MigrationInterface, QueryRunner } from 'typeorm';

/**
 * Ruoli ridotti: gli account dello staff sono HR (admin), consulenti del
 * lavoro e formatori. Responsabili di reparto e commercialisti non esistono
 * piu'; i reparti restano come raggruppamento dei dipendenti.
 *
 * Gli account con i ruoli tolti vengono disattivati, perdono il reparto e le
 * loro sessioni si chiudono. Restano nel database con il ruolo originale per
 * lo storico (chat, registri, documenti caricati); il vincolo impedisce di
 * riattivarli.
 */
export class RuoliRidotti1761552000000 implements MigrationInterface {
  name = 'RuoliRidotti1761552000000';

  public async up(q: QueryRunner): Promise<void> {
    await q.query(`UPDATE "auth_sessions" s SET "revoked_at" = CURRENT_TIMESTAMP, "revoked_reason" = 'ruolo_rimosso'
      WHERE s."subject_type" = 'user' AND s."revoked_at" IS NULL
        AND s."subject_id" IN (SELECT "id" FROM "users" WHERE "role" IN ('admin_reparto', 'accountant'))`);
    await q.query(`UPDATE "users" SET "is_active" = false, "department_id" = NULL WHERE "role" IN ('admin_reparto', 'accountant')`);
    await q.query(`ALTER TABLE "users" DROP CONSTRAINT "users__role__valori"`);
    await q.query(`ALTER TABLE "users" ADD CONSTRAINT "users__role__valori" CHECK ("role" IN ('admin', 'consulente_lavoro', 'formatore')
      OR ("role" IN ('admin_reparto', 'accountant') AND "is_active" = false))`);
    await q.query(`ALTER TABLE "users" ALTER COLUMN "role" SET DEFAULT 'consulente_lavoro'`);
  }

  public async down(q: QueryRunner): Promise<void> {
    await q.query(`ALTER TABLE "users" ALTER COLUMN "role" SET DEFAULT 'accountant'`);
    await q.query(`ALTER TABLE "users" DROP CONSTRAINT "users__role__valori"`);
    await q.query(`ALTER TABLE "users" ADD CONSTRAINT "users__role__valori" CHECK ("role" IN ('admin', 'accountant', 'admin_reparto', 'consulente_lavoro', 'formatore'))`);
  }
}
