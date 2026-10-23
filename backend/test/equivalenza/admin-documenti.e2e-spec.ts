import { mariadb, postgres, ripristina } from './ambiente';
import { Node, Php } from './clienti';

/** Documenti aziendali (distribuzione a tutti) e documenti del dipendente dalla scheda. */
const PW = 'Prova!Cora2026';
async function phpEntra(u: string) { const p = new Php(); await p.login(u, PW); return p; }
async function nodeEntra(u: string) { const n = new Node(); await n.login(u, PW); return n; }
type File = { campo: string; nome: string; dati: Buffer; tipo: string };

async function phpPost(p: Php, pagina: string, campi: Record<string, string>, file: File[] = []) {
  const html = (await p.b.chiama('/admin/documenti-aziendali.php')).testo;
  const fd = new FormData();
  fd.append('csrf_token', /name="csrf_token" value="([^"]+)"/.exec(html)?.[1] ?? '');
  for (const [k, v] of Object.entries(campi)) fd.append(k, v);
  for (const f of file) fd.append(f.campo, new Blob([new Uint8Array(f.dati)], { type: f.tipo }), f.nome);
  const r = await p.b.chiama(pagina, { metodo: 'POST', corpo: fd as any });
  if (r.location) { const u = new URL(r.location, `http://x${pagina}`); return { redirect: u.pathname + u.search + u.hash }; }
  return { status: r.status, testo: r.testo };
}
/** Come lo script della pagina o il modulo: indirizzo .php del PHP, CSRF nell'header. */
async function nodePost(n: Node, pagina: string, campi: Record<string, string>, file: File[] = []) {
  const fd = new FormData();
  for (const [k, v] of Object.entries(campi)) fd.append(k, v);
  for (const f of file) fd.append(f.campo, new Blob([new Uint8Array(f.dati)], { type: f.tipo }), f.nome);
  const r = await n.b.chiama(pagina, { metodo: 'POST', corpo: fd as any, header: { 'X-CSRF-Token': n.b.valoreCookie('cora_csrf')! } });
  if (r.location) { const u = new URL(r.location, `http://x${pagina}`); return { redirect: u.pathname + u.search + u.hash }; }
  return { status: r.status, testo: r.testo };
}
const PDF = Buffer.from('%PDF-1.4\n1 0 obj<<>>endobj\ntrailer<<>>\n%%EOF\n');
const pdf = (nome: string, campo = 'document'): File => ({ campo, nome, dati: PDF, tipo: 'application/pdf' });
const gruppi = (fn: typeof mariadb) => fn(
  `SELECT name, original_name, mime_type, COUNT(*) AS n, SUM(CASE WHEN notify_pending THEN 1 ELSE 0 END) AS sospese, MAX(expires_on) AS scade
     FROM employee_documents WHERE bulk_group IS NOT NULL GROUP BY name, original_name, mime_type ORDER BY name`);
const gruppo = (fn: typeof mariadb) => fn(`SELECT bulk_group FROM employee_documents WHERE name = 'Regolamento 2027' ORDER BY id LIMIT 1`)[0]?.bulk_group;

beforeAll(() => ripristina());

