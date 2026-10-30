import { useEffect, useState } from 'react';
import { nome } from '../../cora/formato';
import type { CorniceHr } from '../../cora/HrShell';
import { svgCora } from '../../cora/svg';
import { api } from '../../services/api';
import { inviaPagina, Modale, S, SezioneHr, toast, useDati, Volto } from './comune';

/**
 * Profilo e configurazione dell'HR (Figma 995:12853, 13079, 13292, 13506,
 * 13745). Ogni scheda usa la pagina esistente (stessi dati e stesse azioni).
 */
export const SCHEDE: Array<[string, string]> = [
  ['Profilo', '/admin/profile'], ['Timbrature', '/admin/punch-settings'], ['Reset password', '/admin/password-resets'],
  ['Orario lavorativo', '/admin/work-schedule'], ['Buoni pasto', '/admin/buoni-pasto'],
];
const logo = <span className="hr-btn-logo" dangerouslySetInnerHTML={{ __html: svgCora('logo-cora-white-20.svg') }} />;
const GIORNI: Array<[string, string]> = [['mon', 'Lun'], ['tue', 'Mar'], ['wed', 'Mer'], ['thu', 'Gio'], ['fri', 'Ven'], ['sat', 'Sab'], ['sun', 'Dom']];

function Sezione({ cornice, scheda, sottotitolo, children }: { cornice: CorniceHr; scheda: string; sottotitolo: string; children: React.ReactNode }) {
  return (
    <SezioneHr cornice={cornice} pagina="profilo" titolo="Profilo e configurazione" sottotitolo={sottotitolo} schede={SCHEDE} attiva={scheda}
      azioni={scheda !== 'Profilo' ? <span className="hr-tenue hr-solo-largo">Le impostazioni valgono per {cornice.azienda?.name}</span> : undefined}>
      {children}
      {/* Sul telefono in fondo, per lasciare le schede del Figma al loro posto */}
      {scheda !== 'Profilo' && <p className="hr-tenue hr-solo-tel">Le impostazioni valgono per {cornice.azienda?.name}</p>}
    </SezioneHr>
  );
}

/** Interruttore con lo stile Cora (checkbox vero, per i moduli). */
function Interruttore({ name, checked, onChange }: { name: string; checked: boolean; onChange: (v: boolean) => void }) {
  return (
    <label className="hr-switch"><input type="checkbox" name={name} value="1" checked={checked} onChange={(e) => onChange(e.target.checked)} /><span /></label>
  );
}

/* ===================== Profilo ===================== */

