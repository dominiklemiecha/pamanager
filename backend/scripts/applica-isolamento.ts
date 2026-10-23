/**
 * Applica ruolo, permessi e regole di isolamento (RLS) al database, come fa il
 * backend all'avvio. Serve prima di salvare istantanee di test, cosi' i
 * ripristini portano con se' anche la protezione.
 */
import 'reflect-metadata';
import { DataSource } from 'typeorm';
import { opzioniDatabase } from '../src/database/data-source';
import { applicaRegole } from '../src/common/database/isolamento';

(async () => {
  const ds = new DataSource({ ...opzioniDatabase(), entities: [] });
  await ds.initialize();
  const n = await applicaRegole(ds);
  console.log(`Isolamento applicato su ${n} tabelle`);
  await ds.destroy();
})().catch((e) => { console.error(e); process.exit(1); });
