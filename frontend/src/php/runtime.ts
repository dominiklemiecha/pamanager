/* eslint-disable @typescript-eslint/no-explicit-any */
/**
 * Runtime dei template PHP convertiti (strumenti/php2js.py): le funzioni di
 * PHP che i template usano, con la stessa semantica (verita' dei valori,
 * concatenazione, confronti, formattazione di date e numeri) per produrre
 * lo stesso HTML. Gli array associativi creati nei template sono Map
 * (ordine di inserimento come in PHP); i dati del backend sono array e
 * oggetti JSON.
 */

type Funzione = (...a: any[]) => any;

const ROMA = 'Europe/Rome';
const MESI_EN = ['January', 'February', 'March', 'April', 'May', 'June', 'July', 'August', 'September', 'October', 'November', 'December'];
const GIORNI_EN = ['Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday'];

let buffer: string[] = [''];
/** Costanti definite con define() durante il disegno della pagina (si azzerano a ogni disegno). */
let definite: Record<string, any> = {};
const estensioni: Record<string, Funzione> = {};
const widget: Record<string, (V: any, F: any) => void> = {};
const costanti: Record<string, any> = { __DIR__: '', __FILE__: '', __LINE__: 0, PHP_EOL: '\n', ENT_QUOTES: 3, JSON_UNESCAPED_UNICODE: 256, JSON_UNESCAPED_SLASHES: 64, JSON_HEX_TAG: 1, JSON_HEX_AMP: 2, JSON_HEX_APOS: 4, JSON_HEX_QUOT: 8, PUBLIC_URL: '', SORT_STRING: 2, MAX_FILE_SIZE: 10 * 1024 * 1024, PATHINFO_EXTENSION: 4, PATHINFO_FILENAME: 8, PATHINFO_BASENAME: 2, PATHINFO_DIRNAME: 1 };

class Uscita extends Error {}

/** Callable del PHP: closure, nome di funzione ('trim') o [classe, metodo]. */
function chiamabile(f: any): (...a: any[]) => any {
  if (typeof f === 'function') return f;
  if (typeof f === 'string') return (...a: any[]) => P.chiama(f, a, {}, {});
  const v = Array.isArray(f) ? f : f instanceof Map ? [...f.values()] : [];
  if (v.length === 2) return (...a: any[]) => P.chiama(`${v[0]}::${v[1]}`, a, {}, {});
  throw new Error('callable non supportato');
}

/** Data PHP minima (DateTime): istante + formattazione nell'ora di Roma. */
class DataPhp {
  ms: number;
  constructor(v?: any) { const t = P.strtotime(v); this.ms = v === undefined || v === null || v === 'now' ? Date.now() : t === false ? NaN : t * 1000; }
  format(f: string) { return P.date(f, this.ms / 1000); }
  getTimestamp() { return Math.floor(this.ms / 1000); }
  modify(s: string) { const t = P.strtotime(s, this.ms / 1000); if (t !== false) this.ms = (t as number) * 1000; return this; }
  setTime(h: number, m: number, sec = 0) {
    const d = P.date('Y-m-d', this.ms / 1000);
    this.ms = (P.strtotime(`${d} ${String(h).padStart(2, '0')}:${String(m).padStart(2, '0')}:${String(sec).padStart(2, '0')}`) as number) * 1000;
    return this;
  }
  /** DateInterval di DateTime::diff: anni, mesi, giorni (di calendario) e giorni totali. */
  diff(o: DataPhp) {
    const [da, a] = o.ms < this.ms ? [o, this] : [this, o];
    const p = (x: DataPhp) => P.date('Y-n-j-G-i-s', x.ms / 1000).split('-').map(Number);
    const [y1, m1, d1, h1, i1, s1] = p(da);
    const [y2, m2, d2, h2, i2, s2] = p(a);
    let sec = (h2 * 3600 + i2 * 60 + s2) - (h1 * 3600 + i1 * 60 + s1);
    let g = d2 - d1;
    let m = m2 - m1;
    let y = y2 - y1;
    if (sec < 0) { sec += 86400; g--; }
    if (g < 0) { g += new Date(Date.UTC(y2, m2 - 1, 0)).getUTCDate(); m--; }
    if (m < 0) { m += 12; y--; }
    const giorni = Math.floor(Math.abs(Date.parse(P.date('Y-m-d', o.ms / 1000) + 'T00:00:00Z') - Date.parse(P.date('Y-m-d', this.ms / 1000) + 'T00:00:00Z')) / 86400000);
    return { y, m, d: g, h: Math.floor(sec / 3600), i: Math.floor((sec % 3600) / 60), s: sec % 60, days: giorni, invert: o.ms < this.ms ? 1 : 0 };
  }

}

/** Parti di un istante nell'ora di Roma. */
function parti(ts: number) {
  const p = Object.fromEntries(new Intl.DateTimeFormat('en-GB', { timeZone: ROMA, year: 'numeric', month: 'numeric', day: 'numeric', hour: 'numeric', minute: 'numeric', second: 'numeric', hourCycle: 'h23', weekday: 'short', timeZoneName: 'longOffset' })
    .formatToParts(new Date(ts * 1000)).map((x) => [x.type, x.value]));
  const off = String(p.timeZoneName).replace('GMT', '') || '+00:00';
  const wd = ['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'].indexOf(p.weekday);
  return { Y: +p.year, m: +p.month, d: +p.day, H: +p.hour, i: +p.minute, s: +p.second, w: wd, off };
}

/** Istante (secondi) di una data/ora locale di Roma. */
function daLocale(Y: number, m: number, d: number, H = 0, i = 0, s = 0): number {
  const ipotesi = Date.UTC(Y, m - 1, d, H, i, s) / 1000;
  for (const delta of [-3600, -7200, 0]) {
    const t = ipotesi + delta;
    const q = parti(t);
    if (q.Y === Y && q.m === m && q.d === d && q.H === H && q.i === i) return t;
  }
  return ipotesi - 3600;
}

