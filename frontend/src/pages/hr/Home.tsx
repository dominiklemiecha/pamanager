import { useEffect, useState } from 'react';
import { dataBreve, fa, iniziali, nome } from '../../cora/formato';
import { CorniceHr, HrShell, HrTopbar } from '../../cora/HrShell';
import { svgCora } from '../../cora/svg';
import { api } from '../../services/api';

type Stato = 'disponibile' | 'occupato' | 'ferie' | 'permesso' | 'malattia' | 'assente' | 'smart';
interface Persona { id: number; nome: string; cognome: string; foto: string }
interface Settimana {
  inizio: string; fine: string; prima: string; dopo: string; oggi: string;
  giorni: Array<{ data: string; etichetta: string; lavorativo: boolean; festa: string | null }>;
  persone: Array<Persona & { reparto: string; stati: Array<{ stato: Stato; etichetta: string }> }>;
}
interface DatiHome {
  nome: string;
  oggi: string;
  presenze: { attivi: number; assenti: number; presenti: number };
  richieste: Array<Persona & { id: number; tipo: string; etichetta: string; da: string; a: string; giornata: boolean; oraDa: string | null; oraA: string | null; giorni: number }>;
  inAttesa: number;
  prove: number;
  compleanni: Persona[];
  settimana: Settimana;
  attivita: Array<{ tipo: 'dipendente' | 'documento' | 'comunicazione' | 'richiesta'; titolo: string; meta: string; data: string }>;
}

const S = ({ n, t }: { n: string; t?: boolean }) => <span style={{ display: 'contents' }} dangerouslySetInnerHTML={{ __html: svgCora(n, t) }} />;
const GIORNI_BREVI = ['Dom', 'Lun', 'Mar', 'Mer', 'Gio', 'Ven', 'Sab'];
const MESI = ['', 'gennaio', 'febbraio', 'marzo', 'aprile', 'maggio', 'giugno', 'luglio', 'agosto', 'settembre', 'ottobre', 'novembre', 'dicembre'];
const R = 78;
const C = 2 * Math.PI * R;
const ICONE_ATTIVITA: Record<string, string> = { dipendente: 'ic-uplus.svg', documento: 'ic-file.svg', comunicazione: 'ic-mega.svg', richiesta: 'ic-ferie-permessi.svg' };

const data = (ymd: string) => new Date(`${ymd}T12:00:00Z`);
/** "Mer 1 ottobre" */
const giornoLungo = (ymd: string) => { const d = data(ymd); return `${GIORNI_BREVI[d.getUTCDay()]} ${d.getUTCDate()} ${MESI[d.getUTCMonth() + 1]}`; };
/** "5 – 11 ottobre", "29 settembre – 5 ottobre" */
function intervalloSettimana(a: string, b: string) {
  const [x, y] = [data(a), data(b)];
  if (x.getUTCMonth() === y.getUTCMonth()) return `${x.getUTCDate()} – ${y.getUTCDate()} ${MESI[y.getUTCMonth() + 1]}`;
  return `${x.getUTCDate()} ${MESI[x.getUTCMonth() + 1]} – ${y.getUTCDate()} ${MESI[y.getUTCMonth() + 1]}`;
}
/** "14 – 15 nov · 2 giorni", "3 ott · 09:00 – 13:00" */
function periodo(r: DatiHome['richieste'][number]) {
  if (!r.giornata && r.oraDa && r.oraA) return `${dataBreve(r.da, false)} · ${r.oraDa} – ${r.oraA}`;
  const tratto = r.da === r.a ? dataBreve(r.da, false)
    : r.da.slice(0, 7) === r.a.slice(0, 7) ? `${Number(r.da.slice(8, 10))} – ${dataBreve(r.a, false)}` : `${dataBreve(r.da, false)} – ${dataBreve(r.a, false)}`;
  return `${tratto} · ${r.giorni} giorn${r.giorni === 1 ? 'o' : 'i'}`;
}
function ora() {
  const h = Number(new Intl.DateTimeFormat('en-GB', { timeZone: 'Europe/Rome', hour: '2-digit', hourCycle: 'h23' }).format(new Date()));
  return h < 13 ? 'Buongiorno' : h < 18 ? 'Buon pomeriggio' : 'Buonasera';
}
const toast = (titolo: string, testo = '', tipo = '') => (window as any).Cora?.toast(titolo, testo, tipo);

function Volto({ p, classe = 'hr-volto' }: { p: Persona; classe?: string }) {
  return <span className={classe}>{p.foto ? <img src={p.foto} alt="" loading="lazy" /> : iniziali(p.nome, p.cognome)}</span>;
}

