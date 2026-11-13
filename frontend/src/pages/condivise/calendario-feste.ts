/**
 * Giornate aziendali e avviso del periodo (es. feste di Natale) nel
 * calendario: banner in alto con le illustrazioni, giornate nella griglia,
 * neve che scende. Solo HTML: lo inserisce htmlGriglia.
 */

export interface Giornata { id: number; day: string; kind: string; label: string }
export interface Avviso {
  id: number; title: string; message: string; resume_note: string; signature: string;
  start_date: string; end_date: string; theme: string; giornate: Giornata[];
}

const e = (s: unknown) => String(s ?? '').replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;').replace(/'/g, '&#039;');

export const TIPI: Record<string, { nome: string; cls: string }> = {
  festivo: { nome: 'Festivo', cls: 'k-fest' },
  chiusura: { nome: 'Chiusura aziendale', cls: 'k-chiusura' },
  mezza_giornata: { nome: '½ giornata', cls: 'k-mezza' },
  smart_working: { nome: 'Smart working', cls: 'k-sw' },
  presenza: { nome: 'In presenza', cls: 'k-sede' },
};
const MESI = ['', 'Gennaio', 'Febbraio', 'Marzo', 'Aprile', 'Maggio', 'Giugno', 'Luglio', 'Agosto', 'Settembre', 'Ottobre', 'Novembre', 'Dicembre'];
const DOW = ['Dom', 'Lun', 'Mar', 'Mer', 'Gio', 'Ven', 'Sab'];
const dow = (ymd: string) => DOW[new Date(`${ymd}T12:00:00Z`).getUTCDay()];
const dopo = (ymd: string) => { const d = new Date(`${ymd}T12:00:00Z`); d.setUTCDate(d.getUTCDate() + 1); return d.toISOString().slice(0, 10); };

/** Il tema natalizio vale per i giorni del periodo dell'avviso. */
export function nataleAttivo(a: Avviso | null | undefined, giorno?: string): boolean {
  if (!a || a.theme !== 'natale') return false;
  return !giorno || (giorno >= a.start_date && giorno <= a.end_date);
}

