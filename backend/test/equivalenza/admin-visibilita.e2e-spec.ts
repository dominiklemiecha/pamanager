import { mariadb, postgres, ripristina } from './ambiente';
import { Node, Php } from './clienti';

/** Visibilita' dei campi anagrafici (admin/field-visibility.php). */
const PW = 'Prova!Cora2026';
const cfg = (fn: typeof mariadb, cid = 1) => {
  const r = fn(`SELECT setting_value FROM app_settings WHERE setting_key = 'employee_field_visibility' AND company_id = ${cid}`)[0];
  return r ? JSON.parse(r.setting_value) : null;
};

beforeAll(() => ripristina());

it('predefiniti, salvataggio e solo per l\'azienda attiva', async () => {
  const p = new Php(); await p.login('hr.alfa', PW);
  const n = new Node(); await n.login('hr.alfa', PW);
  const html = (await p.b.chiama('/admin/field-visibility.php')).testo;
  const spunte = [...html.matchAll(/name="visibility\[([a-z_]+)\]\[\]" value="([a-z_]+)"\s*checked/g)].map((m) => `${m[1]}:${m[2]}`);
  const V = (await n.get('/pagine/admin/field-visibility')).json.V;
  expect(Object.entries(V.config).flatMap(([f, r]) => (r as string[]).map((x) => `${f}:${x}`))).toEqual(spunte);

  const campi: Array<[string, string]> = [['visibility[iban][]', 'accountant'], ['visibility[email][]', 'admin_reparto'], ['visibility[email][]', 'pirata']];
  const fd = new FormData();
  fd.append('csrf_token', /name="csrf_token" value="([^"]+)"/.exec(html)![1]);
  for (const [k, v] of campi) fd.append(k, v);
  await p.b.chiama('/admin/field-visibility.php', { metodo: 'POST', corpo: fd as any });
  const fn = new FormData();
  for (const [k, v] of campi) fn.append(k, v);
  const r = await n.b.chiama('/api/v1/pagine/admin/field-visibility', { metodo: 'POST', corpo: fn as any, header: { 'X-CSRF-Token': n.b.valoreCookie('cora_csrf')! } });
  expect(JSON.parse(r.testo).V.saved).toBe(1);
  expect(cfg(postgres)).toEqual(cfg(mariadb));
  expect(cfg(postgres, 2)).toEqual(cfg(mariadb, 2));
});