describe('distribuzione a tutti i dipendenti', () => {
  it('caricamento, notifiche a blocchi, eliminazione', async () => {
    const [p, n] = await Promise.all([phpEntra('hr.alfa'), nodeEntra('hr.alfa')]);
    const campi = { action: 'bulk_upload', name: 'Regolamento 2027', expires_on: '2027-12-31', send_email: '1' };
    const a = await phpPost(p, '/admin/documenti-aziendali.php', campi, [pdf('regolamento.pdf')]);
    const b = await nodePost(n, '/admin/documenti-aziendali.php', campi, [pdf('regolamento.pdf')]);
    expect(a.redirect).toMatch(/^\/admin\/documenti-aziendali\.php\?uploaded=[0-9a-f]{16}&n=\d+$/);
    expect(b.redirect!.replace(/uploaded=[0-9a-f]{16}/, 'G')).toBe(a.redirect!.replace(/uploaded=[0-9a-f]{16}/, 'G'));
    expect(gruppi(postgres)).toEqual(gruppi(mariadb));

    // la pagina dopo il caricamento: stesso numero di notifiche da inviare
    const V = (await n.get(`/pagine/admin/documenti-aziendali?uploaded=${gruppo(postgres)}&n=1`)).json.V;
    const html = (await p.b.chiama(`/admin/documenti-aziendali.php?uploaded=${gruppo(mariadb)}&n=1`)).testo;
    expect(V.jobTotal).toBe(Number(/data-total="(\d+)"/.exec(html)![1]));

    // smaltimento come lo fa lo script della pagina
    for (;;) {
      const x = JSON.parse((await phpPost(p, '/admin/documenti-aziendali.php', { action: 'notify_batch', bulk_group: gruppo(mariadb), limit: '4' })).testo!);
      const y = JSON.parse((await nodePost(n, '/admin/documenti-aziendali.php', { action: 'notify_batch', bulk_group: gruppo(postgres), limit: '4' })).testo!);
      expect(y).toEqual(x);
      if (!x.remaining) break;
    }
    expect(gruppi(postgres)).toEqual(gruppi(mariadb));

    const del = (fn: typeof mariadb) => ({ action: 'bulk_delete', bulk_group: gruppo(fn) });
    const c = await phpPost(p, '/admin/documenti-aziendali.php', del(mariadb));
    const d = await nodePost(n, '/admin/documenti-aziendali.php', del(postgres));
    expect(c.redirect).toMatch(/\?ok=Distribuzione/);
    // correzione: il messaggio e' un codice della pagina
    expect(d.redirect).toMatch(/^\/admin\/documenti-aziendali\.php\?ok=deleted_\d+$/);
    expect(gruppi(postgres)).toEqual(gruppi(mariadb));
    expect((await n.get(`/pagine/admin/documenti-aziendali?${d.redirect!.split('?')[1]}`)).json.V.okMessage)
      .toBe(decodeURIComponent(c.redirect!.split('ok=')[1].replace(/\+/g, ' ')));
  });

  it('errori: niente file, estensione non ammessa', async () => {
    const [p, n] = await Promise.all([phpEntra('hr.alfa'), nodeEntra('hr.alfa')]);
    const a = await phpPost(p, '/admin/documenti-aziendali.php', { action: 'bulk_upload', name: 'X' });
    const b = await nodePost(n, '/admin/documenti-aziendali.php', { action: 'bulk_upload', name: 'X' });
    const msg = async (r: { redirect?: string }) => (await n.get(`/pagine/admin/documenti-aziendali?${r.redirect!.split('?')[1]}`)).json.V.errMessage;
    expect(await msg(b)).toBe(decodeURIComponent(a.redirect!.split('err=')[1].replace(/\+/g, ' ')));
    const exe = { campo: 'document', nome: 'virus.exe', dati: Buffer.from('MZ'), tipo: 'application/octet-stream' };
    const c = await phpPost(p, '/admin/documenti-aziendali.php', { action: 'bulk_upload', name: 'X' }, [exe]);
    const d = await nodePost(n, '/admin/documenti-aziendali.php', { action: 'bulk_upload', name: 'X' }, [exe]);
    expect(decodeURIComponent(c.redirect!.split('err=')[1])).toMatch(/^Estensione\+non\+consentita/);
    expect(await msg(d)).toMatch(/^Estensione non consentita/);
    // correzione: testo libero in ?err= non viene mostrato
    expect((await n.get('/pagine/admin/documenti-aziendali?err=Chiama+il+numero+123')).json.V.errMessage).toBe('');
  });

  it('correzione: un PDF finto e\' rifiutato', async () => {
    const n = await nodeEntra('hr.alfa');
    const r = await nodePost(n, '/admin/documenti-aziendali.php', { action: 'bulk_upload', name: 'Finto' },
      [{ campo: 'document', nome: 'finto.pdf', dati: Buffer.from('<html>'), tipo: 'application/pdf' }]);
    expect(r.redirect).toBe('/admin/documenti-aziendali.php?err=contenuto');
  });
});

