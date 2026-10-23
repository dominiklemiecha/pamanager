import { Injectable } from '@nestjs/common';
import { DOMParser, XMLSerializer } from '@xmldom/xmldom';
import * as fs from 'fs';
import JSZip = require('jszip');
import { DataSource } from 'typeorm';
import { festivitaAnno, isFestivo } from '../../common/calcoli/festivita';
import { buoniPastoMese, CHIAVI_GIORNI, oreEffettive } from '../../common/calcoli/orario';
import { giorniNelMese, giornoSettimana } from '../../common/calcoli/php';
import { cartellaRisorse } from '../../config/app';
import { SaldiService } from '../saldi/saldi.service';

const NS = 'http://schemas.openxmlformats.org/spreadsheetml/2006/main';
const MESI = ['', 'Gennaio', 'Febbraio', 'Marzo', 'Aprile', 'Maggio', 'Giugno', 'Luglio', 'Agosto', 'Settembre', 'Ottobre', 'Novembre', 'Dicembre'];
const MESI_RE = /(GENNAIO|FEBBRAIO|MARZO|APRILE|MAGGIO|GIUGNO|LUGLIO|AGOSTO|SETTEMBRE|OTTOBRE|NOVEMBRE|DICEMBRE)\s*\d{4}/i;

type El = any;
type Orario = { days: string[]; hours: number; lunch_start: string | null; lunch_end: string | null };
type Riepilogo = { ferie_d: number; permessi_h: number; malattia_d: number; p104_h: number };

