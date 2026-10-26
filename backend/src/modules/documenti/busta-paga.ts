import { oggiRoma } from '../../common/calcoli/php';
import { testoPdf } from '../../common/file/testo-pdf';

/**
 * Lettura delle buste paga (PayslipParser.php): codice fiscale, mensilita' e
 * saldi ferie/permessi dal testo del PDF (pdftotext -layout).
 *
 * Le posizioni delle colonne sono in byte come nel PHP (strpos e
 * PREG_OFFSET_CAPTURE lavorano sui byte): con caratteri accentati nella riga
 * i numeri si assegnano alle stesse colonne.
 */

export interface RigaSaldo { anno_prec: number | null; maturato: number | null; goduto: number | null; residuo: number | null; proiezione: number | null; components?: Record<string, number> }
export interface BustaPaga { cf: string | null; period: { month: number; year: number } | null; balances: { ferie: RigaSaldo | null; permesso: RigaSaldo | null } }

const MESI: Record<string, number> = {
  gennaio: 1, genn: 1, febbraio: 2, febb: 2, marzo: 3, mar: 3, aprile: 4, apr: 4, maggio: 5, mag: 5, giugno: 6, giu: 6, luglio: 7, lug: 7,
  agosto: 8, ago: 8, settembre: 9, sett: 9, set: 9, ottobre: 10, ott: 10, novembre: 11, nov: 11, dicembre: 12, dic: 12,
};
const byte = (s: string, i: number) => Buffer.byteLength(s.slice(0, i), 'utf8');
const lunghezza = (s: string) => Buffer.byteLength(s, 'utf8');
const vuota = (): RigaSaldo => ({ anno_prec: null, maturato: null, goduto: null, residuo: null, proiezione: null });
const numero = (v: string) => Number.parseFloat(v.replace(',', '.'));
const arrotonda = (n: number) => Math.round(n * 100) / 100;
/** stripos in byte. */
const posizione = (testo: string, cosa: string) => { const i = testo.toLowerCase().indexOf(cosa.toLowerCase()); return i < 0 ? -1 : byte(testo, i); };
/** Numeri della riga con la loro posizione in byte. */
const numeri = (riga: string) => [...riga.matchAll(/-?\d+(?:[.,]\d+)?/gu)].map((m) => ({ val: m[0], pos: byte(riga, m.index!) }));

export function codiceFiscale(testo: string): string | null {
  for (const m of testo.toUpperCase().matchAll(/\b([A-Z]{6}\d{2}[A-Z]\d{2}[A-Z][0-9A-Z]{3}[A-Z])\b/gu)) {
    if (/^[A-Z]{6}\d{2}[A-Z]\d{2}[A-Z][0-9A-Z]{3}[A-Z]$/.test(m[1])) return m[1];
  }
  return null;
}

export function periodo(testo: string): { month: number; year: number } | null {
  const anno = Number(oggiRoma().slice(0, 4));
  const [min, max] = [anno - 2, anno + 1];
  const basso = testo.toLowerCase();
  const trovati = [...basso.matchAll(new RegExp(`(${Object.keys(MESI).join('|')})\\s*[.\\-/]?\\s*(\\d{4})`, 'gu'))];
  if (trovati.length) {
    // Preferisce il mese vicino a mensilita'/competenza/periodo (offset in byte contro posizioni in caratteri, come nel PHP)
    const chiavi = ['mensilita', 'mensilità', 'competenza', 'periodo'].map((k) => basso.indexOf(k)).filter((p) => p >= 0);
    let migliore = 0;
    if (chiavi.length) {
      let dist = Infinity;
      trovati.forEach((m, i) => {
        const pos = byte(basso, m.index!);
        for (const k of chiavi) { const d = Math.abs(pos - k); if (d < dist) { dist = d; migliore = i; } }
      });
    }
    const m = trovati[migliore];
    const mese = MESI[m[1]] ?? 0;
    const a = Number(m[2]);
    if (mese >= 1 && mese <= 12 && a >= min && a <= max) return { month: mese, year: a };
  }
  for (const m of testo.matchAll(/\b(0[1-9]|1[0-2])[/\-.](\d{4})\b/gu)) {
    const a = Number(m[2]);
    if (a >= min && a <= max) return { month: Number(m[1]), year: a };
  }
  return null;
}

function somma(righe: RigaSaldo[]): RigaSaldo {
  const s = vuota();
  for (const r of righe) {
    for (const k of ['anno_prec', 'maturato', 'goduto', 'residuo', 'proiezione'] as const) {
      if (r[k] !== null && r[k] !== undefined) s[k] = arrotonda((s[k] ?? 0) + Number(r[k]));
    }
  }
  return s;
}

