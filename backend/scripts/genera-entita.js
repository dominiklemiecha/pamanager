#!/usr/bin/env node
/**
 * Genera le entita' TypeORM leggendo lo schema Postgres gia' migrato.
 *
 * Le entita' descrivono le tabelle cosi' come sono: nessuna relazione
 * automatica, nessun default inventato. I servizi le usano con il repository
 * o con SQL esplicito; lo schema resta governato solo dalle migrazioni.
 *
 * Uso: node scripts/genera-entita.js   (stesse variabili DB_* del backend)
 */
require('dotenv').config({ path: require('path').resolve(__dirname, '../.env') });
const fs = require('fs');
const path = require('path');
const { Client } = require('pg');

const CARTELLA = path.resolve(__dirname, '../src/database/entita');

const pascal = (s) => s.split('_').map((p) => p[0].toUpperCase() + p.slice(1)).join('');
const camel = (s) => { const p = pascal(s); return p[0].toLowerCase() + p.slice(1); };

// Tipo Postgres -> tipo della colonna TypeORM e tipo TypeScript.
function tipi(c) {
  switch (c.udt_name) {
    case 'int4': return ['integer', 'number'];
    case 'int8': return ['bigint', 'string'];
    case 'int2': return ['smallint', 'number'];
    case 'bool': return ['boolean', 'boolean'];
    case 'numeric': return [`numeric', precision: ${c.numeric_precision}, scale: ${c.numeric_scale}, transformer: numero, type2: '`, 'number'];
    case 'float8': return ['double precision', 'number'];
    case 'citext': return ['citext', 'string'];
    case 'varchar': return [`varchar', length: ${c.character_maximum_length}, type2: '`, 'string'];
    case 'text': return ['text', 'string'];
    case 'json': return ['json', 'string'];
    case 'timestamptz': return ['timestamptz', 'Date'];
    case 'date': return ['date', 'string'];
    case 'time': return ['time', 'string'];
    case 'bytea': return ['bytea', 'Buffer'];
    case 'uuid': return ['uuid', 'string'];
    case 'bpchar': return [`char', length: ${c.character_maximum_length}, type2: '`, 'string'];
    default: throw new Error(`Tipo non gestito: ${c.table_name}.${c.column_name} ${c.udt_name}`);
  }
}

async function main() {
  const db = new Client({
    host: process.env.DB_HOST, port: Number(process.env.DB_PORT), user: process.env.DB_USERNAME,
    password: process.env.DB_PASSWORD, database: process.env.DB_DATABASE,
  });
  await db.connect();
  const tabelle = (await db.query(`SELECT table_name FROM information_schema.tables WHERE table_schema = 'public'
    AND table_type = 'BASE TABLE' AND table_name <> 'typeorm_migrations' ORDER BY 1`)).rows.map((r) => r.table_name);
  fs.mkdirSync(CARTELLA, { recursive: true });
  const indice = [];
  for (const t of tabelle) {
    const col = (await db.query(`SELECT * FROM information_schema.columns WHERE table_schema = 'public' AND table_name = $1 ORDER BY ordinal_position`, [t])).rows;
    const pk = (await db.query(`SELECT a.attname FROM pg_index i JOIN pg_attribute a ON a.attrelid = i.indrelid AND a.attnum = ANY(i.indkey)
      WHERE i.indrelid = $1::regclass AND i.indisprimary`, [`"${t}"`])).rows.map((r) => r.attname);
    const classe = pascal(t);
    let usaNumero = false;
    const righe = col.map((c) => {
      let [tipoDb, tipoTs] = tipi(c);
      if (tipoDb.includes('transformer: numero')) usaNumero = true;
      const opzioni = [`name: '${c.column_name}'`];
      // "varchar', length: 30, type2: '" e simili: il tipo e' la prima parte.
      const [base, resto] = tipoDb.split("', ");
      opzioni.push(`type: '${base}'`);
      if (resto) opzioni.push(resto.replace(/, type2: '$/, '').replace(/type2: '$/, ''));
      if (c.is_nullable === 'YES') opzioni.push('nullable: true');
      const generata = c.is_identity === 'YES';
      const uuidGenerato = c.udt_name === 'uuid' && /gen_random_uuid/.test(c.column_default || '');
      const decoratore = pk.includes(c.column_name)
        ? (uuidGenerato ? `@PrimaryGeneratedColumn('uuid', { name: '${c.column_name}' })`
          : generata ? `@PrimaryGeneratedColumn({ ${opzioni.filter((o) => !o.startsWith('type:')).join(', ')}, type: '${base}' })` : `@PrimaryColumn({ ${opzioni.join(', ')} })`)
        : `@Column({ ${opzioni.join(', ')}${c.column_default !== null ? ', select: true' : ''} })`;
      const opzionale = c.is_nullable === 'YES' ? ' | null' : '';
      return `  ${decoratore.replace(', select: true', '')}\n  ${camel(c.column_name)}!: ${tipoTs}${opzionale};`;
    });
    const autoPk = (c) => c.is_identity === 'YES' || (c.udt_name === 'uuid' && /gen_random_uuid/.test(c.column_default || ''));
    const importa = ['Column', 'Entity', col.some((c) => pk.includes(c.column_name) && autoPk(c)) ? 'PrimaryGeneratedColumn' : null,
      col.some((c) => pk.includes(c.column_name) && !autoPk(c)) ? 'PrimaryColumn' : null].filter(Boolean);
    const file = `// Generato da scripts/genera-entita.js dallo schema: non modificare a mano.
import { ${importa.join(', ')} } from 'typeorm';
${usaNumero ? "import { numero } from './numero';\n" : ''}
@Entity({ name: '${t}' })
export class ${classe} {
${righe.join('\n\n')}
}
`;
    fs.writeFileSync(path.join(CARTELLA, `${t.replace(/_/g, '-')}.entity.ts`), file);
    indice.push(`export { ${classe} } from './${t.replace(/_/g, '-')}.entity';`);
  }
  fs.writeFileSync(path.join(CARTELLA, 'numero.ts'), `import { ValueTransformer } from 'typeorm';

/**
 * Le colonne numeric arrivano da Postgres come stringhe: qui diventano numeri.
 * Gli importi hanno al massimo 2 decimali, quindi il passaggio e' esatto.
 */
export const numero: ValueTransformer = {
  to: (v: number | null | undefined) => v,
  from: (v: string | null) => (v === null || v === undefined ? null : Number(v)),
};
`);
  fs.writeFileSync(path.join(CARTELLA, 'index.ts'), `// Generato da scripts/genera-entita.js\n${indice.join('\n')}\n`);
  console.log(`${tabelle.length} entita' generate in ${CARTELLA}`);
  await db.end();
}

main().catch((e) => { console.error(e); process.exit(1); });
