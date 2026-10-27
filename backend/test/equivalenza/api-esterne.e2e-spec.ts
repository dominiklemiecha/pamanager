import { mariadb, NODE_URL, PHP_URL, postgres, ripristina } from './ambiente';
import { Node, Php } from './clienti';

/**
 * Endpoint fuori dalle pagine: API a token del CRM (api/auth.php,
 * api/attendance.php), porta NFC (api/door.php), timbratura da carta
 * (punch.php), verifica attestato, download documenti, clic dell'HR.
 */
const PW = 'Prova!Cora2026';
async function phpEntra(u: string) { const p = new Php(); await p.login(u, PW); return p; }
async function nodeEntra(u: string) { const n = new Node(); await n.login(u, PW); return n; }

async function json(base: string, percorso: string, init: RequestInit = {}) {
  const r = await fetch(base + percorso, { redirect: 'manual', ...init, headers: { 'Content-Type': 'application/json', 'X-Forwarded-For': `198.51.100.${Math.floor(Math.random() * 200) + 1}`, ...(init.headers ?? {}) } });
  let corpo: any = null;
  try { corpo = await r.json(); } catch { corpo = null; }
  return { status: r.status, corpo };
}
const login = (base: string, username: string, password = PW, action = 'login') => json(base, '/api/auth.php', { method: 'POST', body: JSON.stringify({ action, username, password }) });

beforeAll(() => ripristina());

describe('API a token per il CRM', () => {
  it('login: stesso esito (riuscito, password errata, formatore)', async () => {
    for (const [u, pw] of [['hr.alfa', PW], ['hr.alfa', 'sbagliata'], ['formatore.alfa', PW]]) {
      const [a, b] = [await login(PHP_URL, u, pw), await login(NODE_URL, u, pw)];
      expect(b.status).toBe(a.status);
      if (a.status === 200) {
        expect(b.corpo.user).toEqual(a.corpo.user);
        expect(b.corpo.token.split('.')).toHaveLength(3);
      } else expect(b.corpo).toEqual(a.corpo);
    }
  });

  it('presenze di un periodo: stessi dati', async () => {
    const [ta, tb] = [(await login(PHP_URL, 'hr.alfa')).corpo.token, (await login(NODE_URL, 'hr.alfa')).corpo.token];
    const q = '/api/attendance.php?from=2026-10-01&to=2026-10-20';
    const a = await json(PHP_URL, q, { headers: { Authorization: `Bearer ${ta}` } });
    const b = await json(NODE_URL, q, { headers: { Authorization: `Bearer ${tb}` } });
    expect(b.status).toBe(200);
    const senzaOggi = (x: any) => ({ ...x, range: { ...x.range, today: undefined }, employees: x.employees.map((e: any) => ({ ...e, today: undefined })) });
    expect(senzaOggi(b.corpo)).toEqual(senzaOggi(a.corpo));
  });

  it('errori: senza token, token falso, intervallo troppo lungo', async () => {
    const tb = (await login(NODE_URL, 'hr.alfa')).corpo.token;
    const ta = (await login(PHP_URL, 'hr.alfa')).corpo.token;
    for (const [q, ha, hb] of [
      ['/api/attendance.php', {}, {}],
      ['/api/attendance.php', { Authorization: 'Bearer a.b.c' }, { Authorization: 'Bearer a.b.c' }],
      ['/api/attendance.php?from=2026-01-01&to=2026-06-30', { Authorization: `Bearer ${ta}` }, { Authorization: `Bearer ${tb}` }],
      ['/api/attendance.php?range=month', { Authorization: `Bearer ${ta}` }, { Authorization: `Bearer ${tb}` }],
    ] as Array<[string, Record<string, string>, Record<string, string>]>) {
      const a = await json(PHP_URL, q, { headers: ha });
      const b = await json(NODE_URL, q, { headers: hb });
      expect([b.status, b.corpo]).toEqual([a.status, a.corpo]);
    }
  });

  it('correzione: account con verifica in due passaggi senza token', async () => {
    postgres(`UPDATE users SET mfa_enabled = true WHERE username = 'studio.consulente'`);
    const b = await login(NODE_URL, 'studio.consulente');
    postgres(`UPDATE users SET mfa_enabled = false WHERE username = 'studio.consulente'`);
    expect(b.status).toBe(403);
  });

  it('correzione: rinnovo e presenze col ruolo attuale, rinnovo limitato a 24 ore', async () => {
    const t = (await login(NODE_URL, 'studio.consulente')).corpo.token;
    const rinnova = (tok: string) => json(NODE_URL, '/api/auth.php', { method: 'POST', body: JSON.stringify({ action: 'refresh' }), headers: { Authorization: `Bearer ${tok}` } });
    const r = await rinnova(t);
    expect(r.status).toBe(200);
    const corpo = (tok: string) => JSON.parse(Buffer.from(tok.split('.')[1].replace(/-/g, '+').replace(/_/g, '/'), 'base64').toString());
    expect(corpo(r.corpo.token).orig_iat).toBe(corpo(t).iat);
    const ruolo = postgres(`SELECT role FROM users WHERE username = 'studio.consulente'`)[0].role;
    postgres(`UPDATE users SET role = 'formatore' WHERE username = 'studio.consulente'`);
    try {
      expect((await json(NODE_URL, '/api/attendance.php', { headers: { Authorization: `Bearer ${r.corpo.token}` } })).status).toBe(403);
      expect((await rinnova(r.corpo.token)).status).toBe(403);
    } finally {
      postgres(`UPDATE users SET role = '${ruolo}' WHERE username = 'studio.consulente'`);
    }
  });
});

