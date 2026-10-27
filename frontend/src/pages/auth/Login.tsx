import { FormEvent, useEffect, useState } from 'react';
import { Navigate, useNavigate, useSearchParams } from 'react-router-dom';
import { messaggioErrore, useSessione } from '../../componenti/sessione';
import { useStile, useTitolo } from '../../componenti/stile';
import { TESTA_CLASSICA, useTesta } from '../../componenti/testa';
import { api } from '../../services/api';
import css from './cora-login.css?inline';
import { svgCora } from '../../cora/svg';
import { InstallaApp } from './InstallaApp';

/** Login unico (auth/login.php) con la grafica Cora (Figma 1142:35866 e 1140:28372). */
export default function Login() {
  useStile(css);
  useTitolo('Accesso · Cora');
  useTesta(TESTA_CLASSICA);
  const sessione = useSessione();
  const naviga = useNavigate();
  const [parametri] = useSearchParams();
  const ritorno = parametri.get('return') ?? undefined;
  const [username, setUsername] = useState('');
  const [password, setPassword] = useState('');
  const [mostra, setMostra] = useState(false);
  const [errore, setErrore] = useState('');
  const [invio, setInvio] = useState(false);

  useEffect(() => {
    if (sessione.mfa) naviga('/auth/mfa-verify.php', { replace: true });
  }, [sessione.mfa, naviga]);

  // Chi e' gia' dentro va alla sua pagina, come nel PHP.
  if (sessione.utente && sessione.destinazione) return <Navigate to={sessione.destinazione} replace />;

  async function accedi(e: FormEvent) {
    e.preventDefault();
    if (invio) return;
    setInvio(true);
    setErrore('');
    try {
      const { data } = await api.post('/auth/login', { username, password, ...(ritorno ? { ritorno } : {}) });
      if (data.mfa) {
        await sessione.ricarica();
        naviga('/auth/mfa-verify.php', { replace: true });
      } else {
        // Pagina nuova come dopo il redirect del PHP.
        window.location.assign(data.destinazione);
      }
    } catch (err) {
      setErrore(messaggioErrore(err, 'Credenziali non valide'));
      // Come la pagina PHP ricaricata: password vuota, cursore sullo username.
      setPassword('');
      document.getElementById('username')?.focus();
      setInvio(false);
    }
  }

  return (
    <div className="cl-wrap">
      <aside className="cl-hero">
        <h2>Il tuo spazio HR,<br />in un unico posto.</h2>
        <p>Buste paga, ferie, comunicazioni e pratiche di assunzione: dipendenti, HR e consulente lavorano sullo stesso flusso.</p>
      </aside>

      <main className="cl-lato">
        <div className="cl-form">
          <div className="cl-logo" aria-label="Cora" dangerouslySetInnerHTML={{ __html: svgCora('logo-cora.svg') }} />
          <div className="cl-campi">
            <div className="cl-titolo">
              <h1>Bentornato</h1>
              <p>Accedi al tuo spazio.</p>
            </div>

            {errore && (
              <div className="cl-errore" role="alert">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round"><circle cx="12" cy="12" r="10" /><line x1="12" y1="8" x2="12" y2="12" /><line x1="12" y1="16" x2="12.01" y2="16" /></svg>
                <span>{errore}</span>
              </div>
            )}

            <form method="POST" onSubmit={accedi} className="cl-campi">
              <div className="cl-campo">
                <label htmlFor="username">Username, email o codice fiscale</label>
                <div className="cl-input">
                  <span className="cl-ic" dangerouslySetInnerHTML={{ __html: svgCora('ic-user.svg') }} />
                  <input type="text" id="username" name="username" required autoComplete="username" autoFocus
                    autoCapitalize="none" autoCorrect="off" spellCheck={false}
                    value={username} onChange={(e) => setUsername(e.target.value)} />
                </div>
              </div>

              <div className="cl-campo">
                <label htmlFor="password">Password</label>
                <div className="cl-input">
                  <input type={mostra ? 'text' : 'password'} id="password" name="password" required autoComplete="current-password"
                    value={password} onChange={(e) => setPassword(e.target.value)} />
                  <button type="button" className={'cl-occhio' + (mostra ? ' is-on' : '')} id="togglePw" aria-label={mostra ? 'Nascondi password' : 'Mostra password'}
                    onClick={() => setMostra(!mostra)} dangerouslySetInnerHTML={{ __html: svgCora('ic-eye.svg') }} />
                </div>
              </div>

              <div className="cl-opzioni">
                <a href="/auth/forgot-password.php">Password dimenticata?</a>
              </div>

              <button type="submit" className="cl-btn" disabled={invio}>Accedi</button>

              <div className="cl-info">
                <span style={{ display: 'contents' }} dangerouslySetInnerHTML={{ __html: svgCora('ic-info.svg') }} />
                <span>Primo accesso? Riceverai le credenziali via email dall’HR della tua azienda.</span>
              </div>
            </form>

            <InstallaApp />
          </div>
        </div>
      </main>
    </div>
  );
}
