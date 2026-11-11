import { MigrationInterface, QueryRunner } from 'typeorm';

/**
 * Dati anagrafici del consulente paghe (TeamSystem, tracciato ANDIP).
 *
 * Il dipendente in TeamSystem e' identificato da azienda + filiale +
 * matricola: servono per importare l'anagrafica e per l'export delle
 * presenze. Si aggiungono anche la data di cessazione del rapporto e la
 * percentuale di part-time come arrivano dal tracciato, e l'intero record
 * ANDIP dell'ultimo import (tutte le colonne, anche quelle che Cora non usa)
 * con la data dell'import.
 */
export class DatiTeamSystem1762819200000 implements MigrationInterface {
  name = 'DatiTeamSystem1762819200000';

  public async up(q: QueryRunner): Promise<void> {
    await q.query(`ALTER TABLE "companies" ADD COLUMN "teamsystem_code" citext`);
    await q.query(`ALTER TABLE "employees" ADD COLUMN "matricola" citext`);
    await q.query(`ALTER TABLE "employees" ADD COLUMN "filiale" integer`);
    await q.query(`ALTER TABLE "employees" ADD COLUMN "termination_date" date`);
    await q.query(`ALTER TABLE "employees" ADD COLUMN "part_time_percent" numeric(5,2)`);
    await q.query(`ALTER TABLE "employees" ADD CONSTRAINT "employees__part_time_percent__intervallo"
      CHECK ("part_time_percent" IS NULL OR ("part_time_percent" > 0 AND "part_time_percent" <= 100))`);
    await q.query(`ALTER TABLE "employees" ADD COLUMN "teamsystem_data" json`);
    await q.query(`ALTER TABLE "employees" ADD COLUMN "teamsystem_imported_at" timestamptz`);
  }

  public async down(q: QueryRunner): Promise<void> {
    await q.query(`ALTER TABLE "employees" DROP COLUMN "teamsystem_imported_at"`);
    await q.query(`ALTER TABLE "employees" DROP COLUMN "teamsystem_data"`);
    await q.query(`ALTER TABLE "employees" DROP CONSTRAINT "employees__part_time_percent__intervallo"`);
    await q.query(`ALTER TABLE "employees" DROP COLUMN "part_time_percent"`);
    await q.query(`ALTER TABLE "employees" DROP COLUMN "termination_date"`);
    await q.query(`ALTER TABLE "employees" DROP COLUMN "filiale"`);
    await q.query(`ALTER TABLE "employees" DROP COLUMN "matricola"`);
    await q.query(`ALTER TABLE "companies" DROP COLUMN "teamsystem_code"`);
  }
}
