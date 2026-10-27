import { useCallback, useMemo, useState } from 'react';
import { dataBreve, nome } from '../../cora/formato';
import type { CorniceHr } from '../../cora/HrShell';
import { svgCora } from '../../cora/svg';
import { azione, MenuRiga, Modale, S, SCHEDE_DIPENDENTI, SezioneHr, toast, useDati, Volto } from './comune';

/* ===================== Anagrafica (Figma 918:5548) ===================== */

interface Dipendente {
  id: number; nome: string; cognome: string; email: string; username: string; cf: string; foto: string;
  attivo: boolean; repartoId: number | null; reparto: string; stato: string; etichetta: string;
}

export function AnagraficaHr({ cornice }: { cornice: CorniceHr }) {
  const [d] = useDati<{ dipendenti: Dipendente[]; reparti: Array<{ id: number; name: string }> }>('/hr/dipendenti');
  const [cerca, setCerca] = useState('');
  const [reparto, setReparto] = useState('');
  const [stato, setStato] = useState('attivi');
  const righe = useMemo(() => {
    const q = cerca.trim().toLowerCase();
    return (d?.dipendenti ?? []).filter((x) => {
      if (q && ![x.nome, x.cognome, `${x.nome} ${x.cognome}`, x.cf, x.username, x.email].some((v) => v.toLowerCase().includes(q))) return false;
      if (reparto && String(x.repartoId ?? '') !== reparto) return false;
      if (stato === 'attivi' && !x.attivo) return false;
      if (stato === 'cessati' && x.attivo) return false;
      if (['presente', 'occupato', 'assente', 'smart'].includes(stato) && x.stato !== stato) return false;
      return true;
    });
  }, [d, cerca, reparto, stato]);
  if (!d) return null;
  const attivi = d.dipendenti.filter((x) => x.attivo).length;
  return (
    <SezioneHr cornice={cornice} pagina="dipendenti" titolo="Dipendenti" sottotitolo={`Anagrafica, accessi e contatti · ${attivi} dipendent${attivi === 1 ? 'e' : 'i'} attiv${attivi === 1 ? 'o' : 'i'}`}
      schede={SCHEDE_DIPENDENTI} attiva="Anagrafica"
      azioni={<>
        <a className="cora-btn cora-btn-secondary" href="/admin/employees?action=new"><S n="ic-uplus.svg" />Crea dipendente</a>
        <a className="cora-btn cora-btn-primary" href="/admin/hire-requests?action=new"><span className="hr-btn-logo" dangerouslySetInnerHTML={{ __html: svgCora('logo-cora-white-20.svg') }} />Nuova assunzione</a>
      </>}>
      <div className="cora-card hr-elenco">
        <div className="hr-toolbar">
          <label className="cora-search hr-cerca"><S n="ic-search.svg" /><input type="search" placeholder="Cerca per nome, cognome, CF o username…" value={cerca} onChange={(e) => setCerca(e.target.value)} /></label>
          <select className="hr-select" value={reparto} onChange={(e) => setReparto(e.target.value)} aria-label="Reparto">
            <option value="">Tutti i reparti</option>
            {d.reparti.map((r) => <option key={r.id} value={r.id}>{r.name}</option>)}
          </select>
          <select className="hr-select" value={stato} onChange={(e) => setStato(e.target.value)} aria-label="Stato">
            <option value="attivi">Attivi</option>
            <option value="tutti">Tutti gli stati</option>
            <option value="presente">In presenza</option>
            <option value="occupato">In chiamata o riunione</option>
            <option value="smart">Smart working</option>
            <option value="assente">Assenti oggi</option>
            <option value="cessati">Non attivi</option>
          </select>
        </div>
        <div className="hr-tabella hr-tab-anagrafica">
          <div className="hr-riga hr-intestazione"><span>Dipendente</span><span>Codice fiscale</span><span>Reparto</span><span>Stato</span><span /></div>
          {!righe.length && <div className="hr-vuoto">Nessun dipendente trovato</div>}
          {righe.map((x) => (
            <div key={x.id} className="hr-riga">
              <a className="hr-persona" href={`/admin/employees?action=view&id=${x.id}`}>
                <Volto p={x} />
                <span><strong>{nome(x.nome)} {nome(x.cognome)}</strong><small>{x.email || x.username}</small></span>
              </a>
              <span className="hr-cf">{x.cf || '—'}</span>
              <span className="hr-forte">{x.reparto || '—'}</span>
              <span><span className={`hr-badge is-${x.stato}`}><i />{x.etichetta}</span></span>
              <MenuRiga voci={[
                { etichetta: 'Apri scheda', href: `/admin/employees?action=view&id=${x.id}` },
                { etichetta: 'Modifica', href: `/admin/employees?action=edit&id=${x.id}` },
                { etichetta: 'Documenti', href: `/admin/employee-documents?employee_id=${x.id}` },
              ]} />
            </div>
          ))}
        </div>
      </div>
    </SezioneHr>
  );
}