/* ---------- Illustrazioni (definite una volta, riusate con <use>) ---------- */
export const SVG_DEFS = `<svg width="0" height="0" style="position:absolute" aria-hidden="true" focusable="false"><defs>
<symbol id="fe-tree" viewBox="0 0 84 120"><rect x="36" y="100" width="12" height="16" rx="2" fill="#6b4226"/><polygon points="42,22 76,104 8,104" fill="#0f4a34"/><polygon points="42,14 68,74 16,74" fill="#16634a"/><polygon points="42,8 60,48 24,48" fill="#1f7a5a"/><path d="M22 70 Q 42 80 64 66" stroke="#f4d58d" stroke-width="1.5" fill="none"/><path d="M16 96 Q 42 108 70 92" stroke="#f4d58d" stroke-width="1.5" fill="none"/><path d="M42 22 L 26 46 Q 34 44 42 48 Q 50 44 58 46 Z" fill="#fff" opacity=".9"/><circle cx="32" cy="64" r="3.2" fill="#e04556"/><circle cx="52" cy="58" r="3" fill="#f4d58d"/><circle cx="26" cy="90" r="3.4" fill="#f4d58d"/><circle cx="46" cy="86" r="3.2" fill="#e04556"/><circle cx="62" cy="94" r="3" fill="#7fc8ff"/><circle cx="40" cy="36" r="2.6" fill="#e04556"/><polygon points="42,0 45,7 52,7 46.5,11 48.5,18 42,14 35.5,18 37.5,11 32,7 39,7" fill="#ffd166"/></symbol>
<symbol id="fe-reindeer" viewBox="0 0 58 42"><path d="M44 14 L 41 4 M41 4 L 37 5 M41 4 L 40 0 M47 13 L 50 4 M50 4 L 54 4 M50 4 L 50 0" stroke="#5a3a1a" stroke-width="1.8" stroke-linecap="round" fill="none"/><path d="M10 26 L 2 32 M14 28 L 8 37 M32 28 L 40 36 M36 26 L 46 31" stroke="#6b4226" stroke-width="3" stroke-linecap="round"/><ellipse cx="23" cy="23" rx="15" ry="7.5" fill="#a0673a"/><ellipse cx="23" cy="26" rx="10" ry="3.5" fill="#d9b48c"/><path d="M33 21 L 40 13 L 46 16 L 38 25 Z" fill="#a0673a"/><ellipse cx="47" cy="15" rx="6.5" ry="4.5" fill="#a0673a"/><circle cx="53" cy="16" r="2.3" fill="#3b2412"/><circle cx="47" cy="13" r="1.1" fill="#1b1b1b"/><ellipse cx="42" cy="11" rx="2.5" ry="1.4" fill="#8a552c" transform="rotate(-30 42 11)"/><circle cx="8" cy="19" r="3" fill="#fff"/><path d="M30 18 L 36 22" stroke="#c9123b" stroke-width="2.4"/><circle cx="33" cy="22" r="1.6" fill="#ffd166"/></symbol>
<symbol id="fe-rudolph" viewBox="0 0 58 42"><use href="#fe-reindeer"/><circle cx="53.5" cy="16" r="3" fill="#ff3b4e"/><circle cx="53.5" cy="16" r="6" fill="#ff3b4e" opacity=".25"/></symbol>
<symbol id="fe-sleigh" viewBox="0 0 110 62"><circle cx="30" cy="22" r="15" fill="#8a5a2b"/><path d="M22 10 Q 30 2 38 10" stroke="#5a3a1a" stroke-width="2" fill="none"/><rect x="22" y="12" width="8" height="8" rx="1" fill="#e04556"/><rect x="31" y="8" width="7" height="9" rx="1" fill="#2f7d5f"/><rect x="25" y="14" width="2" height="6" fill="#ffd166"/><circle cx="62" cy="22" r="12" fill="#c9123b"/><circle cx="66" cy="10" r="7" fill="#f6c9a8"/><path d="M59 11 Q 60 22 66 23 Q 73 22 73 11 Q 66 15 59 11 Z" fill="#fff"/><circle cx="68.5" cy="9" r="1" fill="#1b1b1b"/><circle cx="71" cy="11.5" r="1.6" fill="#ef8f86"/><path d="M58 6 Q 64 -6 76 2 L 72 6 Z" fill="#c9123b"/><rect x="57" y="4.5" width="17" height="4" rx="2" fill="#fff"/><circle cx="77" cy="2.5" r="2.8" fill="#fff"/><path d="M71 22 L 80 16" stroke="#c9123b" stroke-width="5" stroke-linecap="round"/><circle cx="81" cy="15" r="2.6" fill="#fff"/><path d="M12 26 L 92 26 Q 98 26 98 34 L 96 40 Q 92 46 82 46 L 26 46 Q 12 46 10 34 Z" fill="#c9123b"/><path d="M12 30 L 96 30" stroke="#ffd166" stroke-width="2"/><path d="M2 54 L 88 54 Q 104 54 106 40" stroke="#ffd166" stroke-width="3" fill="none" stroke-linecap="round"/><path d="M28 46 L 26 54 M80 46 L 82 54" stroke="#ffd166" stroke-width="3"/></symbol>
<symbol id="fe-snowman" viewBox="0 0 58 74"><circle cx="29" cy="54" r="18" fill="#fff"/><circle cx="29" cy="26" r="12" fill="#fff"/><rect x="19" y="4" width="20" height="12" rx="2" fill="#1b1b1b"/><rect x="14" y="14" width="30" height="4" rx="2" fill="#1b1b1b"/><rect x="19" y="10" width="20" height="3" fill="#c9123b"/><circle cx="25" cy="24" r="1.4" fill="#1b1b1b"/><circle cx="33" cy="24" r="1.4" fill="#1b1b1b"/><path d="M29 27 L 38 29 L 29 30 Z" fill="#ff9400"/><path d="M17 36 Q 29 42 41 36 L 41 40 Q 29 46 17 40 Z" fill="#c9123b"/><path d="M38 38 L 42 50 L 36 50 Z" fill="#c9123b"/><circle cx="29" cy="50" r="1.6" fill="#1b1b1b"/><circle cx="29" cy="58" r="1.6" fill="#1b1b1b"/><path d="M12 48 L 0 40 M47 48 L 57 38" stroke="#6b4226" stroke-width="2" stroke-linecap="round"/></symbol>
<symbol id="fe-gift" viewBox="0 0 34 32"><rect x="2" y="10" width="30" height="21" rx="2" fill="#c9123b"/><rect x="0" y="7" width="34" height="7" rx="2" fill="#e04556"/><rect x="15" y="7" width="4" height="24" fill="#ffd166"/><path d="M17 7 Q 8 -2 9 6 Q 12 8 17 7 Q 26 -2 25 6 Q 22 8 17 7" fill="#ffd166"/></symbol>
<symbol id="fe-gift2" viewBox="0 0 34 32"><rect x="2" y="10" width="30" height="21" rx="2" fill="#2f7d5f"/><rect x="0" y="7" width="34" height="7" rx="2" fill="#3f9a76"/><rect x="15" y="7" width="4" height="24" fill="#fff"/><path d="M17 7 Q 8 -2 9 6 Q 12 8 17 7 Q 26 -2 25 6 Q 22 8 17 7" fill="#fff"/></symbol>
</defs></svg>`;

