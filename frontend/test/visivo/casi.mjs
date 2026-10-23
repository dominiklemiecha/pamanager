/** Casi del confronto visivo PHP / nuovo frontend (dati sintetici della baseline). */
const PW = 'Prova!Cora2026';

async function scrivi(page, sel, testo) { await page.fill(sel, testo); }

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
];
export { PW };