/** Formato tabellare: ANNO PREC. MATURATO GODUTO RESIDUO PROIEZIONE + righe g (ferie) / h (permessi). */
function tabellare(testo: string): { ferie: RigaSaldo | null; permesso: RigaSaldo | null } {
  const out: { ferie: RigaSaldo | null; permesso: RigaSaldo | null } = { ferie: null, permesso: null };
  const righe = testo.split('\n');
  const h = righe.findIndex((r) => /ANNO\s*PREC\.?\s+MATURATO\s+GODUTO\s+RESIDUO\s+PROIEZIONE/i.test(r));
  if (h < 0) return out;
  const colonne: Record<string, number> = {};
  for (const [k, etichetta] of Object.entries({ anno_prec: 'ANNO PREC.', maturato: 'MATURATO', goduto: 'GODUTO', residuo: 'RESIDUO', proiezione: 'PROIEZIONE' })) {
    const p = posizione(righe[h], etichetta);
    if (p >= 0) colonne[k] = p + lunghezza(etichetta) - 1;
  }
  if (Object.keys(colonne).length < 3) return out;
  const ore: RigaSaldo[] = [];
  for (let i = h + 1; i < Math.min(h + 30, righe.length); i++) {
    const m = /^(\s*)([gh])\s+(-?\d)/.exec(righe[i]);
    if (!m) continue;
    const r = vuota();
    for (const n of numeri(righe[i])) {
      const destra = n.pos + lunghezza(n.val) - 1;
      let chiave: string | null = null;
      let dist = Infinity;
      for (const [k, c] of Object.entries(colonne)) { const d = Math.abs(c - destra); if (d < dist) { dist = d; chiave = k; } }
      if (chiave && dist <= 14) (r as any)[chiave] = numero(n.val);
    }
    if (m[2] === 'g') out.ferie = r;
    else ore.push(r);
  }
  if (ore.length) out.permesso = somma(ore);
  return out;
}

/** Formato a griglia (Mod. Cedolino TS): FERIE/PERM./ROL e FEST./FLESS./B.ORE con A.P. MAT. GOD. RES. */
function griglia(testo: string): { ferie: RigaSaldo | null; permesso: RigaSaldo | null } | null {
  const righe = testo.split('\n');
  const minuscolo = (s: string) => s.toLowerCase();
  const r1 = righe.findIndex((r) => minuscolo(r).includes('ferie a.p.') && minuscolo(r).includes('ferie res.'));
  const r2 = righe.findIndex((r) => minuscolo(r).includes('fest. a.p.'));
  if (r1 < 0) return null;
  const res: Record<string, number> = {};
  const pieni: Record<string, RigaSaldo> = {};
  const prendi = (indice: number, etichette: string[], mappa: Record<string, string>) => {
    if (indice < 0) return;
    let valori: string | null = null;
    for (let i = indice + 1; i < Math.min(indice + 5, righe.length); i++) if (/\d/.test(righe[i] ?? '')) { valori = righe[i]; break; }
    if (valori === null) return;
    const colonne = etichette.map((e) => ({ e, pos: posizione(righe[indice], e) })).filter((c) => c.pos >= 0)
      .map((c) => ({ ...c, centro: c.pos + (lunghezza(c.e) - 1) / 2 })).sort((a, b) => a.pos - b.pos);
    if (colonne.length < 2) return;
    // Assegnazione monotona sinistra->destra: le celle a 0 omesse non disallineano i numeri successivi
    const assegnati: Record<string, number> = {};
    let ultimo = -1;
    for (const n of numeri(valori)) {
      const centro = n.pos + (lunghezza(n.val) - 1) / 2;
      let migliore = -1;
      let dist = Infinity;
      for (let c = ultimo + 1; c < colonne.length; c++) { const d = Math.abs(colonne[c].centro - centro); if (d < dist) { dist = d; migliore = c; } }
      if (migliore < 0) break;
      assegnati[colonne[migliore].e] = numero(n.val);
      ultimo = migliore;
    }
    for (const [etRes, istituto] of Object.entries(mappa)) {
      if (!(etRes in assegnati)) continue;
      res[istituto] = assegnati[etRes];
      const i = etichette.indexOf(etRes);
      pieni[istituto] = { anno_prec: assegnati[etichette[i - 3]] ?? null, maturato: assegnati[etichette[i - 2]] ?? null, goduto: assegnati[etichette[i - 1]] ?? null, residuo: assegnati[etRes], proiezione: null };
    }
  };
  prendi(r1, ['FERIE A.P.', 'FERIE MAT.', 'FERIE GOD.', 'FERIE RES.', 'PERM. A.P.', 'PERM. MAT.', 'PERM. GOD.', 'PERM. RES.', 'ROL. A.P.', 'ROL. MAT.', 'ROL GOD.', 'ROL RES.'],
    { 'FERIE RES.': 'ferie', 'PERM. RES.': 'perm', 'ROL RES.': 'rol' });
  prendi(r2, ['FEST. A.P.', 'FEST. MAT.', 'FEST. GOD.', 'FEST. RES.', 'FLESS. A.P.', 'FLESS. MAT.', 'FLESS.GOD.', 'FLESS. RES.', 'B. ORE A.P.', 'B. ORE MAT.', 'B. ORE GOD.', 'B. ORE RES.'],
    { 'FEST. RES.': 'exfest' });
  if (!Object.keys(res).length) return null;
  const ferie = pieni.ferie ? { ...pieni.ferie } : null;
  const componenti: Record<string, number> = {};
  const voci: RigaSaldo[] = [];
  for (const k of ['perm', 'rol', 'exfest']) if (k in res) { componenti[k] = res[k]; voci.push(pieni[k]); }
  const permesso = voci.length ? { ...somma(voci), components: componenti } : null;
  return { ferie, permesso };
}

export function saldi(testo: string): { ferie: RigaSaldo | null; permesso: RigaSaldo | null } {
  const t = tabellare(testo);
  if (t.ferie !== null || t.permesso !== null) return t;
  return griglia(testo) ?? { ferie: null, permesso: null };
}

export function leggiTesto(testo: string): BustaPaga {
  return { cf: codiceFiscale(testo), period: periodo(testo), balances: saldi(testo) };
}

export async function leggiBustaPaga(percorso: string): Promise<BustaPaga> {
  return leggiTesto(await testoPdf(percorso));
}
