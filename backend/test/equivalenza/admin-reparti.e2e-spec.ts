import { mariadb, postgres, ripristina } from './ambiente';
import { Node, Php } from './clienti';

/** Reparti (admin/departments.php e admin/department-detail.php). */
const PW = 'Prova!Cora2026';

async function phpEntra(u: string) { const p = new Php(); await p.login(u, PW); return p; }
async function nodeEntra(u: string) { const n = new Node(); await n.login(u, PW); return n; }
const decodifica = (s: string) => s.replace(/&#0?39;/g, "'").replace(/&quot;/g, '"').replace(/&amp;/g, '&').replace(/&lt;/g, '<').replace(/&gt;/g, '>');

async function phpPost(p: Php, pagina: string, campi: Record<string, string>) {
  const html = (await p.b.chiama(pagina)).testo;
  const corpo = new URLSearchParams({ csrf_token: /name="csrf_token" value="([^"]+)"/.exec(html)?.[1] ?? '', ...campi }).toString();
  const r = await p.b.chiama(pagina, { metodo: 'POST', corpo, tipo: 'application/x-www-form-urlencoded' });
  if (r.location) { const u = new URL(r.location, `http://x${pagina}`); return { redirect: u.pathname + u.search }; }
  return { error: decodifica(/alert alert-error">([^<]*)</.exec(r.testo)?.[1] ?? '') };
}
async function nodePost(n: Node, api: string, campi: Record<string, string>) {
  const fd = new FormData();
  for (const [k, v] of Object.entries(campi)) fd.append(k, v);
  const r = await n.b.chiama('/api/v1' + api, { metodo: 'POST', corpo: fd as any, header: { 'X-CSRF-Token': n.b.valoreCookie('cora_csrf')! } });
  const j = JSON.parse(r.testo);
  return j.redirect ? { redirect: j.redirect } : { error: j.V.error };
}
const rep = (fn: typeof mariadb, nome: string, cid = 1) => fn(`SELECT id FROM departments WHERE name = '${nome}' AND company_id = ${cid}`)[0]?.id;
const reparti = (fn: typeof mariadb) => fn(`SELECT company_id, name, code, description, is_active FROM departments ORDER BY company_id, name`)
  .map((r) => ({ ...r, is_active: ['1', 't'].includes(r.is_active), description: r.description === 'NULL' ? null : r.description }));

beforeAll(() => ripristina());

describe('elenco e modifica', () => {
  it('stessi reparti e contatori', async () => {
    const [p, n] = await Promise.all([phpEntra('hr.alfa'), nodeEntra('hr.alfa')]);
    const html = (await p.b.chiama('/admin/departments.php')).testo;
    const nomi = [...html.matchAll(/class="dp-name">([^<]*)</g)].map((m) => decodifica(m[1]));
    const V = (await n.get('/pagine/admin/departments')).json.V;
    expect(V.departments.map((d: any) => d.name)).toEqual(nomi);
    const conta = [...html.matchAll(/class="dp-stat-v">(\d+)</g)].map((m) => Number(m[1]));
    expect(V.departments.flatMap((d: any) => [d.employee_count, d.admin_count])).toEqual(conta);
  });

  it('creazione, poi doppione ed errori', async () => {
    const [p, n] = await Promise.all([phpEntra('hr.alfa'), nodeEntra('hr.alfa')]);
    const campi = { action: 'create', name: ' Ricerca ', code: 'ric_1', description: ' Laboratorio ' };
    const a = await phpPost(p, '/admin/departments.php', campi);
    expect(a).toEqual({ redirect: '/admin/departments.php?message=created' });
    expect(await nodePost(n, '/pagine/admin/departments', campi)).toEqual(a);
    for (const c of [campi, { ...campi, name: 'Altro', code: 'RIC_1' }, { action: 'create', name: 'X', code: 'XX' }, { action: 'create', name: 'Valido', code: 'A-B' }]) {
      const e = await phpPost(p, '/admin/departments.php', c);
      expect(e.error).not.toBe('');
      expect(await nodePost(n, '/pagine/admin/departments', c)).toEqual(e);
    }
    expect(reparti(postgres)).toEqual(reparti(mariadb));
  });

  it('modifica ed eliminazione', async () => {
    const [p, n] = await Promise.all([phpEntra('hr.alfa'), nodeEntra('hr.alfa')]);
    const mod = (fn: typeof mariadb) => ({ action: 'update', department_id: rep(fn, 'Ricerca'), name: 'Ricerca e sviluppo', code: 'rsv', description: '' });
    const a = await phpPost(p, '/admin/departments.php', mod(mariadb));
    expect(await nodePost(n, '/pagine/admin/departments', mod(postgres))).toEqual(a);
    const del = (fn: typeof mariadb) => ({ action: 'delete', department_id: rep(fn, 'Ricerca e sviluppo') });
    const b = await phpPost(p, '/admin/departments.php', del(mariadb));
    expect(b).toEqual({ redirect: '/admin/departments.php?message=deleted' });
    expect(await nodePost(n, '/pagine/admin/departments', del(postgres))).toEqual(b);
    // reparto con dipendenti: non eliminabile
    const pieno = (fn: typeof mariadb) => ({ action: 'delete', department_id: rep(fn, 'Commerciale') });
    expect(await nodePost(n, '/pagine/admin/departments', pieno(postgres))).toEqual(await phpPost(p, '/admin/departments.php', pieno(mariadb)));
    expect(reparti(postgres)).toEqual(reparti(mariadb));
  });

  it('correzione: reparto di un\'altra azienda non modificabile', async () => {
    const n = await nodeEntra('hr.alfa');
    const altro = postgres(`SELECT id FROM departments WHERE company_id = 2 LIMIT 1`)[0].id;
    expect(await nodePost(n, '/pagine/admin/departments', { action: 'update', department_id: altro, name: 'Preso', code: 'PRS' })).toEqual({ error: 'Reparto non trovato' });
    expect(await nodePost(n, '/pagine/admin/departments', { action: 'delete', department_id: altro })).toEqual({ error: 'Reparto non trovato' });
    expect((await n.get(`/pagine/admin/department-detail?id=${altro}`)).json).toEqual({ redirect: '/admin/departments.php?error=not_found' });
  });
});

describe('dettaglio del reparto', () => {
  const pag = (fn: typeof mariadb) => `/admin/department-detail.php?id=${rep(fn, 'Commerciale')}`;
  const api = (fn: typeof mariadb) => `/pagine/admin/department-detail?id=${rep(fn, 'Commerciale')}`;

  it('stessi dipendenti, responsabili e disponibili', async () => {
    const [p, n] = await Promise.all([phpEntra('hr.alfa'), nodeEntra('hr.alfa')]);
    const html = (await p.b.chiama(pag(mariadb))).testo;
    const V = (await n.get(api(postgres))).json.V;
    const nomi = [...html.matchAll(/<div class="name">([^<]*)</g)].map((m) => decodifica(m[1]));
    expect([...V.deptAdmins.map((a: any) => a.name), ...V.deptEmployees.map((e: any) => `${e.last_name} ${e.first_name}`)]).toEqual(nomi);
    const opzioni = [...html.matchAll(/<option value="(\d+)">/g)].length;
    expect(V.unassignedEmployees.length).toBe(opzioni);
    expect(JSON.stringify(V)).not.toMatch(/password_hash|\$argon2|\$2y\$/);
  });

  it('assegna e rimuovi un dipendente', async () => {
    const [p, n] = await Promise.all([phpEntra('hr.alfa'), nodeEntra('hr.alfa')]);
    const emp = (fn: typeof mariadb) => fn(`SELECT id FROM employees WHERE last_name = 'Vezzani'`)[0].id;
    const dove = (fn: typeof mariadb) => fn(`SELECT d.name FROM employees e LEFT JOIN departments d ON d.id = e.department_id WHERE e.last_name = 'Vezzani'`)[0].name;
    const a = await phpPost(p, pag(mariadb), { action: 'assign_employee', employee_id: emp(mariadb) });
    expect(await nodePost(n, api(postgres), { action: 'assign_employee', employee_id: emp(postgres) })).toEqual(a);
    expect(dove(postgres)).toBe(dove(mariadb));
    const b = await phpPost(p, pag(mariadb), { action: 'remove_employee', employee_id: emp(mariadb) });
    expect(await nodePost(n, api(postgres), { action: 'remove_employee', employee_id: emp(postgres) })).toEqual(b);
    expect(dove(postgres)).toBe(dove(mariadb));
  });

  it('nuovo responsabile, poi rimosso', async () => {
    const [p, n] = await Promise.all([phpEntra('hr.alfa'), nodeEntra('hr.alfa')]);
    const campi = { action: 'create_admin', admin_username: 'resp.nuovo', admin_password: 'Responsabile!2026', admin_name: 'Nuovo Responsabile', admin_email: 'resp.nuovo@cora.test' };
    const a = await phpPost(p, pag(mariadb), campi);
    expect(a.redirect).toMatch(/message=admin_created$/);
    expect(await nodePost(n, api(postgres), campi)).toEqual({ redirect: a.redirect!.replace(/id=\d+/, `id=${rep(postgres, 'Commerciale')}`) });
    expect((await new Node().login('resp.nuovo', 'Responsabile!2026')).esito).toBe('ok');
    const utente = (fn: typeof mariadb) => fn(`SELECT role, company_id, department_id IS NULL AS senza FROM users WHERE username = 'resp.nuovo'`)
      .map((r) => ({ ...r, senza: ['1', 't'].includes(r.senza) }))[0];
    expect(utente(postgres)).toEqual(utente(mariadb));
    // doppione
    expect(await nodePost(n, api(postgres), campi)).toEqual(await phpPost(p, pag(mariadb), campi));
    const uid = (fn: typeof mariadb) => fn(`SELECT id FROM users WHERE username = 'resp.nuovo'`)[0].id;
    const b = await phpPost(p, pag(mariadb), { action: 'remove_admin', user_id: uid(mariadb) });
    expect(await nodePost(n, api(postgres), { action: 'remove_admin', user_id: uid(postgres) })).toEqual({ redirect: b.redirect!.replace(/id=\d+/, `id=${rep(postgres, 'Commerciale')}`) });
    expect(utente(postgres)).toEqual(utente(mariadb));
  });

  it('correzione: password sotto la politica comune e utenti di altri non toccabili', async () => {
    const n = await nodeEntra('hr.alfa');
    const debole = await nodePost(n, api(postgres), { action: 'create_admin', admin_username: 'resp.corto', admin_password: 'Corta!26', admin_name: 'Corto' });
    expect(debole.error).toMatch(/almeno 12 caratteri/);
    for (const u of ['connecteed', 'hr.beta', 'studio.commercialista']) {
      const id = postgres(`SELECT id FROM users WHERE username = '${u}'`)[0].id;
      expect(await nodePost(n, api(postgres), { action: 'remove_admin', user_id: id })).toEqual({ error: 'Utente non trovato' });
    }
    expect(postgres(`SELECT role FROM users WHERE username = 'connecteed'`)[0].role).toBe('admin');
    const altro = postgres(`SELECT id FROM employees WHERE last_name = 'Morselli'`)[0].id;
    expect(await nodePost(n, api(postgres), { action: 'assign_employee', employee_id: altro })).toEqual({ error: 'Dipendente non trovato' });
  });
});
