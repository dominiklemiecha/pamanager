import { useEffect, useRef, useState, type ReactNode } from 'react';

/*
 * Selettori nello stile della piattaforma (stesse classi di cora-pickers.js:
 * .cora-picker, .cora-pop, .cora-cal, .cora-times) per le pagine React:
 * data, orario e tendina, al posto di quelli nativi del browser.
 */

const MESI = ['Gennaio', 'Febbraio', 'Marzo', 'Aprile', 'Maggio', 'Giugno', 'Luglio', 'Agosto', 'Settembre', 'Ottobre', 'Novembre', 'Dicembre'];
const GIORNI = ['L', 'M', 'M', 'G', 'V', 'S', 'D'];
const pad = (n: number) => String(n).padStart(2, '0');
const ymd = (d: Date) => `${d.getFullYear()}-${pad(d.getMonth() + 1)}-${pad(d.getDate())}`;
const daYmd = (v: string) => { const m = /^(\d{4})-(\d{2})-(\d{2})$/.exec(v || ''); return m ? new Date(+m[1], +m[2] - 1, +m[3]) : null; };
const inItaliano = (v: string) => { const d = daYmd(v); return d ? `${pad(d.getDate())}/${pad(d.getMonth() + 1)}/${d.getFullYear()}` : ''; };
/** "23/10/2026", "23-10-26", "23.10" (anno corrente) -> aaaa-mm-gg */
function daItaliano(t: string): string | null {
  const m = /^\s*(\d{1,2})[/.\-\s](\d{1,2})(?:[/.\-\s](\d{2,4}))?\s*$/.exec(t || '');
  if (!m) return null;
  let y = m[3] ? +m[3] : new Date().getFullYear();
  if (y < 100) y += 2000;
  const d = new Date(y, +m[2] - 1, +m[1]);
  return d.getMonth() === +m[2] - 1 && d.getDate() === +m[1] ? ymd(d) : null;
}
/** "9", "930", "9.30", "14:15" -> hh:mm */
function daOra(t: string): string | null {
  const s = (t || '').trim().replace(/[.,]/g, ':');
  const m = /^(\d{1,2})(?::?(\d{2}))?$/.exec(s);
  if (!m) return null;
  const h = +m[1]; const mi = m[2] ? +m[2] : 0;
  return h < 24 && mi < 60 ? `${pad(h)}:${pad(mi)}` : null;
}

const ICONA_CAL = <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="1.8" strokeLinecap="round"><rect x="3" y="5" width="18" height="16" rx="3" /><path d="M3 10h18M8 3v4M16 3v4" /></svg>;
const ICONA_ORA = <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="1.8" strokeLinecap="round"><circle cx="12" cy="12" r="9" /><path d="M12 7v5l3 2" /></svg>;
const ICONA_GIU = <svg viewBox="0 0 16 16" fill="none"><path d="M4 6l4 4 4-4" stroke="currentColor" strokeWidth="1.6" strokeLinecap="round" strokeLinejoin="round" /></svg>;
const POP: React.CSSProperties = { position: 'absolute', top: 'calc(100% + 6px)', left: 0 };

/** Chiude il pannello con un clic fuori o con Esc. */
function useFuori(aperto: boolean, chiudi: () => void) {
  const ref = useRef<HTMLSpanElement>(null);
  useEffect(() => {
    if (!aperto) return;
    const giu = (e: MouseEvent) => { if (ref.current && !ref.current.contains(e.target as Node)) chiudi(); };
    const tasto = (e: KeyboardEvent) => { if (e.key === 'Escape') chiudi(); };
    document.addEventListener('mousedown', giu);
    document.addEventListener('keydown', tasto);
    return () => { document.removeEventListener('mousedown', giu); document.removeEventListener('keydown', tasto); };
  }, [aperto, chiudi]);
  return ref;
}

