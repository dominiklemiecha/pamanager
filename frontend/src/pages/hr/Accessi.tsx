import { useState } from 'react';
import { iniziali } from '../../cora/formato';
import type { CorniceHr } from '../../cora/HrShell';
import { svgCora } from '../../cora/svg';
import { api } from '../../services/api';
import { Modale, S, SezioneHr, toast, useDati } from './comune';

/**
 * Accessi esterni dell'HR (Figma 921:8618, 921:8854, 921:9144): consulenti
 * del lavoro e formatori. Stesse azioni della pagina classica
 * (admin/_user-role-manager.inc.php); i commercialisti non esistono piu'.
 */
type Ruolo = 'consulente_lavoro' | 'formatore';
interface Utente {
  id: number; nome: string; username: string; email: string; attivo: boolean; ultimoAccesso: string;
  aziende: number; collegate: number[]; condiviso: boolean;
}
interface DatiAccessi { aziende: Array<{ id: number; name: string; dipendenti: number }>; utenti: Utente[] }

const CFG: Record<Ruolo, { pagina: string; etichetta: string; plurale: string; multi: boolean }> = {
  consulente_lavoro: { pagina: 'admin/consulente-lavoro', etichetta: 'consulente', plurale: 'Consulenti del lavoro', multi: true },
  formatore: { pagina: 'admin/formatori', etichetta: 'formatore', plurale: 'Formatori', multi: false },
};
export const SCHEDE: Array<[string, string]> = [['Consulenti del lavoro', '/admin/consulente-lavoro'], ['Formatori', '/admin/formatori']];
const logo = <span className="hr-btn-logo" dangerouslySetInnerHTML={{ __html: svgCora('logo-cora-white-20.svg') }} />;
const testo = (html: string) => { const d = document.createElement('div'); d.innerHTML = html; return d.textContent ?? ''; };

/** Azione sulla pagina classica: torna l'errore, la password generata o null. */
async function invia(pagina: string, campi: Array<[string, string]>): Promise<{ errore: string; password: string | null }> {
  const fd = new FormData();
  for (const [k, v] of campi) fd.append(k, v);
  try {
    const r = await api.post(`/pagine/${pagina}`, fd);
    if (r.data?.redirect) return { errore: '', password: null };
    return { errore: testo(String(r.data?.V?.error ?? '')), password: r.data?.V?.generatedPassword ?? null };
  } catch (e: any) {
    return { errore: e?.response?.data?.message ?? 'Operazione non riuscita', password: null };
  }
}
function ultimo(iso: string) {
  if (!iso) return 'Mai effettuato';
  return new Intl.DateTimeFormat('it-IT', { timeZone: 'Europe/Rome', day: '2-digit', month: '2-digit', year: 'numeric', hour: '2-digit', minute: '2-digit' }).format(new Date(iso)).replace(',', ' ·');
}

