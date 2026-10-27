import { FormEvent, useState } from 'react';
import { Navigate } from 'react-router-dom';
import { messaggioErrore, useSessione } from '../../componenti/sessione';
import { useClasseBody, useCss, useStile, useTitolo } from '../../componenti/stile';
import { api } from '../../services/api';
import css from './forgot-password.css?inline';

/** Recupero password (auth/forgot-password.php). */
export default function ForgotPassword() {
  useCss('/assets/css/style.css', '/assets/css/theme.css');
  useStile(css);
  useClasseBody('login-page');
  useTitolo('Recupero Password - Connecteed HR');
  const sessione = useSessione();
  const [identificativo, setIdentificativo] = useState('');
  const [messaggio, setMessaggio] = useState('');
  const [errore, setErrore] = useState('');

  // Come il PHP: lo staff gia' collegato torna all'area admin.
  if (sessione.utente?.tipo === 'user') return <Navigate to="/admin/" replace />;

  async function invia(e: FormEvent) {
    e.preventDefault();
    setErrore('');
    try {
      const { data } = await api.post('/auth/recupero', { identificativo });
      setMessaggio(data.message);
    } catch (err) {
      setErrore(messaggioErrore(err, 'Errore durante la richiesta'));
    }
  }

  return (
    <div className="login-container">
      <div className="login-card">
        <div className="login-header">
          <h1>Connecteed HR</h1>
          <p>Recupero Password</p>
        </div>

        {messaggio ? (
          <>
            <div className="success-box">
              <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor">
                <path d="M12 2C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12 2zm-2 15l-5-5 1.41-1.41L10 14.17l7.59-7.59L19 8l-9 9z" />
              </svg>
              <h3>Controlla la tua email</h3>
              <p>{messaggio}</p>
            </div>
            <div style={{ marginTop: '1.5rem', textAlign: 'center' }}>
              <a href="/auth/login" className="btn btn-primary">Torna al Login</a>
            </div>
          </>
        ) : (
          <>
            {errore && <div className="alert alert-error">{errore}</div>}
            <div className="reset-info">
              <p>
                Inserisci il tuo username, email o codice fiscale. Se l'account esiste, riceverai via email un link per reimpostare la password (valido 1 ora).
              </p>
            </div>
            <form method="POST" className="login-form" onSubmit={invia}>
              <div className="form-group">
                <label htmlFor="identifier">Username, email o codice fiscale</label>
                <input type="text" id="identifier" name="identifier" required autoComplete="username" autoFocus
                  autoCapitalize="none" autoCorrect="off" spellCheck={false}
                  placeholder="es. mario.rossi, mario@azienda.it o RSSMRA80A01H501U"
                  value={identificativo} onChange={(e) => setIdentificativo(e.target.value)} />
              </div>
              <button type="submit" className="btn btn-primary btn-block">Richiedi Reset Password</button>
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
