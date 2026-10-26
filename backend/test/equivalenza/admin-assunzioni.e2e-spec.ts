import { mariadb, postgres, ripristina } from './ambiente';
import { Node, Php } from './clienti';

/** Richieste di assunzione dell'HR (admin/hire-requests.php). */
const PW = 'Prova!Cora2026';
const PAGINA = '/admin/hire-requests.php';
const API = '/pagine/admin/hire-requests';
async function phpEntra(u: string) { const p = new Php(); await p.login(u, PW); return p; }
async function nodeEntra(u: string) { const n = new Node(); await n.login(u, PW); return n; }
const testo = (s: string) => s.replace(/<[^>]+>/g, '').replace(/&#0?39;|&apos;/g, "'").replace(/&quot;/g, '"').replace(/&amp;/g, '&').replace(/\s+/g, ' ').trim();

/** Codice fiscale sintetico con carattere di controllo corretto. */
function cf(base15: string) {
  const D: Record<string, number> = { 0: 1, 1: 0, 2: 5, 3: 7, 4: 9, 5: 13, 6: 15, 7: 17, 8: 19, 9: 21, A: 1, B: 0, C: 5, D: 7, E: 9, F: 13, G: 15, H: 17, I: 19, J: 21, K: 2, L: 4, M: 18, N: 20, O: 11, P: 3, Q: 6, R: 8, S: 12, T: 14, U: 16, V: 10, W: 22, X: 25, Y: 24, Z: 23 };
  let s = 0;
  for (let i = 0; i < 15; i++) s += i % 2 === 0 ? D[base15[i]] : /\d/.test(base15[i]) ? Number(base15[i]) : base15.charCodeAt(i) - 65;
  return base15 + String.fromCharCode(65 + (s % 26));
}
const PDF = Buffer.from('%PDF-1.4\n1 0 obj<<>>endobj\ntrailer<<>>\n%%EOF\n');
type Allegato = { campo: string; nome: string; dati: Buffer; tipo: string };

async function phpPost(p: Php, campi: Array<[string, string]>, file: Allegato[] = []) {
  const html = (await p.b.chiama(PAGINA)).testo;
  const fd = new FormData();
  fd.append('csrf_token', /name="csrf_token" value="([^"]+)"/.exec(html)?.[1] ?? '');
  for (const [k, v] of campi) fd.append(k, v);
  for (const f of file) fd.append(f.campo, new Blob([new Uint8Array(f.dati)], { type: f.tipo }), f.nome);
  const r = await p.b.chiama(PAGINA, { metodo: 'POST', corpo: fd as any });
  if (r.location) { const u = new URL(r.location, `http://x${PAGINA}`); return { redirect: u.pathname + u.search, html: '' }; }
  return { redirect: null, html: r.testo };
}
async function nodePost(n: Node, campi: Array<[string, string]>, file: Allegato[] = []) {
  const fd = new FormData();
  for (const [k, v] of campi) fd.append(k, v);
  for (const f of file) fd.append(f.campo, new Blob([new Uint8Array(f.dati)], { type: f.tipo }), f.nome);
  const r = await n.b.chiama(PAGINA, { metodo: 'POST', corpo: fd as any, header: { 'X-CSRF-Token': n.b.valoreCookie('cora_csrf')! } });
  if (r.location) { const u = new URL(r.location, `http://x${PAGINA}`); return { redirect: u.pathname + u.search, V: null as any }; }
  return { redirect: null, V: JSON.parse(r.testo).V };
}
/** Errore mostrato dal PHP (alert rosso) e dal Node (variabile error o _GET). */
const erroreHtml = (html: string) => testo(/<div class="alert alert-error"[^>]*>([\s\S]*?)<\/div>/.exec(html)?.[1] ?? '');
const erroreV = (V: any) => V.error || V._GET?.err || V._GET?.bypass_err || V._GET?.del_err || '';

