import { useMemo, useState } from 'react';
import { dataBreve, nome } from '../../cora/formato';
import type { CorniceHr } from '../../cora/HrShell';
import { svgCora } from '../../cora/svg';
import { api } from '../../services/api';
import { azione, MenuRiga, Modale, S, SezioneHr, toast, useDati, Volto } from './comune';

/** Ferie e permessi dell'HR (Figma 920:7415). */
interface Richiesta {
  id: number; tipo: string; etichetta: string; stato: string; da: string; a: string; giornata: boolean; oraDa: string | null; oraA: string | null;
  durata: string; inviata: string; allegato: boolean; certificato: boolean; documentiMancanti: boolean;
  dipendente: { id: number; nome: string; cognome: string; email: string; foto: string; repartoId: number | null };
}
interface DatiFerie {
  richieste: Richiesta[]; reparti: Array<{ id: number; name: string }>; dipendenti: Array<{ id: number; nome: string; cognome: string }>;
  tipi: Array<{ valore: string; etichetta: string }>;
}
const STATI: Record<string, string> = { pending: 'In attesa', approved: 'Approvata', rejected: 'Rifiutata', cancelled: 'Annullata' };
const TONO: Record<string, string> = { pending: 'attesa', approved: 'ok', rejected: 'errore', cancelled: 'neutro' };

/** "20 ott → 26 ott 2026", "22 ott 2026 · 14:00 – 18:00" */
function periodo(r: Richiesta) {
  if (!r.giornata && r.oraDa && r.oraA) return `${dataBreve(r.da)} · ${r.oraDa} – ${r.oraA}`;
  return r.da === r.a ? dataBreve(r.da) : `${dataBreve(r.da, false)} → ${dataBreve(r.a)}`;
}
/** "02/10 · 17:08" */
function inviata(iso: string) {
  const p = Object.fromEntries(new Intl.DateTimeFormat('it-IT', { timeZone: 'Europe/Rome', day: '2-digit', month: '2-digit', hour: '2-digit', minute: '2-digit', hourCycle: 'h23' })
    .formatToParts(new Date(iso)).map((x) => [x.type, x.value]));
  return `${p.day}/${p.month} · ${p.hour}:${p.minute}`;
}

