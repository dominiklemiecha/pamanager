import { postgres, ripristina } from './ambiente';
import { Node } from './clienti';
import { php } from './php-cli';

/**
 * Cambio e recupero della password. Le email arrivano in Mailpit (sviluppo),
 * nessun invio reale.
 */
const PW = 'Prova!Cora2026';
const MAILPIT = 'http://localhost:8046/api/v1';

async function ultimaEmail(a: string): Promise<{ oggetto: string; html: string } | null> {
  const lista = await (await fetch(`${MAILPIT}/messages`)).json();
  const m = lista.messages.find((x: any) => x.To.some((t: any) => t.Address === a));
  if (!m) return null;
  const msg = await (await fetch(`${MAILPIT}/message/${m.ID}`)).json();
  return { oggetto: msg.Subject, html: msg.HTML };
}

beforeAll(async () => {
  ripristina();
  await fetch(`${MAILPIT}/messages`, { method: 'DELETE' });
});

describe('recupero password', () => {
  let token = '';

  it('stessa risposta per account esistente e inesistente; email solo al primo', async () => {
    const a = await new Node().post('/auth/recupero', { identificativo: 'giulia.ferraresi' });
    const b = await new Node().post('/auth/recupero', { identificativo: 'nessuno.esiste' });
    expect(a).toEqual(b);
    expect(a.json.message).toBe("Se l'account esiste, riceverai a breve un'email con il link per reimpostare la password.");
    const mail = await ultimaEmail('giulia.ferraresi@esempio.test');
    expect(mail?.oggetto).toBe('Recupero password Connecteed HR');
    // Link costruito da APP_URL, mai dall'header Host della richiesta.
    const link = /href="([^"]+reset-password\.php\?token=([a-f0-9]{64}))"/.exec(mail!.html)!;
    expect(link[1].startsWith('http://localhost:5180/auth/reset-password.php')).toBe(true);
    token = link[2];
  });

  it('nel database il token non e in chiaro', () => {
    expect(postgres(`SELECT COUNT(*) AS n FROM password_reset_tokens WHERE token = '${token}'`)[0].n).toBe('0');
    expect(postgres("SELECT status FROM password_reset_requests ORDER BY id DESC LIMIT 1")[0].status).toBe('sent');
  });

  it('password debole: stessi messaggi del PHP', async () => {
    const n = new Node();
    expect((await n.post('/auth/reset/verifica', { token })).json).toEqual({ valido: true, nome: 'Giulia', username: 'giulia.ferraresi' });
    const r = await n.post('/auth/reset', { token, password: 'corta', conferma: 'corta' });
    const attesi = php<{ errors: string[] }>("echo json_encode(Auth::validatePassword('corta'));").errors;
    expect(r.json.errori).toEqual(attesi);
  });

  it('reset valido: nuova password funziona, la vecchia no, il link non si riusa, sessioni chiuse', async () => {
    const aperta = new Node();
    await aperta.login('giulia.ferraresi', PW);
    const r = await new Node().post('/auth/reset', { token, password: 'Nuova!Giulia2026', conferma: 'Nuova!Giulia2026' });
    expect(r.json).toEqual({ ok: true, message: 'Password aggiornata con successo' });
    expect((await new Node().login('giulia.ferraresi', PW)).esito).toBe('errore');
    expect((await new Node().login('giulia.ferraresi', 'Nuova!Giulia2026')).esito).toBe('ok');
    expect((await new Node().post('/auth/reset', { token, password: 'Altra!Giulia2026', conferma: 'Altra!Giulia2026' })).json.message).toBe('Link non valido o scaduto');
    expect((await aperta.get('/auth/sessione')).json).toEqual({ autenticato: false });
  });

  it('token inventato o malformato: non valido', async () => {
    expect((await new Node().post('/auth/reset/verifica', { token: 'a'.repeat(64) })).json).toEqual({ valido: false });
    expect((await new Node().post('/auth/reset/verifica', { token: "' OR 1=1 --" })).json).toEqual({ valido: false });
  });
});

describe('cambio password', () => {
  beforeAll(() => ripristina());

  it('messaggi di errore come il PHP', async () => {
    const n = new Node();
    await n.login('marco.bellandi', PW);
    expect((await n.post('/auth/password', { attuale: PW, nuova: 'Nuova!Marco2026', conferma: 'Diversa!Marco2026' })).json.message).toBe('La nuova password e la conferma non coincidono');
    expect((await n.post('/auth/password', { attuale: 'Sbagliata!2026', nuova: 'Nuova!Marco2026', conferma: 'Nuova!Marco2026' })).json.message).toBe('Password attuale non corretta');
    expect((await n.post('/auth/password', { attuale: PW, nuova: PW, conferma: PW })).json.message).toBe('La nuova password deve essere diversa dalla precedente');
  });

  it('cambio riuscito: questa sessione resta, le altre si chiudono', async () => {
    const a = new Node();
    await a.login('marco.bellandi', PW);
    const b = new Node();
    await b.login('marco.bellandi', PW);
    expect((await a.post('/auth/password', { attuale: PW, nuova: 'Nuova!Marco2026', conferma: 'Nuova!Marco2026' })).json).toEqual({ ok: true });
    expect((await a.get('/auth/sessione')).json.autenticato).toBe(true);
    expect((await b.get('/auth/sessione')).json).toEqual({ autenticato: false });
  });

  it('password temporanea: tutto bloccato finche non si cambia (anche lo staff)', async () => {
    postgres("UPDATE users SET must_change_password = true WHERE username = 'hr.beta'");
    const n = new Node();
    await n.login('hr.beta', PW);
    expect((await n.get('/auth/sessione')).json.deveCambiarePassword).toBe(true);
    const bloccata = await n.get('/auth/aziende');
    expect(bloccata.status).toBe(403);
    expect(bloccata.json.must_change_password).toBe(true);
    expect((await n.post('/auth/password', { attuale: PW, nuova: 'Nuova!Beta20266', conferma: 'Nuova!Beta20266' })).json).toEqual({ ok: true });
    expect((await n.get('/auth/aziende')).status).toBe(200);
    expect(postgres("SELECT must_change_password FROM users WHERE username = 'hr.beta'")[0].must_change_password).toBe('f');
  });
});