/** Data (valore aaaa-mm-gg). `minimo`: giorni precedenti non selezionabili. */
export function DataCora({ valore, cambia, minimo, nome, etichetta, classe = '' }: {
  valore: string; cambia: (v: string) => void; minimo?: string; nome?: string; etichetta?: string; classe?: string;
}) {
  const [aperto, setAperto] = useState(false);
  const [testo, setTesto] = useState(inItaliano(valore));
  const [vista, setVista] = useState(() => { const d = daYmd(valore) ?? new Date(); return new Date(d.getFullYear(), d.getMonth(), 1); });
  const [errata, setErrata] = useState(false);
  const ref = useFuori(aperto, () => setAperto(false));
  useEffect(() => { setTesto(inItaliano(valore)); setErrata(false); }, [valore]);
  const apri = () => {
    const d = daYmd(valore) ?? daYmd(minimo ?? '') ?? new Date();
    setVista(new Date(d.getFullYear(), d.getMonth(), 1));
    setAperto(true);
  };
  const scegli = (v: string) => { cambia(v); setAperto(false); };
  const sel = daYmd(valore); const min = daYmd(minimo ?? '');
  const oggi = new Date(); oggi.setHours(0, 0, 0, 0);
  const vuoti = (vista.getDay() + 6) % 7;
  const giorni = new Date(vista.getFullYear(), vista.getMonth() + 1, 0).getDate();
  const mese = (n: number) => setVista(new Date(vista.getFullYear(), vista.getMonth() + n, 1));
  return (
    <span ref={ref} className={`cora-picker${aperto ? ' is-open' : ''}${errata ? ' is-invalid' : ''} ${classe}`}>
      <input className="cora-input" type="text" inputMode="numeric" autoComplete="off" placeholder="gg/mm/aaaa" aria-label={etichetta}
        value={testo} onFocus={apri} onChange={(e) => setTesto(e.target.value)}
        onBlur={() => {
          if (testo.trim() === '') { setErrata(false); if (valore) cambia(''); return; }
          const v = daItaliano(testo);
          if (v) { setErrata(false); if (v !== valore) cambia(v); } else setErrata(true);
        }} />
      {nome && <input type="hidden" name={nome} value={valore} />}
      <span className="cora-field-ic" onMouseDown={(e) => { e.preventDefault(); if (aperto) setAperto(false); else apri(); }}>{ICONA_CAL}</span>
      {aperto && (
        <div className="cora-pop cora-cal" style={POP} onMouseDown={(e) => e.preventDefault()}>
          <div className="cora-cal-h">
            <button type="button" className="cora-cal-nav" aria-label="Mese precedente" onClick={() => mese(-1)}><svg viewBox="0 0 16 16" width="16" height="16" fill="none"><path d="M10 12.5L5.5 8 10 3.5" stroke="currentColor" strokeWidth="1.5" strokeLinecap="round" strokeLinejoin="round" /></svg></button>
            <strong>{MESI[vista.getMonth()]} {vista.getFullYear()}</strong>
            <button type="button" className="cora-cal-nav" aria-label="Mese successivo" onClick={() => mese(1)}><svg viewBox="0 0 16 16" width="16" height="16" fill="none"><path d="M6 3.5L10.5 8 6 12.5" stroke="currentColor" strokeWidth="1.5" strokeLinecap="round" strokeLinejoin="round" /></svg></button>
          </div>
          <div className="cora-cal-grid">
            {GIORNI.map((g, i) => <span key={i} className="cora-cal-dow">{g}</span>)}
            {Array.from({ length: vuoti }, (_, i) => <span key={`v${i}`} />)}
            {Array.from({ length: giorni }, (_, i) => {
              const d = new Date(vista.getFullYear(), vista.getMonth(), i + 1);
              const c = ['cora-cal-day'];
              if (d.getDay() === 0 || d.getDay() === 6) c.push('is-off');
              if (+d === +oggi) c.push('is-today');
              if (sel && +d === +sel) c.push('is-selected');
              return <button key={i} type="button" className={c.join(' ')} disabled={!!min && d < min} onClick={() => scegli(ymd(d))}>{i + 1}</button>;
            })}
          </div>
          <div className="cora-cal-f">
            {valore && <button type="button" className="cora-cal-link" onClick={() => scegli('')}>Cancella</button>}
            <button type="button" className="cora-cal-link" onClick={() => scegli(ymd(oggi))}>Oggi</button>
          </div>
        </div>
      )}
    </span>
  );
}

const ORARI = Array.from({ length: 48 }, (_, i) => `${pad(Math.floor(i / 2))}:${i % 2 ? '30' : '00'}`);

