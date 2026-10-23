import { mariadb, postgres, ripristina } from './ambiente';
import { Node, Php } from './clienti';

/** Comunicazioni dell'HR (admin/communications.php e communications_upload.php). */
const PW = 'Prova!Cora2026';
const PAGINA = '/admin/communications.php';
const API = '/pagine/admin/communications';

async function phpEntra(u: string) { const p = new Php(); await p.login(u, PW); return p; }
async function nodeEntra(u: string) { const n = new Node(); await n.login(u, PW); return n; }
const decodifica = (s: string) => s.replace(/&#0?39;|&apos;/g, "'").replace(/&quot;/g, '"').replace(/&amp;/g, '&').replace(/&lt;/g, '<').replace(/&gt;/g, '>');
type File = { campo: string; nome: string; dati: Buffer; tipo: string };

async function phpPost(p: Php, query: string, campi: Record<string, string>, file: File[] = []) {
  const html = (await p.b.chiama(PAGINA)).testo;
  const fd = new FormData();
  fd.append('csrf_token', /name="csrf_token" value="([^"]+)"/.exec(html)?.[1] ?? '');
  for (const [k, v] of Object.entries(campi)) fd.append(k, v);
  for (const f of file) fd.append(f.campo, new Blob([new Uint8Array(f.dati)], { type: f.tipo }), f.nome);
  const r = await p.b.chiama(PAGINA + query, { metodo: 'POST', corpo: fd as any });
  if (r.location) { const u = new URL(r.location, `http://x${PAGINA}`); return { redirect: u.pathname + u.search }; }
  return { error: decodifica(/alert alert-error">([^<]*)</.exec(r.testo)?.[1] ?? '') };
}
async function nodePost(n: Node, query: string, campi: Record<string, string>, file: File[] = []) {
  const fd = new FormData();
  for (const [k, v] of Object.entries(campi)) fd.append(k, v);
  for (const f of file) fd.append(f.campo, new Blob([new Uint8Array(f.dati)], { type: f.tipo }), f.nome);
  const r = await n.b.chiama('/api/v1' + API + query, { metodo: 'POST', corpo: fd as any, header: { 'X-CSRF-Token': n.b.valoreCookie('cora_csrf')! } });
  const j = JSON.parse(r.testo);
  return j.redirect ? { redirect: j.redirect } : { error: j.V.error };
}
const com = (fn: typeof mariadb, titolo: string) => fn(`SELECT id FROM communications WHERE title = '${titolo}' ORDER BY id DESC LIMIT 1`)[0]?.id;
const riga = (fn: typeof mariadb, titolo: string) => fn(
  `SELECT title, content, priority, is_published, publish_date, expire_date, is_global, company_id FROM communications WHERE title = '${titolo}' ORDER BY id DESC LIMIT 1`)
  .map((r) => ({ ...r, is_published: ['1', 't'].includes(r.is_published), is_global: ['1', 't'].includes(r.is_global), expire_date: r.expire_date === 'NULL' ? null : r.expire_date }))[0];
const allegati = (fn: typeof mariadb, titolo: string) => fn(
  `SELECT a.original_name, a.size_bytes, a.is_inline_image FROM communication_attachments a JOIN communications c ON c.id = a.communication_id
    WHERE c.title = '${titolo}' ORDER BY a.id`).map((r) => ({ ...r, is_inline_image: ['1', 't'].includes(r.is_inline_image) }));
const PDF = Buffer.from('%PDF-1.4\n1 0 obj<<>>endobj\ntrailer<<>>\n%%EOF\n');

beforeAll(() => ripristina());

describe('elenco e dettaglio', () => {
  it.each([[''], ['?search=parcheggio'], ['?search=ORARI']])('stesse comunicazioni e letture (%s)', async (q) => {
    const [p, n] = await Promise.all([phpEntra('hr.alfa'), nodeEntra('hr.alfa')]);
    const html = (await p.b.chiama(PAGINA + q)).testo;
    const titoli = [...html.matchAll(/<td data-label="Titolo">\s*<a href="\?action=view&id=\d+">([^<]*)</g)].map((m) => decodifica(m[1]));
    const letture = [...html.matchAll(/class="cm-reads">(\d+)\/(\d+)/g)].map((m) => `${m[1]}/${m[2]}`);
    const V = (await n.get(API + q)).json.V;
    expect(V.communications.map((c: any) => c.title)).toEqual(titoli);
    expect(V.communications.map((c: any) => `${c.read_count}/${c.total_employees}`)).toEqual(letture);
    expect(V.__commActive).toBe(Number(/Hai (\d+) comunicazion/.exec(html)?.[1] ?? 0));
  });

  it('dettaglio con statistiche e testo ripulito', async () => {
    const [p, n] = await Promise.all([phpEntra('hr.alfa'), nodeEntra('hr.alfa')]);
    const html = (await p.b.chiama(`${PAGINA}?action=view&id=${com(mariadb, 'Nuovi orari di apertura')}`)).testo;
    const V = (await n.get(`${API}?action=view&id=${com(postgres, 'Nuovi orari di apertura')}`)).json.V;
    const contenuto = /<div class="cm-view-content cm-rich">([\s\S]*?)<\/div>\s*\n\s*(?:<div class="cm-view-attachments">|<div class="cm-view-actions">)/.exec(html)![1];
    // correzione: il testo dei tag eliminati (es. <script>) non resta nella pagina
    expect(V.communication.content).toBe(contenuto.replace('alert(3)', ''));
    expect(V.readStats.percentage).toBe(Number(/(\d+(?:\.\d+)?)% lette/.exec(html)![1]));
    expect(V.attachments.map((a: any) => a.original_name)).toEqual([...html.matchAll(/<span class="cm-att-name">([^<]*)</g)].map((m) => decodifica(m[1])));
  });

  it('correzione: comunicazione di un\'altra azienda non apribile', async () => {
    const n = await nodeEntra('hr.alfa');
    const altra = com(postgres, 'Inventario di fine anno');
    expect((await n.get(`${API}?action=view&id=${altra}`)).json).toEqual({ redirect: '/admin/communications.php?error=not_found' });
    expect((await n.get(`${API}?action=edit&id=${altra}`)).json).toEqual({ redirect: '/admin/communications.php?error=not_found' });
    expect(await nodePost(n, '', { action: 'delete', id: altra })).toEqual({ error: 'Comunicazione non trovata' });
  });
});

describe('creazione e modifica', () => {
  it('nuova comunicazione con allegato e testo da ripulire', async () => {
    const [p, n] = await Promise.all([phpEntra('hr.alfa'), nodeEntra('hr.alfa')]);
    const campi = { action: 'create', title: 'Turni di dicembre', content: '<p>Ecco i <b>turni</b><script>alert(1)</script></p>', priority: 'high',
      is_published: 'on', publish_date: '2026-10-23', expire_date: '2026-12-31' };
    const f = [{ campo: 'attachments[]', nome: 'turni.pdf', dati: PDF, tipo: 'application/pdf' }];
    const a = await phpPost(p, '', campi, f);
    const b = await nodePost(n, '', campi, f);
    expect(a.redirect).toMatch(/^\/admin\/communications\.php\?action=edit&id=\d+&message=created$/);
    expect(b.redirect).toBe(`/admin/communications.php?action=edit&id=${com(postgres, 'Turni di dicembre')}&message=created`);
    // correzione: del <script> non resta nemmeno il testo
    expect(riga(postgres, 'Turni di dicembre')).toEqual({ ...riga(mariadb, 'Turni di dicembre'), content: '<p>Ecco i <b>turni</b></p>' });
    expect(allegati(postgres, 'Turni di dicembre')).toEqual(allegati(mariadb, 'Turni di dicembre'));
  });

  it('modifica, poi elimina allegato', async () => {
    const [p, n] = await Promise.all([phpEntra('hr.alfa'), nodeEntra('hr.alfa')]);
    const campi = { action: 'update', title: 'Turni di dicembre', content: '<p>Turni aggiornati</p>', priority: 'normal', is_published: 'on', publish_date: '2026-10-24', expire_date: '' };
    const a = await phpPost(p, `?action=edit&id=${com(mariadb, 'Turni di dicembre')}`, campi);
    const b = await nodePost(n, `?action=edit&id=${com(postgres, 'Turni di dicembre')}`, campi);
    expect(b.redirect).toBe(a.redirect!.replace(/id=\d+/, `id=${com(postgres, 'Turni di dicembre')}`));
    expect(riga(postgres, 'Turni di dicembre')).toEqual(riga(mariadb, 'Turni di dicembre'));
    const att = (fn: typeof mariadb) => fn(`SELECT a.id FROM communication_attachments a JOIN communications c ON c.id = a.communication_id WHERE c.title = 'Turni di dicembre'`)[0].id;
    await phpPost(p, '', { action: 'delete_attachment', id: com(mariadb, 'Turni di dicembre'), attachment_id: att(mariadb) });
    await nodePost(n, '', { action: 'delete_attachment', id: com(postgres, 'Turni di dicembre'), attachment_id: att(postgres) });
    expect(allegati(postgres, 'Turni di dicembre')).toEqual(allegati(mariadb, 'Turni di dicembre'));
  });

  it.each([
    ['senza titolo', { action: 'create', title: '', content: '<p>x</p>', publish_date: '2026-10-23' }],
    ['scadenza prima della pubblicazione', { action: 'create', title: 'X', content: '<p>x</p>', publish_date: '2026-10-23', expire_date: '2026-10-01' }],
  ])('errore: %s', async (_n, campi) => {
    const [p, n] = await Promise.all([phpEntra('hr.alfa'), nodeEntra('hr.alfa')]);
    const a = await phpPost(p, '', campi as Record<string, string>);
    expect(a.error).not.toBe('');
    expect(await nodePost(n, '', campi as Record<string, string>)).toEqual(a);
  });

  it('correzione: togliere la pubblicazione in modifica funziona (il PHP dava errore)', async () => {
    const [p, n] = await Promise.all([phpEntra('hr.alfa'), nodeEntra('hr.alfa')]);
    const campi = { action: 'update', title: 'Turni di dicembre', content: '<p>In revisione</p>', priority: 'normal', publish_date: '2026-10-24', expire_date: '' };
    expect(await phpPost(p, `?action=edit&id=${com(mariadb, 'Turni di dicembre')}`, campi)).toEqual({ error: "Errore durante l'aggiornamento" });
    expect((await nodePost(n, `?action=edit&id=${com(postgres, 'Turni di dicembre')}`, campi)).redirect).toMatch(/message=updated$/);
    expect(riga(postgres, 'Turni di dicembre').is_published).toBe(false);
  });

  it('correzione: una bozza resta bozza', async () => {
    const n = await nodeEntra('hr.alfa');
    await nodePost(n, '', { action: 'create', title: 'Solo bozza', content: '<p>bozza</p>', publish_date: '2026-10-23' });
    expect(riga(postgres, 'Solo bozza').is_published).toBe(false);
  });

  it('eliminazione', async () => {
    const [p, n] = await Promise.all([phpEntra('hr.alfa'), nodeEntra('hr.alfa')]);
    const a = await phpPost(p, '', { action: 'delete', id: com(mariadb, 'Turni di dicembre') });
    expect(a).toEqual({ redirect: '/admin/communications.php?message=deleted' });
    expect(await nodePost(n, '', { action: 'delete', id: com(postgres, 'Turni di dicembre') })).toEqual(a);
    expect(com(postgres, 'Turni di dicembre')).toBe(com(mariadb, 'Turni di dicembre'));
  });
});

describe('caricamento dall\'editor', () => {
  const PNG = Buffer.from('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg==', 'base64');
  async function carica(n: Node, campi: Record<string, string>, f?: File) {
    const fd = new FormData();
    for (const [k, v] of Object.entries(campi)) fd.append(k, v);
    if (f) fd.append(f.campo, new Blob([new Uint8Array(f.dati)], { type: f.tipo }), f.nome);
    const r = await n.b.chiama('/admin/communications_upload.php', { metodo: 'POST', corpo: fd as any, header: { 'X-CSRF-Token': n.b.valoreCookie('cora_csrf')! } });
    return { status: r.status, json: JSON.parse(r.testo) };
  }

  it('immagine nel testo: risposta come il PHP', async () => {
    const n = await nodeEntra('hr.alfa');
    const id = com(postgres, 'Nuovi orari di apertura');
    const r = await carica(n, { communication_id: id, type: 'image' }, { campo: 'file', nome: 'foto.png', dati: PNG, tipo: 'image/png' });
    expect(r.json).toMatchObject({ success: true, name: 'foto.png', size: PNG.length });
    expect(r.json.url).toMatch(/^\/communication_file\.php\?id=\d+&inline=1$/);
    expect((await n.b.chiama(r.json.url)).status).toBe(200);
  });

  it('errori e correzione: comunicazione di un\'altra azienda', async () => {
    const n = await nodeEntra('hr.alfa');
    expect((await carica(n, {})).json).toEqual({ success: false, error: 'Comunicazione non specificata' });
    expect((await carica(n, { communication_id: com(postgres, 'Inventario di fine anno') }, { campo: 'file', nome: 'a.pdf', dati: PDF, tipo: 'application/pdf' })).json)
      .toEqual({ success: false, error: 'Comunicazione non trovata' });
    expect((await carica(n, { communication_id: com(postgres, 'Nuovi orari di apertura'), type: 'image' }, { campo: 'file', nome: 'x.png', dati: Buffer.from('<svg/>'), tipo: 'image/png' })).json)
      .toEqual({ success: false, error: 'Tipo file non consentito' });
    const d = await nodeEntra('giulia.ferraresi');
    expect((await carica(d, { communication_id: '1' })).status).toBe(403);
  });
});
