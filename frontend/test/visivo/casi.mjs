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
];
export { PW };
