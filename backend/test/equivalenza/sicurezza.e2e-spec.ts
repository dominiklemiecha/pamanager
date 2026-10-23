import { postgres, ripristina } from './ambiente';
import { Node } from './clienti';
import { codiceTotp } from '../../src/common/sicurezza/totp';

/**
 * Audit di sicurezza del nuovo backend: autenticazione, sessioni, CSRF,
 * ruoli e confini tra aziende. Ogni test controlla anche lo stato nel
 * database, non solo il codice HTTP. Solo dati sintetici.
 *
 * Aziende: 1 = Alfa, 2 = Beta. Staff: hr.alfa (admin Alfa), hr.beta (admin Beta),
 * resp.commerciale (reparto Alfa), studio.commercialista e studio.consulente
 * (Alfa + Beta), formatore.alfa, connecteed (admin globale).
 */
const PW = 'Prova!Cora2026';

async function entra(username: string, ip?: string, ua?: string): Promise<Node> {
  const n = new Node(ip, ua);
  const r = await n.login(username, PW);
  if (r.esito !== 'ok') throw new Error(`login ${username}: ${r.messaggio}`);
  return n;
}

beforeAll(() => ripristina());

describe('SEC-AZ: cambio azienda (confine tra tenant)', () => {
  it('admin di Alfa non puo passare a Beta', async () => {
    const n = await entra('hr.alfa');
    const r = await n.post('/auth/azienda', { azienda: 2 });
    expect(r.status).toBe(403);
    expect((await n.get('/auth/aziende')).json).toMatchObject({ corrente: 1, accessibili: [1] });
    expect(postgres("SELECT DISTINCT selected_company_id FROM auth_sessions s JOIN users u ON u.id = s.subject_id AND s.subject_type='user' WHERE u.username='hr.alfa'")).toEqual([{ selected_company_id: '1' }]);
  });

  it('commercialista collegato ad Alfa e Beta puo passare a Beta, non a un id inesistente', async () => {
    const n = await entra('studio.commercialista');
    expect((await n.post('/auth/azienda', { azienda: 2 })).status).toBe(200);
    expect((await n.get('/auth/aziende')).json.corrente).toBe(2);
    expect((await n.post('/auth/azienda', { azienda: 999 })).status).toBe(403);
    expect((await n.get('/auth/aziende')).json.corrente).toBe(2);
  });

  it('responsabile di reparto e formatore non cambiano azienda', async () => {
    for (const u of ['resp.commerciale', 'formatore.alfa']) {
      const n = await entra(u);
      expect((await n.post('/auth/azienda', { azienda: 2 })).status).toBe(403);
    }
  });

  it('dipendente: nessun accesso alle API dello staff', async () => {
    const n = await entra('giulia.ferraresi');
    expect((await n.get('/auth/aziende')).status).toBe(403);
    expect((await n.post('/auth/azienda', { azienda: 2 })).status).toBe(403);
    // Il tentativo resta nel registro come accesso non autorizzato.
    expect(Number(postgres("SELECT COUNT(*) AS n FROM audit_log WHERE action='unauthorized_access' AND user_type='employee'")[0].n)).toBeGreaterThanOrEqual(2);
  });

  it('admin globale vede tutte le aziende attive', async () => {
    const n = await entra('connecteed');
    expect((await n.get('/auth/aziende')).json.accessibili).toEqual([1, 2]);
    expect((await n.post('/auth/azienda', { azienda: 2 })).status).toBe(200);
  });

  it('identificativo non numerico rifiutato dalla validazione', async () => {
    const n = await entra('studio.consulente');
    expect((await n.post('/auth/azienda', { azienda: '2 OR 1=1' })).status).toBe(400);
  });

  it('azienda disattivata: non selezionabile e scompare dalle accessibili', async () => {
    postgres('UPDATE companies SET is_active = false WHERE id = 2');
    try {
      const n = await entra('studio.commercialista');
      expect((await n.post('/auth/azienda', { azienda: 2 })).status).toBe(403);
      expect((await n.get('/auth/aziende')).json.accessibili).toEqual([1]);
    } finally {
      postgres('UPDATE companies SET is_active = true WHERE id = 2');
    }
  });
});

