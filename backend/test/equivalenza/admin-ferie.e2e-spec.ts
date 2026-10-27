import { mariadb, postgres, ripristina } from './ambiente';
import { Node, Php } from './clienti';

/**
 * Ferie e permessi dell'HR (admin/leave-requests.php): elenchi per stato e
 * reparto, approvazione, rifiuto, modifica, eliminazione, inserimento
 * manuale, documenti della malattia e download.
 */
const PW = 'Prova!Cora2026';
const PAGINA = '/admin/leave-requests.php';
const API = '/pagine/admin/leave-requests';

async function phpEntra(u: string) { const p = new Php(); await p.login(u, PW); return p; }
async function nodeEntra(u: string) { const n = new Node(); await n.login(u, PW); return n; }

const decodifica = (s: string) => s.replace(/&#0?39;/g, "'").replace(/&quot;/g, '"').replace(/&amp;/g, '&').replace(/&lt;/g, '<').replace(/&gt;/g, '>');

/** POST del modulo sul PHP: redirect (percorso e query) o la pagina ridisegnata. */
async function phpPost(p: Php, campi: Record<string, string>, file?: { campo: string; nome: string; dati: Buffer; tipo: string }) {
  const html = (await p.b.chiama(PAGINA)).testo;
  const csrf = /name="csrf_token" value="([^"]+)"/.exec(html)?.[1] ?? '';
  const fd = new FormData();
  fd.append('csrf_token', csrf);
  for (const [k, v] of Object.entries(campi)) fd.append(k, v);
  if (file) fd.append(file.campo, new Blob([new Uint8Array(file.dati)], { type: file.tipo }), file.nome);
  const r = await p.b.chiama(PAGINA, { metodo: 'POST', corpo: fd as any });
  if (!r.location) return { html: r.testo };
  const u = new URL(r.location, `http://x${PAGINA}`);
  return { redirect: u.pathname + u.search };
}
/** Lo stesso sul nuovo backend, come lo manda il frontend (multipart, CSRF nell'header). */
async function nodePost(n: Node, campi: Record<string, string>, file?: { campo: string; nome: string; dati: Buffer; tipo: string }) {
  const fd = new FormData();
  for (const [k, v] of Object.entries(campi)) fd.append(k, v);
  if (file) fd.append(file.campo, new Blob([new Uint8Array(file.dati)], { type: file.tipo }), file.nome);
  const r = await n.b.chiama('/api/v1' + API, { metodo: 'POST', corpo: fd as any, header: { 'X-CSRF-Token': n.b.valoreCookie('cora_csrf')! } });
  const j = JSON.parse(r.testo);
  return j.redirect ? { redirect: j.redirect } : { V: j.V };
}

/** Righe della tabella nel PHP: dipendente e tipo, nell'ordine mostrato. */
async function righePhp(p: Php, query: string) {
  const html = (await p.b.chiama(PAGINA + query)).testo;
  const nomi = [...html.matchAll(/class="lp-emp-name">([^<]*)</g)].map((m) => decodifica(m[1]));
  const tipi = [...html.matchAll(/<span class="lp-type ([a-z_0-9]+)">/g)].map((m) => m[1]);
  return nomi.map((x, i) => `${x}|${tipi[i]}`);
}
async function righeNode(n: Node, query: string) {
  const d = await n.get(API + query);
  return (d.json.V.requests as any[]).map((r) => `${r.last_name} ${r.first_name}|${r.leave_type}`);
}

const richiesta = (fn: typeof mariadb, cognome: string, tipo: string) => Number(fn(
  `SELECT lr.id FROM leave_requests lr JOIN employees e ON e.id = lr.employee_id WHERE e.last_name = '${cognome}' AND lr.leave_type = '${tipo}' ORDER BY lr.id DESC LIMIT 1`)[0].id);
const riga = (fn: typeof mariadb, id: number) => fn(
  `SELECT leave_type, start_date, end_date, is_full_day, start_time, end_time, reason, notes, status, protocol_number, certificate_name, certificate_waived,
          approved_by IS NOT NULL AS da, created_by_admin FROM leave_requests WHERE id = ${id}`)
  .map((r) => Object.fromEntries(Object.entries(r).map(([k, v]) => [k, v === 't' ? '1' : v === 'f' ? '0' : v === 'NULL' ? null : v])))[0];
const notifiche = (fn: typeof mariadb, tipo: string) => fn(
  `SELECT company_id, recipient_type, recipient_id, title, message, link FROM notifications WHERE type = '${tipo}' ORDER BY recipient_type, recipient_id, id`);
const reparto = (fn: typeof mariadb, nome: string) => fn(`SELECT id FROM departments WHERE name = '${nome}' AND company_id = 1`)[0].id;

