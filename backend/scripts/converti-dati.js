#!/usr/bin/env node
/**
 * Copia i dati dal MariaDB di PAManager al Postgres di Cora e li verifica.
 *
 * Non scrive mai sul database di partenza: lo legge e basta. Sul Postgres di
 * arrivo pretende tabelle vuote (schema creato dalle migrazioni). Alla fine
 * confronta tabella per tabella numero di righe e impronta SHA-256 dei valori
 * normalizzati: se qualcosa non torna esce con errore e lo dice.
 *
 * Regole (le stesse di scripts/converti-schema.js):
 *  - tinyint(1) -> boolean: ammessi solo 0, 1 e NULL;
 *  - timestamp MariaDB (salvato in UTC) -> timestamptz con lo stesso istante;
 *  - datetime MariaDB (ora di Roma scritta dal PHP) -> timestamptz letto come Europe/Rome;
 *  - date e datetime "zero" (0000-00-00) bloccano la copia: vanno decise a mano;
 *  - JSON non valido nelle colonne json blocca la copia;
 *  - gli id restano uguali e le sequenze ripartono dal massimo.
 *
 * Uso:
 *   SRC_HOST=127.0.0.1 SRC_PORT=33070 SRC_USER=... SRC_PASS=... SRC_DB=baseline \
 *   DST_HOST=localhost DST_PORT=5444 DST_USER=cora DST_PASS=... DST_DB=cora \
 *   node scripts/converti-dati.js [--solo-verifica]
 *
 * Sicurezza: senza CONFERMA_PRODUZIONE=si accetta solo database locali.
 */
const crypto = require('crypto');
const mysql = require('mysql2/promise');
const { Client, types } = require('pg');

// Le date senza ora restano stringhe "AAAA-MM-GG": come Date di JavaScript
// cambierebbero giorno secondo il fuso di chi lancia lo script.
types.setTypeParser(1082, (v) => v);
// JSON come testo, cosi' l'impronta confronta esattamente cio' che e' salvato.
types.setTypeParser(114, (v) => v);

const env = (k, d) => process.env[k] ?? d;
const SRC = { host: env('SRC_HOST', '127.0.0.1'), port: Number(env('SRC_PORT', 33070)), user: env('SRC_USER', 'baseline'), password: env('SRC_PASS', 'baseline_sviluppo'), database: env('SRC_DB', 'baseline') };
const DST = { host: env('DST_HOST', 'localhost'), port: Number(env('DST_PORT', 5444)), user: env('DST_USER', 'cora'), password: env('DST_PASS', 'cora_sviluppo'), database: env('DST_DB', 'cora') };
const SOLO_VERIFICA = process.argv.includes('--solo-verifica');
const LOCALI = ['127.0.0.1', 'localhost'];

if (env('CONFERMA_PRODUZIONE') !== 'si' && (!LOCALI.includes(SRC.host) || !LOCALI.includes(DST.host))) {
  console.error('Rifiutato: database non locali. Per una copia autorizzata imposta CONFERMA_PRODUZIONE=si.');
  process.exit(1);
}

const BLOCCO = 1000;

/**
 * Colonne che esistono solo nel nuovo schema: non si copiano e non entrano
 * nell'impronta; a copia finita si riempiono dai dati copiati.
 */
/**
 * Ruoli che il nuovo stack non ha piu' (migration RuoliRidotti): gli account si
 * copiano tali e quali per lo storico e, a copia verificata, si disattivano.
 * Il vincolo sul ruolo si toglie durante la copia e si rimette dopo.
 */
const RUOLI_TOLTI = ['admin_reparto', 'accountant'];
const VINCOLO_RUOLI = `CHECK ("role" IN ('admin', 'consulente_lavoro', 'formatore') OR ("role" IN ('admin_reparto', 'accountant') AND "is_active" = false))`;

