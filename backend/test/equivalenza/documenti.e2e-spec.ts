import { execFileSync } from 'child_process';
import { mariadb, NODE_URL, PHP_URL, postgres, ripristina } from './ambiente';
import { Node, Php } from './clienti';

/**
 * Documenti del dipendente: elenco, download di buste paga (con filigrana) e
 * documenti personali, tracciamento, audit e blocchi di accesso, sul PHP
 * (employee/documents.php?download=) e sul nuovo backend (API).
 */
const PW = 'Prova!Cora2026';

async function phpEntra(u: string) { const p = new Php(); await p.login(u, PW); return p; }
async function nodeEntra(u: string) { const n = new Node(); await n.login(u, PW); return n; }

/** Download grezzo: stato, tipo e byte (il client di test legge solo testo). */
async function scarica(base: string, b: Php['b'] | Node['b'], percorso: string) {
  const cookie = (b as any).cookie as Map<string, string>;
  const r = await fetch(base + percorso, {
    redirect: 'manual',
    headers: { 'User-Agent': b.userAgent, 'X-Forwarded-For': (b as any).ip, Cookie: [...cookie].map(([k, v]) => `${k}=${v}`).join('; ') },
  });
  return { status: r.status, tipo: r.headers.get('content-type'), disp: r.headers.get('content-disposition'), location: r.headers.get('location'), csp: r.headers.get('content-security-policy'), byte: Buffer.from(await r.arrayBuffer()) };
}
const phpScarica = (p: Php, q: string) => scarica(PHP_URL, p.b, `/employee/documents.php?${q}`);
const nodeScarica = (n: Node, p: string) => scarica(NODE_URL, n.b, `/api/v1/dipendente/documenti/${p}`);

/** Testo del PDF (pdftotext della baseline). */
const testoPdf = (pdf: Buffer) => execFileSync('docker', ['exec', '-i', 'cora-baseline-app-1', 'pdftotext', '-layout', '-', '-'], { input: pdf }).toString();
const filigrana = (t: string) => /Scaricato da: .*? \| Data: \d\d\/\d\d\/\d{4} \d\d:\d\d:\d\d \| IP: .*? \| Doc ID: \d+/.exec(t.replace(/\s+/g, ' '))?.[0]
  ?.replace(/Data: [^|]+/, 'Data: X ').replace(/IP: [^|]+/, 'IP: X ');

/** Errore mostrato dalla pagina PHP dopo un download rifiutato. */
const erroreHtml = (b: Buffer) => /class="cora-alert is-error">([^<]+)</.exec(b.toString())?.[1];
const CODICI: Record<string, string> = { non_autorizzato: 'Accesso non autorizzato', non_trovato: 'Documento non trovato', limite: 'Limite download raggiunto. Riprova tra 60 minuti.' };
const erroreNode = (loc: string | null) => CODICI[new URL(loc ?? '/', 'http://x').searchParams.get('errore') ?? ''];

const id = (fn: typeof mariadb, tabella: string, nome: string, cognome: string) =>
  fn(`SELECT d.id FROM ${tabella} d JOIN employees e ON e.id = d.employee_id WHERE ${tabella === 'documents' ? 'd.title' : 'd.name'} = '${nome}' AND e.last_name = '${cognome}'`)[0].id;

beforeAll(() => ripristina());

describe('elenco', () => {
  it('stesse buste paga, documenti personali visibili e anni', async () => {
    const [p, n] = await Promise.all([phpEntra('giulia.ferraresi'), nodeEntra('giulia.ferraresi')]);
    const html = (await p.b.chiama('/employee/documents.php')).testo;
    const titoliPhp = [...html.matchAll(/<strong>([^<]+)<\/strong>\s*<span>([^<]+)<\/span>/g)].map((m) => m[1]).sort();
    const d = (await n.get('/dipendente/documenti')).json;
    const titoliNode = [...d.buste.map((x: any) => x.title), ...d.personali.map((x: any) => x.name)].sort();
    expect(titoliNode).toEqual(titoliPhp);
    expect(titoliNode).not.toContain('Nota riservata HR');
    const anniPhp = [...html.matchAll(/<option value="(\d+)"/g)].map((m) => Number(m[1]));
    const anniNode = [...new Set([...d.anni, ...d.buste.map((x: any) => x.year), ...d.personali.map((x: any) => Number(x.created_at.slice(0, 4)))])].sort((a, b) => b - a);
    expect(anniNode).toEqual(anniPhp);
  });
});