const richiesta = (fn: typeof mariadb, cognome: string) => fn(
  `SELECT company_id, status, employer_name, employee_first_name, employee_last_name, employee_birth_date, fiscal_code, contract_indeterminato, contract_determinato,
          start_date, end_date, role_description, weekly_hours, work_days, workplace, iban, employee_email, generated_username, send_count, updated_after_send,
          assigned_consulente_user_id IS NOT NULL AS consulente, employee_id IS NOT NULL AS dipendente, rejection_reason
     FROM hire_requests WHERE employee_last_name = '${cognome}'`)
  .map((r) => Object.fromEntries(Object.entries(r).map(([k, v]) => [k, v === 't' ? '1' : v === 'f' ? '0' : v === 'NULL' ? null : k === 'weekly_hours' && v !== null ? String(Number(v)) : v])))[0];
const idRichiesta = (fn: typeof mariadb, cognome: string) => fn(`SELECT id FROM hire_requests WHERE employee_last_name = '${cognome}'`)[0]?.id;
const dipendente = (fn: typeof mariadb, cognome: string) => fn(
  `SELECT username, fiscal_code, email, hire_date, position, working_days, hours_per_day, must_change_password, company_id,
          (SELECT COUNT(*) FROM documents d WHERE d.employee_id = e.id) AS documenti FROM employees e WHERE last_name = '${cognome}'`)
  .map((r) => Object.fromEntries(Object.entries(r).map(([k, v]) => [k, v === 't' ? '1' : v === 'f' ? '0' : v === 'NULL' ? null : k === 'hours_per_day' && v !== null ? String(Number(v)) : v])))[0];
/** Esito positivo del Node: redirect senza password, oppure pagina con la password temporanea a video. */
function esitoNode(b: { redirect: string | null; V: any }, flag: string) {
  if (b.redirect) {
    expect(b.redirect).toContain(`${flag}=1`);
    expect(b.redirect).not.toMatch(/pwd=|email_err=/);
  } else {
    expect(b.V._GET).toMatchObject({ [flag]: '1' });
    expect(b.V._GET.email_err).toBeTruthy();
  }
}
const notifiche = (fn: typeof mariadb, tipo: string) => Number(fn(`SELECT COUNT(*) AS n FROM notifications WHERE type = '${tipo}'`)[0].n);

const base = (cognome: string, extra: Array<[string, string]> = []): Array<[string, string]> => ([
  ['employee_first_name', 'Prova'], ['employee_last_name', cognome], ['employer_name', 'Alfa Servizi S.r.l.'], ['contract_indeterminato', '1'],
  ['start_date', '2026-11-02'], ['weekly_hours', '38,5'], ['work_days[]', 'mon'], ['work_days[]', 'tue'], ['work_days[]', 'wed'], ['role_description', 'Magazziniere'],
  ['workplace', 'Bologna']] as Array<[string, string]>).filter(([k]) => k.endsWith('[]') || !extra.some(([e]) => e === k)).concat(extra);

beforeAll(() => ripristina());

describe('elenco e dettaglio', () => {
  it('stesse richieste in elenco', async () => {
    const [p, n] = await Promise.all([phpEntra('hr.beta'), nodeEntra('hr.beta')]);
    const html = (await p.b.chiama(PAGINA)).testo;
    const V = (await n.get(API)).json.V;
    expect(V.__rows.map((r: any) => r.employee_last_name)).toEqual(['Zanichelli']);
    expect(html).toContain('Zanichelli');
  });

  it("richiesta di un'altra azienda non apribile", async () => {
    const [p, n] = await Promise.all([phpEntra('hr.alfa'), nodeEntra('hr.alfa')]);
    const html = (await p.b.chiama(`${PAGINA}?id=1`)).testo;
    expect(html).toContain('Richiesta non trovata');
    expect((await n.get(`${API}?id=1`)).json.V.__hr).toBeNull();
  });

  it('correzione: testo libero nell\'indirizzo non mostrato', async () => {
    const n = await nodeEntra('hr.beta');
    const V = (await n.get(`${API}?id=1&err=<b>falso</b>&pwd=Segreta1!&email_err=x`)).json.V;
    expect(V._GET.err).toBeUndefined();
    expect(V._GET.pwd).toBeUndefined();
    expect(V._GET.email_err).toBeUndefined();
  });
});

