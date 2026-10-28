import { ReactNode, useCallback, useEffect, useRef, useState } from 'react';
import { useLocation } from 'react-router-dom';
import { iniziali } from '../../cora/formato';
import { datoVisto, scaricaDato } from '../../cora/datiHr';
import { CorniceHr, HrShell, HrTopbar, useCorniceHr } from '../../cora/HrShell';
import { svgCora } from '../../cora/svg';
import { toastCora } from '../../cora/toast';
import { api } from '../../services/api';
import { PaginaStaff } from '../../staff/PaginaStaff';

/** Pezzi comuni delle pagine Cora dell'HR. */
export const S = ({ n, t }: { n: string; t?: boolean }) => <span style={{ display: 'contents' }} dangerouslySetInnerHTML={{ __html: svgCora(n, t) }} />;
export const toast = toastCora;

export interface Persona { id: number; nome: string; cognome: string; foto: string }
export function Volto({ p, classe = 'hr-volto' }: { p: Persona; classe?: string }) {
  return <span className={classe}>{p.foto ? <img src={p.foto} alt="" loading="lazy" /> : iniziali(p.nome, p.cognome)}</span>;
}

/**
 * Pagina dell'HR: vista Cora se attiva (HR_CORA_UI) e se l'indirizzo e' quello
 * dell'elenco; altrimenti la pagina classica convertita dal PHP (schede,
 * moduli e azioni che il Figma non ridisegna).
 */
export function SceltaHr({ cora, classica, usaCora = () => true }: {
  cora: (c: CorniceHr) => ReactNode;
  classica: React.ComponentProps<typeof PaginaStaff>;
  usaCora?: (q: URLSearchParams) => boolean;
}) {
  const cornice = useCorniceHr();
  const { search } = useLocation();
  if (!cornice) return null;
  return cornice.coraHr && usaCora(new URLSearchParams(search)) ? <>{cora(cornice)}</> : <PaginaStaff {...classica} />;
}

/**
 * Dati di una pagina: carica, ricarica dopo un'azione. Se la pagina e' gia'
 * stata vista (o precaricata) si disegna subito e intanto si aggiorna.
 */
export function useDati<T>(percorso: string): [T | null, () => Promise<void>] {
  const [d, setD] = useState<T | null>(() => datoVisto<T>(percorso));
  // Dopo un'azione serve una richiesta nuova, non un precaricamento partito prima.
  const ricarica = useCallback(async () => { setD(await scaricaDato<T>(percorso, true)); }, [percorso]);
  useEffect(() => { scaricaDato<T>(percorso).then(setD).catch(() => undefined); }, [percorso]);
  return [d, ricarica];
}

/**
 * Azione su una pagina del gestionale (stesse azioni dei moduli PHP):
 * ritorna null se riuscita (redirect), altrimenti il messaggio d'errore.
 */
export async function azione(pagina: string, campi: Record<string, string | number>): Promise<string | null> {
  const fd = new FormData();
  for (const [k, v] of Object.entries(campi)) fd.append(k, String(v));
  try {
    const r = await api.post(`/pagine/${pagina}`, fd);
    if (r.data?.redirect) return null;
    const errore = r.data?.V?.error;
    return errore ? String(errore) : null;
  } catch (e: any) {
    return e?.response?.data?.message ?? 'Operazione non riuscita';
  }
}

/** Azione su una pagina del gestionale con la risposta completa (dati della pagina o redirect). */
export async function inviaPagina(pagina: string, campi: Record<string, string | number | Blob> | FormData): Promise<{ redirect: string | null; V: any; errore: string }> {
  let fd = campi;
  if (!(campi instanceof FormData)) {
    fd = new FormData();
    for (const [k, v] of Object.entries(campi)) (fd as FormData).append(k, v instanceof Blob ? v : String(v));
  }
  try {
    const r = await api.post(`/pagine/${pagina}`, fd);
    const testo = (html: unknown) => { const d = document.createElement('div'); d.innerHTML = String(html ?? ''); return d.textContent ?? ''; };
    return { redirect: r.data?.redirect ?? null, V: r.data?.V ?? null, errore: testo(r.data?.V?.error) };
  } catch (e: any) {
    return { redirect: null, V: null, errore: e?.response?.data?.message ?? 'Operazione non riuscita' };
  }
}

