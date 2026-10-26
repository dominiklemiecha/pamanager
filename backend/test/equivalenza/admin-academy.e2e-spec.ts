import { mariadb, postgres, ripristina } from './ambiente';
import { Node, Php } from './clienti';

/**
 * Academy dello staff (admin/academy.php, academy-templates.php e le stesse
 * pagine di consulente e formatore): corsi, materiali, assegnazioni,
 * notifiche, solleciti, registro, attestati e modelli.
 */
const PW = 'Prova!Cora2026';
async function phpEntra(u: string) { const p = new Php(); await p.login(u, PW); return p; }
async function nodeEntra(u: string) { const n = new Node(); await n.login(u, PW); return n; }
const PDF = Buffer.from('%PDF-1.4\n1 0 obj<<>>endobj\ntrailer<<>>\n%%EOF\n');
const PNG = Buffer.from('89504e470d0a1a0a0000000d49484452000000010000000108060000001f15c4890000000d49444154789c6360000002000154a24f5d0000000049454e44ae426082', 'hex');
type Allegato = { campo: string; nome: string; dati: Buffer; tipo: string };

async function phpPost(p: Php, pagina: string, campi: Array<[string, string]>, file: Allegato[] = []) {
  const html = (await p.b.chiama(pagina)).testo;
  const fd = new FormData();
  fd.append('csrf_token', /name="csrf_token" value="([^"]+)"/.exec(html)?.[1] ?? /csrfToken\s*=\s*['"]([^'"]+)/.exec(html)?.[1] ?? '');
  for (const [k, v] of campi) fd.append(k, v);
  for (const f of file) fd.append(f.campo, new Blob([new Uint8Array(f.dati)], { type: f.tipo }), f.nome);
  const r = await p.b.chiama(pagina, { metodo: 'POST', corpo: fd as any });
  return { status: r.status, redirect: r.location ? new URL(r.location, 'http://x').pathname + new URL(r.location, 'http://x').search : null, testo: r.testo };
}
async function nodePost(n: Node, pagina: string, campi: Array<[string, string]>, file: Allegato[] = []) {
  const fd = new FormData();
  for (const [k, v] of campi) fd.append(k, v);
  for (const f of file) fd.append(f.campo, new Blob([new Uint8Array(f.dati)], { type: f.tipo }), f.nome);
  const r = await n.b.chiama(pagina, { metodo: 'POST', corpo: fd as any, header: { 'X-CSRF-Token': n.b.valoreCookie('cora_csrf')! } });
  let corpo: any = null;
  try { corpo = JSON.parse(r.testo); } catch { corpo = r.testo; }
  return { status: r.status, redirect: r.location ? new URL(r.location, 'http://x').pathname + new URL(r.location, 'http://x').search : null, corpo };
}
const ok = (q: string) => decodeURIComponent((/[?&]ok=([^&]*)/.exec(q ?? '')?.[1] ?? '').replace(/\+/g, ' '));
const err = (q: string) => decodeURIComponent((/[?&]err=([^&]*)/.exec(q ?? '')?.[1] ?? '').replace(/\+/g, ' '));

const corsoId = (fn: typeof mariadb, titolo: string) => fn(`SELECT id FROM academy_courses WHERE title = '${titolo}'`)[0]?.id;
const corsoRiga = (fn: typeof mariadb, titolo: string) => fn(`SELECT title, description, duration_hours, due_date, status FROM academy_courses WHERE title = '${titolo}'`)
  .map((r) => ({ ...r, duration_hours: r.duration_hours === 'NULL' || r.duration_hours === null ? null : String(Number(r.duration_hours)), description: r.description === 'NULL' ? null : r.description, due_date: r.due_date === 'NULL' ? null : r.due_date }))[0];
const materiali = (fn: typeof mariadb, corso: string) => fn(`SELECT kind, title, url, mime_type, sort_order FROM academy_materials WHERE course_id = ${corso} ORDER BY sort_order, id`)
  .map((r) => Object.fromEntries(Object.entries(r).map(([k, v]) => [k, v === 'NULL' ? null : v])));
const assegnazioni = (fn: typeof mariadb, corso: string) => fn(
  `SELECT e.last_name, a.notify_pending, a.notify_email, a.reminder_count FROM academy_assignments a JOIN employees e ON e.id = a.employee_id WHERE a.course_id = ${corso} ORDER BY e.last_name`)
  .map((r) => ({ ...r, notify_pending: ['1', 't'].includes(r.notify_pending), notify_email: ['1', 't'].includes(r.notify_email) }));
const eventi = (fn: typeof mariadb) => fn(`SELECT event, actor_type, details FROM academy_events ORDER BY id`).map((r) => ({ ...r, details: r.details === 'NULL' ? null : r.details }));
const notificheAcademy = (fn: typeof mariadb) => Number(fn(`SELECT COUNT(*) AS n FROM notifications WHERE type = 'academy'`)[0].n);
const dip = (fn: typeof mariadb, cognome: string) => fn(`SELECT id FROM employees WHERE last_name = '${cognome}'`)[0].id;
const ass = (fn: typeof mariadb, cognome: string) => fn(`SELECT a.id FROM academy_assignments a JOIN employees e ON e.id = a.employee_id WHERE e.last_name = '${cognome}' ORDER BY a.id DESC LIMIT 1`)[0].id;

const A = '/admin/academy.php';
const T = '/admin/academy-templates.php';

beforeAll(() => ripristina());

describe('corsi e materiali', () => {
  it('nuovo corso: stessi dati e stesso registro', async () => {
    const [p, n] = await Promise.all([phpEntra('hr.alfa'), nodeEntra('hr.alfa')]);
    const campi: Array<[string, string]> = [['action', 'save_course'], ['title', 'Privacy GDPR'], ['description', 'Trattamento dei dati'], ['duration_hours', '2,5'], ['due_date', '2026-12-31']];
    const a = await phpPost(p, A, campi);
    const b = await nodePost(n, A, campi);
    expect(b.redirect).toBe(a.redirect!.replace(`course=${corsoId(mariadb, 'Privacy GDPR')}`, `course=${corsoId(postgres, 'Privacy GDPR')}`));
    expect(ok(b.redirect!)).toBe('Corso creato: ora aggiungi i materiali');
    expect(corsoRiga(postgres, 'Privacy GDPR')).toEqual(corsoRiga(mariadb, 'Privacy GDPR'));
  });

  it.each([
    ['senza titolo', [['title', '']]],
    ['durata negativa', [['title', 'X'], ['duration_hours', '-1']]],
    ['scadenza errata', [['title', 'X'], ['due_date', '31/12/2026']]],
  ])('stesso errore (%s); correzione: non passa dall\'indirizzo', async (_n, campi) => {
    const [p, n] = await Promise.all([phpEntra('hr.alfa'), nodeEntra('hr.alfa')]);
    const c = [['action', 'save_course'], ...(campi as Array<[string, string]>)] as Array<[string, string]>;
    const a = await phpPost(p, A, c);
    const b = await nodePost(n, A, c);
    expect(b.redirect).toBeNull();
    expect(b.corpo.V.errMessage).toBe(err(a.redirect!));
  });

  it('pubblicare senza materiali: stesso errore', async () => {
    const [p, n] = await Promise.all([phpEntra('hr.alfa'), nodeEntra('hr.alfa')]);
    const c = (fn: typeof mariadb): Array<[string, string]> => [['action', 'set_status'], ['course_id', corsoId(fn, 'Privacy GDPR')], ['status', 'published'], ['return_tab', 'info']];
    const a = await phpPost(p, A, c(mariadb));
    const b = await nodePost(n, A, c(postgres));
    expect(b.corpo.V.errMessage).toBe(err(a.redirect!));
  });

  it('materiali: file, link, rinomina, sposta', async () => {
    const [p, n] = await Promise.all([phpEntra('hr.alfa'), nodeEntra('hr.alfa')]);
    const file: Allegato[] = [{ campo: 'files[]', nome: 'Slide privacy.pdf', dati: PDF, tipo: 'application/pdf' }, { campo: 'files[]', nome: 'Schema.png', dati: PNG, tipo: 'image/png' }];
    const c = (fn: typeof mariadb, extra: Array<[string, string]>): Array<[string, string]> => [['course_id', corsoId(fn, 'Privacy GDPR')], ...extra];
    const a1 = await phpPost(p, A, c(mariadb, [['action', 'add_files']]), file);
    const b1 = await nodePost(n, A, c(postgres, [['action', 'add_files']]), file);
    expect(ok(b1.redirect!)).toBe(ok(a1.redirect!));
    for (const [x, y] of [[p, n]] as const) {
      await phpPost(x, A, c(mariadb, [['action', 'add_link'], ['title', ''], ['url', 'https://www.garanteprivacy.it/regolamento']]));
      await nodePost(y, A, c(postgres, [['action', 'add_link'], ['title', ''], ['url', 'https://www.garanteprivacy.it/regolamento']]));
    }
    const m = (fn: typeof mariadb) => fn(`SELECT id FROM academy_materials WHERE course_id = ${corsoId(fn, 'Privacy GDPR')} ORDER BY sort_order, id`).map((r) => r.id);
    await phpPost(p, A, c(mariadb, [['action', 'rename_material'], ['material_id', m(mariadb)[0]], ['title', 'Presentazione']]));
    await nodePost(n, A, c(postgres, [['action', 'rename_material'], ['material_id', m(postgres)[0]], ['title', 'Presentazione']]));
    await phpPost(p, A, c(mariadb, [['action', 'move_material'], ['material_id', m(mariadb)[2]], ['dir', 'up']]));
    await nodePost(n, A, c(postgres, [['action', 'move_material'], ['material_id', m(postgres)[2]], ['dir', 'up']]));
    expect(materiali(postgres, corsoId(postgres, 'Privacy GDPR'))).toEqual(materiali(mariadb, corsoId(mariadb, 'Privacy GDPR')));
  });

  it('correzione: un file con contenuto diverso dall\'estensione non entra', async () => {
    const n = await nodeEntra('hr.alfa');
    const b = await nodePost(n, A, [['action', 'add_files'], ['course_id', corsoId(postgres, 'Privacy GDPR')]], [{ campo: 'files[]', nome: 'finto.pdf', dati: Buffer.from('<html><script>alert(1)</script>'), tipo: 'application/pdf' }]);
    expect(b.corpo.V.errMessage).toBe('finto.pdf: contenuto non consentito');
  });

  it('pubblicazione', async () => {
    const [p, n] = await Promise.all([phpEntra('hr.alfa'), nodeEntra('hr.alfa')]);
    const c = (fn: typeof mariadb): Array<[string, string]> => [['action', 'set_status'], ['course_id', corsoId(fn, 'Privacy GDPR')], ['status', 'published'], ['return_tab', 'info']];
    expect(ok((await nodePost(n, A, c(postgres))).redirect!)).toBe(ok((await phpPost(p, A, c(mariadb))).redirect!));
    expect(corsoRiga(postgres, 'Privacy GDPR')).toEqual(corsoRiga(mariadb, 'Privacy GDPR'));
  });
});

describe('assegnazioni e notifiche', () => {
  it('assegna a selezionati (anche non attivi e di altre aziende: scartati)', async () => {
    const [p, n] = await Promise.all([phpEntra('hr.alfa'), nodeEntra('hr.alfa')]);
    const c = (fn: typeof mariadb): Array<[string, string]> => [['action', 'assign'], ['course_id', corsoId(fn, 'Privacy GDPR')], ['mode', 'selected'],
      ['employee_ids[]', dip(fn, 'Montanari')], ['employee_ids[]', dip(fn, 'Pellegatti')], ['employee_ids[]', dip(fn, 'Lombardelli')], ['employee_ids[]', dip(fn, 'Morselli')], ['send_email', '1']];
    const a = await phpPost(p, A, c(mariadb));
    const b = await nodePost(n, A, c(postgres));
    expect(ok(b.redirect!)).toBe(ok(a.redirect!));
    expect(b.redirect).toContain('notify=1');
    expect(assegnazioni(postgres, corsoId(postgres, 'Privacy GDPR'))).toEqual(assegnazioni(mariadb, corsoId(mariadb, 'Privacy GDPR')));
  });

  it('assegna a un reparto', async () => {
    const [p, n] = await Promise.all([phpEntra('hr.alfa'), nodeEntra('hr.alfa')]);
    const c = (fn: typeof mariadb): Array<[string, string]> => [['action', 'assign'], ['course_id', corsoId(fn, 'Privacy GDPR')], ['mode', 'department'], ['department_id', '11']];
    expect(ok((await nodePost(n, A, c(postgres))).redirect!)).toBe(ok((await phpPost(p, A, c(mariadb))).redirect!));
    expect(assegnazioni(postgres, corsoId(postgres, 'Privacy GDPR'))).toEqual(assegnazioni(mariadb, corsoId(mariadb, 'Privacy GDPR')));
  });

  it('invio delle notifiche a blocchi', async () => {
    const [p, n] = await Promise.all([phpEntra('hr.alfa'), nodeEntra('hr.alfa')]);
    const c = (fn: typeof mariadb): Array<[string, string]> => [['action', 'notify_batch'], ['course_id', corsoId(fn, 'Privacy GDPR')], ['limit', '2']];
    const a = JSON.parse((await phpPost(p, A, c(mariadb))).testo);
    const b = (await nodePost(n, A, c(postgres))).corpo;
    expect(b).toEqual(a);
    expect(notificheAcademy(postgres)).toBe(notificheAcademy(mariadb));
  });

  it('sollecito, sollecito a tutti, rimozione', async () => {
    const [p, n] = await Promise.all([phpEntra('hr.alfa'), nodeEntra('hr.alfa')]);
    const c = (fn: typeof mariadb, x: Array<[string, string]>): Array<[string, string]> => [['course_id', corsoId(fn, 'Privacy GDPR')], ...x];
    expect(ok((await nodePost(n, A, c(postgres, [['action', 'remind'], ['assignment_id', ass(postgres, 'Montanari')]]))).redirect!))
      .toBe(ok((await phpPost(p, A, c(mariadb, [['action', 'remind'], ['assignment_id', ass(mariadb, 'Montanari')]]))).redirect!));
    expect(ok((await nodePost(n, A, c(postgres, [['action', 'remind_all']]))).redirect!)).toBe(ok((await phpPost(p, A, c(mariadb, [['action', 'remind_all']]))).redirect!));
    expect(ok((await nodePost(n, A, c(postgres, [['action', 'unassign'], ['assignment_id', ass(postgres, 'Pellegatti')]]))).redirect!))
      .toBe(ok((await phpPost(p, A, c(mariadb, [['action', 'unassign'], ['assignment_id', ass(mariadb, 'Pellegatti')]]))).redirect!));
    expect(assegnazioni(postgres, corsoId(postgres, 'Privacy GDPR'))).toEqual(assegnazioni(mariadb, corsoId(mariadb, 'Privacy GDPR')));
    expect(notificheAcademy(postgres)).toBe(notificheAcademy(mariadb));
  });

  it('stesso registro eventi', () => {
    expect(eventi(postgres)).toEqual(eventi(mariadb));
  });
});

describe('attestati e registro', () => {
  it('generazione a blocchi: stesso esito e numero', async () => {
    const [p, n] = await Promise.all([phpEntra('hr.alfa'), nodeEntra('hr.alfa')]);
    const c = (fn: typeof mariadb): Array<[string, string]> => [['action', 'generate_batch'], ['course_id', corsoId(fn, 'Sicurezza sul lavoro')], ['ids', ''], ['limit', '5']];
    const a = JSON.parse((await phpPost(p, A, c(mariadb))).testo);
    const b = (await nodePost(n, A, c(postgres))).corpo;
    expect(b).toEqual(a);
    const num = (fn: typeof mariadb) => fn('SELECT number, seq_number FROM academy_certificates ORDER BY id');
    expect(num(postgres)).toEqual(num(mariadb));
  });

  it('ZIP degli attestati e registro CSV/PDF', async () => {
    const [p, n] = await Promise.all([phpEntra('hr.alfa'), nodeEntra('hr.alfa')]);
    const cid = (fn: typeof mariadb) => corsoId(fn, 'Sicurezza sul lavoro');
    const z = await n.b.scarica(`${A}?course=${cid(postgres)}&zip=1`);
    expect(z.status).toBe(200);
    expect(z.dati.subarray(0, 2).toString()).toBe('PK');
    const [csvA, csvB] = [await p.b.scarica(`${A}?course=${cid(mariadb)}&export=csv`), await n.b.scarica(`${A}?course=${cid(postgres)}&export=csv`)];
    const righe = (b: Buffer) => b.toString('utf8').replace(/^﻿/, '').trim().split('\n').map((r) => r.split(';').slice(2, 9).join(';'));
    expect(righe(csvB.dati)).toEqual(righe(csvA.dati));
    const pdf = await n.b.scarica(`${A}?course=${cid(postgres)}&export=pdf`);
    expect(pdf.dati.subarray(0, 5).toString()).toBe('%PDF-');
  });

  it('il responsabile di reparto e\' in sola lettura', async () => {
    const n = await nodeEntra('resp.commerciale');
    const b = await nodePost(n, '/admin-reparto/academy.php', [['action', 'save_course'], ['title', 'Abusivo']]);
    expect([403, 404]).toContain(b.status);
    expect(corsoId(postgres, 'Abusivo')).toBeUndefined();
  });

  it('correzione: messaggio libero nell\'indirizzo non mostrato', async () => {
    const n = await nodeEntra('hr.alfa');
    expect((await n.get('/pagine/admin/academy?ok=Clicca+qui+per+il+bonus')).json.V.okMessage).toBe('');
    expect((await n.get('/pagine/admin/academy?ok=Corso+eliminato')).json.V.okMessage).toBe('Corso eliminato');
  });
});

describe('modelli di attestato', () => {
  const layout = JSON.stringify([{ id: 1, type: 'text', x: 10, y: 10, width: 200, height: 30, content: '<b>{NOME_COGNOME}</b><script>x</script><a href="javascript:1" onclick="x">a</a>', fontSize: 18, color: '#112233', bold: true, align: 'center' },
    { id: 2, type: 'image', x: 1, y: 1, width: 10, height: 10, src: 'https://evil.test/a.png' }, { id: 3, type: 'qr', x: 700, y: 450, width: 76, height: 76 }]);

  it('salvataggio: stesso layout ripulito', async () => {
    const [p, n] = await Promise.all([phpEntra('hr.alfa'), nodeEntra('hr.alfa')]);
    const c: Array<[string, string]> = [['action', 'save'], ['name', 'Modello aziendale'], ['orientation', 'landscape'], ['layout_json', layout]];
    const a = await phpPost(p, T, c);
    const b = await nodePost(n, T, c);
    expect(ok(b.redirect!)).toBe(ok(a.redirect!));
    const riga = (fn: typeof mariadb) => fn(`SELECT name, orientation, layout_json FROM academy_certificate_templates WHERE name = 'Modello aziendale'`)[0];
    const ra = riga(mariadb);
    const rb = riga(postgres);
    expect(rb.orientation).toBe(ra.orientation);
    expect(JSON.parse(rb.layout_json)).toEqual(JSON.parse(ra.layout_json));
  });

  it('duplica ed elimina', async () => {
    const [p, n] = await Promise.all([phpEntra('hr.alfa'), nodeEntra('hr.alfa')]);
    const id = (fn: typeof mariadb, nome: string) => fn(`SELECT id FROM academy_certificate_templates WHERE name = '${nome}'`)[0]?.id;
    expect(ok((await nodePost(n, T, [['action', 'duplicate'], ['id', id(postgres, 'Modello aziendale')]])).redirect!))
      .toBe(ok((await phpPost(p, T, [['action', 'duplicate'], ['id', id(mariadb, 'Modello aziendale')]])).redirect!));
    expect(id(postgres, 'Modello aziendale (copia)')).toBeDefined();
    expect(ok((await nodePost(n, T, [['action', 'delete'], ['id', id(postgres, 'Modello aziendale (copia)')]])).redirect!))
      .toBe(ok((await phpPost(p, T, [['action', 'delete'], ['id', id(mariadb, 'Modello aziendale (copia)')]])).redirect!));
    expect(id(postgres, 'Modello aziendale (copia)')).toBeUndefined();
  });

  it('immagine dei modelli e anteprima PDF', async () => {
    const n = await nodeEntra('hr.alfa');
    const img = (await nodePost(n, T, [['action', 'upload_image']], [{ campo: 'image', nome: 'logo.png', dati: PNG, tipo: 'image/png' }])).corpo;
    expect(img.success).toBe(true);
    const nome = /img=([a-f0-9]{24}\.png)$/.exec(img.url)![1];
    const f = await n.b.scarica(`/academy-file.php?img=${nome}`);
    expect(f.status).toBe(200);
    expect(f.tipo).toContain('image/png');
    const falsa = (await nodePost(n, T, [['action', 'upload_image']], [{ campo: 'image', nome: 'logo.png', dati: Buffer.from('<svg onload=alert(1)>'), tipo: 'image/png' }])).corpo;
    expect(falsa.success).toBe(false);
    const fd = new FormData();
    fd.append('action', 'preview');
    fd.append('orientation', 'landscape');
    fd.append('layout_json', layout);
    const r = await fetch(`${(n.b as any).base}${T}`, { method: 'POST', body: fd, headers: { 'X-CSRF-Token': n.b.valoreCookie('cora_csrf')!, 'User-Agent': n.b.userAgent, 'X-Forwarded-For': (n.b as any).ip, Cookie: [...(n.b as any).cookie].map(([k, v]: [string, string]) => `${k}=${v}`).join('; ') } });
    expect(r.headers.get('content-type')).toContain('application/pdf');
    expect(Buffer.from(await r.arrayBuffer()).subarray(0, 5).toString()).toBe('%PDF-');
  });

  it('il formatore vede e gestisce i modelli della sua azienda', async () => {
    const n = await nodeEntra('formatore.alfa');
    const V = (await n.get('/pagine/formatore/academy-templates')).json.V;
    expect(V.__templates.map((t: any) => t.name)).toEqual(['Modello aziendale']);
  });
});
