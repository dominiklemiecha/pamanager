import { useEffect, useState } from 'react';
import { datoVisto, salvaDato, scaricaDato } from '../../cora/datiHr';
import { fa, nome } from '../../cora/formato';
import { CorniceHr, HrShell, HrTopbar } from '../../cora/HrShell';
import { svgCora } from '../../cora/svg';
import { toastCora } from '../../cora/toast';
import { api } from '../../services/api';
import { Compleanni, ora, periodo, Volto } from '../hr/Home';

interface Persona { id: number; nome: string; cognome: string; foto: string }
interface DatiHome {
  nome: string; oggi: string; mese: number; anno: number;
  buste: { caricate: number; totale: number };
  caricati: number; approvate: number; attivi: number;
  inAttesa: number;
  richieste: Array<Persona & { tipo: string; etichetta: string; da: string; a: string; giornata: boolean; oraDa: string | null; oraA: string | null; giorni: number }>;
  documenti: Array<{ id: number; tipo: string; etichetta: string; titolo: string; mese: number | null; anno: number | null; caricato: string; nome: string; cognome: string; foto: string }>;
  inviti: Array<{ id: number; titolo: string; inizio: string; giornata: boolean }>;
  festeggiati: Persona[];
}

const S = ({ n }: { n: string }) => <span style={{ display: 'contents' }} dangerouslySetInnerHTML={{ __html: svgCora(n) }} />;
const MESI = ['', 'gennaio', 'febbraio', 'marzo', 'aprile', 'maggio', 'giugno', 'luglio', 'agosto', 'settembre', 'ottobre', 'novembre', 'dicembre'];
const GIORNI = ['Domenica', 'Lunedì', 'Martedì', 'Mercoledì', 'Giovedì', 'Venerdì', 'Sabato'];
const R = 78;
const C = 2 * Math.PI * R;
const maiuscola = (s: string) => s.charAt(0).toUpperCase() + s.slice(1);
const toast = toastCora;
const BASE = '/consulente-lavoro';

/** "Venerdì 16 ottobre 2026" */
function giornoEsteso(ymd: string) {
  const d = new Date(`${ymd}T12:00:00Z`);
  return `${GIORNI[d.getUTCDay()]} ${d.getUTCDate()} ${MESI[d.getUTCMonth() + 1]} ${d.getUTCFullYear()}`;
}
/** "16/10 · 10:31" */
function caricato(isoData: string) {
  const p = Object.fromEntries(new Intl.DateTimeFormat('it-IT', { timeZone: 'Europe/Rome', day: '2-digit', month: '2-digit', hour: '2-digit', minute: '2-digit', hourCycle: 'h23' })
    .formatToParts(new Date(isoData)).map((x) => [x.type, x.value]));
  return `${p.day}/${p.month} · ${p.hour}:${p.minute}`;
}
/** "lun 20 ott, 10:00" */
function quando(isoData: string, giornata: boolean) {
  const f = new Intl.DateTimeFormat('it-IT', { timeZone: 'Europe/Rome', weekday: 'short', day: 'numeric', month: 'short', ...(giornata ? {} : { hour: '2-digit', minute: '2-digit', hourCycle: 'h23' }) });
  return f.format(new Date(isoData)).replace(/\./g, '');
}

/** Chat diretta aperta dalla dashboard ("Contatta HR", "Fai gli auguri"). */
async function apriChat(percorso: string) {
  try {
    const r = await api.post(percorso);
    location.href = `${BASE}/chat?conv=${r.data.conversazione}`;
  } catch (e: any) {
    toast('Chat non disponibile', e?.response?.data?.message ?? '', 'error');
  }
}