describe('documenti dalla scheda del dipendente', () => {
  const emp = (fn: typeof mariadb) => fn(`SELECT id FROM employees WHERE last_name = 'Ferraresi'`)[0].id;
  const docs = (fn: typeof mariadb) => fn(
    `SELECT ed.name, ed.original_name, ed.visible_to_employee, ed.expires_on FROM employee_documents ed JOIN employees e ON e.id = ed.employee_id
      WHERE e.last_name = 'Ferraresi' ORDER BY ed.id`).map((r) => ({ ...r, visible_to_employee: ['1', 't'].includes(r.visible_to_employee) }));
  const ultimo = (fn: typeof mariadb) => fn(`SELECT ed.id FROM employee_documents ed JOIN employees e ON e.id = ed.employee_id WHERE e.last_name = 'Ferraresi' ORDER BY ed.id DESC LIMIT 1`)[0].id;
  const allinea = (r: { redirect?: string }, fn: typeof mariadb) => r.redirect?.replace(/id=\d+/, `id=${emp(fn)}`);

  it('caricamento multiplo, rinomina, visibilita\', scadenza, eliminazione', async () => {
    const [p, n] = await Promise.all([phpEntra('hr.alfa'), nodeEntra('hr.alfa')]);
    const up = (fn: typeof mariadb) => ({ action: 'upload', employee_id: emp(fn), name: 'Contratto', visible_to_employee: '1' });
    const f = [pdf('a.pdf', 'documents[]'), pdf('b.pdf', 'documents[]')];
    const a = await phpPost(p, '/admin/employee-documents.php', up(mariadb), f);
    expect(a.redirect).toBe(`/admin/employees.php?action=view&id=${emp(mariadb)}&ed_status=uploaded_2#docs`);
    expect(allinea(await nodePost(n, '/admin/employee-documents.php', up(postgres), f), mariadb)).toBe(a.redirect);
    expect(docs(postgres)).toEqual(docs(mariadb));
    for (const azione of [{ action: 'rename', name: 'Contratto firmato' }, { action: 'toggle_visibility' }, { action: 'update_expiry', expires_on: '2027-01-31' },
      { action: 'update_expiry', expires_on: 'domani' }, { action: 'rename', name: '  ' }] as Record<string, string>[]) {
      const x = await phpPost(p, '/admin/employee-documents.php', { ...azione, employee_id: emp(mariadb), document_id: ultimo(mariadb) });
      const y = await nodePost(n, '/admin/employee-documents.php', { ...azione, employee_id: emp(postgres), document_id: ultimo(postgres) });
      expect(allinea(y, mariadb)).toBe(x.redirect);
      expect(docs(postgres)).toEqual(docs(mariadb));
    }
    const x = await phpPost(p, '/admin/employee-documents.php', { action: 'delete', employee_id: emp(mariadb), document_id: ultimo(mariadb) });
    const y = await nodePost(n, '/admin/employee-documents.php', { action: 'delete', employee_id: emp(postgres), document_id: ultimo(postgres) });
    expect(allinea(y, mariadb)).toBe(x.redirect);
    expect(docs(postgres)).toEqual(docs(mariadb));
  });

  it('correzione: documento di un\'altra azienda non modificabile', async () => {
    const n = await nodeEntra('hr.alfa');
    postgres(`INSERT INTO employee_documents (company_id, employee_id, name, file_path, file_name, original_name, file_size, mime_type, visible_to_employee, uploaded_by)
              SELECT 2, e.id, 'Altrui', '/x', 'x.pdf', 'x.pdf', 1, 'application/pdf', true, (SELECT id FROM users WHERE username = 'hr.beta')
                FROM employees e WHERE e.last_name = 'Morselli'`);
    const altrui = postgres(`SELECT id FROM employee_documents WHERE name = 'Altrui'`)[0].id;
    const r = await nodePost(n, '/admin/employee-documents.php', { action: 'rename', name: 'Preso', employee_id: emp(postgres), document_id: altrui });
    expect(r.redirect).toMatch(/ed_status=error_Documento\+non\+trovato#docs$/);
    expect(postgres(`SELECT name FROM employee_documents WHERE id = ${altrui}`)[0].name).toBe('Altrui');
    expect((await n.b.chiama(`/admin/employee-documents.php?download=${altrui}`)).status).toBe(403);
  });
});
