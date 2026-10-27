import { useEffect, useMemo, useRef, useState } from 'react';
import { dataBreve } from '../../cora/formato';
import type { CorniceHr } from '../../cora/HrShell';
import { svgCora } from '../../cora/svg';
import { api } from '../../services/api';
import { azione, MenuRiga, Modale, S, SezioneHr, toast, useDati } from './comune';

/** Archivio dell'HR: comunicazioni e documenti aziendali (Figma 920:7762, 920:8094, 920:8497). */
const SCHEDE: Array<[string, string]> = [['Comunicazioni', '/admin/communications'], ['Documenti', '/admin/documenti-aziendali']];
const SOTTOTITOLO = 'Comunicazioni e documenti condivisi con i dipendenti, con letture e scadenze.';
const logo = <span className="hr-btn-logo" dangerouslySetInnerHTML={{ __html: svgCora('logo-cora-white-20.svg') }} />;
const giorno = (ymd: string | null) => (ymd ? `${ymd.slice(8, 10)}/${ymd.slice(5, 7)}/${ymd.slice(0, 4)}` : '—');

/* ===================== Comunicazioni ===================== */

interface Comunicazione {
  id: number; titolo: string; priorita: string; pubblicazione: string | null; scadenza: string | null;
  stato: 'bozza' | 'scaduta' | 'programmata' | 'pubblicata'; letture: number; destinatari: number; reparto: string;
}
const PRIORITA: Record<string, [string, string]> = { high: ['Alta', 'errore'], normal: ['Normale', 'attesa'], low: ['Bassa', 'neutro'] };
const STATO_COM: Record<string, [string, string]> = {
  pubblicata: ['Pubblicata', 'ok'], programmata: ['Programmata', 'attesa'], scaduta: ['Scaduta', 'neutro'], bozza: ['Bozza', 'attesa'],
};

export function ComunicazioniHr({ cornice }: { cornice: CorniceHr }) {
  const [d, ricarica] = useDati<Comunicazione[]>('/hr/comunicazioni');
  const [cerca, setCerca] = useState('');
  const [priorita, setPriorita] = useState('');
  const [stato, setStato] = useState('');
  const righe = useMemo(() => (d ?? []).filter((c) => (!cerca.trim() || c.titolo.toLowerCase().includes(cerca.trim().toLowerCase()))
    && (!priorita || c.priorita === priorita) && (!stato || c.stato === stato)), [d, cerca, priorita, stato]);
  if (!d) return null;
  const esegui = async (c: Comunicazione, action: 'toggle_publish' | 'delete') => {
    if (action === 'delete' && !window.confirm(`Eliminare la comunicazione "${c.titolo}"?`)) return;
    const err = await azione(`admin/communications?id=${c.id}`, { action });
    if (err) toast('Operazione non riuscita', err, 'error');
    else { toast(action === 'delete' ? 'Comunicazione eliminata' : 'Comunicazione aggiornata'); await ricarica(); }
  };
  return (
    <SezioneHr cornice={cornice} pagina="archivio" titolo="Archivio" sottotitolo={SOTTOTITOLO} schede={SCHEDE} attiva="Comunicazioni"
      azioni={<a className="cora-btn cora-btn-primary" href="/admin/communications?action=new">{logo}Nuova comunicazione</a>}>
      <div className="cora-card hr-elenco">
        <div className="hr-toolbar">
          <label className="cora-search hr-cerca"><S n="ic-search.svg" /><input type="search" placeholder="Cerca per titolo…" value={cerca} onChange={(e) => setCerca(e.target.value)} /></label>
          <select className="hr-select" value={priorita} onChange={(e) => setPriorita(e.target.value)} aria-label="Priorità">
            <option value="">Tutte le priorità</option><option value="high">Alta</option><option value="normal">Normale</option><option value="low">Bassa</option>
          </select>
          <select className="hr-select" value={stato} onChange={(e) => setStato(e.target.value)} aria-label="Stato">
            <option value="">Tutti gli stati</option>
            {Object.entries(STATO_COM).map(([k, [t]]) => <option key={k} value={k}>{t}</option>)}
          </select>
        </div>
        <div className="hr-tabella hr-tab-comunicazioni">
          <div className="hr-riga hr-intestazione"><span>Titolo</span><span>Priorità</span><span>Pubblicazione</span><span>Scadenza</span><span>Letture</span><span>Stato</span><span /></div>
          {!righe.length && <div className="hr-vuoto">Nessuna comunicazione</div>}
          {righe.map((c) => {
            const pct = c.destinatari ? Math.round((c.letture / c.destinatari) * 100) : 0;
            const [pt, pc] = PRIORITA[c.priorita] ?? [c.priorita, 'neutro'];
            const [st, sc] = STATO_COM[c.stato];
            return (
              <div key={c.id} className="hr-riga">
                <a className="hr-persona" href={`/admin/communications?action=view&id=${c.id}`}>
                  <span className="hr-icona-riga"><S n="ic-mega.svg" /></span>
                  <span><strong>{c.titolo}</strong>{c.reparto && <small>Reparto {c.reparto}</small>}</span>
                </a>
                <span><span className={`hr-badge is-${pc}`}><i />{pt}</span></span>
                <span>{giorno(c.pubblicazione)}</span>
                <span>{giorno(c.scadenza)}</span>
                <span className="hr-letture">
                  <small>{c.stato === 'bozza' ? '—' : `${c.letture}/${c.destinatari} · ${pct}%`}</small>
                  <span className="hr-barra"><i style={{ width: `${c.stato === 'bozza' ? 0 : pct}%` }} /></span>
                </span>
                <span><span className={`hr-badge is-${sc}`}><i />{st}</span></span>
                <MenuRiga voci={[
                  { etichetta: 'Apri con le letture', href: `/admin/communications?action=view&id=${c.id}` },
                  { etichetta: 'Modifica', href: `/admin/communications?action=edit&id=${c.id}` },
                  { etichetta: c.stato === 'bozza' ? 'Pubblica' : 'Riporta in bozza', azione: () => esegui(c, 'toggle_publish') },
                  { etichetta: 'Elimina', azione: () => esegui(c, 'delete'), pericolo: true },
                ]} />
              </div>
            );
          })}
        </div>
      </div>
    </SezioneHr>
  );
}

