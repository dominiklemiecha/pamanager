import { useEffect, useMemo, useRef, useState, type ReactNode } from 'react';
import { useLocation } from 'react-router-dom';
import { dataBreve, iniziali, nome as nomeProprio } from '../../cora/formato';
import type { CorniceHr } from '../../cora/HrShell';
import { DataCora, OraCora, TendinaCora } from '../../cora/Selettori';
import { inviaPagina, SCHEDE_DIPENDENTI, SezioneHr, toast, useDati } from './comune';
import './schede.css';

/*
 * Vista Cora HR di due pagine che prima erano solo classiche:
 *  - modifica avanzata del dipendente (/admin/employees?action=edit&id=..)
 *  - nuova richiesta di assunzione (/admin/hire-requests?action=new|edit)
 * Leggono e salvano con le stesse azioni delle pagine classiche
 * (/pagine/admin/employees, /pagine/admin/hire-requests): stesse regole e controlli.
 */

const GIORNI: Array<[string, string]> = [['mon', 'L'], ['tue', 'M'], ['wed', 'M'], ['thu', 'G'], ['fri', 'V'], ['sat', 'S'], ['sun', 'D']];
const NOMI_GIORNI: Record<string, string> = { mon: 'lun', tue: 'mar', wed: 'mer', thu: 'gio', fri: 'ven', sat: 'sab', sun: 'dom' };
const giorniTesto = (g: string[]) => (g.length ? g.map((x) => NOMI_GIORNI[x] ?? x).join(', ') : 'nessuno');
const csv = (v: unknown) => String(v ?? '').split(',').map((x) => x.trim()).filter(Boolean);
/** Numero dal database ("30500.00") come testo da modificare ("30500"). */
const numTesto = (v: unknown) => (v === null || v === undefined || v === '' ? '' : String(Number(v)).replace('.', ','));
const ora5 = (v: unknown) => (v ? String(v).slice(0, 5) : '');
const minuti = (o: string) => { const [h, m] = o.split(':').map(Number); return h * 60 + (m || 0); };
const oggi = () => new Date().toISOString().slice(0, 10);

function Campo({ etichetta, obbligatorio, modificato, aiuto, intera, children }: {
  etichetta: string; obbligatorio?: boolean; modificato?: boolean; aiuto?: ReactNode; intera?: boolean; children: ReactNode;
}) {
  return (
    <label className={'cora-field' + (modificato ? ' hs-mod' : '') + (intera ? ' hs-intera' : '')}>
      <span className="cora-label">{etichetta}{obbligatorio && <span className="req">*</span>}{modificato && <span className="hs-tag">modificato</span>}</span>
      {children}
      {aiuto && <span className="cora-hint">{aiuto}</span>}
    </label>
  );
}

const Interruttore = ({ on, cambia, etichetta }: { on: boolean; cambia: (v: boolean) => void; etichetta: string }) => (
  <button type="button" role="switch" aria-checked={on} aria-label={etichetta} className={'hs-interruttore' + (on ? ' is-on' : '')} onClick={() => cambia(!on)} />
);

function Giorni({ valore, cambia }: { valore: string[]; cambia: (v: string[]) => void }) {
  return (
    <div className="hs-giorni">
      {GIORNI.map(([k, t]) => (
        <button key={k} type="button" title={NOMI_GIORNI[k]} className={'hs-giorno' + (valore.includes(k) ? ' is-on' : '')}
          onClick={() => cambia(valore.includes(k) ? valore.filter((x) => x !== k) : GIORNI.map(([g]) => g).filter((g) => g === k || valore.includes(g)))}>{t}</button>
      ))}
    </div>
  );
}

const Icona = ({ d }: { d: string }) => <span className="hs-ic"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="1.8" strokeLinecap="round" strokeLinejoin="round"><path d={d} /></svg></span>;
const IC_PERSONA = 'M12 12a4 4 0 1 0 0-8 4 4 0 0 0 0 8zM4 21c1.5-4 4.5-6 8-6s6.5 2 8 6';
const IC_DOC = 'M7 3h7l5 5v13H7zM14 3v5h5M10 13h6M10 17h6';
const IC_ALLEGATI = 'M21 12l-8.5 8.5a5 5 0 0 1-7-7L14 5a3.5 3.5 0 0 1 5 5l-8.5 8.5a2 2 0 0 1-3-3L15 8';

/* ============================== Modifica avanzata dipendente ============================== */

interface Saldo { residual: number; total: number; used: number; entitled: number; carried: number }
interface DatiModifica {
  V: {
    employee: Record<string, any> | null; error?: string;
    departments: Array<{ id: number; name: string }>;
    __compDefaults: { days: string[]; hours: number; lunch_start: string; lunch_end: string };
    __ccnls: Array<{ id: number; name: string; ferie_days_year: number; permessi_hours_year: number }>;
    __bpCompDb: { buoni_pasto_enabled: boolean; buoni_pasto_min_hours_enabled: boolean; buoni_pasto_min_hours: number; buoni_pasto_sw_eligible: boolean; smart_working_days: string | null } | false;
    __saldi?: { ferie: Saldo; permesso: Saldo };
  };
}

/** Valori del modulo dal dipendente salvato (chiavi = campi inviati alla pagina). */
function valoriDipendente(e: Record<string, any>, s?: { ferie: Saldo; permesso: Saldo }): Record<string, string> {
  const t = (k: string) => (e[k] === null || e[k] === undefined ? '' : String(e[k]));
  const sw = e.buoni_pasto_sw_eligible_override;
  return {
    first_name: t('first_name'), last_name: t('last_name'), fiscal_code: t('fiscal_code'), birth_date: t('birth_date'), iban: t('iban'),
    email: t('email'), phone: t('phone'), address: t('address'), username: t('username'),
    position: t('position'), department_id: t('department_id'), ccnl_id: t('ccnl_id'), job_level: t('job_level'),
    ral_amount: numTesto(e.ral_amount), monthly_salary: numTesto(e.monthly_salary), hire_date: t('hire_date'), probation_end_date: t('probation_end_date'),
    part_time_percent: numTesto(e.part_time_percent), termination_date: t('termination_date'),
    ferie_year_override: numTesto(e.ferie_year_override), permessi_year_override: numTesto(e.permessi_year_override),
    working_days_override: e.working_days || e.hours_per_day !== null ? '1' : '', working_days: t('working_days'), hours_per_day: numTesto(e.hours_per_day),
    lunch_break_override: e.lunch_break_custom ? '1' : '', lunch_break_start: ora5(e.lunch_break_start), lunch_break_end: ora5(e.lunch_break_end),
    smart_working_override: e.smart_working_days !== null && e.smart_working_days !== undefined ? '1' : '', smart_working_days: t('smart_working_days'),
    buoni_pasto_excluded: e.buoni_pasto_excluded ? '1' : '', buoni_pasto_sw_eligible_override: sw === null || sw === undefined ? '' : sw ? '1' : '0',
    buoni_pasto_min_hours_override: numTesto(e.buoni_pasto_min_hours_override),
    matricola: t('matricola'), filiale: t('filiale'), nfc_uid: t('nfc_uid'),
    saldo_ferie: s ? numTesto(s.ferie.residual) : '', saldo_permesso: s ? numTesto(s.permesso.residual) : '',
  };
}