describe('SEC-SESS: sessioni', () => {
  it('cookie di sessione httpOnly, SameSite=Lax; CSRF leggibile, SameSite=Strict', async () => {
    const n = new Node();
    await n.get('/auth/sessione');
    const r = await fetch('http://localhost:3100/api/v1/auth/login', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': n.b.valoreCookie('cora_csrf')!, Cookie: `cora_csrf=${n.b.valoreCookie('cora_csrf')}`, 'X-Forwarded-For': '203.0.113.240', 'X-Forwarded-Proto': 'https' },
      body: JSON.stringify({ username: 'hr.alfa', password: PW }),
    });
    const c = r.headers.getSetCookie();
    const sess = c.find((x) => x.startsWith('cora_sessione='))!;
    const csrf = c.find((x) => x.startsWith('cora_csrf='))!;
    expect(sess).toMatch(/HttpOnly/);
    expect(sess).toMatch(/SameSite=Lax/);
    expect(sess).toMatch(/Secure/);
    expect(sess).toMatch(/Max-Age=1800/);
    expect(csrf).not.toMatch(/HttpOnly/);
    expect(csrf).toMatch(/SameSite=Strict/);
    // Nel database c'e' solo l'impronta, mai il codice del cookie.
    const token = decodeURIComponent(sess.split(';')[0].split('=')[1]);
    expect(postgres(`SELECT COUNT(*) AS n FROM auth_sessions WHERE token_hash = '${token}'`)[0].n).toBe('0');
  });

  it('session fixation: un cookie scelto da altri prima del login non viene adottato', async () => {
    const n = new Node();
    (n.b as any).cookie.set('cora_sessione', 'scelto-dall-attaccante');
    await n.login('hr.alfa', PW);
    expect(n.b.valoreCookie('cora_sessione')).not.toBe('scelto-dall-attaccante');
  });

  it('dopo il logout il vecchio cookie non vale piu', async () => {
    const n = await entra('giulia.ferraresi');
    const vecchio = n.b.valoreCookie('cora_sessione')!;
    await n.post('/auth/logout', {});
    const altro = new Node();
    (altro.b as any).cookie = new Map([['cora_sessione', vecchio]]);
    expect((await altro.get('/auth/sessione')).json).toEqual({ autenticato: false });
  });

  it('inattivita oltre il limite del ruolo: fuori e registrato', async () => {
    const n = await entra('hr.beta');
    postgres("UPDATE auth_sessions SET last_activity_at = now() - interval '11 minutes' WHERE revoked_at IS NULL AND subject_type='user' AND subject_id = (SELECT id FROM users WHERE username='hr.beta')");
    expect((await n.get('/auth/aziende')).status).toBe(401);
    expect(Number(postgres("SELECT COUNT(*) AS n FROM audit_log WHERE action='session_expired' AND user_type='admin'")[0].n)).toBeGreaterThanOrEqual(1);
  });

  it('dipendente disattivato mentre e dentro: la richiesta successiva e respinta', async () => {
    const n = await entra('anna.castellari');
    expect((await n.get('/auth/sessione')).json.autenticato).toBe(true);
    postgres("UPDATE employees SET is_active = false WHERE username = 'anna.castellari'");
    try {
      expect((await n.get('/auth/sessione')).json).toEqual({ autenticato: false });
      expect(postgres("SELECT revoked_reason FROM auth_sessions WHERE subject_type='employee' AND subject_id=(SELECT id FROM employees WHERE username='anna.castellari')")).toEqual([{ revoked_reason: 'account_non_attivo' }]);
    } finally {
      postgres("UPDATE employees SET is_active = true WHERE username = 'anna.castellari'");
    }
  });

  it('ruolo cambiato nel database: la sessione col vecchio ruolo non vale piu', async () => {
    const n = await entra('formatore.alfa');
    postgres("UPDATE users SET role = 'accountant' WHERE username = 'formatore.alfa'");
    try {
      expect((await n.get('/auth/aziende')).status).toBe(401);
    } finally {
      postgres("UPDATE users SET role = 'formatore' WHERE username = 'formatore.alfa'");
    }
  });
});

