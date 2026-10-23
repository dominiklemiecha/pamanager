import { FormEvent, useState } from 'react';
import { Navigate } from 'react-router-dom';
import { messaggioErrore, useSessione } from '../../componenti/sessione';
import { useFoglioEsterno, useStile, useTitolo } from '../../componenti/stile';
import { TESTA_CLASSICA, useTesta } from '../../componenti/testa';
import { api } from '../../services/api';
import css from './login.css?inline';

const FONT = 'https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&family=Host+Grotesk:wght@500;600;700;800&display=swap';

/**
 * Secondo passo dell'accesso per lo staff con MFA attiva.
 * Nel PHP il login mandava qui ma la pagina non esisteva: stesso aspetto del login.
 */
export default function MfaVerify() {
  useStile(css);
  useFoglioEsterno(FONT);
  useTitolo('Verifica accesso - Connecteed HR');
  useTesta(TESTA_CLASSICA);
  const sessione = useSessione();
  const [codice, setCodice] = useState('');
  const [errore, setErrore] = useState('');

  if (sessione.caricata && !sessione.mfa) return <Navigate to={sessione.destinazione ?? '/auth/login.php'} replace />;

  async function verifica(e: FormEvent) {
    e.preventDefault();
    setErrore('');
    try {
      const { data } = await api.post('/auth/mfa', { codice });
      window.location.assign(data.destinazione);
    } catch (err) {
      setErrore(messaggioErrore(err, 'Codice non valido'));
    }
  }

  async function annulla() {
    await api.post('/auth/logout', {}).catch(() => undefined);
    window.location.assign('/auth/login.php');
  }

  return (
    <div className="login-wrap" style={{ gridTemplateColumns: '1fr' }}>
      <main className="login-form-side">
        <div style={{ width: '100%', maxWidth: 420 }}>
          <div className="login-card">
            <div className="login-card-h">
              <h1>Verifica accesso</h1>
              <p>Inserisci il codice a 6 cifre dell'app di autenticazione, oppure un codice di riserva.</p>
            </div>
            {errore && (
              <div className="login-err">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round"><circle cx="12" cy="12" r="10" /><line x1="12" y1="8" x2="12" y2="12" /><line x1="12" y1="16" x2="12.01" y2="16" /></svg>
                <span>{errore}</span>
              </div>
            )}
            <form method="POST" onSubmit={verifica}>
              <div className="login-fg">
                <label htmlFor="codice">Codice</label>
                <div className="login-input-wrap">
                  <span className="ic">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round"><rect x="3" y="11" width="18" height="11" rx="2" /><path d="M7 11V7a5 5 0 0 1 10 0v4" /></svg>
                  </span>
                  <input type="text" id="codice" name="codice" required autoFocus inputMode="numeric" autoComplete="one-time-code"
                    placeholder="123456" value={codice} onChange={(e) => setCodice(e.target.value)} />
                </div>
              </div>
              <button type="submit" className="login-btn">
                Verifica
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round"><line x1="5" y1="12" x2="19" y2="12" /><polyline points="12 5 19 12 12 19" /></svg>
              </button>
            </form>
            <div className="login-foot"><a href="#" onClick={(e) => { e.preventDefault(); annulla(); }}>Annulla e torna al login</a></div>
          </div>
        </div>
      </main>
    </div>
  );
}
