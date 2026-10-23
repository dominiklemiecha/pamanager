import { mariadb, NODE_URL, PHP_URL, postgres, ripristina } from './ambiente';
import { Node, Php } from './clienti';

/**
 * Comunicazioni del dipendente: elenco, apertura (lettura automatica o
 * conferma "Ho letto"), pulizia dell'HTML e allegati, sul PHP e sul nuovo
 * backend. Le correzioni volute sono scritte nei test.
 */
const PW = 'Prova!Cora2026';

async function phpEntra(u: string) { const p = new Php(); await p.login(u, PW); return p; }
async function nodeEntra(u: string) { const n = new Node(); await n.login(u, PW); return n; }

const idDi = (fn: typeof mariadb, titolo: string) => Number(fn(`SELECT id FROM communications WHERE title = '${titolo}'`)[0].id);
const titoliPhp = (html: string) => [...html.matchAll(/class="cora-comm-card"[\s\S]*?<strong>([^<]+)<\/strong>/g)].map((m) => m[1].replace(/&#039;/g, "'")).sort();
const letture = (fn: typeof mariadb) => fn(`SELECT c.title, e.last_name FROM communication_reads r JOIN communications c ON c.id = r.communication_id JOIN employees e ON e.id = r.employee_id ORDER BY c.title, e.last_name`);

async function file(base: string, b: Php['b'] | Node['b'], percorso: string) {
  const cookie = (b as any).cookie as Map<string, string>;
  const r = await fetch(base + percorso, { redirect: 'manual', headers: { 'User-Agent': b.userAgent, 'X-Forwarded-For': (b as any).ip, Cookie: [...cookie].map(([k, v]) => `${k}=${v}`).join('; ') } });
  return { status: r.status, tipo: r.headers.get('content-type'), disp: r.headers.get('content-disposition'), byte: Buffer.from(await r.arrayBuffer()) };
}

beforeAll(() => ripristina());

describe('elenco', () => {
  it('stesse comunicazioni per un dipendente del reparto', async () => {
    const [p, n] = await Promise.all([phpEntra('giulia.ferraresi'), nodeEntra('giulia.ferraresi')]);
    const a = titoliPhp((await p.b.chiama('/employee/communications.php')).testo);
    const b = (await n.get('/dipendente/comunicazioni')).json.map((c: any) => c.title).sort();
    expect(a.length).toBeGreaterThan(0);
    expect(b).toEqual(a);
  });

  it('correzione: le comunicazioni di un altro reparto non compaiono', async () => {
    const [p, n] = await Promise.all([phpEntra('sara.montanari'), nodeEntra('sara.montanari')]);
    const a = titoliPhp((await p.b.chiama('/employee/communications.php')).testo);
    const b = (await n.get('/dipendente/comunicazioni')).json.map((c: any) => c.title).sort();
    expect(a).toContain('Riunione commerciale');
    expect(b).toEqual(a.filter((t) => t !== 'Riunione commerciale'));
    // E non si apre nemmeno conoscendo l'id.
    expect((await n.get(`/dipendente/comunicazioni/${idDi(postgres, 'Riunione commerciale')}`)).status).toBe(404);
  });
});

describe('apertura', () => {
  it('priorita\' normale: letta all\'apertura', async () => {
    const [p, n] = await Promise.all([phpEntra('giulia.ferraresi'), nodeEntra('giulia.ferraresi')]);
    await p.b.chiama(`/employee/communications.php?id=${idDi(mariadb, 'Nuovo regolamento parcheggio')}`);
    const r = await n.get(`/dipendente/comunicazioni/${idDi(postgres, 'Nuovo regolamento parcheggio')}`);
    expect(r.status).toBe(200);
    expect(r.json.letta).toBe(true);
    expect(letture(postgres)).toEqual(letture(mariadb));
  });

  it('priorita\' alta: serve "Ho letto"', async () => {
    const [p, n] = await Promise.all([phpEntra('marco.bellandi'), nodeEntra('marco.bellandi')]);
    const im = idDi(mariadb, 'Chiusura aziendale di Natale');
    const ip = idDi(postgres, 'Chiusura aziendale di Natale');
    const pagina = await p.b.chiama(`/employee/communications.php?id=${im}`);
    const r = await n.get(`/dipendente/comunicazioni/${ip}`);
    expect(pagina.testo).toContain('class="cora-ack"');
    expect(r.json.letta).toBe(false);
    expect(letture(postgres)).toEqual(letture(mariadb));

    const csrf = /name="csrf_token" value="([^"]+)"/.exec(pagina.testo)?.[1] ?? '';
    const a = await p.b.chiama(`/employee/communications.php?id=${im}`, { metodo: 'POST', corpo: new URLSearchParams({ csrf_token: csrf, action: 'ack' }).toString(), tipo: 'application/x-www-form-urlencoded' });
    const b = await n.post(`/dipendente/comunicazioni/${ip}/conferma`, {});
    expect(a.status).toBe(302);
    expect(a.location).toContain('message=ack');
    expect(b.status).toBe(200);
    expect(letture(postgres)).toEqual(letture(mariadb));
    expect((await n.get(`/dipendente/comunicazioni/${ip}`)).json.letta).toBe(true);
  });

  it('"Ho letto" non vale per le comunicazioni normali', async () => {
    const n = await nodeEntra('marco.bellandi');
    expect((await n.post(`/dipendente/comunicazioni/${idDi(postgres, 'Nuovi orari di apertura')}/conferma`, {})).status).toBe(404);
  });

  it('comunicazione di un\'altra azienda: non si apre', async () => {
    const [p, n] = await Promise.all([phpEntra('giulia.ferraresi'), nodeEntra('giulia.ferraresi')]);
    const a = await p.b.chiama(`/employee/communications.php?id=${idDi(mariadb, 'Inventario di fine anno')}`);
    const b = await n.get(`/dipendente/comunicazioni/${idDi(postgres, 'Inventario di fine anno')}`);
    expect(a.testo).not.toContain('cora-comm-body');
    expect(b.status).toBe(404);
    expect(letture(postgres)).toEqual(letture(mariadb));
  });
});

