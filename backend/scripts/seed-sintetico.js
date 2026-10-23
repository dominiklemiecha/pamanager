#!/usr/bin/env node
/**
 * Dati sintetici per i test di equivalenza della migrazione.
 *
 * Riempie il MariaDB della baseline PHP (docker-compose.baseline.yml) con due
 * aziende inventate, il loro personale, dipendenti, richieste, comunicazioni,
 * eventi, timbrature e una chat. Tutto deterministico: stessi dati a ogni
 * esecuzione, cosi' le risposte del PHP si possono confrontare con quelle del
 * nuovo backend (che riceve gli stessi dati con il convertitore MariaDB -> Postgres).
 *
 * Nessun dato reale: nomi inventati, email su dominio .test, codici fiscali
 * calcolati da dati finti.
 *
 * Uso: node scripts/seed-sintetico.js   (BASELINE_DB_HOST/PORT/USER/PASS/NAME per cambiare)
 */
const mysql = require('mysql2/promise');
const bcrypt = require('bcrypt');

const DB = {
  host: process.env.BASELINE_DB_HOST || '127.0.0.1',
  port: Number(process.env.BASELINE_DB_PORT || 33070),
  user: process.env.BASELINE_DB_USER || 'baseline',
  password: process.env.BASELINE_DB_PASS || 'baseline_sviluppo',
  database: process.env.BASELINE_DB_NAME || 'baseline',
};
// Solo database locali che si chiamano "baseline": mai altro.
if (!['127.0.0.1', 'localhost'].includes(DB.host) || DB.database !== 'baseline') {
  console.error(`Rifiutato: ${DB.host}/${DB.database} non e' il database locale della baseline`);
  process.exit(1);
}

const PASSWORD = 'Prova!Cora2026';

// ---------- Codice fiscale (algoritmo ufficiale, dati finti) ----------
const VOCALI = 'AEIOU';
function consonantiVocali(s) {
  const t = s.toUpperCase().replace(/[^A-Z]/g, '');
  return { c: [...t].filter((x) => !VOCALI.includes(x)).join(''), v: [...t].filter((x) => VOCALI.includes(x)).join('') };
}
function codCognome(s) { const { c, v } = consonantiVocali(s); return (c + v + 'XXX').slice(0, 3); }
function codNome(s) {
  const { c, v } = consonantiVocali(s);
  if (c.length >= 4) return c[0] + c[2] + c[3];
  return (c + v + 'XXX').slice(0, 3);
}
const MESI = 'ABCDEHLMPRST';
const DISPARI = { 0: 1, 1: 0, 2: 5, 3: 7, 4: 9, 5: 13, 6: 15, 7: 17, 8: 19, 9: 21, A: 1, B: 0, C: 5, D: 7, E: 9, F: 13, G: 15, H: 17, I: 19, J: 21, K: 2, L: 4, M: 18, N: 20, O: 11, P: 3, Q: 6, R: 8, S: 12, T: 14, U: 16, V: 10, W: 22, X: 25, Y: 24, Z: 23 };
function carattereControllo(s) {
  let tot = 0;
  [...s].forEach((ch, i) => {
    if (i % 2 === 0) tot += DISPARI[ch];
    else tot += /\d/.test(ch) ? Number(ch) : ch.charCodeAt(0) - 65;
  });
  return String.fromCharCode(65 + (tot % 26));
}
function codiceFiscale(cognome, nome, nascita, donna, comune) {
  const [a, m, g] = nascita.split('-').map(Number);
  const giorno = String(g + (donna ? 40 : 0)).padStart(2, '0');
  const base = codCognome(cognome) + codNome(nome) + String(a).slice(2) + MESI[m - 1] + giorno + comune;
  return base + carattereControllo(base);
}