const SOLO_NODE = {
  users: {
    // Admin globale esplicito: nel PHP era "company_id NULL e nessuna azienda collegata".
    is_global: `UPDATE users u SET is_global = true WHERE u.role = 'admin' AND u.company_id IS NULL
      AND NOT EXISTS (SELECT 1 FROM user_companies uc WHERE uc.user_id = u.id)`,
  },
};

function pad(n) { return String(n).padStart(2, '0'); }

/** Valore MariaDB -> valore da passare a Postgres, secondo il tipo delle due colonne. */
function converti(v, srcTipo, dstTipo, dove) {
  if (v === null || v === undefined) return null;
  if (dstTipo === 'boolean') {
    const n = Number(v);
    if (n !== 0 && n !== 1) throw new Error(`${dove}: valore ${v} non e' 0/1, non diventa booleano`);
    return n === 1;
  }
  if (dstTipo === 'timestamp with time zone') {
    if (/^0000-00-00/.test(v)) throw new Error(`${dove}: data zero "${v}"`);
    // timestamp: letto con time_zone +00:00, quindi e' UTC. datetime: ora di Roma.
    return srcTipo === 'timestamp' ? `${v}+00` : v;
  }
  if (dstTipo === 'date') {
    if (/^0000-00-00/.test(v)) throw new Error(`${dove}: data zero "${v}"`);
    return v;
  }
  if (dstTipo === 'json') {
    try { JSON.parse(v); } catch { throw new Error(`${dove}: JSON non valido`); }
    return v;
  }
  if (Buffer.isBuffer(v)) return v;
  return v;
}

/** Forma comune per l'impronta: uguale da tutte e due le parti se il dato e' lo stesso. */
function normalizza(v, tipo) {
  if (v === null || v === undefined) return '∅';
  if (tipo === 'boolean') return v === true || v === 1 || v === '1' || v === 't' ? '1' : '0';
  if (tipo === 'timestamp with time zone') return new Date(v).toISOString();
  if (tipo === 'numeric' || tipo === 'integer' || tipo === 'bigint' || tipo === 'smallint' || tipo === 'double precision') {
    return String(Number(v));
  }
  if (tipo === 'json') return typeof v === 'string' ? v : JSON.stringify(v);
  if (tipo === 'time without time zone') return String(v).slice(0, 8);
  if (Buffer.isBuffer(v)) return v.toString('hex');
  return String(v);
}

