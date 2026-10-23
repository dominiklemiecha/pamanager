import { mariadb, postgres, ripristina } from './ambiente';
import { Node, Php } from './clienti';

/**
 * Configurazione dell'HR: orario lavorativo, timbrature, buoni pasto,
 * apertura porta e relativo log (solo admin globale).
 */
const PW = 'Prova!Cora2026';

async function phpEntra(u: string) { const p = new Php(); await p.login(u, PW); return p; }
async function nodeEntra(u: string) { const n = new Node(); await n.login(u, PW); return n; }
const decodifica = (s: string) => s.replace(/&#0?39;/g, "'").replace(/&quot;/g, '"').replace(/&amp;/g, '&').replace(/&lt;/g, '<').replace(/&gt;/g, '>');

/** POST multipart (campi ripetibili come array) sul PHP: messaggio o errore della pagina ridisegnata. */
async function phpPost(p: Php, pagina: string, campi: Array<[string, string]>) {
  const html = (await p.b.chiama(pagina)).testo;
  const fd = new FormData();
  fd.append('csrf_token', /name="csrf_token" value="([^"]+)"/.exec(html)?.[1] ?? '');
  for (const [k, v] of campi) fd.append(k, v);
  const r = await p.b.chiama(pagina, { metodo: 'POST', corpo: fd as any });
  return {
    message: decodifica(/alert alert-success">([^<]*)</.exec(r.testo)?.[1] ?? ''),
    error: decodifica(/alert alert-error">([^<]*)</.exec(r.testo)?.[1] ?? ''),
  };
}
async function nodePost(n: Node, api: string, campi: Array<[string, string]>) {
  const fd = new FormData();
  for (const [k, v] of campi) fd.append(k, v);
  const r = await n.b.chiama('/api/v1' + api, { metodo: 'POST', corpo: fd as any, header: { 'X-CSRF-Token': n.b.valoreCookie('cora_csrf')! } });
  const j = JSON.parse(r.testo);
  return { message: j.V?.message ?? '', error: j.V?.error ?? '' };
}

const azienda = (fn: typeof mariadb, campi: string) => fn(`SELECT ${campi} FROM companies WHERE id = 1`)
  .map((r) => Object.fromEntries(Object.entries(r).map(([k, v]) => [k, v === 't' ? '1' : v === 'f' ? '0' : v === 'NULL' ? null : v])))[0];
const num = (r: Record<string, any>) => Object.fromEntries(Object.entries(r).map(([k, v]) => [k, v !== null && /^\d+(\.\d+)?$/.test(v) ? Number(v) : v]));

beforeAll(() => ripristina());

describe('orario lavorativo', () => {
  const CAMPI = 'working_days, hours_per_day, default_ccnl_id, smart_working_days, lunch_break_start, lunch_break_end, slug';

  it('apertura: stesse impostazioni', async () => {
    const n = await nodeEntra('hr.alfa');
    const V = (await n.get('/pagine/admin/work-schedule')).json.V;
    const r = azienda(mariadb, CAMPI);
    expect(V.defaults.days.join(',')).toBe(r.working_days);
    expect(V.defaults.hours).toBe(Number(r.hours_per_day));
    expect(V.ccnls.map((x: any) => x.name)).toEqual(mariadb(`SELECT name FROM ccnl_templates WHERE company_id IS NULL OR company_id = 1 ORDER BY is_system DESC, name ASC`).map((x) => x.name));
  });

  it('salvataggio', async () => {
    const [p, n] = await Promise.all([phpEntra('hr.alfa'), nodeEntra('hr.alfa')]);
    const ccnl = (fn: typeof mariadb) => fn(`SELECT id FROM ccnl_templates WHERE company_id IS NULL ORDER BY name LIMIT 1`)[0].id;
    const campi = (fn: typeof mariadb): Array<[string, string]> => [
      ['working_days[]', 'mon'], ['working_days[]', 'tue'], ['working_days[]', 'wed'], ['working_days[]', 'thu'], ['working_days[]', 'sat'],
      ['hours_per_day', '7,5'], ['default_ccnl_id', ccnl(fn)], ['smart_working_days[]', 'wed'], ['smart_working_days[]', 'xxx'],
      ['lunch_break_start', '13:00'], ['lunch_break_end', '14:00']];
    const a = await phpPost(p, '/admin/work-schedule.php', campi(mariadb));
    const b = await nodePost(n, '/pagine/admin/work-schedule', campi(postgres));
    expect(a).toEqual({ message: 'Impostazioni salvate.', error: '' });
    expect(b).toEqual(a);
    const norm = (r: any) => ({ ...num(r), default_ccnl_id: undefined, lunch_break_start: r.lunch_break_start?.slice(0, 5), lunch_break_end: r.lunch_break_end?.slice(0, 5) });
    expect(norm(azienda(postgres, CAMPI))).toEqual(norm(azienda(mariadb, CAMPI)));
  });

  it.each([
    ['nessun giorno', [['hours_per_day', '8']]],
    ['ore fuori intervallo', [['working_days[]', 'mon'], ['hours_per_day', '25']]],
    ['pausa a meta\'', [['working_days[]', 'mon'], ['hours_per_day', '8'], ['lunch_break_start', '13:00']]],
    ['pausa invertita', [['working_days[]', 'mon'], ['hours_per_day', '8'], ['lunch_break_start', '14:00'], ['lunch_break_end', '13:00']]],
  ])('errore: %s', async (_n, campi) => {
    const [p, n] = await Promise.all([phpEntra('hr.alfa'), nodeEntra('hr.alfa')]);
    const a = await phpPost(p, '/admin/work-schedule.php', campi as Array<[string, string]>);
    expect(a.error).not.toBe('');
    expect(await nodePost(n, '/pagine/admin/work-schedule', campi as Array<[string, string]>)).toEqual(a);
  });

  it('correzione: CCNL di un\'altra azienda rifiutato', async () => {
    postgres(`INSERT INTO ccnl_templates (company_id, code, name, ferie_days_year, permessi_hours_year, is_system) VALUES (2, 'BETA', 'CCNL Beta', 20, 30, false)`);
    const n = await nodeEntra('hr.alfa');
    const id = postgres(`SELECT id FROM ccnl_templates WHERE code = 'BETA'`)[0].id;
    const b = await nodePost(n, '/pagine/admin/work-schedule', [['working_days[]', 'mon'], ['hours_per_day', '8'], ['default_ccnl_id', id]]);
    expect(b.error).toBe('CCNL non valido.');
  });
});

describe('timbrature e buoni pasto', () => {
  it('timbrature: disattivazione e identificativo', async () => {
    const [p, n] = await Promise.all([phpEntra('hr.alfa'), nodeEntra('hr.alfa')]);
    const a = await phpPost(p, '/admin/punch-settings.php', [['nfc_slug', '  Alfa Uffici!! ']]);
    const b = await nodePost(n, '/pagine/admin/punch-settings', [['nfc_slug', '  Alfa Uffici!! ']]);
    expect(b).toEqual(a);
    expect(azienda(postgres, 'slug, timbratura_enabled')).toEqual(azienda(mariadb, 'slug, timbratura_enabled'));
    const V = (await n.get('/pagine/admin/punch-settings')).json.V;
    expect(V.punchUrl).toMatch(/\/punch\.php\?c=alfa-uffici$/);
  });

  it('timbrature: identificativo di un\'altra azienda', async () => {
    const [p, n] = await Promise.all([phpEntra('hr.alfa'), nodeEntra('hr.alfa')]);
    const slug = mariadb(`SELECT slug FROM companies WHERE id = 2`)[0].slug;
    const a = await phpPost(p, '/admin/punch-settings.php', [['timbratura_enabled', '1'], ['nfc_slug', slug]]);
    expect(a.error).not.toBe('');
    expect(await nodePost(n, '/pagine/admin/punch-settings', [['timbratura_enabled', '1'], ['nfc_slug', slug]])).toEqual(a);
  });

  it('buoni pasto: salvataggio e soglia non valida', async () => {
    const [p, n] = await Promise.all([phpEntra('hr.alfa'), nodeEntra('hr.alfa')]);
    const campi: Array<[string, string]> = [['buoni_pasto_enabled', '1'], ['buoni_pasto_min_hours', '6,5'], ['buoni_pasto_sw_eligible', '1']];
    expect(await nodePost(n, '/pagine/admin/buoni-pasto', campi)).toEqual(await phpPost(p, '/admin/buoni-pasto.php', campi));
    const C = 'buoni_pasto_enabled, buoni_pasto_min_hours_enabled, buoni_pasto_min_hours, buoni_pasto_sw_eligible';
    expect(num(azienda(postgres, C))).toEqual(num(azienda(mariadb, C)));
    const errato: Array<[string, string]> = [['buoni_pasto_min_hours', '0']];
    expect(await nodePost(n, '/pagine/admin/buoni-pasto', errato)).toEqual(await phpPost(p, '/admin/buoni-pasto.php', errato));
  });
});

describe('apertura porta', () => {
  it('solo l\'admin globale', async () => {
    const n = await nodeEntra('hr.alfa');
    expect((await n.get('/pagine/admin/door-settings')).json).toEqual({ redirect: '/admin/' });
    expect((await n.get('/pagine/admin/door-log')).json).toEqual({ redirect: '/admin/' });
    const p = await phpEntra('hr.alfa');
    expect((await p.b.chiama('/admin/door-log.php')).location).toMatch(/\/admin\/$/);
  });

  it('log con filtri', async () => {
    const [p, n] = await Promise.all([phpEntra('connecteed'), nodeEntra('connecteed')]);
    for (const q of ['', '?esito=granted', '?esito=denied', '?date=2026-10-20', '?date=2026-10-21&esito=denied']) {
      const html = (await p.b.chiama('/admin/door-log.php' + q)).testo;
      const uidPhp = [...html.matchAll(/font-size: 12px; color: #64748b;">\s*([^<\s]+)\s*</g)].map((m) => m[1]);
      const V = (await n.get('/pagine/admin/door-log' + q)).json.V;
      expect(V.rows.map((r: any) => r.nfc_uid)).toEqual(uidPhp);
    }
  });
});