/* ===================== Assunzioni (Figma 918:5885) ===================== */

interface Assunzione { id: number; stato: string; etichetta: string; nome: string; cognome: string; inizio: string | null; contratto: string; ruolo: string; consulente: string; creata: string }
const TONO_ASSUNZIONE: Record<string, string> = {
  draft: 'neutro', awaiting_prospects: 'attesa', prospects_review: 'attesa', approved: 'attesa', contract_pending: 'attesa',
  contract_signed: 'ok', direct_hire: 'ok', rejected: 'errore', cancelled: 'neutro',
};

export function AssunzioniHr({ cornice }: { cornice: CorniceHr }) {
  const [d] = useDati<Assunzione[]>('/hr/assunzioni');
  if (!d) return null;
  return (
    <SezioneHr cornice={cornice} pagina="dipendenti" titolo="Dipendenti" sottotitolo="Avvia nuove assunzioni e segui i prospetti del consulente fino alla firma del contratto."
      schede={SCHEDE_DIPENDENTI} attiva="Assunzioni"
      azioni={<>
        <a className="cora-btn cora-btn-secondary" href="/admin/hire-requests?action=direct"><S n="ic-firma.svg" />Assunzione diretta</a>
        <a className="cora-btn cora-btn-primary" href="/admin/hire-requests?action=new"><span className="hr-btn-logo" dangerouslySetInnerHTML={{ __html: svgCora('logo-cora-white-20.svg') }} />Nuova assunzione</a>
      </>}>
      <div className="cora-card hr-elenco">
        {!d.length ? (
          <div className="hr-vuoto-grande">
            <span className="cora-empty-ic"><S n="ic-empty.svg" /></span>
            <h3>Nessuna assunzione in corso</h3>
            <p>Avvia una nuova assunzione: il consulente del lavoro riceverà la richiesta e potrai seguire ogni passaggio.</p>
            <div className="hr-passi">
              {[['Richiesta', 'Inserisci i dati del candidato e il tipo di contratto.'], ['Prospetto del consulente', 'Il consulente prepara il prospetto e lo condivide con te.'], ['Firma del dipendente', 'Il nuovo dipendente firma il contratto con OTP da Cora.']].map(([t, s], i) => (
                <div key={t} className={'hr-passo' + (i === 0 ? ' is-primo' : '')}><b>{i + 1}</b><span><strong>{t}</strong><small>{s}</small></span></div>
              ))}
            </div>
          </div>
        ) : (
          <div className="hr-tabella hr-tab-assunzioni">
            <div className="hr-riga hr-intestazione"><span>Candidato</span><span>Contratto</span><span>Inizio</span><span>Stato</span><span /></div>
            {d.map((a) => (
              <div key={a.id} className="hr-riga">
                <a className="hr-persona" href={`/admin/hire-requests?id=${a.id}`}>
                  <Volto p={{ id: a.id, nome: a.nome || '?', cognome: a.cognome, foto: '' }} />
                  <span><strong>{nome(a.nome)} {nome(a.cognome)}</strong><small>{a.ruolo || (a.consulente ? `Consulente: ${a.consulente}` : 'Creata ' + dataBreve(a.creata))}</small></span>
                </a>
                <span className="hr-forte">{a.contratto || '—'}</span>
                <span>{a.inizio ? dataBreve(a.inizio) : '—'}</span>
                <span><span className={`hr-badge is-${TONO_ASSUNZIONE[a.stato] ?? 'neutro'}`}><i />{a.etichetta}</span></span>
                <MenuRiga voci={[{ etichetta: 'Apri richiesta', href: `/admin/hire-requests?id=${a.id}` }]} />
              </div>
            ))}
          </div>
        )}
      </div>
    </SezioneHr>
  );
}

