import PDFDocument = require('pdfkit');
import { leggiTesto } from '../../src/modules/documenti/busta-paga';
import { mariadb, postgres, ripristina } from './ambiente';
import { Node, Php } from './clienti';
import { php } from './php-cli';

/** Pagine di commercialista e consulente del lavoro (public/accountant, public/consulente-lavoro). */
const PW = 'Prova!Cora2026';
async function phpEntra(u: string) { const p = new Php(); await p.login(u, PW); return p; }
async function nodeEntra(u: string) { const n = new Node(); await n.login(u, PW); return n; }
const testo = (s: string) => s.replace(/<[^>]+>/g, '').replace(/&#0?39;|&apos;/g, "'").replace(/&quot;/g, '"').replace(/&amp;/g, '&').replace(/\s+/g, ' ').trim();
type Allegato = { campo: string; nome: string; dati: Buffer; tipo: string };

async function phpPost(p: Php, pagina: string, campi: Array<[string, string]>, file: Allegato[] = []) {
  const html = (await p.b.chiama(pagina)).testo;
  const fd = new FormData();
  fd.append('csrf_token', /name="csrf_token" value="([^"]+)"/.exec(html)?.[1] ?? /csrf-token" content="([^"]+)"/.exec(html)?.[1] ?? '');
  for (const [k, v] of campi) fd.append(k, v);
  for (const f of file) fd.append(f.campo, new Blob([new Uint8Array(f.dati)], { type: f.tipo }), f.nome);
  const r = await p.b.chiama(pagina, { metodo: 'POST', corpo: fd as any });
  let j: any = null;
  try { j = JSON.parse(r.testo); } catch { j = null; }
  return {
    redirect: r.location ? new URL(r.location, `http://x${pagina}`).pathname + new URL(r.location, `http://x${pagina}`).search : null,
    error: testo(/<div class="alert alert-(?:error|danger)"[^>]*>([\s\S]*?)<\/div>/.exec(r.testo)?.[1] ?? ''), json: j,
  };
}
async function nodePost(n: Node, pagina: string, campi: Array<[string, string]>, file: Allegato[] = []) {
  const fd = new FormData();
  for (const [k, v] of campi) fd.append(k, v);
  for (const f of file) fd.append(f.campo, new Blob([new Uint8Array(f.dati)], { type: f.tipo }), f.nome);
  const r = await n.b.chiama(pagina, { metodo: 'POST', corpo: fd as any, header: { 'X-CSRF-Token': n.b.valoreCookie('cora_csrf')! } });
  let j: any = null;
  try { j = JSON.parse(r.testo); } catch { j = r.testo; }
  return { redirect: r.location ? new URL(r.location, 'http://x').pathname + new URL(r.location, 'http://x').search : null, error: testo(j?.V?.error ?? ''), json: j, V: j?.V };
}

/** Busta paga sintetica: testo come lo stampa un software paghe (pdftotext -layout). */
function bustaPdf(cf: string, mese: string): Promise<Buffer> {
  return new Promise((ok) => {
    const doc = new PDFDocument({ size: 'A4', margin: 30 });
    const parti: Buffer[] = [];
    doc.on('data', (b: Buffer) => parti.push(b));
    doc.on('end', () => ok(Buffer.concat(parti)));
    doc.font('Courier').fontSize(9);
    doc.text('ALFA SERVIZI SRL                       P.IVA 01234567890');
    doc.text(`COGNOME E NOME  ROSSI MARIO   CODICE FISCALE ${cf}`);
    doc.text(`MENSILITA' ${mese}`);
    doc.text('                ANNO PREC.  MATURATO  GODUTO  RESIDUO  PROIEZIONE');
    doc.text('FERIE');
    doc.text('   g                7,36      1,83     5,53    20,17');
    doc.text('PERMESSI');
    doc.text('   h               10,64      9,33     1,31    22,67');
    doc.end();
  });
}

beforeAll(() => ripristina());

describe('lettura delle buste paga', () => {
  const testi = [
    `CODICE FISCALE FRRGLI90A41A944X  MENSILITA' SETTEMBRE 2026\n                ANNO PREC.  MATURATO  GODUTO  RESIDUO  PROIEZIONE\nFERIE\n   g                7,36      1,83     5,53    20,17\nPERMESSI\n   h               10,64      9,33     1,31    22,67\nR.O.L.\n   h                2,00               -1,50   4,00\n`,
    `Periodo di competenza 09/2026  CF BLLMRC88B12A944Z\n  FERIE A.P.  FERIE MAT.  FERIE GOD.  FERIE RES.  PERM. A.P.  PERM. MAT.  PERM. GOD.  PERM. RES.  ROL. A.P.  ROL. MAT.  ROL GOD.  ROL RES.\n     2,00        1,67                    3,67         4,00        2,00        1,00        5,00                  3,00                   3,00\n  FEST. A.P.  FEST. MAT.  FEST. GOD.  FEST. RES.  FLESS. A.P.  FLESS. MAT.  FLESS.GOD.  FLESS. RES.  B. ORE A.P.  B. ORE MAT.  B. ORE GOD.  B. ORE RES.\n                 8,00                    8,00                                                       12,00\n`,
    `Retribuzione di ottobre 2026 – àccénti in riga\n  ANNO PREC. MATURATO GODUTO RESIDUO PROIEZIONE\n g  -11,49      2,00          -9,49      14,00\n`,
  ];
  it.each(testi.map((t, i) => [i, t]))('stesso risultato del PHP (testo %s)', (_i, t) => {
    const a = php(`echo json_encode(['cf' => PayslipParser::findFiscalCode(${JSON.stringify(t)}), 'period' => PayslipParser::findPeriod(${JSON.stringify(t)}), 'balances' => PayslipParser::findBalances(${JSON.stringify(t)})]);`);
    expect(JSON.parse(JSON.stringify(leggiTesto(t as string)))).toEqual(a);
  });
});