// ---------- Anagrafiche inventate ----------
const AZIENDE = [
  { id: 1, name: 'Alfa Servizi S.r.l.', slug: 'alfa', working_days: 'mon,tue,wed,thu,fri', hours_per_day: 8, smart_working_days: 'fri',
    lunch_break_start: '13:00:00', lunch_break_end: '14:00:00', buoni_pasto_enabled: 1, buoni_pasto_min_hours_enabled: 1,
    buoni_pasto_min_hours: 6, buoni_pasto_sw_eligible: 0, default_ccnl_id: 1, timbratura_enabled: 1 },
  { id: 2, name: 'Beta Logistica S.r.l.', slug: 'beta', working_days: 'mon,tue,wed,thu,fri,sat', hours_per_day: 6.5, smart_working_days: null,
    lunch_break_start: null, lunch_break_end: null, buoni_pasto_enabled: 0, buoni_pasto_min_hours_enabled: 0,
    buoni_pasto_min_hours: 6, buoni_pasto_sw_eligible: 0, default_ccnl_id: 2, timbratura_enabled: 0 },
];
// Il numero e' la chiave usata qui sotto; nel database l'id lo assegna MariaDB
// (install_full.sql crea gia' 10 reparti di esempio nell'azienda 1).
const REPARTI = [
  { chiave: 1, company_id: 1, name: 'Commerciale', code: 'COMM' },
  { chiave: 2, company_id: 1, name: 'Tecnico', code: 'TECN' },
  { chiave: 3, company_id: 2, name: 'Magazzino', code: 'MAG' },
];
// [nome, cognome, nascita, donna, azienda, reparto, assunzione, extra]
const DIPENDENTI = [
  ['Giulia', 'Ferraresi', '1990-04-12', true, 1, 1, '2019-03-01', {}],
  ['Marco', 'Bellandi', '1985-11-30', false, 1, 1, '2021-09-15', { nfc_uid: '04A1B2C3D4E5F6' }],
  ['Sara', 'Montanari', '1994-07-08', true, 1, 2, '2026-05-04', { probation_end_date: '2026-11-03' }],
  ['Luca', 'Pellegatti', '1988-02-17', false, 1, 2, '2015-01-12', { hours_per_day: 4, working_days: 'mon,tue,wed,thu,fri' }],
  ['Elena', 'Gualtieri', '1979-09-25', true, 1, 1, '2012-06-01', { buoni_pasto_excluded: 1 }],
  ['Davide', 'Rinaldini', '1996-12-03', false, 1, 2, '2023-02-01', { must_change_password: 1 }],
  ['Chiara', 'Lombardelli', '1992-05-19', true, 1, null, '2020-10-05', { is_active: 0 }],
  ['Paolo', 'Vezzani', '1983-08-14', false, 1, 1, '2017-04-03', { ferie_year_override: 30 }],
  ['Anna', 'Castellari', '1991-01-22', true, 2, 3, '2018-07-02', {}],
  ['Roberto', 'Fontanesi', '1987-03-09', false, 2, 3, '2022-03-14', { nfc_uid: '04FFEEDDCCBBAA' }],
  ['Francesca', 'Morselli', '1995-10-11', true, 2, 3, '2024-11-04', {}],
  ['Stefano', 'Barbieri', '1980-06-28', false, 2, null, '2010-02-01', {}],
  ['Laura', 'Zanichelli', '1998-04-04', true, 2, 3, '2026-02-02', {}],
];

// [username, ruolo, azienda (null = piu' aziende), nome, reparto, aziende collegate]
const STAFF = [
  ['hr.alfa', 'admin', 1, 'Martina Sala (HR Alfa)', null, [1]],
  ['hr.beta', 'admin', 2, 'Giorgio Neri (HR Beta)', null, [2]],
  ['resp.commerciale', 'admin_reparto', 1, 'Fabio Conti (Resp. Commerciale)', 1, [1]],
  ['studio.commercialista', 'accountant', 1, 'Studio Rossi Commercialisti', null, [1, 2]],
  ['studio.consulente', 'consulente_lavoro', 1, 'Studio Bianchi Consulenti del Lavoro', null, [1, 2]],
  ['formatore.alfa', 'formatore', 1, 'Irene Valli (Formazione)', null, [1]],
];