describe('download busta paga', () => {
  it('PDF con la stessa filigrana, stesse righe di tracciamento e audit', async () => {
    const [p, n] = await Promise.all([phpEntra('giulia.ferraresi'), nodeEntra('giulia.ferraresi')]);
    const [a, b] = [await phpScarica(p, `download=${id(mariadb, 'documents', 'Busta paga Settembre 2026', 'Ferraresi')}`),
      await nodeScarica(n, `${id(postgres, 'documents', 'Busta paga Settembre 2026', 'Ferraresi')}/scarica`)];
    expect(b.status).toBe(200);
    expect(a.status).toBe(200);
    expect(b.tipo).toBe(a.tipo);
    expect(b.disp).toBe(a.disp);
    expect(b.csp).toBe("default-src 'none'");
    expect(b.byte.slice(0, 5).toString()).toBe('%PDF-');
    const [fa, fb] = [filigrana(testoPdf(a.byte)), filigrana(testoPdf(b.byte))];
    expect(fa).toBeTruthy();
    expect(fb).toBe(fa);
    // Il contenuto originale resta leggibile.
    expect(testoPdf(b.byte)).toContain('Busta paga Settembre 2026 - Giulia Ferraresi');

    const tracciati = (fn: typeof mariadb) => fn(`SELECT dd.user_type, d.title FROM document_downloads dd JOIN documents d ON d.id = dd.document_id ORDER BY dd.id`);
    expect(tracciati(postgres)).toEqual(tracciati(mariadb));
    const audit = (fn: typeof mariadb) => fn(`SELECT user_type, action, entity_type, severity, new_values FROM audit_log WHERE action = 'document_download' ORDER BY id`)
      .map((r) => ({ ...r, new_values: JSON.parse(r.new_values) }));
    const [am, ap] = [audit(mariadb), audit(postgres)];
    expect(ap.length).toBe(1);
    expect({ ...ap[0], new_values: { ...ap[0].new_values, employee_id: 0 } }).toEqual({ ...am[0], new_values: { ...am[0].new_values, employee_id: 0 } });
  });

  it('dopo il download la busta non e\' piu\' "nuova"', async () => {
    const n = await nodeEntra('giulia.ferraresi');
    const d = (await n.get('/dipendente/documenti')).json;
    const scaricate = d.buste.filter((x: any) => x.is_downloaded).map((x: any) => x.title);
    expect(scaricate).toEqual(['Busta paga Settembre 2026']);
  });

  it('busta di un altro dipendente: rifiutata e segnalata', async () => {
    const [p, n] = await Promise.all([phpEntra('marco.bellandi'), nodeEntra('marco.bellandi')]);
    const [a, b] = [await phpScarica(p, `download=${id(mariadb, 'documents', 'CU 2025', 'Ferraresi')}`),
      await nodeScarica(n, `${id(postgres, 'documents', 'CU 2025', 'Ferraresi')}/scarica`)];
    expect(erroreHtml(a.byte)).toBe('Accesso non autorizzato');
    expect(b.status).toBe(302);
    expect(erroreNode(b.location)).toBe('Accesso non autorizzato');
    const allarmi = (fn: typeof mariadb) => fn(`SELECT user_type, severity FROM audit_log WHERE action = 'unauthorized_access' ORDER BY id`);
    expect(allarmi(postgres)).toEqual(allarmi(mariadb));
  });

  it('busta di un\'altra azienda: rifiutata', async () => {
    const n = await nodeEntra('giulia.ferraresi');
    const b = await nodeScarica(n, `${id(postgres, 'documents', 'Busta paga Settembre 2026', 'Castellari')}/scarica`);
    expect(b.status).toBe(302);
    expect(b.byte.slice(0, 5).toString()).not.toBe('%PDF-');
  });

  it('documento inesistente', async () => {
    const [p, n] = await Promise.all([phpEntra('giulia.ferraresi'), nodeEntra('giulia.ferraresi')]);
    const [a, b] = [await phpScarica(p, 'download=999999'), await nodeScarica(n, '999999/scarica')];
    expect(erroreNode(b.location)).toBe(erroreHtml(a.byte));
  });

  it('senza accesso: nessun file', async () => {
    const b = await scarica(NODE_URL, new Node().b, `/api/v1/dipendente/documenti/1/scarica`);
    expect([401, 403]).toContain(b.status);
    expect(b.byte.slice(0, 5).toString()).not.toBe('%PDF-');
  });
});

