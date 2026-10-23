import * as fs from 'fs';
import * as path from 'path';
import { mariadb, NODE_URL, PHP_URL, postgres, ripristina } from './ambiente';
import { Node, Php } from './clienti';

// eslint-disable-next-line @typescript-eslint/no-require-imports
const sharp: (input?: any, o?: any) => any = require('sharp');

/**
 * Profilo del dipendente: dati personali, foto, preferenze di notifica, sul
 * PHP (modulo della pagina) e sul nuovo backend (API).
 */
const PW = 'Prova!Cora2026';

async function phpEntra(u: string) { const p = new Php(); await p.login(u, PW); return p; }
async function nodeEntra(u: string) { const n = new Node(); await n.login(u, PW); return n; }

/** Invio di un modulo della pagina PHP: "ok:<message>" o il testo dell'errore. */
async function phpInvia(p: Php, campi: Record<string, string>, file?: { nome: string; dati: Buffer; tipo: string }) {
  const pagina = await p.b.chiama('/employee/profile.php');
  const csrf = /name="csrf_token" value="([^"]+)"/.exec(pagina.testo)?.[1] ?? '';
  const fd = new FormData();
  fd.append('csrf_token', csrf);
  for (const [k, v] of Object.entries(campi)) fd.append(k, v);
  if (file) fd.append('photo', new Blob([new Uint8Array(file.dati)], { type: file.tipo }), file.nome);
  const r = await p.b.chiama('/employee/profile.php', { metodo: 'POST', corpo: fd as any });
  if (r.status === 302) return 'ok:' + new URL(r.location!, 'http://x').searchParams.get('message');
  return /class="cora-alert is-error">([^<]+)</.exec(r.testo)?.[1]?.replace(/&#039;/g, "'") ?? `HTTP ${r.status}`;
}

const MESSAGGIO: Record<string, string> = { dati: 'updated', foto: 'photo_updated', 'foto/rimuovi': 'photo_removed', notifiche: 'notifications_updated' };
async function nodeInvia(n: Node, azione: string, campi: Record<string, string>, file?: { nome: string; dati: Buffer; tipo: string }) {
  const fd = new FormData();
  for (const [k, v] of Object.entries(campi)) fd.append(k, v);
  if (file) fd.append('photo', new Blob([new Uint8Array(file.dati)], { type: file.tipo }), file.nome);
  const r = await n.b.chiama(`/api/v1/dipendente/profilo/${azione}`, { metodo: 'POST', corpo: fd as any, header: { 'X-CSRF-Token': n.b.valoreCookie('cora_csrf')! } });
  if (r.status === 200) return 'ok:' + MESSAGGIO[azione];
  return JSON.parse(r.testo).message;
}

const riga = (fn: typeof mariadb, cognome: string): Record<string, any> => {
  const [r] = fn(`SELECT first_name, last_name, notify_email, notify_push, photo_path FROM employees WHERE last_name = '${cognome}'`);
  return { ...r, notify_email: ['1', 't'].includes(r.notify_email) ? 1 : 0, notify_push: ['1', 't'].includes(r.notify_push) ? 1 : 0 };
};

async function scarica(base: string, b: Php['b'] | Node['b'], percorso: string) {
  const cookie = (b as any).cookie as Map<string, string>;
  const r = await fetch(base + percorso, { headers: { 'User-Agent': b.userAgent, 'X-Forwarded-For': (b as any).ip, Cookie: [...cookie].map(([k, v]) => `${k}=${v}`).join('; ') } });
  return { status: r.status, tipo: r.headers.get('content-type'), byte: Buffer.from(await r.arrayBuffer()) };
}

beforeAll(() => ripristina());