describe('porta NFC', () => {
  beforeAll(() => {
    for (const fn of [mariadb, postgres]) fn(`UPDATE companies SET door_enabled = ${fn === mariadb ? 1 : 'true'}, door_api_key = 'chiave-porta-di-prova-0123456789' WHERE slug = 'alfa'`);
  });
  const porta = (base: string, uid: string, chiave = 'chiave-porta-di-prova-0123456789') =>
    json(base, '/api/door.php?c=alfa', { method: 'POST', body: JSON.stringify({ uid }), headers: { 'X-Door-Key': chiave } });

  it.each([['04A1B2C3D4E5F6'], ['04FFEEDDCCBBAA'], ['04000000000000'], ['zz']])('stessa risposta (uid %s)', async (uid) => {
    const [a, b] = [await porta(PHP_URL, uid), await porta(NODE_URL, uid)];
    expect([b.status, b.corpo]).toEqual([a.status, a.corpo]);
  });

  it('chiave errata', async () => {
    const [a, b] = [await porta(PHP_URL, '04A1B2C3D4E5F6', 'sbagliata'), await porta(NODE_URL, '04A1B2C3D4E5F6', 'sbagliata')];
    expect([b.status, b.corpo]).toEqual([a.status, a.corpo]);
    const registro = (fn: typeof mariadb) => fn(`SELECT nfc_uid, granted, reason FROM door_access_log ORDER BY id`).map((r) => ({ ...r, granted: ['1', 't'].includes(r.granted) }));
    expect(registro(postgres)).toEqual(registro(mariadb));
  });
});

