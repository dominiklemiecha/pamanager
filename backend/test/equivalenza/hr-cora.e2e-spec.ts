import { postgres, ripristina } from './ambiente';
import { Node } from './clienti';

/**
 * Vista Cora dell'HR (solo nuovo stack, nessun corrispettivo nel PHP):
 * dati della dashboard presi dal database, azioni rapide e permessi.
 */
const PW = 'Prova!Cora2026';
async function entra(u: string) { const n = new Node(); await n.login(u, PW); return n; }
const conta = (sql: string) => Number(postgres(sql)[0].n);

beforeAll(() => ripristina());

describe('dashboard', () => {
  it('presenze, richieste in attesa e settimana dai dati dell\'azienda', async () => {
    const n = await entra('hr.alfa');
    const { status, json: d } = await n.get('/hr/home');
    expect(status).toBe(200);
    expect(d.presenze.attivi).toBe(conta(`SELECT COUNT(*) AS n FROM employees WHERE company_id = 1 AND is_active = true`));
    expect(d.inAttesa).toBe(conta(`SELECT COUNT(*) AS n FROM leave_requests WHERE company_id = 1 AND status = 'pending'`));
    expect(d.richieste.length).toBe(Math.min(3, d.inAttesa));
    expect(d.settimana.giorni).toHaveLength(7);
    expect(d.settimana.persone).toHaveLength(d.presenze.attivi);
    // niente dati di altre aziende
    const beta = postgres(`SELECT first_name FROM employees WHERE company_id = 2`).map((r) => r.first_name);
    expect(d.settimana.persone.some((p: any) => beta.includes(p.nome) && !postgres(`SELECT 1 FROM employees WHERE company_id = 1 AND first_name = '${p.nome}'`).length)).toBe(false);
    expect(JSON.stringify(d)).not.toMatch(/password_hash|nfc_uid|fiscal_code|iban/);
  });

  it('settimana precedente', async () => {
    const n = await entra('hr.alfa');
    const d = (await n.get('/hr/home')).json;
    const p = (await n.get(`/hr/settimana?w=${d.settimana.prima}`)).json;
    expect(p.fine < d.settimana.inizio).toBe(true);
  });

  it('cornice con campanella e flag della vista', async () => {
    const n = await entra('hr.alfa');
    const c = (await n.get('/hr/cornice')).json;
    expect(typeof c.coraHr).toBe('boolean');
    expect(c.notificheNonLette).toBe(conta(`SELECT COUNT(*) AS n FROM notifications WHERE company_id = 1 AND recipient_type = 'admin'
      AND recipient_id = (SELECT id FROM users WHERE username = 'hr.alfa') AND is_read = false`));
  });
});

describe('azioni rapide', () => {
  it('approva e rifiuta una richiesta dell\'azienda', async () => {
    const n = await entra('hr.alfa');
    const [a, b] = postgres(`SELECT id FROM leave_requests WHERE company_id = 1 AND status = 'pending' AND leave_type <> 'malattia' ORDER BY id LIMIT 2`).map((r) => r.id);
    expect((await n.post(`/hr/richieste/${a}/approva`, {})).status).toBe(200);
    expect(postgres(`SELECT status FROM leave_requests WHERE id = ${a}`)[0].status).toBe('approved');
    expect((await n.post(`/hr/richieste/${b}/rifiuta`, { motivo: 'Periodo pieno' })).status).toBe(200);
    expect(postgres(`SELECT status, rejection_reason FROM leave_requests WHERE id = ${b}`)[0]).toEqual({ status: 'rejected', rejection_reason: 'Periodo pieno' });
  });

  it('richiesta di un\'altra azienda: rifiutata, nessuna modifica', async () => {
    const n = await entra('hr.alfa');
    const altra = postgres(`SELECT id, status FROM leave_requests WHERE company_id = 2 ORDER BY id LIMIT 1`)[0];
    if (!altra) return;
    expect((await n.post(`/hr/richieste/${altra.id}/approva`, {})).status).toBe(400);
    expect(postgres(`SELECT status FROM leave_requests WHERE id = ${altra.id}`)[0].status).toBe(altra.status);
  });

  it('messaggio a un collega: apre la chat diretta, solo dipendenti dell\'azienda', async () => {
    const n = await entra('hr.alfa');
    const giulia = postgres(`SELECT id FROM employees WHERE username = 'giulia.ferraresi'`)[0].id;
    const r = await n.post(`/hr/chat/${giulia}`, {});
    expect(r.status).toBe(200);
    expect(Number(r.json.conversazione)).toBeGreaterThan(0);
    const altro = postgres(`SELECT id FROM employees WHERE company_id = 2 ORDER BY id LIMIT 1`)[0].id;
    expect((await n.post(`/hr/chat/${altro}`, {})).status).toBe(404);
  });
});

it('solo l\'HR', async () => {
  for (const u of ['studio.consulente', 'formatore.alfa', 'giulia.ferraresi']) {
    const n = await entra(u);
    expect((await n.get('/hr/home')).status).toBe(403);
  }
});

describe('pagine della vista Cora', () => {
  const PERCORSI = ['/hr/dipendenti', '/hr/assunzioni', '/hr/presenze', '/hr/reparti', '/hr/ferie', '/hr/comunicazioni', '/hr/documenti-aziendali',
    '/hr/accessi?ruolo=consulente_lavoro', '/hr/accessi?ruolo=formatore', '/hr/buoni-pasto', '/hr/richieste-reset'];

  it("rispondono all'HR senza dati segreti", async () => {
    const n = await entra('hr.alfa');
    for (const p of PERCORSI) {
      const r = await n.get(p);
      expect([p, r.status]).toEqual([p, 200]);
      expect(JSON.stringify(r.json)).not.toMatch(/password_hash|mfa_secret|nfc_uid|iban|file_path|attachment_path/);
    }
  });

  it("solo dati dell'azienda attiva", async () => {
    const n = await entra('hr.alfa');
    const dip = (await n.get('/hr/dipendenti')).json.dipendenti.map((x: any) => x.id).sort();
    expect(dip).toEqual(postgres('SELECT id FROM employees WHERE company_id = 1 ORDER BY id').map((r) => Number(r.id)).sort());
    const rep = (await n.get('/hr/reparti')).json.reparti.map((x: any) => x.id).sort();
    expect(rep).toEqual(postgres('SELECT id FROM departments WHERE company_id = 1 ORDER BY id').map((r) => Number(r.id)).sort());
    const ferie = (await n.get('/hr/ferie')).json.richieste.map((x: any) => x.id);
    const altre = postgres('SELECT id FROM leave_requests WHERE company_id <> 1').map((r) => Number(r.id));
    expect(ferie.some((id: number) => altre.includes(id))).toBe(false);
  });

  it('vietate a consulenti, formatori e dipendenti', async () => {
    for (const u of ['studio.consulente', 'formatore.alfa', 'giulia.ferraresi']) {
      const n = await entra(u);
      for (const p of PERCORSI) expect([u, p, (await n.get(p)).status]).toEqual([u, p, 403]);
    }
  });
});