beforeAll(() => ripristina());

describe('elenchi', () => {
  it.each([['', 'in attesa'], ['?status=all', 'tutte'], ['?status=approved', 'approvate'], ['?status=rejected', 'rifiutate'], ['?status=cancelled', 'annullate']])(
    'stesse righe nello stesso ordine (%s %s)', async (q) => {
      const [p, n] = await Promise.all([phpEntra('hr.alfa'), nodeEntra('hr.alfa')]);
      const attese = await righePhp(p, q);
      expect(await righeNode(n, q)).toEqual(attese);
    });

  it('filtro per reparto', async () => {
    const [p, n] = await Promise.all([phpEntra('hr.alfa'), nodeEntra('hr.alfa')]);
    const attese = await righePhp(p, `?status=all&department=${reparto(mariadb, 'Commerciale')}`);
    expect(attese.length).toBeGreaterThan(0);
    expect(await righeNode(n, `?status=all&department=${reparto(postgres, 'Commerciale')}`)).toEqual(attese);
  });

  it('contatori e malattie senza documenti', async () => {
    const [p, n] = await Promise.all([phpEntra('hr.alfa'), nodeEntra('hr.alfa')]);
    const html = (await p.b.chiama(PAGINA)).testo;
    const V = (await n.get(API)).json.V;
    const stat = [...html.matchAll(/<div class="v">(\d+)<\/div>/g)].map((m) => Number(m[1]));
    if (stat.length) expect([V.__lrPending, V.__lrApproved, V.__lrTotal]).toEqual(stat.slice(0, 3));
    expect(Number(/cd-tab-badge">(\d+)</.exec(html)?.[1] ?? 0)).toBe(V.__pendingCountDb);
    const malattie = Number(/<strong>(\d+) malatti[ae] senza protocollo/.exec(html)?.[1] ?? 0);
    expect(V.__sickLate.length).toBe(malattie);
  });

  it('solo per l\'HR', async () => {
    expect((await (await nodeEntra('studio.consulente')).get(API)).status).toBe(403);
    expect((await (await nodeEntra('giulia.ferraresi')).get(API)).status).toBe(403);
  });

  it('correzione: ?message= non riflette testo arbitrario', async () => {
    const n = await nodeEntra('hr.alfa');
    expect((await n.get(API + '?message=%3Cb%3Eciao%3C%2Fb%3E')).json.V.message).toBe('');
    expect((await n.get(API + '?message=approved')).json.V.message).toBe('Richiesta approvata con successo');
    expect((await n.get(API + '?status=approved&message=Create+2+richieste')).json.V.message).toBe('Create 2 richieste');
  });
});

describe('azioni sulle richieste', () => {
  it('approvazione: stato, notifiche, redirect', async () => {
    const [p, n] = await Promise.all([phpEntra('hr.alfa'), nodeEntra('hr.alfa')]);
    const a = await phpPost(p, { action: 'approve', request_id: String(richiesta(mariadb, 'Montanari', 'ferie')) });
    const b = await nodePost(n, { action: 'approve', request_id: String(richiesta(postgres, 'Montanari', 'ferie')) });
    expect(a).toEqual({ redirect: '/admin/leave-requests.php?message=approved' });
    expect(b).toEqual(a);
    expect(riga(postgres, richiesta(postgres, 'Montanari', 'ferie'))).toEqual(riga(mariadb, richiesta(mariadb, 'Montanari', 'ferie')));
    // Correzione: niente commercialisti nel nuovo stack, quindi niente avviso per loro
    expect(notifiche(postgres, 'leave_approved')).toEqual(notifiche(mariadb, 'leave_approved').filter((r) => r.recipient_type !== 'accountant'));
  });

  it('seconda approvazione: errore nella pagina', async () => {
    const [p, n] = await Promise.all([phpEntra('hr.alfa'), nodeEntra('hr.alfa')]);
    const a = await phpPost(p, { action: 'approve', request_id: String(richiesta(mariadb, 'Montanari', 'ferie')) });
    const b = await nodePost(n, { action: 'approve', request_id: String(richiesta(postgres, 'Montanari', 'ferie')) });
    const errore = decodifica(/alert alert-error">([^<]*)</.exec(a.html!)?.[1] ?? '');
    expect(errore).toBe('Solo le richieste in attesa possono essere approvate');
    expect(b.V.error).toBe(errore);
  });

  it('rifiuto con motivo', async () => {
    const [p, n] = await Promise.all([phpEntra('hr.alfa'), nodeEntra('hr.alfa')]);
    const a = await phpPost(p, { action: 'reject', request_id: String(richiesta(mariadb, 'Rinaldini', 'altro')), rejection_reason: 'Periodo pieno' });
    const b = await nodePost(n, { action: 'reject', request_id: String(richiesta(postgres, 'Rinaldini', 'altro')), rejection_reason: 'Periodo pieno' });
    expect(b).toEqual(a);
    expect(riga(postgres, richiesta(postgres, 'Rinaldini', 'altro'))).toEqual(riga(mariadb, richiesta(mariadb, 'Rinaldini', 'altro')));
    expect(notifiche(postgres, 'leave_rejected')).toEqual(notifiche(mariadb, 'leave_rejected'));
  });

  it('modifica dell\'HR', async () => {
    const [p, n] = await Promise.all([phpEntra('hr.alfa'), nodeEntra('hr.alfa')]);
    const campi = { action: 'admin_edit', leave_type: 'permesso', status: 'approved', start_date: '2026-10-26', end_date: '2026-10-26',
      start_time: '09:00', end_time: '11:30', reason: '  Visita  ', notes: '' };
    const a = await phpPost(p, { ...campi, request_id: String(richiesta(mariadb, 'Gualtieri', 'ferie')) });
    const b = await nodePost(n, { ...campi, request_id: String(richiesta(postgres, 'Gualtieri', 'ferie')) });
    expect(a).toEqual({ redirect: '/admin/leave-requests.php?message=updated' });
    expect(b).toEqual(a);
    const norm = (r: any) => ({ ...r, start_time: r.start_time?.slice(0, 5), end_time: r.end_time?.slice(0, 5) });
    expect(norm(riga(postgres, richiesta(postgres, 'Gualtieri', 'permesso')))).toEqual(norm(riga(mariadb, richiesta(mariadb, 'Gualtieri', 'permesso'))));
  });

  /** Correzione: la richiesta di un'altra azienda risulta inesistente (il PHP ne confermava l'esistenza). */
  it('richiesta di un\'altra azienda: errore e niente modifiche', async () => {
    const [p, n] = await Promise.all([phpEntra('hr.alfa'), nodeEntra('hr.alfa')]);
    for (const action of ['approve', 'admin_delete', 'admin_waive_cert', 'admin_sick_docs']) {
      const a = await phpPost(p, { action, waived: '1', protocol_number: 'X', request_id: String(richiesta(mariadb, 'Morselli', 'malattia')) });
      const b = await nodePost(n, { action, waived: '1', protocol_number: 'X', request_id: String(richiesta(postgres, 'Morselli', 'malattia')) });
      expect(a.html).toMatch(/alert alert-error">Richiesta non appartiene all/);
      expect(b.V.error).toBe('Richiesta non trovata');
    }
    expect(riga(postgres, richiesta(postgres, 'Morselli', 'malattia'))).toEqual(riga(mariadb, richiesta(mariadb, 'Morselli', 'malattia')));
  });

  it('eliminazione', async () => {
    const [p, n] = await Promise.all([phpEntra('hr.alfa'), nodeEntra('hr.alfa')]);
    const a = await phpPost(p, { action: 'admin_delete', request_id: String(richiesta(mariadb, 'Vezzani', 'congedo_parentale')) });
    const b = await nodePost(n, { action: 'admin_delete', request_id: String(richiesta(postgres, 'Vezzani', 'congedo_parentale')) });
    expect(b).toEqual(a);
    const resta = (fn: typeof mariadb) => fn(`SELECT COUNT(*) AS n FROM leave_requests lr JOIN employees e ON e.id = lr.employee_id WHERE e.last_name = 'Vezzani'`)[0].n;
    expect(resta(postgres)).toBe(resta(mariadb));
  });
});

describe('documenti della malattia', () => {
  const pdf = Buffer.from('%PDF-1.4\n1 0 obj<<>>endobj\ntrailer<<>>\n%%EOF\n');
  const cert = { campo: 'certificate', nome: 'certificato medico.pdf', dati: pdf, tipo: 'application/pdf' };

  it('protocollo e certificato, poi download', async () => {
    const [p, n] = await Promise.all([phpEntra('hr.alfa'), nodeEntra('hr.alfa')]);
    const a = await phpPost(p, { action: 'admin_sick_docs', request_id: String(richiesta(mariadb, 'Bellandi', 'malattia')), protocol_number: ' INPS-999 ' }, cert);
    const b = await nodePost(n, { action: 'admin_sick_docs', request_id: String(richiesta(postgres, 'Bellandi', 'malattia')), protocol_number: ' INPS-999 ' }, cert);
    expect(a).toEqual({ redirect: '/admin/leave-requests.php?message=sick_docs' });
    expect(b).toEqual(a);
    expect(riga(postgres, richiesta(postgres, 'Bellandi', 'malattia'))).toEqual(riga(mariadb, richiesta(mariadb, 'Bellandi', 'malattia')));

    const fp = await p.b.chiama(`${PAGINA}?download_cert=${richiesta(mariadb, 'Bellandi', 'malattia')}`);
    const fn = await n.b.chiama(`/api/v1${API}?download_cert=${richiesta(postgres, 'Bellandi', 'malattia')}`);
    expect(fn.status).toBe(200);
    expect(fn.testo).toBe(fp.testo);
  });

  it('nessun documento: errore', async () => {
    const [p, n] = await Promise.all([phpEntra('hr.alfa'), nodeEntra('hr.alfa')]);
    const a = await phpPost(p, { action: 'admin_sick_docs', request_id: String(richiesta(mariadb, 'Bellandi', 'malattia')), protocol_number: '' });
    const b = await nodePost(n, { action: 'admin_sick_docs', request_id: String(richiesta(postgres, 'Bellandi', 'malattia')), protocol_number: '' });
    expect(b.V.error).toBe(decodifica(/alert alert-error">([^<]*)</.exec(a.html!)?.[1] ?? ''));
  });

  it('certificato non richiesto e di nuovo obbligatorio', async () => {
    const [p, n] = await Promise.all([phpEntra('hr.alfa'), nodeEntra('hr.alfa')]);
    for (const waived of ['1', '0']) {
      const a = await phpPost(p, { action: 'admin_waive_cert', waived, request_id: String(richiesta(mariadb, 'Bellandi', 'malattia')) });
      const b = await nodePost(n, { action: 'admin_waive_cert', waived, request_id: String(richiesta(postgres, 'Bellandi', 'malattia')) });
      expect(b).toEqual(a);
      expect(riga(postgres, richiesta(postgres, 'Bellandi', 'malattia'))).toEqual(riga(mariadb, richiesta(mariadb, 'Bellandi', 'malattia')));
    }
  });

  it('download di una richiesta di un\'altra azienda: negato', async () => {
    const n = await nodeEntra('hr.alfa');
    const r = await n.b.chiama(`/api/v1${API}?download_attachment=${richiesta(postgres, 'Morselli', 'malattia')}`);
    expect(r.status).toBe(403);
  });
});

describe('inserimento manuale', () => {
  it('un dipendente: gia\' approvata', async () => {
    const [p, n] = await Promise.all([phpEntra('hr.alfa'), nodeEntra('hr.alfa')]);
    const emp = (fn: typeof mariadb) => fn(`SELECT id FROM employees WHERE last_name = 'Ferraresi'`)[0].id;
    const campi = { action: 'admin_create', leave_type: 'ferie', start_date: '2026-12-14', end_date: '2026-12-15', is_full_day: 'on', reason: '', notes: ' nota ' };
    const a = await phpPost(p, { ...campi, employee_id: emp(mariadb) });
    const b = await nodePost(n, { ...campi, employee_id: emp(postgres) });
    expect(a).toEqual({ redirect: '/admin/leave-requests.php?status=approved&message=Create+1+richieste' });
    expect(b).toEqual(a);
    const r = (fn: typeof mariadb) => riga(fn, Number(fn(`SELECT MAX(id) AS id FROM leave_requests`)[0].id));
    expect(r(postgres)).toEqual(r(mariadb));
  });

  it('chiusura a tutto un reparto, con sovrapposizioni saltate', async () => {
    const [p, n] = await Promise.all([phpEntra('hr.alfa'), nodeEntra('hr.alfa')]);
    const campi = { action: 'admin_create', leave_type: 'chiusura', start_date: '2026-12-14', end_date: '2026-12-14', is_full_day: 'on', reason: 'Inventario', apply_all: '1' };
    const a = await phpPost(p, { ...campi, target_department_id: reparto(mariadb, 'Commerciale') });
    const b = await nodePost(n, { ...campi, target_department_id: reparto(postgres, 'Commerciale') });
    expect(b).toEqual(a);
    const chiusure = (fn: typeof mariadb) => fn(`SELECT e.last_name, lr.status FROM leave_requests lr JOIN employees e ON e.id = lr.employee_id WHERE lr.leave_type = 'chiusura' ORDER BY e.last_name`);
    expect(chiusure(postgres)).toEqual(chiusure(mariadb));
  });

  it('nessun dipendente: errore', async () => {
    const [p, n] = await Promise.all([phpEntra('hr.alfa'), nodeEntra('hr.alfa')]);
    const campi = { action: 'admin_create', leave_type: 'ferie', start_date: '2026-12-14', end_date: '2026-12-14', is_full_day: 'on', reason: 'x' };
    const a = await phpPost(p, campi);
    const b = await nodePost(n, campi);
    expect(b.V.error).toBe(decodifica(/alert alert-error">([^<]*)</.exec(a.html!)?.[1] ?? ''));
  });
});