/** Intestazione delle sezioni (titolo, sottomenu a pillole e azioni a destra). */
export function SezioneHr({ cornice, pagina, titolo, sottotitolo, schede, attiva, azioni, children }: {
  cornice: CorniceHr; pagina: string; titolo: string; sottotitolo: string;
  /** Etichetta, indirizzo e (facoltativo) numero di cose da fare. */
  schede?: Array<[string, string, number?]>; attiva?: string; azioni?: ReactNode; children: ReactNode;
}) {
  // Sul telefono le schede scorrono: quella attiva resta visibile (Figma 1201:6160)
  const nav = useRef<HTMLElement>(null);
  useEffect(() => {
    const n = nav.current;
    const a = n?.querySelector<HTMLElement>('.is-active');
    if (!n || !a || n.scrollWidth <= n.clientWidth) return;
    const r = a.getBoundingClientRect();
    n.scrollLeft += r.left - n.getBoundingClientRect().left - (n.clientWidth - r.width) / 2;
  }, [attiva]);
  return (
    <HrShell pagina={pagina} titolo={titolo} cornice={cornice}>
      <div className="cora-reveal" style={{ '--i': 0 } as React.CSSProperties}>
        <HrTopbar titolo={titolo} sottotitolo={sottotitolo} cornice={cornice} />
      </div>
      {(schede || azioni) && (
        <div className="hr-sottomenu cora-reveal" style={{ '--i': 1 } as React.CSSProperties}>
          {schede && (
            <nav ref={nav} className="hr-schede" aria-label={titolo}>
              {schede.map(([etichetta, href, n]) => (
                <a key={href} href={href} className={'cora-tab' + (attiva === etichetta ? ' is-active' : '')} aria-current={attiva === etichetta ? 'page' : undefined}>
                  {etichetta}{!!n && <span className="hr-scheda-n">{n > 9 ? '9+' : n}</span>}
                </a>
              ))}
            </nav>
          )}
          <span className="hr-spazio" />
          {azioni && <div className="hr-azioni">{azioni}</div>}
        </div>
      )}
      <div className="hr-corpo cora-reveal" style={{ '--i': 2 } as React.CSSProperties}>{children}</div>
    </HrShell>
  );
}

/** Modale Cora controllata da React. */
export function Modale({ aperta, chiudi, titolo, sottotitolo, larga, intestazione, children }: {
  aperta: boolean; chiudi: () => void; titolo: string; sottotitolo?: ReactNode; larga?: boolean; intestazione?: ReactNode; children: ReactNode;
}) {
  const [visibile, setVisibile] = useState(false);
  useEffect(() => {
    if (!aperta) { setVisibile(false); return; }
    const r = requestAnimationFrame(() => setVisibile(true));
    const esc = (e: KeyboardEvent) => { if (e.key === 'Escape') chiudi(); };
    document.addEventListener('keydown', esc);
    return () => { cancelAnimationFrame(r); document.removeEventListener('keydown', esc); };
  }, [aperta, chiudi]);
  if (!aperta) return null;
  return (
    <div className={'cora-modal' + (visibile ? ' is-open' : '')} onMouseDown={(e) => { if (e.target === e.currentTarget) chiudi(); }}>
      <div className={'cora-modal-box' + (larga ? '' : ' cora-modal-sm')} role="dialog" aria-modal="true" aria-label={titolo}>
        <div className="cora-modal-h">
          {intestazione ?? <div><h2>{titolo}</h2>{sottotitolo && <p>{sottotitolo}</p>}</div>}
          <button type="button" className="cora-modal-x" aria-label="Chiudi" onClick={chiudi}><S n="ic-x-modal.svg" /></button>
        </div>
        {children}
      </div>
    </div>
  );
}

/** Menu "..." di una riga: voci che portano a pagine o azioni esistenti (icona e nuova scheda facoltative). */
export function MenuRiga({ voci }: { voci: Array<{ etichetta: string; href?: string; azione?: () => void; pericolo?: boolean; icona?: string; nuova?: boolean }> }) {
  const [aperto, setAperto] = useState(false);
  useEffect(() => {
    if (!aperto) return;
    const chiudi = () => setAperto(false);
    document.addEventListener('click', chiudi);
    return () => document.removeEventListener('click', chiudi);
  }, [aperto]);
  return (
    <div className={'hr-menu' + (aperto ? ' is-open' : '')}>
      <button type="button" className="hr-menu-btn" aria-label="Altre azioni" aria-haspopup="true" aria-expanded={aperto}
        onClick={(e) => { e.stopPropagation(); setAperto(!aperto); }}><S n="ic-more.svg" /></button>
      {aperto && (
        <div className="hr-menu-lista" role="menu">
          {voci.map((v) => v.href
            ? <a key={v.etichetta} role="menuitem" href={v.href} className={v.pericolo ? 'is-pericolo' : ''} {...(v.nuova ? { target: '_blank', rel: 'noopener' } : {})}>{v.icona && <S n={v.icona} />}{v.etichetta}</a>
            : <button key={v.etichetta} type="button" role="menuitem" className={v.pericolo ? 'is-pericolo' : ''} onClick={v.azione}>{v.icona && <S n={v.icona} />}{v.etichetta}</button>)}
        </div>
      )}
    </div>
  );
}

export const SCHEDE_DIPENDENTI: Array<[string, string]> = [
  ['Anagrafica', '/admin/employees'], ['Assunzioni', '/admin/hire-requests'], ['Presenze', '/admin/attendance'], ['Reparti', '/admin/departments'],
];
