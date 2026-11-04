#!/usr/bin/env node
/**
 * Dopo la copia (converti-dati.js) il PHP e' rimasto acceso: riporta in Cora
 * le righe nate o cambiate sul MariaDB da quel momento (RECUPERA_DA, UTC).
 *
 *  - leave_requests: nuove o aggiornate, stesso id (inserite o aggiornate);
 *  - employee_leave_balances: aggiornate, per dipendente/anno/tipo;
 *  - notifications: nuove, con id nuovo; quelle gia' presenti non si duplicano.
 *
 * Si puo' rilanciare: il risultato non cambia. Il MariaDB si legge e basta.
 * Variabili come converti-dati.js (SRC_*, DST_*, CONFERMA_PRODUZIONE).
 */
const mysql = require('mysql2/promise');
const { Client, types } = require('pg');

types.setTypeParser(1082, (v) => v);

const env = (k, d) => process.env[k] ?? d;
const SRC = { host: env('SRC_HOST', '127.0.0.1'), port: Number(env('SRC_PORT', 33070)), user: env('SRC_USER', 'baseline'), password: env('SRC_PASS', 'baseline_sviluppo'), database: env('SRC_DB', 'baseline') };
const DST = { host: env('DST_HOST', 'localhost'), port: Number(env('DST_PORT', 5444)), user: env('DST_USER', 'cora'), password: env('DST_PASS', 'cora_sviluppo'), database: env('DST_DB', 'cora') };
const DA = env('RECUPERA_DA', '');
const LOCALI = ['127.0.0.1', 'localhost'];

if (env('CONFERMA_PRODUZIONE') !== 'si' && (!LOCALI.includes(SRC.host) || !LOCALI.includes(DST.host))) {
  console.error('Rifiutato: database non locali. Per un recupero autorizzato imposta CONFERMA_PRODUZIONE=si.');
  process.exit(1);
}
if (!/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/.test(DA)) {
  console.error('RECUPERA_DA mancante o non valida (formato "AAAA-MM-GG HH:MM:SS", UTC).');
  process.exit(1);
}

const TABELLE = [
  { nome: 'leave_requests', dove: 'created_at > ? OR updated_at > ?', chiave: ['id'] },
  { nome: 'employee_leave_balances', dove: 'updated_at > ?', chiave: ['employee_id', 'year', 'leave_type'], senzaId: true },
  { nome: 'notifications', dove: 'created_at > ?', chiave: null, senzaId: true },
];

/** Valore MariaDB -> Postgres, con le stesse regole di converti-dati.js. */
function converti(v, srcTipo, dstTipo) {
  if (v === null || v === undefined) return null;
  if (dstTipo === 'boolean') return Number(v) === 1;
  if (dstTipo === 'timestamp with time zone') return srcTipo === 'timestamp' ? `${v}+00` : v;
  return v;
}

async function main() {
  const src = await mysql.createConnection({ ...SRC, dateStrings: true, decimalNumbers: false, supportBigNumbers: true, bigNumberStrings: true });
  await src.query("SET time_zone = '+00:00'");
  const dst = new Client(DST);
  await dst.connect();
  // I datetime del PHP sono ora di Roma.
  await dst.query("SET TIME ZONE 'Europe/Rome'");
  await dst.query('BEGIN');
  try {
    for (const t of TABELLE) {
      const colDst = (await dst.query(`SELECT column_name, data_type FROM information_schema.columns WHERE table_schema = 'public' AND table_name = $1 ORDER BY ordinal_position`, [t.nome])).rows;
      const [colSrc] = await src.query('SELECT column_name AS c, data_type AS d FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = ?', [t.nome]);
      const tipoSrc = Object.fromEntries(colSrc.map((r) => [r.c, r.d]));
      const colonne = colDst.filter((c) => c.column_name in tipoSrc && !(t.senzaId && c.column_name === 'id'));
      const nomi = colonne.map((c) => c.column_name);
      const [righe] = await src.query(`SELECT ${nomi.map((c) => `\`${c}\``).join(', ')} FROM \`${t.nome}\` WHERE ${t.dove} ORDER BY id`, t.dove.split('?').slice(1).map(() => DA));
      let scritte = 0;
      let gia = 0;
      for (const r of righe) {
        const valori = colonne.map((c) => converti(r[c.column_name], tipoSrc[c.column_name], c.data_type));
        const segnaposti = nomi.map((_, i) => `$${i + 1}`).join(', ');
        const elenco = nomi.map((c) => `"${c}"`).join(', ');
        if (t.chiave) {
          const aggiorna = nomi.filter((c) => !t.chiave.includes(c)).map((c) => `"${c}" = EXCLUDED."${c}"`).join(', ');
          await dst.query(`INSERT INTO "${t.nome}" (${elenco}) ${t.senzaId ? '' : 'OVERRIDING SYSTEM VALUE '}VALUES (${segnaposti})
            ON CONFLICT (${t.chiave.map((c) => `"${c}"`).join(', ')}) DO UPDATE SET ${aggiorna}`, valori);
          scritte++;
        } else {
          // Senza chiave naturale: una notifica uguale (destinatario, tipo, titolo, istante) c'e' gia'?
          const confronto = ['company_id', 'recipient_type', 'recipient_id', 'type', 'title', 'created_at'];
          const idx = confronto.map((c) => nomi.indexOf(c));
          const { rows } = await dst.query(`SELECT 1 FROM "${t.nome}" WHERE ${confronto.map((c, i) => `"${c}" IS NOT DISTINCT FROM $${i + 1}`).join(' AND ')} LIMIT 1`, idx.map((i) => valori[i]));
          if (rows.length) { gia++; continue; }
          await dst.query(`INSERT INTO "${t.nome}" (${elenco}) VALUES (${segnaposti})`, valori);
          scritte++;
        }
      }
      if (!t.senzaId) {
        const seq = (await dst.query(`SELECT pg_get_serial_sequence($1, 'id') AS s`, [`"${t.nome}"`])).rows[0]?.s;
        if (seq) await dst.query(`SELECT setval($1, GREATEST((SELECT COALESCE(MAX(id), 0) FROM "${t.nome}"), 1))`, [seq]);
      }
      console.log(`${t.nome}: ${righe.length} cambiate sul PHP, ${scritte} scritte${gia ? `, ${gia} gia' presenti` : ''}`);
    }
    await dst.query('COMMIT');
  } catch (e) {
    await dst.query('ROLLBACK');
    throw e;
  } finally {
    await src.end();
    await dst.end();
  }
  console.log('Recupero completato.');
}

main().catch((e) => { console.error(`\nERRORE: ${e.message}`); process.exit(1); });