describe('buste paga e documenti', () => {
  it('caricamento massivo: analisi e conferma con saldi', async () => {
    const [p, n] = await Promise.all([phpEntra('studio.commercialista'), nodeEntra('studio.commercialista')]);
    const cf = mariadb(`SELECT fiscal_code FROM employees WHERE last_name = 'Ferraresi'`)[0].fiscal_code;
    const pdf = await bustaPdf(cf, 'SETTEMBRE 2026');
    const file: Allegato[] = [{ campo: 'files[]', nome: 'busta_settembre.pdf', dati: pdf, tipo: 'application/pdf' }, { campo: 'files[]', nome: 'note.txt', dati: Buffer.from('x'), tipo: 'text/plain' }];
    const a = (await phpPost(p, '/accountant/documents.php', [['action', 'bulk_analyze']], file)).json;
    const b = (await nodePost(n, '/accountant/documents.php', [['action', 'bulk_analyze']], file)).json;
    const senzaId = (x: any) => x.rows.map(({ staging_id, ...r }: any) => r);
    expect(senzaId(b)).toEqual(senzaId(a));
    const conferma = (j: any): Array<[string, string]> => [['action', 'bulk_commit'], ['rows', JSON.stringify([{ ...j.rows[0], apply_balance: 1 }])]];
    expect((await nodePost(n, '/accountant/documents.php', conferma(b))).json).toEqual((await phpPost(p, '/accountant/documents.php', conferma(a))).json);
    const doc = (fn: typeof mariadb) => fn(`SELECT d.type, d.title, d.month, d.year FROM documents d JOIN employees e ON e.id = d.employee_id WHERE e.last_name = 'Ferraresi' AND d.title = 'Busta paga 09/2026'`);
    expect(doc(postgres)).toEqual(doc(mariadb));
    const saldo = (fn: typeof mariadb) => fn(`SELECT b.year, b.leave_type, b.manual_used, b.notes FROM employee_leave_balances b JOIN employees e ON e.id = b.employee_id
      WHERE e.last_name = 'Ferraresi' AND b.notes LIKE 'Allineato%' ORDER BY b.leave_type`).map((r) => ({ ...r, manual_used: String(Number(r.manual_used)) }));
    expect(saldo(postgres)).toEqual(saldo(mariadb));
  });

  it('caricamento singolo e stesso errore senza file', async () => {
    const [p, n] = await Promise.all([phpEntra('studio.commercialista'), nodeEntra('studio.commercialista')]);
    const pdf = await bustaPdf('XXXXXX00X00X000X', 'OTTOBRE 2026');
    const c = (fn: typeof mariadb): Array<[string, string]> => [['action', 'upload'], ['employee_id', fn(`SELECT id FROM employees WHERE last_name = 'Bellandi'`)[0].id], ['type', 'cud'], ['title', ''], ['description', ''], ['period', '2026-02']];
    expect((await nodePost(n, '/accountant/documents.php', c(postgres))).error).toBe((await phpPost(p, '/accountant/documents.php', c(mariadb))).error);
    const f: Allegato[] = [{ campo: 'document', nome: 'cu.pdf', dati: pdf, tipo: 'application/pdf' }];
    expect((await nodePost(n, '/accountant/documents.php', c(postgres), f)).redirect).toBe((await phpPost(p, '/accountant/documents.php', c(mariadb), f)).redirect);
    const doc = (fn: typeof mariadb) => fn(`SELECT d.type, d.title, d.month, d.year FROM documents d JOIN employees e ON e.id = d.employee_id WHERE e.last_name = 'Bellandi' AND d.type = 'cud'`);
    expect(doc(postgres)).toEqual(doc(mariadb));
  });

  it("correzione: documento di un'altra azienda non eliminabile", async () => {
    const n = await nodeEntra('studio.commercialista');
    const altro = postgres(`SELECT d.id FROM documents d WHERE d.company_id = 2 LIMIT 1`)[0]?.id;
    if (!altro) return;
    const b = await nodePost(n, '/accountant/documents.php', [['action', 'delete'], ['document_id', altro]]);
    expect(b.error).toBe('Documento non trovato');
    expect(postgres(`SELECT COUNT(*) AS n FROM documents WHERE id = ${altro}`)[0].n).toBe('1');
  });
});