const SEZIONI: Array<[string, string, string[]]> = [
  ['anagrafica', 'Anagrafica', ['first_name', 'last_name', 'fiscal_code', 'birth_date', 'iban']],
  ['contatti', 'Contatti e residenza', ['email', 'phone', 'address']],
  ['contratto', 'Contratto', ['position', 'department_id', 'ccnl_id', 'job_level', 'ral_amount', 'monthly_salary', 'hire_date', 'probation_end_date', 'part_time_percent', 'termination_date']],
  ['orario', 'Orario e pausa', ['working_days_override', 'working_days', 'hours_per_day', 'lunch_break_override', 'lunch_break_start', 'lunch_break_end', 'smart_working_override', 'smart_working_days']],
  ['saldi', 'Ferie e permessi', ['saldo_ferie', 'saldo_permesso', 'ferie_year_override', 'permessi_year_override']],
  ['buoni', 'Buoni pasto', ['buoni_pasto_excluded', 'buoni_pasto_sw_eligible_override', 'buoni_pasto_min_hours_override']],
  ['accessi', 'Accessi e badge', ['username', 'nfc_uid']],
  ['teamsystem', 'TeamSystem', ['matricola', 'filiale']],
];
const CAMPI_SALDO = ['saldo_ferie', 'saldo_permesso'];

