import { mariadb, postgres, ripristina } from './ambiente';
import { Node, Php } from './clienti';

/** Reset password dell'HR (admin/password-resets.php). */
const PW = 'Prova!Cora2026';
const PAGINA = '/admin/password-resets.php';
const API = '/pagine/admin/password-resets';

async function phpEntra(u: string) { const p = new Php(); await p.login(u, PW); return p; }
async function nodeEntra(u: string) { const n = new Node(); await n.login(u, PW); return n; }

async function phpPost(p: Php, campi: Record<string, string>) {
  const html = (await p.b.chiama(PAGINA)).testo;
  const corpo = new URLSearchParams({ csrf_token: /name="csrf_token" value="([^"]+)"/.exec(html)?.[1] ?? '', ...campi }).toString();
  const r = await p.b.chiama(PAGINA, { metodo: 'POST', corpo, tipo: 'application/x-www-form-urlencoded' });
  return {
    message: /alert alert-success">([\s\S]*?)<\/div>\s*(?:<div class="alert alert-error"|<!-- Manual Reset -->)/.exec(r.testo)?.[1] ?? '',
    error: /alert alert-error">([^<]*)</.exec(r.testo)?.[1] ?? '',
  };
}
async function nodePost(n: Node, campi: Record<string, string>) {
  const fd = new FormData();
  for (const [k, v] of Object.entries(campi)) fd.append(k, v);
  const r = await n.b.chiama('/api/v1' + API, { metodo: 'POST', corpo: fd as any, header: { 'X-CSRF-Token': n.b.valoreCookie('cora_csrf')! } });
  const j = JSON.parse(r.testo);
  return { message: j.V.message as string, error: j.V.error as string };
}
const id = (fn: typeof mariadb, tabella: string, campo: string, valore: string) => fn(`SELECT id FROM ${tabella} WHERE ${campo} = '${valore}'`)[0].id;
const nuovaRichiesta = (fn: typeof mariadb, tipo: string, uid: string) => {
  fn(`INSERT INTO password_reset_requests (user_type, user_id, status, requested_ip) VALUES ('${tipo}', ${uid}, 'pending', '127.0.0.1')`);
  return fn(`SELECT MAX(id) AS id FROM password_reset_requests`)[0].id;
};
const stato = (fn: typeof mariadb, rid: string): Record<string, any> => fn(`SELECT status, notes, resolved_by IS NOT NULL AS r, token_id IS NOT NULL AS t FROM password_reset_requests WHERE id = ${rid}`)
  .map((x) => ({ ...x, r: ['1', 't'].includes(x.r), t: ['1', 't'].includes(x.t) }))[0];

beforeAll(() => ripristina());

describe('elenco degli account', () => {
  it('dipendenti uguali; correzione: niente admin senza azienda', async () => {
    const [p, n] = await Promise.all([phpEntra('hr.alfa'), nodeEntra('hr.alfa')]);
    const html = (await p.b.chiama(PAGINA)).testo;
    const dip = JSON.parse(/const employees = (\[.*?\]);/.exec(html)![1]);
    const ut = JSON.parse(/const users = (\[.*?\]);/.exec(html)![1]);
    const V = (await n.get(API)).json.V;
    expect(V.employees.map((e: any) => e.name)).toEqual(dip.map((e: any) => e.name));
    expect(ut.some((u: any) => u.username === 'connecteed')).toBe(true);
    // Correzione: responsabili di reparto e commercialisti non esistono piu' (account disattivati)
    const tolti = ['connecteed', 'resp.commerciale', 'studio.commercialista'];
    expect(V.users.map((u: any) => u.username)).toEqual(ut.filter((u: any) => !tolti.includes(u.username)).map((u: any) => u.username));
  });
});

