import { Injectable, Logger } from '@nestjs/common';
import { createHash, randomBytes } from 'crypto';
import * as fs from 'fs';
import * as path from 'path';
import PDFDocument = require('pdfkit');
import * as QRCode from 'qrcode';
import { DataSource, EntityManager } from 'typeorm';
import { decodificaCodiceFiscale } from '../../common/calcoli/codice-fiscale';
import { oggiRoma } from '../../common/calcoli/php';
import { senzaIsolamento } from '../../common/database/isolamento';
import { contenutoCoerente, estensione, FileCaricato, fileSicuro } from '../../common/file/caricamenti';
import { cartellaStorage, urlPubblico } from '../../config/app';
import { ETICHETTE_EVENTI as ETICHETTE_EVENTI_PUBBLICHE } from './academy-staff.service';
import { AcademyService, Attore } from './academy.service';
import JSZip = require('jszip');
import sanitizeHtml = require('sanitize-html');
// eslint-disable-next-line @typescript-eslint/no-require-imports
const sharp: (input?: any) => any = require('sharp');

export const SEGNAPOSTO: Record<string, string[]> = {
  Dipendente: ['{NOME}', '{COGNOME}', '{NOME_COGNOME}', '{COGNOME_NOME}', '{CF}', '{DATA_NASCITA}', '{LUOGO_NASCITA}', '{MANSIONE}', '{REPARTO}'],
  Corso: ['{CORSO}', '{ORE}', '{DATA_INIZIO}', '{DATA_COMPLETAMENTO}', '{AZIENDA}'],
  Attestato: ['{NUMERO_ATTESTATO}', '{DATA_EMISSIONE}', '{CODICE_VERIFICA}', '{URL_VERIFICA}'],
};
const IMMAGINI = ['jpg', 'jpeg', 'png', 'gif', 'webp'];
const NOME_IMMAGINE = /^[a-f0-9]{24}\.(jpe?g|png|gif|webp)$/i;
const TAG_AMMESSI = ['b', 'i', 'u', 'strong', 'em', 'span', 'br', 'p', 'div', 'font'];
type Elemento = Record<string, any>;

const dmy = (d: string | null | undefined) => (d ? `${String(d).slice(8, 10)}/${String(d).slice(5, 7)}/${String(d).slice(0, 4)}` : '');
const escHtml = (s: string) => s.replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;').replace(/'/g, '&#039;');
const taglia = (s: string, n: number) => [...s].slice(0, n).join('');
const NAVY = '#0b3aa4';