/* ===================== Presenze (Figma 918:6076) ===================== */

interface RigaPresenza {
  id: number; nome: string; cognome: string; email: string; foto: string; repartoId: number | null; reparto: string;
  stato: string; etichetta: string; entrata: string | null; uscita: string | null; minuti: number | null;
}
interface DatiPresenze { attive: boolean; giorno: string; oggi: string; totale?: number; presenti?: number; senza?: number; ore?: number; righe?: RigaPresenza[]; reparti?: Array<{ id: number; name: string }> }
const durata = (m: number | null) => (m === null ? '—' : `${Math.floor(m / 60)}h ${String(m % 60).padStart(2, '0')}m`);

const MESI = ['Gennaio', 'Febbraio', 'Marzo', 'Aprile', 'Maggio', 'Giugno', 'Luglio', 'Agosto', 'Settembre', 'Ottobre', 'Novembre', 'Dicembre'];

/** Export presenze (come la modale della testata classica): Excel del mese scelto. */
function EsportaPresenze({ aperta, chiudi }: { aperta: boolean; chiudi: () => void }) {
  const ora = new Date();
  const anno = ora.getFullYear();
  return (
    <Modale aperta={aperta} chiudi={chiudi} titolo="Export presenze" sottotitolo="Scarica il file Excel mensile (ferie, ROL, malattia).">
      <form className="hr-form" method="get" action="/api/v1/pagine/admin/presenze-export" onSubmit={() => setTimeout(chiudi)}>
        <input type="hidden" name="action" value="download" />
        <label className="cora-field"><span className="cora-label">Mese</span>
          <select className="cora-input" name="month" defaultValue={ora.getMonth() + 1}>
            {MESI.map((m, i) => <option key={m} value={i + 1}>{m}</option>)}
          </select></label>
        <label className="cora-field"><span className="cora-label">Anno</span>
          <select className="cora-input" name="year" defaultValue={anno}>
            {[anno - 1, anno, anno + 1].map((y) => <option key={y} value={y}>{y}</option>)}
          </select></label>
        <div className="hr-form-azioni">
          <button type="button" className="cora-btn cora-btn-ghost" onClick={chiudi}>Annulla</button>
          <button type="submit" className="cora-btn cora-btn-primary"><span className="hr-btn-logo" dangerouslySetInnerHTML={{ __html: svgCora('logo-cora-white-20.svg') }} />Scarica Excel</button>
        </div>
      </form>
    </Modale>
  );
}

