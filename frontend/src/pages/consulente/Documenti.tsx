import { useMemo, useRef, useState } from 'react';
import { nome } from '../../cora/formato';
import type { CorniceHr } from '../../cora/HrShell';
import { svgCora } from '../../cora/svg';
import { api } from '../../services/api';
import { inviaPagina, MenuRiga, Modale, S, SezioneHr, toast, useDati } from '../hr/comune';

const BASE = '/consulente-lavoro';
const MESI = ['', 'Gennaio', 'Febbraio', 'Marzo', 'Aprile', 'Maggio', 'Giugno', 'Luglio', 'Agosto', 'Settembre', 'Ottobre', 'Novembre', 'Dicembre'];
const logo = <span className="hr-btn-logo" dangerouslySetInnerHTML={{ __html: svgCora('logo-cora-white-20.svg') }} />;

/** Schede di Documenti (Figma 982:10280 / 982:11229). */
export const SCHEDE_DOCUMENTI: Array<[string, string]> = [['Buste paga e CU', `${BASE}/documents`], ['Caricati dai dipendenti', `${BASE}/employee-documents`]];

/** "16/10/2026 - 10:31" */
function dataOra(iso: string) {
  const p = Object.fromEntries(new Intl.DateTimeFormat('it-IT', { timeZone: 'Europe/Rome', day: '2-digit', month: '2-digit', year: 'numeric', hour: '2-digit', minute: '2-digit', hourCycle: 'h23' })
    .formatToParts(new Date(iso)).map((x) => [x.type, x.value]));
  return `${p.day}/${p.month}/${p.year} - ${p.hour}:${p.minute}`;
}
/** "16/10" */
const giornoMese = (iso: string) => new Intl.DateTimeFormat('it-IT', { timeZone: 'Europe/Rome', day: '2-digit', month: '2-digit' }).format(new Date(iso));
/** "16 → 19 ott" */
function intervallo(da: string, a: string) {
  const f = (ymd: string, mese: boolean) => new Intl.DateTimeFormat('it-IT', { timeZone: 'UTC', day: 'numeric', ...(mese ? { month: 'short' } : {}) }).format(new Date(`${ymd}T12:00:00Z`)).replace('.', '');
  return da === a ? f(a, true) : `${da.slice(0, 7) === a.slice(0, 7) ? f(da, false) : f(da, true)} → ${f(a, true)}`;
}
const meseCorrente = () => new Intl.DateTimeFormat('sv-SE', { timeZone: 'Europe/Rome', year: 'numeric', month: '2-digit' }).format(new Date()).slice(0, 7);

async function scriviA(dipendente: number) {
  try {
    const r = await api.post(`/consulente/chat/${dipendente}`);
    location.href = `${BASE}/chat?conv=${r.data.conversazione}`;
  } catch (e: any) {
    toast('Chat non disponibile', e?.response?.data?.message ?? '', 'error');
  }
}

/* ===================== Buste paga e CU (Figma 982:10280) ===================== */

interface Documento {
  id: number; tipo: string; etichetta: string; titolo: string; mese: number | null; anno: number | null; caricato: string;
  dipendenteId: number; nome: string; cognome: string; letto: string;
}
interface Dipendente { id: number; nome: string; cognome: string; cf: string }
type DatiDocumenti = { documenti: Documento[]; dipendenti: Dipendente[] };

const periodo = (d: Documento) => (d.tipo === 'cud' && d.anno ? `Anno ${d.anno}` : d.mese && d.anno ? `${MESI[d.mese]} ${d.anno}` : '—');

