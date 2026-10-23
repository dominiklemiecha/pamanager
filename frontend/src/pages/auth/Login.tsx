import { FormEvent, useEffect, useState } from 'react';
import { Navigate, useNavigate, useSearchParams } from 'react-router-dom';
import { messaggioErrore, useSessione } from '../../componenti/sessione';
import { useFoglioEsterno, useStile, useTitolo } from '../../componenti/stile';
import { TESTA_CLASSICA, useTesta } from '../../componenti/testa';
import { api } from '../../services/api';
import css from './login.css?inline';
import { InstallaApp } from './InstallaApp';

const FONT = 'https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&family=Host+Grotesk:wght@500;600;700;800&family=Space+Grotesk:wght@500;600;700&display=swap';

/** Login unico (auth/login.php): stesso aspetto, stessi testi. */
export default function Login() {
  useStile(css);
  useFoglioEsterno(FONT);
  useTitolo('Accesso - Connecteed HR');
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
      await sessione.ricarica();
      if (data.mfa) naviga('/auth/mfa-verify.php', { replace: true });
      else window.location.assign(data.destinazione);
    } catch (err) {
      setErrore(messaggioErrore(err, 'Credenziali non valide'));
      // Come la pagina PHP ricaricata: password vuota, cursore sullo username.
      setPassword('');
      document.getElementById('username')?.focus();
      setInvio(false);
    }
  }

  return (
    <div className="login-wrap">
      <aside className="login-hero">
        <div className="login-brand">ConnecteedHR</div>
        <div className="login-hero-body">
          <h2>Il tuo team, organizzato.</h2>
          <p>Ferie, presenze, comunicazioni, documenti e chat. Tutto in un solo posto, accessibile da ogni dispositivo.</p>
          <ul className="login-features">
            <li>
              <span className="login-feat-ic">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round"><rect x="3" y="4" width="18" height="18" rx="2" /><path d="M16 2v4M8 2v4M3 10h18" /></svg>
              </span>
              Gestisci ferie e permessi in pochi click
            </li>
            <li>
              <span className="login-feat-ic">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round"><path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z" /></svg>
              </span>
              Comunica con il team in tempo reale
            </li>
            <li>
              <span className="login-feat-ic">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z" /><polyline points="14 2 14 8 20 8" /></svg>
              </span>
              Documenti e buste paga sempre disponibili
            </li>
          </ul>
        </div>
        <div className="login-hero-foot">© {new Date().getFullYear()} ConnecteedHR · Tutti i diritti riservati</div>
      </aside>

      <main className="login-form-side">
        <div style={{ width: '100%', maxWidth: 420 }}>
          <div className="login-card">
            <div className="login-card-h">
              <h1>Bentornato 👋</h1>
              <p>Accedi al tuo account ConnecteedHR.</p>
            </div>

            {errore && (
              <div className="login-err">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round"><circle cx="12" cy="12" r="10" /><line x1="12" y1="8" x2="12" y2="12" /><line x1="12" y1="16" x2="12.01" y2="16" /></svg>
                <span>{errore}</span>
              </div>
            )}

            <form method="POST" onSubmit={accedi}>
              <div className="login-fg">
                <label htmlFor="username">Username, email o codice fiscale</label>
                <div className="login-input-wrap">
                  <span className="ic">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2" /><circle cx="12" cy="7" r="4" /></svg>
                  </span>
                  <input type="text" id="username" name="username" required autoComplete="username" autoFocus
                    autoCapitalize="none" autoCorrect="off" spellCheck={false} placeholder="Username, email o codice fiscale"
                    value={username} onChange={(e) => setUsername(e.target.value)} />
                </div>
              </div>

              <div className="login-fg">
                <label htmlFor="password">Password</label>
                <div className="login-input-wrap">
                  <span className="ic">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round"><rect x="3" y="11" width="18" height="11" rx="2" /><path d="M7 11V7a5 5 0 0 1 10 0v4" /></svg>
                  </span>
                  <input type={mostra ? 'text' : 'password'} id="password" name="password" required autoComplete="current-password"
                    placeholder="••••••••" value={password} onChange={(e) => setPassword(e.target.value)} />
                  <button type="button" className="toggle-pw" id="togglePw" aria-label={mostra ? 'Nascondi password' : 'Mostra password'} onClick={() => setMostra(!mostra)}>
                    <svg id="eyeOn" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round" style={mostra ? { display: 'none' } : undefined}><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z" /><circle cx="12" cy="12" r="3" /></svg>
                    <svg id="eyeOff" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round" style={mostra ? undefined : { display: 'none' }}><path d="M17.94 17.94A10.07 10.07 0 0 1 12 20c-7 0-11-8-11-8a18.45 18.45 0 0 1 5.06-5.94M9.9 4.24A9.12 9.12 0 0 1 12 4c7 0 11 8 11 8a18.5 18.5 0 0 1-2.16 3.19m-6.72-1.07a3 3 0 1 1-4.24-4.24" /><line x1="1" y1="1" x2="23" y2="23" /></svg>
                  </button>
                </div>
              </div>

              <div className="login-options">
                <span></span>
                <a href="/auth/forgot-password.php">Password dimenticata?</a>
              </div>

              <button type="submit" className="login-btn">
                Accedi
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round"><line x1="5" y1="12" x2="19" y2="12" /><polyline points="12 5 19 12 12 19" /></svg>
              </button>
            </form>
          </div>

          <InstallaApp />
        </div>
      </main>
    </div>
  );
}