describe('richiesta al consulente', () => {
  it('bozza: stessi dati salvati', async () => {
    const [p, n] = await Promise.all([phpEntra('hr.alfa'), nodeEntra('hr.alfa')]);
    const campi = base('Bozzetti', [['action', 'create'], ['submit_mode', 'draft'], ['iban', 'it60 x054 2811 1010 0000 0123 456']]);
    const a = await phpPost(p, campi);
    const b = await nodePost(n, campi);
    expect(a.redirect).toBe(`${PAGINA}?id=${idRichiesta(mariadb, 'Bozzetti')}&saved=1`);
    expect(b.redirect).toBe(`${PAGINA}?id=${idRichiesta(postgres, 'Bozzetti')}&saved=1`);
    expect(richiesta(postgres, 'Bozzetti')).toEqual(richiesta(mariadb, 'Bozzetti'));
  });

  it.each([
    ['senza tipologia', [['contract_indeterminato', '']]],
    ['senza ore', [['weekly_hours', '0']]],
    ['determinato senza fine', [['contract_determinato', '1']]],
    ['codice fiscale errato', [['fiscal_code', 'ABC']]],
  ])('stessi errori di invio (%s)', async (_n, extra) => {
    const [p, n] = await Promise.all([phpEntra('hr.alfa'), nodeEntra('hr.alfa')]);
    const campi = [...base('Errori').filter(([k]) => !(extra as Array<[string, string]>).some(([e]) => e === k)), ...(extra as Array<[string, string]>), ['action', 'create'], ['submit_mode', 'send']] as Array<[string, string]>;
    const a = await phpPost(p, campi);
    const b = await nodePost(n, campi);
    expect(erroreV(b.V)).toBe(erroreHtml(a.html));
    expect(b.V.action).toBe('new');
  });

  it('invio: richiesta al consulente con notifica', async () => {
    const [p, n] = await Promise.all([phpEntra('hr.alfa'), nodeEntra('hr.alfa')]);
    const campi = base('Inviati', [['action', 'create'], ['submit_mode', 'send']]);
    const a = await phpPost(p, campi);
    const b = await nodePost(n, campi);
    expect(a.redirect).toBe(`${PAGINA}?id=${idRichiesta(mariadb, 'Inviati')}&created=1&notified=1`);
    expect(b.redirect).toBe(`${PAGINA}?id=${idRichiesta(postgres, 'Inviati')}&created=1&notified=1`);
    expect(richiesta(postgres, 'Inviati')).toEqual(richiesta(mariadb, 'Inviati'));
    expect(notifiche(postgres, 'hire_request_new')).toBe(notifiche(mariadb, 'hire_request_new'));
  });

  it('modifica dopo l\'invio e re-invio', async () => {
    const [p, n] = await Promise.all([phpEntra('hr.alfa'), nodeEntra('hr.alfa')]);
    const campi = (fn: typeof mariadb) => base('Inviati', [['action', 'update'], ['id', String(idRichiesta(fn, 'Inviati'))], ['weekly_hours', '40'], ['submit_mode', 'save']]);
    expect((await phpPost(p, campi(mariadb))).redirect).toContain('saved=1');
    expect((await nodePost(n, campi(postgres))).redirect).toContain('saved=1');
    expect(richiesta(postgres, 'Inviati')).toEqual(richiesta(mariadb, 'Inviati'));
    const invio = (fn: typeof mariadb): Array<[string, string]> => [['action', 'send'], ['id', String(idRichiesta(fn, 'Inviati'))]];
    expect((await phpPost(p, invio(mariadb))).redirect).toContain('sent=1&notified=1');
    expect((await nodePost(n, invio(postgres))).redirect).toContain('sent=1&notified=1');
    expect(richiesta(postgres, 'Inviati')).toEqual(richiesta(mariadb, 'Inviati'));
    expect(notifiche(postgres, 'hire_request_updated')).toBe(notifiche(mariadb, 'hire_request_updated'));
  });

  it('allegato con contenuto diverso dal tipo: correzione, rifiutato', async () => {
    const n = await nodeEntra('hr.alfa');
    const b = await nodePost(n, base('Falsi', [['action', 'create'], ['submit_mode', 'draft']]), [{ campo: 'id_doc[]', nome: 'doc.pdf', dati: Buffer.from('<?php echo 1;'), tipo: 'application/pdf' }]);
    expect(erroreV(b.V)).toContain('non valido');
    expect(idRichiesta(postgres, 'Falsi')).toBeUndefined();
  });
});