/** Dashboard dell'HR (Figma "Dashboard Hr", 744:521). */
export default function HomeHr({ cornice }: { cornice: CorniceHr }) {
  const [d, setD] = useState<DatiHome | null>(null);
  useEffect(() => { api.get('/hr/home').then((r) => setD(r.data)).catch(() => undefined); }, []);
  if (!d) return null;
  const sottotitolo = [
    d.inAttesa > 0 ? `Hai ${d.inAttesa} richiest${d.inAttesa === 1 ? 'a' : 'e'} da approvare` : 'Nessuna richiesta da approvare',
    d.prove > 0 ? `${d.prove} period${d.prove === 1 ? 'o' : 'i'} di prova da decidere` : '',
  ].filter(Boolean).join(' · ');
  return (
    <HrShell pagina="index" titolo="Home" cornice={cornice}>
      <div className="cora-reveal" style={{ '--i': 0 } as React.CSSProperties}>
        <HrTopbar titolo={`${ora()}, ${nome(d.nome)}`} sottotitolo={sottotitolo} centro={<Compleanni persone={d.compleanni} />} cornice={cornice} />
      </div>
      <section className="cora-home-row hr-oggi cora-reveal" style={{ '--i': 1 } as React.CSSProperties}>
        <PresenzeOggi d={d} />
        <Richieste d={d} aggiorna={(r) => setD({ ...d, ...r })} />
      </section>
      <section className="cora-home-row cora-reveal" style={{ '--i': 2 } as React.CSSProperties}>
        <a className="cora-card cora-quick" href="/admin/hire-requests?action=new">
          <span className="cora-quick-txt"><strong>Nuova assunzione</strong><span>Avvia il flusso con il consulente</span></span>
          <span className="cora-quick-logo" dangerouslySetInnerHTML={{ __html: svgCora('logo-cora.svg') }} />
        </a>
        <a className="cora-card cora-quick" href="/admin/documents">
          <span className="cora-quick-txt"><strong>Carica busta paga</strong><span>Cedolini e CU del mese</span></span>
          <span className="cora-quick-logo" dangerouslySetInnerHTML={{ __html: svgCora('logo-cora.svg') }} />
        </a>
        <a className="cora-card cora-quick" href="/admin/communications?action=new">
          <span className="cora-quick-txt"><strong>Nuovo avviso</strong><span>Invia una comunicazione ai dipendenti</span></span>
          <span className="cora-quick-logo" dangerouslySetInnerHTML={{ __html: svgCora('logo-cora.svg') }} />
        </a>
      </section>
      <section className="cora-home-row hr-settimana-riga cora-reveal" style={{ '--i': 3 } as React.CSSProperties}>
        <PresenzeSettimana iniziale={d.settimana} />
        <Attivita voci={d.attivita} />
      </section>
    </HrShell>
  );
}

/** Banner dei compleanni di oggi; si chiude per la giornata (come nella vista dipendente). */
function Compleanni({ persone }: { persone: Persona[] }) {
  const chiave = 'cora-bday-' + new Date().toISOString().slice(0, 10);
  const [chiuso, setChiuso] = useState(() => { try { return !!localStorage.getItem(chiave); } catch { return false; } });
  if (!persone.length || chiuso) return null;
  const nomi = persone.map((p) => `${nome(p.nome)} ${nome(p.cognome)}`);
  const ultimo = nomi.length > 1 ? ` e ${nomi.pop()}` : '';
  return (
    <div className="cora-bday">
      <span className="cora-bday-confetti" aria-hidden="true" dangerouslySetInnerHTML={{ __html: svgCora('coriandoli.svg') }} />
      <span className="cora-bday-who">
        <Volto p={persone[0]} classe="cora-bday-avatar" />
        <span className="cora-bday-text">Oggi è il compleanno di {nomi.join(', ')}{ultimo}</span>
      </span>
      <span className="cora-bday-actions">
        <a className="cora-bday-cta" href="/admin/chat">Fai gli auguri</a>
        <button type="button" className="cora-bday-close" aria-label="Chiudi" onClick={() => { setChiuso(true); try { localStorage.setItem(chiave, '1'); } catch { /* niente */ } }}
          dangerouslySetInnerHTML={{ __html: svgCora('ic-x.svg') }} />
      </span>
    </div>
  );
}

