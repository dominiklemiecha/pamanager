import { useMemo, useState } from 'react';
import { nome } from '../../cora/formato';
import type { CorniceHr } from '../../cora/HrShell';
import { svgCora } from '../../cora/svg';
import { api } from '../../services/api';
import { MenuRiga, S, SezioneHr, toast, useDati, Volto } from '../hr/comune';

const BASE = '/consulente-lavoro';
const MESI = ['', 'Gennaio', 'Febbraio', 'Marzo', 'Aprile', 'Maggio', 'Giugno', 'Luglio', 'Agosto', 'Settembre', 'Ottobre', 'Novembre', 'Dicembre'];
const logo = <span className="hr-btn-logo" dangerouslySetInnerHTML={{ __html: svgCora('logo-cora-white-20.svg') }} />;

/** Schede di Presenze (Figma 983:11061 / 983:11309). */
export const SCHEDE_PRESENZE: Array<[string, string]> = [['Export mensile', `${BASE}/presenze-export`], ['Ferie e permessi', `${BASE}/leave-requests`]];

const oggi = () => new Intl.DateTimeFormat('sv-SE', { timeZone: 'Europe/Rome' }).format(new Date());
/** File Excel del mese: stesso download della pagina PHP (action=download). */
const urlExcel = (anno: number, mese: number) => `/api/v1/pagine/consulente-lavoro/presenze-export?action=download&month=${mese}&year=${anno}`;
/** "20 → 26 ott", "22 ott", "16 → 20 ott 2026" */
function periodo(da: string, a: string, conAnno = false) {
  const f = (ymd: string, mese: boolean, anno: boolean) => new Intl.DateTimeFormat('it-IT', { timeZone: 'UTC', day: 'numeric', ...(mese ? { month: 'short' } : {}), ...(anno ? { year: 'numeric' } : {}) })
    .format(new Date(`${ymd}T12:00:00Z`)).replace('.', '');
  if (da === a) return f(a, true, conAnno);
  return `${da.slice(0, 7) === a.slice(0, 7) ? f(da, false, false) : f(da, true, false)} → ${f(a, true, conAnno)}`;
}
const giorni = (n: number) => `${n} giorn${n === 1 ? 'o' : 'i'}`;

async function scriviHr() {
  try {
    const r = await api.post('/consulente/chat-hr');
    location.href = `${BASE}/chat?conv=${r.data.conversazione}`;
  } catch (e: any) {
    toast('Chat non disponibile', e?.response?.data?.message ?? '', 'error');
  }
}

/* ===================== Export mensile (Figma 983:11061) ===================== */

interface Riepilogo {
  anno: number; mese: number; attivi: number; giorni: number;
  somme: Record<'ferie' | 'permessi' | 'malattia' | 'smart', { giorni: number; minuti: number }>;
  inAttesa: Array<{ id: number; tipo: string; etichetta: string; nome: string; cognome: string; da: string; a: string; giornata: boolean; oraDa: string | null; oraA: string | null; giorni: number }>;
}

/** "5 gg", "1 gg · 4 h", "6 h" */
function quantita(s: { giorni: number; minuti: number }) {
  const ore = s.minuti ? `${Math.round((s.minuti / 60) * 10) / 10} h`.replace('.', ',') : '';
  if (!s.giorni && ore) return ore;
  return [`${s.giorni} gg`, ore].filter(Boolean).join(' · ');
}