describe('SEC-CSRF', () => {
  it('header diverso dal cookie: 403 e nessuna modifica', async () => {
    const n = await entra('studio.commercialista');
    const r = await n.b.chiama('/api/v1/auth/azienda', { metodo: 'POST', corpo: JSON.stringify({ azienda: 2 }), tipo: 'application/json', header: { 'X-CSRF-Token': 'falso' } });
    expect(r.status).toBe(403);
    expect((await n.get('/auth/aziende')).json.corrente).toBe(1);
  });

  it('logout senza token: 403, la sessione resta', async () => {
    const n = await entra('giulia.ferraresi');
    const r = await n.b.chiama('/api/v1/auth/logout', { metodo: 'POST', corpo: '{}', tipo: 'application/json' });
    expect(r.status).toBe(403);
    expect((await n.get('/auth/sessione')).json.autenticato).toBe(true);
  });
});

describe('SEC-BRUTE: tentativi ripetuti', () => {
  it('20 password sbagliate dallo stesso IP: anche la giusta viene fermata', async () => {
    const ip = '198.51.100.99';
    for (let i = 0; i < 20; i++) {
      // Account diversi: il blocco per account (5) non scatta, conta quello per IP.
      await new Node(ip).login(`inesistente${i}`, 'x');
    }
    expect(await new Node(ip).login('hr.alfa', PW)).toEqual({ esito: 'errore', messaggio: 'Troppi tentativi. Riprova tra qualche minuto.' });
    expect((await new Node('198.51.100.100').login('hr.alfa', PW)).esito).toBe('ok');
  });
});

describe('SEC-MFA: secondo fattore', () => {
  const SEGRETO = 'JBSWY3DPEHPK3PXP';
  beforeAll(() => postgres(`UPDATE users SET mfa_enabled = true, mfa_secret = '${SEGRETO}' WHERE username = 'hr.beta'`));
  afterAll(() => postgres("UPDATE users SET mfa_enabled = false, mfa_secret = NULL WHERE username = 'hr.beta'"));

  it('senza codice non si accede a nulla; codice sbagliato respinto; giusto ammesso', async () => {
    const n = new Node();
    expect((await n.post('/auth/login', { username: 'hr.beta', password: PW })).json).toEqual({ mfa: true });
    expect((await n.get('/auth/aziende')).status).toBe(401);
    expect((await n.get('/auth/sessione')).json).toEqual({ autenticato: false, mfa: true });
    expect((await n.post('/auth/mfa', { codice: '000000' })).status).toBe(401);
    const prima = n.b.valoreCookie('cora_sessione');
    const r = await n.post('/auth/mfa', { codice: codiceTotp(SEGRETO) });
    expect(r.json).toEqual({ destinazione: '/admin/' });
    // Sessione nuova dopo il secondo fattore.
    expect(n.b.valoreCookie('cora_sessione')).not.toBe(prima);
    expect((await n.get('/auth/aziende')).json.corrente).toBe(2);
  });

  it('dopo 5 codici sbagliati in 5 minuti: fermo', async () => {
    const n = new Node();
    await n.post('/auth/login', { username: 'hr.beta', password: PW });
    let ultimo = 0;
    for (let i = 0; i < 6; i++) ultimo = (await n.post('/auth/mfa', { codice: '111111' })).status;
    expect(ultimo).toBe(429);
  });
});

describe('SEC-INFO: niente informazioni in piu', () => {
  it('health non espone dati', async () => {
    expect((await new Node().get('/health')).json).toEqual({ ok: true });
  });

  it('JSON malformato: errore generico, nessuna traccia interna', async () => {
    const n = new Node();
    await n.get('/auth/sessione');
    const r = await n.b.chiama('/api/v1/auth/login', { metodo: 'POST', corpo: '{rotto', tipo: 'application/json', header: { 'X-CSRF-Token': n.b.valoreCookie('cora_csrf')! } });
    expect(r.status).toBe(400);
    expect(r.testo).not.toMatch(/at \w+ \(|node_modules|\.ts:/);
  });

  it('utente inesistente e password sbagliata danno lo stesso messaggio', async () => {
    expect(await new Node().login('nessuno.esiste', PW)).toEqual(await new Node().login('hr.alfa', 'Sbagliata!2026'));
  });

  it('rotta inesistente: 404 senza dettagli', async () => {
    const r = await new Node().get('/non-esiste');
    expect(r.status).toBe(404);
  });
});