export function DocumentiConsulente({ cornice }: { cornice: CorniceHr }) {
  const [d, ricarica] = useDati<DatiDocumenti>('/consulente/documenti');
  const iniziale = useMemo(() => new URLSearchParams(location.search), []);
  const [tipo, setTipo] = useState('');
  const [dipendente, setDipendente] = useState(iniziale.get('employee_id') ?? '');
  const [mese, setMese] = useState('');
  const [modale, setModale] = useState<'' | 'singolo' | 'massivo'>('');
  const righe = useMemo(() => (d?.documenti ?? []).filter((x) => (!tipo || x.tipo === tipo) && (!dipendente || String(x.dipendenteId) === dipendente)
    && (!mese || (x.anno === Number(mese.slice(0, 4)) && x.mese === Number(mese.slice(5, 7))))), [d, tipo, dipendente, mese]);
  if (!d) return null;
  const elimina = async (x: Documento) => {
    if (!window.confirm(`Eliminare "${x.titolo}"? Il dipendente non lo vedrà più.`)) return;
    const r = await inviaPagina('consulente-lavoro/documents', { action: 'delete', document_id: x.id });
    if (r.redirect?.includes('deleted')) { toast('Documento eliminato'); await ricarica(); } else toast('Eliminazione non riuscita', r.errore, 'error');
  };
  return (
    <SezioneHr cornice={cornice} pagina="documenti" titolo="Documenti" sottotitolo="Carica buste paga e CU: ogni dipendente riceve una notifica e li trova in «I tuoi documenti»."
      schede={SCHEDE_DOCUMENTI} attiva="Buste paga e CU">
      <div className="cs-carica-righe">
        <button type="button" className="cs-carica" onClick={() => setModale('singolo')}>
          <span className="cs-carica-ic"><S n="ic-up-22.svg" /></span>
          <span className="cs-carica-txt"><strong>Carica manualmente</strong><span>Un singolo file per un dipendente specifico</span></span>
          <S n="ic-arr-r-20.svg" />
        </button>
        <button type="button" className="cs-carica is-nera" onClick={() => setModale('massivo')}>
          <span className="cs-carica-ic"><S n="ic-grid-bianca.svg" /></span>
          <span className="cs-carica-txt"><strong>Caricamento massivo buste paga</strong><span>Trascina i PDF: Cora legge codice fiscale e mensilità e li assegna da solo</span></span>
          <S n="ic-arr-r-20-bianca.svg" />
        </button>
      </div>
      <div className="cora-card cs-doc-card">
        <div className="cs-doc-filtri">
          <div className="cs-pillole" role="tablist">
            {[['', 'Tutti'], ['payslip', 'Busta paga'], ['cud', 'CU'], ['other', 'Altro']].map(([k, t]) => (
              <button key={k} type="button" role="tab" aria-selected={tipo === k} className={'cs-pill' + (tipo === k ? ' is-active' : '')} onClick={() => setTipo(k)}>{t}</button>
            ))}
          </div>
          <span className="hr-spazio" />
          <select className="hr-select" value={dipendente} onChange={(e) => setDipendente(e.target.value)} aria-label="Dipendente">
            <option value="">Tutti i dipendenti</option>
            {d.dipendenti.map((x) => <option key={x.id} value={x.id}>{nome(x.cognome)} {nome(x.nome)}</option>)}
          </select>
          <input className="cora-input cs-mese" type="month" value={mese} onChange={(e) => setMese(e.target.value)} aria-label="Periodo" />
        </div>
        <div className="cs-doc-tab" role="table" aria-label="Documenti caricati">
          <div className="cs-doc-riga cs-intestazione" role="row">
            <span role="columnheader">Documento</span><span role="columnheader">Caricato il</span><span role="columnheader">Dipendente</span>
            <span role="columnheader">Periodo</span><span role="columnheader">Letto dal dipendente</span><span />
          </div>
          {!righe.length && <div className="hr-vuoto">Nessun documento</div>}
          {righe.map((x) => (
            <div key={x.id} className="cs-doc-riga" role="row">
              <span className="cs-doc-nome" role="cell"><span className="cs-doc-ic"><S n="ic-file-orange.svg" /></span>{x.titolo}</span>
              <span className="cs-doc-data" role="cell">{dataOra(x.caricato)}</span>
              <span className="cs-doc-dip" role="cell">{nome(x.nome)} {nome(x.cognome)}</span>
              <span className="cs-doc-periodo" role="cell">{periodo(x)}</span>
              <span className="cs-doc-letto" role="cell">{x.letto ? `Sì - ${giornoMese(x.letto)}` : 'Non ancora'}</span>
              <MenuRiga voci={[
                { etichetta: 'Visualizza', icona: 'ic-eye.svg', href: `/api/download.php?id=${x.id}&inline=1`, nuova: true },
                { etichetta: 'Scarica', icona: 'ic-down.svg', href: `/api/download.php?id=${x.id}` },
                { etichetta: 'Elimina documento', icona: 'ic-trash.svg', pericolo: true, azione: () => elimina(x) },
              ]} />
            </div>
          ))}
        </div>
      </div>
      <CaricaSingolo aperta={modale === 'singolo'} chiudi={() => setModale('')} dipendenti={d.dipendenti} caricato={ricarica} />
      <CaricaMassivo aperta={modale === 'massivo'} chiudi={() => setModale('')} dipendenti={d.dipendenti} caricato={ricarica} />
    </SezioneHr>
  );
}

