import { createHmac } from 'crypto';
/** Casi del confronto visivo PHP / nuovo frontend (dati sintetici della baseline). */
const PW = 'Prova!Cora2026';

async function scrivi(page, sel, testo) { await page.fill(sel, testo); }

/** Entra con il modulo di login (uguale sui due sistemi). */
async function entra(page, base, utente) {
  await page.goto(base + '/auth/login.php');
  // Gli script della pagina di login possono ancora toccare i campi: si aspetta che finiscano.
  await page.waitForLoadState('networkidle');
  for (let i = 0; i < 3; i++) {
    await page.fill('#username', utente);
    await page.fill('#password', PW);
    if (await page.inputValue('#username') === utente) break;
    await page.waitForTimeout(300);
  }
  await Promise.all([page.waitForURL((u) => !u.pathname.startsWith('/auth/'), { waitUntil: 'load' }), page.click('button[type=submit]')]);
  await page.waitForLoadState('networkidle');
}
/** Codice TOTP (RFC 6238, 30 secondi, 6 cifre) per l'accesso al superadmin di prova. */
function totp(segreto) {
  const alfabeto = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
  let bit = '';
  for (const c of segreto.replace(/=+$/, '')) bit += alfabeto.indexOf(c).toString(2).padStart(5, '0');
  const chiave = Buffer.from(bit.match(/.{8}/g).map((b) => parseInt(b, 2)));
  const contatore = Buffer.alloc(8);
  contatore.writeBigUInt64BE(BigInt(Math.floor(Date.now() / 30000)));
  const h = createHmac('sha1', chiave).update(contatore).digest();
  const o = h[h.length - 1] & 15;
  return String((h.readUInt32BE(o) & 0x7fffffff) % 1000000).padStart(6, '0');
}
/** Superadmin di prova: password, poi codice. */
async function entraSa(page, base) {
  await page.goto(base + '/superadmin/login.php');
  await page.waitForSelector('input[name=username]');
  await page.fill('input[name=username]', 'sa.prova');
  await page.fill('input[name=password]', PW);
  await Promise.all([page.waitForURL(/totp\.php/), page.click('button')]);
  await page.waitForSelector('input[name=code]');
  await page.fill('input[name=code]', totp('JBSWY3DPEHPK3PXP'));
  await Promise.all([page.waitForURL(/index\.php/), page.click('button')]);
  await page.waitForLoadState('networkidle');
}
const comeSa = () => ({ utente: 'sa', login: (page, base) => entraSa(page, base) });
const come = (utente) => ({ utente, login: (page, base) => entra(page, base, utente) });

/** Se la guida interattiva parte da sola la si chiude (salvo i casi che la provano). */
async function senzaGuida(page) {
  await page.waitForTimeout(2500);
  const salta = page.locator('.pt-root.is-open .pt-skip');
  if (await salta.isVisible().catch(() => false)) { await salta.click(); await page.waitForTimeout(600); }
}