const SCENA = `<svg class="fe-scena" viewBox="0 0 640 300" preserveAspectRatio="xMinYMax meet" aria-hidden="true">
  <circle cx="560" cy="58" r="30" fill="#fff6d8"/><circle cx="572" cy="50" r="28" fill="#1d5c45" opacity=".92"/>
  <g class="fe-vola">
    <path d="M60 128 C 140 110, 230 100, 330 70" fill="none" stroke="#f4d58d" stroke-width="1.5" stroke-dasharray="2 7" opacity=".7"/>
    <use href="#fe-sleigh" x="250" y="56" width="110" height="62"/>
    <line x1="338" y1="78" x2="372" y2="68" stroke="#f4d58d" stroke-width="1.2"/><line x1="388" y1="66" x2="420" y2="56" stroke="#f4d58d" stroke-width="1.2"/><line x1="436" y1="54" x2="468" y2="44" stroke="#f4d58d" stroke-width="1.2"/>
    <use href="#fe-reindeer" x="362" y="50" width="58" height="42"/><use href="#fe-reindeer" x="410" y="38" width="58" height="42"/><use href="#fe-rudolph" x="458" y="26" width="58" height="42"/>
  </g>
  <path d="M-700 220 C -400 190, -150 236, 0 232 C 120 190, 220 220, 330 206 C 450 190, 540 216, 640 198 C 800 180, 1000 226, 1300 206 L 1300 300 L -700 300 Z" fill="#cfe4da"/>
  <path d="M-700 250 C -400 236, -150 266, 0 260 C 110 232, 250 256, 360 242 C 470 228, 560 252, 640 240 C 820 226, 1020 262, 1300 246 L 1300 300 L -700 300 Z" fill="#fff"/>
  <use href="#fe-tree" x="18" y="130" width="84" height="120"/><use href="#fe-tree" x="92" y="166" width="58" height="84"/>
  <use href="#fe-tree" x="470" y="120" width="96" height="134"/><use href="#fe-tree" x="556" y="166" width="62" height="88"/><use href="#fe-tree" x="420" y="178" width="48" height="70"/>
  <use href="#fe-snowman" x="300" y="184" width="58" height="74"/>
  <use href="#fe-gift" x="168" y="228" width="34" height="32"/><use href="#fe-gift2" x="200" y="236" width="26" height="24"/><use href="#fe-gift" x="368" y="234" width="26" height="25"/>
</svg>`;

/** Fiocchi che scendono su tutta la pagina (posizioni fisse: niente salti a ogni ridisegno). */
function neve(): string {
  const forme = ['❄', '❅', '❆', '•', '•'];
  let s = 7;
  const rnd = () => { s = (s * 9301 + 49297) % 233280; return s / 233280; };
  let out = '';
  for (let i = 0; i < 40; i++) {
    const durata = 9 + rnd() * 12;
    out += `<span class="fe-fiocco" style="left:${(rnd() * 100).toFixed(1)}vw;font-size:${(8 + rnd() * 14).toFixed(0)}px;opacity:${(0.45 + rnd() * 0.5).toFixed(2)};animation-duration:${durata.toFixed(1)}s,${(2.5 + rnd() * 3).toFixed(1)}s;animation-delay:${(-rnd() * durata).toFixed(1)}s,0s">${forme[i % forme.length]}</span>`;
  }
  return `<div class="fe-neve" aria-hidden="true">${out}</div>`;
}