/** Dashboard del consulente del lavoro (Figma "Dashboard Consulente lavoro", 744:973). */
export default function HomeConsulente({ cornice }: { cornice: CorniceHr }) {
  const [d, setDati] = useState<DatiHome | null>(() => datoVisto<DatiHome>('/consulente/home'));
  useEffect(() => { scaricaDato<DatiHome>('/consulente/home').then(setDati).catch(() => undefined); }, []);
  if (!d) return null;
  const meseNome = MESI[d.mese];
  return (
    <HrShell pagina="index" titolo="Home" cornice={cornice}>
      <div className="cora-reveal" style={{ '--i': 0 } as React.CSSProperties}>
        <HrTopbar titolo={`${ora()}, ${d.nome}`} sottotitolo={`${giornoEsteso(d.oggi)} · Area Consulente del Lavoro`}
          centro={<Compleanni persone={d.festeggiati} auguri={(p) => apriChat(`/consulente/chat/${p.id}`)} />} cornice={cornice} />
      </div>
      <section className="cora-home-row hr-oggi cora-reveal" style={{ '--i': 1 } as React.CSSProperties}>
        <BustePaga d={d} meseNome={meseNome} />
        <InAttesa d={d} />
      </section>
      <section className="cora-home-row cora-home-actions cora-reveal" style={{ '--i': 2 } as React.CSSProperties}>
        <a className="cora-card cora-quick" href={`${BASE}/documents`}>
          <span className="cora-quick-txt"><strong>Carica documenti</strong><span>Buste paga, CU e altri documenti</span></span>
          <span className="cora-quick-logo" dangerouslySetInnerHTML={{ __html: svgCora('logo-cora.svg') }} />
        </a>
        <a className="cora-card cora-quick" href={`${BASE}/employees`}>
          <span className="cora-quick-txt"><strong>Anagrafica</strong><span>{d.attivi} dipendent{d.attivi === 1 ? 'e' : 'i'} di {cornice.azienda?.name ?? ''}</span></span>
          <span className="cora-quick-logo" dangerouslySetInnerHTML={{ __html: svgCora('logo-cora.svg') }} />
        </a>
        <a className="cora-card cora-quick" href={`${BASE}/presenze-export`}>
          <span className="cora-quick-txt"><strong>Export presenze</strong><span>{maiuscola(meseNome)} {d.anno} · pronto da scaricare</span></span>
          <span className="cora-quick-logo" dangerouslySetInnerHTML={{ __html: svgCora('logo-cora.svg') }} />
        </a>
      </section>
      <section className="cora-home-row cs-riga-documenti cora-reveal" style={{ '--i': 3 } as React.CSSProperties}>
        <UltimiDocumenti d={d} />
        <Notifiche d={d} cornice={cornice} aggiorna={(inviti) => { const x = { ...d, inviti }; salvaDato('/consulente/home', x); setDati(x); }} />
      </section>
    </HrShell>
  );
}

/** Buste paga del mese caricate sui dipendenti attivi, con i conteggi della dashboard PHP. */
function BustePaga({ d, meseNome }: { d: DatiHome; meseNome: string }) {
  const { caricate, totale } = d.buste;
  const arco = totale > 0 ? (Math.min(caricate, totale) / totale) * C : 0;
  return (
    <div className="cora-card hr-presenze cs-buste">
      <div className="cora-card-h"><h2>Buste paga di {meseNome}</h2></div>
      <div className="cora-saldo-body">
        <div className="cora-donut">
          <svg viewBox="0 0 176 176" width="176" height="176" aria-hidden="true">
            <circle cx="88" cy="88" r={R} fill="none" stroke="var(--cora-accent-soft)" strokeWidth="20" />
            <circle className="cora-donut-arc" cx="88" cy="88" r={R} fill="none" stroke="var(--cora-accent)" strokeWidth="20"
              strokeDasharray={`${arco.toFixed(2)} ${C.toFixed(2)}`} style={{ '--arc': arco.toFixed(2) } as React.CSSProperties} transform="rotate(-90 88 88)" />
          </svg>
          <div className="cora-donut-val hr-donut-val">
            <strong>{caricate}<small>/{totale}</small></strong>
            <span>caricate</span>
          </div>
        </div>
        <div className="cora-saldo-rows">
          <div className="cora-saldo-row"><i className="dot is-used" /><span>Documenti caricati (storico)</span><b>{d.caricati}</b></div>
          <div className="cora-saldo-row"><i className="dot is-resid" /><span>Ferie e permessi approvati {d.anno}</span><b>{d.approvate}</b></div>
          <div className="cora-saldo-row"><i className="dot is-total" /><span>Dipendenti attivi in anagrafica</span><b>{d.attivi}</b></div>
        </div>
      </div>
    </div>
  );
}

/** Richieste in attesa dell'HR: il consulente le vede ma non le decide. */
function InAttesa({ d }: { d: DatiHome }) {
  return (
    <div className="cora-card hr-richieste cs-attesa">
      <div className="cora-card-h">
        <h2>In attesa di approvazione</h2>
        {d.inAttesa > 0 && <span className="hr-chip">{d.inAttesa} richiest{d.inAttesa === 1 ? 'a' : 'e'}</span>}
        <a href={`${BASE}/leave-requests`} className="cora-see-all">Vedi tutte <S n="ic-arr-r.svg" /></a>
      </div>
      <p className="cs-avviso"><S n="ic-info.svg" />Non incluse negli export finché l’HR non le approva o rifiuta</p>
      {!d.richieste.length ? (
        <div className="cora-list-empty">
          <strong>Nessuna richiesta in attesa</strong>
          <span>Ferie e permessi ancora da approvare compariranno qui</span>
        </div>
      ) : (
        <div className="hr-richieste-righe">
          {d.richieste.map((r) => (
            <div key={r.id} className="hr-richiesta">
              <Volto p={r} />
              <div className="hr-richiesta-txt">
                <div><strong>{nome(r.nome)} {nome(r.cognome)}</strong><span className={`hr-tipo is-${r.tipo}`}>{r.etichetta}</span></div>
                <span>{periodo(r)}</span>
              </div>
              <span className="cs-stato-attesa"><i />In attesa HR</span>
            </div>
          ))}
        </div>
      )}
      <button type="button" className="cora-btn cora-btn-primary cs-contatta" onClick={() => apriChat('/consulente/chat-hr')}>
        <S n="ic-messaggio.svg" />Contatta Hr
      </button>
    </div>
  );
}