export function ProfiloHr({ cornice }: { cornice: CorniceHr }) {
  const [d, ricarica] = useDati<{ V: { user: { username: string; name: string; email: string; photo_path: string | null } } }>('/pagine/admin/profile');
  const [errore, setErrore] = useState<Record<string, string>>({});
  if (!d) return null;
  const u = d.V.user;
  const foto = u.photo_path ? '/' + String(u.photo_path).replace(/^\/+/, '') : '';
  const invia = async (chiave: string, e: React.FormEvent<HTMLFormElement>, messaggio: string) => {
    e.preventDefault();
    const fd = new FormData(e.currentTarget);
    const r = await inviaPagina('admin/profile', fd);
    if (!r.redirect) { setErrore({ ...errore, [chiave]: r.errore || 'Operazione non riuscita' }); return; }
    setErrore({ ...errore, [chiave]: '' });
    toast(messaggio);
    if (chiave === 'password') e.currentTarget?.reset();
    await ricarica();
  };
  const caricaFoto = async (f: File | undefined) => {
    if (!f) return;
    const r = await inviaPagina('admin/profile', { action: 'upload_photo', photo: f });
    if (!r.redirect) toast('Foto non caricata', r.errore, 'error'); else location.reload();
  };
  return (
    <Sezione cornice={cornice} scheda="Profilo" sottotitolo="Le tue informazioni personali e le impostazioni dell'azienda.">
      <div className="hr-profilo">
        <div className="cora-card hr-profilo-foto">
          <Volto p={{ id: 0, nome: (u.name || u.username).split(' ')[0], cognome: (u.name || '').split(' ')[1] ?? '', foto }} classe="hr-volto hr-volto-grande" />
          <strong>{u.name || u.username}</strong>
          <small>Amministratore · {cornice.azienda?.name}</small>
          <label className="cora-btn cora-btn-secondary hr-carica"><S n="ic-up.svg" />Carica foto
            <input type="file" accept="image/jpeg,image/png,image/webp" hidden onChange={(e) => caricaFoto(e.target.files?.[0])} /></label>
          {foto && <button type="button" className="hr-link" onClick={async () => { await inviaPagina('admin/profile', { action: 'remove_photo' }); location.reload(); }}>Rimuovi foto</button>}
          <small>JPG, PNG o WEBP · max 10 MB</small>
        </div>
        <form className="cora-card hr-modulo-card" onSubmit={(e) => invia('info', e, 'Profilo aggiornato')}>
          <h2>Informazioni account</h2>
          <input type="hidden" name="action" value="update_info" />
          <label className="cora-field"><span className="cora-label">Username</span><input className="cora-input" value={u.username} readOnly /></label>
          <label className="cora-field"><span className="cora-label">Nome visualizzato</span><input className="cora-input" name="name" required maxLength={100} defaultValue={u.name} /></label>
          <label className="cora-field"><span className="cora-label">Email</span><input className="cora-input" type="email" name="email" maxLength={100} defaultValue={u.email ?? ''} /></label>
          {errore.info && <p className="hr-errore">{errore.info}</p>}
          <div><button type="submit" className="cora-btn cora-btn-primary">{logo}Salva modifiche</button></div>
        </form>
        <form className="cora-card hr-modulo-card" onSubmit={(e) => invia('password', e, 'Password cambiata')}>
          <h2>Cambia password</h2>
          <input type="hidden" name="action" value="change_password" />
          <label className="cora-field"><span className="cora-label">Password attuale</span><input className="cora-input" type="password" name="current_password" required autoComplete="current-password" /></label>
          <label className="cora-field"><span className="cora-label">Nuova password</span><input className="cora-input" type="password" name="new_password" required minLength={12} autoComplete="new-password" placeholder="Almeno 12 caratteri" /></label>
          <label className="cora-field"><span className="cora-label">Conferma nuova password</span><input className="cora-input" type="password" name="confirm_password" required minLength={12} autoComplete="new-password" placeholder="Ripeti la nuova password" /></label>
          {errore.password && <p className="hr-errore">{errore.password}</p>}
          <div><button type="submit" className="cora-btn cora-btn-primary">{logo}Cambia password</button></div>
        </form>
      </div>
      <div className="cora-card hr-sessione">
        <div><h2>Sessione</h2><p>Esci da questa sessione. Dovrai inserire di nuovo le credenziali al prossimo accesso.</p></div>
        <a className="cora-btn cora-btn-danger" href="/auth/logout"><S n="ic-logout.svg" />Esci dall'account</a>
      </div>
    </Sezione>
  );
}

/* ===================== Timbrature ===================== */