async function main() {
  const src = await mysql.createConnection({ ...SRC, dateStrings: true, decimalNumbers: false, supportBigNumbers: true, bigNumberStrings: true });
  await src.query("SET time_zone = '+00:00'");
  const dst = new Client(DST);
  await dst.connect();
  await dst.query("SET TIME ZONE 'Europe/Rome'");
  // Le date di Roma nelle colonne datetime si interpretano con questo fuso; per
  // leggere le impronte gli istanti tornano come Date di JavaScript.
  const tabelle = (await dst.query(`SELECT table_name FROM information_schema.tables
    WHERE table_schema = 'public' AND table_type = 'BASE TABLE' AND table_name <> 'typeorm_migrations' ORDER BY 1`)).rows.map((r) => r.table_name);

  const [srcTab] = await src.query('SELECT table_name AS t FROM information_schema.tables WHERE table_schema = DATABASE() AND table_type = ?', ['BASE TABLE']);
  const nomiSrc = new Set(srcTab.map((r) => r.t));
  const ignorate = [...nomiSrc].filter((t) => !tabelle.includes(t));
  if (ignorate.length) console.log(`Tabelle della sorgente non copiate (non fanno parte di Cora): ${ignorate.join(', ')}`);

  if (!SOLO_VERIFICA) {
    for (const t of tabelle) {
      const { rows } = await dst.query(`SELECT EXISTS (SELECT 1 FROM "${t}") AS c`);
      if (rows[0].c) throw new Error(`La tabella di arrivo ${t} non e' vuota: la copia si fa solo su uno schema appena creato`);
    }
  }

  if (!SOLO_VERIFICA) await dst.query('ALTER TABLE "users" DROP CONSTRAINT "users__role__valori"');

  const esito = [];
  let errori = 0;
  for (const t of tabelle) {
    const colDst = (await dst.query(`SELECT column_name, data_type FROM information_schema.columns WHERE table_schema = 'public' AND table_name = $1 ORDER BY ordinal_position`, [t])).rows
      .filter((c) => !(c.column_name in (SOLO_NODE[t] ?? {})));
    if (!nomiSrc.has(t)) {
      esito.push([t, 0, 0, 'assente nella sorgente (resta vuota)']);
      continue;
    }
    const [colSrcRighe] = await src.query('SELECT column_name AS c, data_type AS d FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = ?', [t]);
    const tipoSrc = Object.fromEntries(colSrcRighe.map((r) => [r.c, r.d]));
    const mancanti = colDst.filter((c) => !(c.column_name in tipoSrc)).map((c) => c.column_name);
    const extra = Object.keys(tipoSrc).filter((c) => !colDst.find((d) => d.column_name === c));
    if (mancanti.length || extra.length) {
      throw new Error(`Colonne diverse in ${t}: mancano nella sorgente [${mancanti}] / in piu' nella sorgente [${extra}]`);
    }
    const colonne = colDst.map((c) => c.column_name);
    const pk = (await dst.query(`SELECT a.attname FROM pg_index i JOIN pg_attribute a ON a.attrelid = i.indrelid AND a.attnum = ANY(i.indkey)
      WHERE i.indrelid = $1::regclass AND i.indisprimary ORDER BY a.attnum`, [`"${t}"`])).rows.map((r) => r.attname);
    const ordine = (pk.length ? pk : colonne).map((c) => `\`${c}\``).join(', ');

    if (!SOLO_VERIFICA) {
      await dst.query('BEGIN');
      // Inserimento con gli id originali, senza scattare trigger e chiavi esterne
      // (le tabelle si riempiono in ordine alfabetico, non di dipendenza).
      await dst.query("SET LOCAL session_replication_role = 'replica'");
      for (let off = 0; ; off += BLOCCO) {
        const [righe] = await src.query(`SELECT ${colonne.map((c) => `\`${c}\``).join(', ')} FROM \`${t}\` ORDER BY ${ordine} LIMIT ${BLOCCO} OFFSET ${off}`);
        if (!righe.length) break;
        for (const r of righe) {
          const valori = colDst.map((c) => converti(r[c.column_name], tipoSrc[c.column_name], c.data_type, `${t}.${c.column_name}`));
          await dst.query(`INSERT INTO "${t}" (${colonne.map((c) => `"${c}"`).join(', ')}) OVERRIDING SYSTEM VALUE VALUES (${colonne.map((_, i) => `$${i + 1}`).join(', ')})`, valori);
        }
      }
      await dst.query('COMMIT');
      // La prossima riga nuova riparte dopo l'id piu' alto copiato.
      const seq = (await dst.query(`SELECT pg_get_serial_sequence($1, 'id') AS s`, [`"${t}"`]).catch(() => ({ rows: [{}] }))).rows[0]?.s;
      if (seq) await dst.query(`SELECT setval($1, GREATEST((SELECT COALESCE(MAX(id), 0) FROM "${t}"), 1), (SELECT COUNT(*) > 0 FROM "${t}"))`, [seq]);
    }

    // Impronta: stesse righe, stesso ordine, valori normalizzati.
    const impronta = async (righe, daPostgres) => {
      const h = crypto.createHash('sha256');
      for (const r of righe) {
        h.update(colDst.map((c) => {
          let v = r[c.column_name];
          if (!daPostgres && v !== null && c.data_type === 'timestamp with time zone') {
            v = tipoSrc[c.column_name] === 'timestamp' ? `${v.replace(' ', 'T')}Z` : romaToIso(v);
          }
          if (!daPostgres && c.data_type === 'boolean' && v !== null) v = Number(v);
          return normalizza(v, c.data_type);
        }).join('␟') + '\n');
      }
      return h.digest('hex');
    };
    const [tutteSrc] = await src.query(`SELECT ${colonne.map((c) => `\`${c}\``).join(', ')} FROM \`${t}\` ORDER BY ${ordine}`);
    const tutteDst = (await dst.query(`SELECT ${colonne.map((c) => `"${c}"`).join(', ')} FROM "${t}" ORDER BY ${(pk.length ? pk : colonne).map((c) => `"${c}"`).join(', ')}`)).rows;
    const [a, b] = [await impronta(tutteSrc, false), await impronta(tutteDst, true)];
    const ok = tutteSrc.length === tutteDst.length && a === b;
    if (!ok) {
      errori++;
      // Prima differenza, per capire subito dove guardare.
      for (let i = 0; i < Math.min(tutteSrc.length, tutteDst.length); i++) {
        const c = colDst.find((c) => {
          let v = tutteSrc[i][c.column_name];
          if (v !== null && c.data_type === 'timestamp with time zone') v = tipoSrc[c.column_name] === 'timestamp' ? `${v.replace(' ', 'T')}Z` : romaToIso(v);
          if (v !== null && c.data_type === 'boolean') v = Number(v);
          return normalizza(v, c.data_type) !== normalizza(tutteDst[i][c.column_name], c.data_type);
        });
        if (c) {
          console.log(`  ${t}.${c.column_name} riga ${i + 1}: MariaDB=${JSON.stringify(tutteSrc[i][c.column_name])} Postgres=${JSON.stringify(tutteDst[i][c.column_name])}`);
          break;
        }
      }
    }
    esito.push([t, tutteSrc.length, tutteDst.length, ok ? 'identica' : 'DIVERSA']);
  }

  if (!SOLO_VERIFICA) {
    for (const [t, colonne] of Object.entries(SOLO_NODE)) {
      for (const [c, sql] of Object.entries(colonne)) {
        const r = await dst.query(sql);
        console.log(`${t}.${c}: ${r.rowCount} righe impostate`);
      }
    }
    const tolti = await dst.query(`UPDATE "users" SET "is_active" = false, "department_id" = NULL WHERE "role" = ANY($1::text[])`, [RUOLI_TOLTI]);
    console.log(`users: ${tolti.rowCount} account di commercialisti e responsabili di reparto disattivati`);
    await dst.query(`ALTER TABLE "users" ADD CONSTRAINT "users__role__valori" ${VINCOLO_RUOLI}`);
  }

  console.log('\nTabella'.padEnd(34) + 'MariaDB'.padStart(9) + 'Postgres'.padStart(10) + '  Esito');
  for (const [t, s, d, e] of esito) console.log(t.padEnd(33) + String(s).padStart(9) + String(d).padStart(10) + '  ' + e);
  await src.end();
  await dst.end();
  if (errori) {
    console.error(`\n${errori} tabelle diverse: copia NON valida.`);
    process.exit(2);
  }
  console.log('\nTutte le tabelle coincidono.');
}

/** "2026-10-22 11:00:00" ora di Roma -> istante ISO, con l'ora legale giusta per quel giorno. */
function romaToIso(s) {
  const [d, o] = s.split(' ');
  const base = new Date(`${d}T${o || '00:00:00'}Z`);
  for (const ore of [1, 2]) {
    const prova = new Date(base.getTime() - ore * 3600_000);
    const roma = new Intl.DateTimeFormat('sv-SE', { timeZone: 'Europe/Rome', year: 'numeric', month: '2-digit', day: '2-digit', hour: '2-digit', minute: '2-digit', second: '2-digit', hourCycle: 'h23' }).format(prova);
    if (roma === `${d} ${o || '00:00:00'}`) return prova.toISOString();
  }
  // Ora che non esiste (salto dell'ora legale): Postgres la sposta avanti di un'ora.
  return new Date(base.getTime() - 3600_000).toISOString();
}

main().catch((e) => { console.error(`\nERRORE: ${e.message}`); process.exit(1); });