describe('documenti personali', () => {
  it('visibile: file originale, tracciato', async () => {
    const [p, n] = await Promise.all([phpEntra('giulia.ferraresi'), nodeEntra('giulia.ferraresi')]);
    const [a, b] = [await phpScarica(p, `download_personal=${id(mariadb, 'employee_documents', 'Attestato sicurezza', 'Ferraresi')}`),
      await nodeScarica(n, `personali/${id(postgres, 'employee_documents', 'Attestato sicurezza', 'Ferraresi')}/scarica`)];
    expect(b.status).toBe(200);
    expect(b.byte.equals(a.byte)).toBe(true);
    expect(b.disp).toBe(a.disp);
    const t = (fn: typeof mariadb) => fn(`SELECT dd.user_type, d.name FROM employee_document_downloads dd JOIN employee_documents d ON d.id = dd.employee_document_id ORDER BY dd.id`);
    expect(t(postgres)).toEqual(t(mariadb));
    const audit = (fn: typeof mariadb) => fn(`SELECT user_type, action, entity_type, severity FROM audit_log WHERE action = 'employee_document_downloaded' ORDER BY id`);
    expect(audit(postgres)).toEqual(audit(mariadb));
  });

  it('non visibile al dipendente: rifiutato', async () => {
    const [p, n] = await Promise.all([phpEntra('giulia.ferraresi'), nodeEntra('giulia.ferraresi')]);
    const [a, b] = [await phpScarica(p, `download_personal=${id(mariadb, 'employee_documents', 'Nota riservata HR', 'Ferraresi')}`),
      await nodeScarica(n, `personali/${id(postgres, 'employee_documents', 'Nota riservata HR', 'Ferraresi')}/scarica`)];
    expect(erroreHtml(a.byte)).toBe('Accesso non autorizzato');
    expect(erroreNode(b.location)).toBe('Accesso non autorizzato');
  });

  // Correzione voluta: per il nuovo backend il documento di un'altra azienda
  // non esiste nemmeno (isolamento nel database), il PHP ne confermava l'esistenza.
  it('di un altro dipendente (altra azienda): rifiutato', async () => {
    const [p, n] = await Promise.all([phpEntra('giulia.ferraresi'), nodeEntra('giulia.ferraresi')]);
    const [a, b] = [await phpScarica(p, `download_personal=${id(mariadb, 'employee_documents', 'Attestato sicurezza', 'Castellari')}`),
      await nodeScarica(n, `personali/${id(postgres, 'employee_documents', 'Attestato sicurezza', 'Castellari')}/scarica`)];
    expect(erroreHtml(a.byte)).toBe('Accesso non autorizzato');
    expect(b.status).toBe(302);
    expect(erroreNode(b.location)).toBe('Documento non trovato');
  });
});

describe('limite di 50 download l\'ora', () => {
  it('il 51esimo e\' rifiutato da tutte e due le parti', async () => {
    ripristina();
    const [p, n] = await Promise.all([phpEntra('giulia.ferraresi'), nodeEntra('giulia.ferraresi')]);
    const dm = id(mariadb, 'employee_documents', 'Regolamento aziendale', 'Ferraresi');
    const dp = id(postgres, 'employee_documents', 'Regolamento aziendale', 'Ferraresi');
    for (let i = 0; i < 50; i++) {
      expect((await phpScarica(p, `download_personal=${dm}`)).status).toBe(200);
      expect((await nodeScarica(n, `personali/${dp}/scarica`)).status).toBe(200);
    }
    const [a, b] = [await phpScarica(p, `download_personal=${dm}`), await nodeScarica(n, `personali/${dp}/scarica`)];
    expect(erroreHtml(a.byte)).toBe('Limite download raggiunto. Riprova tra 60 minuti.');
    expect(erroreNode(b.location)).toBe(erroreHtml(a.byte));
  }, 120_000);
});
