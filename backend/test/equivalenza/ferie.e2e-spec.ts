import { mariadb, postgres, ripristina } from './ambiente';
import { Node, Php } from './clienti';

/**
 * Richieste di ferie e permessi dal lato del dipendente: stesse operazioni sul
 * PHP (modulo della pagina) e sul nuovo backend (API), stesso esito e stesse
 * righe salvate. Le correzioni volute sono scritte nei test.
 */
const PW = 'Prova!Cora2026';

async function phpEntra(u: string) { const p = new Php(); await p.login(u, PW); return p; }
async function nodeEntra(u: string) { const n = new Node(); await n.login(u, PW); return n; }

/** Invio del modulo PHP: esito "ok" (redirect con message) o testo dell'errore. */
async function phpInvia(p: Php, campi: Record<string, string>) {
  const pagina = await p.b.chiama('/employee/leave-requests.php');
  const csrf = /name="csrf_token" value="([^"]+)"/.exec(pagina.testo)?.[1] ?? '';
  const r = await p.b.chiama('/employee/leave-requests.php', { metodo: 'POST', corpo: new URLSearchParams({ csrf_token: csrf, ...campi }).toString(), tipo: 'application/x-www-form-urlencoded' });
  if (r.status === 302) return { ok: true, message: new URL(r.location!, 'http://x').searchParams.get('message') };
  return { ok: false, errore: /class="cora-alert is-error">([^<]+)</.exec(r.testo)?.[1]?.replace(/&#039;/g, "'") };
}

async function nodeInvia(n: Node, campi: Record<string, string>) {
  const fd = new FormData();
  for (const [k, v] of Object.entries(campi)) fd.append(k, v);
  const token = n.b.valoreCookie('cora_csrf')!;
  const r = await n.b.chiama('/api/v1/dipendente/richieste', { metodo: 'POST', corpo: fd as any, header: { 'X-CSRF-Token': token } });
  const j = r.testo ? JSON.parse(r.testo) : {};
  return r.status === 200 ? { ok: true, message: 'created' } : { ok: false, errore: j.message };
}

const ULTIMA = (fn: typeof mariadb) => fn(`SELECT employee_id, leave_type, start_date, end_date, is_full_day, start_time, end_time, reason, notes, protocol_number, status,
  approved_at IS NOT NULL AS approvata FROM leave_requests ORDER BY id DESC LIMIT 1`)[0];
const norm = (r: Record<string, string>) => ({ ...r, is_full_day: ['1', 't'].includes(r.is_full_day) ? 1 : 0, approvata: ['1', 't'].includes(r.approvata) ? 1 : 0, start_time: r.start_time?.slice(0, 5) ?? null, end_time: r.end_time?.slice(0, 5) ?? null });

beforeAll(() => ripristina());

describe('nuova richiesta: stessi esiti e stesse righe', () => {
  const casi: Array<[string, Record<string, string>]> = [
    ['ferie su piu giorni', { action: 'create', leave_type: 'ferie', start_date: '2026-11-16', end_date: '2026-11-20', is_full_day: '1', reason: 'Vacanza' }],
    ['permesso a ore senza motivazione (vale il tipo)', { action: 'create', leave_type: 'permesso', start_date: '2026-11-24', end_date: '2026-11-24', is_full_day: '0', start_time: '09:00', end_time: '11:00', reason: '' }],
    ['L.104 approvata subito', { action: 'create', leave_type: 'permesso_104', start_date: '2026-11-25', end_date: '2026-11-25', is_full_day: '1', reason: '' }],
    ['malattia con protocollo', { action: 'create', leave_type: 'malattia', start_date: '2026-11-26', end_date: '2026-11-27', is_full_day: '1', reason: '', protocol_number: 'INPS-77' }],
  ];
  it.each(casi)('%s', async (_n, campi) => {
    const [p, n] = await Promise.all([phpEntra('paolo.vezzani'), nodeEntra('paolo.vezzani')]);
    const [a, b] = [await phpInvia(p, campi), await nodeInvia(n, campi)];
    expect(b).toEqual(a);
    expect(norm(ULTIMA(postgres))).toEqual(norm(ULTIMA(mariadb)));
  });

  it('notifiche agli stessi responsabili con lo stesso testo', () => {
    const q = (fn: typeof mariadb) => fn(`SELECT recipient_type, recipient_id, type, title, message, link FROM notifications WHERE type = 'new_leave_request' ORDER BY recipient_type, recipient_id, id`);
    expect(q(postgres)).toEqual(q(mariadb));
  });

  const errori: Array<[string, Record<string, string>]> = [
    ['periodo gia occupato', { action: 'create', leave_type: 'ferie', start_date: '2026-11-18', end_date: '2026-11-19', is_full_day: '1', reason: 'x' }],
    ['fine prima dell inizio', { action: 'create', leave_type: 'ferie', start_date: '2026-12-10', end_date: '2026-12-09', is_full_day: '1', reason: 'x' }],
    ['date mancanti', { action: 'create', leave_type: 'ferie', start_date: '', end_date: '', is_full_day: '1', reason: 'x' }],
    ['tipo inesistente', { action: 'create', leave_type: 'vacanza', start_date: '2026-12-01', end_date: '2026-12-01', is_full_day: '1', reason: 'x' }],
  ];
  it.each(errori)('errore: %s', async (_n, campi) => {
    const [p, n] = await Promise.all([phpEntra('paolo.vezzani'), nodeEntra('paolo.vezzani')]);
    const prima = [mariadb('SELECT COUNT(*) AS n FROM leave_requests')[0].n, postgres('SELECT COUNT(*) AS n FROM leave_requests')[0].n];
    const [a, b] = [await phpInvia(p, campi), await nodeInvia(n, campi)];
    expect(b).toEqual(a);
    expect([mariadb('SELECT COUNT(*) AS n FROM leave_requests')[0].n, postgres('SELECT COUNT(*) AS n FROM leave_requests')[0].n]).toEqual(prima);
  });

  it('permessi nello stesso giorno con orari che non si toccano: ammessi', async () => {
    const campi = { action: 'create', leave_type: 'permesso', start_date: '2026-11-24', end_date: '2026-11-24', is_full_day: '0', start_time: '14:00', end_time: '16:00', reason: '' };
    const [p, n] = await Promise.all([phpEntra('paolo.vezzani'), nodeEntra('paolo.vezzani')]);
    expect(await nodeInvia(n, campi)).toEqual(await phpInvia(p, campi));
  });

  it('CORREZIONE: il dipendente non puo inviare una "chiusura aziendale"', async () => {
    const campi = { action: 'create', leave_type: 'chiusura', start_date: '2026-12-21', end_date: '2026-12-21', is_full_day: '1', reason: 'x' };
    const [p, n] = await Promise.all([phpEntra('paolo.vezzani'), nodeEntra('paolo.vezzani')]);
    expect((await phpInvia(p, campi)).ok).toBe(true);
    expect(await nodeInvia(n, campi)).toEqual({ ok: false, errore: 'Tipo assenza non valido' });
  });

  it("CORREZIONE: orario di fine prima dell'inizio rifiutato anche dal server", async () => {
    const campi = { action: 'create', leave_type: 'permesso', start_date: '2026-11-30', end_date: '2026-11-30', is_full_day: '0', start_time: '15:00', end_time: '10:00', reason: '' };
    const n = await nodeEntra('paolo.vezzani');
    expect(await nodeInvia(n, campi)).toEqual({ ok: false, errore: "L'ora di fine deve essere successiva all'ora di inizio" });
  });
});

describe('annullamento', () => {
  beforeAll(() => ripristina());

  it('la propria richiesta in attesa: annullata su entrambi', async () => {
    const id = mariadb("SELECT lr.id FROM leave_requests lr JOIN employees e ON e.id = lr.employee_id WHERE e.username = 'giulia.ferraresi' AND lr.status = 'pending'")[0].id;
    const p = await phpEntra('giulia.ferraresi');
    const pag = await p.b.chiama('/employee/leave-requests.php');
    const csrf = /name="csrf_token" value="([^"]+)"/.exec(pag.testo)?.[1] ?? '';
    await p.b.chiama('/employee/leave-requests.php', { metodo: 'POST', corpo: new URLSearchParams({ csrf_token: csrf, action: 'cancel', request_id: id }).toString(), tipo: 'application/x-www-form-urlencoded' });
    const n = await nodeEntra('giulia.ferraresi');
    expect((await n.post(`/dipendente/richieste/${id}/annulla`, {})).status).toBe(200);
    expect(postgres(`SELECT status FROM leave_requests WHERE id = ${id}`)).toEqual(mariadb(`SELECT status FROM leave_requests WHERE id = ${id}`));
  });

  it('la richiesta di un collega: rifiutato e niente cambia', async () => {
    const id = postgres("SELECT lr.id FROM leave_requests lr JOIN employees e ON e.id = lr.employee_id WHERE e.username = 'davide.rinaldini' AND lr.status = 'pending'")[0].id;
    const n = await nodeEntra('giulia.ferraresi');
    const r = await n.post(`/dipendente/richieste/${id}/annulla`, {});
    expect(r.json.message).toBe('Non puoi annullare richieste di altri dipendenti');
    expect(postgres(`SELECT status FROM leave_requests WHERE id = ${id}`)[0].status).toBe('pending');
  });

  it('CROSS-TENANT: un dipendente di Beta non vede ne tocca richieste di Alfa', async () => {
    const id = postgres("SELECT lr.id FROM leave_requests lr JOIN employees e ON e.id = lr.employee_id WHERE e.username = 'davide.rinaldini' AND lr.status = 'pending'")[0].id;
    const n = await nodeEntra('anna.castellari');
    expect((await n.post(`/dipendente/richieste/${id}/annulla`, {})).status).toBe(400);
    expect((await n.get(`/dipendente/richieste/${id}/certificato`)).status).toBe(403);
    const elenco = await n.get('/dipendente/richieste');
    expect(elenco.json.richieste.every((r: any) => r.company_id === 2)).toBe(true);
    expect(postgres(`SELECT status FROM leave_requests WHERE id = ${id}`)[0].status).toBe('pending');
  });
});
