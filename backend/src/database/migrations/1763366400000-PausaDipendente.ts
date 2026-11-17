import { MigrationInterface, QueryRunner } from 'typeorm';

/**
 * Pausa pranzo personalizzata per dipendente. Con lunch_break_custom = false
 * vale la fascia dell'azienda; con true vale quella del dipendente (vuota =
 * nessuna pausa). La fascia si toglie dalle ore dei permessi a ore.
 */
export class PausaDipendente1763366400000 implements MigrationInterface {
  name = 'PausaDipendente1763366400000';

  public async up(q: QueryRunner): Promise<void> {
    await q.query(`ALTER TABLE "employees" ADD COLUMN "lunch_break_custom" boolean NOT NULL DEFAULT false`);
    await q.query(`ALTER TABLE "employees" ADD COLUMN "lunch_break_start" time`);
    await q.query(`ALTER TABLE "employees" ADD COLUMN "lunch_break_end" time`);
    await q.query(`ALTER TABLE "employees" ADD CONSTRAINT "employees__lunch_break__ordine"
      CHECK ("lunch_break_start" IS NULL OR "lunch_break_end" IS NULL OR "lunch_break_end" > "lunch_break_start")`);
  }

  public async down(q: QueryRunner): Promise<void> {
    await q.query(`ALTER TABLE "employees" DROP CONSTRAINT "employees__lunch_break__ordine"`);
    await q.query(`ALTER TABLE "employees" DROP COLUMN "lunch_break_end"`);
    await q.query(`ALTER TABLE "employees" DROP COLUMN "lunch_break_start"`);
    await q.query(`ALTER TABLE "employees" DROP COLUMN "lunch_break_custom"`);
  }
}
