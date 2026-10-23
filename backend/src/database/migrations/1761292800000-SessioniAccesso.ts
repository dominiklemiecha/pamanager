import { MigrationInterface, QueryRunner } from 'typeorm';

/**
 * Sessioni di accesso lato server (sostituiscono le sessioni PHP su file).
 *
 * Il browser tiene solo un cookie httpOnly con un codice casuale; qui se ne
 * salva l'impronta SHA-256, mai il codice. Cosi' una sessione si puo' chiudere
 * dal server (logout, cambio password, account disattivato) e i timeout per
 * ruolo si controllano a ogni richiesta, come faceva il PHP.
 */
export class SessioniAccesso1761292800000 implements MigrationInterface {
  name = 'SessioniAccesso1761292800000';

  public async up(q: QueryRunner): Promise<void> {
    await q.query(`CREATE TABLE "auth_sessions" (
      "id" uuid PRIMARY KEY DEFAULT gen_random_uuid(),
      "token_hash" char(64) NOT NULL,
      "subject_type" varchar(8) NOT NULL CHECK ("subject_type" IN ('user', 'employee')),
      "subject_id" integer NOT NULL,
      "role" varchar(20) NOT NULL,
      "selected_company_id" integer NULL,
      "mfa_pending" boolean NOT NULL DEFAULT false,
      "user_agent" text NOT NULL DEFAULT '',
      "ip_address" varchar(45) NULL,
      "created_at" timestamptz NOT NULL DEFAULT CURRENT_TIMESTAMP,
      "last_activity_at" timestamptz NOT NULL DEFAULT CURRENT_TIMESTAMP,
      "expires_at" timestamptz NOT NULL,
      "revoked_at" timestamptz NULL,
      "revoked_reason" varchar(40) NULL
    )`);
    await q.query('CREATE UNIQUE INDEX "auth_sessions__token_hash" ON "auth_sessions" ("token_hash")');
    await q.query('CREATE INDEX "auth_sessions__soggetto" ON "auth_sessions" ("subject_type", "subject_id")');
  }

  public async down(q: QueryRunner): Promise<void> {
    await q.query('DROP TABLE IF EXISTS "auth_sessions"');
  }
}
