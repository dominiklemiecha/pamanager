import { ReactNode, useCallback, useEffect, useState } from 'react';
import { useLocation } from 'react-router-dom';
import { iniziali } from '../../cora/formato';
import { CorniceHr, HrShell, HrTopbar, useCorniceHr } from '../../cora/HrShell';
import { svgCora } from '../../cora/svg';
import { api } from '../../services/api';
import { PaginaStaff } from '../../staff/PaginaStaff';

/** Pezzi comuni delle pagine Cora dell'HR. */
export const S = ({ n, t }: { n: string; t?: boolean }) => <span style={{ display: 'contents' }} dangerouslySetInnerHTML={{ __html: svgCora(n, t) }} />;
export const toast = (titolo: string, testo = '', tipo = '') => (window as any).Cora?.toast(titolo, testo, tipo);

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

/** Dati di una pagina: carica, ricarica dopo un'azione. */
export function useDati<T>(percorso: string): [T | null, () => Promise<void>] {
  const [d, setD] = useState<T | null>(null);
  const carica = useCallback(async () => { const r = await api.get(percorso); setD(r.data); }, [percorso]);
  useEffect(() => { carica().catch(() => undefined); }, [carica]);
  return [d, carica];
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
  schede?: Array<[string, string]>; attiva?: string; azioni?: ReactNode; children: ReactNode;
}) {
  return (
    <HrShell pagina={pagina} titolo={titolo} cornice={cornice}>
      <div className="cora-reveal" style={{ '--i': 0 } as React.CSSProperties}>
        <HrTopbar titolo={titolo} sottotitolo={sottotitolo} cornice={cornice} />
      </div>
      {(schede || azioni) && (
        <div className="hr-sottomenu cora-reveal" style={{ '--i': 1 } as React.CSSProperties}>
          {schede && (
            <nav className="hr-schede" aria-label={titolo}>
              {schede.map(([etichetta, href]) => (
                <a key={href} href={href} className={'cora-tab' + (attiva === etichetta ? ' is-active' : '')} aria-current={attiva === etichetta ? 'page' : undefined}>{etichetta}</a>
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

/** Menu "..." di una riga: voci che portano a pagine o azioni esistenti. */
export function MenuRiga({ voci }: { voci: Array<{ etichetta: string; href?: string; azione?: () => void; pericolo?: boolean }> }) {
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
            ? <a key={v.etichetta} role="menuitem" href={v.href} className={v.pericolo ? 'is-pericolo' : ''}>{v.etichetta}</a>
            : <button key={v.etichetta} type="button" role="menuitem" className={v.pericolo ? 'is-pericolo' : ''} onClick={v.azione}>{v.etichetta}</button>)}
        </div>
      )}
    </div>
  );
}

export const SCHEDE_DIPENDENTI: Array<[string, string]> = [
  ['Anagrafica', '/admin/employees'], ['Assunzioni', '/admin/hire-requests'], ['Presenze', '/admin/attendance'], ['Reparti', '/admin/departments'],
];