describe('consulente: richieste di assunzione', () => {
  beforeAll(() => {
    for (const fn of [mariadb, postgres]) {
      fn(`INSERT INTO hire_requests (company_id, status, created_by_user_id, assigned_consulente_user_id, employer_name, employee_first_name, employee_last_name,
            contract_indeterminato, contract_determinato, contract_apprendistato, contract_tirocinio, contract_agevolata, start_date, weekly_hours, role_description, send_count, updated_after_send)
          VALUES (1, 'awaiting_prospects', 2, 6, 'Alfa Servizi S.r.l.', 'Prova', 'Prospetti', ${fn === mariadb ? '1,0,0,0,0' : 'true,false,false,false,false'}, '2026-11-02', 38, 'Magazziniere', 1, ${fn === mariadb ? '0' : 'false'})`);
    }
  });
  const id = (fn: typeof mariadb) => fn(`SELECT id FROM hire_requests WHERE employee_last_name = 'Prospetti'`)[0].id;
  const PDF = Buffer.from('%PDF-1.4\n1 0 obj<<>>endobj\ntrailer<<>>\n%%EOF\n');

  it('caricamento dei prospetti', async () => {
    const [p, n] = await Promise.all([phpEntra('studio.consulente'), nodeEntra('studio.consulente')]);
    const f: Allegato[] = [{ campo: 'prospects[]', nome: 'prospetto.pdf', dati: PDF, tipo: 'application/pdf' }];
    const a = await phpPost(p, '/consulente-lavoro/hire-requests.php', [['action', 'upload_prospects'], ['id', id(mariadb)], ['display_names[]', 'Costo azienda']], f);
    const b = await nodePost(n, '/consulente-lavoro/hire-requests.php', [['action', 'upload_prospects'], ['id', id(postgres)], ['display_names[]', 'Costo azienda']], f);
    expect(b.redirect).toBe(a.redirect!.replace(`id=${id(mariadb)}`, `id=${id(postgres)}`));
    const stato = (fn: typeof mariadb) => fn(`SELECT hr.status, f.category, f.display_name FROM hire_requests hr JOIN hire_request_files f ON f.hire_request_id = hr.id WHERE hr.employee_last_name = 'Prospetti'`);
    expect(stato(postgres)).toEqual(stato(mariadb));
    const avvisi = (fn: typeof mariadb) => Number(fn(`SELECT COUNT(*) AS n FROM notifications WHERE type = 'hire_prospects_uploaded'`)[0].n);
    expect(avvisi(postgres)).toBeGreaterThan(0);
  });

  it('contratto in uno stato non valido: stesso errore (correzione: nella pagina, non nell\'indirizzo)', async () => {
    const [p, n] = await Promise.all([phpEntra('studio.consulente'), nodeEntra('studio.consulente')]);
    const f: Allegato[] = [{ campo: 'contract', nome: 'contratto.pdf', dati: PDF, tipo: 'application/pdf' }];
    const a = await phpPost(p, '/consulente-lavoro/hire-requests.php', [['action', 'upload_contract'], ['id', id(mariadb)]], f);
    const b = await nodePost(n, '/consulente-lavoro/hire-requests.php', [['action', 'upload_contract'], ['id', id(postgres)]], f);
    expect(b.V._GET.err).toBe(decodeURIComponent(/err=([^&]*)/.exec(a.redirect!)![1].replace(/\+/g, ' ')));
  });

  it("correzione: richiesta di un'altra azienda (o di un altro consulente) non apribile", async () => {
    const n = await nodeEntra('studio.consulente');
    expect((await n.get('/pagine/consulente-lavoro/hire-requests?id=1')).json.V.__hr).toBeNull();
  });
});

describe('profilo e Wrike', () => {
  it('aggiornamento del profilo', async () => {
    const [p, n] = await Promise.all([phpEntra('studio.consulente'), nodeEntra('studio.consulente')]);
    const c: Array<[string, string]> = [['action', 'update_info'], ['name', 'Studio Bianchi'], ['email', 'nonvalida']];
    expect((await nodePost(n, '/consulente-lavoro/profile.php', c)).error).toBe((await phpPost(p, '/consulente-lavoro/profile.php', c)).error);
  });

  it('token Wrike troppo corto: stesso errore', async () => {
    const [p, n] = await Promise.all([phpEntra('studio.consulente'), nodeEntra('studio.consulente')]);
    const c: Array<[string, string]> = [['action', 'wrike_connect'], ['wrike_token', 'corto']];
    expect((await nodePost(n, '/consulente-lavoro/profile.php', c)).error).toBe((await phpPost(p, '/consulente-lavoro/profile.php', c)).error);
  });

  it('correzione: il formatore apre il suo profilo', async () => {
    const n = await nodeEntra('formatore.alfa');
    expect((await n.get('/pagine/formatore/profile')).json.V.user.username).toBe('formatore.alfa');
  });
});