const pad = (n: number, l = 2) => String(n).padStart(l, '0');

export const P = {
  // ── output ──
  inizio() { buffer = ['']; definite = {}; },
  fine(): string { const s = buffer.join(''); buffer = ['']; return s; },
  o(v: any) { buffer[buffer.length - 1] += P.s(v); },
  ob_start() { buffer.push(''); return true; },
  ob_get_clean() { return buffer.length > 1 ? buffer.pop()! : ''; },
  exit(): never { throw new Uscita(); },
  Uscita,

  // ── conversioni ──
  s(v: any): string {
    if (v === null || v === undefined || v === false) return '';
    if (v === true) return '1';
    if (typeof v === 'number') return Number.isInteger(v) ? String(v) : String(+v.toPrecision(14));
    if (v instanceof DataPhp) return '';
    return String(v);
  },
  t(v: any): boolean {
    if (v === null || v === undefined || v === false || v === 0 || v === '' || v === '0') return false;
    if (Array.isArray(v)) return v.length > 0;
    if (v instanceof Map) return v.size > 0;
    if (typeof v === 'number' && Number.isNaN(v)) return true;
    return true;
  },
  n(v: any): number {
    if (typeof v === 'number') return v;
    if (v === null || v === undefined || v === false) return 0;
    if (v === true) return 1;
    const m = /^\s*[+-]?(\d+(\.\d*)?|\.\d+)([eE][+-]?\d+)?/.exec(String(v));
    return m ? Number(m[0]) : 0;
  },
  int(v: any): number { const n = P.n(v); return Number.isFinite(n) ? Math.trunc(n) : 0; },
  float(v: any): number { return P.n(v); },
  arr(v: any): any[] | Map<any, any> { if (v === null || v === undefined) return []; if (Array.isArray(v) || v instanceof Map) return v; if (typeof v === 'object') return new Map(Object.entries(v)); return [v]; },
  add(a: any, b: any) { if (Array.isArray(a) || a instanceof Map) { const r = new Map(P.iter(a)); for (const [k, v] of P.iter(b)) if (!r.has(k)) r.set(k, v); return r; } return P.n(a) + P.n(b); },
  mod(a: any, b: any) { return P.int(a) % P.int(b); },

  // ── confronti (i dati arrivano dal JSON: i numeri valgono anche se il PHP li aveva come stringhe) ──
  numerico(v: any) { return typeof v === 'number' || (typeof v === 'string' && /^\s*[+-]?(\d+(\.\d*)?|\.\d+)([eE][+-]?\d+)?\s*$/.test(v)); },
  seq(a: any, b: any) {
    if (a === b) return true;
    if (a === null || b === null || a === undefined || b === undefined) return (a ?? null) === (b ?? null);
    if (typeof a === 'boolean' || typeof b === 'boolean') return false;
    if (P.numerico(a) && P.numerico(b) && (typeof a === 'number' || typeof b === 'number')) return P.n(a) === P.n(b);
    return false;
  },
  eq(a: any, b: any): boolean {
    if (a === b) return true;
    if (a === null || a === undefined) return !P.t(b);
    if (b === null || b === undefined) return !P.t(a);
    if (typeof a === 'boolean' || typeof b === 'boolean') return P.t(a) === P.t(b);
    if (P.numerico(a) && P.numerico(b)) return P.n(a) === P.n(b);
    if (typeof a === 'number' || typeof b === 'number') return String(a) === String(b);
    return String(a) === String(b);
  },
  lt(a: any, b: any) { if (P.numerico(a) && P.numerico(b)) return P.n(a) < P.n(b); if (typeof a === 'number' || typeof b === 'number') return P.n(a) < P.n(b); return String(a ?? '') < String(b ?? ''); },
  cmp(a: any, b: any) { return P.lt(a, b) ? -1 : P.lt(b, a) ? 1 : 0; },
  nc(a: () => any, b: () => any) { try { const v = a(); return v === null || v === undefined ? b() : v; } catch { return b(); } },
  elvis(a: any, b: () => any) { return P.t(a) ? a : b(); },

  // ── accesso agli array e oggetti ──
  g(o: any, k: any): any {
    if (o === null || o === undefined) return null;
    if (o instanceof Map) { if (o.has(k)) return o.get(k); const alt = typeof k === 'number' ? String(k) : (P.numerico(k) ? Number(k) : undefined); return alt !== undefined && o.has(alt) ? o.get(alt) : null; }
    if (typeof o === 'string') { const i = P.int(k); return o[i < 0 ? o.length + i : i] ?? ''; }
    const v = o[k];
    return v === undefined ? null : v;
  },
  set(o: any, k: any, v: any) {
    // Chiavi intere come in PHP ("2026" e 2026 sono la stessa chiave)
    const kk = typeof k === 'string' && /^(0|-?[1-9]\d*)$/.test(k) ? Number(k) : typeof k === 'boolean' ? Number(k) : k;
    if (o instanceof Map) o.set(kk, v); else if (o !== null && o !== undefined) o[kk] = v;
    return v;
  },
  /** L'array (lista JS) resta lista solo se la chiave e' la prossima posizione: altrimenti diventa Map, come un array PHP. */
  serveMappa(c: any, kk: any) {
    if (!Array.isArray(c) || kk === undefined) return false;
    const n = typeof kk === 'number' ? kk : P.numerico(kk) && /^(0|[1-9]\d*)$/.test(String(kk)) ? Number(kk) : NaN;
    return !(n >= 0 && n <= c.length);
  },
  inMappa(c: any[]) { return new Map(c.map((v, i) => [i, v])); },
  auto(o: any, k: any, kk?: any) {
    let x = P.g(o, k);
    if (x === null) { x = new Map(); P.set(o, k, x); } else if (P.serveMappa(x, kk)) { x = P.inMappa(x); P.set(o, k, x); }
    return x;
  },
  autov(V: any, k: string, kk?: any) {
    if (V[k] === null || V[k] === undefined) V[k] = new Map();
    else if (P.serveMappa(V[k], kk)) V[k] = P.inMappa(V[k]);
    return V[k];
  },
  push(o: any, v: any) {
    if (Array.isArray(o)) { o.push(v); return v; }
    if (o instanceof Map) { let max = -1; for (const k of o.keys()) if (typeof k === 'number' && k > max) max = k; o.set(max + 1, v); return v; }
    return v;
  },
  PUSH(o: any) { return o; },
  assoc(voci: Array<[any, any, boolean?]>) {
    const m = new Map<any, any>();
    let prossimo = 0;
    for (const [k, v, espandi] of voci) {
      if (espandi) { for (const [kk, vv] of P.iter(v)) { if (typeof kk === 'number') m.set(prossimo++, vv); else m.set(kk, vv); } continue; }
      if (k === null) { m.set(prossimo++, v); continue; }
      const kk = typeof k === 'string' && /^(0|-?[1-9]\d*)$/.test(k) ? Number(k) : typeof k === 'boolean' ? Number(k) : k;
      m.set(kk, v);
      if (typeof kk === 'number' && kk >= prossimo) prossimo = kk + 1;
    }
    return m;
  },
  *iter(o: any): Generator<[any, any]> {
    if (o === null || o === undefined || o === false) return;
    if (Array.isArray(o)) { for (let i = 0; i < o.length; i++) yield [i, o[i]]; return; }
    if (o instanceof Map) { yield* o.entries(); return; }
    if (typeof o === 'object') { for (const k of Object.keys(o)) yield [P.numerico(k) && /^(0|-?[1-9]\d*)$/.test(k) ? Number(k) : k, o[k]]; }
  },
  isset(f: () => any) { try { const v = f(); return v !== null && v !== undefined; } catch { return false; } },
  empty(f: () => any) { try { return !P.t(f()); } catch { return true; } },
  unset(leggi: () => any, scrivi: (x: any) => any) { try { if (leggi() !== undefined) scrivi(null); } catch { /* niente */ } },
  LIST(v: any) { return v; },
  postinc(leggi: () => any, scrivi: (x: any) => any, d: number) { const v = leggi(); scrivi(P.n(v) + d); return v; },
  invoca(f: any, a: any[]) { return typeof f === 'function' ? f(...a) : null; },
  clona(v: any) { return v instanceof DataPhp ? Object.assign(new DataPhp(), { ms: v.ms }) : structuredClone(v); },
  nuovo(nome: string, a: any[]) { if (nome === 'DateTime' || nome === 'DateTimeImmutable') return new DataPhp(a[0]); throw new Error(`classe non supportata: ${nome}`); },
  metodo(o: any, m: string, a: any[]) { if (o && typeof o[m] === 'function') return o[m](...a); throw new Error(`metodo non supportato: ${m}`); },
  statico(cl: string, nome: string) { return estensioni[`${cl}::${nome}`]?.() ?? null; },
  costante(nome: string) { if (nome in definite) return definite[nome]; if (nome in costanti) return costanti[nome]; const f = estensioni[nome]; if (f) return f(); throw new Error(`costante non definita: ${nome}`); },
  include(file: any, V: any, F: any) { const nome = String(file).split('/').pop()!.replace(/\.php$/, ''); const w = widget[nome]; if (!w) throw new Error(`include non convertito: ${nome}`); w(V, F); return 1; },
  registraWidget(nome: string, f: (V: any, F: any) => void) { widget[nome] = f; },
  estendi(nome: string, f: Funzione) { estensioni[nome] = f; },

  chiama(nome: string, a: any[], V: any, F: any): any {
    const f = (P as any).fn[nome.toLowerCase()];
    if (f) return f(...a);
    // Funzioni e metodi statici delle classi di Cora: registrati dalla pagina (ricevono anche V e F).
    const e = estensioni[nome];
    if (!e) throw new Error(`funzione PHP non supportata: ${nome}`);
    return e(...a, { V, F });
  },

  // ── date ──
  strtotime(v: any, base?: number): number | false {
    if (v === null || v === undefined || v === '') return false;
    if (typeof v === 'number') return v;
    const s = String(v).trim();
    let m = /^(\d{4})-(\d{2})-(\d{2})(?:[ T](\d{2}):(\d{2})(?::(\d{2}))?(?:\.\d+)?)?(Z|[+-]\d{2}:?\d{2})?$/.exec(s);
    if (m) {
      if (m[7]) return Math.floor(Date.parse(s.replace(' ', 'T')) / 1000);
      return daLocale(+m[1], +m[2], +m[3], +(m[4] ?? 0), +(m[5] ?? 0), +(m[6] ?? 0));
    }
    const ora = base ?? Date.now() / 1000;
    if (s === 'now') return Math.floor(ora);
    if (s === 'today' || s === 'midnight') { const q = parti(ora); return daLocale(q.Y, q.m, q.d); }
    if (s === 'tomorrow') { const q = parti(ora); return daLocale(q.Y, q.m, q.d + 1); }
    if (s === 'yesterday') { const q = parti(ora); return daLocale(q.Y, q.m, q.d - 1); }
    m = /^([+-]?\d+)\s*(day|days|week|weeks|month|months|year|years|hour|hours|minute|minutes)$/i.exec(s);
    if (m) {
      const q = parti(ora);
      const n = +m[1];
      const u = m[2].toLowerCase().replace(/s$/, '');
      if (u === 'day') return daLocale(q.Y, q.m, q.d + n, q.H, q.i, q.s);
      if (u === 'week') return daLocale(q.Y, q.m, q.d + 7 * n, q.H, q.i, q.s);
      if (u === 'month') return daLocale(q.Y, q.m + n, q.d, q.H, q.i, q.s);
      if (u === 'year') return daLocale(q.Y + n, q.m, q.d, q.H, q.i, q.s);
      if (u === 'hour') return Math.floor(ora) + 3600 * n;
      return Math.floor(ora) + 60 * n;
    }
    m = /^first day of (this|next|last) month$/i.exec(s);
    if (m) { const q = parti(ora); return daLocale(q.Y, q.m + (m[1] === 'next' ? 1 : m[1] === 'last' ? -1 : 0), 1, q.H, q.i, q.s); }
    m = /^last day of (this|next|last) month$/i.exec(s);
    if (m) { const q = parti(ora); return daLocale(q.Y, q.m + (m[1] === 'next' ? 2 : m[1] === 'last' ? 0 : 1), 0, q.H, q.i, q.s); }
    const t = Date.parse(s);
    return Number.isNaN(t) ? false : Math.floor(t / 1000);
  },
  date(f: string, ts?: any): string {
    const t = ts === undefined || ts === null ? Date.now() / 1000 : typeof ts === 'number' ? ts : (P.strtotime(ts) || 0) as number;
    const q = parti(t);
    let out = '';
    for (let i = 0; i < f.length; i++) {
      const c = f[i];
      if (c === '\\') { out += f[++i] ?? ''; continue; }
      switch (c) {
        case 'd': out += pad(q.d); break;
        case 'j': out += q.d; break;
        case 'm': out += pad(q.m); break;
        case 'n': out += q.m; break;
        case 'Y': out += q.Y; break;
        case 'y': out += pad(q.Y % 100); break;
        case 'H': out += pad(q.H); break;
        case 'G': out += q.H; break;
        case 'i': out += pad(q.i); break;
        case 's': out += pad(q.s); break;
        case 'N': out += q.w === 0 ? 7 : q.w; break;
        case 'w': out += q.w; break;
        case 'D': out += GIORNI_EN[q.w].slice(0, 3); break;
        case 'l': out += GIORNI_EN[q.w]; break;
        case 'M': out += MESI_EN[q.m - 1].slice(0, 3); break;
        case 'F': out += MESI_EN[q.m - 1]; break;
        case 't': out += new Date(Date.UTC(q.Y, q.m, 0)).getUTCDate(); break;
        case 'c': out += `${q.Y}-${pad(q.m)}-${pad(q.d)}T${pad(q.H)}:${pad(q.i)}:${pad(q.s)}${q.off}`; break;
        case 'U': out += Math.floor(t); break;
        case 'A': out += q.H < 12 ? 'AM' : 'PM'; break;
        case 'a': out += q.H < 12 ? 'am' : 'pm'; break;
        case 'z': out += Math.floor((Date.UTC(q.Y, q.m - 1, q.d) - Date.UTC(q.Y, 0, 1)) / 86400000); break;
        case 'L': out += (q.Y % 4 === 0 && q.Y % 100 !== 0) || q.Y % 400 === 0 ? 1 : 0; break;
        default: out += c;
      }
    }
    return out;
  },

  fn: {} as Record<string, Funzione>,
};

