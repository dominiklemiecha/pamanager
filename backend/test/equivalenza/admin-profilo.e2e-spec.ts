import { mariadb, postgres, ripristina } from './ambiente';
import { Node, Php } from './clienti';

/** Profilo dello staff (admin/profile.php): nome ed email, foto, password. */
const PW = 'Prova!Cora2026';
const PAGINA = '/admin/profile.php';
const API = '/pagine/admin/profile';

async function phpEntra(u: string) { const p = new Php(); await p.login(u, PW); return p; }
async function nodeEntra(u: string) { const n = new Node(); await n.login(u, PW); return n; }
const decodifica = (s: string) => s.replace(/&#0?39;/g, "'").replace(/&quot;/g, '"').replace(/&amp;/g, '&').replace(/&lt;/g, '<').replace(/&gt;/g, '>');

type File = { campo: string; nome: string; dati: Buffer; tipo: string };
async function phpPost(p: Php, campi: Record<string, string>, file?: File) {
  const html = (await p.b.chiama(PAGINA)).testo;
  const fd = new FormData();
  fd.append('csrf_token', /name="csrf_token" value="([^"]+)"/.exec(html)?.[1] ?? '');
  for (const [k, v] of Object.entries(campi)) fd.append(k, v);
  if (file) fd.append(file.campo, new Blob([new Uint8Array(file.dati)], { type: file.tipo }), file.nome);
  const r = await p.b.chiama(PAGINA, { metodo: 'POST', corpo: fd as any });
  if (r.location) { const u = new URL(r.location, `http://x${PAGINA}`); return { redirect: u.pathname + u.search }; }
  return { error: decodifica(/alert alert-danger"[^>]*>([^<]*)</.exec(r.testo)?.[1] ?? '') };
}
async function nodePost(n: Node, campi: Record<string, string>, file?: File) {
  const fd = new FormData();
  for (const [k, v] of Object.entries(campi)) fd.append(k, v);
  if (file) fd.append(file.campo, new Blob([new Uint8Array(file.dati)], { type: file.tipo }), file.nome);
  const r = await n.b.chiama('/api/v1' + API, { metodo: 'POST', corpo: fd as any, header: { 'X-CSRF-Token': n.b.valoreCookie('cora_csrf')! } });
  const j = JSON.parse(r.testo);
  return j.redirect ? { redirect: j.redirect } : { error: j.V.error };
}
const utente = (fn: typeof mariadb, u: string) => fn(`SELECT name, email, photo_path IS NOT NULL AS foto FROM users WHERE username = '${u}'`)
  .map((r) => ({ ...r, foto: ['1', 't'].includes(r.foto) }))[0];

// PNG 1x1 valido
const PNG = Buffer.from('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg==', 'base64');

beforeAll(() => ripristina());

describe('dati dell\'account', () => {
  it('nome ed email', async () => {
    const [p, n] = await Promise.all([phpEntra('studio.consulente'), nodeEntra('studio.consulente')]);
    const campi = { action: 'update_info', name: '  Studio Bianchi  ', email: 'studio@bianchi.test' };
    const a = await phpPost(p, campi);
    expect(a).toEqual({ redirect: '/admin/profile.php?message=updated' });
    expect(await nodePost(n, campi)).toEqual(a);
    expect(utente(postgres, 'studio.consulente')).toEqual(utente(mariadb, 'studio.consulente'));
  });

  it.each([[{ name: '' }], [{ name: 'X', email: 'non-una-email' }]])('errore %j', async (c) => {
    const [p, n] = await Promise.all([phpEntra('hr.alfa'), nodeEntra('hr.alfa')]);
    const campi = { action: 'update_info', ...c } as Record<string, string>;
    const a = await phpPost(p, campi);
    expect(a.error).not.toBe('');
    expect(await nodePost(n, campi)).toEqual(a);
  });

  it('solo staff', async () => {
    expect((await (await nodeEntra('giulia.ferraresi')).get(API)).status).toBe(403);
    expect((await (await nodeEntra('resp.commerciale')).get(API)).status).toBe(403);
    expect((await (await nodeEntra('studio.commercialista')).get(API)).status).toBe(200);
  });
});

describe('foto', () => {
  it('caricamento e rimozione', async () => {
    const [p, n] = await Promise.all([phpEntra('hr.alfa'), nodeEntra('hr.alfa')]);
    const f = { campo: 'photo', nome: 'io.png', dati: PNG, tipo: 'image/png' };
    const a = await phpPost(p, { action: 'upload_photo' }, f);
    expect(a).toEqual({ redirect: '/admin/profile.php?message=photo' });
    expect(await nodePost(n, { action: 'upload_photo' }, f)).toEqual(a);
    expect(utente(postgres, 'hr.alfa')).toEqual(utente(mariadb, 'hr.alfa'));
    const V = (await n.get(API)).json.V;
    expect(V.user.photo_path).toMatch(/^uploads\/users\/u\d+_[0-9a-f]{8}\.webp$/);
    expect((await n.b.chiama('/' + V.user.photo_path)).status).toBe(200);
    const b = await phpPost(p, { action: 'remove_photo' });
    expect(await nodePost(n, { action: 'remove_photo' })).toEqual(b);
    expect(utente(postgres, 'hr.alfa')).toEqual(utente(mariadb, 'hr.alfa'));
  });

  it('formato non ammesso', async () => {
    const [p, n] = await Promise.all([phpEntra('hr.alfa'), nodeEntra('hr.alfa')]);
    const f = { campo: 'photo', nome: 'doc.pdf', dati: Buffer.from('%PDF-1.4'), tipo: 'application/pdf' };
    expect(await nodePost(n, { action: 'upload_photo' }, f)).toEqual(await phpPost(p, { action: 'upload_photo' }, f));
  });

  it('correzione: un file non immagine con estensione .png e\' rifiutato', async () => {
    const n = await nodeEntra('hr.alfa');
    const r = await nodePost(n, { action: 'upload_photo' }, { campo: 'photo', nome: 'finta.png', dati: Buffer.from('<?php echo 1;'), tipo: 'image/png' });
    expect(r).toEqual({ error: 'Solo immagini JPG, PNG, WEBP' });
  });
});

describe('password', () => {
  it.each([
    ['conferma diversa', { current_password: PW, new_password: 'Nuova!Pass2026', confirm_password: 'Altra!Pass2026' }],
    ['attuale sbagliata', { current_password: 'Sbagliata!1', new_password: 'Nuova!Pass2026', confirm_password: 'Nuova!Pass2026' }],
    ['troppo semplice', { current_password: PW, new_password: 'corta', confirm_password: 'corta' }],
    ['uguale alla precedente', { current_password: PW, new_password: PW, confirm_password: PW }],
  ])('errore: %s', async (_n, campi) => {
    const [p, n] = await Promise.all([phpEntra('hr.beta'), nodeEntra('hr.beta')]);
    const a = await phpPost(p, { action: 'change_password', ...campi });
    expect(a.error).not.toBe('');
    expect(await nodePost(n, { action: 'change_password', ...campi })).toEqual(a);
  });

  it('cambio riuscito', async () => {
    const [p, n] = await Promise.all([phpEntra('hr.beta'), nodeEntra('hr.beta')]);
    const campi = { action: 'change_password', current_password: PW, new_password: 'Nuova!Pass2026', confirm_password: 'Nuova!Pass2026' };
    const a = await phpPost(p, campi);
    expect(a).toEqual({ redirect: '/admin/profile.php?message=password' });
    expect(await nodePost(n, campi)).toEqual(a);
    const n2 = new Node();
    expect((await n2.login('hr.beta', 'Nuova!Pass2026')).esito).toBe('ok');
  });
});