describe('testo formattato', () => {
  it('stessa formattazione, niente codice', async () => {
    const [p, n] = await Promise.all([phpEntra('giulia.ferraresi'), nodeEntra('giulia.ferraresi')]);
    const html = (await p.b.chiama(`/employee/communications.php?id=${idDi(mariadb, 'Nuovi orari di apertura')}`)).testo;
    const corpoPhp = /<div class="cora-comm-body cm-rich">([\s\S]*?)<\/div>\s*\n/.exec(html)?.[1] ?? '';
    const corpo = (await n.get(`/dipendente/comunicazioni/${idDi(postgres, 'Nuovi orari di apertura')}`)).json.content as string;
    const tag = (s: string) => [...s.matchAll(/<([a-z0-9]+)/g)].map((m) => m[1]);
    expect(tag(corpo)).toEqual(tag(corpoPhp).filter((t) => t !== 'script'));
    for (const vietato of ['javascript:', 'onclick', 'style=', '<script', '<!--', 'alert(3)']) expect(corpo).not.toContain(vietato);
    expect(corpo).toContain('<a href="https://esempio.test/orari" target="_blank" rel="noopener noreferrer">sito</a>');
    expect(corpo).toContain('<a href="mailto:hr@cora.test">HR</a>');
  });
});

describe('allegati', () => {
  it('il dipendente scarica l\'allegato della sua comunicazione', async () => {
    const [p, n] = await Promise.all([phpEntra('giulia.ferraresi'), nodeEntra('giulia.ferraresi')]);
    const ida = (fn: typeof mariadb) => fn(`SELECT id FROM communication_attachments WHERE original_name = 'Orari 2026.pdf'`)[0].id;
    const [a, b] = [await file(PHP_URL, p.b, `/communication_file.php?id=${ida(mariadb)}`), await file(NODE_URL, n.b, `/communication_file.php?id=${ida(postgres)}`)];
    expect(a.status).toBe(200);
    expect(b.status).toBe(200);
    expect(b.byte.equals(a.byte)).toBe(true);
    expect(b.tipo).toContain('application/pdf');
    expect(b.disp).toBe(a.disp);
  });

  it('correzione: un dipendente di un\'altra azienda non lo scarica', async () => {
    const [p, n] = await Promise.all([phpEntra('anna.castellari'), nodeEntra('anna.castellari')]);
    const ida = (fn: typeof mariadb) => fn(`SELECT id FROM communication_attachments WHERE original_name = 'Orari 2026.pdf'`)[0].id;
    const [a, b] = [await file(PHP_URL, p.b, `/communication_file.php?id=${ida(mariadb)}`), await file(NODE_URL, n.b, `/communication_file.php?id=${ida(postgres)}`)];
    expect(a.status).toBe(200); // falla del PHP
    expect(b.status).toBe(404);
    expect(b.byte.slice(0, 5).toString()).not.toBe('%PDF-');
  });

  it('senza accesso: niente file', async () => {
    const b = await file(NODE_URL, new Node().b, '/communication_file.php?id=1');
    expect([401, 403]).toContain(b.status);
  });
});