export function TimbratureImpostazioniHr({ cornice }: { cornice: CorniceHr }) {
  const [d] = useDati<{ V: { companySlug: string; isEnabled: number; punchUrl: string } }>('/pagine/admin/punch-settings');
  const [attive, setAttive] = useState<boolean | null>(null);
  const [slug, setSlug] = useState<string | null>(null);
  const [errore, setErrore] = useState('');
  if (!d) return null;
  const on = attive ?? !!d.V.isEnabled;
  const ident = slug ?? d.V.companySlug;
  const url = d.V.punchUrl.replace(/c=[^&]*$/, `c=${encodeURIComponent(ident.toLowerCase().replace(/[^a-z0-9]+/g, '-').replace(/^-+|-+$/g, ''))}`);
  const salva = async (e: React.FormEvent<HTMLFormElement>) => {
    e.preventDefault();
    const r = await inviaPagina('admin/punch-settings', new FormData(e.currentTarget));
    if (r.errore) { setErrore(r.errore); return; }
    setErrore('');
    toast('Impostazioni salvate');
  };
  return (
    <Sezione cornice={cornice} scheda="Timbrature" sottotitolo="Abilita le timbrature NFC e configura la carta da appoggiare all'ingresso.">
      <div className="hr-config">
        <form className="cora-card hr-modulo-card" onSubmit={salva}>
          <h2><span className="hr-solo-tel"><S n="ic-nfc.svg" /></span>Timbrature NFC</h2>
          <div className="hr-opzione">
            <span><strong>Abilita timbrature NFC per {cornice.azienda?.name}</strong><small>Se disattivate, i dipendenti non vedono il pulsante «Timbra entrata/uscita» e l'URL NFC restituisce errore.</small></span>
            <Interruttore name="timbratura_enabled" checked={on} onChange={setAttive} />
          </div>
          <label className="cora-field hr-campo-corto"><span className="cora-label">Identificativo azienda</span>
            <input className="cora-input" name="nfc_slug" value={ident} onChange={(e) => setSlug(e.target.value)} maxLength={60} />
            <span className="cora-hint">Solo lettere, numeri e trattini: è la parte finale dell'URL NFC.</span></label>
          <div className="cora-field"><span className="cora-label">URL da scrivere sulla carta NTAG215</span>
            <div className="hr-url"><code>{url}</code>
              <button type="button" className="cora-btn cora-btn-secondary" onClick={() => { navigator.clipboard?.writeText(url); toast('URL copiato'); }}><span className="hr-solo-tel"><S n="ic-copy.svg" /></span>Copia</button></div></div>
          <p className="hr-nota"><S n="ic-info.svg" />Si aggiorna mentre scrivi l'identificativo: salva prima di scriverlo sulla carta.</p>
          {errore && <p className="hr-errore">{errore}</p>}
          <div className="hr-form-azioni"><button type="submit" className="cora-btn cora-btn-primary">{logo}Salva impostazioni</button></div>
        </form>
        <div className="cora-card hr-modulo-card hr-laterale">
          <h2>Come scrivere la carta</h2>
          <ol className="hr-passi-lista">
            <li><b>1</b>Apri NFC Tools sul telefono</li>
            <li><b>2</b>Vai su Scrivi → Aggiungi record → URL/URI</li>
            <li><b>3</b>Incolla l'URL copiato</li>
            <li><b>4</b>Avvicina la carta e tocca Scrivi</li>
          </ol>
          <p className="hr-avviso"><S n="ic-info.svg" />Ogni timbratura compare in Dipendenti → Presenze e nell'export del consulente.</p>
        </div>
      </div>
    </Sezione>
  );
}

/* ===================== Reset password ===================== */

