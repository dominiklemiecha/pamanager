/** Casi del confronto visivo PHP / nuovo frontend (dati sintetici della baseline). */
const PW = 'Prova!Cora2026';

async function scrivi(page, sel, testo) { await page.fill(sel, testo); }

/** Entra con il modulo di login (uguale sui due sistemi). */
async function entra(page, base, utente) {
  await page.goto(base + '/auth/login.php');
  await page.fill('#username', utente);
  await page.fill('#password', PW);
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
];
export { PW };