describe('prospetti e assunzione', () => {
  const completa = (fn: typeof mariadb, cognome: string, codice: string): Array<[string, string]> => [
    ...base(cognome), ['action', 'approve'], ['id', String(idRichiesta(fn, cognome))], ['fiscal_code', codice], ['employee_birth_date', '1990-03-15'],
    ['birth_state', 'Italia'], ['birth_city', 'Bologna'], ['residence_address', 'Via Prova 1'], ['residence_cap', '40100'], ['residence_city', 'Bologna'],
    ['residence_province', 'BO'], ['marital_status', 'coniugato'], ['education_level', 'diploma'], ['employee_email', `${cognome.toLowerCase()}@esempio.test`],
    ['department_id', ''], ['position', 'Operatore'], ['monthly_salary', '1.500,00'.replace('.', '')]];
  const documenti: Allegato[] = [{ campo: 'id_doc[]', nome: 'documento.pdf', dati: PDF, tipo: 'application/pdf' }, { campo: 'fiscal_code_doc[]', nome: 'cf.pdf', dati: PDF, tipo: 'application/pdf' }];

  it('approvazione: dipendente creato con i documenti, stessi dati', async () => {
    const [p, n] = await Promise.all([phpEntra('hr.alfa'), nodeEntra('hr.alfa')]);
    for (const fn of [mariadb, postgres]) fn(`UPDATE hire_requests SET status = 'prospects_review' WHERE employee_last_name = 'Inviati'`);
    const codice = cf('NVTPRV90C15A944');
    const a = await phpPost(p, completa(mariadb, 'Inviati', codice), documenti);
    const b = await nodePost(n, completa(postgres, 'Inviati', codice), documenti);
    // Senza SMTP le credenziali non partono: il PHP mette la password nell'indirizzo
    expect(a.redirect).toMatch(/approved=1&email_err=.*&pwd=/);
    // correzione: nel Node la password non e' mai nell'indirizzo (con SMTP locale l'email parte)
    esitoNode(b, 'approved');
    expect(richiesta(postgres, 'Inviati')).toEqual(richiesta(mariadb, 'Inviati'));
    expect(dipendente(postgres, 'Inviati')).toEqual(dipendente(mariadb, 'Inviati'));
    expect(notifiche(postgres, 'hire_approved')).toBe(notifiche(mariadb, 'hire_approved'));
  });

  it('approvazione con dati mancanti: stesso errore', async () => {
    const [p, n] = await Promise.all([phpEntra('hr.alfa'), nodeEntra('hr.alfa')]);
    for (const fn of [mariadb, postgres]) fn(`UPDATE hire_requests SET status = 'prospects_review' WHERE employee_last_name = 'Bozzetti'`);
    const campi = (fn: typeof mariadb) => completa(fn, 'Bozzetti', '').filter(([k]) => k !== 'residence_cap');
    const a = await phpPost(p, campi(mariadb));
    const b = await nodePost(n, campi(postgres));
    expect(a.redirect).toContain('err=');
    expect(testo(erroreV(b.V))).toBe(decodeURIComponent(/err=([^&]*)/.exec(a.redirect!)![1].replace(/\+/g, ' ')));
  });

  it('rifiuto con motivazione', async () => {
    const [p, n] = await Promise.all([phpEntra('hr.alfa'), nodeEntra('hr.alfa')]);
    const campi = (fn: typeof mariadb, motivo: string): Array<[string, string]> => [['action', 'reject'], ['id', String(idRichiesta(fn, 'Bozzetti'))], ['reason', motivo]];
    const a0 = await phpPost(p, campi(mariadb, ' '));
    const b0 = await nodePost(n, campi(postgres, ' '));
    expect(erroreV(b0.V)).toBe(decodeURIComponent(/err=([^&]*)/.exec(a0.redirect!)![1].replace(/\+/g, ' ')));
    expect((await phpPost(p, campi(mariadb, 'Costo troppo alto'))).redirect).toContain('rejected=1');
    expect((await nodePost(n, campi(postgres, 'Costo troppo alto'))).redirect).toContain('rejected=1');
    expect(richiesta(postgres, 'Bozzetti')).toEqual(richiesta(mariadb, 'Bozzetti'));
  });

  it('assunzione diretta', async () => {
    const [p, n] = await Promise.all([phpEntra('hr.alfa'), nodeEntra('hr.alfa')]);
    const campi: Array<[string, string]> = [...base('Diretti'), ['action', 'hire_direct'], ['fiscal_code', cf('DRTPRV88A41A944')], ['employee_email', 'diretti@esempio.test'],
      ['username', 'prova.diretti'], ['password', ''], ['department_id', ''], ['position', '']];
    const a = await phpPost(p, campi, documenti.slice(0, 1));
    const b = await nodePost(n, campi, documenti.slice(0, 1));
    expect(a.redirect).toMatch(/hired=1&email_err=/);
    esitoNode(b, 'hired');
    expect(richiesta(postgres, 'Diretti')).toEqual(richiesta(mariadb, 'Diretti'));
    expect(dipendente(postgres, 'Diretti')).toEqual(dipendente(mariadb, 'Diretti'));
  });

  it('assunzione diretta con codice fiscale gia usato: stesso errore', async () => {
    const [p, n] = await Promise.all([phpEntra('hr.alfa'), nodeEntra('hr.alfa')]);
    const campi: Array<[string, string]> = [...base('Doppi'), ['action', 'hire_direct'], ['fiscal_code', cf('DRTPRV88A41A944')], ['employee_email', 'doppi@esempio.test']];
    const a = await phpPost(p, campi);
    const b = await nodePost(n, campi);
    expect(erroreV(b.V)).toBe(erroreHtml(a.html));
    expect(b.V.action).toBe('direct');
  });

  it('bypass su una richiesta avviata', async () => {
    const [p, n] = await Promise.all([phpEntra('hr.alfa'), nodeEntra('hr.alfa')]);
    const crea = base('Bypass', [['action', 'create'], ['submit_mode', 'send']]);
    await phpPost(p, crea);
    await nodePost(n, crea);
    const campi = (fn: typeof mariadb): Array<[string, string]> => [['action', 'hire_direct_existing'], ['id', String(idRichiesta(fn, 'Bypass'))],
      ['fiscal_code', cf('BYPPRV85T10A944')], ['employee_email', 'bypass@esempio.test'], ['department_id', ''], ['position', 'Autista']];
    const a = await phpPost(p, campi(mariadb));
    const b = await nodePost(n, campi(postgres));
    expect(a.redirect).toMatch(/hired=1/);
    esitoNode(b, 'hired');
    expect(richiesta(postgres, 'Bypass')).toEqual(richiesta(mariadb, 'Bypass'));
    expect(dipendente(postgres, 'Bypass')).toEqual(dipendente(mariadb, 'Bypass'));
    expect(notifiche(postgres, 'hire_direct')).toBe(notifiche(mariadb, 'hire_direct'));
  });
});