describe('reset manuale', () => {
  it('dipendente dell\'azienda: password temporanea e cambio obbligatorio', async () => {
    const [p, n] = await Promise.all([phpEntra('hr.alfa'), nodeEntra('hr.alfa')]);
    const a = await phpPost(p, { action: 'manual_reset', user_type: 'employee', user_id: id(mariadb, 'employees', 'last_name', 'Vezzani') });
    const b = await nodePost(n, { action: 'manual_reset', user_type: 'employee', user_id: id(postgres, 'employees', 'last_name', 'Vezzani') });
    const temporanea = (m: string) => /^Password resettata\. Nuova password temporanea: <code>(.*)<\/code>$/.exec(m)?.[1].replace(/&amp;/g, '&') ?? '';
    expect(temporanea(a.message)).toHaveLength(16);
    expect(temporanea(b.message)).toHaveLength(16);
    const pw = temporanea(b.message);
    const d = new Node();
    expect((await d.login(postgres(`SELECT username FROM employees WHERE last_name = 'Vezzani'`)[0].username, pw)).esito).toBe('ok');
    const forza = (fn: typeof mariadb) => ['1', 't'].includes(fn(`SELECT must_change_password AS m FROM employees WHERE last_name = 'Vezzani'`)[0].m);
    expect(forza(postgres)).toBe(forza(mariadb));
  });

  it('correzione: account di un\'altra azienda o admin globale rifiutati', async () => {
    const n = await nodeEntra('hr.alfa');
    for (const [tipo, uid] of [['employee', id(postgres, 'employees', 'last_name', 'Morselli')], ['admin', id(postgres, 'users', 'username', 'connecteed')],
      ['admin', id(postgres, 'users', 'username', 'hr.beta')], ['consulente_lavoro', id(postgres, 'users', 'username', 'studio.consulente')]]) {
      expect(await nodePost(n, { action: 'manual_reset', user_type: tipo, user_id: uid })).toEqual({ message: '', error: 'Utente non trovato' });
    }
    expect((await new Node().login('hr.beta', PW)).esito).toBe('ok');
  });
});

describe('richieste di recupero', () => {
  it('approvazione: link di 24 ore che funziona', async () => {
    const [p, n] = await Promise.all([phpEntra('hr.alfa'), nodeEntra('hr.alfa')]);
    const rp = nuovaRichiesta(mariadb, 'employee', id(mariadb, 'employees', 'last_name', 'Gualtieri'));
    const rn = nuovaRichiesta(postgres, 'employee', id(postgres, 'employees', 'last_name', 'Gualtieri'));
    const a = await phpPost(p, { action: 'approve', request_id: rp });
    const b = await nodePost(n, { action: 'approve', request_id: rn });
    expect(a.message).toMatch(/^Richiesta approvata!/);
    expect(b.message.replace(/token=[0-9a-f]{64}/, 'token=X').replace(/http:\/\/[^/]+/, 'U')).toBe(a.message.replace(/token=[0-9a-f]{64}/, 'token=X').replace(/http:\/\/[^/]+/, 'U'));
    expect(stato(postgres, rn)).toEqual(stato(mariadb, rp));
    const token = /token=([0-9a-f]{64})/.exec(b.message)![1];
    const v = await new Node().post('/auth/reset/verifica', { token });
    expect(v.json.valido).toBe(true);
    // una seconda approvazione non e' possibile
    expect(await nodePost(n, { action: 'approve', request_id: rn })).toEqual(await phpPost(p, { action: 'approve', request_id: rp }));
  });

  it('rifiuto con nota', async () => {
    const [p, n] = await Promise.all([phpEntra('hr.alfa'), nodeEntra('hr.alfa')]);
    const rp = nuovaRichiesta(mariadb, 'employee', id(mariadb, 'employees', 'last_name', 'Rinaldini'));
    const rn = nuovaRichiesta(postgres, 'employee', id(postgres, 'employees', 'last_name', 'Rinaldini'));
    const a = await phpPost(p, { action: 'reject', request_id: rp, notes: ' Non riconosciuto ' });
    expect(await nodePost(n, { action: 'reject', request_id: rn, notes: ' Non riconosciuto ' })).toEqual(a);
    expect(stato(postgres, rn)).toEqual(stato(mariadb, rp));
  });

  it('correzione: richiesta di un\'altra azienda non gestibile', async () => {
    const n = await nodeEntra('hr.alfa');
    const rn = nuovaRichiesta(postgres, 'employee', id(postgres, 'employees', 'last_name', 'Morselli'));
    expect(await nodePost(n, { action: 'approve', request_id: rn })).toEqual({ message: '', error: 'Richiesta non trovata' });
    expect(await nodePost(n, { action: 'reject', request_id: rn })).toEqual({ message: '', error: 'Richiesta non trovata' });
    expect(stato(postgres, rn).status).toBe('pending');
  });
});