describe('dati personali', () => {
  it.each([
    ['nome e cognome nuovi', { first_name: '  Giulia Maria ', last_name: 'Ferraresi' }],
    ['nome vuoto', { first_name: '   ', last_name: 'Ferraresi' }],
    ['cognome mancante', { first_name: 'Giulia' }],
  ])('%s', async (_n, campi: Record<string, string>) => {
    const [p, n] = await Promise.all([phpEntra('giulia.ferraresi'), nodeEntra('giulia.ferraresi')]);
    const [a, b] = [await phpInvia(p, { action: 'update_info', ...campi }), await nodeInvia(n, 'dati', campi)];
    expect(b).toBe(a);
    expect(riga(postgres, 'Ferraresi')).toEqual(riga(mariadb, 'Ferraresi'));
  });

  it('correzione: nome oltre 50 caratteri rifiutato con un messaggio chiaro', async () => {
    const n = await nodeEntra('marco.bellandi');
    expect(await nodeInvia(n, 'dati', { first_name: 'x'.repeat(51), last_name: 'Bellandi' })).toBe('Nome e cognome possono avere al massimo 50 caratteri');
  });

  it('l\'utente non puo\' cambiare altri campi', async () => {
    const n = await nodeEntra('marco.bellandi');
    await nodeInvia(n, 'dati', { first_name: 'Marco', last_name: 'Bellandi', fiscal_code: 'XXXXXX', iban: 'IT00', email: 'x@y.z', is_active: '0' });
    const [r] = postgres(`SELECT fiscal_code, iban, email, is_active FROM employees WHERE last_name = 'Bellandi'`);
    const [m] = mariadb(`SELECT fiscal_code, iban, email, is_active FROM employees WHERE last_name = 'Bellandi'`);
    expect(r.fiscal_code).toBe(m.fiscal_code);
    expect(r.email).toBe(m.email);
    expect(r.is_active).toBe('t');
  });
});

describe('notifiche', () => {
  it.each([
    ['solo push', { notify_push: '1' }],
    ['tutte e due', { notify_email: '1', notify_push: '1' }],
    ['nessuna', {}],
  ])('%s', async (_n, campi: Record<string, string>) => {
    const [p, n] = await Promise.all([phpEntra('elena.gualtieri'), nodeEntra('elena.gualtieri')]);
    const [a, b] = [await phpInvia(p, { action: 'update_notifications', ...campi }), await nodeInvia(n, 'notifiche', campi)];
    expect(b).toBe(a);
    expect(riga(postgres, 'Gualtieri')).toEqual(riga(mariadb, 'Gualtieri'));
  });
});