export const CASI = [
  { nome: 'login', percorso: '/auth/login.php' },
  {
    nome: 'login-errore', percorso: '/auth/login.php',
    azione: async (page) => { await scrivi(page, '#username', 'hr.alfa'); await scrivi(page, '#password', 'Sbagliata!2026'); await page.click('button[type=submit]'); await page.waitForSelector('.login-err'); },
  },
  { nome: 'recupero', percorso: '/auth/forgot-password.php' },
  {
    nome: 'recupero-inviato', percorso: '/auth/forgot-password.php',
    azione: async (page) => { await scrivi(page, '#identifier', 'nessuno.esiste'); await page.click('button[type=submit]'); await page.waitForSelector('.success-box'); },
  },
  { nome: 'reset-non-valido', percorso: `/auth/reset-password.php?token=${'a'.repeat(64)}`, attesa: 1200 },
  { nome: 'guida-benvenuto', percorso: '/employee/', attesa: 3000, ...come('elena.gualtieri') },
  { nome: 'home-dipendente', percorso: '/employee/', attesa: 1500, ...come('giulia.ferraresi'), azione: senzaGuida },
  { nome: 'home-dipendente-presenze', percorso: '/employee/', attesa: 800, ...come('marco.bellandi'),
    azione: async (page) => { await senzaGuida(page); await page.click('.cora-presence .cora-see-all'); await page.waitForSelector('#coraPresenceModal.is-open'); } },
  { nome: 'richieste-elenco', percorso: '/employee/leave-requests.php', attesa: 1200, ...come('giulia.ferraresi'), azione: senzaGuida },
  { nome: 'richieste-nuova', percorso: '/employee/leave-requests.php?new=permesso', attesa: 1200, ...come('giulia.ferraresi'), azione: senzaGuida },
  { nome: 'richieste-dettaglio', percorso: '/employee/leave-requests.php', attesa: 900, ...come('marco.bellandi'),
    azione: async (page) => { await senzaGuida(page); await page.locator('.cora-tr[data-cora-open]').nth(1).click(); await page.waitForTimeout(500); } },
  { nome: 'richieste-filtro', percorso: '/employee/leave-requests.php?status=pending', attesa: 900, ...come('giulia.ferraresi'), azione: senzaGuida },
  { nome: 'documenti-elenco', percorso: '/employee/documents.php', attesa: 1200, ...come('giulia.ferraresi'), azione: senzaGuida, paginaIntera: true },
  { nome: 'documenti-anno', percorso: '/employee/documents.php?year=2025', attesa: 1200, ...come('giulia.ferraresi'), azione: senzaGuida },
  { nome: 'documenti-anno-vuoto', percorso: '/employee/documents.php?year=2024', attesa: 1200, ...come('giulia.ferraresi'), azione: senzaGuida },
  { nome: 'documenti-vuoto', percorso: '/employee/documents.php', attesa: 1200, ...come('elena.gualtieri'), azione: senzaGuida },
  { nome: 'documenti-filtro', percorso: '/employee/documents.php', attesa: 900, ...come('giulia.ferraresi'),
    azione: async (page) => { await senzaGuida(page); await page.click('.cora-tab[data-filter=payslip]'); await page.waitForTimeout(400); } },
  { nome: 'documenti-cerca', percorso: '/employee/documents.php', attesa: 900, ...come('giulia.ferraresi'),
    azione: async (page) => { await senzaGuida(page); await page.fill('[data-cora-search]', 'regolamento'); await page.waitForTimeout(400); } },
  { nome: 'documenti-errore', percorso: '/employee/documents.php?download=999999', percorsoNode: '/employee/documents.php?errore=non_trovato', attesa: 1200, ...come('giulia.ferraresi'), azione: senzaGuida },
  { nome: 'comunicazioni-elenco', percorso: '/employee/communications.php', attesa: 1200, ...come('giulia.ferraresi'), azione: senzaGuida },
  { nome: 'comunicazioni-non-lette', percorso: '/employee/communications.php', attesa: 900, ...come('marco.bellandi'),
    azione: async (page) => { await senzaGuida(page); await page.click('.cora-tab[data-filter=unread]'); await page.waitForTimeout(400); } },
  // Differenza voluta: il PHP mostrava come testo il contenuto di <script> ("alert(3)"), il nuovo lo toglie.
  { nome: 'comunicazioni-testo', percorso: '/employee/communications.php?id=5', attesa: 1200, ...come('giulia.ferraresi'), azione: senzaGuida, paginaIntera: true, soglia: 0.005 },
  { nome: 'comunicazioni-alta', percorso: '/employee/communications.php?id=1', attesa: 1200, ...come('paolo.vezzani'), azione: senzaGuida },
  { nome: 'comunicazioni-confermata', percorso: '/employee/communications.php?id=1&message=ack', attesa: 1500, ...come('elena.gualtieri'), azione: senzaGuida },
  { nome: 'comunicazioni-altra-azienda', percorso: '/employee/communications.php?id=4', attesa: 1200, ...come('giulia.ferraresi'), azione: senzaGuida },
  { nome: 'profilo', percorso: '/employee/profile.php', attesa: 1200, ...come('giulia.ferraresi'), azione: senzaGuida, paginaIntera: true },
  { nome: 'profilo-salvato', percorso: '/employee/profile.php?message=updated', attesa: 1500, ...come('marco.bellandi'), azione: senzaGuida },
  { nome: 'profilo-disponibilita', percorso: '/employee/profile.php', attesa: 800, ...come('elena.gualtieri'),
    azione: async (page) => { await senzaGuida(page); await page.click('.cora-profile-card .availability-pill'); await page.waitForTimeout(400); } },
  { nome: 'password-obbligatoria', percorso: '/employee/change-password.php', attesa: 900, ...come('davide.rinaldini') },
  { nome: 'password-obbligatoria-errore', percorso: '/employee/change-password.php', attesa: 900, ...come('davide.rinaldini'),
    azione: async (page) => { await page.fill('#current_password', 'Sbagliata!2026'); await page.fill('#new_password', 'corta'); await page.fill('#confirm_password', 'corta'); await page.click('button[type=submit]'); await page.waitForSelector('.alert-error'); } },
  { nome: 'password-volontaria', percorso: '/employee/change-password.php', attesa: 1200, ...come('giulia.ferraresi'), azione: senzaGuida },
  { nome: 'disiscrizione-non-valida', percorso: '/employee/unsubscribe.php?token=zz', attesa: 600 },
  { nome: 'calendario-settimana', percorso: '/employee/calendar.php?view=week&d=2026-10-28', attesa: 1500, ...come('marco.bellandi'), azione: senzaGuida, misure: [[1512, 900]] },
  { nome: 'calendario-giorno', percorso: '/employee/calendar.php?view=day&d=2026-10-28', attesa: 1500, ...come('giulia.ferraresi'), azione: senzaGuida },
  { nome: 'calendario-festivo', percorso: '/employee/calendar.php?view=week&d=2026-11-01', attesa: 1500, ...come('giulia.ferraresi'), azione: senzaGuida, misure: [[1512, 900]] },
  { nome: 'calendario-nuovo', percorso: '/employee/calendar.php?view=week&d=2026-10-28', attesa: 900, ...come('giulia.ferraresi'), misure: [[1512, 900]],
    azione: async (page) => { await senzaGuida(page); await page.click('.cal-add-btn'); await page.waitForSelector('#calModal.show'); await page.waitForTimeout(400); } },
  { nome: 'calendario-dettaglio', percorso: '/employee/calendar.php?view=week&d=2026-10-28', attesa: 900, ...come('marco.bellandi'), misure: [[1512, 900]],
    azione: async (page) => { await senzaGuida(page); await page.click('.cal-evt'); await page.waitForSelector('#calModal.show'); await page.waitForTimeout(500); } },
  { nome: 'chat-elenco', percorso: '/employee/chat.php', attesa: 2000, ...come('giulia.ferraresi'), azione: senzaGuida },
  { nome: 'chat-aperta', percorso: '/employee/chat.php?conv=1', attesa: 3500, ...come('giulia.ferraresi'), azione: senzaGuida },
  { nome: 'chat-nuova', percorso: '/employee/chat.php', attesa: 900, ...come('marco.bellandi'), misure: [[1512, 900]],
    azione: async (page) => { await senzaGuida(page); await page.click('[data-cc-new]'); await page.waitForTimeout(600); } },
  { nome: 'academy-elenco', percorso: '/employee/academy.php', attesa: 1200, ...come('marco.bellandi'), azione: senzaGuida },
  { nome: 'academy-da-iniziare', percorso: '/employee/academy.php?course=1', attesa: 1200, ...come('giulia.ferraresi'), azione: senzaGuida },
  { nome: 'academy-in-corso', percorso: '/employee/academy.php?course=1', attesa: 1200, ...come('marco.bellandi'), azione: senzaGuida, paginaIntera: true },
  { nome: 'academy-completato', percorso: '/employee/academy.php?course=1', attesa: 1200, ...come('elena.gualtieri'), azione: senzaGuida },
  { nome: 'academy-errore', percorso: '/employee/academy.php?course=1&err=Apri%20tutti%20i%20materiali%20del%20corso%20prima%20di%20completarlo',
    percorsoNode: '/employee/academy.php?course=1&err=materiali', attesa: 1200, ...come('marco.bellandi'), azione: senzaGuida },
  { nome: 'contratto-da-firmare', percorso: '/employee/contract-sign.php?id=1', attesa: 2500, ...come('laura.zanichelli') },
  { nome: 'admin-dashboard', percorso: '/admin/', attesa: 1500, ...come('hr.alfa'), paginaIntera: true },
  { nome: 'admin-dashboard-settimana', percorso: '/admin/index.php?w=2026-10-26', attesa: 1500, ...come('hr.alfa') },
  { nome: 'admin-ferie', percorso: '/admin/leave-requests.php', attesa: 1200, ...come('hr.alfa'), paginaIntera: true },
  { nome: 'admin-ferie-tutte', percorso: '/admin/leave-requests.php?status=all', attesa: 1200, ...come('hr.alfa'), paginaIntera: true },
  { nome: 'admin-ferie-messaggio', percorso: '/admin/leave-requests.php?status=approved&message=updated', attesa: 1200, ...come('hr.alfa') },
  { nome: 'admin-ferie-nuova', percorso: '/admin/leave-requests.php', attesa: 900, ...come('hr.alfa'), misure: [[1512, 900]],
    azione: async (page) => { await page.click('.lp-hero-btn'); await page.click('#acl_all'); await page.waitForTimeout(400); } },
  { nome: 'admin-ferie-dettaglio', percorso: '/admin/leave-requests.php', attesa: 900, ...come('hr.alfa'), misure: [[1512, 900]],
    azione: async (page) => { await page.click('tr:has(.lp-btn-approve) .js-detail >> nth=0'); await page.waitForSelector('#detailModal.show'); await page.click('#dmBtnReject'); await page.waitForTimeout(400); } },
  { nome: 'admin-ferie-malattia', percorso: '/admin/leave-requests.php', attesa: 900, ...come('hr.alfa'), misure: [[1512, 900]],
    azione: async (page) => { await page.click('.js-sick >> nth=0'); await page.waitForSelector('#sickModal.show'); await page.waitForTimeout(400); } },
  { nome: 'admin-ferie-modifica', percorso: '/admin/leave-requests.php?status=all', attesa: 900, ...come('hr.alfa'), misure: [[1512, 900]],
    azione: async (page) => { await page.click('.js-edit >> nth=1'); await page.waitForSelector('#editModal.show'); await page.waitForTimeout(400); } },
  { nome: 'admin-orario', percorso: '/admin/work-schedule.php', attesa: 1000, ...come('hr.alfa'), paginaIntera: true },
  { nome: 'admin-orario-errore', percorso: '/admin/work-schedule.php', attesa: 800, ...come('hr.alfa'), misure: [[1512, 900]],
    azione: async (page) => { await page.evaluate(() => document.querySelectorAll('input[name="working_days[]"]').forEach((c) => { c.checked = false; })); await page.click('.cfg-actions button[type=submit]'); await page.waitForSelector('.alert-error'); await page.waitForTimeout(400); } },
  { nome: 'admin-timbrature-nfc', percorso: '/admin/punch-settings.php?msg=enable_first', attesa: 1000, ...come('hr.alfa'), soglia: 0.004 },
  { nome: 'admin-buoni-pasto', percorso: '/admin/buoni-pasto.php', attesa: 1000, ...come('hr.alfa') },
  { nome: 'admin-porta', percorso: '/admin/door-settings.php', attesa: 1000, ...come('connecteed') },
  { nome: 'admin-porta-log', percorso: '/admin/door-log.php', attesa: 1000, ...come('connecteed') },
  { nome: 'admin-profilo', percorso: '/admin/profile.php?message=updated', attesa: 1000, ...come('hr.alfa') },
  { nome: 'admin-profilo-commercialista', percorso: '/admin/profile.php', attesa: 1000, ...come('studio.commercialista') },
  { nome: 'admin-password', percorso: '/admin/password-resets.php', attesa: 1000, ...come('hr.alfa'), misure: [[1512, 900], [390, 844]],
    azione: async (page) => { await page.selectOption('#manualUserType', 'employee'); await page.waitForTimeout(300); } },
  { nome: 'admin-reparti', percorso: '/admin/departments.php', attesa: 1000, ...come('hr.alfa') },
  { nome: 'admin-reparti-nuovo', percorso: '/admin/departments.php?action=new', attesa: 1000, ...come('hr.alfa') },
  { nome: 'admin-reparti-modifica', percorso: '/admin/departments.php?edit=1&message=updated', attesa: 1000, ...come('hr.alfa') },
  { nome: 'admin-reparto-dettaglio', percorso: '/admin/department-detail.php?id=1', attesa: 1000, ...come('hr.alfa'), paginaIntera: true },
  { nome: 'admin-aziende', percorso: '/admin/companies.php?msg=updated', attesa: 1000, ...come('connecteed') },
  { nome: 'admin-aziende-modifica', percorso: '/admin/companies.php', attesa: 900, ...come('hr.alfa'), misure: [[1512, 900]],
    azione: async (page) => { await page.click('tr[data-row-id="1"] td:last-child button.btn-secondary'); await page.waitForTimeout(300); } },
  { nome: 'admin-aziende-elimina-errore', percorso: '/admin/companies.php', attesa: 900, ...come('connecteed'), misure: [[1512, 900]],
    azione: async (page) => {
      page.once('dialog', (d) => d.accept('nome sbagliato'));
      await page.evaluate(() => { document.getElementById('deleteId').value = '2'; document.getElementById('deleteConfirmName').value = 'nome sbagliato'; document.getElementById('deleteForm').submit(); });
      await page.waitForSelector('text=Conferma fallita'); await page.waitForTimeout(400);
    } },
  { nome: 'admin-comunicazioni', percorso: '/admin/communications.php', attesa: 1000, ...come('hr.alfa') },
  { nome: 'admin-comunicazioni-nuova', percorso: '/admin/communications.php?action=new', attesa: 1000, ...come('hr.alfa'), paginaIntera: true },
  { nome: 'admin-comunicazioni-modifica', percorso: '/admin/communications.php?action=edit&id=1&message=created', attesa: 1000, ...come('hr.alfa'), paginaIntera: true },
  { nome: 'admin-comunicazioni-dettaglio', percorso: '/admin/communications.php?action=view&id=1', attesa: 1000, ...come('hr.alfa'), paginaIntera: true },
  { nome: 'admin-documenti-aziendali', percorso: '/admin/documenti-aziendali.php', attesa: 1000, ...come('hr.alfa'), paginaIntera: true },
  { nome: 'admin-documenti-aziendali-errore', percorso: '/admin/documenti-aziendali.php?err=Seleziona+un+file+da+caricare.',
    percorsoNode: '/admin/documenti-aziendali.php?err=no_file', attesa: 1000, ...come('hr.alfa') },
  { nome: 'admin-documenti-aziendali-invio', percorso: '/admin/documenti-aziendali.php', attesa: 800, ...come('hr.alfa'), misure: [[1512, 900]],
    azione: async (page) => {
      page.on('dialog', (d) => d.accept());
      await page.fill('input[name=name]', 'Codice etico');
      await page.setInputFiles('input[name=document]', { name: 'codice-etico.pdf', mimeType: 'application/pdf', buffer: Buffer.from('%PDF-1.4\n%%EOF\n') });
      await page.click('#docUploadBtn');
      await page.waitForSelector('text=Notifiche inviate a tutti i dipendenti.', { timeout: 20000 });
      await page.waitForTimeout(400);
    } },
  { nome: 'admin-visibilita-campi', percorso: '/admin/field-visibility.php', attesa: 1000, ...come('hr.alfa'), paginaIntera: true },
  { nome: 'admin-visibilita-salvata', percorso: '/admin/field-visibility.php', attesa: 800, ...come('hr.alfa'), misure: [[1512, 900]],
    azione: async (page) => { await page.click('form.card button[type=submit]'); await page.waitForSelector('text=Configurazione salvata'); await page.waitForTimeout(400); } },
  { nome: 'admin-presenze-export', percorso: '/admin/presenze-export.php?month=11&year=2026', attesa: 1000, ...come('hr.alfa') },
  { nome: 'admin-timbrature', percorso: '/admin/attendance.php?d=2026-10-22', attesa: 1000, ...come('hr.alfa'), paginaIntera: true },
  { nome: 'admin-timbrature-modifica', percorso: '/admin/attendance.php?d=2026-10-22', attesa: 800, ...come('hr.alfa'), misure: [[1512, 900]],
    azione: async (page) => { await page.click('.att-punch >> nth=1'); await page.waitForSelector('#pmModal.show'); await page.waitForTimeout(400); } },
  { nome: 'admin-commercialisti', percorso: '/admin/accountant.php', attesa: 1000, ...come('hr.alfa') },
  { nome: 'admin-commercialisti-nuovo', percorso: '/admin/accountant.php?action=new', attesa: 1000, ...come('hr.alfa') },
  { nome: 'admin-consulenti-modifica', percorso: '/admin/consulente-lavoro.php?action=edit&id=6', attesa: 1000, ...come('hr.alfa') },
  { nome: 'admin-consulenti-collega', percorso: '/admin/consulente-lavoro.php?action=link', attesa: 1000, ...come('hr.beta') },
  { nome: 'admin-formatori', percorso: '/admin/formatori.php?message=created&email=fail', attesa: 1000, ...come('hr.alfa') },
  { nome: 'admin-calendario', percorso: '/admin/calendar.php?view=week&d=2026-10-28', attesa: 1200, ...come('hr.alfa') },
  { nome: 'admin-calendario-giorno', percorso: '/admin/calendar.php?view=day&d=2026-10-28', attesa: 1200, ...come('hr.alfa') },
  { nome: 'admin-calendario-nuovo', percorso: '/admin/calendar.php?view=week&d=2026-10-28', attesa: 900, ...come('hr.alfa'), misure: [[1512, 900]],
    azione: async (page) => { await page.click('.cal-add-btn'); await page.waitForSelector('#calModal.show'); await page.waitForTimeout(400); } },
  { nome: 'commercialista-calendario', percorso: '/accountant/calendar.php?view=week&d=2026-10-28', attesa: 1200, ...come('studio.commercialista') },
  { nome: 'admin-chat', percorso: '/admin/chat.php', attesa: 1500, ...come('hr.alfa') },
  { nome: 'admin-chat-aperta', percorso: '/admin/chat.php?conv=1', attesa: 1500, ...come('hr.alfa') },
  { nome: 'consulente-chat', percorso: '/consulente-lavoro/chat.php', attesa: 1500, ...come('studio.consulente') },
  { nome: 'admin-dipendenti', percorso: '/admin/employees.php', attesa: 1200, ...come('hr.alfa'), paginaIntera: true },
  { nome: 'admin-dipendenti-ricerca', percorso: '/admin/employees.php?search=bell&message=updated', attesa: 1200, ...come('hr.alfa') },
  { nome: 'admin-dipendenti-nuovo', percorso: '/admin/employees.php?action=new', attesa: 1200, ...come('hr.alfa'), paginaIntera: true },
  { nome: 'admin-dipendenti-modifica', percorso: '/admin/employees.php?action=edit&id=1', attesa: 1200, ...come('hr.alfa'), paginaIntera: true },
  { nome: 'admin-dipendenti-scheda', percorso: '/admin/employees.php?action=view&id=1', attesa: 1500, ...come('hr.alfa'), paginaIntera: true },
  { nome: 'admin-dipendenti-scheda-prova', percorso: '/admin/employees.php?action=view&id=3&probc=found&d=2026-12-01', attesa: 1500, ...come('hr.alfa') },
  { nome: 'admin-assunzioni', percorso: '/admin/hire-requests.php', attesa: 1200, ...come('hr.beta'), paginaIntera: true },
  { nome: 'admin-assunzioni-dettaglio', percorso: '/admin/hire-requests.php?id=1&sent=1&notified=1', attesa: 1200, ...come('hr.beta'), paginaIntera: true },
  { nome: 'admin-assunzioni-nuova', percorso: '/admin/hire-requests.php?action=new', attesa: 1200, ...come('hr.alfa'), paginaIntera: true },
  { nome: 'admin-assunzioni-diretta', percorso: '/admin/hire-requests.php?action=direct', attesa: 1200, ...come('hr.alfa'), paginaIntera: true },
  { nome: 'admin-academy', percorso: '/admin/academy.php', attesa: 1200, ...come('hr.alfa'), paginaIntera: true },
  { nome: 'admin-academy-persone', percorso: '/admin/academy.php?course=1&tab=people', attesa: 1200, ...come('hr.alfa'), paginaIntera: true },
  { nome: 'admin-academy-info', percorso: '/admin/academy.php?course=1&tab=info&ok=Corso+aggiornato', attesa: 1200, ...come('hr.alfa'), paginaIntera: true },
  { nome: 'admin-academy-materiali', percorso: '/admin/academy.php?course=1&tab=materials', attesa: 1200, ...come('hr.alfa'), paginaIntera: true },
  { nome: 'admin-academy-registro', percorso: '/admin/academy.php?course=1&tab=log', attesa: 1200, ...come('hr.alfa'), paginaIntera: true },
  { nome: 'admin-academy-modelli', percorso: '/admin/academy-templates.php', attesa: 1200, ...come('hr.alfa'), paginaIntera: true },
  { nome: 'admin-academy-modello-nuovo', percorso: '/admin/academy-templates.php?new=1', attesa: 2000, ...come('hr.alfa') },
  { nome: 'consulente-academy', percorso: '/consulente-lavoro/academy.php', attesa: 1200, ...come('studio.consulente'), paginaIntera: true },
  { nome: 'formatore-academy-persone', percorso: '/formatore/academy.php?course=1&tab=people', attesa: 1200, ...come('formatore.alfa'), paginaIntera: true },
  // Solo fino alla heatmap: sotto, il PHP va in errore (vedi genera-pagine.py, REPARTO_DASHBOARD).
  { nome: 'reparto-dashboard', percorso: '/admin-reparto/', attesa: 1500, ...come('resp.commerciale'), misure: [[1512, 760]] },
  { nome: 'reparto-dashboard-tutti', percorso: '/admin-reparto/index.php?scope=all', attesa: 1500, ...come('resp.commerciale'), misure: [[1512, 760]] },
  { nome: 'reparto-dipendenti', percorso: '/admin-reparto/employees.php', attesa: 1200, ...come('resp.commerciale'), paginaIntera: true },
  { nome: 'reparto-dipendenti-ricerca', percorso: '/admin-reparto/employees.php?search=fer', attesa: 1200, ...come('resp.commerciale') },
  { nome: 'reparto-ferie', percorso: '/admin-reparto/leave-requests.php', attesa: 1200, ...come('resp.commerciale'), paginaIntera: true },
  { nome: 'reparto-ferie-tutte', percorso: '/admin-reparto/leave-requests.php?status=all&message=approved', attesa: 1200, ...come('resp.commerciale'), paginaIntera: true },
  { nome: 'reparto-comunicazioni', percorso: '/admin-reparto/communications.php', attesa: 1200, ...come('resp.commerciale'), paginaIntera: true },
  { nome: 'reparto-password', percorso: '/admin-reparto/password-resets.php', attesa: 1200, ...come('resp.commerciale'), paginaIntera: true },
  { nome: 'reparto-documenti', percorso: '/admin-reparto/employee-documents.php?employee_id=1&ed_status=updated', attesa: 1200, ...come('resp.commerciale'), paginaIntera: true },
  { nome: 'reparto-academy', percorso: '/admin-reparto/academy.php', attesa: 1200, ...come('resp.commerciale'), paginaIntera: true },
  { nome: 'reparto-academy-persone', percorso: '/admin-reparto/academy.php?course=1', attesa: 1200, ...come('resp.commerciale'), paginaIntera: true },
  { nome: 'reparto-academy-registro', percorso: '/admin-reparto/academy.php?course=1&tab=log', attesa: 1200, ...come('resp.commerciale'), paginaIntera: true },
  { nome: 'reparto-chat', percorso: '/admin-reparto/chat.php', attesa: 2000, ...come('resp.commerciale') },
  { nome: 'reparto-calendario', percorso: '/admin-reparto/calendar.php', attesa: 2000, ...come('resp.commerciale') },
  { nome: 'commercialista-dashboard', percorso: '/accountant/', attesa: 1500, ...come('studio.commercialista'), paginaIntera: true },
  { nome: 'commercialista-dipendenti', percorso: '/accountant/employees.php', attesa: 1200, ...come('studio.commercialista'), paginaIntera: true },
  { nome: 'commercialista-dipendenti-ricerca', percorso: '/accountant/employees.php?search=bel', attesa: 1200, ...come('studio.commercialista') },
  { nome: 'commercialista-documenti-dipendente', percorso: '/accountant/employee-documents.php?employee_id=1', attesa: 1200, ...come('studio.commercialista'), paginaIntera: true },
  { nome: 'commercialista-documenti', percorso: '/accountant/documents.php', attesa: 1200, ...come('studio.commercialista'), paginaIntera: true },
  { nome: 'commercialista-documenti-filtro', percorso: '/accountant/documents.php?type=payslip&message=uploaded', attesa: 1200, ...come('studio.commercialista') },
  { nome: 'commercialista-ferie', percorso: '/accountant/leave-requests.php', attesa: 1200, ...come('studio.commercialista'), paginaIntera: true },
  { nome: 'commercialista-presenze', percorso: '/accountant/presenze-export.php', attesa: 1200, ...come('studio.commercialista') },
  { nome: 'commercialista-profilo', percorso: '/accountant/profile.php?message=updated', attesa: 1200, ...come('studio.commercialista'), paginaIntera: true },
  { nome: 'consulente-dashboard', percorso: '/consulente-lavoro/', attesa: 1500, ...come('studio.consulente'), paginaIntera: true },
  { nome: 'consulente-dipendenti', percorso: '/consulente-lavoro/employees.php', attesa: 1200, ...come('studio.consulente'), paginaIntera: true },
  { nome: 'consulente-dipendenti-ricerca', percorso: '/consulente-lavoro/employees.php?search=bel', attesa: 1200, ...come('studio.consulente') },
  { nome: 'consulente-documenti-dipendente', percorso: '/consulente-lavoro/employee-documents.php?employee_id=1', attesa: 1200, ...come('studio.consulente'), paginaIntera: true },
  { nome: 'consulente-documenti', percorso: '/consulente-lavoro/documents.php', attesa: 1200, ...come('studio.consulente'), paginaIntera: true },
  { nome: 'consulente-documenti-filtro', percorso: '/consulente-lavoro/documents.php?type=payslip&message=uploaded', attesa: 1200, ...come('studio.consulente') },
  { nome: 'consulente-ferie', percorso: '/consulente-lavoro/leave-requests.php', attesa: 1200, ...come('studio.consulente'), paginaIntera: true },
  { nome: 'consulente-presenze', percorso: '/consulente-lavoro/presenze-export.php', attesa: 1200, ...come('studio.consulente') },
  { nome: 'consulente-profilo', percorso: '/consulente-lavoro/profile.php?message=updated', attesa: 1200, ...come('studio.consulente'), paginaIntera: true },
  { nome: 'consulente-assunzioni', percorso: '/consulente-lavoro/hire-requests.php', attesa: 1200, ...come('studio.consulente'), paginaIntera: true },
  { nome: 'superadmin-login', percorso: '/superadmin/login.php', attesa: 800 },
  { nome: 'superadmin-tenant', percorso: '/superadmin/index.php', attesa: 1200, ...comeSa(), paginaIntera: true },
  { nome: 'superadmin-clic', percorso: '/superadmin/clic.php?days=0', attesa: 1200, ...comeSa(), paginaIntera: true },
  { nome: 'admin-assunzioni-vuota', percorso: '/admin/hire-requests.php?deleted=1', attesa: 1200, ...come('hr.alfa') },
];
export { PW };