describe('timbratura da carta', () => {
  it('entrata, poi attesa di 30 secondi', async () => {
    for (const fn of [mariadb, postgres]) fn(`UPDATE companies SET timbratura_enabled = ${fn === mariadb ? 1 : 'true'} WHERE slug = 'alfa'`);
    const [p, n] = await Promise.all([phpEntra('sara.montanari'), nodeEntra('sara.montanari')]);
    const a = await p.b.chiama('/punch.php?c=alfa');
    const b = await n.b.chiama('/punch.php?c=alfa');
    const tipo = (h: string) => /<div class="kind">([^<]*)<\/div>/.exec(h)?.[1];
    expect(tipo(b.testo)).toBe(tipo(a.testo));
    const a2 = await p.b.chiama('/punch.php?c=alfa');
    const b2 = await n.b.chiama('/punch.php?c=alfa');
    const err = (h: string) => /<div class="err-msg">([^<]*)<\/div>/.exec(h)?.[1];
    expect(err(b2.testo)).toBe(err(a2.testo));
    const carta = await n.b.chiama('/punch.php?c=beta');
    const cartaPhp = await p.b.chiama('/punch.php?c=beta');
    expect(err(carta.testo)).toBe(err(cartaPhp.testo));
  });

  it('correzione: senza carta niente timbratura; da un altro sito serve la conferma', async () => {
    const n = await nodeEntra('sara.montanari');
    const conta = () => Number(postgres(`SELECT COUNT(*) AS n FROM attendance_punches p JOIN employees e ON e.id = p.employee_id WHERE e.first_name = 'Sara'`)[0].n);
    const prima = conta();
    const vuota = await n.b.chiama('/punch.php');
    expect(vuota.testo).toContain('Carta non valida per la tua azienda.');
    const esterno = await n.b.chiama('/punch.php?c=alfa', { header: { 'Sec-Fetch-Site': 'cross-site' } });
    expect(esterno.testo).toContain('Confermi la timbratura?');
    expect(esterno.testo).toContain('href="/punch.php?c=alfa"');
    expect(conta()).toBe(prima);
  });

  it('senza accesso: al login con ritorno alla timbratura', async () => {
    const r = await fetch(`${NODE_URL}/punch.php?c=alfa`, { redirect: 'manual' });
    expect(r.status).toBe(302);
    expect(r.headers.get('location')).toBe('/auth/login.php?return=%2Fpunch.php%3Fc%3Dalfa');
  });
});

describe('verifica attestato e download', () => {
  it('stessa verifica pubblica', async () => {
    const token = postgres('SELECT verify_token FROM academy_certificates LIMIT 1')[0].verify_token;
    const [a, b] = [await (await fetch(`${PHP_URL}/verifica-attestato.php?t=${token}`)).text(), await (await fetch(`${NODE_URL}/verifica-attestato.php?t=${token}`)).text()];
    const essenziale = (h: string) => [/<span class="badge (\w+)">/.exec(h)?.[1], /<h1>([^<]*)<\/h1>/.exec(h)?.[1], [...h.matchAll(/<dd>([^<]*)<\/dd>/g)].map((m) => m[1]),
      [...h.matchAll(/<div class="what">([\s\S]*?)<\/div>/g)].map((m) => m[1])];
    expect(essenziale(b)).toEqual(essenziale(a));
    const nessuno = await (await fetch(`${NODE_URL}/verifica-attestato.php?t=${'0'.repeat(32)}`)).text();
    expect(nessuno).toContain('Attestato non trovato');
  });

  it('download di una busta paga dallo staff', async () => {
    const n = await nodeEntra('hr.alfa');
    const id = postgres(`SELECT id FROM documents WHERE company_id = 1 AND mime_type = 'application/pdf' ORDER BY id LIMIT 1`)[0].id;
    const r = await n.b.scarica(`/api/download.php?id=${id}`);
    expect(r.status).toBe(200);
    expect(r.dati.subarray(0, 5).toString()).toBe('%PDF-');
    const altro = postgres(`SELECT id FROM documents WHERE company_id = 2 ORDER BY id LIMIT 1`)[0]?.id;
    // Correzione: il documento di un'altra azienda risulta inesistente (il PHP ne confermava l'esistenza con 403)
    if (altro) expect((await n.b.scarica(`/api/download.php?id=${altro}`)).status).toBe(404);
  });

  it('clic dell\'HR registrati (token nel corpo, come sendBeacon)', async () => {
    const n = await nodeEntra('hr.alfa');
    const fd = new FormData();
    fd.append('csrf_token', n.b.valoreCookie('cora_csrf')!);
    fd.append('label', 'Nuova richiesta');
    fd.append('page', 'Ferie e permessi');
    fd.append('kind', 'button');
    const r = await n.b.chiama('/api/ui-click.php', { metodo: 'POST', corpo: fd as any });
    expect(r.status).toBe(204);
    expect(postgres(`SELECT label FROM ui_click_logs ORDER BY id DESC LIMIT 1`)[0].label).toBe('Nuova richiesta');
    const senza = new FormData();
    senza.append('label', 'Falso');
    senza.append('page', 'X');
    await n.b.chiama('/api/ui-click.php', { metodo: 'POST', corpo: senza as any });
    expect(postgres(`SELECT COUNT(*) AS n FROM ui_click_logs WHERE label = 'Falso'`)[0].n).toBe('0');
  });
});