const pesoFile = (n: number) => (n < 1024 * 1024 ? `${Math.max(1, Math.round(n / 1024))} KB` : `${(n / 1024 / 1024).toFixed(1).replace('.', ',')} MB`);

/** Carica documento (Figma 982:10551): stesso caricamento della pagina PHP (action=upload). */
function CaricaSingolo({ aperta, chiudi, dipendenti, caricato }: { aperta: boolean; chiudi: () => void; dipendenti: Dipendente[]; caricato: () => Promise<void> }) {
  const [file, setFile] = useState<File | null>(null);
  const [errore, setErrore] = useState('');
  const [invio, setInvio] = useState(false);
  const input = useRef<HTMLInputElement>(null);
  const esci = () => { setFile(null); setErrore(''); chiudi(); };
  const invia = async (e: React.FormEvent<HTMLFormElement>) => {
    e.preventDefault();
    if (!file) { setErrore('Seleziona un file da caricare'); return; }
    const fd = new FormData(e.currentTarget);
    fd.append('action', 'upload');
    fd.append('document', file);
    setInvio(true);
    const r = await inviaPagina('consulente-lavoro/documents', fd);
    setInvio(false);
    if (r.redirect?.includes('uploaded')) { toast('Documento caricato', 'Il dipendente riceverà una notifica'); esci(); await caricato(); } else setErrore(r.errore || 'Caricamento non riuscito');
  };
  return (
    <Modale aperta={aperta} chiudi={esci} titolo="Carica documento" sottotitolo="Un singolo file per un dipendente specifico. Il dipendente riceverà una notifica.">
      <form className="hr-form" onSubmit={invia}>
        <div className="cora-grid-2">
          <label className="cora-field"><span className="cora-label">Dipendente<span className="req">*</span></span>
            <select className="cora-input" name="employee_id" required defaultValue="">
              <option value="" disabled>Scegli il dipendente</option>
              {dipendenti.map((x) => <option key={x.id} value={x.id}>{nome(x.nome)} {nome(x.cognome)}{x.cf ? ` - ${x.cf}` : ''}</option>)}
            </select></label>
          <label className="cora-field"><span className="cora-label">Tipo documento<span className="req">*</span></span>
            <select className="cora-input" name="type" required defaultValue="payslip">
              <option value="payslip">Busta paga</option><option value="cud">CU</option><option value="other">Altro</option>
            </select></label>
        </div>
        <div className="cora-grid-2">
          <label className="cora-field"><span className="cora-label">Periodo<span className="req">*</span></span>
            <input className="cora-input" type="month" name="period" required defaultValue={meseCorrente()} /></label>
          <label className="cora-field"><span className="cora-label">Titolo</span>
            <input className="cora-input" name="title" maxLength={255} placeholder="Generato in automatico se vuoto" /></label>
        </div>
        <div className="cora-field">
          {file ? (
            <div className="cs-file">
              <span className="cs-doc-ic"><S n="ic-file-orange.svg" /></span>
              <span className="cs-file-txt"><strong>{file.name}</strong><span>{(file.name.split('.').pop() ?? '').toUpperCase()} - {pesoFile(file.size)} - pronto per il caricamento</span></span>
              <button type="button" className="cs-file-x" aria-label="Togli il file" onClick={() => setFile(null)}><S n="ic-x.svg" /></button>
            </div>
          ) : (
            <button type="button" className="hr-drop" onClick={() => input.current?.click()}
              onDragOver={(e) => e.preventDefault()} onDrop={(e) => { e.preventDefault(); const f = e.dataTransfer.files[0]; if (f) setFile(f); }}>
              <span className="hr-drop-ic"><S n="ic-up.svg" /></span>
              <span>Trascina qui il file oppure <b>sfoglia</b></span>
            </button>
          )}
          <input ref={input} type="file" hidden accept=".pdf,.jpg,.jpeg,.png,.doc,.docx" onChange={(e) => setFile(e.target.files?.[0] ?? null)} />
          <small className="cs-formati">PDF, JPG, PNG, DOC - max 10 MB</small>
        </div>
        {errore && <p className="hr-errore">{errore}</p>}
        <div className="hr-form-azioni">
          <button type="button" className="cora-btn cora-btn-ghost" onClick={esci}>Annulla</button>
          <button type="submit" className="cora-btn cora-btn-primary" disabled={invio}>{logo}{invio ? 'Caricamento…' : 'Carica documento'}</button>
        </div>
      </form>
    </Modale>
  );
}