export function PresenzeHr({ cornice }: { cornice: CorniceHr }) {
  const [giorno, setGiorno] = useState(() => new URLSearchParams(location.search).get('d') ?? '');
  const [d] = useDati<DatiPresenze>(`/hr/presenze?d=${encodeURIComponent(giorno)}`);
  const [cerca, setCerca] = useState('');
  const [reparto, setReparto] = useState('');
  const [esporta, setEsporta] = useState(false);
  const chiudiEsporta = useCallback(() => setEsporta(false), []);
  if (!d) return null;
  const righe = (d.righe ?? []).filter((x) => {
    const q = cerca.trim().toLowerCase();
    if (q && ![x.nome, x.cognome, `${x.nome} ${x.cognome}`, x.email].some((v) => v.toLowerCase().includes(q))) return false;
    return !reparto || String(x.repartoId ?? '') === reparto;
  });
  return (
    <SezioneHr cornice={cornice} pagina="dipendenti" titolo="Dipendenti" sottotitolo="Timbrature e presenze della giornata, esportabili per il consulente del lavoro."
      schede={SCHEDE_DIPENDENTI} attiva="Presenze"
      azioni={<button type="button" className="cora-btn cora-btn-primary" onClick={() => setEsporta(true)}><span className="hr-btn-logo" dangerouslySetInnerHTML={{ __html: svgCora('logo-cora-white-20.svg') }} />Esporta presenze</button>}>
      <EsportaPresenze aperta={esporta} chiudi={chiudiEsporta} />
      {!d.attive ? (
        <div className="cora-card hr-vuoto-grande">
          <span className="cora-empty-ic"><S n="ic-orologio.svg" /></span>
          <h3>Timbrature non attive</h3>
          <p>Attiva la timbratura per vedere entrate e uscite dei dipendenti.</p>
          <a className="cora-btn cora-btn-primary" href="/admin/punch-settings">Impostazioni timbrature</a>
        </div>
      ) : (
        <>
          <div className="hr-kpi">
            <div className="cora-card">
              <span className="hr-kpi-t">Presenti {d.giorno === d.oggi ? 'oggi' : 'il ' + dataBreve(d.giorno)}</span>
              <span className="hr-kpi-v"><b>{d.presenti}</b> / {d.totale} · attualmente in sede</span>
              <span className="hr-barra"><i style={{ width: `${d.totale ? Math.round((d.presenti! / d.totale) * 100) : 0}%` }} /></span>
            </div>
            <div className="cora-card">
              <span className="hr-kpi-t"><i className="hr-pallino is-errore" />Senza timbratura</span>
              <span className="hr-kpi-v"><b>{d.senza}</b> dipendent{d.senza === 1 ? 'e' : 'i'} da verificare</span>
            </div>
            <div className="cora-card">
              <span className="hr-kpi-t">Ore lavorate {d.giorno === d.oggi ? 'oggi' : 'nel giorno'}</span>
              <span className="hr-kpi-v"><b>{d.ore}</b> ore totali</span>
            </div>
          </div>
          <div className="cora-card hr-elenco">
            <div className="hr-toolbar">
              <label className="cora-search hr-cerca"><S n="ic-search.svg" /><input type="search" placeholder="Cerca per nome, cognome o email…" value={cerca} onChange={(e) => setCerca(e.target.value)} /></label>
              <input type="date" className="hr-select hr-data" value={d.giorno} max={d.oggi} onChange={(e) => setGiorno(e.target.value)} aria-label="Giorno" />
              <button type="button" className={'cora-tab' + (d.giorno === d.oggi ? ' is-active' : '')} onClick={() => setGiorno(d.oggi)}>Oggi</button>
              <select className="hr-select" value={reparto} onChange={(e) => setReparto(e.target.value)} aria-label="Reparto">
                <option value="">Tutti i reparti</option>
                {(d.reparti ?? []).map((r) => <option key={r.id} value={r.id}>{r.name}</option>)}
              </select>
            </div>
            <div className="hr-tabella hr-tab-presenze">
              <div className="hr-riga hr-intestazione"><span>Dipendente</span><span>Reparto</span><span>Stato</span><span>Entrata</span><span>Uscita</span><span>Ore</span><span /></div>
              {!righe.length && <div className="hr-vuoto">Nessun dipendente trovato</div>}
              {righe.map((x) => (
                <div key={x.id} className="hr-riga">
                  <span className="hr-persona"><Volto p={x} /><span><strong>{nome(x.nome)} {nome(x.cognome)}</strong><small>{x.email}</small></span></span>
                  <span className="hr-forte">{x.reparto || '—'}</span>
                  <span><span className={`hr-badge is-p-${x.stato}`}><i />{x.etichetta}</span></span>
                  <span>{x.entrata ?? '—'}</span>
                  <span>{x.uscita ?? '—'}</span>
                  <span className="hr-forte">{durata(x.minuti)}</span>
                  <MenuRiga voci={[{ etichetta: 'Modifica timbrature', href: `/admin/attendance?d=${d.giorno}&classica=1` }]} />
                </div>
              ))}
            </div>
          </div>
        </>
      )}
    </SezioneHr>
  );
}

