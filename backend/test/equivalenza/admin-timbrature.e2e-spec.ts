import { mariadb, postgres, ripristina } from './ambiente';
import { Node, Php } from './clienti';

/** Timbrature dell'HR (admin/attendance.php). */
const PW = 'Prova!Cora2026';
const PAGINA = '/admin/attendance.php';
const API = '/pagine/admin/attendance';
async function phpEntra(u: string) { const p = new Php(); await p.login(u, PW); return p; }
async function nodeEntra(u: string) { const n = new Node(); await n.login(u, PW); return n; }
const decodifica = (s: string) => s.replace(/&#0?39;|&apos;/g, "'").replace(/&quot;/g, '"').replace(/&amp;/g, '&');

async function phpPost(p: Php, query: string, campi: Record<string, string>) {
  const html = (await p.b.chiama(PAGINA + query)).testo;
  const corpo = new URLSearchParams({ csrf_token: /name="csrf_token" value="([^"]+)"/.exec(html)?.[1] ?? '', ...campi }).toString();
  const r = await p.b.chiama(PAGINA + query, { metodo: 'POST', corpo, tipo: 'application/x-www-form-urlencoded' });
  return {
    message: decodifica(/alert alert-success">([^<]*)</.exec(r.testo)?.[1] ?? ''),
    error: decodifica(/alert alert-error">([^<]*)</.exec(r.testo)?.[1] ?? ''),
  };
}
async function nodePost(n: Node, query: string, campi: Record<string, string>) {
  const fd = new FormData();
  for (const [k, v] of Object.entries(campi)) fd.append(k, v);
  const r = await n.b.chiama('/api/v1' + API + query, { metodo: 'POST', corpo: fd as any, header: { 'X-CSRF-Token': n.b.valoreCookie('cora_csrf')! } });
  const j = JSON.parse(r.testo);
  return j.redirect ? { redirect: j.redirect } : { message: j.V.message, error: j.V.error };
}
const timbrature = (fn: typeof mariadb) => fn(
  `SELECT e.last_name, ${fn === postgres ? "to_char(p.punch_at AT TIME ZONE 'Europe/Rome', 'YYYY-MM-DD HH24:MI:SS')" : 'p.punch_at'} AS t, p.kind, p.source, p.notes
     FROM attendance_punches p JOIN employees e ON e.id = p.employee_id ORDER BY p.punch_at, p.id`)
  .map((r) => ({ ...r, notes: r.notes === 'NULL' ? null : r.notes }));
const ultima = (fn: typeof mariadb) => fn(`SELECT id FROM attendance_punches ORDER BY id DESC LIMIT 1`)[0].id;
const emp = (fn: typeof mariadb, c: string) => fn(`SELECT id FROM employees WHERE last_name = '${c}'`)[0].id;

beforeAll(() => ripristina());

it('giorno con timbrature: stessi contatori e stessa lista', async () => {
  const [p, n] = await Promise.all([phpEntra('hr.alfa'), nodeEntra('hr.alfa')]);
  const html = (await p.b.chiama(`${PAGINA}?d=2026-10-22`)).testo;
  const V = (await n.get(`${API}?d=2026-10-22`)).json.V;
  const valori = [...html.matchAll(/class="att-stat-value">([^<]*)</g)].map((m) => m[1]);
  expect([`${V.empWithPunches}/${V.totalEmp}`, String(V.empCurrentlyIn), String(V.empAbsent)]).toEqual(valori.slice(0, 3));
  expect(`${V.totalHours.toFixed(1).replace('.', ',')}h`).toBe(valori[3]);
  const orari = [...html.matchAll(/<span class="t">([^<]*)</g)].map((m) => m[1]);
  expect(Object.values(V.byEmp).flat().map((x: any) => x.punch_at.slice(11, 16))).toEqual(orari);
});

it('manuale: aggiungi, modifica, elimina', async () => {
  const [p, n] = await Promise.all([phpEntra('hr.alfa'), nodeEntra('hr.alfa')]);
  const crea = (fn: typeof mariadb) => ({ action: 'create_manual', employee_id: emp(fn, 'Ferraresi'), punch_date: '2026-10-22', punch_time: '08:30', kind: 'in', notes: ' Dimenticata ' });
  expect(await nodePost(n, '?d=2026-10-22', crea(postgres))).toEqual(await phpPost(p, '?d=2026-10-22', crea(mariadb)));
  expect(timbrature(postgres)).toEqual(timbrature(mariadb));
  const mod = (fn: typeof mariadb) => ({ action: 'update_manual', punch_id: ultima(fn), punch_date: '2026-10-22', punch_time: '08:45', kind: 'out', notes: '' });
  expect(await nodePost(n, '?d=2026-10-22', mod(postgres))).toEqual(await phpPost(p, '?d=2026-10-22', mod(mariadb)));
  expect(timbrature(postgres)).toEqual(timbrature(mariadb));
  const del = (fn: typeof mariadb) => ({ action: 'delete', punch_id: ultima(fn) });
  expect(await nodePost(n, '', del(postgres))).toEqual(await phpPost(p, '', del(mariadb)));
  expect(timbrature(postgres)).toEqual(timbrature(mariadb));
});

it('errori e altre aziende', async () => {
  const [p, n] = await Promise.all([phpEntra('hr.alfa'), nodeEntra('hr.alfa')]);
  for (const c of [
    { action: 'create_manual', employee_id: '', punch_date: '2026-10-22', punch_time: '08:00', kind: 'in' },
    { action: 'create_manual', employee_id: emp(mariadb, 'Morselli'), punch_date: '2026-10-22', punch_time: '08:00', kind: 'in' },
    { action: 'create_manual', employee_id: emp(mariadb, 'Ferraresi'), punch_date: '2026-10-22', punch_time: '08:00', kind: 'pausa' },
    { action: 'delete', punch_id: '999999' },
  ] as Record<string, string>[]) {
    const a = await phpPost(p, '', c);
    expect(a.error).not.toBe('');
    const pg = { ...c, employee_id: c.employee_id === emp(mariadb, 'Morselli') ? emp(postgres, 'Morselli') : c.employee_id === emp(mariadb, 'Ferraresi') ? emp(postgres, 'Ferraresi') : c.employee_id };
    expect(await nodePost(n, '', pg)).toEqual(a);
  }
  // correzione: data non valida -> errore della pagina
  expect((await nodePost(n, '', { action: 'create_manual', employee_id: emp(postgres, 'Ferraresi'), punch_date: '2026-02-31', punch_time: '25:00', kind: 'in' })).error)
    .toBe('Data o ora non valida.');
});

it('timbrature disattivate: si va alle impostazioni', async () => {
  const [p, n] = await Promise.all([phpEntra('hr.alfa'), nodeEntra('hr.alfa')]);
  mariadb('UPDATE companies SET timbratura_enabled = 0 WHERE id = 1');
  postgres('UPDATE companies SET timbratura_enabled = false WHERE id = 1');
  expect((await p.b.chiama(PAGINA)).location).toMatch(/punch-settings\.php\?msg=enable_first$/);
  expect((await n.get(API)).json).toEqual({ redirect: '/admin/punch-settings.php?msg=enable_first' });
});