function UltimiDocumenti({ d }: { d: DatiHome }) {
  return (
    <div className="cora-card cs-documenti">
      <div className="cora-card-h">
        <h2>Ultimi documenti caricati</h2>
        <a href={`${BASE}/documents`} className="cora-see-all">Vedi tutti <S n="ic-arr-r.svg" /></a>
      </div>
      {!d.documenti.length ? (
        <div className="cora-list-empty"><strong>Nessun documento caricato</strong><span>Buste paga e CU caricati compariranno qui</span></div>
      ) : (
        <div className="cs-tabella" role="table" aria-label="Ultimi documenti caricati">
          <div className="cs-riga cs-intestazione" role="row">
            <span role="columnheader">Dipendente</span><span role="columnheader">Tipo</span><span role="columnheader">Titolo</span>
            <span role="columnheader">Periodo</span><span role="columnheader">Caricato</span><span />
          </div>
          {d.documenti.map((x) => (
            <div key={x.id} className="cs-riga" role="row">
              <span className="cs-persona" role="cell"><Volto p={{ id: 0, nome: x.nome, cognome: x.cognome, foto: x.foto }} />{nome(x.nome)} {nome(x.cognome)}</span>
              <span role="cell"><span className={`cs-tipo is-${x.tipo}`}><S n="ic-file-orange.svg" />{x.etichetta}</span></span>
              <span className="cs-titolo" role="cell">{x.titolo}</span>
              <span className="cs-periodo" role="cell">{x.mese && x.anno ? `${maiuscola(MESI[x.mese])} ${x.anno}` : '—'}</span>
              <span className="cs-quando" role="cell">{caricato(x.caricato)}</span>
              <a className="cs-scarica" href={`/api/download.php?id=${x.id}`} aria-label={`Scarica ${x.titolo}`}><S n="ic-down.svg" /></a>
            </div>
          ))}
        </div>
      )}
    </div>
  );
}

/** Inviti del calendario da confermare e ultime notifiche della campanella. */
function Notifiche({ d, cornice, aggiorna }: { d: DatiHome; cornice: CorniceHr; aggiorna: (inviti: DatiHome['inviti']) => void }) {
  const [lavoro, setLavoro] = useState<number | null>(null);
  const rispondi = async (id: number, stato: 'accepted' | 'declined') => {
    setLavoro(id);
    const fd = new FormData();
    fd.append('action', 'respond_event');
    fd.append('event_id', String(id));
    fd.append('status', stato);
    try {
      const r = await api.post('/calendario/azione', fd);
      if (r.data?.success === false) throw new Error(r.data?.error ?? '');
      aggiorna(d.inviti.filter((x) => x.id !== id));
      toast(stato === 'accepted' ? 'Invito accettato' : 'Invito rifiutato');
    } catch (e: any) {
      toast('Operazione non riuscita', e?.response?.data?.message ?? e?.message ?? '', 'error');
    } finally {
      setLavoro(null);
    }
  };
  const altre = cornice.notifiche.slice(0, Math.max(0, 3 - d.inviti.length));
  const nuove = cornice.notificheNonLette + d.inviti.length;
  return (
    <div className="cora-card cs-notifiche">
      <div className="cora-card-h">
        <h2>Notifiche recenti</h2>
        {nuove > 0 && <span className="cs-nuove"><i />{nuove} nuov{nuove === 1 ? 'a' : 'e'}</span>}
      </div>
      {!d.inviti.length && !altre.length ? (
        <div className="cora-list-empty"><strong>Nessuna notifica</strong><span>Inviti e avvisi compariranno qui</span></div>
      ) : (
        <div className="cs-notifiche-righe">
          {d.inviti.map((x) => (
            <div key={`i${x.id}`} className="cs-notifica">
              <span className="cs-notifica-ic"><S n="ic-cal.svg" /></span>
              <span className="cs-notifica-txt"><strong>Invito da confermare</strong><span>{x.titolo} · {quando(x.inizio, x.giornata)}</span></span>
              <span className="hr-richiesta-azioni">
                <button type="button" className="hr-azione" aria-label="Rifiuta invito" disabled={lavoro === x.id} onClick={() => rispondi(x.id, 'declined')}><S n="ic-rifiuta.svg" /></button>
                <button type="button" className="hr-azione" aria-label="Accetta invito" disabled={lavoro === x.id} onClick={() => rispondi(x.id, 'accepted')}><S n="ic-approva.svg" /></button>
              </span>
            </div>
          ))}
          {altre.map((n) => (
            <a key={`n${n.id}`} className={'cs-notifica' + (n.letta ? '' : ' is-nuova')} href={n.link || undefined}>
              <span className="cs-notifica-ic"><S n="ic-bell.svg" /></span>
              <span className="cs-notifica-txt"><strong>{n.titolo}</strong><span>{n.messaggio ? `${n.messaggio} · ` : ''}{fa(n.creata)}</span></span>
            </a>
          ))}
        </div>
      )}
    </div>
  );
}