export function AccessiHr({ cornice, ruolo }: { cornice: CorniceHr; ruolo: Ruolo }) {
  const cfg = CFG[ruolo];
  const [d, ricarica] = useDati<DatiAccessi>(`/hr/accessi?ruolo=${ruolo}`);
  const [cerca, setCerca] = useState('');
  const [modale, setModale] = useState<{ tipo: 'nuovo' | 'esistente' | 'modifica'; u?: Utente } | null>(null);
  const [password, setPassword] = useState<{ nome: string; password: string } | null>(null);
  if (!d) return null;
  const righe = d.utenti.filter((u) => !cerca.trim() || `${u.nome} ${u.email} ${u.username}`.toLowerCase().includes(cerca.trim().toLowerCase()));

  const reset = async (u: Utente) => {
    if (!window.confirm(`Generare una nuova password per ${u.nome}? Quella attuale smetterà di funzionare.`)) return;
    const r = await invia(`${cfg.pagina}?id=${u.id}`, [['action', 'reset_password']]);
    if (r.errore) toast('Password non reimpostata', r.errore, 'error');
    else if (r.password) setPassword({ nome: u.nome, password: r.password });
  };
  const elimina = async (u: Utente) => {
    if (!window.confirm(`Eliminare l'account di ${u.nome}?`)) return;
    const r = await invia(`${cfg.pagina}?id=${u.id}`, [['action', 'delete']]);
    if (r.errore) toast('Account non eliminato', r.errore, 'error'); else { toast('Account eliminato'); await ricarica(); }
  };
  const scollega = async (u: Utente) => {
    if (!window.confirm(`Togliere ${u.nome} dalle tue aziende? L'account resta attivo per le altre.`)) return;
    const r = await invia(`${cfg.pagina}?id=${u.id}`, [['action', 'unlink_from_my']]);
    if (r.errore) toast('Operazione non riuscita', r.errore, 'error'); else { toast('Rimosso dalle tue aziende'); await ricarica(); }
  };

  return (
    <SezioneHr cornice={cornice} pagina="accessi" titolo="Accessi esterni" sottotitolo="Gestisci gli accessi al portale per consulenti del lavoro e formatori."
      schede={SCHEDE} attiva={cfg.plurale}
      azioni={<>
        {cfg.multi && <button type="button" className="cora-btn cora-btn-secondary" onClick={() => setModale({ tipo: 'esistente' })}><S n="ic-link.svg" />Aggiungi esistente</button>}
        <button type="button" className="cora-btn cora-btn-primary" onClick={() => setModale({ tipo: 'nuovo' })}>{logo}Nuovo {cfg.etichetta}</button>
      </>}>
      <div className="cora-card hr-elenco">
        <div className="hr-toolbar">
          <label className="cora-search hr-cerca"><S n="ic-search.svg" /><input type="search" placeholder="Cerca per nome o email…" value={cerca} onChange={(e) => setCerca(e.target.value)} /></label>
        </div>
        {!righe.length ? <div className="hr-vuoto">Nessun {cfg.etichetta}</div> : (
          <div className="hr-accessi">
            {righe.map((u) => (
              <div key={u.id} className="hr-accesso">
                <div className="hr-accesso-h">
                  <span className="hr-volto">{iniziali(u.nome.split(' ')[0], u.nome.split(' ')[1] ?? '')}</span>
                  <span className="hr-accesso-nome"><strong>{u.nome}</strong>
                    <small>{ruolo === 'formatore' ? 'Responsabile formazione' : 'Consulente del lavoro'}{cfg.multi ? ` · ${u.aziende} aziend${u.aziende === 1 ? 'a' : 'e'}` : ''}{u.condiviso ? ' · condiviso' : ''}</small></span>
                  <span className={`hr-badge is-${!u.attivo ? 'cessato' : u.ultimoAccesso ? 'ok' : 'attesa'}`}><i />{!u.attivo ? 'Non attivo' : u.ultimoAccesso ? 'Attivo' : 'Invito inviato'}</span>
                </div>
                <div className="hr-accesso-info">
                  <span><S n="ic-mail.svg" /><em>Email:</em>{u.email || '—'}</span>
                  <span><S n="ic-clock.svg" /><em>Ultimo accesso:</em>{ultimo(u.ultimoAccesso)}</span>
                </div>
                <div className="hr-accesso-azioni">
                  <button type="button" className="hr-pillola" onClick={() => setModale({ tipo: 'modifica', u })}><S n="ic-pen.svg" />{u.condiviso ? 'Aziende' : 'Modifica'}</button>
                  {!u.condiviso && <button type="button" className="hr-pillola" onClick={() => reset(u)}><S n="ic-key.svg" />Reset password</button>}
                  <span className="hr-spazio" />
                  {u.condiviso
                    ? <button type="button" className="hr-pillola" onClick={() => scollega(u)}>Rimuovi dalle mie aziende</button>
                    : <button type="button" className="hr-cestino" aria-label="Elimina" onClick={() => elimina(u)}><S n="ic-trash.svg" /></button>}
                </div>
              </div>
            ))}
          </div>
        )}
      </div>
      {modale && (
        <ModuloAccesso ruolo={ruolo} tipo={modale.tipo} u={modale.u} aziende={d.aziende} chiudi={() => setModale(null)}
          fatto={async (pwd) => { setModale(null); await ricarica(); if (pwd) setPassword(pwd); }} />
      )}
      <Modale aperta={!!password} chiudi={() => setPassword(null)} titolo="Password generata" sottotitolo={password ? `Per ${password.nome}: copiala adesso, non sarà più visualizzata.` : ''}>
        <div className="hr-password"><code>{password?.password}</code>
          <button type="button" className="cora-btn cora-btn-secondary" onClick={() => { navigator.clipboard?.writeText(password?.password ?? ''); toast('Password copiata'); }}>Copia</button></div>
        <p className="hr-nota"><S n="ic-info.svg" />Al primo accesso verrà chiesto di cambiarla. Se l'email è configurata, le credenziali sono state inviate anche per email.</p>
        <div className="hr-form-azioni"><button type="button" className="cora-btn cora-btn-primary" onClick={() => setPassword(null)}>Fatto</button></div>
      </Modale>
    </SezioneHr>
  );
}

