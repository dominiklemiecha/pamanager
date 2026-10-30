import { useEffect, useState } from 'react';
import type { CorniceHr } from '../../cora/HrShell';
import { svgCora } from '../../cora/svg';
import { api } from '../../services/api';
import { inviaPagina, S, SezioneHr, toast } from '../hr/comune';

const BASE = '/consulente-lavoro';
const logo = <span className="hr-btn-logo" dangerouslySetInnerHTML={{ __html: svgCora('logo-cora-white-20.svg') }} />;

interface DatiProfilo {
  user: { username: string; name: string; email: string | null };
  wrike: { is_active: boolean; token: string | null; last_error: string | null } | null;
}

/** Campo password con occhio per mostrarla. */
function Password({ name, placeholder, autoComplete }: { name: string; placeholder?: string; autoComplete: string }) {
  const [vedi, setVedi] = useState(false);
  return (
    <span className="cs-password">
      <input className="cora-input" type={vedi ? 'text' : 'password'} name={name} placeholder={placeholder} autoComplete={autoComplete} required />
      <button type="button" aria-label={vedi ? 'Nascondi password' : 'Mostra password'} onClick={() => setVedi(!vedi)}><S n="ic-eye.svg" /></button>
    </span>
  );
}

/**
 * Il mio profilo (Figma 994:12958): stesse azioni della pagina PHP
 * (update_info, change_password); Wrike si configura nella pagina esistente.
 */
export function ProfiloConsulente({ cornice }: { cornice: CorniceHr }) {
  const [d, setD] = useState<DatiProfilo | null>(null);
  const [lavoro, setLavoro] = useState('');
  const carica = () => api.get('/pagine/consulente-lavoro/profile').then((r) => setD(r.data?.V ?? null)).catch(() => undefined);
  useEffect(() => { carica(); }, []);
  if (!d) return null;
  const invia = (action: 'update_info' | 'change_password', ok: string) => async (e: React.FormEvent<HTMLFormElement>) => {
    e.preventDefault();
    const form = e.currentTarget;
    const fd = new FormData(form);
    fd.append('action', action);
    setLavoro(action);
    const r = await inviaPagina('consulente-lavoro/profile', fd);
    setLavoro('');
    if (r.redirect) {
      toast(ok);
      if (action === 'change_password') form.reset();
      await carica();
    } else toast('Operazione non riuscita', r.errore, 'error');
  };
  const foto = async (f: File | undefined, rimuovi = false) => {
    if (!f && !rimuovi) return;
    const r = await inviaPagina('consulente-lavoro/profile', rimuovi ? { action: 'remove_photo' } : { action: 'upload_photo', photo: f! });
    if (r.redirect) location.reload(); else toast('Foto non aggiornata', r.errore, 'error');
  };
  const aziende = cornice.aziende.length;
  const wrike = d.wrike?.token ? (d.wrike.is_active ? 'Collegato' : 'Collegato, in pausa') : 'Non collegato';
  return (
    <SezioneHr cornice={cornice} pagina="profilo" titolo="Il mio profilo" sottotitolo="Account dello studio, password e integrazioni.">
      <div className="cs-profilo">
        <div className="cs-profilo-col">
          <div className="cora-card cs-profilo-card cs-profilo-chi">
            <span className="cs-profilo-avatar">{cornice.foto ? <img src={cornice.foto} alt="" /> : cornice.iniziali}</span>
            <strong>{d.user.name}</strong>
            <span>Consulente del lavoro{aziende > 1 ? ` - ${aziende} aziende` : ''}</span>
            {/* Foto del profilo: solo sul telefono (Figma 1202:6985) */}
            <div className="cs-profilo-foto">
              <label className="cs-pillola"><S n="ic-up.svg" />Carica foto
                <input type="file" accept="image/jpeg,image/png,image/webp" hidden onChange={(e) => foto(e.target.files?.[0])} /></label>
              {cornice.foto && <button type="button" className="cs-pillola cs-pillola-rossa" onClick={() => foto(undefined, true)}>Rimuovi</button>}
            </div>
            <small className="cs-profilo-nota">JPG, PNG o WEBP - max 3 MB</small>
          </div>
          <div className="cora-card cs-profilo-card">
            <h2>Integrazioni</h2>
            <div className="cs-integrazione">
              <span className="cs-integrazione-logo"><S n="logo-wrike.svg" /></span>
              <span className="cs-carica-txt"><strong>Wrike</strong><span>Crea attività dalle assunzioni e dalla chat · {wrike}</span></span>
              <a className="cs-pillola" href={`${BASE}/profile?classica=1`}>Configura</a>
            </div>
          </div>
        </div>
        <div className="cs-profilo-col cs-profilo-larga">
          <div className="cs-profilo-riga">
            <form className="cora-card cs-profilo-card hr-form" onSubmit={invia('update_info', 'Profilo aggiornato')}>
              <h2>Informazioni account</h2>
              <label className="cora-field"><span className="cora-label">Username</span>
                <span className="cs-password"><input className="cora-input" value={d.user.username} readOnly disabled /><span className="cs-lucchetto"><S n="ic-lock-sm.svg" /></span></span></label>
              <label className="cora-field"><span className="cora-label">Nome visualizzato</span>
                <input className="cora-input" name="name" required maxLength={100} defaultValue={d.user.name} key={d.user.name} /></label>
              <label className="cora-field"><span className="cora-label">Email</span>
                <input className="cora-input" type="email" name="email" maxLength={100} defaultValue={d.user.email ?? ''} key={d.user.email ?? ''} /></label>
              <div><button type="submit" className="cora-btn cora-btn-primary cs-pillola-nera" disabled={!!lavoro}>{logo}Salva modifiche</button></div>
            </form>
            <form className="cora-card cs-profilo-card hr-form" onSubmit={invia('change_password', 'Password cambiata')}>
              <h2>Cambia password</h2>
              <label className="cora-field"><span className="cora-label">Password attuale</span><Password name="current_password" autoComplete="current-password" /></label>
              <label className="cora-field"><span className="cora-label">Nuova password</span><Password name="new_password" placeholder="Minimo 8 caratteri" autoComplete="new-password" /></label>
              <label className="cora-field"><span className="cora-label">Conferma nuova password</span><Password name="confirm_password" placeholder="Ripeti la nuova password" autoComplete="new-password" /></label>
              <div><button type="submit" className="cora-btn cora-btn-primary cs-pillola-nera" disabled={!!lavoro}>{logo}Cambia password</button></div>
            </form>
          </div>
          <div className="cora-card cs-profilo-card cs-sessione">
            <h2>Sessione</h2>
            <p>Esci da questa sessione. Dovrai inserire di nuovo le credenziali al prossimo accesso.</p>
            <div><a className="cs-esci" href="/auth/logout"><S n="ic-esci-rosso.svg" />Esci dall’account</a></div>
          </div>
        </div>
      </div>
    </SezioneHr>
  );
}