/** Orario hh:mm (scrivibile, o dall'elenco ogni 30 minuti). */
export function OraCora({ valore, cambia, nome, etichetta }: { valore: string; cambia: (v: string) => void; nome?: string; etichetta?: string }) {
  const [aperto, setAperto] = useState(false);
  const [testo, setTesto] = useState(valore);
  const [errata, setErrata] = useState(false);
  const ref = useFuori(aperto, () => setAperto(false));
  const lista = useRef<HTMLDivElement>(null);
  useEffect(() => { setTesto(valore); setErrata(false); }, [valore]);
  useEffect(() => {
    if (!aperto || !lista.current) return;
    const s = lista.current.querySelector<HTMLElement>('.is-selected') ?? lista.current.children[18] as HTMLElement | undefined;
    if (s) lista.current.scrollTop = s.offsetTop - 80;
  }, [aperto]);
  return (
    <span ref={ref} className={`cora-picker${aperto ? ' is-open' : ''}${errata ? ' is-invalid' : ''}`}>
      <input className="cora-input" type="text" inputMode="numeric" autoComplete="off" maxLength={5} placeholder="hh:mm" aria-label={etichetta}
        value={testo} onFocus={() => setAperto(true)} onChange={(e) => setTesto(e.target.value)}
        onBlur={() => {
          if (testo.trim() === '') { setErrata(false); if (valore) cambia(''); return; }
          const v = daOra(testo);
          if (v) { setErrata(false); setTesto(v); if (v !== valore) cambia(v); } else setErrata(true);
        }} />
      {nome && <input type="hidden" name={nome} value={valore} />}
      <span className="cora-field-ic" onMouseDown={(e) => { e.preventDefault(); setAperto(!aperto); }}>{ICONA_ORA}</span>
      {aperto && (
        <div ref={lista} className="cora-pop cora-times" style={POP} onMouseDown={(e) => e.preventDefault()}>
          {ORARI.map((o) => <button key={o} type="button" className={o === valore ? 'is-selected' : ''} onClick={() => { cambia(o); setAperto(false); }}>{o}</button>)}
        </div>
      )}
    </span>
  );
}

/** Tendina: elenco di scelte nello stile Cora. */
export function TendinaCora({ valore, cambia, opzioni, nome, etichetta, vuota }: {
  valore: string; cambia: (v: string) => void; opzioni: Array<{ valore: string; testo: ReactNode }>;
  nome?: string; etichetta?: string; vuota?: string;
}) {
  const [aperto, setAperto] = useState(false);
  const ref = useFuori(aperto, () => setAperto(false));
  const scelta = opzioni.find((o) => o.valore === valore);
  return (
    <span ref={ref} className={`cora-picker cora-tendina${aperto ? ' is-open' : ''}`}>
      <button type="button" className="cora-input cora-tendina-btn" aria-label={etichetta} aria-haspopup="listbox" aria-expanded={aperto}
        onClick={() => setAperto(!aperto)}
        onKeyDown={(e) => {
          if (e.key !== 'ArrowDown' && e.key !== 'ArrowUp') return;
          e.preventDefault();
          const i = opzioni.findIndex((o) => o.valore === valore);
          const n = opzioni[Math.min(opzioni.length - 1, Math.max(0, i + (e.key === 'ArrowDown' ? 1 : -1)))];
          if (n) cambia(n.valore);
        }}>
        <span className={scelta ? '' : 'cora-tendina-vuota'}>{scelta ? scelta.testo : (vuota ?? 'Seleziona')}</span>
      </button>
      {nome && <input type="hidden" name={nome} value={valore} />}
      <span className="cora-field-ic" style={{ pointerEvents: 'none' }}>{ICONA_GIU}</span>
      {aperto && (
        <div className="cora-pop cora-times cora-tendina-pop" role="listbox" style={{ ...POP, right: 0 }}>
          {opzioni.map((o) => (
            <button key={o.valore} type="button" role="option" aria-selected={o.valore === valore} className={o.valore === valore ? 'is-selected' : ''}
              onClick={() => { cambia(o.valore); setAperto(false); }}>{o.testo}</button>
          ))}
        </div>
      )}
    </span>
  );
}