describe('file ed eliminazione', () => {
  it('file della pratica con il tipo dal contenuto', async () => {
    const n = await nodeEntra('hr.alfa');
    const id = idRichiesta(postgres, 'Diretti');
    const f = postgres(`SELECT id FROM hire_request_files WHERE hire_request_id = ${id} LIMIT 1`)[0].id;
    const r = await n.b.scarica(`${PAGINA}?action=file&id=${id}&file_id=${f}`);
    expect(r.status).toBe(200);
    expect(r.tipo).toContain('application/pdf');
    expect(r.dati.equals(PDF)).toBe(true);
  });

  it('eliminazione', async () => {
    const [p, n] = await Promise.all([phpEntra('hr.alfa'), nodeEntra('hr.alfa')]);
    const campi = (fn: typeof mariadb): Array<[string, string]> => [['action', 'delete'], ['id', String(idRichiesta(fn, 'Bozzetti'))]];
    expect((await phpPost(p, campi(mariadb))).redirect).toBe(`${PAGINA}?deleted=1`);
    expect((await nodePost(n, campi(postgres))).redirect).toBe(`${PAGINA}?deleted=1`);
    expect(idRichiesta(postgres, 'Bozzetti')).toBeUndefined();
  });

  it("eliminazione di una richiesta di un'altra azienda: rifiutata", async () => {
    const n = await nodeEntra('hr.alfa');
    const b = await nodePost(n, [['action', 'delete'], ['id', '1']]);
    expect(erroreV(b.V)).toBe('Richiesta non trovata');
    expect(postgres('SELECT COUNT(*) AS n FROM hire_requests WHERE id = 1')[0].n).toBe('1');
  });
});
