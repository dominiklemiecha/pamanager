import { useMemo, useState } from 'react';
import { nome } from '../../cora/formato';
import type { CorniceHr } from '../../cora/HrShell';
import { svgCora } from '../../cora/svg';
import { api } from '../../services/api';
import { S, SezioneHr, toast, useDati, Volto } from '../hr/comune';

const BASE = '/consulente-lavoro';

/** Schede di Dipendenti (Figma 981:9942 / 981:10204); il numero e' quello delle assunzioni da fare. */
export const schedeDipendenti = (daFare: number): Array<[string, string, number?]> => [
  ['Anagrafica', `${BASE}/employees`],
  ['Assunzioni', `${BASE}/hire-requests`, daFare],
];

/** "18/06/1996" */
const data = (ymd: string | null) => (ymd ? `${ymd.slice(8, 10)}/${ymd.slice(5, 7)}/${ymd.slice(0, 4)}` : '—');
const euro = (v: number | null) => (v === null ? '—' : v.toLocaleString('it-IT', { style: 'currency', currency: 'EUR' }));
const livello = (l: string) => (!l ? '—' : /^\d+$/.test(l) ? `${l}° livello` : l);

async function scrivi(percorso: string) {
  try {
    const r = await api.post(percorso);
    location.href = `${BASE}/chat?conv=${r.data.conversazione}`;
  } catch (e: any) {
    toast('Chat non disponibile', e?.response?.data?.message ?? '', 'error');
  }
}

/* ===================== Anagrafica (Figma 981:9942) ===================== */

interface Dipendente {
  id: number; nome: string; cognome: string; foto: string; cf: string; nascita: string | null; indirizzo: string; email: string; telefono: string;
  assunzione: string | null; livello: string; posizione: string; reparto: string; ral: number | null; mensile: number | null; iban: string;
}

export function AnagraficaConsulente({ cornice }: { cornice: CorniceHr }) {
  const [d] = useDati<{ dipendenti: Dipendente[] }>('/consulente/dipendenti');
  const [cerca, setCerca] = useState('');
  const [aperto, setAperto] = useState<number | null>(null);
  const righe = useMemo(() => {
    const q = cerca.trim().toLowerCase();
    return (d?.dipendenti ?? []).filter((x) => !q || [x.nome, x.cognome, `${x.nome} ${x.cognome}`, `${x.cognome} ${x.nome}`, x.cf, x.email].some((v) => v.toLowerCase().includes(q)));
  }, [d, cerca]);
  if (!d) return null;
  const n = d.dipendenti.length;
  return (
    <SezioneHr cornice={cornice} pagina="dipendenti" titolo="Dipendenti"
      sottotitolo={`${n} dipendent${n === 1 ? 'e' : 'i'} attiv${n === 1 ? 'o' : 'i'} di ${cornice.azienda?.name ?? ''} - dati sincronizzati dall’HR`}
      schede={schedeDipendenti(cornice.contatori.assunzioni)} attiva="Anagrafica"
      azioni={<label className="cora-search cs-cerca"><S n="ic-search.svg" /><input type="search" placeholder="Nome, codice fiscale, email…" value={cerca} onChange={(e) => setCerca(e.target.value)} /></label>}>
      <p className="cs-sola-lettura"><S n="ic-lock-sm.svg" />Dati in sola lettura: anagrafica e contratto sono gestiti dall’HR. Per una correzione scrivi all’HR dalla chat.</p>
      <div className="cs-elenco">
        {!righe.length && <div className="cora-card hr-vuoto">Nessun dipendente trovato</div>}
        {righe.map((x) => {
          const su = aperto === x.id;
          const dettagli = [x.posizione, x.reparto, x.cf].filter(Boolean).join(' - ');
          return (
            <div key={x.id} className={'cs-accordion' + (su ? ' is-open' : '')}>
              <button type="button" className="cs-accordion-h" aria-expanded={su} onClick={() => setAperto(su ? null : x.id)}>
                <Volto p={x} classe="cs-iniziali" />
                <span className="cs-accordion-txt">
                  <span><strong>{nome(x.cognome)} {nome(x.nome)}</strong><span className="cs-attivo"><i />Attivo</span></span>
                  <span>{dettagli || '—'}</span>
                </span>
                <span className="cs-freccia"><S n="ic-chev-d.svg" /></span>
              </button>
              {su && (
                <>
                  <div className="cs-dettaglio">
                    <Gruppo titolo="Dati anagrafici" voci={[['Codice fiscale', x.cf || '—'], ['Nascita', data(x.nascita)], ['Indirizzo', x.indirizzo || '—'],
                      ['Email', x.email ? <a href={`mailto:${x.email}`}>{x.email}</a> : '—'], ['Telefono', x.telefono || '—']]} />
                    <Gruppo titolo="Contratto" voci={[['Assunzione', data(x.assunzione)], ['Livello CCNL', livello(x.livello)], ['Posizione', x.posizione || '—'], ['Reparto', x.reparto || '—']]} />
                    <Gruppo titolo="Dati economici" voci={[['RAL annua', euro(x.ral)], ['Mensile lordo', euro(x.mensile)], ['IBAN', x.iban || '—']]} />
                  </div>
                  <div className="cs-azioni">
                    <button type="button" className="cs-pillola" onClick={() => scrivi(`/consulente/chat/${x.id}`)}><S n="ic-chat.svg" />Scrivi al dipendente</button>
                    <a className="cs-pillola" href={`${BASE}/documents?employee_id=${x.id}`}><S n="ic-file.svg" />Documenti del dipendente</a>
                  </div>
                </>
              )}
            </div>
          );
        })}
      </div>
    </SezioneHr>
  );
}