/* ===================== Reparti (Figma 919:6165, 919:6524, 919:6926) ===================== */

interface PersonaReparto { id: number; nome: string; cognome: string; email: string; cf: string; foto: string; repartoId: number | null }
interface Reparto { id: number; nome: string; codice: string; descrizione: string; attivo: boolean; dipendenti: PersonaReparto[] }

export function RepartiHr({ cornice }: { cornice: CorniceHr }) {
  const [d, ricarica] = useDati<{ reparti: Reparto[]; dipendenti: PersonaReparto[] }>('/hr/reparti');
  const [modulo, setModulo] = useState<Reparto | 'nuovo' | null>(null);
  const [gestito, setGestito] = useState<number | null>(null);
  if (!d) return null;
  const attivi = d.reparti.filter((r) => r.attivo).length;
  const assegnati = d.dipendenti.filter((p) => p.repartoId).length;
  const elimina = async (r: Reparto) => {
    if (!window.confirm(`Eliminare definitivamente il reparto ${r.nome}?`)) return;
    const err = await azione('admin/departments', { action: 'delete', department_id: r.id });
    if (err) toast('Reparto non eliminato', err, 'error'); else { toast('Reparto eliminato'); await ricarica(); }
  };
  const corrente = d.reparti.find((r) => r.id === gestito) ?? null;
  return (
    <SezioneHr cornice={cornice} pagina="dipendenti" titolo="Dipendenti"
      sottotitolo={`Gestisci la struttura organizzativa: ${attivi} repart${attivi === 1 ? 'o' : 'i'} attiv${attivi === 1 ? 'o' : 'i'} · ${assegnati} dipendent${assegnati === 1 ? 'e' : 'i'} assegnat${assegnati === 1 ? 'o' : 'i'}.`}
      schede={SCHEDE_DIPENDENTI} attiva="Reparti"
      azioni={<button type="button" className="cora-btn cora-btn-primary" onClick={() => setModulo('nuovo')}><span className="hr-btn-logo" dangerouslySetInnerHTML={{ __html: svgCora('logo-cora-white-20.svg') }} />Nuovo reparto</button>}>
      <div className="cora-card hr-elenco">
        <div className="hr-reparti">
          {d.reparti.map((r) => (
            <div key={r.id} className={'hr-reparto' + (r.attivo ? '' : ' is-inattivo')}>
              <div className="hr-reparto-h">
                <span className="hr-reparto-lettera">{r.nome.charAt(0).toUpperCase()}</span>
                <span className="hr-reparto-nome"><strong>{r.nome}</strong><small><i />{r.attivo ? 'Attivo' : 'Non attivo'}</small></span>
                <MenuRiga voci={[
                  { etichetta: 'Gestisci dipendenti', azione: () => setGestito(r.id) },
                  { etichetta: 'Modifica', azione: () => setModulo(r) },
                  ...(r.dipendenti.length ? [] : [{ etichetta: 'Elimina', azione: () => elimina(r), pericolo: true }]),
                ]} />
              </div>
              <div className="hr-reparto-volti">
                {r.dipendenti.slice(0, 5).map((p) => <Volto key={p.id} p={p} classe="hr-volto hr-volto-mini" />)}
                {r.dipendenti.length > 5 && <span className="hr-volto hr-volto-mini">+{r.dipendenti.length - 5}</span>}
              </div>
              <div className="hr-reparto-f">
                <span><S n="dock-dipendenti.svg" t />{r.dipendenti.length} dipendent{r.dipendenti.length === 1 ? 'e' : 'i'}</span>
                <button type="button" className="hr-link" onClick={() => setGestito(r.id)}>Gestisci <S n="ic-chev-r-16.svg" /></button>
              </div>
            </div>
          ))}
          <button type="button" className="hr-reparto hr-reparto-nuovo" onClick={() => setModulo('nuovo')}>
            <span className="hr-piu">+</span>
            <strong>Nuovo reparto</strong>
            <small>Raggruppa i dipendenti per area</small>
          </button>
        </div>
      </div>
      <ModuloReparto reparto={modulo} chiudi={() => setModulo(null)} salvato={ricarica} />
      {corrente && <GestisciReparto reparto={corrente} tutti={d.dipendenti} reparti={d.reparti} chiudi={() => setGestito(null)} cambiato={ricarica} />}
    </SezioneHr>
  );
}

