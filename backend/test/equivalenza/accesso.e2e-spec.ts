import { mariadb, postgres, ripristina } from './ambiente';
import { Node, Php } from './clienti';

/**
 * Accesso: stesso esito dal vecchio PHP e dal nuovo backend a parita' di dati.
 * Le differenze volute (correzioni di sicurezza) sono scritte nei test stessi.
 */
const PW = 'Prova!Cora2026';

beforeAll(() => ripristina());

async function entrambi(username: string, password: string, ritorno?: string) {
  const [php, node] = await Promise.all([new Php().login(username, password, ritorno), new Node().login(username, password, ritorno)]);
  return { php, node };
}

describe('login: stessi esiti', () => {
  const casi: Array<[string, string, string]> = [
    ['dipendente per username', 'giulia.ferraresi', PW],
    ['dipendente con maiuscole diverse', 'Giulia.Ferraresi', PW],
    ['dipendente per email', 'marco.bellandi@esempio.test', PW],
    ['admin di azienda', 'hr.alfa', PW],
    ['consulente del lavoro', 'studio.consulente', PW],
    ['formatore', 'formatore.alfa', PW],
    ['admin globale', 'connecteed', PW],
    ['password sbagliata', 'hr.beta', 'Sbagliata!2026'],
    ['utente inesistente', 'nessuno.qui', PW],
    ['dipendente disattivato', 'chiara.lombardelli', PW],
  ];
  it.each(casi)('%s', async (_n, username, password) => {
    const { php, node } = await entrambi(username, password);
    expect(node).toEqual(php);
  });

  it.each([['responsabile di reparto', 'resp.commerciale'], ['commercialista', 'studio.commercialista']])(
    'correzione: ruolo tolto nel nuovo stack (%s), account disattivato', async (_n, username) => {
      const { php, node } = await entrambi(username, PW);
      expect(php.esito).toBe('ok');
      expect(node.esito).not.toBe('ok');
    });

  it('dipendente per codice fiscale scritto in minuscolo', async () => {
    const [cf] = mariadb("SELECT fiscal_code FROM employees WHERE username = 'sara.montanari'");
    const { php, node } = await entrambi(cf.fiscal_code.toLowerCase(), PW);
    expect(php.esito).toBe('ok');
    expect(node).toEqual(php);
  });

  it('ritorno a una pagina interna dopo il login', async () => {
    const { php, node } = await entrambi('giulia.ferraresi', PW, '/employee/documents.php');
    expect(node).toEqual(php);
  });

  it('CORREZIONE: un ritorno verso un altro sito non viene seguito', async () => {
    const { php, node } = await entrambi('giulia.ferraresi', PW, '//sito-esterno.example/');
    // Il PHP mandava davvero sull'altro dominio (redirect aperto).
    expect(php.destinazione).toBe('//sito-esterno.example/');
    expect(node).toEqual({ esito: 'ok', destinazione: '/employee/' });
  });
});

describe('blocco dopo 5 tentativi sbagliati', () => {
  beforeAll(() => ripristina());

  it('stessi contatori, stesso blocco, stesso messaggio', async () => {
    for (let i = 0; i < 5; i++) {
      const { php, node } = await entrambi('davide.rinaldini', 'Sbagliata!2026');
      expect(node).toEqual(php);
    }
    const m = mariadb("SELECT failed_attempts, locked_until IS NOT NULL AS bloccato FROM employees WHERE username = 'davide.rinaldini'")[0];
    const p = postgres("SELECT failed_attempts, (locked_until IS NOT NULL)::int AS bloccato FROM employees WHERE username = 'davide.rinaldini'")[0];
    expect(p).toEqual(m);
    expect(m).toEqual({ failed_attempts: '5', bloccato: '1' });

    // Anche con la password giusta resta fuori per 15 minuti.
    const { php, node } = await entrambi('davide.rinaldini', PW);
    expect(php).toEqual({ esito: 'errore', messaggio: 'Account bloccato. Riprova tra 15 minuti.' });
    expect(node).toEqual(php);
  });

  it('il registro ha gli stessi eventi', () => {
    const q = "SELECT action, severity, COUNT(*) AS n FROM audit_log WHERE action IN ('login_failed','suspicious_activity') GROUP BY action, severity ORDER BY action, severity";
    expect(postgres(q)).toEqual(mariadb(q));
  });
});

describe('sessione', () => {
  beforeAll(() => ripristina());

  it('pagina protetta senza login: il PHP rimanda al login, il backend risponde 401', async () => {
    const php = await new Php().pagina('/employee/');
    const node = await new Node().get('/auth/sessione');
    expect(php.versoLogin).toBe(true);
    expect(node.json).toEqual({ autenticato: false });
  });

  it('stesso cookie da un altro browser: sessione chiusa e allarme', async () => {
    const n = new Node('203.0.113.200', 'Browser-A');
    await n.login('giulia.ferraresi', PW);
    expect((await n.get('/auth/sessione')).json.autenticato).toBe(true);
    // Il cookie copiato in un altro browser: User-Agent diverso.
    const ladro = new Node('198.51.100.7', 'Browser-B');
    (ladro.b as any).cookie = new Map((n.b as any).cookie);
    expect((await ladro.get('/auth/sessione')).json).toEqual({ autenticato: false });
    // E la sessione originale non vale piu'.
    expect((await n.get('/auth/sessione')).json).toEqual({ autenticato: false });
    expect(postgres("SELECT COUNT(*) AS n FROM security_alerts WHERE alert_type = 'session_hijack_attempt'")[0].n).toBe('1');
  });

  it('CORREZIONE: senza token CSRF il login non parte', async () => {
    const n = new Node();
    const r = await n.b.chiama('/api/v1/auth/login', { metodo: 'POST', corpo: JSON.stringify({ username: 'hr.alfa', password: PW }), tipo: 'application/json' });
    expect(r.status).toBe(403);
  });
});
