#!/usr/bin/env node
/**
 * Converte lo schema MariaDB di PAManager (mysqldump --no-data) nella
 * migrazione iniziale Postgres di TypeORM.
 *
 * Si usa una volta sola, sullo schema "pulito" (install_full.sql + tutte le
 * migrazioni SQL applicate a un MariaDB vuoto). Il risultato va riletto e
 * committato: da li' in poi lo schema cambia solo con nuove migrazioni.
 *
 * Regole di conversione (ognuna conserva il comportamento di MariaDB):
 *  - varchar/char -> citext + CHECK sulla lunghezza: utf8mb4_unicode_ci confronta
 *    senza distinguere maiuscole (login, univoci su username e email);
 *  - tinyint(1) -> boolean; altri tinyint/smallint -> smallint;
 *  - enum -> varchar + CHECK con gli stessi valori; set -> varchar con la stessa
 *    stringa "mon,tue" (la logica che la legge resta uguale);
 *  - timestamp e datetime -> timestamptz: i datetime erano ora di Roma, il
 *    convertitore dei dati li legge come tali (niente piu' scarto con l'ora legale);
 *  - JSON nativo (CHECK json_valid) -> jsonb; il resto dei testi -> text;
 *  - AUTO_INCREMENT -> identity "by default" (gli id esistenti restano uguali);
 *  - ON UPDATE current_timestamp -> trigger;
 *  - nomi degli indici prefissati con la tabella (in Postgres sono globali).
 *
 * Uso: node scripts/converti-schema.js <dump.sql> <uscita.ts>
 */
const fs = require('fs');

const [, , ingresso, uscita] = process.argv;
if (!ingresso || !uscita) {
  console.error('Uso: node scripts/converti-schema.js <dump.sql> <uscita.ts>');
  process.exit(1);
}

// Tabelle del dump che non fanno parte dell'applicazione o che Postgres gestisce in altro modo.
const ESCLUSE = new Set(['migrations']);

const sql = fs.readFileSync(ingresso, 'utf8');
const blocchi = [...sql.matchAll(/CREATE TABLE `([a-z0-9_]+)` \(\n([\s\S]*?)\n\) ENGINE=[^;]*;/g)];

const q = (n) => `"${n}"`;
const lista = (s) => s.split(',').map((c) => q(c.replace(/`|\(\d+\)/g, '').trim())).join(', ');

const tabelle = [];
const indici = [];
const chiavi = [];
const trigger = [];

for (const [, nome, corpo] of blocchi) {
  if (ESCLUSE.has(nome)) continue;
  const colonne = [];
  const vincoli = [];
  for (let riga of corpo.split('\n')) {
    riga = riga.trim().replace(/,$/, '');
    let m;
    if ((m = riga.match(/^PRIMARY KEY \((.+)\)$/))) {
      vincoli.push(`PRIMARY KEY (${lista(m[1])})`);
    } else if ((m = riga.match(/^UNIQUE KEY `([^`]+)` \((.+)\)$/))) {
      indici.push(`CREATE UNIQUE INDEX ${q(`${nome}__${m[1]}`)} ON ${q(nome)} (${lista(m[2])})`);
    } else if ((m = riga.match(/^KEY `([^`]+)` \((.+)\)$/))) {
      indici.push(`CREATE INDEX ${q(`${nome}__${m[1]}`)} ON ${q(nome)} (${lista(m[2])})`);
    } else if ((m = riga.match(/^CONSTRAINT `([^`]+)` FOREIGN KEY \((.+?)\) REFERENCES `([^`]+)` \((.+?)\)(.*)$/))) {
      const regole = m[5].replace(/ON DELETE NO ACTION|ON UPDATE NO ACTION/g, '').replace(/\s+/g, ' ').trim();
      chiavi.push(`ALTER TABLE ${q(nome)} ADD CONSTRAINT ${q(`${nome}__${m[1]}`)} FOREIGN KEY (${lista(m[2])}) REFERENCES ${q(m[3])} (${lista(m[4])})${regole ? ' ' + regole : ''}`);
    } else if ((m = riga.match(/^`([^`]+)` (.+)$/))) {
      colonne.push(colonna(nome, m[1], m[2], vincoli));
    } else if (riga) {
      throw new Error(`Riga non riconosciuta in ${nome}: ${riga}`);
    }
  }
  tabelle.push(`CREATE TABLE ${q(nome)} (\n  ${[...colonne, ...vincoli].join(',\n  ')}\n)`);
}