function ModuloReparto({ reparto, chiudi, salvato }: { reparto: Reparto | 'nuovo' | null; chiudi: () => void; salvato: () => Promise<void> }) {
  const nuovo = reparto === 'nuovo';
  const r = reparto && reparto !== 'nuovo' ? reparto : null;
  const [errore, setErrore] = useState('');
  const [invio, setInvio] = useState(false);
  const invia = async (e: React.FormEvent<HTMLFormElement>) => {
    e.preventDefault();
    const f = new FormData(e.currentTarget);
    setInvio(true);
    const err = await azione('admin/departments', {
      action: nuovo ? 'create' : 'update', ...(r ? { department_id: r.id } : {}),
      name: String(f.get('name') ?? ''), code: String(f.get('code') ?? ''), description: String(f.get('description') ?? ''),
    });
    setInvio(false);
    if (err) { setErrore(err); return; }
    toast(nuovo ? 'Reparto creato' : 'Reparto aggiornato');
    setErrore('');
    chiudi();
    await salvato();
  };
  return (
    <Modale aperta={!!reparto} chiudi={() => { setErrore(''); chiudi(); }} titolo={nuovo ? 'Nuovo reparto' : 'Modifica reparto'}
      sottotitolo={nuovo ? 'Crea un\'area e poi assegna i dipendenti.' : r?.nome}>
      <form className="hr-form" onSubmit={invia} key={r?.id ?? 'nuovo'}>
        <label className="cora-field"><span className="cora-label">Nome reparto<span className="req">*</span></span>
          <input className="cora-input" name="name" required minLength={2} maxLength={100} defaultValue={r?.nome ?? ''} placeholder="Es. Ufficio tecnico" autoFocus /></label>
        <label className="cora-field"><span className="cora-label">Codice<span className="req">*</span></span>
          <input className="cora-input" name="code" required minLength={2} maxLength={20} defaultValue={r?.codice ?? ''} placeholder="Es. TEC" /></label>
        <label className="cora-field"><span className="cora-label">Descrizione (facoltativa)</span>
          <textarea className="cora-input cora-textarea" name="description" defaultValue={r?.descrizione ?? ''} placeholder="Breve descrizione del reparto…" /></label>
        {errore && <p className="hr-errore">{errore}</p>}
        <div className="hr-form-azioni">
          <button type="button" className="cora-btn cora-btn-ghost" onClick={chiudi}>Annulla</button>
          <button type="submit" className="cora-btn cora-btn-primary" disabled={invio}><span className="hr-btn-logo" dangerouslySetInnerHTML={{ __html: svgCora('logo-cora-white-20.svg') }} />{nuovo ? 'Crea reparto' : 'Salva'}</button>
        </div>
      </form>
    </Modale>
  );
}