export function ExportConsulente({ cornice }: { cornice: CorniceHr }) {
  const adesso = oggi();
  const [anno, setAnno] = useState(Number(adesso.slice(0, 4)));
  const [mese, setMese] = useState(Number(adesso.slice(5, 7)));
  const [d] = useDati<Riepilogo>(`/consulente/export?anno=${anno}&mese=${mese}`);
  // Ultimi quattro mesi chiusi, pronti da scaricare (nessun registro dei download: niente date inventate).
  const storico = useMemo(() => Array.from({ length: 4 }, (_, i) => {
    const x = new Date(Date.UTC(Number(adesso.slice(0, 4)), Number(adesso.slice(5, 7)) - 2 - i, 1));
    return { anno: x.getUTCFullYear(), mese: x.getUTCMonth() + 1 };
  }), [adesso]);
  const anni = [Number(adesso.slice(0, 4)) - 1, Number(adesso.slice(0, 4)), Number(adesso.slice(0, 4)) + 1];
  return (
    <SezioneHr cornice={cornice} pagina="presenze" titolo="Presenze" sottotitolo="Genera il file Excel mensile con ferie, ROL, malattia e smart working per l’elaborazione delle buste paga."
      schede={SCHEDE_PRESENZE} attiva="Export mensile">
      <div className="cs-export-righe">
        <div className="cora-card cs-export">
          <div className="cs-export-h">
            <span className="cs-export-ic"><S n="ic-sheet-22.svg" /></span>
            <span className="cs-carica-txt">
              <strong>Export presenze di {MESI[mese].toLowerCase()} {anno}</strong>
              <span>{d ? `${d.attivi} dipendent${d.attivi === 1 ? 'e' : 'i'} - ${giorni(d.giorni)} di assenza approvati` : ' '}</span>
            </span>
          </div>
          <div className="cs-export-campi">
            <label className="cora-field cs-export-mese"><span className="cora-label">Mese</span>
              <select className="cora-input" value={mese} onChange={(e) => setMese(Number(e.target.value))}>
                {MESI.slice(1).map((m, i) => <option key={m} value={i + 1}>{m}</option>)}
              </select></label>
            <label className="cora-field cs-export-anno"><span className="cora-label">Anno</span>
              <select className="cora-input" value={anno} onChange={(e) => setAnno(Number(e.target.value))}>
                {anni.map((a) => <option key={a} value={a}>{a}</option>)}
              </select></label>
            <a className="cora-btn cora-btn-primary cs-scarica-excel" href={urlExcel(anno, mese)}>{logo}Scarica Excel</a>
          </div>
          {d && (
            <>
              <div className="cs-kpi">
                {([['ferie', 'Ferie'], ['permessi', 'Permessi / ROL'], ['malattia', 'Malattia'], ['smart', 'Smart working']] as const).map(([k, t]) => (
                  <div key={k} className="cs-kpi-voce"><span>{t}</span><strong>{quantita(d.somme[k])}</strong></div>
                ))}
              </div>
              {d.inAttesa.length > 0 && (
                <div className="cs-in-attesa">
                  <p><S n="ic-alert-18.svg" />{d.inAttesa.length} richiest{d.inAttesa.length === 1 ? 'a' : 'e'} di {MESI[mese].toLowerCase()} {d.inAttesa.length === 1 ? 'è' : 'sono'} ancora in attesa: non {d.inAttesa.length === 1 ? 'sarà inclusa' : 'saranno incluse'} nell’export finché l’HR non {d.inAttesa.length === 1 ? 'la approva o rifiuta' : 'le approva o rifiuta'}.</p>
                  {d.inAttesa.map((r) => (
                    <div key={r.id} className="cs-in-attesa-riga">
                      <strong>{nome(r.nome)} {nome(r.cognome)}</strong>
                      <span className="cs-stato-attesa"><i />In attesa HR</span>
                      <span>{r.etichetta} - {periodo(r.da, r.a)}{r.giornata ? ` - ${giorni(r.giorni)}` : r.oraDa && r.oraA ? ` - ${r.oraDa} – ${r.oraA}` : ''}</span>
                    </div>
                  ))}
                  <button type="button" className="cs-pillola cs-pillola-alta" onClick={scriviHr}><S n="ic-chat.svg" />Scrivi all’HR</button>
                </div>
              )}
            </>
          )}
        </div>
        <div className="cora-card cs-storico">
          <h2>Storico export</h2>
          {storico.map((x) => (
            <div key={`${x.anno}-${x.mese}`} className="cs-storico-riga">
              <span className="cs-storico-ic"><S n="ic-sheet-17.svg" /></span>
              <span className="cs-carica-txt"><strong>{MESI[x.mese]} {x.anno}</strong><span>Excel</span></span>
              <a className="cs-storico-giu" href={urlExcel(x.anno, x.mese)} aria-label={`Scarica ${MESI[x.mese]} ${x.anno}`}><S n="ic-down.svg" /></a>
            </div>
          ))}
        </div>
      </div>
    </SezioneHr>
  );
}

/* ===================== Ferie e permessi (Figma 983:11309) ===================== */

interface Richiesta {
  id: number; tipo: string; etichetta: string; dipendenteId: number; nome: string; cognome: string; cf: string; foto: string;
  da: string; a: string; giornata: boolean; oraDa: string | null; oraA: string | null; giorni: number;
  protocollo: string; esonero: boolean; certificato: boolean; approvata: string;
}

const GRUPPI: Array<[string, string, (t: string) => boolean]> = [
  ['ferie', 'Ferie', (t) => t === 'ferie'],
  ['permesso', 'Permessi', (t) => t === 'permesso' || t === 'permesso_104'],
  ['malattia', 'Malattia', (t) => t === 'malattia'],
  ['smart_working', 'Smart working', (t) => t === 'smart_working'],
];
const dataIt = (iso: string) => (iso ? new Intl.DateTimeFormat('it-IT', { timeZone: 'Europe/Rome', day: '2-digit', month: '2-digit', year: 'numeric' }).format(new Date(iso)) : '—');