function colonna(tabella, nome, def, vincoli) {
  const orig = def;
  def = def.replace(/ COMMENT '(?:[^']|'')*'/g, '').replace(/ CHARACTER SET \w+| COLLATE \w+/g, '');
  const json = /CHECK \(json_valid/.test(def);
  def = def.replace(/ CHECK \(json_valid\(`[^`]+`\)\)/, '');
  const m = def.match(/^(\w+)(\(([^)]*)\))?( unsigned)?(.*)$/);
  if (!m) throw new Error(`Colonna non riconosciuta: ${tabella}.${nome} ${orig}`);
  const [, tipo, , arg, unsigned, resto] = m;
  if (unsigned) throw new Error(`unsigned non gestito: ${tabella}.${nome}`);
  let pg;
  let booleano = false;
  switch (tipo) {
    case 'int': pg = 'integer'; break;
    case 'bigint': pg = 'bigint'; break;
    case 'smallint': pg = 'smallint'; break;
    case 'tinyint': if (arg === '1') { pg = 'boolean'; booleano = true; } else pg = 'smallint'; break;
    case 'decimal': pg = `numeric(${arg})`; break;
    case 'float': case 'double': pg = 'double precision'; break;
    case 'varchar': case 'char':
      pg = 'citext';
      vincoli.push(`CONSTRAINT ${q(`${tabella}__${nome}__lunghezza`)} CHECK (char_length(${q(nome)}) <= ${arg})`);
      break;
    case 'text': case 'tinytext': case 'mediumtext': case 'longtext': pg = json ? 'jsonb' : 'text'; break;
    case 'date': pg = 'date'; break;
    case 'time': pg = 'time'; break;
    case 'datetime': case 'timestamp': pg = 'timestamptz'; break;
    case 'year': pg = 'smallint'; break;
    case 'blob': case 'longblob': case 'mediumblob': pg = 'bytea'; break;
    case 'enum': {
      const valori = [...arg.matchAll(/'((?:[^']|'')*)'/g)].map((v) => v[1]);
      pg = `varchar(${Math.max(...valori.map((v) => v.length))})`;
      vincoli.push(`CONSTRAINT ${q(`${tabella}__${nome}__valori`)} CHECK (${q(nome)} IN (${valori.map((v) => `'${v}'`).join(', ')}))`);
      break;
    }
    case 'set': {
      const valori = [...arg.matchAll(/'([^']*)'/g)].map((v) => v[1]);
      pg = `varchar(${valori.join(',').length})`;
      // Come in MariaDB: vuota oppure elenco di valori ammessi separati da virgola.
      vincoli.push(`CONSTRAINT ${q(`${tabella}__${nome}__valori`)} CHECK (${q(nome)} = '' OR string_to_array(${q(nome)}, ',') <@ ARRAY[${valori.map((v) => `'${v}'`).join(', ')}]::text[])`);
      break;
    }
    default: throw new Error(`Tipo non gestito: ${tabella}.${nome} ${tipo}`);
  }
  const parti = [q(nome), pg];
  if (/AUTO_INCREMENT/.test(resto)) parti.push('GENERATED BY DEFAULT AS IDENTITY');
  if (/NOT NULL/.test(resto)) parti.push('NOT NULL');
  const d = resto.match(/DEFAULT ('(?:[^']|'')*'|[^ ]+)/);
  if (d && d[1] !== 'NULL') parti.push(`DEFAULT ${valoreDefault(d[1], booleano, pg)}`);
  if (/ON UPDATE current_timestamp\(\)/.test(resto)) {
    trigger.push([tabella, nome]);
  }
  return parti.join(' ');
}

function valoreDefault(v, booleano, pg) {
  if (/^current_timestamp\(\)$/i.test(v)) return 'CURRENT_TIMESTAMP';
  if (/^curdate\(\)$/i.test(v)) return 'CURRENT_DATE';
  if (booleano) {
    const n = v.replace(/'/g, '');
    if (n !== '0' && n !== '1') throw new Error(`Default booleano non 0/1: ${v}`);
    return n === '1' ? 'true' : 'false';
  }
  if (pg === 'jsonb' && v.startsWith("'")) return `${v}::jsonb`;
  return v;
}

// Un trigger per ogni colonna "ON UPDATE current_timestamp": stessa regola di MariaDB,
// la colonna si aggiorna solo se la riga cambia davvero e se chi scrive non la imposta.
const funzione = `CREATE OR REPLACE FUNCTION cora_aggiorna_timestamp() RETURNS trigger AS $$
DECLARE
  colonna text := TG_ARGV[0];
  vecchio jsonb := to_jsonb(OLD) - colonna;
  nuovo jsonb := to_jsonb(NEW) - colonna;
BEGIN
  IF vecchio IS DISTINCT FROM nuovo AND (to_jsonb(NEW) -> colonna) IS NOT DISTINCT FROM (to_jsonb(OLD) -> colonna) THEN
    NEW := jsonb_populate_record(NEW, jsonb_build_object(colonna, CURRENT_TIMESTAMP));
  END IF;
  RETURN NEW;
END $$ LANGUAGE plpgsql`;

const istruzioni = [
  'CREATE EXTENSION IF NOT EXISTS citext',
  funzione,
  ...tabelle,
  ...indici,
  ...chiavi,
  ...trigger.map(([t, c]) => `CREATE TRIGGER ${q(`${t}__${c}__aggiorna`)} BEFORE UPDATE ON ${q(t)} FOR EACH ROW EXECUTE FUNCTION cora_aggiorna_timestamp('${c}')`),
];

const nomi = tabelle.map((t) => t.match(/CREATE TABLE "([^"]+)"/)[1]);
const ts = `import { MigrationInterface, QueryRunner } from 'typeorm';

/**
 * Schema iniziale di Cora su Postgres, generato da scripts/converti-schema.js
 * dallo schema MariaDB di PAManager (install_full.sql + migrazioni 001-065).
 * ${nomi.length} tabelle. Le viste MariaDB (v_*) non sono riportate: nessun codice le usa.
 */
export class SchemaIniziale1761206400000 implements MigrationInterface {
  name = 'SchemaIniziale1761206400000';

  public async up(q: QueryRunner): Promise<void> {
${istruzioni.map((s) => `    await q.query(${JSON.stringify(s)});`).join('\n')}
  }

  public async down(q: QueryRunner): Promise<void> {
${nomi.reverse().map((n) => `    await q.query('DROP TABLE IF EXISTS "${n}" CASCADE');`).join('\n')}
    await q.query('DROP FUNCTION IF EXISTS cora_aggiorna_timestamp()');
  }
}
`;
fs.writeFileSync(uscita, ts);
console.log(`${nomi.length} tabelle, ${indici.length} indici, ${chiavi.length} chiavi esterne, ${trigger.length} trigger -> ${uscita}`);
