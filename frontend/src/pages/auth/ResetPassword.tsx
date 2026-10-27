import { FormEvent, useEffect, useState } from 'react';
import { useSearchParams } from 'react-router-dom';
import { messaggioErrore } from '../../componenti/sessione';
import { useClasseBody, useCss, useStile, useTitolo } from '../../componenti/stile';
import { api } from '../../services/api';
import css from './reset-password.css?inline';

/** Nuova password dal link ricevuto via email (auth/reset-password.php). */
export default function ResetPassword() {
  useCss('/assets/css/style.css', '/assets/css/theme.css');
  useStile(css);
  useClasseBody('login-page');
  useTitolo('Reimposta Password - Connecteed HR');
  const [parametri] = useSearchParams();
  const token = parametri.get('token') ?? '';
  const [dati, setDati] = useState<{ valido: boolean; nome?: string; username?: string } | null>(null);
  const [password, setPassword] = useState('');
  const [conferma, setConferma] = useState('');
  const [mostra, setMostra] = useState(false);
  const [errore, setErrore] = useState('');
  const [fatto, setFatto] = useState(false);

  useEffect(() => {
    api.post('/auth/reset/verifica', { token }).then((r) => setDati(r.data)).catch(() => setDati({ valido: false }));
  }, [token]);

  async function invia(e: FormEvent) {
    e.preventDefault();
    setErrore('');
    try {
      await api.post('/auth/reset', { token, password, conferma });
      setFatto(true);
    } catch (err) {
      setErrore(messaggioErrore(err, 'Errore sconosciuto'));
    }
  }

  if (!dati) return null;

  return (
    <div className="login-container">
      <div className="login-card">
        <div className="login-header">
          <h1>Connecteed HR</h1>
          <p>Reimposta Password</p>
        </div>

        {fatto ? (
          <>
            <div className="success-box">
              <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor">
                <path d="M12 2C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12 2zm-2 15l-5-5 1.41-1.41L10 14.17l7.59-7.59L19 8l-9 9z" />
              </svg>
              <h3>Password Aggiornata</h3>
              <p>La tua password è stata reimpostata con successo. Ora puoi accedere con la nuova password.</p>
            </div>
            <div style={{ marginTop: '1.5rem', textAlign: 'center' }}>
              <a href="/auth/login" className="btn btn-primary">Vai al Login</a>
            </div>
          </>
        ) : !dati.valido ? (
          <>
            <div className="error-box">
              <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor">
                <path d="M12 2C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12 2zm1 15h-2v-2h2v2zm0-4h-2V7h2v6z" />
              </svg>
              <h3>Link Non Valido</h3>
              <p>Questo link per il reset password non è valido o è scaduto. Richiedi un nuovo link di reset.</p>
            </div>
            <div style={{ marginTop: '1.5rem', textAlign: 'center' }}>
              <a href="/auth/forgot-password" className="btn btn-primary">Richiedi Nuovo Reset</a>
            </div>
          </>
        ) : (
          <>
            {errore && <div className="alert alert-error">{errore}</div>}
            <p style={{ marginBottom: '1rem', color: '#4a5568', fontSize: '0.9rem' }}>
              Ciao <strong>{dati.nome || 'Utente'}</strong>, inserisci la nuova password per l'account <strong>{dati.username}</strong>.
            </p>
            <div className="password-requirements">
              <h4>Requisiti password:</h4>
              <ul>
                <li>Almeno 12 caratteri</li>
                <li>Almeno una lettera maiuscola</li>
                <li>Almeno una lettera minuscola</li>
                <li>Almeno un numero</li>
                <li>Almeno un carattere speciale (!@#$%^&amp;*)</li>
              </ul>
            </div>
            <form method="POST" className="login-form" onSubmit={invia}>
              <div className="form-group">
                <label htmlFor="password">Nuova Password</label>
                <input type={mostra ? 'text' : 'password'} id="password" name="password" required autoComplete="new-password" minLength={12}
                  value={password} onChange={(e) => setPassword(e.target.value)} />
              </div>
              <div className="form-group">
                <label htmlFor="confirm_password">Conferma Password</label>
                <input type={mostra ? 'text' : 'password'} id="confirm_password" name="confirm_password" required autoComplete="new-password" minLength={12}
                  value={conferma} onChange={(e) => setConferma(e.target.value)} />
              </div>
              <label className="show-password">
                <input type="checkbox" id="showPassword" checked={mostra} onChange={(e) => setMostra(e.target.checked)} />
                Mostra password
              </label>
              <button type="submit" className="btn btn-primary btn-block" style={{ marginTop: '1rem' }}>Reimposta Password</button>
            </form>
          </>
        )}

        <div className="login-footer">
          <a href="/auth/login" className="back-link">Torna al login</a>
        </div>
      </div>
    </div>
  );
}