export function ResetPasswordHr({ cornice }: { cornice: CorniceHr }) {
  const [d] = useDati<{ V: { users: Array<{ id: number; name: string; username: string; email: string; role: string }>; employees: Array<{ id: number; first_name: string; last_name: string; email: string }> } }>('/pagine/admin/password-resets');
  const [richieste, ricarica] = useDati<Array<{ id: number; nome: string; tipo: string; richiesta: string }>>('/hr/richieste-reset');
  const [tipo, setTipo] = useState<'employee' | 'admin'>('employee');
  const [utente, setUtente] = useState('');
  const [risultato, setRisultato] = useState<{ titolo: string; valore: string; nota: string } | null>(null);
  if (!d || !richieste) return null;
  const elenco = tipo === 'employee'
    ? d.V.employees.map((e) => ({ id: e.id, etichetta: `${nome(e.first_name)} ${nome(e.last_name)}${e.email ? ` · ${e.email}` : ''}` }))
    : d.V.users.filter((u) => u.role === 'admin').map((u) => ({ id: u.id, etichetta: `${u.name || u.username}${u.email ? ` · ${u.email}` : ''}` }));
  const reset = async () => {
    if (!utente) return;
    if (!window.confirm('Generare una password temporanea? Quella attuale smetterà di funzionare.')) return;
    const r = await inviaPagina('admin/password-resets', { action: 'manual_reset', user_type: tipo, user_id: utente });
    const pwd = /<code>([^<]+)<\/code>/.exec(String(r.V?.message ?? ''))?.[1];
    if (!pwd) { toast('Password non reimpostata', r.errore, 'error'); return; }
    setRisultato({ titolo: 'Password temporanea', valore: pwd, nota: "Comunicala all'utente: al primo accesso dovrà cambiarla." });
  };
  const decidi = async (id: number, azione: 'approve' | 'reject') => {
    const r = await inviaPagina('admin/password-resets', { action: azione, request_id: id });
    if (r.errore) { toast('Operazione non riuscita', r.errore, 'error'); return; }
    if (azione === 'approve') {
      const link = /value="([^"]+)"/.exec(String(r.V?.message ?? ''))?.[1]?.replace(/&amp;/g, '&');
      if (link) setRisultato({ titolo: 'Link di reset', valore: link, nota: "Invialo all'utente: scade tra 24 ore." });
    } else toast('Richiesta rifiutata');
    await ricarica();
  };
  return (
    <Sezione cornice={cornice} scheda="Reset password" sottotitolo="Reimposta l'accesso di dipendenti e amministratori. Per i consulenti usa Accessi esterni.">
      <div className="cora-card hr-modulo-card">
        <h2><span className="hr-solo-tel"><S n="ic-key.svg" /></span>Reset manuale della password</h2>
        <span className="cora-label">Tipo di utente</span>
        <div className="cora-chips">
          {([['employee', 'Dipendente'], ['admin', 'Amministratore']] as const).map(([k, t]) => (
            <button key={k} type="button" className={'cora-chip' + (tipo === k ? ' is-selected' : '')} onClick={() => { setTipo(k); setUtente(''); }}>{t}</button>
          ))}
        </div>
        <span className="cora-label">Utente</span>
        <div className="hr-riga-campo">
          <select className="cora-input" value={utente} onChange={(e) => setUtente(e.target.value)}>
            <option value="">Scegli…</option>{elenco.map((x) => <option key={x.id} value={x.id}>{x.etichetta}</option>)}
          </select>
          <button type="button" className="cora-btn cora-btn-primary" disabled={!utente} onClick={reset}>{logo}Reset password</button>
        </div>
        <p className="hr-nota"><S n="ic-info.svg" />L'utente riceve una password temporanea e dovrà cambiarla al primo accesso.</p>
      </div>
      <div className="cora-card hr-modulo-card">
        <h2>Richieste di reset</h2>
        <p className="hr-tenue">Utenti che hanno usato «Password dimenticata?» nella pagina di accesso.</p>
        {!richieste.length ? <div className="hr-vuoto">Nessuna richiesta in attesa</div> : richieste.map((x) => (
          <div key={x.id} className="hr-richiesta">
            <Volto p={{ id: x.id, nome: x.nome.split(' ')[0], cognome: x.nome.split(' ')[1] ?? '', foto: '' }} />
            <div className="hr-richiesta-txt"><div><strong>{x.nome}</strong></div><span>{x.tipo} · {new Date(x.richiesta).toLocaleString('it-IT', { timeZone: 'Europe/Rome', day: '2-digit', month: '2-digit', hour: '2-digit', minute: '2-digit' })}</span></div>
            <span className="hr-badge is-attesa"><i />In attesa</span>
            <button type="button" className="hr-pillola" onClick={() => decidi(x.id, 'reject')}>Rifiuta</button>
            <button type="button" className="hr-pillola" onClick={() => decidi(x.id, 'approve')}><S n="ic-key.svg" />Genera link di reset</button>
          </div>
        ))}
      </div>
      <Modale aperta={!!risultato} chiudi={() => setRisultato(null)} titolo={risultato?.titolo ?? ''} sottotitolo={risultato?.nota}>
        <div className="hr-password"><code>{risultato?.valore}</code>
          <button type="button" className="cora-btn cora-btn-secondary" onClick={() => { navigator.clipboard?.writeText(risultato?.valore ?? ''); toast('Copiato'); }}>Copia</button></div>
        <div className="hr-form-azioni"><button type="button" className="cora-btn cora-btn-primary" onClick={() => setRisultato(null)}>Fatto</button></div>
      </Modale>
    </Sezione>
  );
}

/* ===================== Orario lavorativo ===================== */