interface RigaMassiva {
  filename: string; staging_id?: string; cf?: string | null; employee_id: number | null; employee_name?: string | null; month: number | null; year: number | null;
  ferie_residuo?: number | null; perm_residuo?: number | null; status: 'ready' | 'partial' | 'unmatched' | 'error'; error?: string; applica?: boolean;
  /** Mensilita' non letta dal PDF: si sceglie a mano (il campo resta visibile). */
  senzaMese?: boolean;
}

/** Caricamento massivo (Figma 982:10884): bulk_analyze legge i PDF, bulk_commit li carica (stesse azioni della pagina PHP). */
function CaricaMassivo({ aperta, chiudi, dipendenti, caricato }: { aperta: boolean; chiudi: () => void; dipendenti: Dipendente[]; caricato: () => Promise<void> }) {
  const [saldi, setSaldi] = useState(true);
  const [righe, setRighe] = useState<RigaMassiva[]>([]);
  const [lavoro, setLavoro] = useState('');
  const input = useRef<HTMLInputElement>(null);
  const esci = () => { setRighe([]); setLavoro(''); chiudi(); };
  const analizza = async (lista: FileList | null) => {
    if (!lista?.length) return;
    const fd = new FormData();
    fd.append('action', 'bulk_analyze');
    Array.from(lista).forEach((f) => fd.append('files[]', f));
    setLavoro('Lettura dei PDF…');
    try {
      const r = await api.post('/pagine/consulente-lavoro/documents', fd);
      if (!r.data?.success) throw new Error(r.data?.error ?? '');
      setRighe((prima) => [...prima, ...(r.data.rows as RigaMassiva[]).map((x) => ({ ...x, applica: x.ferie_residuo != null || x.perm_residuo != null, senzaMese: !(x.month && x.year) }))]);
    } catch (e: any) {
      toast('Lettura non riuscita', e?.response?.data?.message ?? e?.message ?? '', 'error');
    } finally {
      setLavoro('');
      if (input.current) input.current.value = '';
    }
  };
  const aggiorna = (i: number, p: Partial<RigaMassiva>) => setRighe((r) => r.map((x, j) => (j === i ? { ...x, ...p } : x)));
  const pronte = righe.filter((x) => x.status !== 'error' && x.staging_id && x.employee_id && x.month && x.year);
  const conferma = async () => {
    const fd = new FormData();
    fd.append('action', 'bulk_commit');
    fd.append('rows', JSON.stringify(pronte.map((x) => ({
      staging_id: x.staging_id, employee_id: x.employee_id, month: x.month, year: x.year, filename: x.filename,
      apply_balance: saldi && x.applica ? 1 : 0, ferie_residuo: x.ferie_residuo ?? '', perm_residuo: x.perm_residuo ?? '',
    }))));
    setLavoro('Caricamento…');
    try {
      const r = await api.post('/pagine/consulente-lavoro/documents', fd);
      const errori = (r.data?.errors ?? []) as Array<{ filename: string; error: string }>;
      toast(`${r.data?.created ?? 0} buste paga caricate`, errori.length ? `${errori.length} con errori: ${errori.map((x) => x.filename).join(', ')}` : 'I dipendenti riceveranno una notifica', errori.length ? 'error' : 'success');
      esci();
      await caricato();
    } catch (e: any) {
      toast('Caricamento non riuscito', e?.response?.data?.message ?? '', 'error');
      setLavoro('');
    }
  };
  const dettaglio = (x: RigaMassiva) => [x.employee_name, x.month && x.year ? `${MESI[x.month]} ${x.year}` : ''].filter(Boolean).join(' - ');
  const residui = (x: RigaMassiva) => (x.ferie_residuo == null && x.perm_residuo == null ? '—'
    : [x.ferie_residuo != null ? `Ferie ${x.ferie_residuo}` : '', x.perm_residuo != null ? `Permessi ${x.perm_residuo}` : ''].filter(Boolean).join(' - '));
  return (
    <Modale aperta={aperta} chiudi={esci} larga titolo="Caricamento massivo buste paga" sottotitolo="Trascina i PDF: Cora legge codice fiscale e mensilità e li assegna ai dipendenti.">
      <div className="hr-form cs-massivo">
        <label className="cs-saldi">
          <input type="checkbox" checked={saldi} onChange={(e) => setSaldi(e.target.checked)} />
          <span><strong>Aggiorna anche i saldi di ferie e permessi leggendoli dal PDF</strong>
            <span>In anteprima vedi i residui rilevati e scegli riga per riga se applicarli. I dipendenti vedranno i saldi aggiornati nella loro dashboard.</span></span>
        </label>
        <button type="button" className="cs-drop-riga" onClick={() => input.current?.click()} disabled={!!lavoro}
          onDragOver={(e) => e.preventDefault()} onDrop={(e) => { e.preventDefault(); analizza(e.dataTransfer.files); }}>
          <S n="ic-up.svg" />{lavoro || <>Trascina qui {righe.length ? 'altri ' : 'i '}PDF oppure <b>scegli file</b></>}
        </button>
        <input ref={input} type="file" hidden multiple accept=".pdf,application/pdf" onChange={(e) => analizza(e.target.files)} />
        {!!righe.length && (
          <div className="cs-anteprima">
            <p>Anteprima - {righe.length} file</p>
            {righe.map((x, i) => {
              const ok = x.status === 'ready';
              return (
                <div key={`${x.staging_id ?? ''}${i}`} className={'cs-anteprima-riga' + (ok ? '' : ' is-attenzione')}>
                  <span className="cs-anteprima-ic"><S n={ok ? 'ic-check-verde.svg' : 'ic-alert.svg'} /></span>
                  <span className="cs-anteprima-txt">
                    <strong>{x.filename}</strong>
                    <span className={ok ? '' : 'is-avviso'}>{x.status === 'error' ? x.error : ok ? dettaglio(x) : !x.cf ? 'Codice fiscale non trovato' : !x.employee_id ? 'Dipendente non trovato' : 'Mensilità non trovata'}</span>
                  </span>
                  <span className="cs-anteprima-saldi">{residui(x)}</span>
                  {x.status !== 'error' && (ok ? (
                    saldi && (x.ferie_residuo != null || x.perm_residuo != null) && (
                      <label className="cs-check"><input type="checkbox" checked={!!x.applica} onChange={(e) => aggiorna(i, { applica: e.target.checked })} />Aggiorna saldi</label>)
                  ) : (
                    <span className="cs-assegna">
                      <select className="cs-assegna-sel" value={x.employee_id ?? ''} onChange={(e) => aggiorna(i, { employee_id: Number(e.target.value) || null })} aria-label="Assegna dipendente">
                        <option value="">Assegna dipendente</option>
                        {dipendenti.map((p) => <option key={p.id} value={p.id}>{nome(p.cognome)} {nome(p.nome)}</option>)}
                      </select>
                      {x.senzaMese && (
                        <input className="cs-assegna-sel" type="month" aria-label="Mensilità"
                          onChange={(e) => aggiorna(i, { year: Number(e.target.value.slice(0, 4)) || null, month: Number(e.target.value.slice(5, 7)) || null })} />
                      )}
                    </span>
                  ))}
                </div>
              );
            })}
          </div>
        )}
        <div className="hr-form-azioni">
          <button type="button" className="cora-btn cora-btn-ghost" onClick={esci}>Annulla</button>
          <button type="button" className="cora-btn cora-btn-primary" disabled={!pronte.length || !!lavoro} onClick={conferma}>{logo}Conferma e carica ({pronte.length})</button>
        </div>
      </div>
    </Modale>
  );
}

