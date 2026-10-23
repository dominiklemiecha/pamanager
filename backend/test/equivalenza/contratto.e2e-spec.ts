import { mariadb, NODE_URL, PHP_URL, postgres, ripristina } from './ambiente';
import { Node, Php } from './clienti';

// eslint-disable-next-line @typescript-eslint/no-require-imports
const sharp: (input?: any, o?: any) => any = require('sharp');

/**
 * Firma del contratto di assunzione: blocco del portale finche' non si firma,
 * PDF incorporato, firma, contratto firmato tra i documenti e avvisi.
 */
const PW = 'Prova!Cora2026';

async function phpEntra(u: string) { const p = new Php(); await p.login(u, PW); return p; }
async function nodeEntra(u: string) { const n = new Node(); await n.login(u, PW); return n; }
const pratica = (fn: typeof mariadb) => Number(fn(`SELECT id FROM hire_requests ORDER BY id LIMIT 1`)[0].id);

async function prendi(base: string, b: Php['b'] | Node['b'], percorso: string) {
  const cookie = (b as any).cookie as Map<string, string>;
  const r = await fetch(base + percorso, { redirect: 'manual', headers: { 'User-Agent': b.userAgent, 'X-Forwarded-For': (b as any).ip, Cookie: [...cookie].map(([k, v]) => `${k}=${v}`).join('; ') } });
  return { status: r.status, tipo: r.headers.get('content-type'), frame: r.headers.get('x-frame-options'), location: r.headers.get('location'), byte: Buffer.from(await r.arrayBuffer()) };
}

async function phpFirma(p: Php, id: number, firma: string) {
  const pagina = await p.b.chiama(`/employee/contract-sign.php?id=${id}`);
  const csrf = /name="csrf_token" value="([^"]+)"/.exec(pagina.testo)?.[1] ?? '';
  const r = await p.b.chiama(`/employee/contract-sign.php?id=${id}`, { metodo: 'POST', corpo: new URLSearchParams({ csrf_token: csrf, action: 'sign', signature: firma }).toString(), tipo: 'application/x-www-form-urlencoded' });
  if (r.status === 302) return 'ok';
  return /class="sign-modal__err">([^<]+)</.exec(r.testo)?.[1] ?? `HTTP ${r.status}`;
}
async function nodeFirma(n: Node, id: number, firma: string) {
  const r = await n.post(`/dipendente/contratto/${id}/firma`, { signature: firma });
  return r.status === 200 ? 'ok' : r.json?.message;
}

beforeAll(() => ripristina());

describe('contratto da firmare', () => {
  it('il portale porta alla firma', async () => {
    const [p, n] = await Promise.all([phpEntra('laura.zanichelli'), nodeEntra('laura.zanichelli')]);
    const a = await p.b.chiama('/employee/documents.php');
    expect(a.status).toBe(302);
    expect(a.location).toContain(`contract-sign.php?id=${pratica(mariadb)}`);
    expect((await n.get('/dipendente/cornice')).json.contrattoDaFirmare).toBe(pratica(postgres));
  });

  it('PDF del contratto solo al dipendente, incorporabile nella pagina', async () => {
    const [p, n] = await Promise.all([phpEntra('laura.zanichelli'), nodeEntra('laura.zanichelli')]);
    const [a, b] = [await prendi(PHP_URL, p.b, `/employee/contract-pdf.php?id=${pratica(mariadb)}`), await prendi(NODE_URL, n.b, `/employee/contract-pdf.php?id=${pratica(postgres)}`)];
    expect(b.status).toBe(200);
    expect(b.byte.equals(a.byte)).toBe(true);
    expect(b.frame).toBe('SAMEORIGIN');
    const [p2, n2] = await Promise.all([phpEntra('anna.castellari'), nodeEntra('anna.castellari')]);
    expect((await prendi(NODE_URL, n2.b, `/employee/contract-pdf.php?id=${pratica(postgres)}`)).status).toBe((await prendi(PHP_URL, p2.b, `/employee/contract-pdf.php?id=${pratica(mariadb)}`)).status);
    expect((await n2.get(`/dipendente/contratto/${pratica(postgres)}`)).status).toBe(404);
  });

  it('firme non valide: stessi errori', async () => {
    const [p, n] = await Promise.all([phpEntra('laura.zanichelli'), nodeEntra('laura.zanichelli')]);
    for (const firma of ['', 'data:image/jpeg;base64,AAAA', 'data:image/png;base64,AAAA']) {
      expect(await nodeFirma(n, pratica(postgres), firma)).toBe(await phpFirma(p, pratica(mariadb), firma));
    }
  });

  it('firma: stato, file, documento firmato e avvisi', async () => {
    const png: Buffer = await sharp({ create: { width: 600, height: 200, channels: 4, background: { r: 0, g: 0, b: 0, alpha: 0 } } })
      .composite([{ input: Buffer.from('<svg width="600" height="200"><path d="M20 150 C 120 20, 220 180, 320 60 S 520 160, 580 40" stroke="#0f172a" stroke-width="5" fill="none"/></svg>') }])
      .png().toBuffer();
    const firma = 'data:image/png;base64,' + png.toString('base64');
    const [p, n] = await Promise.all([phpEntra('laura.zanichelli'), nodeEntra('laura.zanichelli')]);
    expect(await phpFirma(p, pratica(mariadb), firma)).toBe('ok');
    expect(await nodeFirma(n, pratica(postgres), firma)).toBe('ok');

    const stato = (fn: typeof mariadb) => fn(`SELECT status FROM hire_requests`)[0].status;
    expect(stato(postgres)).toBe('contract_signed');
    expect(stato(mariadb)).toBe('contract_signed');
    const file = (fn: typeof mariadb) => fn(`SELECT category, file_path, original_name, mime_type, file_size, signature_hash FROM hire_request_files ORDER BY id`)
      .map((r) => ({ ...r, file_path: r.file_path.replace(/^hire-requests\/\d+\//, ''), file_size: r.category === 'signed_contract' ? 'PDF generato' : r.file_size }));
    expect(file(postgres)).toEqual(file(mariadb));
    const doc = (fn: typeof mariadb) => fn(`SELECT d.type, d.title, d.original_name, d.mime_type, d.month, d.year, LEFT(d.description, 30) AS inizio FROM documents d JOIN employees e ON e.id = d.employee_id WHERE e.last_name = 'Zanichelli'`);
    expect(doc(postgres)).toEqual(doc(mariadb));
    const avvisi = (fn: typeof mariadb) => fn(`SELECT recipient_type, recipient_id, type, title, message, link FROM notifications WHERE type = 'contract_signed' ORDER BY recipient_type, recipient_id`);
    expect(avvisi(postgres).length).toBeGreaterThan(0);
    expect(avvisi(postgres)).toEqual(avvisi(mariadb));

    // Firmato: il portale torna libero e la firma si vede
    expect((await n.get('/dipendente/cornice')).json.contrattoDaFirmare).toBeNull();
    const img = await prendi(NODE_URL, n.b, `/api/v1/dipendente/contratto/${pratica(postgres)}/firma`);
    expect(img.status).toBe(200);
    expect(img.byte.equals(png)).toBe(true);
    // Non si firma due volte (stesso messaggio di HireRequest::signContract; la pagina PHP
    // firmata non ha piu' il modulo, quindi lato PHP si verifica direttamente la classe).
    expect(await nodeFirma(n, pratica(postgres), firma)).toBe('Stato non valido per firmare');
  });
});