const escapeXml = (s: string) => s.replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;').replace(/'/g, '&apos;');
const escRe = (s: string) => s.replace(/[.*+?^${}()|[\]\\#]/g, '\\$&');
const colonna = (ref: string) => ref.replace(/\d+/g, '');
const colToNum = (c: string) => [...c.toUpperCase()].reduce((n, ch) => n * 26 + (ch.charCodeAt(0) - 64), 0);
const numToCol = (n: number) => { let s = ''; while (n > 0) { const r = (n - 1) % 26; s = String.fromCharCode(65 + r) + s; n = Math.floor((n - 1) / 26); } return s; };
const colCompare = (a: string, b: string) => (a.length === b.length ? (a < b ? -1 : a > b ? 1 : 0) : a.length - b.length);
const serialeExcel = (ymd: string) => Math.floor(Date.parse(`${ymd}T00:00:00Z`) / 86400000) + 25569;
const dataDaSeriale = (n: number) => new Date((n - 25569) * 86400000).toISOString().slice(0, 10);
const giorniDi = (el: El, nome: string): El[] => Array.from(el.getElementsByTagName(nome));
const elementi = (el: El): El[] => Array.from(el.childNodes ?? []).filter((x: any) => x.nodeType === 1);
const numero = (v: unknown) => /^\s*[+-]?(\d+(\.\d*)?|\.\d+)([eE][+-]?\d+)?\s*$/.test(String(v));
/** Ore: intero se .0, altrimenti un decimale (PresenzeExport::fmtHours). */
const fmtOre = (h: number) => (Math.abs(h - Math.round(h)) < 0.01 ? String(Math.round(h)) : h.toFixed(1).replace(/0+$/, '').replace(/\.$/, ''));
/** "17:30" o "18" (PresenzeExport::fmtTime). */
const fmtOra = (t: string) => { const h = Number.parseInt(t.slice(0, 2), 10); const m = Number.parseInt(t.slice(3, 5), 10); return m === 0 ? String(h) : `${h}:${String(m).padStart(2, '0')}`; };

/**
 * Export presenze per il consulente paghe (PresenzeExport.php): il modello
 * Excel con il solo foglio del mese, codici delle assenze per giorno,
 * festivita', riepilogo e buoni pasto. Stessi calcoli del PHP.
 */
@Injectable()
export class PresenzeExportService {
  constructor(private readonly ds: DataSource, private readonly saldi: SaldiService) {}

  nomeFile(mese: number, anno: number) { return `Presenze_${MESI[mese]}_${anno}.xlsx`; }

  async genera(cid: number, mese: number, anno: number): Promise<Buffer> {
    const s = new Lavoro(this.ds, this.saldi, cid, mese, anno);
    await s.carica();
    return s.xlsx();
  }
}

class Lavoro {
  private dipendenti: any[] = [];
  private celle = new Map<number, Map<string, string>>();
  private riepilogo = new Map<number, Riepilogo>();
  private orari = new Map<number, Orario>();
  private assenze = new Map<number, Record<string, { full: boolean; hours: number; sw: boolean }>>();
  private buoni = { enabled: false, min_hours_enabled: true, min_hours: 6.0, sw_eligible: false, smart_working_days: [] as string[] };

  constructor(private readonly ds: DataSource, private readonly saldi: SaldiService, private readonly cid: number,
    private readonly mese: number, private readonly anno: number) {}

  private get ym() { return `${this.anno}-${String(this.mese).padStart(2, '0')}`; }

  private async orario(id: number): Promise<Orario> {
    if (!this.orari.has(id)) this.orari.set(id, await this.saldi.orario(id));
    return this.orari.get(id)!;
  }

  private cella(id: number) { if (!this.celle.has(id)) this.celle.set(id, new Map()); return this.celle.get(id)!; }

  async carica() {
    this.dipendenti = await this.ds.query(
      `SELECT id, first_name, last_name, smart_working_days, buoni_pasto_min_hours_override, buoni_pasto_sw_eligible_override, buoni_pasto_excluded
         FROM employees WHERE is_active = true AND company_id = $1 ORDER BY last_name, first_name`, [this.cid]);
    const [c] = await this.ds.query(
      `SELECT buoni_pasto_enabled, buoni_pasto_min_hours_enabled, buoni_pasto_min_hours, buoni_pasto_sw_eligible, smart_working_days FROM companies WHERE id = $1`, [this.cid]);
    this.buoni = {
      enabled: !!c?.buoni_pasto_enabled, min_hours_enabled: !!c?.buoni_pasto_min_hours_enabled,
      min_hours: c?.buoni_pasto_min_hours != null ? Number(c.buoni_pasto_min_hours) : 6.0, sw_eligible: !!c?.buoni_pasto_sw_eligible,
      smart_working_days: c?.smart_working_days ? String(c.smart_working_days).split(',').map((x) => x.trim()).filter(Boolean) : [],
    };
    await this.assenzeDelMese();
    this.festivita();
  }

  private codice(r: any): string {
    const intera = !!r.is_full_day || !r.start_time || !r.end_time;
    switch (r.leave_type) {
      case 'ferie': return 'F';
      case 'malattia': return 'M';
      case 'permesso_104': return intera ? 'P104' : `P104 ${fmtOra(r.start_time)}-${fmtOra(r.end_time)}`;
      case 'congedo_parentale': return 'CP';
      case 'altro': return 'A';
      case 'chiusura': return 'C';
      case 'smart_working': return 'SW';
      case 'permesso': return intera ? 'ROL' : `ROL ${fmtOra(r.start_time)}-${fmtOra(r.end_time)}`;
      default: return '';
    }
  }

  private async assenzeDelMese() {
    const inizio = `${this.ym}-01`;
    const fine = `${this.ym}-${String(giorniNelMese(this.anno, this.mese)).padStart(2, '0')}`;
    const righe = await this.ds.query(
      `SELECT employee_id, leave_type, start_date, end_date, is_full_day, start_time, end_time FROM leave_requests
        WHERE company_id = $1 AND status = 'approved' AND start_date <= $2 AND end_date >= $3 ORDER BY id`, [this.cid, fine, inizio]);
    for (const r of righe) {
      const codice = this.codice(r);
      if (codice === '') continue;
      const d = new Date(`${r.start_date}T12:00:00Z`);
      const ultimo = new Date(`${r.end_date}T12:00:00Z`);
      for (; d <= ultimo; d.setUTCDate(d.getUTCDate() + 1)) {
        const key = d.toISOString().slice(0, 10);
        if (key.slice(0, 7) !== this.ym) continue;
        const id = Number(r.employee_id);
        const celle = this.cella(id);
        const c = celle.get(key) ?? '';
        if (c === '') celle.set(key, codice); else if (!c.includes(codice)) celle.set(key, `${c}/${codice}`);

        // Assenze del giorno per i buoni pasto: giornata intera, permesso a ore o smart working
        const aOre = ['permesso', 'permesso_104'].includes(r.leave_type) && !r.is_full_day && !!r.start_time && !!r.end_time;
        if (!this.assenze.has(id)) this.assenze.set(id, {});
        const a = this.assenze.get(id)!;
        a[key] ??= { full: false, hours: 0, sw: false };
        if (r.leave_type === 'smart_working') a[key].sw = true;
        else if (aOre) { const o = await this.orario(id); a[key].hours += oreEffettive(r.start_time, r.end_time, o.lunch_start, o.lunch_end); }
        else a[key].full = true;

        // Riepilogo: ferie e malattia in giorni (lavorativi, non festivi); permessi e L.104 in ore
        if (!this.riepilogo.has(id)) this.riepilogo.set(id, { ferie_d: 0, permessi_h: 0, malattia_d: 0, p104_h: 0 });
        const s = this.riepilogo.get(id)!;
        const giorno = CHIAVI_GIORNI[giornoSettimana(key) - 1];
        if (r.leave_type === 'ferie' || r.leave_type === 'malattia') {
          const o = await this.orario(id);
          if (!o.days.includes(giorno) || isFestivo(key)) continue;
          if (r.leave_type === 'ferie') s.ferie_d++; else s.malattia_d++;
        } else if (r.leave_type === 'permesso' || r.leave_type === 'permesso_104') {
          const o = await this.orario(id);
          if (!o.days.includes(giorno)) continue;
          const campo = r.leave_type === 'permesso' ? 'permessi_h' : 'p104_h';
          const intera = !!r.is_full_day || !r.start_time || !r.end_time;
          s[campo] += intera ? o.hours : oreEffettive(r.start_time, r.end_time, o.lunch_start, o.lunch_end);
        }
      }
    }
  }

  private festivita() {
    for (const ymd of festivitaAnno(this.anno).keys()) {
      if (ymd.slice(0, 7) !== this.ym) continue;
      for (const e of this.dipendenti) {
        const celle = this.cella(Number(e.id));
        const c = celle.get(ymd) ?? '';
        if (c === '') celle.set(ymd, 'FE'); else if (!c.includes('FE')) celle.set(ymd, `${c}/FE`);
      }
    }
  }

  private async buoniPasto(e: any): Promise<number | null> {
    if (!this.buoni.enabled) return null;
    const o = await this.orario(Number(e.id));
    const sw = e.smart_working_days !== null && e.smart_working_days !== undefined
      ? String(e.smart_working_days).split(',').map((x) => x.trim()).filter(Boolean) : this.buoni.smart_working_days;
    return buoniPastoMese({
      enabled: true, excluded: !!e.buoni_pasto_excluded, workingDays: o.days, hoursPerDay: o.hours, smartWorkingDays: sw,
      minHoursEnabled: this.buoni.min_hours_enabled,
      minHours: e.buoni_pasto_min_hours_override !== null && e.buoni_pasto_min_hours_override !== undefined ? Number(e.buoni_pasto_min_hours_override) : this.buoni.min_hours,
      swEligible: e.buoni_pasto_sw_eligible_override === null || e.buoni_pasto_sw_eligible_override === undefined ? this.buoni.sw_eligible
        : typeof e.buoni_pasto_sw_eligible_override === 'boolean' ? e.buoni_pasto_sw_eligible_override : !!Number(e.buoni_pasto_sw_eligible_override),
    }, this.anno, this.mese, this.assenze.get(Number(e.id)) ?? {});
  }

  // ─── Excel ────────────────────────────────────────────────────────────

  async xlsx(): Promise<Buffer> {
    const percorso = cartellaRisorse('presenze_template.xlsx');
    if (!fs.existsSync(percorso)) throw new Error(`Template non trovato: ${percorso}`);
    const zip = await JSZip.loadAsync(fs.readFileSync(percorso));
    let workbook = await zip.file('xl/workbook.xml')!.async('string');
    let rels = await zip.file('xl/_rels/workbook.xml.rels')!.async('string');
    let ct = await zip.file('[Content_Types].xml')!.async('string');
    const ss = zip.file('xl/sharedStrings.xml') ? await zip.file('xl/sharedStrings.xml')!.async('string') : '';
    const fogli = new Map<string, string>();
    for (const nome of Object.keys(zip.files)) {
      if (nome.startsWith('xl/worksheets/sheet') && nome.endsWith('.xml')) fogli.set(nome.split('/').pop()!, await zip.file(nome)!.async('string'));
    }

    // Maggio dell'anno come foglio di partenza (layout uniforme), altrimenti il primo foglio mese
    const sorgente = this.foglioDelMese(workbook, rels, 5, this.anno) ?? this.foglioQualsiasi(workbook, rels);
    if (!sorgente || !fogli.has(sorgente)) throw new Error('Nessun foglio mese disponibile nel template.');
    const stili = this.stiliFineSettimana(fogli.get(sorgente)!);
    const titolo = `${MESI[this.mese].toUpperCase()} ${this.anno}`;
    fogli.set(sorgente, this.rititola(fogli.get(sorgente)!));
    workbook = this.rinominaFoglio(workbook, rels, sorgente, titolo);
    const tolti: string[] = [];
    [workbook, rels, ct] = this.togliAltri(workbook, rels, ct, sorgente, tolti);
    for (const f of tolti) fogli.delete(f);
    workbook = this.primaScheda(workbook);
    fogli.set(sorgente, await this.riempi(fogli.get(sorgente)!, this.stringheCondivise(ss), stili));

    zip.file('xl/workbook.xml', workbook);
    zip.file('xl/_rels/workbook.xml.rels', rels);
    zip.file('[Content_Types].xml', ct);
    for (const f of tolti) zip.remove(`xl/worksheets/${f}`);
    for (const [f, xml] of fogli) zip.file(`xl/worksheets/${f}`, xml);
    return zip.generateAsync({ type: 'nodebuffer', compression: 'DEFLATE' });
  }

  private foglioDelMese(wb: string, rels: string, mese: number, anno: number): string | null {
    const nome = `${MESI[mese].toUpperCase()} ${anno}`;
    let rId: string | null = null;
    for (const p of [nome, nome.replace(/ /g, '')]) {
      const m = new RegExp(`<sheet\\s+name="${escRe(p)}"[^>]*r:id="(rId\\d+)"`, 'i').exec(wb);
      if (m) { rId = m[1]; break; }
    }
    if (!rId) return null;
    return new RegExp(`<Relationship\\s+Id="${rId}"[^>]*Target="worksheets/([^"]+)"`).exec(rels)?.[1] ?? null;
  }

  private foglioQualsiasi(wb: string, rels: string): string | null {
    for (const s of wb.matchAll(/<sheet\s+name="([^"]+)"[^>]*r:id="(rId\d+)"\s*\/>/g)) {
      if (!MESI_RE.test(s[1])) continue;
      const r = new RegExp(`<Relationship\\s+Id="${s[2]}"[^>]*Target="worksheets/([^"]+)"`).exec(rels);
      if (r) return r[1];
    }
    return null;
  }

  /** Titoli e date della riga 3 del mese richiesto (retitleAndRedateSheet). */
  private rititola(xml: string): string {
    const titolo = `${MESI[this.mese].toUpperCase()} ${this.anno}`;
    xml = xml.replace(/<t[^>]*>([^<]+)<\/t>/gi, (m0, testo) => (MESI_RE.test(testo) ? `<t xml:space="preserve">${escapeXml(titolo)}</t>` : m0));
    const giorni = giorniNelMese(this.anno, this.mese);
    const primo = serialeExcel(`${this.ym}-01`);
    return xml.replace(/<row\s+r="3"[^>]*>([\s\S]*?)<\/row>/, (m0, contenuto: string) => {
      let i = 0;
      const nuovo = contenuto.replace(/<c\s+r="([A-Z]+)3"([^>]*)>\s*<v>(\d+)<\/v>\s*<\/c>/g, (cm, col: string, attr: string) => {
        if (attr.includes('t="s"')) return cm;
        if (i >= giorni) { i++; return ''; }
        const v = primo + i;
        i++;
        return `<c r="${col}3"${attr}><v>${v}</v></c>`;
      });
      return m0.replace(/<row\s+r="3"([^>]*)>[\s\S]*<\/row>/, (_x, a: string) => `<row r="3"${a}>${nuovo}</row>`);
    });
  }

  private rinominaFoglio(wb: string, rels: string, file: string, nome: string): string {
    const m = new RegExp(`<Relationship\\s+Id="(rId\\d+)"[^>]*Target="worksheets/${escRe(file)}"`).exec(rels);
    if (!m) return wb;
    return wb.replace(new RegExp(`<sheet\\s+name="[^"]*"([^>]*r:id="${m[1]}"[^>]*/>)`), (_x, resto: string) => `<sheet name="${escapeXml(nome)}"${resto}`);
  }

  private togliAltri(wb: string, rels: string, ct: string, tieni: string, tolti: string[]): [string, string, string] {
    for (const s of [...wb.matchAll(/<sheet\s+name="([^"]+)"[^>]*r:id="(rId\d+)"\s*\/>/g)]) {
      const [, nome, rId] = s;
      const rm = new RegExp(`<Relationship\\s+Id="${rId}"[^>]*Target="(worksheets/[^"]+)"`).exec(rels);
      if (!rm) continue;
      const file = rm[1].split('/').pop()!;
      if (file === tieni) continue;
      wb = wb.replace(new RegExp(`<sheet\\s+name="${escRe(nome)}"[^>]*r:id="${rId}"\\s*/>`), '');
      rels = rels.replace(new RegExp(`<Relationship\\s+Id="${rId}"[^>]*/>`), '');
      ct = ct.replace(new RegExp(`<Override\\s+PartName="/xl/${escRe(rm[1])}"[^>]*/>`), '');
      tolti.push(file);
    }
    return [wb, rels, ct];
  }

  private primaScheda(wb: string): string {
    const m = /<workbookView\b[^/]*\/>/.exec(wb);
    if (!m) return wb;
    const nuovo = m[0].replace(/\s(activeTab|firstSheet)="\d+"/g, '').replace(/\/>$/, ' activeTab="0" firstSheet="0"/>');
    return wb.split(m[0]).join(nuovo);
  }

  private stringheCondivise(xml: string): string[] {
    if (xml === '') return [];
    const dec = (s: string) => s.replace(/&lt;/g, '<').replace(/&gt;/g, '>').replace(/&quot;/g, '"').replace(/&apos;/g, "'")
      .replace(/&#(\d+);/g, (_x, n) => String.fromCodePoint(Number(n))).replace(/&#x([0-9a-f]+);/gi, (_x, n) => String.fromCodePoint(parseInt(n, 16))).replace(/&amp;/g, '&');
    return [...xml.matchAll(/<si\b[^>]*>([\s\S]*?)<\/si>/g)].map((si) => dec([...si[1].matchAll(/<t[^>]*>([\s\S]*?)<\/t>/g)].map((t) => t[1]).join('')));
  }

  // ─── Foglio (DOM) ─────────────────────────────────────────────────────

  private riga(doc: El, n: number): El | null {
    return giorniDi(doc, 'row').find((r) => r.parentNode?.nodeName === 'sheetData' && r.getAttribute('r') === String(n)) ?? null;
  }

  private cellaDom(doc: El, ref: string): El | null {
    const r = this.riga(doc, Number(ref.replace(/\D+/g, '')));
    return r ? elementi(r).find((c) => c.nodeName === 'c' && c.getAttribute('r') === ref) ?? null : null;
  }

  private testo(c: El, ss: string[]): string {
    const t = c.getAttribute('t');
    const v = giorniDi(c, 'v')[0];
    const is = giorniDi(c, 'is')[0];
    if (t === 's' && v) return ss[Number.parseInt(v.textContent, 10)] ?? '';
    if (t === 'inlineStr' && is) return giorniDi(is, 't')[0]?.textContent ?? '';
    return v ? v.textContent : '';
  }

  /** Date della riga 3: colonna -> data. */
  private dateRiga3(doc: El): Map<string, string> {
    const out = new Map<string, string>();
    const r3 = this.riga(doc, 3);
    for (const c of r3 ? elementi(r3).filter((x) => x.nodeName === 'c') : []) {
      const v = giorniDi(c, 'v')[0];
      if (!v) continue;
      const t = c.getAttribute('t');
      if ((t === '' || t === 'n') && numero(v.textContent) && Number.parseInt(v.textContent, 10) > 30000) {
        out.set(colonna(c.getAttribute('r')), dataDaSeriale(Number.parseInt(v.textContent, 10)));
      }
    }
    return out;
  }

  /** Stili piu' frequenti delle celle vuote di fine settimana e feriali (detectWeekendStyles). */
  private stiliFineSettimana(xml: string): { weekend: number | null; weekday: number | null } {
    const doc = new DOMParser().parseFromString(xml, 'text/xml');
    const giorni = new Map<string, number>();
    for (const [col, data] of this.dateRiga3(doc)) giorni.set(col, giornoSettimana(data));
    if (!giorni.size) return { weekend: null, weekday: null };
    const fs1 = new Map<string, number>();
    const fe = new Map<string, number>();
    for (let r = 5; r <= 22; r++) {
      const riga = this.riga(doc, r);
      for (const c of riga ? elementi(riga).filter((x) => x.nodeName === 'c') : []) {
        const col = colonna(c.getAttribute('r'));
        if (col === 'A' || !giorni.has(col)) continue;
        if (giorniDi(c, 'v').length || giorniDi(c, 'is').length) continue;
        const s = c.getAttribute('s');
        if (s === '') continue;
        const m = giorni.get(col)! >= 6 ? fs1 : fe;
        m.set(s, (m.get(s) ?? 0) + 1);
      }
    }
    const migliore = (m: Map<string, number>) => {
      if (!m.size) return null;
      // arsort stabile: a parita' vince il primo incontrato (chiavi numeriche come in PHP)
      const voci = [...m.entries()].map(([k, v], i) => ({ k, v, i }));
      voci.sort((a, b) => b.v - a.v || a.i - b.i);
      return Number.parseInt(voci[0].k, 10);
    };
    return { weekend: migliore(fs1), weekday: migliore(fe) };
  }

  private scrivi(doc: El, ref: string, valore: string, stile: number | null = null) {
    const col = colonna(ref);
    const tNode = () => {
      const is = doc.createElementNS(NS, 'is');
      const t = doc.createElementNS(NS, 't');
      t.setAttribute('xml:space', 'preserve');
      t.appendChild(doc.createTextNode(valore));
      is.appendChild(t);
      return is;
    };
    const c = this.cellaDom(doc, ref);
    if (c) {
      while (c.firstChild) c.removeChild(c.firstChild);
      c.setAttribute('t', 'inlineStr');
      if (stile !== null) c.setAttribute('s', String(stile));
      c.appendChild(tNode());
      return;
    }
    const riga = this.riga(doc, Number(ref.replace(/\D+/g, '')));
    if (!riga) return;
    const nuova = doc.createElementNS(NS, 'c');
    nuova.setAttribute('r', ref);
    nuova.setAttribute('t', 'inlineStr');
    if (stile !== null) nuova.setAttribute('s', String(stile));
    nuova.appendChild(tNode());
    const dopo = elementi(riga).find((x) => colCompare(colonna(x.getAttribute('r')), col) > 0);
    if (dopo) riga.insertBefore(nuova, dopo); else riga.appendChild(nuova);
  }

  private legenda(nome: string): boolean {
    const low = nome.trim().toLowerCase();
    if (low === '') return false;
    return ['smart working', 'ferie/rol/malattia', 'fiera/evento', 'festivit', 'chiusura', 'legenda', 'totale', 'presenze'].some((k) => low.includes(k));
  }

  private stiliCodici(doc: El, ss: string[]): Record<string, number> {
    const out: Record<string, number> = {};
    const mappa: Array<[string, string[]]> = [
      ['F', ['ferie/rol/malattia', 'f/rol/m']], ['M', ['ferie/rol/malattia', 'f/rol/m']], ['ROL', ['ferie/rol/malattia', 'f/rol/m']],
      ['P104', ['ferie/rol/malattia', 'f/rol/m']], ['CP', ['ferie/rol/malattia', 'f/rol/m']], ['A', ['ferie/rol/malattia', 'f/rol/m']],
      ['SW', ['smart working', 'sw']], ['C', ['festivit', 'chiusura']],
    ];
    for (let r = 20; r <= 40; r++) {
      for (const col of ['A', 'B']) {
        const c = this.cellaDom(doc, `${col}${r}`);
        if (!c) continue;
        const testo = this.testo(c, ss);
        if (testo === '') continue;
        const low = testo.toLowerCase();
        for (const [codice, parole] of mappa) {
          if (codice in out) continue;
          for (const k of parole) {
            if (!low.includes(k)) continue;
            const sc = col === 'B' ? c : this.cellaDom(doc, `B${r}`);
            if (sc && sc.getAttribute('s') !== '') out[codice] = Number.parseInt(sc.getAttribute('s'), 10);
            break;
          }
        }
      }
    }
    return out;
  }

  private stileCodice(codice: string, stili: Record<string, number>): number | null {
    const primo = codice.split('/')[0].trim();
    for (const k of ['ROL', 'P104', 'CP', 'SW', 'F', 'M', 'A', 'C']) {
      if (primo === k || primo.startsWith(`${k} `)) return stili[k] ?? null;
    }
    return null;
  }

  private colonneElemento(doc: El): El {
    const ws = doc.documentElement;
    let cols = giorniDi(ws, 'cols')[0];
    if (!cols) {
      cols = doc.createElementNS(NS, 'cols');
      const sd = giorniDi(ws, 'sheetData')[0];
      if (sd) sd.parentNode.insertBefore(cols, sd);
    }
    return cols;
  }

  private larghezzaA(doc: El, w: number) {
    const cols = this.colonneElemento(doc);
    const c = giorniDi(cols, 'col').find((x) => Number.parseInt(x.getAttribute('min'), 10) === 1 && Number.parseInt(x.getAttribute('max'), 10) === 1);
    if (c) { c.setAttribute('width', String(w)); c.setAttribute('customWidth', '1'); return; }
    const n = doc.createElementNS(NS, 'col');
    n.setAttribute('min', '1'); n.setAttribute('max', '1'); n.setAttribute('width', String(w)); n.setAttribute('customWidth', '1');
    if (cols.firstChild) cols.insertBefore(n, cols.firstChild); else cols.appendChild(n);
  }

  private larghezza(doc: El, min: number, max: number, w: number) {
    const cols = this.colonneElemento(doc);
    const n = doc.createElementNS(NS, 'col');
    n.setAttribute('min', String(min)); n.setAttribute('max', String(max)); n.setAttribute('width', String(w)); n.setAttribute('customWidth', '1');
    cols.appendChild(n);
  }

  private bloccaPrimaColonna(doc: El) {
    const ws = doc.documentElement;
    let view = giorniDi(ws, 'sheetView').find((v) => v.parentNode?.nodeName === 'sheetViews');
    if (!view) {
      const sv = doc.createElementNS(NS, 'sheetViews');
      view = doc.createElementNS(NS, 'sheetView');
      view.setAttribute('workbookViewId', '0');
      sv.appendChild(view);
      ws.insertBefore(sv, giorniDi(ws, 'sheetData')[0]);
    }
    const vecchio = giorniDi(view, 'pane')[0];
    if (vecchio) vecchio.parentNode.removeChild(vecchio);
    const p = doc.createElementNS(NS, 'pane');
    p.setAttribute('xSplit', '1'); p.setAttribute('topLeftCell', 'B1'); p.setAttribute('activePane', 'topRight'); p.setAttribute('state', 'frozen');
    if (view.firstChild) view.insertBefore(p, view.firstChild); else view.appendChild(p);
  }

  private espandi(doc: El, righe: number[], extra: number): number[] {
    const ultima = righe[righe.length - 1];
    const el = this.riga(doc, ultima);
    if (!el) return righe;
    for (const r of giorniDi(doc, 'row').filter((x) => x.parentNode?.nodeName === 'sheetData')) {
      const n = Number.parseInt(r.getAttribute('r'), 10);
      if (n <= ultima) continue;
      r.setAttribute('r', String(n + extra));
      for (const c of giorniDi(r, 'c')) c.setAttribute('r', colonna(c.getAttribute('r')) + (n + extra));
    }
    const prima = el.nextSibling;
    for (let i = 1; i <= extra; i++) {
      const n = ultima + i;
      const clone = el.cloneNode(true);
      clone.setAttribute('r', String(n));
      for (const c of giorniDi(clone, 'c')) {
        c.setAttribute('r', colonna(c.getAttribute('r')) + n);
        while (c.firstChild) c.removeChild(c.firstChild);
        c.removeAttribute('t');
      }
      el.parentNode.insertBefore(clone, prima);
      righe.push(n);
    }
    return righe;
  }

  private rinumera(doc: El, togli: number[]) {
    for (const n of togli) { const r = this.riga(doc, n); if (r) r.parentNode.removeChild(r); }
    let n = 1;
    for (const r of giorniDi(doc, 'row').filter((x) => x.parentNode?.nodeName === 'sheetData')) {
      r.setAttribute('r', String(n));
      for (const c of giorniDi(r, 'c')) c.setAttribute('r', colonna(c.getAttribute('r')) + n);
      n++;
    }
  }

  /** fillSheetXml: nomi, codici, stili, riepilogo, righe e colonne del foglio. */
  private async riempi(xml: string, ss: string[], stiliRilevati: { weekend: number | null; weekday: number | null }): Promise<string> {
    const doc = new DOMParser().parseFromString(xml, 'text/xml');
    const date = this.dateRiga3(doc);
    if (!date.size) throw new Error('Riga date (riga 3) non riconosciuta nel template.');
    const festivi = new Set([...date].filter(([, d]) => giornoSettimana(d) >= 6 || isFestivo(d)).map(([c]) => c));
    const stiliCodici = this.stiliCodici(doc, ss);
    this.larghezzaA(doc, 28.5);
    this.bloccaPrimaColonna(doc);
    const r3 = this.riga(doc, 3);
    if (r3) { r3.setAttribute('ht', '36.75'); r3.setAttribute('customHeight', '1'); }

    let righe: number[] = [];
    for (let r = 5; r <= 50; r++) {
      const c = this.cellaDom(doc, `A${r}`);
      if (this.legenda(c ? this.testo(c, ss) : '')) break;
      righe.push(r);
    }
    const n = this.dipendenti.length;
    if (n > righe.length && righe.length) righe = this.espandi(doc, righe, n - righe.length);
    const quanti = Math.min(n, righe.length);

    for (let i = 0; i < quanti; i++) {
      const riga = righe[i];
      const e = this.dipendenti[i];
      const celle = this.celle.get(Number(e.id));
      this.scrivi(doc, `A${riga}`, `${[...String(e.first_name)].slice(0, 1).join('').toUpperCase()}. ${e.last_name}`);
      for (const [col, d] of date) {
        const fest = festivi.has(col);
        const codice = celle?.get(d) ?? '';
        const base = fest ? stiliRilevati.weekend : stiliRilevati.weekday;
        if (codice === '') this.scrivi(doc, `${col}${riga}`, '', base);
        else this.scrivi(doc, `${col}${riga}`, codice, fest ? stiliRilevati.weekend : this.stileCodice(codice, stiliCodici));
      }
    }

    // Colonne di riepilogo dopo l'ultimo giorno
    const ultima = Math.max(1, ...[...date.keys()].map(colToNum));
    const riepilogo: Array<[string, string]> = [
      [numToCol(ultima + 2), 'Ferie (gg)'], [numToCol(ultima + 3), 'Permessi (ore)'], [numToCol(ultima + 4), 'Malattia (gg)'], [numToCol(ultima + 5), '104 (ore)'],
    ];
    if (this.buoni.enabled) riepilogo.push([numToCol(ultima + 6), 'Buoni pasto (nr)']);
    for (const [col, t] of riepilogo) this.scrivi(doc, `${col}3`, t);
    for (let i = 0; i < quanti; i++) {
      const riga = righe[i];
      const e = this.dipendenti[i];
      const s = this.riepilogo.get(Number(e.id)) ?? { ferie_d: 0, permessi_h: 0, malattia_d: 0, p104_h: 0 };
      this.scrivi(doc, `${riepilogo[0][0]}${riga}`, s.ferie_d > 0 ? `${s.ferie_d} gg` : '');
      this.scrivi(doc, `${riepilogo[1][0]}${riga}`, s.permessi_h > 0 ? `${fmtOre(s.permessi_h)} ore` : '');
      this.scrivi(doc, `${riepilogo[2][0]}${riga}`, s.malattia_d > 0 ? `${s.malattia_d} gg` : '');
      this.scrivi(doc, `${riepilogo[3][0]}${riga}`, s.p104_h > 0 ? `${fmtOre(s.p104_h)} ore` : '');
      if (riepilogo[4]) {
        const b = await this.buoniPasto(e);
        this.scrivi(doc, `${riepilogo[4][0]}${riga}`, b !== null ? String(b) : '');
      }
    }
    this.larghezza(doc, ultima + 2, ultima + riepilogo.length + 1, 11.0);

    // Via le righe decorative (1, 2, 4) e quelle dei dipendenti non usate; la riga date diventa la 1
    this.rinumera(doc, [1, 2, 4, ...righe.slice(quanti)]);
    const corpo = new XMLSerializer().serializeToString(doc).replace(/^<\?xml[^>]*\?>\s*/, '');
    return `<?xml version="1.0" encoding="UTF-8" standalone="yes"?>\n${corpo}`;
  }
}