export function FerieHr({ cornice }: { cornice: CorniceHr }) {
  const [d, ricarica] = useDati<DatiFerie>('/hr/ferie');
  const [filtro, setFiltro] = useState('tutte');
  const [reparto, setReparto] = useState('');
  const [nuova, setNuova] = useState(false);
  const [lavoro, setLavoro] = useState<number | null>(null);
  const inAttesa = useMemo(() => (d?.richieste ?? []).filter((r) => r.stato === 'pending'), [d]);
  const elenco = useMemo(() => (d?.richieste ?? []).filter((r) => {
    if (filtro === 'pending' && r.stato !== 'pending') return false;
    if (filtro === 'approved' && r.stato !== 'approved') return false;
    if (filtro === 'rejected' && r.stato !== 'rejected') return false;
    return !reparto || String(r.dipendente.repartoId ?? '') === reparto;
  }), [d, filtro, reparto]);
  if (!d) return null;

  const decidi = async (r: Richiesta, cosa: 'approva' | 'rifiuta') => {
    let corpo = {};
    if (cosa === 'rifiuta') {
      const motivo = window.prompt('Motivo del rifiuto (facoltativo)');
      if (motivo === null) return;
      corpo = { motivo };
    }
    setLavoro(r.id);
    try {
      await api.post(`/hr/richieste/${r.id}/${cosa}`, corpo);
      toast(cosa === 'approva' ? 'Richiesta approvata' : 'Richiesta rifiutata');
      await ricarica();
    } catch (e: any) {
      toast('Operazione non riuscita', e?.response?.data?.message ?? '', 'error');
    } finally {
      setLavoro(null);
    }
  };
  const elimina = async (r: Richiesta) => {
    if (!window.confirm(`Eliminare la richiesta di ${nome(r.dipendente.nome)} ${nome(r.dipendente.cognome)}?`)) return;
    const err = await azione('admin/leave-requests', { action: 'admin_delete', request_id: r.id });
    if (err) toast('Richiesta non eliminata', err, 'error'); else { toast('Richiesta eliminata'); await ricarica(); }
  };
  const menu = (r: Richiesta) => [
    ...(r.allegato ? [{ etichetta: 'Scarica allegato', href: `/api/v1/pagine/admin/leave-requests?download_attachment=${r.id}` }] : []),
    ...(r.certificato ? [{ etichetta: 'Scarica certificato', href: `/api/v1/pagine/admin/leave-requests?download_cert=${r.id}` }] : []),
    { etichetta: r.tipo === 'malattia' ? 'Modifica o carica documenti' : 'Modifica', href: '/admin/leave-requests?classica=1' },
    { etichetta: 'Elimina', azione: () => elimina(r), pericolo: true },
  ];
  const riga = (r: Richiesta, conAzioni: boolean) => (
    <div key={r.id} className="hr-riga">
      <span className="hr-persona"><Volto p={r.dipendente} /><span><strong>{nome(r.dipendente.nome)} {nome(r.dipendente.cognome)}</strong><small>{r.dipendente.email}</small></span></span>
      <span className={`hr-tipo is-${r.tipo}`}>{r.etichetta}</span>
      <span>{periodo(r)}</span>
      <span className="hr-forte">{r.durata}</span>
      <span>
        <span className={`hr-badge is-${TONO[r.stato] ?? 'neutro'}`}><i />{STATI[r.stato] ?? r.stato}</span>
        {r.documentiMancanti && <span className="hr-nota-riga">Documenti mancanti</span>}
      </span>
      <span className="hr-tenue">{inviata(r.inviata)}</span>
      <MenuRiga voci={menu(r)} />
      {conAzioni ? (
        <span className="hr-richiesta-azioni">
          <button type="button" className="hr-azione" aria-label="Rifiuta" disabled={lavoro === r.id} onClick={() => decidi(r, 'rifiuta')}><S n="ic-rifiuta.svg" /></button>
          <button type="button" className="hr-azione" aria-label="Approva" disabled={lavoro === r.id} onClick={() => decidi(r, 'approva')}><S n="ic-approva.svg" /></button>
        </span>
      ) : <span />}
    </div>
  );
  const conta = (s: string) => d.richieste.filter((r) => r.stato === s).length;

  return (
    <SezioneHr cornice={cornice} pagina="ferie" titolo="Ferie e permessi" sottotitolo="Approva o rifiuta le richieste del team e crea richieste per conto dei dipendenti.">
      <div className="cora-card hr-elenco">
        <div className="hr-card-testa">
          <h2>Da approvare</h2>
          {inAttesa.length > 0 && <span className="cora-tab-count">{inAttesa.length}</span>}
          <span className="hr-spazio" />
          <span className="hr-tenue">Le richieste approvate entrano nel calcolo delle presenze e negli export del consulente</span>
        </div>
        {!inAttesa.length ? <div className="hr-vuoto">Nessuna richiesta in attesa</div> : (
          <div className="hr-tabella hr-tab-ferie">{inAttesa.map((r) => riga(r, true))}</div>
        )}
      </div>
      <div className="cora-card hr-elenco">
        <div className="hr-toolbar">
          <div className="hr-filtri">
            {([['tutte', 'Tutte'], ['pending', 'In attesa'], ['approved', 'Approvate'], ['rejected', 'Rifiutate']] as const).map(([k, t]) => (
              <button key={k} type="button" className={'cora-tab' + (filtro === k ? ' is-active' : '')} onClick={() => setFiltro(k)}>
                {t}{k === 'pending' && conta('pending') > 0 && <span className="cora-tab-count">{conta('pending')}</span>}
              </button>
            ))}
          </div>
          <span className="hr-spazio" />
          <select className="hr-select" value={reparto} onChange={(e) => setReparto(e.target.value)} aria-label="Reparto">
            <option value="">Tutti i reparti</option>
            {d.reparti.map((r) => <option key={r.id} value={r.id}>{r.name}</option>)}
          </select>
          <button type="button" className="cora-btn cora-btn-primary" onClick={() => setNuova(true)}>
            <span className="hr-btn-logo" dangerouslySetInnerHTML={{ __html: svgCora('logo-cora-white-20.svg') }} />Crea richiesta</button>
        </div>
        <div className="hr-tabella hr-tab-ferie">
          <div className="hr-riga hr-intestazione"><span>Dipendente</span><span>Tipo</span><span>Periodo</span><span>Durata</span><span>Stato</span><span>Inviata</span><span /><span /></div>
          {!elenco.length && <div className="hr-vuoto">Nessuna richiesta</div>}
          {elenco.map((r) => riga(r, false))}
        </div>
      </div>
      <NuovaRichiesta aperta={nuova} chiudi={() => setNuova(false)} d={d} creata={ricarica} />
    </SezioneHr>
  );
}