/* ===================== Caricati dai dipendenti (Figma 982:11229) ===================== */

interface Caricato {
  chiave: string; gruppo: 'malattia' | 'assunzione'; titolo: string; caricato: string; dipendenteId: number | null; nome: string; cognome: string; cf: string;
  categoria: string; collegato: { tipo: string; da?: string; a?: string }; file: string; voce: string;
}

export function CaricatiConsulente({ cornice }: { cornice: CorniceHr }) {
  const [d] = useDati<{ documenti: Caricato[] }>('/consulente/caricati-dipendenti');
  const [cerca, setCerca] = useState('');
  const [gruppo, setGruppo] = useState('');
  const righe = useMemo(() => {
    const q = cerca.trim().toLowerCase();
    return (d?.documenti ?? []).filter((x) => (!gruppo || x.gruppo === gruppo)
      && (!q || [`${x.nome} ${x.cognome}`, `${x.cognome} ${x.nome}`, x.cf].some((v) => v.toLowerCase().includes(q))));
  }, [d, cerca, gruppo]);
  if (!d) return null;
  return (
    <SezioneHr cornice={cornice} pagina="documenti" titolo="Documenti" sottotitolo="Certificati di malattia e documenti dell’assunzione inviati dai dipendenti."
      schede={SCHEDE_DOCUMENTI} attiva="Caricati dai dipendenti">
      <div className="cora-card cs-doc-card">
        <div className="cs-doc-filtri">
          <label className="cora-search cs-cerca cs-cerca-grigia"><S n="ic-search.svg" /><input type="search" placeholder="Cerca per dipendente o codice fiscale…" value={cerca} onChange={(e) => setCerca(e.target.value)} /></label>
          <div className="cs-pillole" role="tablist">
            {[['', 'Tutti'], ['malattia', 'Malattia'], ['assunzione', 'Assunzione']].map(([k, t]) => (
              <button key={k} type="button" role="tab" aria-selected={gruppo === k} className={'cs-pill' + (gruppo === k ? ' is-active' : '')} onClick={() => setGruppo(k)}>{t}</button>
            ))}
          </div>
        </div>
        <div className="cs-doc-tab" role="table" aria-label="Documenti caricati dai dipendenti">
          <div className="cs-doc-riga cs-intestazione" role="row">
            <span role="columnheader">Documento</span><span role="columnheader">Caricato il</span><span role="columnheader">Dipendente</span>
            <span role="columnheader">Categoria</span><span role="columnheader">Collegato a</span><span />
          </div>
          {!righe.length && <div className="hr-vuoto">Nessun documento dai dipendenti</div>}
          {righe.map((x) => (
            <div key={x.chiave} className="cs-doc-riga" role="row">
              <span className="cs-doc-nome" role="cell"><span className="cs-doc-ic"><S n="ic-file-orange.svg" /></span>{x.titolo}</span>
              <span className="cs-doc-data" role="cell">{x.caricato ? dataOra(x.caricato) : '—'}</span>
              <span className="cs-doc-dip" role="cell">{nome(x.cognome)} {nome(x.nome)}</span>
              <span className="cs-doc-periodo" role="cell">{x.categoria}</span>
              <span className="cs-doc-letto" role="cell">{x.collegato.tipo === 'malattia' && x.collegato.da && x.collegato.a ? `Malattia ${intervallo(x.collegato.da, x.collegato.a)}` : 'Assunzione'}</span>
              <MenuRiga voci={[
                { etichetta: 'Scarica', icona: 'ic-down.svg', href: x.file },
                { etichetta: 'Vai alla voce collegata', icona: 'ic-link.svg', href: x.voce },
                ...(x.dipendenteId ? [{ etichetta: 'Scrivi al dipendente', icona: 'ic-chat.svg', azione: () => scriviA(x.dipendenteId!) }] : []),
              ]} />
            </div>
          ))}
        </div>
      </div>
    </SezioneHr>
  );
}
