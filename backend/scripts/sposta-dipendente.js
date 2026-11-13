#!/usr/bin/env node
/**
 * Sposta un dipendente da un'azienda all'altra con tutti i suoi dati personali.
 *
 *   node scripts/sposta-dipendente.js --dipendente <cognome|id> --da <slug|nome> --a <slug|nome> [--esegui]
 *
 * Senza --esegui e' una prova a secco: stampa cosa cambierebbe e annulla.
 * Con --esegui tutto avviene in una sola transazione.
 *
 * Passano alla nuova azienda: anagrafica, presenze, ferie/permessi e saldi,
 * buste paga, documenti personali, malattie, accessi porta, assunzione,
 * notifiche e iscrizioni push del dipendente, foto profilo.
 * Restano nella vecchia: chat, corsi Academy, eventi calendario, documenti
 * aziendali caricati in massa (bulk_group). Reparto e CCNL vengono svuotati:
 * li assegna l'HR della nuova azienda. Le sessioni aperte vengono chiuse.
 *
 * Usa DB_HOST, DB_PORT, DB_USERNAME, DB_PASSWORD, DB_DATABASE e UPLOAD_DIR
 * come il backend (nel container sono gia' impostate).
 */
const fs = require('fs');
const path = require('path');
const { Client } = require('pg');

/** Tabelle con company_id + employee_id che restano all'azienda di partenza. */
const RESTANO = new Set(['academy_assignments', 'academy_events']);

function argomento(nome) {
  const i = process.argv.indexOf(`--${nome}`);
  return i > 0 ? process.argv[i + 1] : undefined;
}
const DIP = argomento('dipendente');
const DA = argomento('da');
const A = argomento('a');
const ESEGUI = process.argv.includes('--esegui');

if (!DIP || !DA || !A) {
  console.error('Uso: node scripts/sposta-dipendente.js --dipendente <cognome|id> --da <slug|nome> --a <slug|nome> [--esegui]');
  process.exit(1);
}

async function azienda(db, chiave) {
  const r = await db.query('SELECT id, name, slug FROM companies WHERE slug = $1 OR name = $1 OR id::text = $1', [chiave]);
  if (r.rows.length !== 1) throw new Error(`Azienda "${chiave}": ${r.rows.length} risultati, serve esattamente 1`);
  return r.rows[0];
}