function PresenzeOggi({ d }: { d: DatiHome }) {
  const { attivi, assenti, presenti } = d.presenze;
  const arco = attivi > 0 ? (presenti / attivi) * C : 0;
  return (
    <div className="cora-card hr-presenze">
      <div className="cora-card-h">
        <h2>Presenze di oggi</h2>
        <span className="hr-chip">{giornoLungo(d.oggi)}</span>
      </div>
      <div className="cora-saldo-body">
        <div className="cora-donut">
          <svg viewBox="0 0 176 176" width="176" height="176" aria-hidden="true">
            <circle cx="88" cy="88" r={R} fill="none" stroke="var(--cora-accent-soft)" strokeWidth="20" />
            <circle className="cora-donut-arc" cx="88" cy="88" r={R} fill="none" stroke="var(--cora-accent)" strokeWidth="20"
              strokeDasharray={`${arco.toFixed(2)} ${C.toFixed(2)}`} style={{ '--arc': arco.toFixed(2) } as React.CSSProperties} transform="rotate(-90 88 88)" />
          </svg>
          <div className="cora-donut-val hr-donut-val">
            <strong>{presenti}<small>/{attivi}</small></strong>
            <span>in ufficio</span>
          </div>
          {d.inAttesa > 0 && (
            <a className="cora-donut-pending" href="/admin/leave-requests" title={`${d.inAttesa} richiest${d.inAttesa === 1 ? 'a' : 'e'} in attesa`}
              dangerouslySetInnerHTML={{ __html: svgCora('ic-orologio.svg') }} />
          )}
        </div>
        <div className="cora-saldo-rows">
          <div className="cora-saldo-row"><i className="dot is-used" /><span>Presenti</span><b>{presenti}</b></div>
          <div className="cora-saldo-row"><i className="dot is-resid" /><span>Assenti (ferie, permessi, malattia)</span><b>{assenti}</b></div>
          <div className="cora-saldo-row"><i className="dot is-total" /><span>Dipendenti attivi</span><b>{attivi}</b></div>
        </div>
      </div>
    </div>
  );
}

/** Richieste in attesa con approvazione e rifiuto rapidi (quick_approve / quick_reject della dashboard). */
function Richieste({ d, aggiorna }: { d: DatiHome; aggiorna: (r: Partial<DatiHome>) => void }) {
  const [lavoro, setLavoro] = useState<number | null>(null);
  const agisci = async (id: number, azione: 'approva' | 'rifiuta') => {
    let corpo: Record<string, string> = {};
    if (azione === 'rifiuta') {
      const motivo = window.prompt('Motivo del rifiuto (facoltativo)');
      if (motivo === null) return;
      corpo = { motivo };
    }
    setLavoro(id);
    try {
      await api.post(`/hr/richieste/${id}/${azione}`, corpo);
      const r = await api.get('/hr/home');
      aggiorna({ richieste: r.data.richieste, inAttesa: r.data.inAttesa, presenze: r.data.presenze, attivita: r.data.attivita });
      toast(azione === 'approva' ? 'Richiesta approvata' : 'Richiesta rifiutata');
    } catch (e: any) {
      toast('Operazione non riuscita', e?.response?.data?.message ?? '', 'error');
    } finally {
      setLavoro(null);
    }
  };
  return (
    <div className="cora-card hr-richieste">
      <div className="cora-card-h">
        <h2>Richieste da approvare</h2>
        {d.inAttesa > 0 && <span className="hr-chip">{d.inAttesa} in attesa</span>}
        <a href="/admin/leave-requests" className="cora-see-all">Vedi tutte <S n="ic-arr-r.svg" /></a>
      </div>
      {!d.richieste.length ? (
        <div className="cora-list-empty">
          <span className="cora-list-empty-ic" dangerouslySetInnerHTML={{ __html: svgCora('ic-ferie-permessi.svg') }} />
          <strong>Nessuna richiesta in attesa</strong>
          <span>Le nuove richieste di ferie e permessi compariranno qui</span>
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
              <div className="hr-richiesta-azioni">
                <button type="button" className="hr-azione" aria-label="Rifiuta" disabled={lavoro === r.id} onClick={() => agisci(r.id, 'rifiuta')}><S n="ic-rifiuta.svg" /></button>
                <button type="button" className="hr-azione" aria-label="Approva" disabled={lavoro === r.id} onClick={() => agisci(r.id, 'approva')}><S n="ic-approva.svg" /></button>
              </div>
            </div>
          ))}
        </div>
      )}
    </div>
  );
}