export function ModificaDipendenteHr({ cornice, id }: { cornice: CorniceHr; id: number }) {
  const [d, ricarica] = useDati<DatiModifica>(`/pagine/admin/employees?action=edit&id=${id}`);
  const e = d?.V.employee ?? null;
  const iniziali_ = useMemo(() => (e ? valoriDipendente(e, d?.V.__saldi) : null), [e, d]);
  const [v, setV] = useState<Record<string, string> | null>(null);
  const [errore, setErrore] = useState('');
  const [invio, setInvio] = useState(false);
  const [password, setPassword] = useState('');
  const [attiva, setAttiva] = useState('anagrafica');
  useEffect(() => { if (iniziali_) setV(iniziali_); }, [iniziali_]);

  if (!d) return null;
  if (!e || !v || !iniziali_) {
    return (
      <SezioneHr cornice={cornice} pagina="dipendenti" titolo="Dipendenti" sottotitolo="Modifica dati" schede={SCHEDE_DIPENDENTI} attiva="Anagrafica">
        <div className="cora-card hs-card"><div className="hs-errore">Dipendente non trovato.</div></div>
      </SezioneHr>
    );
  }
  const V = d.V;
  const cambiati = Object.keys(v).filter((k) => v[k] !== iniziali_[k]);
  const mod = (k: string) => cambiati.includes(k);
  const imposta = (k: string) => (x: string) => setV({ ...v, [k]: x });
  const testo = (k: string, extra: React.InputHTMLAttributes<HTMLInputElement> = {}) => (
    <input className="cora-input" value={v[k]} onChange={(ev) => setV({ ...v, [k]: ev.target.value })} {...extra} />
  );
  const comp = V.__compDefaults;
  const bp = V.__bpCompDb || null;
  const giorniLav = v.working_days_override ? csv(v.working_days) : comp.days;
  const smart = v.smart_working_override ? csv(v.smart_working_days) : csv(bp?.smart_working_days);
  const pausaDa = v.lunch_break_override ? v.lunch_break_start : comp.lunch_start;
  const pausaA = v.lunch_break_override ? v.lunch_break_end : comp.lunch_end;
  const nomeCompleto = `${nomeProprio(e.first_name)} ${nomeProprio(e.last_name)}`;
  const reparto = V.departments.find((r) => String(r.id) === String(e.department_id))?.name;
  const inProva = e.probation_end_date && String(e.probation_end_date) >= oggi() && !e.probation_decision;
  const mesi = e.hire_date ? Math.max(0, Math.round((Date.now() - new Date(`${e.hire_date}T12:00:00`).getTime()) / (30.44 * 864e5))) : null;
  const ccnl = V.__ccnls.find((c) => String(c.id) === v.ccnl_id);

  const vai = (s: string) => {
    setAttiva(s);
    document.getElementById(`hs-${s}`)?.scrollIntoView({ behavior: 'smooth', block: 'start' });
  };

  async function salva() {
    if (invio || !v || !e) return;
    if (v.lunch_break_override && (!!v.lunch_break_start !== !!v.lunch_break_end)) { setErrore('Pausa pranzo: indica sia l\'inizio sia la fine, oppure lasciale vuote per nessuna pausa.'); return; }
    setInvio(true); setErrore('');
    try {
      const fd = new FormData();
      fd.append('action', 'update');
      fd.append('id', String(id));
      if (e.is_active) fd.append('is_active', '1');
      const salta = new Set(['working_days', 'smart_working_days', 'buoni_pasto_excluded', ...CAMPI_SALDO]);
      for (const [k, x] of Object.entries(v)) if (!salta.has(k)) fd.append(k, x);
      csv(v.working_days).forEach((g) => fd.append('working_days[]', g));
      csv(v.smart_working_days).forEach((g) => fd.append('smart_working_days[]', g));
      if (v.buoni_pasto_excluded) fd.append('buoni_pasto_excluded', '1');
      const r = await inviaPagina('admin/employees', fd);
      if (!r.redirect) { setErrore(r.errore || 'Salvataggio non riuscito'); return; }
      // Residuo ad oggi di ferie e permessi: solo se cambiato (riparte la maturazione da oggi).
      const saldi = CAMPI_SALDO.filter((k) => mod(k) && v[k].trim() !== '');
      if (saldi.length) {
        const b = new FormData();
        b.append('action', 'update_balance_inline');
        b.append('id', String(id));
        b.append('year', oggi().slice(0, 4));
        for (const k of ['ccnl_id', 'ferie_year_override', 'permessi_year_override']) b.append(k, v[k]);
        if (saldi.includes('saldo_ferie')) b.append('balance[ferie][residual]', v.saldo_ferie);
        if (saldi.includes('saldo_permesso')) b.append('balance[permesso][residual]', v.saldo_permesso);
        const s = await inviaPagina('admin/employees', b);
        if (!s.redirect) { setErrore(s.errore || 'Dati salvati, ma non il residuo di ferie e permessi'); await ricarica(); return; }
      }
      toast('Modifiche salvate', nomeCompleto);
      await ricarica();
    } finally {
      setInvio(false);
    }
  }

  async function nuovaPassword() {
    if (!confirm(`Generare una nuova password temporanea per ${nomeCompleto}? Quella attuale smette di funzionare.`)) return;
    const r = await inviaPagina('admin/employees', { action: 'reset_password', id });
    if (r.V?.generatedPassword) setPassword(String(r.V.generatedPassword));
    else setErrore(r.errore || 'Password non reimpostata');
  }

  async function cambiaAttivo() {
    const msg = e!.is_active
      ? `Disattivare ${nomeCompleto}? Non potrà più accedere a Cora finché non lo riattivi.`
      : `Riattivare ${nomeCompleto}? Potrà di nuovo accedere a Cora.`;
    if (!confirm(msg)) return;
    const r = await inviaPagina('admin/employees', { action: 'toggle_active', id });
    if (!r.redirect) { setErrore(r.errore || 'Operazione non riuscita'); return; }
    toast(e!.is_active ? 'Dipendente disattivato' : 'Dipendente riattivato', nomeCompleto);
    await ricarica();
  }

  // Giornata tipo per mostrare la pausa (8:00 - 19:00)
  const pos = (o: string) => `${4 + ((minuti(o) - 480) / 660) * 92}%`;
  const pausaValida = pausaDa && pausaA && minuti(pausaA) > minuti(pausaDa);
  const esempio = 6 - (pausaValida ? Math.max(0, Math.min(minuti('15:00'), minuti(pausaA)) - Math.max(minuti('09:00'), minuti(pausaDa))) / 60 : 0);

  const s = V.__saldi;
  return (
    <SezioneHr cornice={cornice} pagina="dipendenti" titolo="Dipendenti" sottotitolo={`Modifica dati · ${nomeCompleto}`} schede={SCHEDE_DIPENDENTI} attiva="Anagrafica"
      azioni={<a className="cora-btn cora-btn-secondary" href={`/admin/employees?action=view&id=${id}`}>Vedi scheda</a>}>
      <div className="hs-colonna" style={{ paddingBottom: 90 }}>
        <div className="hs-testa">
          <span className="hs-volto">{e.photo_path ? <img src={'/' + String(e.photo_path).replace(/^\/+/, '')} alt="" /> : iniziali(e.first_name, e.last_name)}</span>
          <div className="hs-chi">
            <h2>{nomeCompleto}</h2>
            <p>{[e.position, reparto].filter(Boolean).join(' · ') || 'Mansione non indicata'}</p>
            <div>
              <span className={'hs-pill ' + (e.is_active ? 'is-ok' : 'is-neutro')}>{e.is_active ? 'Attivo' : 'Non attivo'}</span>
              {inProva && <span className="hs-pill is-attesa">In prova fino al {dataBreve(e.probation_end_date)}</span>}
              {ccnl && <span className="hs-pill is-neutro">{ccnl.name}</span>}
            </div>
          </div>
          <div className="hs-numeri">
            {mesi !== null && <div><span>In azienda da</span><b>{mesi >= 24 ? Math.floor(mesi / 12) : mesi}<small>{mesi >= 24 ? 'anni' : mesi === 1 ? 'mese' : 'mesi'}</small></b></div>}
            {s && <div><span>Ferie residue</span><b>{numTesto(s.ferie.residual)}<small>gg</small></b></div>}
            {s && <div><span>Permessi residui</span><b>{numTesto(s.permesso.residual)}<small>h</small></b></div>}
          </div>
        </div>

        <div className="hs-modifica">
          <nav className="hs-menu" aria-label="Sezioni">
            {SEZIONI.map(([k, t, campi]) => (
              <a key={k} href={`#hs-${k}`} className={attiva === k ? 'is-attiva' : ''} onClick={(ev) => { ev.preventDefault(); vai(k); }}>
                {t}{campi.some(mod) && <i aria-label="modificato" />}
              </a>
            ))}
          </nav>

          <div className="hs-colonna">
            {errore && <div className="hs-errore">{errore}</div>}

            <section id="hs-anagrafica" className="cora-card hs-card">
              <div className="hs-card-h"><div><h2>Anagrafica</h2><p>Dati personali come da documento</p></div></div>
              <div className="hs-griglia is-3">
                <Campo etichetta="Nome" obbligatorio modificato={mod('first_name')}>{testo('first_name', { maxLength: 50 })}</Campo>
                <Campo etichetta="Cognome" obbligatorio modificato={mod('last_name')}>{testo('last_name', { maxLength: 50 })}</Campo>
                <Campo etichetta="Codice fiscale" obbligatorio modificato={mod('fiscal_code')}>{testo('fiscal_code', { maxLength: 16, style: { textTransform: 'uppercase' } })}</Campo>
                <Campo etichetta="Data di nascita" modificato={mod('birth_date')}><DataCora valore={v.birth_date} cambia={imposta('birth_date')} etichetta="Data di nascita" /></Campo>
                <Campo etichetta="IBAN" modificato={mod('iban')} intera aiuto="Usato dal consulente per gli stipendi.">{testo('iban', { maxLength: 40 })}</Campo>
              </div>
            </section>

            <section id="hs-contatti" className="cora-card hs-card">
              <div className="hs-card-h"><div><h2>Contatti e residenza</h2></div></div>
              <div className="hs-griglia">
                <Campo etichetta="Email" obbligatorio modificato={mod('email')} aiuto="Serve per accedere e ricevere le comunicazioni">{testo('email', { type: 'email' })}</Campo>
                <Campo etichetta="Telefono" modificato={mod('phone')}>{testo('phone', { type: 'tel' })}</Campo>
                <Campo etichetta="Indirizzo di residenza" modificato={mod('address')} intera>{testo('address')}</Campo>
              </div>
            </section>

            <section id="hs-contratto" className="cora-card hs-card">
              <div className="hs-card-h"><div><h2>Contratto</h2><p>Mansione, inquadramento e date del rapporto</p></div></div>
              <div className="hs-griglia is-3">
                <Campo etichetta="Mansione" modificato={mod('position')}>{testo('position')}</Campo>
                <Campo etichetta="Reparto" modificato={mod('department_id')}>
                  <TendinaCora valore={v.department_id} cambia={imposta('department_id')} etichetta="Reparto"
                    opzioni={[{ valore: '', testo: 'Nessun reparto' }, ...V.departments.map((r) => ({ valore: String(r.id), testo: r.name }))]} /></Campo>
                <Campo etichetta="CCNL" modificato={mod('ccnl_id')}>
                  <TendinaCora valore={v.ccnl_id} cambia={imposta('ccnl_id')} etichetta="CCNL"
                    opzioni={[{ valore: '', testo: "Come da azienda" }, ...V.__ccnls.map((c) => ({ valore: String(c.id), testo: `${c.name} · ${Number(c.ferie_days_year)} gg / ${Number(c.permessi_hours_year)} h` }))]} /></Campo>
                <Campo etichetta="Livello" modificato={mod('job_level')}>{testo('job_level')}</Campo>
                <Campo etichetta="RAL (€)" modificato={mod('ral_amount')}>{testo('ral_amount', { inputMode: 'decimal' })}</Campo>
                <Campo etichetta="Lordo mensile (€)" modificato={mod('monthly_salary')}>{testo('monthly_salary', { inputMode: 'decimal' })}</Campo>
                <Campo etichetta="Data assunzione" modificato={mod('hire_date')}><DataCora valore={v.hire_date} cambia={imposta('hire_date')} etichetta="Data assunzione" /></Campo>
                <Campo etichetta="Fine periodo di prova" modificato={mod('probation_end_date')}><DataCora valore={v.probation_end_date} cambia={imposta('probation_end_date')} minimo={v.hire_date || undefined} etichetta="Fine periodo di prova" /></Campo>
                <Campo etichetta="Part-time (%)" modificato={mod('part_time_percent')} aiuto="Vuoto = tempo pieno">{testo('part_time_percent', { inputMode: 'decimal', placeholder: '100' })}</Campo>
                <Campo etichetta="Data di cessazione" modificato={mod('termination_date')}><DataCora valore={v.termination_date} cambia={imposta('termination_date')} minimo={v.hire_date || undefined} etichetta="Data di cessazione" /></Campo>
              </div>
            </section>

            <section id="hs-orario" className="cora-card hs-card">
              <div className="hs-card-h"><div><h2>Orario e pausa pranzo</h2><p>Se non personalizzi, valgono le impostazioni dell'azienda</p></div></div>
              <div className={'hs-eredita' + (v.working_days_override ? ' is-on' : '')}>
                <div><b>Giorni e ore</b> · azienda: {giorniTesto(comp.days)}, {String(comp.hours).replace('.', ',')} h al giorno</div>
                <span>{v.working_days_override ? 'Personalizzato' : 'Personalizza'}</span>
                <Interruttore etichetta="Personalizza giorni e ore" on={!!v.working_days_override}
                  cambia={(on) => setV({ ...v, working_days_override: on ? '1' : '', working_days: on ? (v.working_days || comp.days.join(',')) : '', hours_per_day: on ? v.hours_per_day : '' })} />
              </div>
              {!!v.working_days_override && (
                <div className="hs-griglia">
                  <Campo etichetta="Giorni lavorativi" modificato={mod('working_days')}><Giorni valore={giorniLav} cambia={(g) => setV({ ...v, working_days: g.join(',') })} /></Campo>
                  <Campo etichetta="Ore al giorno" modificato={mod('hours_per_day')}>{testo('hours_per_day', { inputMode: 'decimal', placeholder: String(comp.hours) })}</Campo>
                </div>
              )}

              <div className={'hs-eredita' + (v.lunch_break_override ? ' is-on' : '')}>
                <div><b>Pausa pranzo</b> · azienda: {comp.lunch_start && comp.lunch_end ? `${comp.lunch_start} – ${comp.lunch_end}` : 'nessuna pausa'}</div>
                <span>{v.lunch_break_override ? 'Personalizzata' : 'Personalizza'}</span>
                <Interruttore etichetta="Personalizza pausa pranzo" on={!!v.lunch_break_override}
                  cambia={(on) => setV({ ...v, lunch_break_override: on ? '1' : '', lunch_break_start: on ? (v.lunch_break_start || comp.lunch_start) : '', lunch_break_end: on ? (v.lunch_break_end || comp.lunch_end) : '' })} />
              </div>
              {!!v.lunch_break_override && (
                <div className="hs-orari">
                  <Campo etichetta="Dalle" modificato={mod('lunch_break_start')}><OraCora valore={v.lunch_break_start} cambia={imposta('lunch_break_start')} etichetta="Inizio pausa" /></Campo>
                  <Campo etichetta="Alle" modificato={mod('lunch_break_end')}><OraCora valore={v.lunch_break_end} cambia={imposta('lunch_break_end')} etichetta="Fine pausa" /></Campo>
                </div>
              )}
              {pausaValida ? (
                <>
                  <div className="hs-linea" aria-hidden="true">
                    <div className="is-lavoro" />
                    <div className="is-pausa" style={{ left: pos(pausaDa), width: `calc(${pos(pausaA)} - ${pos(pausaDa)})` }} />
                    <span style={{ left: '4%' }}>8:00</span><span style={{ left: pos(pausaDa) }}>{pausaDa}</span><span style={{ left: pos(pausaA) }}>{pausaA}</span><span style={{ left: '96%' }}>19:00</span>
                  </div>
                  <div className="hs-esempio">Esempio: un permesso dalle 9:00 alle 15:00 conta <b>{String(esempio).replace('.', ',')} ore</b>, perché la pausa {pausaDa} – {pausaA} non viene scalata.</div>
                </>
              ) : (
                <div className="hs-esempio">Nessuna pausa: i permessi a ore contano tutte le ore richieste.</div>
              )}

              <div className={'hs-eredita' + (v.smart_working_override ? ' is-on' : '')}>
                <div><b>Smart working</b> · azienda: {giorniTesto(csv(bp?.smart_working_days))}</div>
                <span>{v.smart_working_override ? 'Personalizzato' : 'Personalizza'}</span>
                <Interruttore etichetta="Personalizza smart working" on={!!v.smart_working_override}
                  cambia={(on) => setV({ ...v, smart_working_override: on ? '1' : '', smart_working_days: on ? (v.smart_working_days || bp?.smart_working_days || '') : '' })} />
              </div>
              {!!v.smart_working_override && (
                <Campo etichetta="Giorni di smart working" modificato={mod('smart_working_days')}><Giorni valore={smart} cambia={(g) => setV({ ...v, smart_working_days: g.join(',') })} /></Campo>
              )}
            </section>

            <section id="hs-saldi" className="cora-card hs-card">
              <div className="hs-card-h"><div><h2>Ferie e permessi {oggi().slice(0, 4)}</h2><p>Inserisci il residuo ad oggi: la maturazione mensile continua da sola</p></div></div>
              <div className="hs-saldi">
                {([['ferie', 'Ferie', 'giorni', 'saldo_ferie', 'ferie_year_override', ccnl?.ferie_days_year, 'gg', 'var(--cora-type-ferie)'],
                  ['permesso', 'Permessi', 'ore', 'saldo_permesso', 'permessi_year_override', ccnl?.permessi_hours_year, 'h', 'var(--cora-type-permesso)']] as const).map(([t, titolo, unita, campo, annuo, daCcnl, u, colore]) => {
                  const x = s?.[t];
                  return (
                    <div key={t} className="hs-saldo">
                      <div><b>{titolo}</b>{daCcnl !== undefined && <span className="hs-pill is-neutro">{Number(daCcnl)} {u}/anno da CCNL</span>}</div>
                      {x && <div className="hs-num">{numTesto(x.residual)}<small>{unita} residui</small></div>}
                      {x && <div className="hs-barra"><i style={{ width: `${x.total > 0 ? Math.min(100, (x.used / x.total) * 100) : 0}%`, background: colore }} /></div>}
                      {x && <div className="hs-mini"><div>Maturati<b>{numTesto(x.entitled)}</b></div><div>Usati<b>{numTesto(x.used)}</b></div><div>Riportati<b>{numTesto(x.carried)}</b></div></div>}
                      <Campo etichetta="Residuo ad oggi" modificato={mod(campo)}>{testo(campo, { inputMode: 'decimal' })}</Campo>
                      <Campo etichetta={`Diritto annuo (${u})`} modificato={mod(annuo)} aiuto="Vuoto = come da CCNL">{testo(annuo, { inputMode: 'decimal', placeholder: daCcnl !== undefined ? String(Number(daCcnl)) : '' })}</Campo>
                    </div>
                  );
                })}
              </div>
            </section>

            <section id="hs-buoni" className="cora-card hs-card">
              <div className="hs-card-h"><div><h2>Buoni pasto</h2>{bp && !bp.buoni_pasto_enabled && <p>I buoni pasto sono disattivati per l'azienda</p>}</div></div>
              <div>
                <div className="hs-riga"><div><b>Riceve i buoni pasto</b><small>Spegni per escludere questo dipendente</small></div>
                  <Interruttore etichetta="Riceve i buoni pasto" on={!v.buoni_pasto_excluded} cambia={(on) => setV({ ...v, buoni_pasto_excluded: on ? '' : '1' })} /></div>
                <div className="hs-riga"><div><b>Anche nei giorni di smart working</b><small>Azienda: {bp?.buoni_pasto_sw_eligible ? 'sì' : 'no'}</small></div>
                  <div style={{ width: 200, flex: 'none' }} className={mod('buoni_pasto_sw_eligible_override') ? 'hs-mod' : ''}>
                    <TendinaCora valore={v.buoni_pasto_sw_eligible_override} cambia={imposta('buoni_pasto_sw_eligible_override')} etichetta="Buoni in smart working"
                      opzioni={[{ valore: '', testo: 'Come da azienda' }, { valore: '1', testo: 'Sì' }, { valore: '0', testo: 'No' }]} /></div></div>
                <div className="hs-riga"><div><b>Ore minime per il buono</b><small>Azienda: {bp?.buoni_pasto_min_hours_enabled ? `${String(bp.buoni_pasto_min_hours).replace('.', ',')} h` : 'nessun minimo'}</small></div>
                  <div style={{ width: 120, flex: 'none' }} className={mod('buoni_pasto_min_hours_override') ? 'hs-mod' : ''}>{testo('buoni_pasto_min_hours_override', { inputMode: 'decimal', placeholder: bp ? String(bp.buoni_pasto_min_hours) : '' })}</div></div>
              </div>
            </section>

            <section id="hs-accessi" className="cora-card hs-card">
              <div className="hs-card-h"><div><h2>Accessi e badge</h2></div></div>
              <div className="hs-griglia">
                <Campo etichetta="Username" modificato={mod('username')} aiuto="Lettere, numeri, punti e underscore">{testo('username', { maxLength: 50, autoComplete: 'off' })}</Campo>
                <Campo etichetta="Badge NFC" modificato={mod('nfc_uid')} aiuto="Codice del badge per la porta (esadecimale)">{testo('nfc_uid', { autoComplete: 'off' })}</Campo>
              </div>
              {password && <div className="hs-password">Nuova password temporanea: <code>{password}</code> · comunicala al dipendente, non sarà più visibile.</div>}
              <div>
                <div className="hs-riga"><div><b>Password</b><small>Genera una password temporanea da cambiare al primo accesso</small></div>
                  <button type="button" className="cora-btn cora-btn-secondary" onClick={nuovaPassword}>Nuova password</button></div>
                <div className="hs-riga"><div><b>Accesso come HR</b><small>Si gestisce dalla scheda del dipendente</small></div>
                  <a className="cora-btn cora-btn-secondary" href={`/admin/employees?action=view&id=${id}`}>Apri scheda</a></div>
              </div>
            </section>

            <section id="hs-teamsystem" className="cora-card hs-card">
              <div className="hs-card-h"><div><h2>TeamSystem</h2><p>Codici per l'import anagrafica e l'export delle presenze</p></div>
                {e.teamsystem_imported_at && <span className="hs-pill is-ok">Importato il {dataBreve(e.teamsystem_imported_at)}</span>}</div>
              <div className="hs-griglia">
                <Campo etichetta="Matricola" modificato={mod('matricola')}>{testo('matricola', { maxLength: 20 })}</Campo>
                <Campo etichetta="Filiale" modificato={mod('filiale')}>{testo('filiale', { inputMode: 'numeric' })}</Campo>
              </div>
            </section>

            <section className="cora-card hs-card hs-delicata">
              <div className="hs-card-h"><div><h2>Zona delicata</h2><p>Azioni che cambiano l'accesso del dipendente</p></div></div>
              <div className="hs-riga"><div><b>{e.is_active ? 'Disattiva il dipendente' : 'Riattiva il dipendente'}</b>
                <small>{e.is_active ? 'Non potrà più accedere. Per la fine del rapporto indica anche la data di cessazione.' : 'Potrà di nuovo accedere a Cora.'}</small></div>
                <button type="button" className={'cora-btn ' + (e.is_active ? 'cora-btn-danger' : 'cora-btn-secondary')} onClick={cambiaAttivo}>{e.is_active ? 'Disattiva' : 'Riattiva'}</button></div>
            </section>
          </div>
        </div>
      </div>

      {cambiati.length > 0 && (
        <div className="hs-salva" role="status">
          <span><span className="hs-n">{cambiati.length}</span>{cambiati.length === 1 ? 'modifica non salvata' : 'modifiche non salvate'}</span>
          <button type="button" className="cora-btn cora-btn-ghost" disabled={invio} onClick={() => { setV(iniziali_); setErrore(''); }}>Annulla</button>
          <button type="button" className="cora-btn cora-btn-primary" disabled={invio} onClick={salva}>{invio ? 'Salvataggio…' : 'Salva modifiche'}</button>
        </div>
      )}
    </SezioneHr>
  );
}

/* ============================== Nuova assunzione ============================== */

const CONTRATTI: Array<[string, string, string]> = [
  ['contract_indeterminato', 'Indeterminato', 'Tempo pieno o part-time, senza scadenza'],
  ['contract_determinato', 'Determinato', 'Con data di fine, rinnovabile'],
  ['contract_apprendistato', 'Apprendistato', 'Formazione e contributi ridotti'],
  ['contract_tirocinio', 'Tirocinio / Stage', 'Percorso formativo con rimborso'],
  ['contract_agevolata', 'Agevolata', 'Da valutare con il consulente'],
];
const STATO_CIVILE = [['celibe_nubile', 'Celibe / nubile'], ['coniugato', 'Coniugato/a'], ['unione_civile', 'Unione civile'], ['separato', 'Separato/a'], ['divorziato', 'Divorziato/a'], ['vedovo', 'Vedovo/a']];
const ISTRUZIONE = [['nessuno', 'Nessuno'], ['licenza_elementare', 'Licenza elementare'], ['licenza_media', 'Licenza media'], ['diploma', 'Diploma'], ['laurea_triennale', 'Laurea triennale'], ['laurea_magistrale', 'Laurea magistrale'], ['dottorato', 'Dottorato']];
const ALLEGATI: Array<[string, string, string]> = [
  ['id_doc', 'Documento di riconoscimento', "Carta d'identità o passaporto, fronte e retro"],
  ['fiscal_code_doc', 'Codice fiscale', 'Tessera sanitaria o certificato'],
  ['permit', 'Permesso di soggiorno', 'Solo per cittadini extra UE'],
  ['c2', 'Modello C2', 'Scheda anagrafica del centro per l’impiego, se disponibile'],
];
const CAMPI_ASSUNZIONE = ['employee_first_name', 'employee_last_name', 'fiscal_code', 'employee_birth_date', 'birth_state', 'birth_city', 'residence_address',
  'residence_cap', 'residence_city', 'residence_province', 'marital_status', 'education_level', 'employee_email', 'start_date', 'end_date', 'weekly_hours',
  'role_description', 'workplace', 'cost_center', 'notes'];
const PASSI: Array<[string, string]> = [['Candidato', 'Dati anagrafici'], ['Contratto', 'Tipo, mansione, orario'], ['Documenti', 'Allegati e note'], ['Invio', 'Al consulente paghe']];

interface DatiAssunzione { V: { __company: { id: number; name: string } | null; __consulente: number | null; __hr?: Record<string, any> | null; error?: string } }

function valoriAssunzione(hr: Record<string, any> | null | undefined): Record<string, string> {
  const v: Record<string, string> = {};
  for (const k of CAMPI_ASSUNZIONE) v[k] = hr?.[k] === null || hr?.[k] === undefined ? '' : String(hr[k]);
  if (hr?.weekly_hours) v.weekly_hours = numTesto(hr.weekly_hours);
  for (const [k] of CONTRATTI) v[k] = hr?.[k] ? '1' : '';
  if (!hr) { v.contract_indeterminato = '1'; v.weekly_hours = '40'; }
  v.work_days = hr ? String(hr.work_days ?? '') : 'mon,tue,wed,thu,fri';
  return v;
}

export function NuovaAssunzioneHr({ cornice, id }: { cornice: CorniceHr; id: number | null }) {
  const [d] = useDati<DatiAssunzione>(id ? `/pagine/admin/hire-requests?action=edit&id=${id}` : '/pagine/admin/hire-requests?action=new');
  const [v, setV] = useState<Record<string, string> | null>(null);
  const [passo, setPasso] = useState(0);
  const [errore, setErrore] = useState('');
  const [invio, setInvio] = useState<'' | 'draft' | 'send'>('');
  const [file, setFile] = useState<Record<string, File[]>>({});
  const cima = useRef<HTMLDivElement>(null);
  useEffect(() => { if (d && !v) setV(valoriAssunzione(d.V.__hr)); }, [d, v]);
  if (!d || !v) return null;
  const hr = d.V.__hr;
  if (id && (!hr || !['draft', 'awaiting_prospects', 'prospects_review'].includes(String(hr.status)))) {
    return (
      <SezioneHr cornice={cornice} pagina="dipendenti" titolo="Dipendenti" sottotitolo="Richiesta di assunzione" schede={SCHEDE_DIPENDENTI} attiva="Assunzioni">
        <div className="cora-card hs-card"><div className="hs-errore">Richiesta non trovata o non più modificabile.</div>
          <div><a className="cora-btn cora-btn-secondary" href="/admin/hire-requests">Torna alle assunzioni</a></div></div>
      </SezioneHr>
    );
  }
  const imposta = (k: string) => (x: string) => setV({ ...v, [k]: x });
  const testo = (k: string, extra: React.InputHTMLAttributes<HTMLInputElement> = {}) => (
    <input className="cora-input" value={v[k]} onChange={(ev) => setV({ ...v, [k]: ev.target.value })} {...extra} />
  );
  const contratti = CONTRATTI.filter(([k]) => v[k]);
  const determinato = !!v.contract_determinato;
  const giorni = csv(v.work_days);
  const ore = Number.parseFloat(v.weekly_hours.replace(',', '.')) || 0;
  // Stesse regole dell'invio al consulente (validaInvio)
  const requisiti: Array<[string, boolean]> = [
    ['Nome e cognome del candidato', !!(v.employee_first_name.trim() && v.employee_last_name.trim())],
    ['Tipo di contratto', contratti.length > 0],
    ['Data di inizio' + (determinato ? ' e di fine' : ''), !!v.start_date && (!determinato || !!v.end_date)],
    ['Ore settimanali', ore > 0],
    ['Mansioni', !!v.role_description.trim()],
  ];
  const pronta = requisiti.every(([, ok]) => ok);
  const vaiA = (n: number) => { setPasso(n); cima.current?.scrollIntoView({ behavior: 'smooth', block: 'start' }); };
  const tappe = hr ? String(hr.status) : 'draft';

  async function invia(modo: 'draft' | 'send') {
    if (invio || !v) return;
    if (modo === 'send' && !pronta) { setErrore('Completa i campi richiesti prima di inviare al consulente.'); vaiA(1); return; }
    if (modo === 'send' && !d!.V.__consulente) { setErrore("Nessun consulente del lavoro collegato all'azienda: salva come bozza e contatta l'amministratore."); return; }
    setInvio(modo); setErrore('');
    try {
      const fd = new FormData();
      fd.append('action', id ? 'update' : 'create');
      if (id) fd.append('id', String(id));
      fd.append('submit_mode', modo);
      fd.append('employer_name', d!.V.__company?.name ?? '');
      for (const k of CAMPI_ASSUNZIONE) fd.append(k, k === 'end_date' && !determinato ? '' : v[k]);
      for (const [k] of CONTRATTI) if (v[k]) fd.append(k, '1');
      giorni.forEach((g) => fd.append('work_days[]', g));
      for (const [k, lista] of Object.entries(file)) lista.forEach((f) => fd.append(`${k}[]`, f));
      const r = await inviaPagina('admin/hire-requests', fd);
      if (r.redirect) { location.href = r.redirect; return; }
      if (r.errore) { setErrore(r.errore); return; }
      // Inviata ma email al consulente non partita: si apre comunque la richiesta.
      const nuovo = r.V?.id || r.V?._GET?.id;
      if (nuovo) { location.href = `/admin/hire-requests?id=${nuovo}`; return; }
      setErrore('Operazione non riuscita');
    } finally {
      setInvio('');
    }
  }

  return (
    <SezioneHr cornice={cornice} pagina="dipendenti" titolo="Dipendenti" sottotitolo={id ? 'Modifica richiesta di assunzione' : 'Nuova assunzione: il consulente prepara i prospetti, tu approvi e il dipendente firma da Cora.'}
      schede={SCHEDE_DIPENDENTI} attiva="Assunzioni"
      azioni={<a className="cora-btn cora-btn-secondary" href={id ? `/admin/hire-requests?id=${id}` : '/admin/hire-requests'}>Annulla</a>}>
      <div ref={cima} className="hs-colonna" style={{ scrollMarginTop: 16 }}>
        <div className="hs-passi">
          {PASSI.map(([t, s], i) => (
            <button key={t} type="button" className={'hs-passo' + (i === passo ? ' is-attivo' : i < passo ? ' is-fatto' : '')} onClick={() => vaiA(i)}>
              <i>{i < passo ? '✓' : i + 1}</i><span><b>{t}</b><small>{s}</small></span>
            </button>
          ))}
        </div>

        <div className="hs-assunzione">
          <div className="hs-colonna">
            {errore && <div className="hs-errore">{errore}</div>}

            <section className="cora-card hs-card" hidden={passo !== 0}>
              <div className="hs-card-h"><Icona d={IC_PERSONA} /><div><h2>Candidato</h2><p>Per inviare bastano nome e cognome: il resto serve prima dell'approvazione</p></div></div>
              <div className="hs-griglia is-3">
                <Campo etichetta="Nome" obbligatorio>{testo('employee_first_name', { maxLength: 50 })}</Campo>
                <Campo etichetta="Cognome" obbligatorio>{testo('employee_last_name', { maxLength: 50 })}</Campo>
                <Campo etichetta="Codice fiscale">{testo('fiscal_code', { maxLength: 16, style: { textTransform: 'uppercase' } })}</Campo>
                <Campo etichetta="Data di nascita"><DataCora valore={v.employee_birth_date} cambia={imposta('employee_birth_date')} etichetta="Data di nascita" /></Campo>
                <Campo etichetta="Stato di nascita">{testo('birth_state', { placeholder: 'Italia' })}</Campo>
                <Campo etichetta="Comune di nascita">{testo('birth_city')}</Campo>
                <Campo etichetta="Stato civile"><TendinaCora valore={v.marital_status} cambia={imposta('marital_status')} etichetta="Stato civile" vuota="Seleziona" opzioni={STATO_CIVILE.map(([a, b]) => ({ valore: a, testo: b }))} /></Campo>
                <Campo etichetta="Titolo di studio"><TendinaCora valore={v.education_level} cambia={imposta('education_level')} etichetta="Titolo di studio" vuota="Seleziona" opzioni={ISTRUZIONE.map(([a, b]) => ({ valore: a, testo: b }))} /></Campo>
                <Campo etichetta="Email per l'account" aiuto="Riceverà le credenziali di Cora">{testo('employee_email', { type: 'email' })}</Campo>
                <Campo etichetta="Indirizzo di residenza" intera>{testo('residence_address', { placeholder: 'Via e numero civico' })}</Campo>
                <Campo etichetta="CAP">{testo('residence_cap', { maxLength: 10, inputMode: 'numeric' })}</Campo>
                <Campo etichetta="Comune">{testo('residence_city')}</Campo>
                <Campo etichetta="Provincia">{testo('residence_province', { maxLength: 2, style: { textTransform: 'uppercase' } })}</Campo>
              </div>
            </section>

            <section className="cora-card hs-card" hidden={passo !== 1}>
              <div className="hs-card-h"><Icona d={IC_DOC} /><div><h2>Contratto</h2><p>Il consulente riceve questi dati per preparare i prospetti</p></div></div>
              <Campo etichetta="Tipo di contratto (anche più di uno da valutare)" obbligatorio intera>
                <div className="hs-contratti">
                  {CONTRATTI.map(([k, t, s]) => (
                    <button key={k} type="button" className={'hs-contratto' + (v[k] ? ' is-on' : '')} aria-pressed={!!v[k]} onClick={() => setV({ ...v, [k]: v[k] ? '' : '1' })}>
                      <b>{t}</b><small>{s}</small>
                    </button>
                  ))}
                </div>
              </Campo>
              <div className="hs-griglia">
                <Campo etichetta="Data di inizio" obbligatorio><DataCora valore={v.start_date} cambia={imposta('start_date')} etichetta="Data di inizio" /></Campo>
                {determinato
                  ? <Campo etichetta="Data di fine" obbligatorio><DataCora valore={v.end_date} cambia={imposta('end_date')} minimo={v.start_date || undefined} etichetta="Data di fine" /></Campo>
                  : <Campo etichetta="Sede di lavoro">{testo('workplace', { placeholder: 'Es. Milano, sede centrale' })}</Campo>}
                {determinato && <Campo etichetta="Sede di lavoro">{testo('workplace', { placeholder: 'Es. Milano, sede centrale' })}</Campo>}
                <Campo etichetta="Centro di costo">{testo('cost_center')}</Campo>
                <Campo etichetta="Giorni di lavoro" intera><Giorni valore={giorni} cambia={(g) => setV({ ...v, work_days: g.join(',') })} /></Campo>
                <Campo etichetta="Ore settimanali" obbligatorio aiuto={ore > 0 && ore < 40 ? 'Part-time' : 'Tempo pieno con 40 ore'}>
                  <div className="cora-chips">
                    {['40', '30', '24', '20'].map((o) => <button key={o} type="button" className={'cora-chip' + (v.weekly_hours === o ? ' is-selected' : '')} onClick={() => setV({ ...v, weekly_hours: o })}>{o} h</button>)}
                    <input className="cora-input" style={{ width: 110 }} inputMode="decimal" aria-label="Altre ore settimanali" value={v.weekly_hours} onChange={(ev) => setV({ ...v, weekly_hours: ev.target.value })} />
                  </div>
                </Campo>
                <Campo etichetta="Mansioni" obbligatorio intera><textarea className="cora-input cora-textarea" value={v.role_description} onChange={(ev) => setV({ ...v, role_description: ev.target.value })} placeholder="Ruolo e attività principali" /></Campo>
              </div>
            </section>

            <section className="cora-card hs-card" hidden={passo !== 2}>
              <div className="hs-card-h"><Icona d={IC_ALLEGATI} /><div><h2>Documenti e note</h2><p>Gli allegati servono per l'approvazione; puoi aggiungerli anche dopo</p></div></div>
              <div className="hs-griglia">
                {ALLEGATI.map(([k, t, s]) => (
                  <label key={k} className="hs-file">
                    <b>{t}</b><small>{s}</small>
                    <input type="file" multiple accept=".pdf,.jpg,.jpeg,.png,.webp,.heic,.heif,.doc,.docx"
                      onChange={(ev) => setFile({ ...file, [k]: Array.from(ev.target.files ?? []) })} />
                    {!!file[k]?.length && <small>{file[k].length} file pronti</small>}
                  </label>
                ))}
              </div>
              <Campo etichetta="Note per il consulente" intera><textarea className="cora-input cora-textarea" value={v.notes} onChange={(ev) => setV({ ...v, notes: ev.target.value })} placeholder="Es. benefit, welfare, richieste particolari" /></Campo>
            </section>

            <section className="cora-card hs-card" hidden={passo !== 3}>
              <div className="hs-card-h"><Icona d={IC_DOC} /><div><h2>Controlla e invia</h2><p>Il consulente riceve la richiesta e prepara i prospetti di costo</p></div></div>
              <div className="hs-griglia">
                {([['Candidato', `${v.employee_first_name} ${v.employee_last_name}`.trim()], ['Codice fiscale', v.fiscal_code.toUpperCase()], ['Email', v.employee_email],
                  ['Contratto', contratti.map(([, t]) => t).join(', ')], ['Periodo', v.start_date ? `dal ${dataBreve(v.start_date)}${determinato && v.end_date ? ` al ${dataBreve(v.end_date)}` : ''}` : ''],
                  ['Orario', ore > 0 ? `${String(ore).replace('.', ',')} h a settimana · ${giorniTesto(giorni)}` : ''], ['Sede', v.workplace],
                  ['Allegati', String(Object.values(file).reduce((n, l) => n + l.length, 0)) + ' file']] as const).map(([t, x]) => (
                  <div key={t} className="cora-field"><span className="cora-label">{t}</span><span style={{ fontSize: 14 }}>{x || '—'}</span></div>
                ))}
              </div>
            </section>

            <div className="hs-piede">
              {passo > 0 && <button type="button" className="cora-btn cora-btn-secondary" onClick={() => vaiA(passo - 1)}>Indietro</button>}
              <span className="hs-spazio" />
              <button type="button" className="cora-btn cora-btn-ghost" disabled={!!invio} onClick={() => invia('draft')}>{invio === 'draft' ? 'Salvataggio…' : 'Salva bozza'}</button>
              {passo < PASSI.length - 1
                ? <button type="button" className="cora-btn cora-btn-primary" onClick={() => vaiA(passo + 1)}>Continua: {PASSI[passo + 1][0].toLowerCase()}</button>
                : <button type="button" className="cora-btn cora-btn-primary" disabled={!!invio} onClick={() => invia('send')}>{invio === 'send' ? 'Invio…' : (hr && hr.status !== 'draft' ? 'Salva e reinvia al consulente' : 'Invia al consulente')}</button>}
            </div>
          </div>

          <aside className="hs-lato">
            <div className="hs-riepilogo">
              <span className="hs-k">Riepilogo</span>
              <strong>{`${nomeProprio(v.employee_first_name)} ${nomeProprio(v.employee_last_name)}`.trim() || 'Nuovo candidato'}</strong>
              <div className="hs-righe">
                <div>Contratto <b>{contratti.map(([, t]) => t).join(', ') || '—'}</b></div>
                <div>Inizio <b>{v.start_date ? dataBreve(v.start_date) : '—'}</b></div>
                {determinato && <div>Fine <b>{v.end_date ? dataBreve(v.end_date) : '—'}</b></div>}
                <div>Orario <b>{ore > 0 ? `${String(ore).replace('.', ',')} h · ${giorni.length} gg` : '—'}</b></div>
                <div>Datore di lavoro <b>{d.V.__company?.name ?? '—'}</b></div>
              </div>
              <p>Costo azienda e netto stimato arrivano con i prospetti del consulente paghe.</p>
            </div>

            <div className="cora-card hs-card">
              <h2 style={{ margin: 0, fontSize: 15 }}>Percorso della richiesta</h2>
              <div className="hs-percorso">
                {([['draft', 'Bozza', 'La stai compilando'], ['awaiting_prospects', 'Prospetti dal consulente', 'Prepara costo e netto'], ['prospects_review', 'Approvazione HR', 'Scegli il prospetto'], ['contract', 'Contratto e firma', "Poi l'account si attiva"]] as const).map(([k, t, s]) => (
                  <div key={k} className={'hs-tappa' + (k === tappe ? ' is-attiva' : '')}><i /><div><b>{t}</b><small>{s}</small></div></div>
                ))}
              </div>
            </div>

            <div className="cora-card hs-card">
              <h2 style={{ margin: 0, fontSize: 15 }}>{pronta ? 'Pronta per l\'invio' : 'Per inviare mancano'}</h2>
              <div className="hs-mancano">{requisiti.map(([t, ok]) => <div key={t} className={ok ? 'is-ok' : ''}>{t}</div>)}</div>
              {!d.V.__consulente && <div className="hs-errore" style={{ fontSize: 13 }}>Nessun consulente del lavoro collegato: per ora puoi solo salvare la bozza.</div>}
            </div>
          </aside>
        </div>
      </div>
    </SezioneHr>
  );
}

const idDa = (q: URLSearchParams) => Number.parseInt(q.get('id') ?? '', 10) || 0;

/** /admin/employees: elenco Cora o modifica avanzata (?action=edit&id=..). */
export function DipendentiHr({ cornice, Elenco }: { cornice: CorniceHr; Elenco: (p: { cornice: CorniceHr }) => ReactNode }) {
  const q = new URLSearchParams(useLocation().search);
  return q.get('action') === 'edit' && idDa(q) ? <ModificaDipendenteHr key={idDa(q)} cornice={cornice} id={idDa(q)} /> : <Elenco cornice={cornice} />;
}

/** /admin/hire-requests: elenco Cora o nuova richiesta / modifica (?action=new, ?action=edit&id=..). */
export function AssunzioniCoraHr({ cornice, Elenco }: { cornice: CorniceHr; Elenco: (p: { cornice: CorniceHr }) => ReactNode }) {
  const q = new URLSearchParams(useLocation().search);
  const a = q.get('action');
  if (a === 'new') return <NuovaAssunzioneHr cornice={cornice} id={null} />;
  if (a === 'edit' && idDa(q)) return <NuovaAssunzioneHr key={idDa(q)} cornice={cornice} id={idDa(q)} />;
  return <Elenco cornice={cornice} />;
}

/** Indirizzi che hanno la vista Cora. */
export const usaCoraDipendenti = (q: URLSearchParams) => (!q.has('action') && !q.has('id')) || (q.get('action') === 'edit' && !!idDa(q));
export const usaCoraAssunzioni = (q: URLSearchParams) => (!q.has('action') && !q.has('id')) || q.get('action') === 'new' || (q.get('action') === 'edit' && !!idDa(q));
