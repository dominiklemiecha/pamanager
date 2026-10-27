import { mariadb, postgres, ripristina } from './ambiente';
import { Node, Php } from './clienti';

/**
 * Dashboard dell'HR: controlli all'apertura (avvisi del periodo di prova),
 * approvazione e rifiuto rapidi, decisione sul periodo di prova.
 */
const PW = 'Prova!Cora2026';

async function phpEntra(u: string) { const p = new Php(); await p.login(u, PW); return p; }
async function nodeEntra(u: string) { const n = new Node(); await n.login(u, PW); return n; }

async function phpPost(p: Php, pagina: string, campi: Record<string, string>) {
  const html = (await p.b.chiama('/admin/index.php')).testo;
  const csrf = /name="csrf_token" value="([^"]+)"/.exec(html)?.[1] ?? /name="csrf-token" content="([^"]+)"/.exec(html)?.[1] ?? '';
  const r = await p.b.chiama(pagina, { metodo: 'POST', corpo: new URLSearchParams({ csrf_token: csrf, ...campi }).toString(), tipo: 'application/x-www-form-urlencoded' });
  if (!r.location) return `HTTP ${r.status}`;
  const u = new URL(r.location, `http://x${pagina}`);
  return u.pathname + u.search;
}
/** Come il frontend: modulo multipart con il token CSRF nell'header. */
async function nodePost(n: Node, pagina: string, campi: Record<string, string>) {
  const fd = new FormData();
  for (const [k, v] of Object.entries(campi)) fd.append(k, v);
  const r = await n.b.chiama('/api/v1' + pagina, { metodo: 'POST', corpo: fd as any, header: { 'X-CSRF-Token': n.b.valoreCookie('cora_csrf')! } });
  return r.status === 200 ? JSON.parse(r.testo).redirect : `HTTP ${r.status}`;
}

const notifiche = (fn: typeof mariadb, tipo: string) => fn(
  `SELECT company_id, recipient_type, recipient_id, title, message, link FROM notifications WHERE type = '${tipo}' ORDER BY recipient_type, recipient_id, id`);
const richiesta = (fn: typeof mariadb, cognome: string, tipo: string) => Number(fn(
  `SELECT lr.id FROM leave_requests lr JOIN employees e ON e.id = lr.employee_id WHERE e.last_name = '${cognome}' AND lr.leave_type = '${tipo}' ORDER BY lr.id DESC LIMIT 1`)[0].id);
const stato = (fn: typeof mariadb, id: number): Record<string, any> => fn(`SELECT status, rejection_reason, approved_by IS NOT NULL AS da FROM leave_requests WHERE id = ${id}`)
  .map((r) => ({ ...r, da: ['1', 't'].includes(r.da) }))[0];

beforeAll(() => ripristina());

describe('apertura della dashboard', () => {
  it('avvisi di fine prova: una volta sola, agli stessi admin', async () => {
    const [p, n] = await Promise.all([phpEntra('hr.alfa'), nodeEntra('hr.alfa')]);
    await p.b.chiama('/admin/index.php');
    const d = await n.get('/pagine/admin/index');
    expect(d.status).toBe(200);
    expect(d.json.V.__probationPending.map((x: any) => x.last_name)).toEqual(['Montanari']);
    expect(notifiche(postgres, 'probation_alert')).toEqual(notifiche(mariadb, 'probation_alert'));
    // seconda apertura: niente doppioni
    await p.b.chiama('/admin/index.php');
    await n.get('/pagine/admin/index');
    expect(notifiche(postgres, 'probation_alert')).toEqual(notifiche(mariadb, 'probation_alert'));
    expect(notifiche(postgres, 'probation_alert').length).toBeGreaterThan(0);
  });

  it('solo per l\'HR', async () => {
    const n = await nodeEntra('studio.consulente');
    expect((await n.get('/pagine/admin/index')).status).toBe(403);
    const d = await nodeEntra('giulia.ferraresi');
    expect((await d.get('/pagine/admin/index')).status).toBe(403);
  });
});

describe('azioni rapide', () => {
  it('approvazione: stato ed esito al dipendente', async () => {
    const [p, n] = await Promise.all([phpEntra('hr.alfa'), nodeEntra('hr.alfa')]);
    const a = await phpPost(p, '/admin/index.php', { action: 'quick_approve', id: String(richiesta(mariadb, 'Ferraresi', 'ferie')) });
    const b = await nodePost(n, '/pagine/admin/index', { action: 'quick_approve', id: String(richiesta(postgres, 'Ferraresi', 'ferie')) });
    expect(b).toBe('/admin/index.php');
    expect(a).toBe('/admin/index.php');
    expect(stato(postgres, richiesta(postgres, 'Ferraresi', 'ferie'))).toEqual(stato(mariadb, richiesta(mariadb, 'Ferraresi', 'ferie')));
    // Correzione: niente commercialisti nel nuovo stack, quindi niente avviso per loro
    expect(notifiche(postgres, 'leave_approved')).toEqual(notifiche(mariadb, 'leave_approved').filter((r) => r.recipient_type !== 'accountant'));
  });

  it('rifiuto con motivo', async () => {
    const [p, n] = await Promise.all([phpEntra('hr.alfa'), nodeEntra('hr.alfa')]);
    await phpPost(p, '/admin/index.php', { action: 'quick_reject', id: String(richiesta(mariadb, 'Rinaldini', 'altro')), reason: '  Periodo pieno  ' });
    await nodePost(n, '/pagine/admin/index', { action: 'quick_reject', id: String(richiesta(postgres, 'Rinaldini', 'altro')), reason: '  Periodo pieno  ' });
    expect(stato(postgres, richiesta(postgres, 'Rinaldini', 'altro'))).toEqual(stato(mariadb, richiesta(mariadb, 'Rinaldini', 'altro')));
    expect(notifiche(postgres, 'leave_rejected')).toEqual(notifiche(mariadb, 'leave_rejected'));
  });

  it('richiesta di un\'altra azienda: niente da fare', async () => {
    const [p, n] = await Promise.all([phpEntra('hr.alfa'), nodeEntra('hr.alfa')]);
    await phpPost(p, '/admin/index.php', { action: 'quick_approve', id: String(richiesta(mariadb, 'Morselli', 'malattia')) });
    await nodePost(n, '/pagine/admin/index', { action: 'quick_approve', id: String(richiesta(postgres, 'Morselli', 'malattia')) });
    expect(stato(postgres, richiesta(postgres, 'Morselli', 'malattia')).status).toBe('pending');
    expect(stato(mariadb, richiesta(mariadb, 'Morselli', 'malattia')).status).toBe('pending');
  });
});

describe('periodo di prova', () => {
  it('conferma: decisione salvata e consulente avvisato', async () => {
    const [p, n] = await Promise.all([phpEntra('hr.alfa'), nodeEntra('hr.alfa')]);
    const id = (fn: typeof mariadb) => fn(`SELECT id FROM employees WHERE last_name = 'Montanari'`)[0].id;
    const a = await phpPost(p, '/admin/probation-decision.php', { employee_id: id(mariadb), decision: 'confirmed' });
    const b = await nodePost(n, '/pagine/admin/probation-decision', { employee_id: id(postgres), decision: 'confirmed' });
    expect(b).toBe(a);
    const dec = (fn: typeof mariadb) => fn(`SELECT probation_decision FROM employees WHERE last_name = 'Montanari'`)[0];
    expect(dec(postgres)).toEqual(dec(mariadb));
    expect(notifiche(postgres, 'probation_decision')).toEqual(notifiche(mariadb, 'probation_decision'));
    // seconda decisione: rifiutata
    expect(await nodePost(n, '/pagine/admin/probation-decision', { employee_id: id(postgres), decision: 'not_confirmed' }))
      .toBe(await phpPost(p, '/admin/probation-decision.php', { employee_id: id(mariadb), decision: 'not_confirmed' }));
  });
});