function Gruppo({ titolo, voci }: { titolo: string; voci: Array<[string, React.ReactNode]> }) {
  return (
    <div className="cs-gruppo">
      <span className="cs-gruppo-t">{titolo}</span>
      {voci.map(([k, v]) => <div key={k} className="cs-voce"><span>{k}</span><b>{v}</b></div>)}
    </div>
  );
}

/* ===================== Assunzioni (Figma 981:10204) ===================== */

interface Assunzione {
  id: number; stato: string; nome: string; cognome: string; ruolo: string; contratto: string; inizio: string | null; fine: string | null;
  ricevuta: string; prospettoId: number | null; prospettoIl: string; decisa: string; contrattoIl: string;
}
type Fase = { titolo: string; nota: string; stato: 'fatta' | 'attuale' | 'attesa' };

/** "14/10" */
const giornoMese = (iso: string) => (iso ? new Intl.DateTimeFormat('it-IT', { timeZone: 'Europe/Rome', day: '2-digit', month: '2-digit' }).format(new Date(iso)) : '');
const ORDINE = ['awaiting_prospects', 'prospects_review', 'approved', 'contract_pending', 'contract_signed'];

/** Le quattro fasi del Figma, dallo stato della richiesta. */
function fasi(a: Assunzione): Fase[] {
  const i = ORDINE.indexOf(a.stato);
  const st = (n: number): Fase['stato'] => (i > n ? 'fatta' : i === n ? 'attuale' : 'attesa');
  const firma = a.stato === 'contract_signed' ? 'fatta' : ['approved', 'contract_pending'].includes(a.stato) ? 'attuale' : 'attesa';
  return [
    { titolo: 'Richiesta HR', nota: `Ricevuta il ${giornoMese(a.ricevuta)}`, stato: 'fatta' },
    { titolo: 'Prospetto', nota: st(0) === 'fatta' ? (a.prospettoIl ? `Caricato il ${giornoMese(a.prospettoIl)}` : 'Caricato') : st(0) === 'attuale' ? 'Da caricare' : 'In attesa', stato: st(0) },
    { titolo: 'Approvazione HR', nota: st(1) === 'fatta' ? (a.decisa ? `Approvato il ${giornoMese(a.decisa)}` : 'Approvato') : st(1) === 'attuale' ? 'In revisione' : 'In attesa', stato: st(1) },
    { titolo: 'Firma dipendente', nota: firma === 'fatta' ? 'Contratto firmato' : a.stato === 'approved' ? 'Contratto da caricare' : a.stato === 'contract_pending' ? 'In firma' : 'In attesa', stato: firma },
  ];
}