function ModuloAccesso({ ruolo, tipo, u, aziende, chiudi, fatto }: {
  ruolo: Ruolo; tipo: 'nuovo' | 'esistente' | 'modifica'; u?: Utente; aziende: DatiAccessi['aziende'];
  chiudi: () => void; fatto: (pwd: { nome: string; password: string } | null) => Promise<void>;
}) {
  const cfg = CFG[ruolo];
  const [auto, setAuto] = useState(true);
  const [errore, setErrore] = useState('');
  const [invio, setInvio] = useState(false);
  const solaAziende = tipo === 'modifica' && !!u?.condiviso;
  const titolo = tipo === 'nuovo' ? `Nuovo ${cfg.etichetta}` : tipo === 'esistente' ? `Aggiungi ${cfg.etichetta} esistente` : solaAziende ? `Aziende di ${u!.nome}` : `Modifica ${u!.nome}`;
  const sottotitolo = tipo === 'nuovo' ? (cfg.multi ? "Crea l'account e scegli a quali aziende potrà accedere." : "Crea l'account per l'Academy.")
    : tipo === 'esistente' ? 'Collega un consulente che ha già un account Cora con un\'altra azienda.' : solaAziende ? 'Account condiviso con un\'altra azienda: puoi cambiare solo le tue aziende collegate.' : '';
  const campoAziende = tipo === 'esistente' ? 'link_company_ids[]' : 'company_ids[]';
  const invia2 = async (e: React.FormEvent<HTMLFormElement>) => {
    e.preventDefault();
    const f = new FormData(e.currentTarget);
    const campi: Array<[string, string]> = [['action', tipo === 'nuovo' ? 'create' : tipo === 'esistente' ? 'link_existing' : 'update']];
    f.forEach((v, k) => campi.push([k, String(v)]));
    setInvio(true);
    const r = await invia(tipo === 'modifica' ? `${cfg.pagina}?id=${u!.id}` : cfg.pagina, campi);
    setInvio(false);
    if (r.errore) { setErrore(r.errore); return; }
    toast(tipo === 'nuovo' ? 'Account creato' : tipo === 'esistente' ? 'Account collegato' : 'Account aggiornato');
    await fatto(r.password ? { nome: String(f.get('name') ?? ''), password: r.password } : null);
  };
  return (
    <Modale aperta chiudi={chiudi} titolo={titolo} sottotitolo={sottotitolo}>
      <form className="hr-form" onSubmit={invia2}>
        {tipo === 'esistente' ? (
          <label className="cora-field"><span className="cora-label">Email del consulente<span className="req">*</span></span>
            <input className="cora-input" type="email" name="link_email" required autoFocus /></label>
        ) : !solaAziende && (
          <>
            <div className="cora-grid-2">
              <label className="cora-field"><span className="cora-label">Nome completo<span className="req">*</span></span>
                <input className="cora-input" name="name" required maxLength={100} defaultValue={u?.nome ?? ''} placeholder="Es. Studio Verdi" autoFocus /></label>
              <label className="cora-field"><span className="cora-label">Username<span className="req">*</span></span>
                <input className="cora-input" name="username" required maxLength={50} defaultValue={u?.username ?? ''} placeholder="Es. studio_verdi" /></label>
            </div>
            <label className="cora-field"><span className="cora-label">Email<span className="req">*</span></span>
              <input className="cora-input" type="email" name="email" required maxLength={100} defaultValue={u?.email ?? ''} /></label>
            {tipo === 'nuovo' && (
              <>
                <label className="hr-check"><input type="checkbox" name="autogen_password" value="1" checked={auto} onChange={(e) => setAuto(e.target.checked)} />Genera una password sicura automaticamente (consigliato)</label>
                {!auto && <label className="cora-field"><span className="cora-label">Password<span className="req">*</span></span>
                  <input className="cora-input" type="password" name="password" required minLength={12} autoComplete="new-password" />
                  <span className="cora-hint">Almeno 12 caratteri con maiuscola, minuscola, numero e simbolo.</span></label>}
              </>
            )}
            {tipo === 'modifica' && <label className="hr-check"><input type="checkbox" name="is_active" value="1" defaultChecked={u?.attivo} />Account attivo</label>}
          </>
        )}
        {cfg.multi && (
          <div className="cora-field"><span className="cora-label">Aziende assegnate</span>
            <span className="cora-hint">Il consulente vedrà solo le aziende selezionate e i loro dipendenti.</span>
            <div className="hr-aziende">
              {aziende.map((a) => (
                <label key={a.id} className="hr-check"><input type="checkbox" name={campoAziende} value={a.id} defaultChecked={u ? u.collegate.includes(a.id) : aziende.length === 1} />{a.name} · {a.dipendenti} dipendent{a.dipendenti === 1 ? 'e' : 'i'}</label>
              ))}
            </div>
          </div>
        )}
        {errore && <p className="hr-errore">{errore}</p>}
        <div className="hr-form-azioni">
          <button type="button" className="cora-btn cora-btn-ghost" onClick={chiudi}>Annulla</button>
          <button type="submit" className="cora-btn cora-btn-primary" disabled={invio}>{logo}{tipo === 'nuovo' ? `Crea ${cfg.etichetta}` : tipo === 'esistente' ? 'Collega consulente' : 'Salva'}</button>
        </div>
      </form>
    </Modale>
  );
}