/** Richiesta creata dall'HR per uno o piu' dipendenti (admin_create di admin/leave-requests.php). */
function NuovaRichiesta({ aperta, chiudi, d, creata }: { aperta: boolean; chiudi: () => void; d: DatiFerie; creata: () => Promise<void> }) {
  const [tutti, setTutti] = useState(false);
  const [intera, setIntera] = useState(true);
  const [errore, setErrore] = useState('');
  const [invio, setInvio] = useState(false);
  const invia = async (e: React.FormEvent<HTMLFormElement>) => {
    e.preventDefault();
    const f = new FormData(e.currentTarget);
    const campi: Record<string, string> = { action: 'admin_create' };
    f.forEach((v, k) => { campi[k] = String(v); });
    if (!tutti) delete campi.target_department_id;
    setInvio(true);
    const err = await azione('admin/leave-requests', campi);
    setInvio(false);
    if (err) { setErrore(err); return; }
    toast('Richiesta creata');
    setErrore('');
    chiudi();
    await creata();
  };
  return (
    <Modale aperta={aperta} chiudi={chiudi} titolo="Crea richiesta" sottotitolo="La richiesta creata dall'HR risulta già approvata.">
      <form className="hr-form" onSubmit={invia}>
        <label className="hr-check"><input type="checkbox" name="apply_all" value="1" checked={tutti} onChange={(e) => setTutti(e.target.checked)} />Applica a tutti i dipendenti attivi</label>
        {tutti ? (
          <label className="cora-field"><span className="cora-label">Solo un reparto (facoltativo)</span>
            <select className="cora-input" name="target_department_id" defaultValue=""><option value="">Tutti i reparti</option>
              {d.reparti.map((r) => <option key={r.id} value={r.id}>{r.name}</option>)}</select></label>
        ) : (
          <label className="cora-field"><span className="cora-label">Dipendente<span className="req">*</span></span>
            <select className="cora-input" name="employee_id" required defaultValue=""><option value="" disabled>Scegli un dipendente</option>
              {d.dipendenti.map((p) => <option key={p.id} value={p.id}>{nome(p.cognome)} {nome(p.nome)}</option>)}</select></label>
        )}
        <label className="cora-field"><span className="cora-label">Tipo<span className="req">*</span></span>
          <select className="cora-input" name="leave_type" required defaultValue="ferie">
            {d.tipi.map((t) => <option key={t.valore} value={t.valore}>{t.etichetta}</option>)}</select></label>
        <div className="cora-grid-2">
          <label className="cora-field"><span className="cora-label">Dal<span className="req">*</span></span><input className="cora-input" type="date" name="start_date" required /></label>
          <label className="cora-field"><span className="cora-label">Al</span><input className="cora-input" type="date" name="end_date" /></label>
        </div>
        <label className="hr-check"><input type="checkbox" name="is_full_day" value="1" checked={intera} onChange={(e) => setIntera(e.target.checked)} />Giornata intera</label>
        {!intera && (
          <div className="cora-grid-2">
            <label className="cora-field"><span className="cora-label">Dalle</span><input className="cora-input" type="time" name="start_time" required /></label>
            <label className="cora-field"><span className="cora-label">Alle</span><input className="cora-input" type="time" name="end_time" required /></label>
          </div>
        )}
        <label className="cora-field"><span className="cora-label">Motivo (facoltativo)</span><input className="cora-input" name="reason" maxLength={500} /></label>
        <label className="cora-field"><span className="cora-label">Note (facoltative)</span><textarea className="cora-input cora-textarea" name="notes" /></label>
        {errore && <p className="hr-errore">{errore}</p>}
        <div className="hr-form-azioni">
          <button type="button" className="cora-btn cora-btn-ghost" onClick={chiudi}>Annulla</button>
          <button type="submit" className="cora-btn cora-btn-primary" disabled={invio}>
            <span className="hr-btn-logo" dangerouslySetInnerHTML={{ __html: svgCora('logo-cora-white-20.svg') }} />Crea richiesta</button>
        </div>
      </form>
    </Modale>
  );
}