describe('foto profilo', () => {
  let png: Buffer;
  beforeAll(async () => {
    png = await sharp({ create: { width: 400, height: 300, channels: 3, background: '#0b3aa4' } }).png().toBuffer();
  });

  it('caricata: WebP 256 px allo stesso tipo di percorso', async () => {
    const [p, n] = await Promise.all([phpEntra('paolo.vezzani'), nodeEntra('paolo.vezzani')]);
    const f = { nome: 'io.png', dati: png, tipo: 'image/png' };
    const [a, b] = [await phpInvia(p, { action: 'upload_photo' }, f), await nodeInvia(n, 'foto', {}, f)];
    expect(a).toBe('ok:photo_updated');
    expect(b).toBe(a);
    const [pp, np] = [riga(mariadb, 'Vezzani').photo_path, riga(postgres, 'Vezzani').photo_path];
    const forma = /^uploads\/co-1\/profile_photos\/emp_\d+_[0-9a-f]{8}\.webp$/;
    expect(pp).toMatch(forma);
    expect(np).toMatch(forma);
    const [fa, fb] = [await scarica(PHP_URL, p.b, '/' + pp), await scarica(NODE_URL, n.b, '/' + np)];
    expect(fb.status).toBe(200);
    expect(fb.tipo).toBe('image/webp');
    const [ma, mb] = [await sharp(fa.byte).metadata(), await sharp(fb.byte).metadata()];
    expect([mb.format, mb.width, mb.height]).toEqual([ma.format, ma.width, ma.height]);
  });

  it('correzione: la foto non si vede senza accesso o da un\'altra azienda', async () => {
    const np = riga(postgres, 'Vezzani').photo_path;
    expect((await scarica(NODE_URL, new Node().b, '/' + np)).status).toBe(401);
    const altra = await nodeEntra('anna.castellari');
    expect((await scarica(NODE_URL, altra.b, '/' + np)).status).toBe(404);
    const stessa = await nodeEntra('giulia.ferraresi');
    expect((await scarica(NODE_URL, stessa.b, '/' + np)).status).toBe(200);
  });

  it('rimossa: percorso vuoto e file cancellato', async () => {
    const [p, n] = await Promise.all([phpEntra('paolo.vezzani'), nodeEntra('paolo.vezzani')]);
    const np = riga(postgres, 'Vezzani').photo_path;
    const [a, b] = [await phpInvia(p, { action: 'remove_photo' }), await nodeInvia(n, 'foto/rimuovi', {})];
    expect(b).toBe(a);
    expect(riga(postgres, 'Vezzani').photo_path).toBeNull();
    expect(riga(mariadb, 'Vezzani').photo_path).toBeNull();
    expect(fs.existsSync(path.resolve(__dirname, '../../uploads', np.replace(/^uploads\//, '')))).toBe(false);
  });

  it.each([
    ['testo spacciato per immagine', () => ({ nome: 'finta.png', dati: Buffer.from('<?php echo 1; ?>'), tipo: 'image/png' })],
    ['SVG', () => ({ nome: 'x.svg', dati: Buffer.from('<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>'), tipo: 'image/svg+xml' })],
  ])('rifiutata: %s', async (_n, crea) => {
    const [p, n] = await Promise.all([phpEntra('paolo.vezzani'), nodeEntra('paolo.vezzani')]);
    const [a, b] = [await phpInvia(p, { action: 'upload_photo' }, crea()), await nodeInvia(n, 'foto', {}, crea())];
    expect(b).toBe(a);
  });

  it('rifiutata: oltre 3 MB', async () => {
    const grande = await sharp(Buffer.from(Array.from({ length: 1400 * 1400 * 3 }, () => Math.floor(Math.random() * 256))), { raw: { width: 1400, height: 1400, channels: 3 } }).png({ compressionLevel: 0 }).toBuffer();
    expect(grande.length).toBeGreaterThan(3 * 1024 * 1024);
    const [p, n] = await Promise.all([phpEntra('paolo.vezzani'), nodeEntra('paolo.vezzani')]);
    const f = { nome: 'grande.png', dati: grande, tipo: 'image/png' };
    const [a, b] = [await phpInvia(p, { action: 'upload_photo' }, f), await nodeInvia(n, 'foto', {}, f)];
    expect(b).toBe(a);
  });

  it('nessun file', async () => {
    const [p, n] = await Promise.all([phpEntra('paolo.vezzani'), nodeEntra('paolo.vezzani')]);
    const [a, b] = [await phpInvia(p, { action: 'upload_photo' }), await nodeInvia(n, 'foto', {})];
    expect(b).toBe(a);
  });
});

describe('dati mostrati', () => {
  it('IBAN gia\' mascherato dal server', async () => {
    postgres(`UPDATE employees SET iban = 'IT60X0542811101000000123456' WHERE last_name = 'Ferraresi'`);
    const n = await nodeEntra('giulia.ferraresi');
    const d = (await n.get('/dipendente/profilo')).json;
    expect(d.iban).toBe('IT60 X054 •••• •••• 3456');
    expect(JSON.stringify(d)).not.toContain('0542811101000000123456');
    expect(d).not.toHaveProperty('password_hash');
  });
});

describe('disattivazione email dal link', () => {
  const TOKEN = 'a1'.repeat(24);
  const statoPhp = (html: string) => /<h1[^>]*>([^<]+)<\/h1>/.exec(html)?.[1];
  const TITOLI: Record<string, string> = {
    confirm: 'Disattivare le notifiche email?', unsubscribed: 'Email disattivate', already: "Email gia' disattivate", invalid: 'Link non valido',
  };
  beforeAll(() => {
    for (const fn of [mariadb, postgres]) fn(`UPDATE employees SET email_unsubscribe_token = '${TOKEN}', notify_email = ${fn === mariadb ? 1 : 'true'} WHERE last_name = 'Pellegatti'`);
  });

  it('stessi passaggi: conferma, disattivata, gia\' disattivata, link non valido', async () => {
    const p = new Php();
    const n = new Node();
    const passi: Array<[string, string]> = [['GET', TOKEN], ['POST', TOKEN], ['GET', TOKEN], ['GET', 'zz'], ['GET', 'b2'.repeat(24)]];
    for (const [metodo, token] of passi) {
      const a = await p.b.chiama(`/employee/unsubscribe.php?token=${token}`, { metodo });
      const b = metodo === 'GET' ? await n.get(`/pubblico/disiscrizione?token=${token}`) : await n.post('/pubblico/disiscrizione', { token });
      expect(statoPhp(a.testo)).toBe(TITOLI[b.json.stato]);
    }
    expect(riga(postgres, 'Pellegatti').notify_email).toBe(0);
    expect(riga(mariadb, 'Pellegatti').notify_email).toBe(0);
  });
});