async function main() {
  const db = await mysql.createConnection({ ...DB, timezone: '+00:00', multipleStatements: false });
  // Stesso fuso del PHP (Database::connect): l'ora di Roma di adesso.
  const offset = new Intl.DateTimeFormat('en', { timeZone: 'Europe/Rome', timeZoneName: 'longOffset' })
    .formatToParts(new Date()).find((p) => p.type === 'timeZoneName').value.replace('GMT', '') || '+00:00';
  await db.query(`SET time_zone = '${offset}'`);
  const hash = (await bcrypt.hash(PASSWORD, 12)).replace(/^\$2b\$/, '$2y$');
  const q = (sql, p) => db.execute(sql, p);

  const [[{ n }]] = await db.query('SELECT COUNT(*) AS n FROM employees');
  if (n > 0) {
    console.error('La baseline ha gia\' dei dipendenti: ricreala (docker compose ... down -v) prima di riseminare.');
    process.exit(1);
  }

  for (const a of AZIENDE) {
    const campi = Object.keys(a).filter((k) => k !== 'id');
    await q(`INSERT INTO companies (id, ${campi.join(', ')}, needs_setup, is_active) VALUES (?, ${campi.map(() => '?').join(', ')}, 0, 1)
             ON DUPLICATE KEY UPDATE ${campi.map((c) => `${c} = VALUES(${c})`).join(', ')}, needs_setup = 0`,
      [a.id, ...campi.map((c) => a[c])]);
  }
  const reparto = {};
  for (const r of REPARTI) {
    const [res] = await q('INSERT INTO departments (company_id, name, code, is_active) VALUES (?, ?, ?, 1)', [r.company_id, r.name, r.code]);
    reparto[r.chiave] = res.insertId;
  }

  // Admin globale creato dall'entrypoint: stessa password degli altri.
  await q('UPDATE users SET password_hash = ?, email = ?, must_change_password = 0 WHERE username = ?', [hash, 'piattaforma@cora.test', 'connecteed']);
  const staffId = {};
  for (const [username, ruolo, azienda, nome, rep, collegate] of STAFF) {
    const [r] = await q(`INSERT INTO users (username, password_hash, role, company_id, department_id, name, email, is_active, must_change_password)
                         VALUES (?, ?, ?, ?, ?, ?, ?, 1, 0)`, [username, hash, ruolo, azienda, rep ? reparto[rep] : null, nome, `${username}@cora.test`]);
    staffId[username] = r.insertId;
    for (const c of collegate) await q('INSERT INTO user_companies (user_id, company_id) VALUES (?, ?)', [r.insertId, c]);
  }

  const dip = [];
  for (const [nome, cognome, nascita, donna, azienda, rep, assunzione, extra] of DIPENDENTI) {
    const cf = codiceFiscale(cognome, nome, nascita, donna, azienda === 1 ? 'A944' : 'F257');
    const riga = {
      company_id: azienda, username: `${nome}.${cognome}`.toLowerCase(), password_hash: hash, fiscal_code: cf,
      first_name: nome, last_name: cognome, email: `${nome}.${cognome}@esempio.test`.toLowerCase(), birth_date: nascita,
      department_id: rep ? reparto[rep] : null, hire_date: assunzione, position: rep === 2 ? 'Tecnico' : rep === 3 ? 'Magazziniere' : 'Impiegato',
      is_active: 1, must_change_password: 0, ...extra,
    };
    const campi = Object.keys(riga);
    const [r] = await q(`INSERT INTO employees (${campi.join(', ')}) VALUES (${campi.map(() => '?').join(', ')})`, campi.map((c) => riga[c]));
    dip.push({ id: r.insertId, ...riga });
  }
  const E = (cognome) => dip.find((d) => d.last_name === cognome);

  // Saldi: alcuni con "residuo ad oggi" (istantanea), altri lasciati alla maturazione automatica.
  const saldi = [
    ['Ferraresi', 'ferie', 0, 12.5, '2026-09-01', 0, '2026-09'],
    ['Ferraresi', 'permesso', 0, 40, '2026-09-01', 0, '2026-09'],
    ['Bellandi', 'ferie', 19.5, 4, null, 2, '2026-10'],
    ['Castellari', 'ferie', 0, 8, '2026-07-01', 0, '2026-07'],
  ];
  for (const [cog, tipo, maturato, riporto, data, usato, mese] of saldi) {
    const d = E(cog);
    await q(`INSERT INTO employee_leave_balances (company_id, employee_id, year, leave_type, entitled, carried_over, balance_set_at, manual_used, accrual_last_month)
             VALUES (?, ?, 2026, ?, ?, ?, ?, ?, ?)`, [d.company_id, d.id, tipo, maturato, riporto, data, usato, mese]);
  }

  // Richieste in tutti gli stati e i tipi, con giornate intere e a ore.
  const hrAlfa = staffId['hr.alfa'];
  const hrBeta = staffId['hr.beta'];
  const richieste = [
    ['Ferraresi', 'ferie', '2026-08-10', '2026-08-21', 1, null, null, 'Vacanze estive', 'approved', hrAlfa],
    ['Ferraresi', 'permesso', '2026-10-06', '2026-10-06', 0, '09:00', '11:30', 'Visita medica', 'approved', hrAlfa],
    ['Ferraresi', 'ferie', '2026-12-28', '2026-12-31', 1, null, null, 'Fine anno', 'pending', null],
    ['Bellandi', 'permesso', '2026-09-15', '2026-09-15', 0, '12:00', '15:00', 'Commissioni', 'approved', hrAlfa],
    ['Bellandi', 'malattia', '2026-10-12', '2026-10-14', 1, null, null, 'Influenza', 'approved', hrAlfa],
    ['Bellandi', 'smart_working', '2026-10-21', '2026-10-21', 1, null, null, 'Lavoro da casa', 'approved', hrAlfa],
    ['Montanari', 'ferie', '2026-11-02', '2026-11-03', 1, null, null, 'Ponte', 'pending', null],
    ['Pellegatti', 'permesso_104', '2026-10-20', '2026-10-20', 1, null, null, 'Assistenza familiare', 'approved', null],
    ['Gualtieri', 'ferie', '2026-07-01', '2026-07-03', 1, null, null, 'Ferie', 'rejected', hrAlfa],
    ['Gualtieri', 'ferie', '2026-10-26', '2026-10-27', 1, null, null, 'Ferie', 'cancelled', null],
    ['Vezzani', 'congedo_parentale', '2026-09-01', '2026-09-05', 1, null, null, 'Congedo', 'approved', hrAlfa],
    ['Rinaldini', 'altro', '2026-10-23', '2026-10-23', 0, '16:00', '18:00', 'Trasloco', 'pending', null],
    ['Castellari', 'ferie', '2026-08-03', '2026-08-14', 1, null, null, 'Vacanze', 'approved', hrBeta],
    ['Fontanesi', 'permesso', '2026-10-19', '2026-10-19', 0, '08:00', '10:00', 'Ufficio postale', 'approved', hrBeta],
    ['Morselli', 'malattia', '2026-10-22', '2026-10-23', 1, null, null, 'Malattia', 'pending', null],
  ];
  // Orari di creazione tutti diversi: a parita' di ora MariaDB e Postgres
  // ordinerebbero le righe in modo diverso e i confronti non sarebbero stabili.
  let progressivo = 0;
  for (const [cog, tipo, da, a, intera, ora1, ora2, motivo, stato, approvatore] of richieste) {
    const d = E(cog);
    progressivo++;
    const creata = `2026-${String(Math.min(10, Number(da.slice(5, 7)))).padStart(2, '0')}-01 ${String(8 + (progressivo % 10)).padStart(2, '0')}:${String(progressivo * 3).padStart(2, '0')}:00`;
    await q(`INSERT INTO leave_requests (company_id, employee_id, leave_type, start_date, end_date, is_full_day, start_time, end_time, reason, status, approved_by, approved_at, protocol_number, created_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)`,
      [d.company_id, d.id, tipo, da, a, intera, ora1, ora2, motivo, stato, approvatore,
        stato === 'approved' || stato === 'rejected' ? `${da} 10:00:00` : null, tipo === 'malattia' ? 'INPS-0000123456' : null, creata]);
  }

  const comunicazioni = [
    [1, 'Chiusura aziendale di Natale', "L'ufficio resta chiuso dal 24 dicembre al 6 gennaio.", 'high', null, hrAlfa],
    [1, 'Nuovo regolamento parcheggio', 'Dal mese prossimo i posti sono assegnati per reparto.', 'normal', null, hrAlfa],
    [1, 'Riunione commerciale', 'Venerdi riunione di reparto alle 10.', 'low', 1, staffId['resp.commerciale']],
    [2, 'Inventario di fine anno', 'Il magazzino fa inventario il 30 dicembre.', 'high', null, hrBeta],
  ];
  for (const [azienda, titolo, testo, priorita, rep, autore] of comunicazioni) {
    await q(`INSERT INTO communications (company_id, title, content, priority, is_published, publish_date, is_global, department_id, created_by)
             VALUES (?, ?, ?, ?, 1, '2026-10-01', ?, ?, ?)`, [azienda, titolo, testo, priorita, rep ? 0 : 1, rep ? reparto[rep] : null, autore]);
  }

  const [ev] = await q(`INSERT INTO calendar_events (company_id, owner_type, owner_id, title, description, location, start_at, end_at, color, all_day)
                         VALUES (1, 'admin', ?, 'Colloqui annuali', 'Revisione obiettivi', 'Sala riunioni', '2026-10-28 10:00:00', '2026-10-28 12:00:00', '#ff9400', 0)`, [hrAlfa]);
  await q(`INSERT INTO calendar_event_participants (event_id, user_type, user_id, status) VALUES (?, 'employee', ?, 'accepted'), (?, 'employee', ?, 'pending')`,
    [ev.insertId, E('Ferraresi').id, ev.insertId, E('Bellandi').id]);

  const timbrature = [
    ['Bellandi', '2026-10-22 08:58:00', 'in'], ['Bellandi', '2026-10-22 13:02:00', 'out'],
    ['Bellandi', '2026-10-22 14:01:00', 'in'], ['Bellandi', '2026-10-22 18:05:00', 'out'],
    ['Bellandi', '2026-10-23 09:01:00', 'in'],
  ];
  for (const [cog, quando, tipo] of timbrature) {
    const d = E(cog);
    await q(`INSERT INTO attendance_punches (company_id, employee_id, punch_at, kind, source, ip, user_agent) VALUES (?, ?, ?, ?, 'nfc', '127.0.0.1', 'seed')`,
      [d.company_id, d.id, quando, tipo]);
  }

  const [conv] = await q(`INSERT INTO chat_conversations (company_id, participant1_type, participant1_id, participant2_type, participant2_id, conv_type, last_message_at, group_key)
                           VALUES (1, 'employee', ?, 'admin', ?, 'direct', '2026-10-22 11:05:00', '')`, [E('Ferraresi').id, hrAlfa]);
  const messaggi = [
    ['employee', E('Ferraresi').id, 'Buongiorno, mi serve il CUD 2025', 0, '2026-10-22 11:00:00'],
    ['admin', hrAlfa, 'Te lo carico entro oggi', 0, '2026-10-22 11:05:00'],
    ['admin', hrAlfa, 'Nota interna: chiedere al consulente', 1, '2026-10-22 11:06:00'],
  ];
  for (const [tipo, id, testo, interna, quando] of messaggi) {
    await q(`INSERT INTO chat_messages (company_id, conversation_id, sender_type, sender_id, message, is_internal, created_at) VALUES (1, ?, ?, ?, ?, ?, ?)`,
      [conv.insertId, tipo, id, testo, interna, quando]);
  }

  // Documenti: PDF finti scritti nello storage di tutte e due le parti
  // (volume della baseline e storage del nuovo backend), stesso percorso salvato.
  const documenti = [
    ['Ferraresi', 'payslip', 'Busta paga Settembre 2026', 2026, 9, hrAlfa, '2026-10-05 09:00:00'],
    ['Ferraresi', 'payslip', 'Busta paga Agosto 2026', 2026, 8, hrAlfa, '2026-09-05 09:00:00'],
    ['Ferraresi', 'cud', 'CU 2025', 2025, 12, hrAlfa, '2026-03-10 10:00:00'],
    ['Ferraresi', 'other', 'Contratto di assunzione', 2019, 3, hrAlfa, '2026-01-15 11:00:00'],
    ['Bellandi', 'payslip', 'Busta paga Settembre 2026', 2026, 9, hrAlfa, '2026-10-05 09:01:00'],
    ['Castellari', 'payslip', 'Busta paga Settembre 2026', 2026, 9, hrBeta, '2026-10-05 09:02:00'],
  ];
  const personali = [
    ['Ferraresi', 'Attestato sicurezza', 1, null, '2027-03-31', '2026-06-01 12:00:00'],
    ['Ferraresi', 'Regolamento aziendale', 1, 'seed-bulk-1', null, '2026-10-10 08:30:00'],
    ['Ferraresi', 'Nota riservata HR', 0, null, null, '2026-10-11 08:30:00'],
    ['Castellari', 'Attestato sicurezza', 1, null, null, '2026-06-02 12:00:00'],
  ];
  const fs = require('fs');
  const path = require('path');
  const { execFileSync } = require('child_process');
  const { PDFDocument, StandardFonts } = require('pdf-lib');
  const locale = path.join(__dirname, '..', 'storage', 'documents');
  fs.mkdirSync(locale, { recursive: true });
  const pdf = async (testo) => {
    const p = await PDFDocument.create();
    p.setCreationDate(new Date('2026-01-01T00:00:00Z'));
    p.setModificationDate(new Date('2026-01-01T00:00:00Z'));
    const font = await p.embedFont(StandardFonts.Helvetica);
    p.addPage([595.28, 841.89]).drawText(testo, { x: 50, y: 780, size: 14, font });
    // Senza object stream: il parser gratuito di FPDI (filigrana del PHP) non li legge.
    return Buffer.from(await p.save({ useObjectStreams: false }));
  };
  const scrivi = async (nome, testo) => {
    const dati = await pdf(testo);
    fs.writeFileSync(path.join(locale, nome), dati);
    return { percorso: `/var/www/html/storage/documents/${nome}`, dimensione: dati.length };
  };
  let nDoc = 0;
  for (const [cog, tipo, titolo, anno, mese, autore, quando] of documenti) {
    const d = E(cog);
    const nome = `seed_doc_${++nDoc}.pdf`;
    const f = await scrivi(nome, `${titolo} - ${d.first_name} ${d.last_name}`);
    await q(`INSERT INTO documents (company_id, employee_id, type, title, file_path, file_name, original_name, file_size, mime_type, month, year, uploaded_by, created_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'application/pdf', ?, ?, ?, ?)`,
      [d.company_id, d.id, tipo, titolo, f.percorso, nome, `${titolo.replace(/ /g, '_')}.pdf`, f.dimensione, mese, anno, autore, quando]);
  }
  for (const [cog, titolo, visibile, gruppo, scadenza, quando] of personali) {
    const d = E(cog);
    const nome = `seed_doc_${++nDoc}.pdf`;
    const f = await scrivi(nome, `${titolo} - ${d.first_name} ${d.last_name}`);
    await q(`INSERT INTO employee_documents (company_id, bulk_group, employee_id, name, file_path, file_name, original_name, file_size, mime_type, visible_to_employee, notify_pending, expires_on, uploaded_by, created_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'application/pdf', ?, 0, ?, ?, ?)`,
      [d.company_id, gruppo, d.id, titolo, f.percorso, nome, `${titolo.replace(/ /g, '_')}.pdf`, f.dimensione, visibile, scadenza, d.company_id === 1 ? hrAlfa : hrBeta, quando]);
  }
  execFileSync('docker', ['exec', 'cora-baseline-app-1', 'mkdir', '-p', '/var/www/html/storage/documents']);
  execFileSync('docker', ['cp', locale + path.sep + '.', 'cora-baseline-app-1:/var/www/html/storage/documents/']);
  execFileSync('docker', ['exec', 'cora-baseline-app-1', 'chown', '-R', 'www-data:www-data', '/var/www/html/storage/documents']);

  console.log(`Seminati: ${AZIENDE.length} aziende, ${STAFF.length + 1} utenti staff, ${dip.length} dipendenti, ${richieste.length} richieste. Password di tutti: ${PASSWORD}`);
  await db.end();
}

main().catch((e) => { console.error(e); process.exit(1); });