export function OrarioHr({ cornice }: { cornice: CorniceHr }) {
  const [d] = useDati<{ V: {
    defaults: { days: string[]; hours: number }; ccnls: Array<{ id: number; name: string; ferie_days_year: number; permessi_hours_year: number }>;
    companySwDays: string[]; currentDefaultCcnl: number | null; compRow: { lunch_break_start: string | null; lunch_break_end: string | null; slug: string } | false;
  } }>('/pagine/admin/work-schedule');
  const [giorni, setGiorni] = useState<string[] | null>(null);
  const [sw, setSw] = useState<string[] | null>(null);
  const [errore, setErrore] = useState('');
  if (!d) return null;
  const V = d.V;
  const lav = giorni ?? V.defaults.days;
  const smart = sw ?? V.companySwDays;
  const ora = (x: string | null | undefined) => (x ? String(x).slice(0, 5) : '');
  const salva = async (e: React.FormEvent<HTMLFormElement>) => {
    e.preventDefault();
    const fd = new FormData(e.currentTarget);
    lav.forEach((g) => fd.append('working_days[]', g));
    smart.forEach((g) => fd.append('smart_working_days[]', g));
    const r = await inviaPagina('admin/work-schedule', fd);
    if (r.errore) { setErrore(r.errore); return; }
    setErrore('');
    toast('Impostazioni salvate');
  };
  const alterna = (lista: string[], g: string) => (lista.includes(g) ? lista.filter((x) => x !== g) : [...lista, g]);
  return (
    <Sezione cornice={cornice} scheda="Orario lavorativo" sottotitolo="Giorni e ore lavorative usati per il saldo di ferie e permessi, le presenze e i buoni pasto.">
      <div className="hr-config">
        <form className="cora-card hr-modulo-card" onSubmit={salva}>
          <h2>Giorni lavorativi</h2>
          <p className="hr-tenue">Default aziendale per calcolare il consumo di ferie e permessi. Ogni dipendente può avere un orario diverso nella sua scheda.</p>
          <div className="cora-chips">{GIORNI.map(([k, t]) => <button key={k} type="button" className={'cora-chip' + (lav.includes(k) ? ' is-selected' : '')} onClick={() => setGiorni(alterna(lav, k))}>{t}</button>)}</div>
          <div className="hr-griglia-campi">
            <label className="cora-field"><span className="cora-label">Ore lavorate al giorno</span><input className="cora-input" name="hours_per_day" inputMode="decimal" defaultValue={String(V.defaults.hours)} /></label>
            <label className="cora-field"><span className="cora-label">CCNL applicato di default</span>
              <select className="cora-input" name="default_ccnl_id" defaultValue={V.currentDefaultCcnl ?? ''}>
                <option value="">Nessuno</option>
                {V.ccnls.map((c) => <option key={c.id} value={c.id}>{c.name} · {Number(c.ferie_days_year)} gg ferie / {Number(c.permessi_hours_year)} h permessi</option>)}
              </select></label>
          </div>
          <h2>Giorni di smart working ricorrenti</h2>
          <p className="hr-tenue">Usati per il conteggio dei buoni pasto. Ogni dipendente può avere un override nella sua scheda.</p>
          <div className="cora-chips">{GIORNI.map(([k, t]) => <button key={k} type="button" className={'cora-chip' + (smart.includes(k) ? ' is-selected' : '')} onClick={() => setSw(alterna(smart, k))}>{t}</button>)}</div>
          <h2>Pausa pranzo</h2>
          <p className="hr-tenue">I permessi a ore che la attraversano scalano la sovrapposizione: es. permesso 12:00 – 18:00 con pausa 13:00 – 14:00 = 5 ore.</p>
          <div className="hr-orari">
            <label className="cora-field"><span className="cora-label">Inizio</span><input className="cora-input" type="time" name="lunch_break_start" defaultValue={ora(V.compRow ? V.compRow.lunch_break_start : '')} /></label>
            <label className="cora-field"><span className="cora-label">Fine</span><input className="cora-input" type="time" name="lunch_break_end" defaultValue={ora(V.compRow ? V.compRow.lunch_break_end : '')} /></label>
          </div>
          {errore && <p className="hr-errore">{errore}</p>}
          <div className="hr-form-azioni"><button type="submit" className="cora-btn cora-btn-primary">{logo}Salva impostazioni</button></div>
        </form>
        <div className="cora-card hr-modulo-card hr-laterale">
          <h2><S n="ic-info.svg" /> Dove si vede</h2>
          {[['Dipendente', 'Saldo di ferie e permessi nei cerchi della dashboard'], ['Consulente del lavoro', "Ore e giorni nell'export mensile delle presenze"], ['HR', 'Calcolo automatico della durata delle richieste']].map(([t, s]) => (
            <div key={t} className="hr-dove"><strong>{t}</strong><small>{s}</small></div>
          ))}
        </div>
      </div>
    </Sezione>
  );
}

/* ===================== Buoni pasto ===================== */

const MESI = ['', 'gennaio', 'febbraio', 'marzo', 'aprile', 'maggio', 'giugno', 'luglio', 'agosto', 'settembre', 'ottobre', 'novembre', 'dicembre'];