const FN: Record<string, Funzione> = {
  htmlspecialchars: (s: any) => P.s(s).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;').replace(/'/g, '&#039;'),
  e: (s: any) => FN.htmlspecialchars(s),
  nl2br: (s: any) => P.s(s).replace(/(\r\n|\n|\r)/g, '<br />$1'),
  strip_tags: (s: any) => P.s(s).replace(/<[^>]*>/g, ''),
  addslashes: (s: any) => P.s(s).replace(/([\\'"])/g, '\\$1').replace(/\0/g, '\\0'),
  urlencode: (s: any) => encodeURIComponent(P.s(s)).replace(/%20/g, '+').replace(/[!'()*~]/g, (c) => '%' + c.charCodeAt(0).toString(16).toUpperCase()),
  rawurlencode: (s: any) => encodeURIComponent(P.s(s)).replace(/[!'()*]/g, (c) => '%' + c.charCodeAt(0).toString(16).toUpperCase()),
  http_build_query: (o: any) => [...P.iter(o)].map(([k, v]) => `${FN.urlencode(k)}=${FN.urlencode(v)}`).join('&'),
  json_encode: (v: any, flag = 0) => {
    const conv = (x: any): any => x instanceof Map ? (([...x.keys()].every((k, i) => k === i)) ? [...x.values()].map(conv) : Object.fromEntries([...x].map(([k, w]) => [k, conv(w)]))) : Array.isArray(x) ? x.map(conv) : x && typeof x === 'object' ? Object.fromEntries(Object.entries(x).map(([k, w]) => [k, conv(w)])) : x;
    let s = JSON.stringify(conv(v)) ?? 'null';
    if (!(flag & 64)) s = s.replace(/\//g, '\\/');
    if (!(flag & 256)) s = s.replace(/[\u007f-\uffff]/g, (c) => '\\u' + c.charCodeAt(0).toString(16).padStart(4, '0'));
    if (flag & 1) s = s.replace(/</g, '\\u003C').replace(/>/g, '\\u003E');
    if (flag & 2) s = s.replace(/&/g, '\\u0026');
    if (flag & 4) s = s.replace(/'/g, '\\u0027');
    if (flag & 8) s = s.replace(/\\"/g, '\\u0022');
    return s;
  },
  trim: (s: any, c?: any) => c === undefined || typeof c === 'object' ? P.s(s).trim() : P.s(s).replace(new RegExp(`^[${c.replace(/[\]\\^-]/g, '\\$&')}]+|[${c.replace(/[\]\\^-]/g, '\\$&')}]+$`, 'g'), ''),
  rtrim: (s: any, c?: any) => c === undefined || typeof c === 'object' ? P.s(s).replace(/[\s\0\x0B]+$/, '') : P.s(s).replace(new RegExp(`[${c.replace(/[\]\\^-]/g, '\\$&')}]+$`), ''),
  ltrim: (s: any, c?: any) => c === undefined || typeof c === 'object' ? P.s(s).replace(/^[\s\0\x0B]+/, '') : P.s(s).replace(new RegExp(`^[${c.replace(/[\]\\^-]/g, '\\$&')}]+`), ''),
  strtolower: (s: any) => P.s(s).toLowerCase(),
  strtoupper: (s: any) => P.s(s).toUpperCase(),
  mb_strtolower: (s: any) => P.s(s).toLowerCase(),
  mb_strtoupper: (s: any) => P.s(s).toUpperCase(),
  ucfirst: (s: any) => { const x = P.s(s); return x.charAt(0).toUpperCase() + x.slice(1); },
  lcfirst: (s: any) => { const x = P.s(s); return x.charAt(0).toLowerCase() + x.slice(1); },
  ucwords: (s: any) => P.s(s).replace(/(^|[\s\t\r\n\f\v])(\S)/g, (_m, a, b) => a + b.toUpperCase()),
  mb_convert_case: (s: any, modo: any) => modo === 2 ? P.s(s).toLowerCase().replace(/(^|[^\p{L}\p{N}'])(\p{L})/gu, (_m, a, b) => a + b.toUpperCase()) : modo === 0 ? P.s(s).toUpperCase() : P.s(s).toLowerCase(),
  strlen: (s: any) => new TextEncoder().encode(P.s(s)).length,
  mb_strlen: (s: any) => [...P.s(s)].length,
  substr: (s: any, a: any, l?: any) => { const x = P.s(s); const n = x.length; let i = P.int(a); if (i < 0) i = Math.max(0, n + i); if (l === undefined || l === null) return x.slice(i); let ll = P.int(l); if (ll < 0) return x.slice(i, Math.max(i, n + ll)); return x.slice(i, i + ll); },
  mb_substr: (s: any, a: any, l?: any) => { const x = [...P.s(s)]; const n = x.length; let i = P.int(a); if (i < 0) i = Math.max(0, n + i); if (l === undefined || l === null) return x.slice(i).join(''); const ll = P.int(l); if (ll < 0) return x.slice(i, Math.max(i, n + ll)).join(''); return x.slice(i, i + ll).join(''); },
  mb_strimwidth: (s: any, a: any, w: any, t: any = '') => { const x = [...P.s(s)].slice(P.int(a)); if (x.length <= P.int(w)) return x.join(''); return x.slice(0, P.int(w) - [...P.s(t)].length).join('') + P.s(t); },
  str_pad: (s: any, l: any, c: any = ' ', tipo: any = 1) => { const x = P.s(s); const n = P.int(l) - x.length; if (n <= 0) return x; const pad1 = P.s(c).repeat(n).slice(0, n); return tipo === 0 ? pad1 + x : tipo === 2 ? P.s(c).repeat(n).slice(0, Math.floor(n / 2)) + x + P.s(c).repeat(n).slice(0, Math.ceil(n / 2)) : x + pad1; },
  str_repeat: (s: any, n: any) => P.s(s).repeat(Math.max(0, P.int(n))),
  str_replace: (a: any, b: any, s: any) => { let x = P.s(s); const da = Array.isArray(a) ? a : [a]; const in_ = Array.isArray(b) ? b : da.map(() => b); da.forEach((d: any, i: number) => { x = x.split(P.s(d)).join(P.s(in_[i] ?? '')); }); return x; },
  strpos: (s: any, c: any, o: any = 0) => { const i = P.s(s).indexOf(P.s(c), P.int(o)); return i < 0 ? false : i; },
  stripos: (s: any, c: any) => { const i = P.s(s).toLowerCase().indexOf(P.s(c).toLowerCase()); return i < 0 ? false : i; },
  mb_strpos: (s: any, c: any) => { const i = [...P.s(s)].join('').indexOf(P.s(c)); return i < 0 ? false : [...P.s(s).slice(0, i)].length; },
  str_contains: (s: any, c: any) => P.s(s).includes(P.s(c)),
  str_starts_with: (s: any, c: any) => P.s(s).startsWith(P.s(c)),
  str_ends_with: (s: any, c: any) => P.s(s).endsWith(P.s(c)),
  explode: (d: any, s: any, l?: any) => { const p = P.s(s).split(P.s(d)); if (l === undefined) return p; const n = P.int(l); if (n > 0 && p.length > n) return [...p.slice(0, n - 1), p.slice(n - 1).join(P.s(d))]; if (n < 0) return p.slice(0, n); return p; },
  implode: (a: any, b?: any) => { const [sep, arr] = b === undefined || typeof b === 'object' && b && 'V' in b ? ['', a] : [a, b]; return [...P.iter(arr)].map(([, v]) => P.s(v)).join(P.s(sep)); },
  join: (a: any, b?: any) => FN.implode(a, b),
  sprintf: (f: any, ...args: any[]) => {
    const vals = args.filter((x) => !(x && typeof x === 'object' && 'V' in x && 'F' in x));
    let i = 0;
    return P.s(f).replace(/%(?:(\d+)\$)?([-+ 0]|'.)*(\d+)?(?:\.(\d+))?([sdfFuxXbceEgG%])/g, (m, pos, _fl, w, prec, tipo) => {
      if (tipo === '%') return '%';
      const v = vals[pos ? +pos - 1 : i++];
      const zero = /%(?:\d+\$)?[-+ ]*0/.test(m);
      const sinistra = m.includes('-') && !/%\d*\$?0/.test(m.replace(/-\d/, ''));
      let s: string;
      if (tipo === 'd' || tipo === 'u') s = String(P.int(v));
      else if (tipo === 'f' || tipo === 'F') s = P.n(v).toFixed(prec !== undefined ? +prec : 6);
      else if (tipo === 'x') s = P.int(v).toString(16);
      else if (tipo === 'X') s = P.int(v).toString(16).toUpperCase();
      else s = P.s(v);
      if (tipo === 's' && prec !== undefined) s = s.slice(0, +prec);
      if (w && s.length < +w) s = sinistra ? s.padEnd(+w) : s.padStart(+w, zero ? '0' : ' ');
      return s;
    });
  },
  number_format: (n: any, dec: any = 0, pd: any = '.', mig: any = ',') => {
    const d = P.int(dec);
    const v = P.n(n);
    const arrot = Math.round((Math.abs(v) + Number.EPSILON) * 10 ** d) / 10 ** d;
    const [int, frac] = arrot.toFixed(d).split('.');
    const conMig = int.replace(/\B(?=(\d{3})+(?!\d))/g, P.s(mig));
    return (v < 0 && arrot !== 0 ? '-' : '') + conMig + (d > 0 ? P.s(pd) + frac : '');
  },
  round: (n: any, p: any = 0) => { const k = 10 ** P.int(p); const v = P.n(n) * k; const r = Math.sign(v) * Math.round(Math.abs(v) + 1e-9); return r / k; },
  floor: (n: any) => Math.floor(P.n(n)),
  ceil: (n: any) => Math.ceil(P.n(n)),
  abs: (n: any) => Math.abs(P.n(n)),
  intval: (n: any) => P.int(n),
  floatval: (n: any) => P.n(n),
  boolval: (n: any) => P.t(n),
  strval: (n: any) => P.s(n),
  is_numeric: (v: any) => P.numerico(v),
  is_array: (v: any) => Array.isArray(v) || v instanceof Map,
  is_string: (v: any) => typeof v === 'string',
  is_null: (v: any) => v === null || v === undefined,
  max: (...a: any[]) => { const v = a.filter((x) => !(x && typeof x === 'object' && 'V' in x && 'F' in x)); const l = v.length === 1 ? [...P.iter(v[0])].map(([, x]) => x) : v; return l.reduce((m, x) => (P.lt(m, x) ? x : m)); },
  min: (...a: any[]) => { const v = a.filter((x) => !(x && typeof x === 'object' && 'V' in x && 'F' in x)); const l = v.length === 1 ? [...P.iter(v[0])].map(([, x]) => x) : v; return l.reduce((m, x) => (P.lt(x, m) ? x : m)); },
  count: (a: any) => a === null || a === undefined ? 0 : Array.isArray(a) ? a.length : a instanceof Map ? a.size : typeof a === 'object' ? Object.keys(a).length : 1,
  sizeof: (a: any) => FN.count(a),
  in_array: (x: any, a: any, stretto: any = false) => [...P.iter(a)].some(([, v]) => (stretto === true ? P.seq(v, x) : P.eq(v, x))),
  array_key_exists: (k: any, a: any) => a instanceof Map ? a.has(k) || a.has(String(k)) : a !== null && typeof a === 'object' && Object.prototype.hasOwnProperty.call(a, k),
  array_keys: (a: any) => [...P.iter(a)].map(([k]) => k),
  array_values: (a: any) => [...P.iter(a)].map(([, v]) => v),
  array_merge: (...a: any[]) => { const v = a.filter((x) => !(x && typeof x === 'object' && 'V' in x && 'F' in x)); if (v.every(Array.isArray)) return v.flat(); return P.assoc(v.flatMap((x) => [...P.iter(x)].map(([k, w]) => [typeof k === 'number' ? null : k, w] as [any, any]))); },
  array_slice: (a: any, o: any, l?: any) => { const e = [...P.iter(a)]; const s = P.int(o) < 0 ? e.length + P.int(o) : P.int(o); const r = e.slice(s, l === undefined || l === null ? undefined : s + P.int(l)); return Array.isArray(a) || r.every(([k]) => typeof k === 'number') ? r.map(([, v]) => v) : new Map(r); },
  array_map: (f0: any, a: any) => { const f = chiamabile(f0); if (Array.isArray(a)) return a.map((x) => f(x)); const r = new Map(); for (const [k, v] of P.iter(a)) r.set(k, f(v)); return r; },
  array_filter: (a: any, f0?: any) => { const f = f0 === undefined || f0 === null ? undefined : chiamabile(f0); const r = new Map(); for (const [k, v] of P.iter(a)) if (f ? P.t(f(v)) : P.t(v)) r.set(k, v); return Array.isArray(a) && [...r.keys()].every((k, i) => k === i) ? [...r.values()] : r; },
  array_column: (a: any, c: any, k?: any) => { if (k === undefined || k === null) return [...P.iter(a)].map(([, r]) => P.g(r, c)); const m = new Map(); for (const [, r] of P.iter(a)) m.set(P.g(r, k), c === null ? r : P.g(r, c)); return m; },
  array_sum: (a: any) => [...P.iter(a)].reduce((s, [, v]) => s + P.n(v), 0),
  array_unique: (a: any) => { const visti = new Set<string>(); const r: any[] = []; for (const [, v] of P.iter(a)) { const k = P.s(v); if (!visti.has(k)) { visti.add(k); r.push(v); } } return r; },
  array_reverse: (a: any) => [...P.iter(a)].map(([, v]) => v).reverse(),
  array_intersect: (a: any, b: any) => [...P.iter(a)].map(([, v]) => v).filter((v) => [...P.iter(b)].some(([, w]) => P.s(w) === P.s(v))),
  array_fill_keys: (k: any, v: any) => new Map([...P.iter(k)].map(([, x]) => [x, v])),
  array_flip: (a: any) => new Map([...P.iter(a)].map(([k, v]) => [v, k])),
  array_search: (x: any, a: any) => { for (const [k, v] of P.iter(a)) if (P.eq(v, x)) return k; return false; },
  array_pad: (a: any, n: any, v: any) => { const r = [...P.iter(a)].map(([, x]) => x); while (r.length < P.int(n)) r.push(v); return r; },
  array_combine: (k: any, v: any) => { const ks = [...P.iter(k)].map(([, x]) => x); const vs = [...P.iter(v)].map(([, x]) => x); return new Map(ks.map((x, i) => [x, vs[i]])); },
  range: (a: any, b: any) => { const r = []; for (let i = P.int(a); i <= P.int(b); i++) r.push(i); return r; },
  array_key_first: (a: any) => { for (const [k] of P.iter(a)) return k; return null; },
  array_key_last: (a: any) => { let u = null; for (const [k] of P.iter(a)) u = k; return u; },
  reset: (a: any) => { for (const [, v] of P.iter(a)) return v; return false; },
  end: (a: any) => { let u: any = false; for (const [, v] of P.iter(a)) u = v; return u; },
  current: (a: any) => FN.reset(a),
  key: (a: any) => FN.array_key_first(a),
  date: (f: any, t?: any) => (t && typeof t === 'object' && 'V' in t ? P.date(P.s(f)) : P.date(P.s(f), t)),
  strtotime: (s: any, b?: any) => P.strtotime(s, typeof b === 'number' ? b : undefined),
  time: () => Math.floor(Date.now() / 1000),
  mktime: (H: any, i: any, s: any, m: any, d: any, Y: any) => daLocale(P.int(Y), P.int(m), P.int(d), P.int(H), P.int(i), P.int(s)),
  checkdate: (m: any, d: any, y: any) => { const x = new Date(Date.UTC(P.int(y), P.int(m) - 1, P.int(d))); return x.getUTCMonth() === P.int(m) - 1 && x.getUTCDate() === P.int(d); },
  cal_days_in_month: (_c: any, m: any, y: any) => new Date(Date.UTC(P.int(y), P.int(m), 0)).getUTCDate(),
  preg_split: (re: any, s: any) => P.s(s).split(convertiRegex(P.s(re))).filter((x, i, arr) => !(x === '' && (i === 0 || i === arr.length - 1) && false)),
  preg_match: (re: any, s: any, out?: any) => { const m = convertiRegex(P.s(re)).exec(P.s(s)); if (out && Array.isArray(out)) { out.length = 0; if (m) out.push(...m); } return m ? 1 : 0; },
  preg_replace: (re: any, r: any, s: any) => P.s(s).replace(convertiRegex(P.s(re), true), P.s(r).replace(/\$(\d)/g, '$$$1').replace(/\\(\d)/g, '$$$1')),
  preg_quote: (s: any) => P.s(s).replace(/[.\\+*?[^\]$(){}=!<>|:\-#/]/g, '\\$&'),
  md5: () => { throw new Error('md5 non disponibile nel browser'); },
  uniqid: () => Date.now().toString(16),
  rand: (a: any = 0, b: any = 2147483647) => P.int(a) + Math.floor(Math.random() * (P.int(b) - P.int(a) + 1)),
  mt_rand: (a: any = 0, b: any = 2147483647) => FN.rand(a, b),
  random_int: (a: any, b: any) => FN.rand(a, b),
  bin2hex: (s: any) => [...new TextEncoder().encode(P.s(s))].map((x) => x.toString(16).padStart(2, '0')).join(''),
  random_bytes: (n: any) => String.fromCharCode(...crypto.getRandomValues(new Uint8Array(P.int(n)))),
  basename: (s: any, suf?: any) => { let b = P.s(s).replace(/\/+$/, '').split('/').pop() ?? ''; if (typeof suf === 'string' && b.endsWith(suf)) b = b.slice(0, -suf.length); return b; },
  pi: () => Math.PI,
  pathinfo: (s: any, op?: any) => { const b = FN.basename(s); const i = b.lastIndexOf('.'); const ext = i >= 0 ? b.slice(i + 1) : ''; if (op === 4) return ext; if (op === 8) return i >= 0 ? b.slice(0, i) : b; return P.assoc([['basename', b], ['extension', ext], ['filename', i >= 0 ? b.slice(0, i) : b]]); },
  dirname: () => '',
  function_exists: () => true,
  class_exists: () => true,
  method_exists: () => true,
  defined: (c: any) => P.s(c) in costanti || P.s(c) in definite,
  define: (c: any, v: any) => { if (P.s(c) in definite) return false; definite[P.s(c)] = v; return true; },
  constant: (c: any) => (P.s(c) in definite ? definite[P.s(c)] : costanti[P.s(c)]),
  is_file: () => false,
  file_exists: () => false,
  error_log: () => true,
  usort: (a: any, f0: any) => { const f = chiamabile(f0); if (Array.isArray(a)) a.sort((x, y) => P.int(f(x, y))); return true; },
  uasort: (a: any, f0: any) => { const f = chiamabile(f0); if (a instanceof Map) { const e = [...a].sort(([, x], [, y]) => P.int(f(x, y))); a.clear(); e.forEach(([k, v]) => a.set(k, v)); } else if (Array.isArray(a)) a.sort((x, y) => P.int(f(x, y))); return true; },
  sort: (a: any) => { if (Array.isArray(a)) a.sort((x, y) => P.cmp(x, y)); return true; },
  rsort: (a: any) => { if (Array.isArray(a)) a.sort((x, y) => P.cmp(y, x)); return true; },
  ksort: (a: any) => { if (a instanceof Map) { const e = [...a].sort(([x], [y]) => P.cmp(x, y)); a.clear(); e.forEach(([k, v]) => a.set(k, v)); } return true; },
  krsort: (a: any) => { if (a instanceof Map) { const e = [...a].sort(([x], [y]) => P.cmp(y, x)); a.clear(); e.forEach(([k, v]) => a.set(k, v)); } return true; },
  asort: (a: any) => FN.uasort(a, (x: any, y: any) => P.cmp(x, y)),
  arsort: (a: any) => FN.uasort(a, (x: any, y: any) => P.cmp(y, x)),
  array_push: (a: any, ...v: any[]) => { for (const x of v.filter((y) => !(y && typeof y === 'object' && 'V' in y && 'F' in y))) P.push(a, x); return FN.count(a); },
  array_pop: (a: any) => Array.isArray(a) ? a.pop() ?? null : null,
  array_shift: (a: any) => Array.isArray(a) ? a.shift() ?? null : null,
  array_unshift: (a: any, v: any) => Array.isArray(a) ? a.unshift(v) : 0,
  array_splice: (a: any, o: any, l?: any) => Array.isArray(a) ? a.splice(P.int(o), l === undefined ? undefined : P.int(l)) : [],
  array_fill: (s: any, n: any, v: any) => { const m = new Map(); for (let i = 0; i < P.int(n); i++) m.set(P.int(s) + i, v); return m; },
  iterator_to_array: (a: any) => a,
  print_r: (v: any, r?: any) => (r === true ? JSON.stringify(v) : (P.o(JSON.stringify(v)), true)),
  var_export: (v: any) => JSON.stringify(v),
  gettype: (v: any) => typeof v,
  setlocale: () => false,
  checkdnsrr: () => true,
  max_len: () => 0,
};
P.fn = FN;

// ── Mersenne Twister di PHP (ext/standard/mt_rand.c, modalita' MT19937) ──
// Serve per riprodurre gli ordinamenti "casuali ma stabili" (mt_srand(seme) + mt_rand).
const MT = { stato: new Uint32Array(624), resto: 0, prossimo: 0, seminato: false };
function mtTwist(m: number, u: number, v: number) {
  const mix = (u & 0x80000000) | (v & 0x7fffffff);
  return (m ^ (mix >>> 1) ^ ((-(v & 1) >>> 0) & 0x9908b0df)) >>> 0;
}
function mtReload() {
  const s = MT.stato;
  let p = 0;
  for (let i = 624 - 397; i--; ++p) s[p] = mtTwist(s[p + 397], s[p], s[p + 1]);
  for (let i = 397; --i; ++p) s[p] = mtTwist(s[p + 397 - 624], s[p], s[p + 1]);
  s[p] = mtTwist(s[p + 397 - 624], s[p], s[0]);
  MT.resto = 624;
  MT.prossimo = 0;
}
function mtSrand(seme: number) {
  const s = MT.stato;
  s[0] = seme >>> 0;
  for (let i = 1; i < 624; i++) s[i] = (Math.imul(1812433253, s[i - 1] ^ (s[i - 1] >>> 30)) + i) >>> 0;
  mtReload();
  MT.seminato = true;
}
function mtRand32(): number {
  if (!MT.seminato) mtSrand((Math.random() * 0x100000000) >>> 0);
  if (MT.resto === 0) mtReload();
  --MT.resto;
  let s1 = MT.stato[MT.prossimo++];
  s1 ^= s1 >>> 11;
  s1 ^= (s1 << 7) & 0x9d2c5680;
  s1 ^= (s1 << 15) & 0xefc60000;
  return (s1 ^ (s1 >>> 18)) >>> 0;
}
function mtRange(min: number, max: number): number {
  const umax = (max - min) >>> 0;
  let r = mtRand32();
  if (umax === 0xffffffff) return r + min;
  const u = umax + 1;
  if ((u & (u - 1)) !== 0) {
    const limite = 0xffffffff - (0xffffffff % u) - 1;
    while (r > limite) r = mtRand32();
  }
  return (r % u) + min;
}
const CRC = (() => { const t = new Uint32Array(256); for (let n = 0; n < 256; n++) { let c = n; for (let k = 0; k < 8; k++) c = c & 1 ? 0xedb88320 ^ (c >>> 1) : c >>> 1; t[n] = c >>> 0; } return t; })();
function crc32(s: string): number {
  let c = 0xffffffff;
  for (const b of new TextEncoder().encode(s)) c = CRC[(c ^ b) & 0xff] ^ (c >>> 8);
  return (c ^ 0xffffffff) >>> 0;
}
Object.assign(FN, {
  crc32: (s: any) => crc32(P.s(s)),
  mt_srand: (seme?: any) => { if (seme === undefined || seme === null) MT.seminato = false; else mtSrand(P.int(seme)); return null; },
  mt_rand: (a?: any, b?: any) => (a === undefined ? mtRand32() >>> 1 : mtRange(P.int(a), P.int(b))),
});

/** Espressione regolare PHP (/.../flag) -> RegExp. */
function convertiRegex(re: string, globale = false): RegExp {
  const d = re[0];
  const fine = re.lastIndexOf(d === '(' ? ')' : d === '{' ? '}' : d);
  let flag = re.slice(fine + 1).replace(/[^imsux]/g, '').replace('x', '');
  if (flag.includes('u')) flag = flag.replace('u', '') + 'u';
  if (globale) flag += 'g';
  return new RegExp(re.slice(1, fine), flag);
}

/** Esegue un template convertito e restituisce l'HTML (un exit() a meta' si ferma li', come in PHP). */
export function esegui(f: (V: any, F: any) => void, V: any, F: any = {}): string {
  P.inizio();
  try {
    f(V, F);
  } catch (e) {
    if (!(e instanceof Uscita)) throw e;
  }
  return P.fine();
}
