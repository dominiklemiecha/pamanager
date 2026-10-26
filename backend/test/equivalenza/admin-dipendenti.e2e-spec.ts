import { mariadb, postgres, ripristina } from './ambiente';
import { Node, Php } from './clienti';

/** Dipendenti dell'HR (admin/employees.php). */
const PW = 'Prova!Cora2026';
const PAGINA = '/admin/employees.php';
const API = '/pagine/admin/employees';
async function phpEntra(u: string) { const p = new Php(); await p.login(u, PW); return p; }
async function nodeEntra(u: string) { const n = new Node(); await n.login(u, PW); return n; }
const testo = (s: string) => s.replace(/<[^>]+>/g, '').replace(/&#0?39;|&apos;/g, "'").replace(/&quot;/g, '"').replace(/&amp;/g, '&').replace(/&mdash;/g, '—').trim();

/** Codice fiscale sintetico con carattere di controllo corretto. */
function cf(base15: string) {
  const D: Record<string, number> = { 0: 1, 1: 0, 2: 5, 3: 7, 4: 9, 5: 13, 6: 15, 7: 17, 8: 19, 9: 21, A: 1, B: 0, C: 5, D: 7, E: 9, F: 13, G: 15, H: 17, I: 19, J: 21, K: 2, L: 4, M: 18, N: 20, O: 11, P: 3, Q: 6, R: 8, S: 12, T: 14, U: 16, V: 10, W: 22, X: 25, Y: 24, Z: 23 };
  let s = 0;
  for (let i = 0; i < 15; i++) s += i % 2 === 0 ? D[base15[i]] : /\d/.test(base15[i]) ? Number(base15[i]) : base15.charCodeAt(i) - 65;
  return base15 + String.fromCharCode(65 + (s % 26));
}

async function phpPost(p: Php, query: string, campi: Array<[string, string]>, file?: { campo: string; nome: string; dati: Buffer; tipo: string }) {
  const html = (await p.b.chiama(PAGINA)).testo;
  const fd = new FormData();
  fd.append('csrf_token', /name="csrf_token" value="([^"]+)"/.exec(html)?.[1] ?? '');
  for (const [k, v] of campi) fd.append(k, v);
  if (file) fd.append(file.campo, new Blob([new Uint8Array(file.dati)], { type: file.tipo }), file.nome);
  const r = await p.b.chiama(PAGINA + query, { metodo: 'POST', corpo: fd as any });
  if (r.location) { const u = new URL(r.location, `http://x${PAGINA}`); return { redirect: u.pathname + u.search }; }
  if (/^\s*\{/.test(r.testo)) return { json: JSON.parse(r.testo) };
  return {
    message: testo(/<div class="alert alert-success"[^>]*>([\s\S]*?)<\/div>/.exec(r.testo)?.[1] ?? ''),
    error: testo(/<div class="alert alert-(?:error|danger)"[^>]*>([\s\S]*?)<\/div>/.exec(r.testo)?.[1] ?? ''),
  };
}
async function nodePost(n: Node, query: string, campi: Array<[string, string]>, file?: { campo: string; nome: string; dati: Buffer; tipo: string }) {
  const fd = new FormData();
  for (const [k, v] of campi) fd.append(k, v);
  if (file) fd.append(file.campo, new Blob([new Uint8Array(file.dati)], { type: file.tipo }), file.nome);
  const r = await n.b.chiama(PAGINA + query, { metodo: 'POST', corpo: fd as any, header: { 'X-CSRF-Token': n.b.valoreCookie('cora_csrf')! } });
  if (r.location) { const u = new URL(r.location, `http://x${PAGINA}`); return { redirect: u.pathname + u.search }; }
  const j = JSON.parse(r.testo);
  if (j.V) return { message: testo(j.V.message ?? ''), error: testo(j.V.error ?? '') };
  return { json: j };
}
const dip = (fn: typeof mariadb, cognome: string) => fn(
  `SELECT username, fiscal_code, first_name, last_name, email, phone, position, hire_date, probation_end_date, iban, ral_amount, department_id IS NOT NULL AS rep,
          working_days, hours_per_day, smart_working_days, buoni_pasto_excluded, is_active, must_change_password, nfc_uid FROM employees WHERE last_name = '${cognome}'`)
  .map((r) => Object.fromEntries(Object.entries(r).map(([k, v]) => [k, v === 't' ? '1' : v === 'f' ? '0' : v === 'NULL' ? null : (k === 'ral_amount' || k === 'hours_per_day') && v !== null ? String(Number(v)) : v])))[0];
const id = (fn: typeof mariadb, cognome: string) => fn(`SELECT id FROM employees WHERE last_name = '${cognome}'`)[0]?.id;
const rep = (fn: typeof mariadb) => fn(`SELECT id FROM departments WHERE company_id = 1 AND name = 'Commerciale'`)[0].id;

beforeAll(() => ripristina());

describe('elenco e scheda', () => {
  it.each([[''], ['?search=bell'], ['?only_active=1']])('stessi dipendenti (%s)', async (q) => {
    const [p, n] = await Promise.all([phpEntra('hr.alfa'), nodeEntra('hr.alfa')]);
    const html = (await p.b.chiama(PAGINA + q)).testo;
    const V = (await n.get(API + q)).json.V;
    const nomi = V.employees.map((e: any) => `${e.last_name} ${e.first_name}`);
    for (const x of nomi) expect(html).toContain(x.split(' ')[0]);
    expect(V.countActive).toBe(Number(/(\d+) dipendent[ei] attiv/.exec(html)![1]));
  });

  it('correzione: scheda di un dipendente di un\'altra azienda non apribile', async () => {
    const n = await nodeEntra('hr.alfa');
    expect((await n.get(`${API}?action=view&id=${id(postgres, 'Morselli')}`)).json).toEqual({ redirect: '/admin/employees.php?error=not_found' });
  });
});

describe('creazione e modifica', () => {
  it('nuovo dipendente con orario e buoni pasto personalizzati', async () => {
    const [p, n] = await Promise.all([phpEntra('hr.alfa'), nodeEntra('hr.alfa')]);
    const campi = (fn: typeof mariadb): Array<[string, string]> => [['action', 'create'], ['username', 'nuovo.assunto'], ['fiscal_code', cf('NVSSNT90A01A944')],
      ['first_name', 'Nuovo'], ['last_name', 'Assunto'], ['email', 'nuovo.assunto@esempio.test'], ['phone', '051 123'], ['department_id', String(rep(fn))],
      ['position', 'Impiegato'], ['hire_date', '2026-10-01'], ['iban', 'it60 x054 2811 1010 0000 0123 456'], ['ral_amount', '28.000,50'.replace('.', '')],
      ['working_days_override', '1'], ['working_days[]', 'mon'], ['working_days[]', 'tue'], ['hours_per_day', '6,5'],
      ['smart_working_override', '1'], ['smart_working_days[]', 'tue'], ['buoni_pasto_excluded', '1']];
    const a = await phpPost(p, '', campi(mariadb));
    const b = await nodePost(n, '', campi(postgres));
    expect(a.redirect ?? a.message).toBeTruthy();
    expect(b.redirect ?? b.message).toBeTruthy();
    expect(dip(postgres, 'Assunto')).toEqual(dip(mariadb, 'Assunto'));
    // correzione: la password temporanea non va nell'indirizzo
    expect(b.redirect ?? '').not.toMatch(/pwd=/);
  });

  it.each([
    ['codice fiscale sbagliato', [['username', 'x.y'], ['fiscal_code', 'RSSMRA80A01A944Z'], ['first_name', 'X'], ['last_name', 'Y'], ['email', 'x@y.test']]],
    ['username doppio', [['username', 'giulia.ferraresi'], ['fiscal_code', cf('DPPSRN80A01A944')], ['first_name', 'X'], ['last_name', 'Y'], ['email', 'x@y.test']]],
    ['senza email', [['username', 'senza.mail'], ['fiscal_code', cf('SNZMLA80A01A944')], ['first_name', 'X'], ['last_name', 'Y']]],
  ])('errore: %s', async (_n, extra) => {
    const [p, n] = await Promise.all([phpEntra('hr.alfa'), nodeEntra('hr.alfa')]);
    const campi = (fn: typeof mariadb): Array<[string, string]> => [['action', 'create'], ['department_id', String(rep(fn))], ...(extra as Array<[string, string]>)];
    const a = await phpPost(p, '', campi(mariadb));
    expect(a.error).not.toBe('');
    expect(await nodePost(n, '', campi(postgres))).toEqual(a);
  });

  it('modifica completa e in linea', async () => {
    const [p, n] = await Promise.all([phpEntra('hr.alfa'), nodeEntra('hr.alfa')]);
    const campi = (fn: typeof mariadb): Array<[string, string]> => [['action', 'update'], ['id', id(fn, 'Vezzani')], ['username', 'paola.vezzani'],
      ['fiscal_code', fn(`SELECT fiscal_code FROM employees WHERE last_name = 'Vezzani'`)[0].fiscal_code], ['first_name', 'Paola'], ['last_name', 'Vezzani'],
      ['email', 'paola@esempio.test'], ['department_id', String(rep(fn))], ['position', 'Responsabile'], ['hire_date', '2020-01-15'], ['probation_end_date', ''],
      ['is_active', 'on']];
    const a = await phpPost(p, `?action=edit&id=${id(mariadb, 'Vezzani')}`, campi(mariadb));
    expect(a).toEqual({ redirect: '/admin/employees.php?message=updated' });
    expect(await nodePost(n, `?action=edit&id=${id(postgres, 'Vezzani')}`, campi(postgres))).toEqual(a);
    expect(dip(postgres, 'Vezzani')).toEqual(dip(mariadb, 'Vezzani'));
    for (const [campo, valore] of [['phone', '333 1234567'], ['nfc_uid', '04:aa:bb:cc'], ['ral_amount', '31500,5'], ['campo_finto', 'x']]) {
      const x = await phpPost(p, '', [['action', 'inline_update'], ['id', id(mariadb, 'Vezzani')], ['field', campo], ['value', valore]]);
      const y = await nodePost(n, '', [['action', 'inline_update'], ['id', id(postgres, 'Vezzani')], ['field', campo], ['value', valore]]);
      expect(y).toEqual(x);
    }
    expect(dip(postgres, 'Vezzani')).toEqual(dip(mariadb, 'Vezzani'));
  });

  it('disattivazione e saldi dalla scheda', async () => {
    const [p, n] = await Promise.all([phpEntra('hr.alfa'), nodeEntra('hr.alfa')]);
    const tog = (fn: typeof mariadb): Array<[string, string]> => [['action', 'toggle_active'], ['id', id(fn, 'Gualtieri')]];
    expect(await nodePost(n, '', tog(postgres))).toEqual(await phpPost(p, '', tog(mariadb)));
    expect(dip(postgres, 'Gualtieri')).toEqual(dip(mariadb, 'Gualtieri'));
    const saldi = (fn: typeof mariadb): Array<[string, string]> => [['action', 'update_balance_inline'], ['id', id(fn, 'Bellandi')], ['year', '2026'],
      ['balance[ferie][residual]', '12,5'], ['balance[permesso][residual]', '30']];
    expect(await nodePost(n, '', saldi(postgres))).toEqual(await phpPost(p, '', saldi(mariadb)));
    const s = (fn: typeof mariadb) => fn(`SELECT b.leave_type, b.entitled, b.carried_over, b.manual_used FROM employee_leave_balances b JOIN employees e ON e.id = b.employee_id
      WHERE e.last_name = 'Bellandi' AND b.year = 2026 ORDER BY b.leave_type`).map((r) => ({ ...r, entitled: Number(r.entitled), carried_over: Number(r.carried_over), manual_used: Number(r.manual_used) }));
    expect(s(postgres)).toEqual(s(mariadb));
  });
});

describe('azioni sensibili', () => {
  it('reset password: nuova password a video', async () => {
    const [p, n] = await Promise.all([phpEntra('hr.alfa'), nodeEntra('hr.alfa')]);
    const a = await phpPost(p, '', [['action', 'reset_password'], ['id', id(mariadb, 'Rinaldini')]]);
    const b = await nodePost(n, '', [['action', 'reset_password'], ['id', id(postgres, 'Rinaldini')]]);
    expect(b.message).toBe(a.message);
    // correzione: cambio obbligatorio al primo accesso
    expect(dip(postgres, 'Rinaldini').must_change_password).toBe('1');
  });

  it('correzione: niente reset, saldi o contratto per dipendenti di altre aziende', async () => {
    const n = await nodeEntra('hr.alfa');
    const altro = id(postgres, 'Morselli');
    expect((await nodePost(n, '', [['action', 'reset_password'], ['id', altro]])).error).toBe('Dipendente non trovato');
    expect((await nodePost(n, '', [['action', 'update_balance_inline'], ['id', altro], ['balance[ferie][residual]', '99']])).error).toBe('Dipendente non trovato');
    expect((await nodePost(n, '', [['action', 'inline_update'], ['id', altro], ['field', 'iban'], ['value', 'IT00']])).json).toEqual({ success: false, error: 'Dipendente non trovato' });
  });

  it('accesso HR: password sbagliata, poi concesso e revocato', async () => {
    const [p, n] = await Promise.all([phpEntra('hr.alfa'), nodeEntra('hr.alfa')]);
    const sbagliata = (fn: typeof mariadb): Array<[string, string]> => [['action', 'grant_hr'], ['id', id(fn, 'Ferraresi')], ['confirm_password', 'no']];
    expect(await nodePost(n, '', sbagliata(postgres))).toEqual(await phpPost(p, '', sbagliata(mariadb)));
    const ok = (fn: typeof mariadb): Array<[string, string]> => [['action', 'grant_hr'], ['id', id(fn, 'Ferraresi')], ['confirm_password', PW]];
    const a = await phpPost(p, '', ok(mariadb));
    const b = await nodePost(n, '', ok(postgres));
    const pulisci = (m?: string) => (m ?? '').replace(/password temporanea: \S+/, 'password temporanea: X').replace(/—\s*email non inviata[\s\S]*$|\(inviate anche[\s\S]*$/, '');
    expect(pulisci(b.message)).toBe(pulisci(a.message));
    const hr = (fn: typeof mariadb) => fn(`SELECT username, role, is_active, company_id FROM users WHERE username LIKE 'giulia.ferraresi.hr%'`)
      .map((r) => ({ ...r, is_active: ['1', 't'].includes(r.is_active) }));
    expect(hr(postgres)).toEqual(hr(mariadb));
    const rev = (fn: typeof mariadb): Array<[string, string]> => [['action', 'revoke_hr'], ['id', id(fn, 'Ferraresi')], ['confirm_password', PW]];
    expect(await nodePost(n, '', rev(postgres))).toEqual(await phpPost(p, '', rev(mariadb)));
    expect(hr(postgres)).toEqual(hr(mariadb));
  });

  it('periodo di prova letto dal contratto', async () => {
    const [p, n] = await Promise.all([phpEntra('hr.alfa'), nodeEntra('hr.alfa')]);
    const fs = await import('fs');
    const path = await import('path');
    const { PDFDocument, StandardFonts } = await import('pdf-lib');
    const doc = await PDFDocument.create();
    const pag = doc.addPage();
    pag.drawText('Il rapporto prevede un periodo di prova di sei mesi di calendario.', { x: 40, y: 700, size: 11, font: await doc.embedFont(StandardFonts.Helvetica) });
    const pdf = Buffer.from(await doc.save({ useObjectStreams: false }));
    void fs; void path;
    const f = { campo: 'contract_pdf', nome: 'contratto.pdf', dati: pdf, tipo: 'application/pdf' };
    const a = await phpPost(p, '', [['action', 'read_probation_contract'], ['id', id(mariadb, 'Bellandi')]], f);
    const b = await nodePost(n, '', [['action', 'read_probation_contract'], ['id', id(postgres, 'Bellandi')]], f);
    expect(b.redirect).toBe(a.redirect!.replace(/id=\d+/, `id=${id(postgres, 'Bellandi')}`));
    expect(dip(postgres, 'Bellandi').probation_end_date).toBe(dip(mariadb, 'Bellandi').probation_end_date);
  });
});