const LEGENDA: Array<[Stato, string]> = [['disponibile', 'Disponibile'], ['occupato', 'Occupato'], ['ferie', 'Ferie'], ['permesso', 'Permesso'], ['malattia', 'Malattia']];

/** Presenze della settimana: un giorno alla volta, una card per collega. */
function PresenzeSettimana({ iniziale }: { iniziale: Settimana }) {
  const [s, setS] = useState(iniziale);
  const indiceOggi = s.giorni.findIndex((g) => g.data === s.oggi);
  const primoLavorativo = Math.max(0, s.giorni.findIndex((g) => g.lavorativo));
  const [giorno, setGiorno] = useState(indiceOggi >= 0 && s.giorni[indiceOggi].lavorativo ? indiceOggi : primoLavorativo);
  const vai = async (w: string) => {
    const r = await api.get('/hr/settimana', { params: { w } });
    setS(r.data);
    const i = r.data.giorni.findIndex((g: any) => g.data === r.data.oggi);
    setGiorno(i >= 0 && r.data.giorni[i].lavorativo ? i : Math.max(0, r.data.giorni.findIndex((g: any) => g.lavorativo)));
  };
  const scrivi = async (id: number) => {
    try {
      const r = await api.post(`/hr/chat/${id}`);
      location.href = `/admin/chat?conv=${r.data.conversazione}`;
    } catch (e: any) {
      toast('Chat non disponibile', e?.response?.data?.message ?? '', 'error');
    }
  };
  const festa = s.giorni[giorno]?.festa ?? null;
  return (
    <div className="cora-card hr-settimana">
      <div className="cora-card-h">
        <div className="hr-card-titolo"><h2>Presenze settimanali</h2><span>{intervalloSettimana(s.inizio, s.fine)}</span></div>
        <div className="hr-nav">
          <button type="button" aria-label="Settimana precedente" onClick={() => vai(s.prima)}><S n="ic-chev-l-16.svg" /></button>
          <button type="button" className="is-oggi" onClick={() => vai(s.oggi)}>Oggi</button>
          <button type="button" aria-label="Settimana successiva" onClick={() => vai(s.dopo)}><S n="ic-chev-r-16.svg" /></button>
        </div>
      </div>
      <div className="hr-giorni" role="tablist">
        {s.giorni.map((g, i) => (
          <button key={g.data} type="button" role="tab" aria-selected={i === giorno} disabled={!g.lavorativo} title={g.festa ?? undefined}
            className={'hr-giorno' + (i === giorno ? ' is-active' : '')} onClick={() => setGiorno(i)}>
            {g.etichetta} {data(g.data).getUTCDate()}
          </button>
        ))}
      </div>
      {festa && <p className="hr-festa">{festa}</p>}
      {!s.persone.length ? (
        <div className="cora-list-empty"><strong>Nessun dipendente attivo</strong></div>
      ) : (
        <div className="hr-colleghi">
          {s.persone.map((p) => {
            const st = p.stati[giorno];
            return (
              <div key={p.id} className="hr-collega">
                <Volto p={p} classe={`hr-volto is-${st.stato}`} />
                <span className={`hr-stato is-${st.stato}`}><i />{st.etichetta}</span>
                <strong>{nome(p.nome)} {nome(p.cognome)}</strong>
                <span className="hr-collega-ruolo">{p.reparto || '—'}</span>
                <button type="button" className="hr-messaggio" onClick={() => scrivi(p.id)}><S n="ic-messaggio.svg" />Messaggio</button>
              </div>
            );
          })}
        </div>
      )}
      <div className="hr-legenda">
        {LEGENDA.map(([k, t]) => <span key={k}><i className={`is-${k}`} />{t}</span>)}
      </div>
    </div>
  );
}

function Attivita({ voci }: { voci: DatiHome['attivita'] }) {
  return (
    <div className={'cora-card hr-attivita' + (voci.length ? '' : ' is-empty')}>
      <div className="cora-card-h"><h2>Attività recente</h2></div>
      {!voci.length ? (
        <div className="cora-list-empty"><strong>Nessuna attività</strong><span>Qui compariranno le ultime novità</span></div>
      ) : (
        <div className="cora-rows">
          {voci.map((v, i) => (
            <div key={i} className="cora-row">
              <span className="cora-row-ic hr-row-ic" dangerouslySetInnerHTML={{ __html: svgCora(ICONE_ATTIVITA[v.tipo]) }} />
              <span className="cora-row-txt"><strong>{v.titolo}</strong><span>{v.meta} · {fa(v.data)}</span></span>
            </div>
          ))}
        </div>
      )}
    </div>
  );
}
