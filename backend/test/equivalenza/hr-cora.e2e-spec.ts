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