/** Giornate consecutive con stesso tipo ed etichetta raggruppate (4–5 gennaio). */
function gruppi(gg: Giornata[]) {
  const out: Array<{ da: string; a: string; kind: string; label: string }> = [];
  for (const g of gg) {
    const ult = out[out.length - 1];
    if (ult && ult.kind === g.kind && ult.label === g.label && dopo(ult.a) === g.day) ult.a = g.day;
    else out.push({ da: g.day, a: g.day, kind: g.kind, label: g.label });
  }
  return out;
}

function compatto(id: number): boolean {
  try { return localStorage.getItem(`cora-feste-${id}`) === '1'; } catch { return false; }
}

/** Banner dell'avviso in alto nel calendario. */
export function htmlAvviso(a: Avviso, oggi: string): string {
  const natale = a.theme === 'natale';
  const card = gruppi(a.giornate).map((g) => {
    const t = TIPI[g.kind] ?? TIPI.festivo;
    const giorni = g.da === g.a ? `${+g.da.slice(8, 10)}` : `${+g.da.slice(8, 10)}–${+g.a.slice(8, 10)}`;
    const sett = g.da === g.a ? dow(g.da) : `${dow(g.da)}–${dow(g.a)}`;
    const passato = g.a < oggi ? ' is-passato' : '';
    return `<div class="fe-gg${passato}"><div class="fe-data ${t.cls}"><b>${giorni}</b><span>${MESI[+g.da.slice(5, 7)].slice(0, 3)} · ${sett}</span></div>`
      + `<div class="fe-gg-txt"><span>${e(g.label || t.nome)}</span><em class="fe-chip ${t.cls}">${t.nome}</em></div></div>`;
  }).join('');
  const prossima = a.giornate.find((g) => g.day >= oggi);
  const riassunto = prossima
    ? `Prossima: <b>${+prossima.day.slice(8, 10)} ${MESI[+prossima.day.slice(5, 7)].toLowerCase()}</b> · ${e(prossima.label || TIPI[prossima.kind]?.nome)}`
    : '';
  const toggle = `var b=this.closest('.fe-avviso');b.classList.toggle('is-compatto');try{localStorage.setItem('cora-feste-${a.id}',b.classList.contains('is-compatto')?'1':'0')}catch(x){}`;
  return `${natale ? SVG_DEFS + neve() : ''}
<section class="fe-avviso ${natale ? 'is-natale' : 'is-semplice'} ${compatto(a.id) ? 'is-compatto' : ''}">
  ${natale ? '<div class="fe-fondoneve"></div>' : ''}
  <div class="fe-barra">
    <span class="fe-kicker">${natale ? '✦ ' : ''}${e(a.title)}</span>
    <span class="fe-riassunto">${riassunto}</span>
    <button type="button" class="fe-toggle" onclick="${toggle}"><span class="fe-mostra">Mostra</span><span class="fe-nascondi">Riduci</span></button>
  </div>
  <div class="fe-corpo">
    <div class="fe-testa">
      <p class="fe-msg">${e(a.message)}</p>
      ${a.resume_note ? `<p class="fe-ripresa">${e(a.resume_note)}</p>` : ''}
      ${a.signature ? `<p class="fe-firma">${e(a.signature)}</p>` : ''}
    </div>
    ${natale ? SCENA : ''}
    <div class="fe-elenco">${card}</div>
  </div>
</section>`;
}

/** Arte della colonna per i giorni di festa nel periodo natalizio. */
export function arteFesta(giorno: string, nome: string): string {
  const md = giorno.slice(5);
  const arte = md === '12-25'
    ? '<svg class="fe-arte-a" viewBox="0 0 110 62"><use href="#fe-sleigh"/></svg><svg class="fe-arte-b" viewBox="0 0 84 120"><use href="#fe-tree"/></svg>'
    : md === '12-26'
      ? '<svg class="fe-arte-a" viewBox="0 0 58 42"><use href="#fe-rudolph"/></svg><svg class="fe-arte-c" viewBox="0 0 34 32"><use href="#fe-gift"/></svg>'
      : md === '12-31' || md === '01-01'
        ? '<svg class="fe-arte-b" viewBox="0 0 58 74"><use href="#fe-snowman"/></svg>'
        : '<svg class="fe-arte-b" viewBox="0 0 84 120"><use href="#fe-tree"/></svg>';
  return `<div class="fe-arte" aria-hidden="true">${arte}<b>${e(nome)}</b></div>`;
}
