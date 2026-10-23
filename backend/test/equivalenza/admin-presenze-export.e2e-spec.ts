import { DOMParser } from '@xmldom/xmldom';
import JSZip = require('jszip');
import { mariadb, postgres, ripristina } from './ambiente';
import { Node, Php } from './clienti';

/**
 * Export presenze (admin/presenze-export.php): il file Excel generato deve
 * avere lo stesso contenuto (fogli, celle, stili, righe, colonne).
 */
const PW = 'Prova!Cora2026';

/** Contenuto confrontabile di un xlsx. */
async function contenuto(buf: Buffer) {
  const zip = await JSZip.loadAsync(buf);
  const wb = await zip.file('xl/workbook.xml')!.async('string');
  const ct = await zip.file('[Content_Types].xml')!.async('string');
  const fogli = Object.keys(zip.files).filter((n) => /^xl\/worksheets\/sheet\d+\.xml$/.test(n)).sort();
  const fogliWb = [...wb.matchAll(/<sheet\s+name="([^"]+)"[^>]*r:id="(rId\d+)"/g)].map((m) => `${m[1]}|${m[2]}`);
  const view = /<workbookView\b[^/]*\/>/.exec(wb)?.[0];
  const sheets: any[] = [];
  for (const f of fogli) {
    const doc: any = new DOMParser().parseFromString(await zip.file(f)!.async('string'), 'text/xml');
    const righe = Array.from(doc.getElementsByTagName('row')).map((r: any) => ({
      r: r.getAttribute('r'), ht: r.getAttribute('ht'), custom: r.getAttribute('customHeight'), hidden: r.getAttribute('hidden'),
      celle: Array.from(r.getElementsByTagName('c')).map((c: any) => [c.getAttribute('r'), c.getAttribute('t'), c.getAttribute('s'), c.textContent].join('|')),
    }));
    const cols = Array.from(doc.getElementsByTagName('col')).map((c: any) => [c.getAttribute('min'), c.getAttribute('max'), c.getAttribute('width'), c.getAttribute('customWidth')].join('|'));
    const pane = Array.from(doc.getElementsByTagName('pane')).map((p: any) => [p.getAttribute('xSplit'), p.getAttribute('topLeftCell'), p.getAttribute('state')].join('|'));
    sheets.push({ f, righe, cols, pane, merge: Array.from(doc.getElementsByTagName('mergeCell')).map((m: any) => m.getAttribute('ref')) });
  }
  return { fogli, fogliWb, view, overrides: [...ct.matchAll(/PartName="([^"]+)"/g)].map((m) => m[1]).sort(), sheets };
}

beforeAll(() => ripristina());

describe('file Excel', () => {
  let p: Php;
  let n: Node;
  beforeAll(async () => {
    p = new Php(); await p.login('hr.alfa', PW);
    n = new Node(); await n.login('hr.alfa', PW);
  });

  it.each([['10', '2026'], ['9', '2026'], ['5', '2026'], ['12', '2026'], ['2', '2028']])('stesso contenuto (%s/%s)', async (m, a) => {
    const q = `?month=${m}&year=${a}&action=download`;
    const x = await p.b.scarica(`/admin/presenze-export.php${q}`);
    const y = await n.b.scarica(`/admin/presenze-export.php${q}`);
    expect(x.status).toBe(200);
    expect(y.status).toBe(200);
    expect(y.tipo).toBe(x.tipo);
    expect(y.nome).toBe(x.nome);
    expect(await contenuto(y.dati)).toEqual(await contenuto(x.dati));
  });

  it('con i buoni pasto attivi e regole del dipendente', async () => {
    for (const fn of [mariadb, postgres]) {
      fn(`UPDATE companies SET buoni_pasto_enabled = ${fn === postgres ? 'true' : '1'}, buoni_pasto_min_hours = 7, smart_working_days = 'wed' WHERE id = 1`);
      fn(`UPDATE employees SET buoni_pasto_excluded = ${fn === postgres ? 'true' : '1'} WHERE last_name = 'Gualtieri'`);
      fn(`UPDATE employees SET smart_working_days = '' , buoni_pasto_min_hours_override = 4 WHERE last_name = 'Bellandi'`);
    }
    const q = '?month=10&year=2026&action=download';
    const x = await p.b.scarica(`/admin/presenze-export.php${q}`);
    const y = await n.b.scarica(`/admin/presenze-export.php${q}`);
    expect(await contenuto(y.dati)).toEqual(await contenuto(x.dati));
  });

  it('il confronto non è vuoto: codici e riepilogo presenti', async () => {
    const c = await contenuto((await n.b.scarica('/admin/presenze-export.php?month=10&year=2026&action=download')).dati);
    const testi = c.sheets[0].righe.flatMap((r: any) => r.celle.map((x: string) => x.split('|')[3]));
    expect(testi).toEqual(expect.arrayContaining(['M', 'SW', 'P104', 'Ferie (gg)', 'Malattia (gg)', '3 gg']));
    expect(c.fogli).toHaveLength(1);
  });

  it('più dipendenti delle righe del modello', async () => {
    for (let i = 1; i <= 22; i++) {
      for (const fn of [mariadb, postgres]) {
        fn(`INSERT INTO employees (company_id, username, password_hash, fiscal_code, first_name, last_name, email, is_active, must_change_password)
            VALUES (1, 'extra${i}', 'x', 'XTRXTR80A01A944${String.fromCharCode(64 + i)}', 'Extra', 'Zeta${String(i).padStart(2, '0')}', 'extra${i}@esempio.test', ${fn === postgres ? 'true' : '1'}, ${fn === postgres ? 'false' : '0'})`);
      }
    }
    const q = '?month=11&year=2026&action=download';
    const x = await contenuto((await p.b.scarica(`/admin/presenze-export.php${q}`)).dati);
    const y = await contenuto((await n.b.scarica(`/admin/presenze-export.php${q}`)).dati);
    expect(y).toEqual(x);
    expect(y.sheets[0].righe.length).toBeGreaterThan(25);
  });

  it('pagina: richieste in attesa del mese', async () => {
    const html = (await p.b.chiama('/admin/presenze-export.php?month=10&year=2026')).testo;
    const V = (await n.get('/pagine/admin/presenze-export?month=10&year=2026')).json.V;
    expect(V.pendingCount).toBe(Number(/<strong>(\d+)<\/strong> richieste in attesa/.exec(html)?.[1] ?? 0));
  });
});