export function BuoniPastoHr({ cornice }: { cornice: CorniceHr }) {
  const [d] = useDati<{ V: { bpEnabled: boolean; bpMinOn: boolean; bpMinHours: number; bpSw: boolean; swDaysLabel: string } }>('/pagine/admin/buoni-pasto');
  const [anteprima, setAnteprima] = useState<{ mese: number; attivi: boolean; media: number; dipendenti: Array<{ id: number; nome: string; cognome: string; buoni: number }> } | null>(null);
  const [stato, setStato] = useState<{ on: boolean; min: boolean; sw: boolean } | null>(null);
  const [errore, setErrore] = useState('');
  const caricaAnteprima = () => api.get('/hr/buoni-pasto').then((r) => setAnteprima(r.data)).catch(() => undefined);
  useEffect(() => { caricaAnteprima(); }, []);
  if (!d) return null;
  const s = stato ?? { on: d.V.bpEnabled, min: d.V.bpMinOn, sw: d.V.bpSw };
  const salva = async (e: React.FormEvent<HTMLFormElement>) => {
    e.preventDefault();
    const r = await inviaPagina('admin/buoni-pasto', new FormData(e.currentTarget));
    if (r.errore) { setErrore(r.errore); return; }
    setErrore('');
    toast('Impostazioni salvate');
    await caricaAnteprima();
  };
  return (
    <Sezione cornice={cornice} scheda="Buoni pasto" sottotitolo="Regole di maturazione dei ticket restaurant.">
      <div className="hr-config">
        <form className="cora-card hr-modulo-card" onSubmit={salva}>
          <h2><span className="hr-solo-tel"><S n="ic-ticket.svg" /></span>Buoni pasto</h2>
          <p className="hr-tenue">1 ticket per ogni giorno effettivamente lavorato. Il totale mensile di ogni dipendente compare nell'export presenze del consulente come colonna «Buoni pasto (nr)».</p>
          <div className="hr-opzione">
            <span><strong>Abilita buoni pasto</strong><small>Attiva il conteggio dei ticket per {cornice.azienda?.name}</small></span>
            <Interruttore name="buoni_pasto_enabled" checked={s.on} onChange={(v) => setStato({ ...s, on: v })} />
          </div>
          <div className="hr-opzione">
            <span><strong>Soglia minima di ore</strong><small>Il ticket matura solo nei giorni con ore effettive (ore/giorno meno permessi) pari o sopra la soglia.</small>
              <span className="hr-soglia"><input className="cora-input" name="buoni_pasto_min_hours" inputMode="decimal" defaultValue={String(d.V.bpMinHours)} /> ore al giorno</span></span>
            <Interruttore name="buoni_pasto_min_hours_enabled" checked={s.min} onChange={(v) => setStato({ ...s, min: v })} />
          </div>
          <div className="hr-opzione">
            <span><strong>Lo smart working dà diritto al ticket?</strong><small>Giorni di smart working ricorrenti: {d.V.swDaysLabel} (si impostano in Orario lavorativo).</small></span>
            <Interruttore name="buoni_pasto_sw_eligible" checked={s.sw} onChange={(v) => setStato({ ...s, sw: v })} />
          </div>
          {errore && <p className="hr-errore">{errore}</p>}
          <div className="hr-form-azioni"><button type="submit" className="cora-btn cora-btn-primary">{logo}Salva impostazioni</button></div>
        </form>
        <div className="cora-card hr-modulo-card hr-laterale">
          <h2>Anteprima di {anteprima ? MESI[anteprima.mese] : 'questo mese'}</h2>
          {!anteprima ? null : !anteprima.attivi ? <p className="hr-tenue">Attiva i buoni pasto per vedere l'anteprima del mese.</p> : (
            <>
              <p className="hr-kpi-v"><b>{anteprima.media}</b> ticket medi per dipendente</p>
              {anteprima.dipendenti.map((x) => <div key={x.id} className="hr-dove hr-dove-riga"><span>{nome(x.nome)} {nome(x.cognome)}</span><strong>{x.buoni} ticket</strong></div>)}
              <p className="hr-avviso"><S n="ic-info.svg" />Il consulente li riceve già calcolati nell'export.</p>
            </>
          )}
        </div>
      </div>
    </Sezione>
  );
}