/** Badge di stato: "Da fare" quando tocca al consulente. */
function badge(stato: string): [string, string] {
  switch (stato) {
    case 'awaiting_prospects': return ['is-dafare', 'Da fare: carica il prospetto'];
    case 'approved': return ['is-dafare', 'Da fare: carica il contratto'];
    case 'prospects_review': return ['is-grigio', 'In approvazione HR'];
    case 'contract_pending': return ['is-grigio', 'In firma dal dipendente'];
    case 'contract_signed': return ['is-fatta', 'Completata'];
    case 'direct_hire': return ['is-fatta', 'Assunzione diretta'];
    case 'rejected': return ['is-grigio', 'Rifiutata'];
    case 'cancelled': return ['is-grigio', 'Annullata'];
    default: return ['is-grigio', stato];
  }
}

export function AssunzioniConsulente({ cornice }: { cornice: CorniceHr }) {
  const [d] = useDati<{ assunzioni: Assunzione[] }>('/consulente/assunzioni');
  if (!d) return null;
  return (
    <SezioneHr cornice={cornice} pagina="dipendenti" titolo="Dipendenti" sottotitolo="Prospetti e contratti delle assunzioni avviate dall’HR."
      schede={schedeDipendenti(cornice.contatori.assunzioni)} attiva="Assunzioni">
      <p className="cs-avviso cs-avviso-largo"><S n="ic-info.svg" />Quando carichi il prospetto l’HR riceve una notifica per approvarlo. Dopo l’approvazione carichi il contratto e il dipendente lo firma con OTP da Cora.</p>
      <div className="cs-elenco">
        {!d.assunzioni.length && <div className="cora-card hr-vuoto">Nessuna richiesta di assunzione</div>}
        {d.assunzioni.map((a) => {
          const [classe, testo] = badge(a.stato);
          const dettagli = [a.ruolo, a.contratto, a.inizio ? `inizio ${data(a.inizio)}` : ''].filter(Boolean).join(' - ');
          const dettaglio = `${BASE}/hire-requests?id=${a.id}`;
          return (
            <div key={a.id} className="cora-card cs-assunzione">
              <div className="cs-assunzione-h">
                <Volto p={{ id: a.id, nome: a.nome, cognome: a.cognome, foto: '' }} classe="cs-iniziali" />
                <span className="cs-accordion-txt"><span><strong>{nome(a.nome)} {nome(a.cognome)}</strong></span><span>{dettagli || '—'}</span></span>
                <span className={`cs-stato ${classe}`}><i />{testo}</span>
              </div>
              {ORDINE.includes(a.stato) && (
                <ol className="cs-fasi">
                  {fasi(a).map((f) => (
                    <li key={f.titolo} className={`is-${f.stato}`}>
                      <strong>{f.stato === 'fatta' && <S n="ic-check.svg" />}{f.titolo}</strong>
                      <span>{f.nota}</span>
                    </li>
                  ))}
                </ol>
              )}
              <div className="cs-azioni">
                {a.stato === 'awaiting_prospects' && (
                  <a className="cora-btn cora-btn-primary cs-pillola-nera" href={dettaglio}>
                    <span className="hr-btn-logo" dangerouslySetInnerHTML={{ __html: svgCora('logo-cora-white-20.svg') }} />Carica prospetto
                  </a>
                )}
                {a.stato === 'approved' && (
                  <a className="cora-btn cora-btn-primary cs-pillola-nera" href={dettaglio}>
                    <span className="hr-btn-logo" dangerouslySetInnerHTML={{ __html: svgCora('logo-cora-white-20.svg') }} />Carica contratto
                  </a>
                )}
                {a.prospettoId
                  ? <a className="cs-pillola" href={`${BASE}/hire-requests?action=file&id=${a.id}&file_id=${a.prospettoId}`} target="_blank" rel="noopener"><S n="ic-eye.svg" />Vedi prospetto</a>
                  : <a className="cs-pillola" href={dettaglio}><S n="ic-eye.svg" />Vedi richiesta HR</a>}
                <button type="button" className="cs-pillola" onClick={() => scrivi('/consulente/chat-hr')}><S n="ic-chat.svg" />Scrivi all’HR</button>
              </div>
            </div>
          );
        })}
      </div>
    </SezioneHr>
  );
}