function GestisciReparto({ reparto, tutti, reparti, chiudi, cambiato }: {
  reparto: Reparto; tutti: PersonaReparto[]; reparti: Reparto[]; chiudi: () => void; cambiato: () => Promise<void>;
}) {
  const [cerca, setCerca] = useState('');
  const [scelto, setScelto] = useState<PersonaReparto | null>(null);
  const [lavoro, setLavoro] = useState(false);
  const nomeReparto = (id: number | null) => reparti.find((x) => x.id === id)?.nome;
  const candidati = tutti.filter((p) => p.repartoId !== reparto.id
    && (!cerca.trim() || `${p.nome} ${p.cognome} ${p.cognome} ${p.nome}`.toLowerCase().includes(cerca.trim().toLowerCase())));
  const esegui = async (action: 'assign_employee' | 'remove_employee', id: number) => {
    setLavoro(true);
    const err = await azione(`admin/department-detail?id=${reparto.id}`, { action, employee_id: id });
    setLavoro(false);
    if (err) { toast('Operazione non riuscita', err, 'error'); return; }
    toast(action === 'assign_employee' ? 'Dipendente assegnato al reparto' : 'Dipendente rimosso dal reparto');
    setScelto(null);
    setCerca('');
    await cambiato();
  };
  return (
    <Modale aperta chiudi={chiudi} titolo={reparto.nome} larga
      intestazione={<div className="hr-reparto-h"><span className="hr-reparto-lettera">{reparto.nome.charAt(0).toUpperCase()}</span>
        <span className="hr-reparto-nome"><strong className="hr-modale-titolo">{reparto.nome}</strong><small><i />{reparto.attivo ? 'Attivo' : 'Non attivo'} · Codice {reparto.codice}</small></span></div>}>
      <div className="hr-aggiungi">
        <div className="hr-combo">
          <label className="cora-search hr-cerca"><S n="ic-search.svg" />
            <input type="search" placeholder="Cerca un dipendente da aggiungere…" value={scelto ? `${nome(scelto.nome)} ${nome(scelto.cognome)}` : cerca}
              onChange={(e) => { setScelto(null); setCerca(e.target.value); }} /></label>
          {cerca.trim() !== '' && !scelto && (
            <div className="hr-combo-lista">
              {!candidati.length && <div className="hr-vuoto">Nessun dipendente</div>}
              {candidati.slice(0, 8).map((p) => (
                <button key={p.id} type="button" onClick={() => setScelto(p)}>
                  <ColtoMini p={p} />
                  <span><strong>{nome(p.nome)} {nome(p.cognome)}</strong><small>{p.repartoId ? `Attualmente in ${nomeReparto(p.repartoId)}` : 'Senza reparto'}</small></span>
                </button>
              ))}
            </div>
          )}
        </div>
        <button type="button" className="cora-btn cora-btn-primary" disabled={!scelto || lavoro} onClick={() => scelto && esegui('assign_employee', scelto.id)}>
          <span className="hr-btn-logo" dangerouslySetInnerHTML={{ __html: svgCora('logo-cora-white-20.svg') }} />Aggiungi</button>
      </div>
      <p className="hr-conteggio">{reparto.dipendenti.length} dipendent{reparto.dipendenti.length === 1 ? 'e' : 'i'} nel reparto</p>
      <div className="hr-membri">
        {reparto.dipendenti.map((p) => (
          <div key={p.id} className="hr-membro">
            <ColtoMini p={p} />
            <span><strong>{nome(p.cognome)} {nome(p.nome)}</strong><small>{[p.email, p.cf].filter(Boolean).join(' · ')}</small></span>
            <button type="button" className="hr-rimuovi" disabled={lavoro} onClick={() => esegui('remove_employee', p.id)}><S n="ic-uplus.svg" />Rimuovi</button>
          </div>
        ))}
      </div>
      <div className="hr-form-azioni"><button type="button" className="cora-btn cora-btn-secondary" onClick={chiudi}>Chiudi</button></div>
    </Modale>
  );
}

const ColtoMini = ({ p }: { p: PersonaReparto }) => <Volto p={p} classe="hr-volto hr-volto-36" />;
