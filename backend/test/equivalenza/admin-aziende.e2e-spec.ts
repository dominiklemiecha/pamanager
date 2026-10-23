import { mariadb, postgres, ripristina } from './ambiente';
import { Node, Php } from './clienti';

/** Aziende (admin/companies.php). */
const PW = 'Prova!Cora2026';
const PAGINA = '/admin/companies.php';
const API = '/pagine/admin/companies';

async function phpEntra(u: string) { const p = new Php(); await p.login(u, PW); return p; }
async function nodeEntra(u: string) { const n = new Node(); await n.login(u, PW); return n; }
const decodifica = (s: string) => s.replace(/&#0?39;/g, "'").replace(/&quot;/g, '"').replace(/&amp;/g, '&').replace(/&lt;/g, '<').replace(/&gt;/g, '>');

async function phpPost(p: Php, campi: Record<string, string>) {
  const html = (await p.b.chiama(PAGINA)).testo;
  const corpo = new URLSearchParams({ csrf_token: /name="csrf_token" value="([^"]+)"/.exec(html)?.[1] ?? '', ...campi }).toString();
  const r = await p.b.chiama(PAGINA, { metodo: 'POST', corpo, tipo: 'application/x-www-form-urlencoded' });
  if (r.location) { const u = new URL(r.location, `http://x${PAGINA}`); return { redirect: u.pathname + u.search }; }
  return { error: decodifica(/background:#fee2e2;border-color:#fca5a5;"><div><h2 style="color:#991b1b;">([^<]*)</.exec(r.testo)?.[1] ?? '') };
}
async function nodePost(n: Node, campi: Record<string, string>) {
  const fd = new FormData();
  for (const [k, v] of Object.entries(campi)) fd.append(k, v);
  const r = await n.b.chiama('/api/v1' + API, { metodo: 'POST', corpo: fd as any, header: { 'X-CSRF-Token': n.b.valoreCookie('cora_csrf')! } });
  const j = JSON.parse(r.testo);
  return j.redirect ? { redirect: j.redirect } : { error: j.V.error ?? '' };
}
const aziende = (fn: typeof mariadb) => fn(`SELECT name, slug, is_active, needs_setup FROM companies ORDER BY id`)
  .map((r) => ({ ...r, is_active: ['1', 't'].includes(r.is_active), needs_setup: ['1', 't'].includes(r.needs_setup) }));
const az = (fn: typeof mariadb, nome: string) => fn(`SELECT id FROM companies WHERE name = '${nome}'`)[0]?.id;

beforeAll(() => ripristina());

describe('elenco', () => {
  it.each([['hr.alfa'], ['connecteed']])('stesse aziende per %s', async (u) => {
    const [p, n] = await Promise.all([phpEntra(u), nodeEntra(u)]);
    const html = (await p.b.chiama(PAGINA)).testo;
    const nomi = [...html.matchAll(/<strong style="font-size:0.95rem;">([^<]*)</g)].map((m) => decodifica(m[1]));
    const V = (await n.get(API)).json.V;
    expect(V.rows.map((r: any) => r.name)).toEqual(nomi);
    expect(V.rows.map((r: any) => [r.emp_count, r.dept_count])).toEqual(
      [...html.matchAll(/tabular-nums;">(\d+)</g)].map((m) => Number(m[1])).reduce((a: number[][], x, i) => (i % 2 ? a[a.length - 1].push(x) : a.push([x]), a), []));
  });
});

describe('gestione', () => {
  it('nuova azienda collegata all\'HR, rinominata, disattivata, eliminata', async () => {
    const [p, n] = await Promise.all([phpEntra('hr.alfa'), nodeEntra('hr.alfa')]);
    const a = await phpPost(p, { action: 'create', name: 'Gamma Logistica S.r.l.' });
    expect(a).toEqual({ redirect: '/admin/companies.php?msg=created' });
    expect(await nodePost(n, { action: 'create', name: 'Gamma Logistica S.r.l.' })).toEqual(a);
    expect(aziende(postgres)).toEqual(aziende(mariadb));
    const collegata = (fn: typeof mariadb) => fn(`SELECT COUNT(*) AS n FROM user_companies uc JOIN users u ON u.id = uc.user_id WHERE u.username = 'hr.alfa'`)[0].n;
    expect(collegata(postgres)).toBe(collegata(mariadb));

    const ren = (fn: typeof mariadb) => ({ action: 'update', id: az(fn, 'Gamma Logistica S.r.l.'), name: 'Gamma S.p.A.' });
    expect(await nodePost(n, ren(postgres))).toEqual(await phpPost(p, ren(mariadb)));

    const sbagliato = (fn: typeof mariadb) => ({ action: 'delete', id: az(fn, 'Gamma S.p.A.'), confirm_name: 'Gamma' });
    expect(await nodePost(n, sbagliato(postgres))).toEqual(await phpPost(p, sbagliato(mariadb)));
    const giusto = (fn: typeof mariadb) => ({ action: 'delete', id: az(fn, 'Gamma S.p.A.'), confirm_name: 'Gamma S.p.A.' });
    const d = await phpPost(p, giusto(mariadb));
    expect(d).toEqual({ redirect: '/admin/companies.php?msg=deleted' });
    expect(await nodePost(n, giusto(postgres))).toEqual(d);
    expect(aziende(postgres)).toEqual(aziende(mariadb));

    // disattivata: per l'HR dell'azienda non e' piu' accessibile (come nel PHP)
    await phpPost(p, { action: 'create', name: 'Epsilon' });
    await nodePost(n, { action: 'create', name: 'Epsilon' });
    const tog = (fn: typeof mariadb) => ({ action: 'toggle', id: az(fn, 'Epsilon') });
    expect(await nodePost(n, tog(postgres))).toEqual(await phpPost(p, tog(mariadb)));
    expect(await nodePost(n, tog(postgres))).toEqual(await phpPost(p, tog(mariadb)));
    expect(aziende(postgres)).toEqual(aziende(mariadb));
  });

  it('azienda corrente e principale non eliminabili, nome vuoto', async () => {
    const [p, n] = await Promise.all([phpEntra('hr.alfa'), nodeEntra('hr.alfa')]);
    for (const c of [{ action: 'delete', id: '1', confirm_name: 'x' }, { action: 'create', name: '  ' }] as Record<string, string>[]) {
      const a = await phpPost(p, c);
      expect(a.error).not.toBe('');
      expect(await nodePost(n, c)).toEqual(a);
    }
  });

  it('cambio azienda dell\'admin globale', async () => {
    const [p, n] = await Promise.all([phpEntra('connecteed'), nodeEntra('connecteed')]);
    const a = await phpPost(p, { action: 'switch', id: '2' });
    expect(a).toEqual({ redirect: '/admin/' });
    expect(await nodePost(n, { action: 'switch', id: '2' })).toEqual(a);
    const V = (await n.get(API)).json.V;
    expect(V.currentCid).toBe(2);
  });

  it('azienda altrui: niente modifiche', async () => {
    const [p, n] = await Promise.all([phpEntra('hr.alfa'), nodeEntra('hr.alfa')]);
    for (const c of [{ action: 'update', id: '2', name: 'Presa' }, { action: 'toggle', id: '2' }, { action: 'delete', id: '2', confirm_name: 'x' }, { action: 'switch', id: '2' }] as Record<string, string>[]) {
      expect(await nodePost(n, c)).toEqual(await phpPost(p, c));
    }
    expect(aziende(postgres)).toEqual(aziende(mariadb));
  });

  it('correzione: eliminazione completa dei dati dell\'azienda', async () => {
    const n = await nodeEntra('connecteed');
    await nodePost(n, { action: 'create', name: 'Delta Prova' });
    const id = az(postgres, 'Delta Prova');
    postgres(`INSERT INTO departments (company_id, name, code, is_active) VALUES (${id}, 'Unico', 'UNI', true)`);
    postgres(`INSERT INTO calendar_events (company_id, owner_type, owner_id, title, start_at, end_at) VALUES (${id}, 'admin', 1, 'Evento', now(), now())`);
    postgres(`INSERT INTO door_access_log (company_id, nfc_uid, granted, reason) VALUES (${id}, 'AA', false, 'unknown_uid')`);
    expect(await nodePost(n, { action: 'delete', id, confirm_name: 'Delta Prova' })).toEqual({ redirect: '/admin/companies.php?msg=deleted' });
    for (const t of ['departments', 'calendar_events', 'door_access_log']) expect(postgres(`SELECT COUNT(*) AS n FROM ${t} WHERE company_id = ${id}`)[0].n).toBe('0');
  });
});