/* ===================== Documenti aziendali ===================== */

interface Documento {
  gruppo: string; nome: string; file: string; caricato: string; caricatoDa: string; destinatari: number; scaricati: number;
  scadenza: string | null; visibile: boolean; notificheInCoda: number; campione: number;
}

/** Notifiche della distribuzione smaltite a blocchi (come lo script di documenti-aziendali.php). */
async function inviaNotifiche(gruppo: string) {
  for (let i = 0; i < 500; i++) {
    const fd = new FormData();
    fd.append('action', 'notify_batch');
    fd.append('bulk_group', gruppo);
    fd.append('limit', '4');
    const r = await api.post('/pagine/admin/documenti-aziendali', fd);
    if (!r.data?.success || !r.data.remaining) return;
  }
}

export function DocumentiHr({ cornice }: { cornice: CorniceHr }) {
  const [d, ricarica] = useDati<{ attivi: number; documenti: Documento[] }>('/hr/documenti-aziendali');
  const [cerca, setCerca] = useState('');
  const [anno, setAnno] = useState('');
  const [nuovo, setNuovo] = useState(false);
  const anni = useMemo(() => [...new Set((d?.documenti ?? []).map((x) => x.caricato.slice(0, 4)))].sort().reverse(), [d]);
  // Notifiche rimaste in coda (pagina chiusa durante l'invio): si riprendono, come nella pagina classica.
  const ripresa = useRef(false);
  useEffect(() => {
    const sospesi = (d?.documenti ?? []).filter((x) => x.notificheInCoda > 0);
    if (ripresa.current || !sospesi.length) return;
    ripresa.current = true;
    (async () => { for (const x of sospesi) await inviaNotifiche(x.gruppo); await ricarica(); })().catch(() => undefined);
  }, [d, ricarica]);
  if (!d) return null;
  const righe = d.documenti.filter((x) => (!cerca.trim() || `${x.nome} ${x.file}`.toLowerCase().includes(cerca.trim().toLowerCase())) && (!anno || x.caricato.startsWith(anno)));
  const elimina = async (x: Documento) => {
    if (!window.confirm(`Eliminare "${x.nome}" per tutti i ${x.destinatari} dipendenti?`)) return;
    const err = await azione('admin/documenti-aziendali', { action: 'bulk_delete', bulk_group: x.gruppo });
    if (err) toast('Documento non eliminato', err, 'error'); else { toast('Distribuzione eliminata'); await ricarica(); }
  };
  return (
    <SezioneHr cornice={cornice} pagina="archivio" titolo="Archivio" sottotitolo={SOTTOTITOLO} schede={SCHEDE} attiva="Documenti"
      azioni={<button type="button" className="cora-btn cora-btn-primary" onClick={() => setNuovo(true)}>{logo}Carica documento</button>}>
      <div className="hr-avviso"><S n="ic-info.svg" />Carica un documento una sola volta: viene agganciato a tutti i {d.attivi} dipendenti attivi, compare nella loro area personale e ognuno riceve la notifica.</div>
      <div className="cora-card hr-elenco">
        {!d.documenti.length ? (
          <div className="hr-vuoto-grande">
            <span className="cora-empty-ic"><S n="ic-documento.svg" /></span>
            <h3>Nessun documento aziendale</h3>
            <p>Carica regolamenti, policy o calendari: arriveranno a tutti i dipendenti attivi.</p>
            <button type="button" className="cora-btn cora-btn-primary" onClick={() => setNuovo(true)}>{logo}Carica documento</button>
          </div>
        ) : (
          <>
            <div className="hr-toolbar">
              <label className="cora-search hr-cerca"><S n="ic-search.svg" /><input type="search" placeholder="Cerca un documento…" value={cerca} onChange={(e) => setCerca(e.target.value)} /></label>
              <select className="hr-select" value={anno} onChange={(e) => setAnno(e.target.value)} aria-label="Anno">
                <option value="">Tutti gli anni</option>{anni.map((a) => <option key={a} value={a}>{a}</option>)}
              </select>
            </div>
            <div className="hr-tabella hr-tab-documenti">
              <div className="hr-riga hr-intestazione"><span>Documento</span><span>Caricato il</span><span>Destinatari</span><span>Scaricato da</span><span>Scadenza</span><span /></div>
              {!righe.length && <div className="hr-vuoto">Nessun documento trovato</div>}
              {righe.map((x) => (
                <div key={x.gruppo} className="hr-riga">
                  <span className="hr-persona"><span className="hr-icona-riga is-file"><S n="ic-file-orange.svg" /></span><span><strong>{x.nome}</strong><small>{x.file}</small></span></span>
                  <span>{new Intl.DateTimeFormat('it-IT', { timeZone: 'Europe/Rome', day: '2-digit', month: '2-digit', year: 'numeric', hour: '2-digit', minute: '2-digit' }).format(new Date(x.caricato)).replace(',', ' ·')}</span>
                  <span className="hr-forte">{x.destinatari} dipendent{x.destinatari === 1 ? 'e' : 'i'}</span>
                  <span className="hr-forte">{x.scaricati} / {x.destinatari}</span>
                  <span>{x.scadenza ? dataBreve(x.scadenza) : '—'}</span>
                  <MenuRiga voci={[
                    { etichetta: 'Scarica', href: `/api/v1/pagine/admin/documenti-aziendali?download=${x.campione}` },
                    { etichetta: 'Elimina per tutti', azione: () => elimina(x), pericolo: true },
                  ]} />
                </div>
              ))}
            </div>
          </>
        )}
      </div>
      <NuovoDocumento aperta={nuovo} chiudi={() => setNuovo(false)} attivi={d.attivi} caricato={ricarica} />
    </SezioneHr>
  );
}