const COLORE_CSS = [/^#[0-9a-f]{3,6}$/i, /^rgb\(\s*\d{1,3}\s*,\s*\d{1,3}\s*,\s*\d{1,3}\s*\)$/i];
const STILI_TESTO = {
  color: COLORE_CSS,
  'font-weight': [/^(normal|bold|[1-9]00)$/i],
  'font-style': [/^(normal|italic)$/i],
  'text-decoration': [/^[a-z -]+$/i],
  'text-decoration-line': [/^[a-z -]+$/i],
  'font-size': [/^\d+(\.\d+)?(px|pt|em|%)$/i],
  'text-align': [/^(left|center|right|justify)$/i],
};

/**
 * Tag ammessi del testo dell'attestato (AcademyCertificate::cleanHtml).
 * Correzione: il PHP (e il primo porting) toglievano gli attributi evento con
 * una regex che pretendeva uno spazio prima: <div/onfocus=...> passava e
 * l'editor dei modelli lo eseguiva all'HR. Qui solo stili di testo e i
 * colori di <font>, nessun altro attributo.
 */
export function pulisciHtml(html: string): string {
  return sanitizeHtml(html, {
    allowedTags: TAG_AMMESSI,
    allowedAttributes: { span: ['style'], div: ['style'], p: ['style'], font: ['color', 'size', 'face'] },
    allowedStyles: { '*': STILI_TESTO },
    disallowedTagsMode: 'discard',
    nonTextTags: ['script', 'style', 'textarea', 'option', 'noscript', 'title', 'template', 'iframe', 'object'],
    allowedSchemes: [],
    transformTags: {
      font: (tag, a) => {
        const attribs: sanitizeHtml.Attributes = {};
        if (a.color && COLORE_CSS.some((r) => r.test(a.color))) attribs.color = a.color;
        if (a.size && /^[1-7]$/.test(a.size)) attribs.size = a.size;
        if (a.face && /^[\w ,'-]{1,60}$/.test(a.face)) attribs.face = a.face;
        return { tagName: tag, attribs };
      },
    },
  });
}

/** Solo campi noti e HTML dei testi ridotto ai tag ammessi (sanitizeLayout). */
export function pulisciLayout(layout: unknown[]): Elemento[] {
  const n = (v: unknown, d: number) => (v === undefined || v === null || v === '' ? d : Number.parseFloat(String(v)) || 0);
  return layout.filter((e) => e && typeof e === 'object' && !Array.isArray(e)).map((el: any) => ({
    id: Number.parseInt(String(el.id ?? 0), 10) || 0,
    type: ['text', 'title', 'image', 'line', 'rect', 'qr'].includes(el.type) ? el.type : 'text',
    name: taglia(String(el.name ?? '').replace(/<[^>]*>/g, ''), 80),
    x: n(el.x, 0), y: n(el.y, 0), width: Math.max(1, n(el.width, 100)), height: Math.max(1, n(el.height, 30)),
    content: pulisciHtml(String(el.content ?? '')),
    fontSize: Math.max(4, Math.min(200, n(el.fontSize, 14))),
    color: /^#[0-9a-fA-F]{6}$/.test(String(el.color ?? '')) ? el.color : '#1f2937',
    bold: !!el.bold && el.bold !== '0', italic: !!el.italic && el.italic !== '0',
    align: ['left', 'center', 'right'].includes(el.align) ? el.align : 'left',
    src: nomeImmagine(String(el.src ?? '')) ? String(el.src) : '',
    locked: !!el.locked && el.locked !== '0',
    visible: !('visible' in el) || (!!el.visible && el.visible !== '0'),
  }));
}

/** Layout salvato -> layout ripulito per l'editor (anche i modelli arrivati dal PHP). */
export function layoutSicuro(json: string | null | undefined): string {
  let layout: unknown;
  try { layout = JSON.parse(String(json ?? '[]')); } catch { return '[]'; }
  return JSON.stringify(layout && typeof layout === 'object' ? pulisciLayout(Object.values(layout)) : []);
}

/** Nome del file di un'immagine caricata nell'Academy (niente indirizzi esterni). */
export function nomeImmagine(src: string): string | null {
  return /academy-file\.php\?img=([a-f0-9]{24}\.(?:jpe?g|png|gif|webp))$/i.exec(src)?.[1] ?? null;
}

/** Layout predefinito (A4 orizzontale) per i corsi senza modello. */
export function layoutPredefinito(): Elemento[] {
  const txt = (id: number, content: string, y: number, h: number, size: number, bold = false, color = '#1f2937') =>
    ({ id, type: 'text', x: 60, y, width: 722, height: h, content, fontSize: size, color, bold, align: 'center', visible: true });
  return [
    { id: 1, type: 'rect', x: 24, y: 24, width: 794, height: 547, color: NAVY, visible: true },
    txt(2, '{AZIENDA}', 60, 24, 14, true, NAVY),
    txt(3, 'ATTESTATO DI PARTECIPAZIONE', 110, 44, 30, true, NAVY),
    txt(4, 'Si attesta che', 185, 22, 13),
    txt(5, '{NOME_COGNOME}', 212, 36, 26, true),
    txt(6, 'Codice fiscale {CF}', 252, 20, 11, false, '#4b5563'),
    txt(7, 'ha completato il corso', 290, 22, 13),
    txt(8, '{CORSO}', 316, 32, 20, true, NAVY),
    txt(9, 'Durata: {ORE} ore - iniziato il {DATA_INIZIO}, completato il {DATA_COMPLETAMENTO}', 358, 20, 11, false, '#4b5563'),
    { id: 10, type: 'line', x: 321, y: 410, width: 200, height: 1, color: '#cbd5e1', visible: true },
    { id: 11, type: 'text', x: 60, y: 470, width: 400, height: 54, content: 'Attestato n. {NUMERO_ATTESTATO}<br>Emesso il {DATA_EMISSIONE}<br>Codice di verifica {CODICE_VERIFICA}',
      fontSize: 10, color: '#4b5563', bold: false, align: 'left', visible: true },
    { id: 12, type: 'qr', x: 712, y: 450, width: 76, height: 76, color: '#111827', visible: true },
  ];
}

const ENTITA: Record<string, string> = { amp: '&', lt: '<', gt: '>', quot: '"', apos: "'", nbsp: ' ', egrave: 'è', eacute: 'é', agrave: 'à', ograve: 'ò', ugrave: 'ù', igrave: 'ì', euro: '€' };
const decodifica = (s: string) => s.replace(/&(#x[0-9a-f]+|#\d+|[a-z]+);/gi, (m, e: string) => {
  if (e[0] === '#') { const c = e[1].toLowerCase() === 'x' ? Number.parseInt(e.slice(2), 16) : Number(e.slice(1)); return Number.isFinite(c) ? String.fromCodePoint(c) : m; }
  return ENTITA[e.toLowerCase()] ?? m;
});

type Stile = { b: boolean; i: boolean; u: boolean; colore: string; dimensione: number };
type Pezzo = Stile & { testo: string };

/** Testo HTML (tag ammessi) -> righe di pezzi con il loro stile. */
function righeHtml(html: string, base: Stile, scala: number): Pezzo[][] {
  const righe: Pezzo[][] = [[]];
  const pila: Array<{ tag: string; stile: Stile }> = [];
  let stile = { ...base };
  const aCapo = () => { righe.push([]); };
  const re = /<(\/?)([a-z0-9]+)([^>]*)>|([^<]+)/gi;
  let m: RegExpExecArray | null;
  while ((m = re.exec(html))) {
    if (m[4] !== undefined) { righe[righe.length - 1].push({ ...stile, testo: decodifica(m[4]) }); continue; }
    const chiude = m[1] === '/';
    const tag = m[2].toLowerCase();
    if (tag === 'br') { aCapo(); continue; }
    if (tag === 'p' || tag === 'div') { if (righe[righe.length - 1].length) aCapo(); if (chiude) continue; }
    if (chiude) {
      const i = pila.map((x) => x.tag).lastIndexOf(tag);
      if (i >= 0) { stile = pila[i].stile; pila.length = i; }
      continue;
    }
    pila.push({ tag, stile });
    stile = { ...stile };
    if (tag === 'b' || tag === 'strong') stile.b = true;
    if (tag === 'i' || tag === 'em') stile.i = true;
    if (tag === 'u') stile.u = true;
    const attr = m[3];
    const col = /color\s*[:=]\s*["']?\s*(#[0-9a-f]{3,6}|rgb\([^)]*\))/i.exec(attr)?.[1];
    if (col) stile.colore = col.startsWith('rgb') ? '#' + (col.match(/\d+/g) ?? []).slice(0, 3).map((x) => Number(x).toString(16).padStart(2, '0')).join('') : col;
    if (/font-weight\s*:\s*(bold|[6-9]00)/i.test(attr)) stile.b = true;
    if (/font-style\s*:\s*italic/i.test(attr)) stile.i = true;
    if (/text-decoration[^;"]*underline/i.test(attr)) stile.u = true;
    const px = /font-size\s*:\s*([\d.]+)px/i.exec(attr)?.[1];
    if (px) stile.dimensione = Number(px) * scala || stile.dimensione;
  }
  while (righe.length > 1 && !righe[righe.length - 1].length) righe.pop();
  return righe;
}

/**
 * Attestati dell'Academy (AcademyCertificate.php): modelli dall'editor
 * visuale, PDF generato una volta e salvato col suo SHA-256, numerazione
 * annuale per azienda, ZIP, verifica dal QR.
 *
 * Correzioni: le immagini dei modelli si riconoscono dal contenuto (il PHP
 * controllava solo il tipo dichiarato dall'estensione e da finfo, e accettava
 * SVG con script come "image/"); la numerazione si serializza per azienda
 * nella stessa transazione dell'inserimento.
 */
@Injectable()
export class AttestatiService {
  private readonly logger = new Logger('Attestati');
  constructor(private readonly ds: DataSource, private readonly academy: AcademyService) {}

  async modelli(cid: number) {
    const righe = await this.ds.query(
      `SELECT t.*, (SELECT COUNT(*) FROM academy_courses c WHERE c.certificate_template_id = t.id)::int AS course_count
         FROM academy_certificate_templates t WHERE t.company_id = $1 ORDER BY t.name, t.id`, [cid]);
    return righe.map((t: any) => ({ ...t, layout_json: layoutSicuro(t.layout_json) }));
  }

  async modello(id: number, cid: number) {
    const [t] = await this.ds.query('SELECT * FROM academy_certificate_templates WHERE id = $1 AND company_id = $2', [id, cid]);
    return t ?? null;
  }

  async salvaModello(nome: string, orientamento: string, layoutJson: string, id: number | null, cid: number, uid: number) {
    const n = nome.trim();
    if (n === '') return { success: false as const, error: 'Il nome del modello è obbligatorio' };
    let layout: unknown;
    try { layout = JSON.parse(layoutJson); } catch { layout = null; }
    if (!layout || typeof layout !== 'object') return { success: false as const, error: 'Layout non valido' };
    const riga = [taglia(n, 150), orientamento === 'portrait' ? 'portrait' : 'landscape', JSON.stringify(pulisciLayout(Object.values(layout as object)))];
    if (id !== null) {
      if (!(await this.modello(id, cid))) return { success: false as const, error: 'Modello non trovato' };
      await this.ds.query('UPDATE academy_certificate_templates SET name = $3, orientation = $4, layout_json = $5 WHERE id = $1 AND company_id = $2', [id, cid, ...riga]);
      return { success: true as const, id };
    }
    const [{ id: nuovo }] = await this.ds.query(
      'INSERT INTO academy_certificate_templates (company_id, name, orientation, layout_json, created_by_user_id) VALUES ($1, $2, $3, $4, $5) RETURNING id', [cid, ...riga, uid]);
    return { success: true as const, id: nuovo as number };
  }

  async duplicaModello(id: number, cid: number, uid: number) {
    const t = await this.modello(id, cid);
    if (!t) return { success: false as const, error: 'Modello non trovato' };
    return this.salvaModello(`${t.name} (copia)`, t.orientation, t.layout_json || '[]', null, cid, uid);
  }

  async eliminaModello(id: number, cid: number) {
    if (!(await this.modello(id, cid))) return { success: false as const, error: 'Modello non trovato' };
    // I corsi che lo usavano tornano al modello predefinito (FK ON DELETE SET NULL)
    await this.ds.query('DELETE FROM academy_certificate_templates WHERE id = $1 AND company_id = $2', [id, cid]);
    return { success: true as const };
  }

  private cartellaImmagini(cid: number) { return cartellaStorage('documents', 'academy', String(cid), 'images'); }

  /** Immagine dei modelli (logo, sfondo, firma): riconosciuta dal contenuto. */
  caricaImmagine(f: FileCaricato | undefined, cid: number) {
    if (!f || !f.size) return { success: false, error: 'Nessun file ricevuto' };
    if (f.size > 5 * 1024 * 1024) return { success: false, error: 'Immagine troppo grande (massimo 5MB)' };
    const ext = estensione(f.originalname);
    if (!IMMAGINI.includes(ext) || !contenutoCoerente(ext, f.buffer)) return { success: false, error: 'Formato non supportato (jpg, png, gif, webp)' };
    const dir = this.cartellaImmagini(cid);
    fs.mkdirSync(dir, { recursive: true, mode: 0o755 });
    const nome = `${randomBytes(12).toString('hex')}.${ext}`;
    fs.writeFileSync(path.join(dir, nome), f.buffer, { mode: 0o640 });
    return { success: true, url: `/academy-file.php?img=${nome}` };
  }

  /** File di un'immagine dei modelli, solo se esiste nell'azienda. */
  percorsoImmagine(nome: string, cid: number): string | null {
    if (!NOME_IMMAGINE.test(nome)) return null;
    const p = path.join(this.cartellaImmagini(cid), nome);
    return fs.existsSync(p) && fs.statSync(p).isFile() ? p : null;
  }

  urlVerifica(token: string) { return urlPubblico(`/verifica-attestato.php?t=${token}`); }

  private async datiAssegnazione(id: number, cid: number) {
    const [a] = await this.ds.query(
      `SELECT a.*, to_char(a.started_at AT TIME ZONE 'Europe/Rome', 'YYYY-MM-DD') AS started_day, to_char(a.completed_at AT TIME ZONE 'Europe/Rome', 'YYYY-MM-DD') AS completed_day,
              c.title AS course_title, c.duration_hours, c.certificate_template_id, e.first_name, e.last_name, e.fiscal_code, e.birth_date, e.position,
              d.name AS department_name, co.name AS company_name
         FROM academy_assignments a JOIN academy_courses c ON c.id = a.course_id JOIN employees e ON e.id = a.employee_id
         LEFT JOIN departments d ON d.id = e.department_id LEFT JOIN companies co ON co.id = a.company_id
        WHERE a.id = $1 AND a.company_id = $2`, [id, cid]);
    return a ?? null;
  }

  /** Valori dei campi automatici (placeholderValues). */
  valori(a: Record<string, any>, numero: string, emesso: string, token: string): Record<string, string> {
    let luogo = '';
    let nascita: string | null = a.birth_date ?? null;
    if (a.fiscal_code) {
      const d = decodificaCodiceFiscale(String(a.fiscal_code));
      if (d) {
        luogo = `${d.birth_city ?? ''}${d.birth_province ? ` (${d.birth_province})` : ''}`.trim() || d.birth_state || '';
        nascita = nascita || d.birth_date;
      }
    }
    const ore = a.duration_hours !== null && a.duration_hours !== undefined && a.duration_hours !== ''
      ? Number(a.duration_hours).toFixed(2).replace('.', ',').replace(/0+$/, '').replace(/,$/, '') : '';
    return {
      '{NOME}': a.first_name ?? '', '{COGNOME}': a.last_name ?? '', '{NOME_COGNOME}': `${a.first_name ?? ''} ${a.last_name ?? ''}`.trim(),
      '{COGNOME_NOME}': `${a.last_name ?? ''} ${a.first_name ?? ''}`.trim(), '{CF}': a.fiscal_code ?? '', '{DATA_NASCITA}': dmy(nascita), '{LUOGO_NASCITA}': luogo,
      '{MANSIONE}': a.position ?? '', '{REPARTO}': a.department_name ?? '', '{CORSO}': a.course_title ?? '', '{ORE}': ore,
      '{DATA_INIZIO}': dmy(a.started_day ?? a.started_at), '{DATA_COMPLETAMENTO}': dmy(a.completed_day ?? a.completed_at), '{AZIENDA}': a.company_name ?? '',
      '{NUMERO_ATTESTATO}': numero, '{DATA_EMISSIONE}': dmy(emesso), '{CODICE_VERIFICA}': token.slice(0, 12).toUpperCase(), '{URL_VERIFICA}': this.urlVerifica(token),
    };
  }

  /** Valori d'esempio per l'anteprima del modello. */
  async valoriEsempio(cid: number): Promise<Record<string, string>> {
    const [az] = await senzaIsolamento(() => this.ds.query('SELECT name FROM companies WHERE id = $1', [cid]));
    const oggi = dmy(oggiRoma());
    return {
      '{NOME}': 'Mario', '{COGNOME}': 'Rossi', '{NOME_COGNOME}': 'Mario Rossi', '{COGNOME_NOME}': 'Rossi Mario', '{CF}': 'RSSMRA80A01H501U', '{DATA_NASCITA}': '01/01/1980',
      '{LUOGO_NASCITA}': 'Roma (RM)', '{MANSIONE}': 'Impiegato', '{REPARTO}': 'Amministrazione', '{CORSO}': 'Corso di esempio', '{ORE}': '4', '{DATA_INIZIO}': oggi,
      '{DATA_COMPLETAMENTO}': oggi, '{AZIENDA}': az?.name ?? 'Azienda', '{NUMERO_ATTESTATO}': `ACD-${oggiRoma().slice(0, 4)}-0001`, '{DATA_EMISSIONE}': oggi,
      '{CODICE_VERIFICA}': 'A1B2C3D4E5F6', '{URL_VERIFICA}': this.urlVerifica('0'.repeat(32)),
    };
  }

  async anteprima(orientamento: string, layoutJson: string, cid: number): Promise<Buffer> {
    let layout: unknown;
    try { layout = JSON.parse(layoutJson); } catch { layout = []; }
    const modello = { orientation: orientamento === 'portrait' ? 'portrait' : 'landscape', layout_json: JSON.stringify(pulisciLayout(layout && typeof layout === 'object' ? Object.values(layout) : [])) };
    return this.disegna(modello, await this.valoriEsempio(cid), this.urlVerifica('0'.repeat(32)), cid);
  }

  /** PDF dal layout (renderPdf): coordinate del foglio 842x595 px riportate sull'A4. */
  async disegna(modello: Record<string, any> | null, valori: Record<string, string>, urlVerifica: string, cid: number): Promise<Buffer> {
    let layout: Elemento[] | null = null;
    try { layout = modello?.layout_json ? JSON.parse(modello.layout_json) : null; } catch { layout = null; }
    let orientamento: 'portrait' | 'landscape' = modello?.orientation === 'portrait' ? 'portrait' : 'landscape';
    if (!Array.isArray(layout) || !layout.length) { layout = layoutPredefinito(); orientamento = 'landscape'; }
    const orizz = orientamento === 'landscape';
    const doc = new PDFDocument({ size: 'A4', layout: orientamento, margin: 0, autoFirstPage: true, info: { Title: `Attestato ${valori['{NUMERO_ATTESTATO}'] ?? ''}`, Creator: 'Academy' } });
    const parti: Buffer[] = [];
    doc.on('data', (b: Buffer) => parti.push(b));
    const fine = new Promise<Buffer>((ok) => doc.on('end', () => ok(Buffer.concat(parti))));
    const sx = doc.page.width / (orizz ? 842 : 595);
    const sy = doc.page.height / (orizz ? 595 : 842);
    const font = (b: boolean, i: boolean) => (b && i ? 'Helvetica-BoldOblique' : b ? 'Helvetica-Bold' : i ? 'Helvetica-Oblique' : 'Helvetica');

    for (const el of layout) {
      if ('visible' in el && !el.visible) continue;
      const x = (Number(el.x) || 0) * sx;
      const y = (Number(el.y) || 0) * sy;
      const w = (Number(el.width ?? 100) || 0) * sx;
      const h = (Number(el.height ?? 30) || 0) * sy;
      const colore = /^#[0-9a-fA-F]{6}$/.test(String(el.color ?? '')) ? el.color : /^#[0-9a-fA-F]{3}$/.test(String(el.color ?? '')) ? `#${[...el.color.slice(1)].map((c: string) => c + c).join('')}` : '#000000';
      switch (el.type ?? 'text') {
        case 'text':
        case 'title': {
          let contenuto = String(el.content ?? '');
          for (const [k, v] of Object.entries(valori)) contenuto = contenuto.split(k).join(escHtml(String(v)));
          contenuto = pulisciHtml(contenuto);
          let dimensione = (Number(el.fontSize ?? 14) || 14) * Math.min(sx, sy);
          const piano = decodifica(contenuto.replace(/<[^>]*>/g, '')).trim();
          // Testo su una riga piu' largo del box: si riduce il carattere invece di andare a capo
          if (piano !== '' && !piano.includes('\n') && !/<br|<\/p>|<\/div>/i.test(contenuto)) {
            doc.font(font(!!el.bold, !!el.italic)).fontSize(dimensione);
            const larghezza = doc.widthOfString(piano);
            const utile = Math.max(1, w - 2 * 2.83);
            if (larghezza > utile) dimensione *= (utile / larghezza) * 0.98;
          }
          const righe = righeHtml(contenuto, { b: !!el.bold, i: !!el.italic, u: false, colore, dimensione }, Math.min(sx, sy));
          this.testo(doc, righe, x, y, w, el.align === 'center' ? 'center' : el.align === 'right' ? 'right' : 'left', font);
          break;
        }
        case 'image': {
          const nome = nomeImmagine(String(el.src ?? ''));
          const p = nome ? this.percorsoImmagine(nome, cid) : null;
          if (p) {
            try {
              const ext = estensione(p);
              const dati = ['jpg', 'jpeg', 'png'].includes(ext) ? fs.readFileSync(p) : await sharp(p).png().toBuffer();
              doc.image(dati, x, y, { fit: [w, h], align: 'center', valign: 'center' });
            } catch { /* immagine non leggibile: si salta */ }
          }
          break;
        }
        case 'qr': {
          const lato = Math.min(w, h);
          const qr = QRCode.create(urlVerifica, { errorCorrectionLevel: 'M' });
          const n = qr.modules.size;
          const modulo = lato / n;
          doc.save().fillColor(colore);
          for (let r = 0; r < n; r++) for (let c = 0; c < n; c++) if (qr.modules.get(r, c)) doc.rect(x + c * modulo, y + r * modulo, modulo, modulo);
          doc.fill().restore();
          break;
        }
        case 'line':
          doc.save().strokeColor(colore).lineWidth(Math.max(0.2 * 2.83, h)).moveTo(x, y + h / 2).lineTo(x + w, y + h / 2).stroke().restore();
          break;
        case 'rect':
          doc.save().strokeColor(colore).lineWidth(0.5 * 2.83).rect(x, y, w, h).stroke().restore();
          break;
      }
    }
    doc.end();
    return fine;
  }

  /** Righe di testo con stili misti, a capo sulle parole e allineate nel box. */
  private testo(doc: PDFKit.PDFDocument, righe: Pezzo[][], x: number, y: number, w: number, allinea: 'left' | 'center' | 'right', font: (b: boolean, i: boolean) => string) {
    const pad = 2.83;
    const utile = Math.max(1, w - 2 * pad);
    let cy = y;
    // Larghezze senza crenatura, come TCPDF: carattere per carattere
    const misura = (p: Pezzo, t: string) => { doc.font(font(p.b, p.i)).fontSize(p.dimensione); return [...t].reduce((s, ch) => s + doc.widthOfString(ch), 0); };
    for (const riga of righe) {
      // Parole (con il loro stile) disposte su piu' righe se serve
      const parole: Array<{ p: Pezzo; t: string }> = [];
      for (const p of riga) for (const t of p.testo.split(/(\s+)/)) if (t !== '') parole.push({ p, t });
      const linee: Array<Array<{ p: Pezzo; t: string }>> = [[]];
      let usato = 0;
      for (const pa of parole) {
        const l = misura(pa.p, pa.t);
        if (usato + l > utile && linee[linee.length - 1].length && !/^\s+$/.test(pa.t)) { linee.push([]); usato = 0; }
        if (!linee[linee.length - 1].length && /^\s+$/.test(pa.t)) continue;
        linee[linee.length - 1].push(pa);
        usato += l;
      }
      const base = riga[0]?.dimensione ?? 10;
      for (const linea of linee) {
        while (linea.length && /^\s+$/.test(linea[linea.length - 1].t)) linea.pop();
        const alt = Math.max(base, ...linea.map((x) => x.p.dimensione)) * 1.25;
        const tot = linea.reduce((s, pa) => s + misura(pa.p, pa.t), 0);
        let cx = x + pad + (allinea === 'center' ? (utile - tot) / 2 : allinea === 'right' ? utile - tot : 0);
        for (const pa of linea) {
          const sy = cy + (alt - pa.p.dimensione * 1.15) / 2 + pa.p.dimensione * 0.23;
          doc.font(font(pa.p.b, pa.p.i)).fontSize(pa.p.dimensione).fillColor(pa.p.colore);
          const inizio = cx;
          for (const ch of pa.t) {
            const l = doc.widthOfString(ch);
            if (ch.trim() !== '') doc.text(ch, cx, sy, { lineBreak: false });
            cx += l;
          }
          if (pa.p.u) doc.save().strokeColor(pa.p.colore).lineWidth(pa.p.dimensione / 18).moveTo(inizio, sy + pa.p.dimensione * 1.05).lineTo(cx, sy + pa.p.dimensione * 1.05).stroke().restore();
        }
        cy += alt;
      }
    }
  }

  /** Genera (una volta sola) l'attestato di un'assegnazione completata. */
  async genera(assegnazione: number, cid: number, attore: Attore): Promise<{ success: true; id: number; created: boolean } | { success: false; error: string }> {
    const a = await this.datiAssegnazione(assegnazione, cid);
    if (!a) return { success: false, error: 'Assegnazione non trovata' };
    if (!a.completed_at) return { success: false, error: 'Il dipendente non ha ancora completato il corso' };
    const [esiste] = await this.ds.query('SELECT id FROM academy_certificates WHERE assignment_id = $1', [assegnazione]);
    if (esiste) return { success: true, id: esiste.id, created: false };
    const modello = a.certificate_template_id ? await this.modello(a.certificate_template_id, cid) : null;
    let creato: { id: number; numero: string } | null = null;
    let scritto: string | null = null;
    try {
      creato = await this.ds.transaction(async (m: EntityManager) => {
        await m.query('SELECT pg_advisory_xact_lock(hashtext($1))', [`academy_cert_${cid}`]);
        // Ricontrollo dentro il lock: due generazioni in parallelo non duplicano
        if ((await m.query('SELECT 1 FROM academy_certificates WHERE assignment_id = $1', [assegnazione])).length) return null;
        const ora = new Intl.DateTimeFormat('sv-SE', { timeZone: 'Europe/Rome', year: 'numeric', month: '2-digit', day: '2-digit', hour: '2-digit', minute: '2-digit', second: '2-digit' }).format(new Date());
        const anno = Number(ora.slice(0, 4));
        const [{ n }] = await m.query('SELECT COALESCE(MAX(seq_number), 0)::int + 1 AS n FROM academy_certificates WHERE company_id = $1 AND seq_year = $2', [cid, anno]);
        const numero = `ACD-${anno}-${String(n).padStart(4, '0')}`;
        const token = randomBytes(16).toString('hex');
        const pdf = await this.disegna(modello, this.valori(a, numero, ora, token), this.urlVerifica(token), cid);
        const dir = cartellaStorage('documents', 'academy', String(cid), 'certificates', String(anno));
        fs.mkdirSync(dir, { recursive: true, mode: 0o755 });
        scritto = path.join(dir, `${numero}_${token}.pdf`);
        fs.writeFileSync(scritto, pdf, { mode: 0o640 });
        const [{ id }] = await m.query(
          `INSERT INTO academy_certificates (company_id, assignment_id, number, seq_year, seq_number, issued_at, issued_by_user_id, file_path, sha256, verify_token)
           VALUES ($1, $2, $3, $4, $5, $6::timestamp AT TIME ZONE 'Europe/Rome', $7, $8, $9, $10) RETURNING id`,
          [cid, assegnazione, numero, anno, n, ora, attore.id || null, scritto, createHash('sha256').update(pdf).digest('hex'), token]);
        return { id, numero };
      });
    } catch (e: any) {
      if (scritto) fs.rmSync(scritto, { force: true });
      this.logger.error(`attestato non generato: ${e?.message ?? e}`);
      return { success: false, error: 'Errore di salvataggio del PDF' };
    }
    if (!creato) return { success: true, id: 0, created: false };
    await this.academy.evento('certificate_generated', cid, { course_id: a.course_id, assignment_id: assegnazione, employee_id: a.employee_id, details: creato.numero }, attore);
    await this.academy.avvisaDipendente(a.employee_id, 'Attestato disponibile', `L'attestato del corso "${a.course_title}" è disponibile nella sezione Academy.`, a.course_id, cid, false);
    return { success: true, id: creato.id, created: true };
  }

  /** Un blocco di attestati mancanti del corso (chiamato in sequenza dalla pagina). */
  async generaBlocco(courseId: number, ids: number[], limite: number, cid: number, attore: Attore) {
    const n = Math.max(1, Math.min(20, limite || 5));
    const p: unknown[] = [cid, courseId];
    let sql = `SELECT a.id FROM academy_assignments a LEFT JOIN academy_certificates ce ON ce.assignment_id = a.id
                WHERE a.company_id = $1 AND a.course_id = $2 AND a.completed_at IS NOT NULL AND ce.id IS NULL`;
    const scelti = ids.filter((x) => x !== 0);
    if (scelti.length) { p.push(scelti); sql += ' AND a.id = ANY($3::int[])'; }
    const tutti = await this.ds.query(`${sql} ORDER BY a.id`, p);
    let fatti = 0;
    const errori: string[] = [];
    for (const r of tutti.slice(0, n)) {
      const e = await this.genera(r.id, cid, attore);
      if (e.success) fatti++;
      else errori.push(e.error);
    }
    return { success: !errori.length || fatti > 0, generated: fatti, remaining: Math.max(0, tutti.length - fatti - errori.length), errors: errori };
  }

  /** ZIP con gli attestati del corso (o delle assegnazioni indicate). */
  async zip(courseId: number, ids: number[], cid: number): Promise<{ nome: string; dati: Buffer } | { errore: string }> {
    const [c] = await this.ds.query('SELECT title FROM academy_courses WHERE id = $1 AND company_id = $2', [courseId, cid]);
    if (!c) return { errore: 'Corso non trovato' };
    const p: unknown[] = [cid, courseId];
    let sql = `SELECT ce.number, ce.file_path, e.first_name, e.last_name FROM academy_certificates ce JOIN academy_assignments a ON a.id = ce.assignment_id
                 JOIN employees e ON e.id = a.employee_id WHERE ce.company_id = $1 AND a.course_id = $2`;
    const scelti = ids.filter((x) => x !== 0);
    if (scelti.length) { p.push(scelti); sql += ` AND a.id = ANY($${p.length}::int[])`; }
    const righe = await this.ds.query(`${sql} ORDER BY ce.id`, p);
    if (!righe.length) return { errore: 'Nessun attestato da scaricare' };
    const z = new JSZip();
    for (const r of righe) {
      const f = fileSicuro(r.file_path);
      if (f) z.file(`attestato_${r.number}_${r.last_name}_${r.first_name}`.replace(/[^A-Za-z0-9_-]/g, '_') + '.pdf', fs.readFileSync(f));
    }
    return { nome: `attestati_${c.title}`.replace(/[^A-Za-z0-9_-]/g, '_') + '.zip', dati: await z.generateAsync({ type: 'nodebuffer' }) };
  }

  /**
   * Verifica pubblica dal QR (AcademyCertificate::verify): dati essenziali,
   * integrita' del PDF e registro dell'assegnazione con IP oscurati.
   */
  async verifica(token: string) {
    if (!/^[a-f0-9]{32}$/.test(token)) return null;
    const [c] = await senzaIsolamento(() => this.ds.query(
      `SELECT ce.number, to_char(ce.issued_at AT TIME ZONE 'Europe/Rome', 'YYYY-MM-DD HH24:MI:SS') AS issued_at, ce.file_path, ce.sha256, ce.company_id, ce.assignment_id,
              to_char(a.completed_at AT TIME ZONE 'Europe/Rome', 'YYYY-MM-DD HH24:MI:SS') AS completed_at, a.declaration_text,
              c.title AS course_title, e.first_name, e.last_name, e.fiscal_code, co.name AS company_name
         FROM academy_certificates ce JOIN academy_assignments a ON a.id = ce.assignment_id JOIN academy_courses c ON c.id = a.course_id
         JOIN employees e ON e.id = a.employee_id LEFT JOIN companies co ON co.id = ce.company_id WHERE ce.verify_token = $1`, [token]));
    if (!c) return null;
    const f = fileSicuro(c.file_path);
    const integro = !!f && createHash('sha256').update(fs.readFileSync(f)).digest('hex') === c.sha256;
    const eventi = await senzaIsolamento(() => this.ds.query(
      `SELECT ev.event, ev.actor_type, ev.ip_address, to_char(ev.created_at AT TIME ZONE 'Europe/Rome', 'YYYY-MM-DD HH24:MI:SS') AS created_at, ev.details, m.title AS material_title
         FROM academy_events ev LEFT JOIN academy_materials m ON m.id = ev.material_id WHERE ev.company_id = $1 AND ev.assignment_id = $2 ORDER BY ev.id`, [c.company_id, c.assignment_id]));
    const attori: Record<string, string> = { employee: 'Dipendente', admin: 'HR', consulente_lavoro: 'Consulente del lavoro', formatore: 'Responsabile formazione', system: 'Sistema' };
    const oscura = (ip: string | null) => {
      if (!ip) return '';
      if (/^\d{1,3}(\.\d{1,3}){3}$/.test(ip)) return ip.replace(/\.\d+$/, '.***');
      return `${ip.split(':').slice(0, 3).join(':')}:****`;
    };
    return {
      ...c, file_path: undefined, sha256: undefined, file_intact: integro,
      events: eventi.map((ev: any) => ({
        at: ev.created_at, label: ETICHETTE_EVENTI_PUBBLICHE[ev.event] ?? ev.event, event: ev.event,
        detail: ev.material_title || (ev.event === 'certificate_generated' ? ev.details ?? '' : ''), actor: attori[ev.actor_type] ?? 'Staff', ip: oscura(ev.ip_address),
      })),
      chain: await this.academy.verificaCatena(c.company_id),
    };
  }
}
