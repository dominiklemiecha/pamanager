import { mariadb, postgres, ripristina } from './ambiente';
import { Node, Php } from './clienti';

/** Calendario dello staff (admin/calendar.php, accountant/calendar.php): azioni dello script come HR o commercialista. */
const PW = 'Prova!Cora2026';

async function phpAzione(p: Php, pagina: string, campi: Record<string, string>) {
  const html = (await p.b.chiama(pagina)).testo;
  const csrf = /name="csrf-token" content="([^"]+)"/.exec(html)?.[1] ?? /name="csrf_token" value="([^"]+)"/.exec(html)?.[1] ?? '';
  const r = await p.b.chiama(pagina, { metodo: 'POST', corpo: new URLSearchParams({ csrf_token: csrf, ...campi }).toString(), tipo: 'application/x-www-form-urlencoded' });
  return JSON.parse(r.testo);
}
async function nodeAzione(n: Node, campi: Record<string, string>) {
  const fd = new FormData();
  for (const [k, v] of Object.entries(campi)) fd.append(k, v);
  const r = await n.b.chiama('/api/v1/calendario/azione', { metodo: 'POST', corpo: fd as any, header: { 'X-CSRF-Token': n.b.valoreCookie('cora_csrf')! } });
  return JSON.parse(r.testo);
}
const eventi = (fn: typeof mariadb) => fn(`SELECT owner_type, title, start_at, end_at FROM calendar_events ORDER BY id`).map((r) => ({ ...r, start_at: r.start_at.slice(0, 16), end_at: r.end_at.slice(0, 16) }));
const inviti = (fn: typeof mariadb) => fn(`SELECT p.user_type, p.status, e.title FROM calendar_event_participants p JOIN calendar_events e ON e.id = p.event_id ORDER BY e.id, p.user_type, p.user_id`);

beforeAll(() => ripristina());

it.each([['hr.alfa', '/admin/calendar.php'], ['studio.commercialista', '/accountant/calendar.php']])('%s crea un evento con un invitato', async (u, pagina) => {
  const p = new Php(); await p.login(u, PW);
  const n = new Node(); await n.login(u, PW);
  const giulia = (fn: typeof mariadb) => fn(`SELECT id FROM employees WHERE last_name = 'Ferraresi'`)[0].id;
  const campi = (fn: typeof mariadb) => ({ action: 'create_event', title: `Riunione ${u}`, start_at: '2026-10-29 09:00:00', end_at: '2026-10-29 10:00:00',
    participants: JSON.stringify([{ user_type: 'employee', user_id: Number(giulia(fn)) }]) });
  const a = await phpAzione(p, pagina, campi(mariadb));
  const b = await nodeAzione(n, campi(postgres));
  expect(b.success).toBe(a.success);
  expect(eventi(postgres)).toEqual(eventi(mariadb));
  expect(inviti(postgres)).toEqual(inviti(mariadb));
});