const ERRORI_CARICAMENTO: Record<string, string> = {
  no_file: 'Seleziona un file da caricare', estensione: 'Tipo di file non ammesso', contenuto: 'Il contenuto del file non corrisponde al tipo',
  dimensione: 'File troppo grande (massimo 20 MB)', salvataggio: 'Errore durante il caricamento',
};

function NuovoDocumento({ aperta, chiudi, attivi, caricato }: { aperta: boolean; chiudi: () => void; attivi: number; caricato: () => Promise<void> }) {
  const [file, setFile] = useState<File | null>(null);
  const [errore, setErrore] = useState('');
  const [invio, setInvio] = useState(false);
  const input = useRef<HTMLInputElement>(null);
  const invia = async (e: React.FormEvent<HTMLFormElement>) => {
    e.preventDefault();
    if (!file) { setErrore('Seleziona un file da caricare'); return; }
    const f = new FormData(e.currentTarget);
    f.set('action', 'bulk_upload');
    f.set('document', file);
    setInvio(true);
    try {
      const r = await api.post('/pagine/admin/documenti-aziendali', f);
      const dove = new URL(String(r.data?.redirect ?? ''), location.origin);
      const err = dove.searchParams.get('err');
      if (err) { setErrore(ERRORI_CARICAMENTO[err] ?? 'Caricamento non riuscito'); return; }
      const gruppo = dove.searchParams.get('uploaded');
      toast('Documento caricato', `Distribuito a ${dove.searchParams.get('n') ?? attivi} dipendenti, invio delle notifiche in corso`);
      setErrore('');
      setFile(null);
      chiudi();
      await caricato();
      if (gruppo) { await inviaNotifiche(gruppo); await caricato(); }
    } catch (e: any) {
      setErrore(e?.response?.data?.message ?? 'Caricamento non riuscito');
    } finally {
      setInvio(false);
    }
  };
  return (
    <Modale aperta={aperta} chiudi={chiudi} titolo="Nuovo documento" sottotitolo="Il documento comparirà nell'area personale dei destinatari.">
      <form className="hr-form" onSubmit={invia}>
        <label className="cora-field"><span className="cora-label">Nome documento<span className="req">*</span></span>
          <input className="cora-input" name="name" required maxLength={255} placeholder="Es. Regolamento aziendale 2026" autoFocus /></label>
        <div className="cora-field"><span className="cora-label">File<span className="req">*</span></span>
          <button type="button" className={'hr-drop' + (file ? ' has-file' : '')} onClick={() => input.current?.click()}
            onDragOver={(e) => e.preventDefault()} onDrop={(e) => { e.preventDefault(); const f = e.dataTransfer.files[0]; if (f) setFile(f); }}>
            <span className="hr-drop-ic"><S n="ic-up.svg" /></span>
            {file ? <strong>{file.name}</strong> : <span>Trascina qui il file oppure <b>sfoglia</b></span>}
            <small>PDF, JPG, PNG, DOC, XLS, CSV, TXT · max 20 MB</small>
          </button>
          <input ref={input} type="file" hidden accept=".pdf,.jpg,.jpeg,.png,.doc,.docx,.xls,.xlsx,.csv,.txt" onChange={(e) => setFile(e.target.files?.[0] ?? null)} />
        </div>
        <div className="cora-grid-2">
          <label className="cora-field"><span className="cora-label">Scadenza (facoltativa)</span><input className="cora-input" type="date" name="expires_on" /></label>
          <label className="cora-field"><span className="cora-label">Destinatari</span><input className="cora-input" readOnly value={`Tutti i dipendenti (${attivi})`} /></label>
        </div>
        <label className="hr-check"><input type="checkbox" name="send_email" value="1" defaultChecked />Invia anche un'email di avviso, oltre alla notifica in app</label>
        <p className="hr-nota"><S n="ic-info.svg" />I dipendenti assunti dopo la distribuzione non riceveranno questo documento automaticamente.</p>
        {errore && <p className="hr-errore">{errore}</p>}
        <div className="hr-form-azioni">
          <button type="button" className="cora-btn cora-btn-ghost" onClick={chiudi}>Annulla</button>
          <button type="submit" className="cora-btn cora-btn-primary" disabled={invio}>{logo}{invio ? 'Caricamento…' : 'Carica e distribuisci'}</button>
        </div>
      </form>
    </Modale>
  );
}