async function main() {
  const db = new Client({
    host: process.env.DB_HOST || 'localhost',
    port: Number(process.env.DB_PORT || 5432),
    user: process.env.DB_USERNAME || 'cora',
    password: process.env.DB_PASSWORD || '',
    database: process.env.DB_DATABASE || 'cora',
  });
  await db.connect();
  const fileDaTogliere = [];
  try {
    const da = await azienda(db, DA);
    const a = await azienda(db, A);
    if (da.id === a.id) throw new Error('Azienda di partenza e di arrivo coincidono');

    const trovati = (await db.query(
      `SELECT id, first_name, last_name, username, fiscal_code, nfc_uid, photo_path, is_active FROM employees
        WHERE company_id = $1 AND (id::text = $2 OR last_name = $2)`, [da.id, DIP])).rows;
    if (trovati.length !== 1) {
      for (const t of trovati) console.error(`  id ${t.id}: ${t.first_name} ${t.last_name} (${t.username})`);
      throw new Error(`Dipendente "${DIP}" in ${da.name}: ${trovati.length} risultati, serve esattamente 1 (usa l'id)`);
    }
    const e = trovati[0];
    console.log(`Dipendente: id ${e.id} ${e.first_name} ${e.last_name} (${e.username})${e.is_active ? '' : ' [NON ATTIVO]'}`);
    console.log(`Da: ${da.name} (id ${da.id})  ->  A: ${a.name} (id ${a.id})`);
    console.log(ESEGUI ? 'MODALITA: ESECUZIONE' : 'MODALITA: PROVA A SECCO (nessuna modifica)');

    // Un account staff (HR) legato al dipendente va gestito a parte: non si
    // spostano permessi di amministrazione senza una decisione esplicita.
    const staff = (await db.query('SELECT id, username, role FROM users WHERE employee_id = $1', [e.id])).rows;
    if (staff.length) {
      throw new Error(`Il dipendente e' collegato a un account staff (${staff.map((u) => `${u.username}/${u.role}`).join(', ')}): spostamento fermato, da decidere a parte`);
    }

    // Vincoli unici per azienda: username, codice fiscale, badge NFC.
    const conflitti = (await db.query(
      `SELECT id, first_name, last_name, username FROM employees WHERE company_id = $1
         AND (username = $2 OR (fiscal_code IS NOT NULL AND fiscal_code = $3) OR (nfc_uid IS NOT NULL AND nfc_uid = $4))`,
      [a.id, e.username, e.fiscal_code, e.nfc_uid])).rows;
    if (conflitti.length) {
      for (const c of conflitti) console.error(`  in ${a.name} esiste gia' id ${c.id}: ${c.first_name} ${c.last_name} (${c.username})`);
      throw new Error('Username, codice fiscale o badge gia\' presenti nell\'azienda di arrivo');
    }

    await db.query('BEGIN');

    // Foto profilo: la cartella dipende dall'azienda (uploads/co-N/...).
    let nuovaFoto = e.photo_path;
    const prefisso = `uploads/co-${da.id}/profile_photos/`;
    if (e.photo_path && String(e.photo_path).startsWith(prefisso)) {
      const nome = String(e.photo_path).slice(prefisso.length);
      const radice = path.resolve(process.env.UPLOAD_DIR || './uploads');
      const origine = path.join(radice, `co-${da.id}`, 'profile_photos', nome);
      const destDir = path.join(radice, `co-${a.id}`, 'profile_photos');
      nuovaFoto = `uploads/co-${a.id}/profile_photos/${nome}`;
      if (fs.existsSync(origine)) {
        console.log(`  foto: ${e.photo_path} -> ${nuovaFoto}`);
        if (ESEGUI) {
          fs.mkdirSync(destDir, { recursive: true });
          fs.copyFileSync(origine, path.join(destDir, nome));
          fileDaTogliere.push(origine);
        }
      } else {
        console.log(`  foto: file non trovato (${origine}), si svuota il riferimento`);
        nuovaFoto = null;
      }
    }

    const r0 = await db.query(
      `UPDATE employees SET company_id = $2, department_id = NULL, ccnl_id = NULL, photo_path = $3 WHERE id = $1 AND company_id = $4`,
      [e.id, a.id, nuovaFoto, da.id]);
    console.log(`  employees: ${r0.rowCount}`);

    // Tutte le tabelle con company_id + employee_id, lette dal database:
    // cosi' sono comprese anche quelle aggiunte da migrazioni successive.
    const tabelle = (await db.query(
      `SELECT c1.table_name FROM information_schema.columns c1
         JOIN information_schema.columns c2 ON c2.table_schema = c1.table_schema AND c2.table_name = c1.table_name AND c2.column_name = 'employee_id'
         JOIN information_schema.tables t ON t.table_schema = c1.table_schema AND t.table_name = c1.table_name AND t.table_type = 'BASE TABLE'
        WHERE c1.table_schema = 'public' AND c1.column_name = 'company_id' AND c1.table_name NOT IN ('employees', 'users')
        ORDER BY c1.table_name`)).rows.map((x) => x.table_name);
    for (const t of tabelle) {
      if (RESTANO.has(t)) {
        const n = await db.query(`SELECT COUNT(*)::int AS n FROM "${t}" WHERE employee_id = $1 AND company_id = $2`, [e.id, da.id]);
        console.log(`  ${t}: ${n.rows[0].n} restano in ${da.name}`);
        continue;
      }
      const extra = t === 'employee_documents' ? ' AND bulk_group IS NULL' : '';
      const r = await db.query(`UPDATE "${t}" SET company_id = $3 WHERE employee_id = $1 AND company_id = $2${extra}`, [e.id, da.id, a.id]);
      console.log(`  ${t}: ${r.rowCount}`);
    }

    const rn = await db.query(
      `UPDATE notifications SET company_id = $3 WHERE recipient_type = 'employee' AND recipient_id = $1 AND company_id = $2`, [e.id, da.id, a.id]);
    console.log(`  notifications: ${rn.rowCount}`);
    const rp = await db.query(
      `UPDATE push_subscriptions SET company_id = $3 WHERE user_type = 'employee' AND user_id = $1 AND company_id = $2`, [e.id, da.id, a.id]);
    console.log(`  push_subscriptions: ${rp.rowCount}`);

    // Le sessioni aperte portano l'azienda vecchia: si chiudono, al prossimo accesso entra in quella nuova.
    const rs = await db.query(
      `UPDATE auth_sessions SET revoked_at = CURRENT_TIMESTAMP, revoked_reason = 'azienda_cambiata'
        WHERE subject_type = 'employee' AND subject_id = $1 AND revoked_at IS NULL`, [e.id]);
    console.log(`  sessioni chiuse: ${rs.rowCount}`);

    await db.query(
      `INSERT INTO audit_log (user_type, user_id, action, entity_type, entity_id, old_values, new_values, created_at)
       VALUES ('system', 0, 'employee_company_moved', 'employee', $1, $2, $3, CURRENT_TIMESTAMP)`,
      [e.id, JSON.stringify({ company_id: da.id }), JSON.stringify({ company_id: a.id })]).catch((err) => {
      throw new Error(`audit_log non scritto: ${err.message}`);
    });

    if (ESEGUI) {
      await db.query('COMMIT');
      for (const f of fileDaTogliere) fs.rmSync(f, { force: true });
      console.log('Fatto: spostamento confermato.');
    } else {
      await db.query('ROLLBACK');
      console.log('Prova a secco: nessuna modifica salvata. Rilancia con --esegui per confermare.');
    }
  } catch (err) {
    await db.query('ROLLBACK').catch(() => undefined);
    console.error(`ERRORE: ${err.message}`);
    process.exitCode = 1;
  } finally {
    await db.end();
  }
}

main();