export function FerieConsulente({ cornice }: { cornice: CorniceHr }) {
  const annoOggi = Number(oggi().slice(0, 4));
  const [anno, setAnno] = useState(annoOggi);
  const [mese, setMese] = useState('');
  const [reparto, setReparto] = useState('');
  const [d] = useDati<{ richieste: Richiesta[]; reparti: Array<{ id: number; nome: string }> }>(`/consulente/ferie?anno=${anno}&mese=${mese}&reparto=${reparto}`);
  const righe = d?.richieste ?? [];
  const totale = righe.reduce((s, r) => s + r.giorni, 0);
  return (
    <SezioneHr cornice={cornice} pagina="presenze" titolo="Presenze" sottotitolo="Ferie, permessi e malattie approvati dall’HR: sono già inclusi nell’export mensile."
      schede={SCHEDE_PRESENZE} attiva="Ferie e permessi">
      <div className="cora-card cs-filtri-card">
        <label className="cora-field"><span className="cora-label">Anno</span>
          <select className="cora-input" value={anno} onChange={(e) => setAnno(Number(e.target.value))}>
            {[annoOggi - 2, annoOggi - 1, annoOggi, annoOggi + 1].map((a) => <option key={a} value={a}>{a}</option>)}
          </select></label>
        <label className="cora-field"><span className="cora-label">Mese</span>
          <select className="cora-input" value={mese} onChange={(e) => setMese(e.target.value)}>
            <option value="">Tutti</option>
            {MESI.slice(1).map((m, i) => <option key={m} value={i + 1}>{m}</option>)}
          </select></label>
        <label className="cora-field"><span className="cora-label">Reparto</span>
          <select className="cora-input" value={reparto} onChange={(e) => setReparto(e.target.value)}>
            <option value="">Tutti i reparti</option>
            {(d?.reparti ?? []).map((r) => <option key={r.id} value={r.id}>{r.nome}</option>)}
          </select></label>
      </div>
      <div className="cs-kpi cs-kpi-6">
        <div className="cs-kpi-voce is-card"><span>Richieste</span><strong>{righe.length}</strong></div>
        <div className="cs-kpi-voce is-card"><span>Giorni totali</span><strong>{totale}</strong></div>
        {GRUPPI.map(([k, t, f]) => (
          <div key={k} className="cs-kpi-voce is-card"><span className={`hr-tipo is-${k}`}>{t}</span><strong>{righe.filter((r) => f(r.tipo)).reduce((s, r) => s + r.giorni, 0)}</strong></div>
        ))}
      </div>
      <div className="cora-card cs-doc-card">
        <div className="cora-card-h cs-ferie-h"><h2>Richieste approvate dall’HR</h2><span className="cs-stato is-grigio"><i />{righe.length} richiest{righe.length === 1 ? 'a' : 'e'}</span></div>
        <div className="cs-doc-tab" role="table" aria-label="Richieste approvate">
          <div className="cs-doc-riga cs-ferie-riga cs-intestazione" role="row">
            <span role="columnheader">Dipendente</span><span role="columnheader">Tipo</span><span role="columnheader">Periodo</span>
            <span role="columnheader">Giorni</span><span role="columnheader">Stato</span><span role="columnheader">Approvata il</span><span />
          </div>
          {!righe.length && <div className="hr-vuoto">Nessuna richiesta approvata nel periodo</div>}
          {righe.map((r) => (
            <div key={r.id} className="cs-doc-riga cs-ferie-riga cs-scheda-m" role="row">
              <span className="cs-persona-cf" role="cell">
                <Volto p={{ id: r.dipendenteId, nome: r.nome, cognome: r.cognome, foto: r.foto }} classe="cs-iniziali cs-iniziali-36" />
                <span><strong>{nome(r.cognome)} {nome(r.nome)}</strong><small>{r.tipo === 'malattia' && r.protocollo ? `${r.protocollo}${r.esonero ? ' - Esonero' : ''}` : r.cf}</small></span>
              </span>
              <span role="cell" data-l="Tipo"><span className={`hr-tipo is-${r.tipo}`}>{r.etichetta}</span></span>
              <span className="cs-doc-data" role="cell" data-l="Periodo">{periodo(r.da, r.a, true)}{!r.giornata && r.oraDa && r.oraA ? ` · ${r.oraDa} – ${r.oraA}` : ''}</span>
              <span className="cs-doc-periodo" role="cell" data-l="Giorni">{r.giornata ? giorni(r.giorni) : 'A ore'}</span>
              <span role="cell" data-l="Stato"><span className="cs-attivo"><i />Approvata</span></span>
              <span className="cs-doc-letto cs-approvata" role="cell" data-l="Approvata il">{dataIt(r.approvata)}</span>
              <MenuRiga voci={[
                ...(r.certificato ? [{ etichetta: 'Scarica certificato', icona: 'ic-down.svg', href: `/api/v1/pagine/consulente-lavoro/leave-requests?download_cert=${r.id}` }] : []),
                { etichetta: 'Vai al dipendente', icona: 'ic-user.svg', href: `${BASE}/employees?cerca=${encodeURIComponent(`${r.cognome} ${r.nome}`)}` },
                { etichetta: 'Segnala un errore all’HR', icona: 'ic-alert.svg', pericolo: true, azione: scriviHr },
              ]} />
            </div>
          ))}
        </div>
      </div>
    </SezioneHr>
  );
}
