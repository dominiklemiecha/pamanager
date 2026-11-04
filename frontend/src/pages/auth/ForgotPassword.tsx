import { FormEvent, useState } from 'react';
import { Navigate } from 'react-router-dom';
import { messaggioErrore, useSessione } from '../../componenti/sessione';
import { useStile, useTitolo } from '../../componenti/stile';
import { TESTA_CLASSICA, useTesta } from '../../componenti/testa';
import { api } from '../../services/api';
import css from './cora-login.css?inline';
import { svgCora } from '../../cora/svg';

/** Recupero password (auth/forgot-password.php) con la grafica Cora del login. */
export default function ForgotPassword() {
  useStile(css);
  useTitolo('Recupero password · Cora');
  useTesta(TESTA_CLASSICA);
  const sessione = useSessione();
  const [identificativo, setIdentificativo] = useState('');
  const [messaggio, setMessaggio] = useState('');
  const [errore, setErrore] = useState('');
  const [invio, setInvio] = useState(false);

  // Come il PHP: lo staff gia' collegato torna all'area admin.
  if (sessione.utente?.tipo === 'user') return <Navigate to="/admin/" replace />;

  async function invia(e: FormEvent) {
    e.preventDefault();
    if (invio) return;
    setInvio(true);
    setErrore('');
    try {
      const { data } = await api.post('/auth/recupero', { identificativo });
      setMessaggio(data.message);
    } catch (err) {
      setErrore(messaggioErrore(err, 'Errore durante la richiesta'));
    }
    setInvio(false);
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
              <h1>Recupero password</h1>
              <p>{messaggio ? 'Controlla la tua email.' : 'Ti mandiamo un link per sceglierne una nuova.'}</p>
            </div>

            {messaggio ? (
              <>
                <div className="cl-ok" role="status">
                  <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round"><circle cx="12" cy="12" r="10" /><polyline points="8 12.5 11 15.5 16 9.5" /></svg>
                  <span>{messaggio}</span>
                </div>
                <a href="/auth/login" className="cl-btn cl-btn-link">Torna al login</a>
              </>
            ) : (
              <>
                {errore && (
                  <div className="cl-errore" role="alert">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round"><circle cx="12" cy="12" r="10" /><line x1="12" y1="8" x2="12" y2="12" /><line x1="12" y1="16" x2="12.01" y2="16" /></svg>
                    <span>{errore}</span>
                  </div>
                )}

                <form method="POST" onSubmit={invia} className="cl-campi">
                  <div className="cl-campo">
                    <label htmlFor="identifier">Username, email o codice fiscale</label>
                    <div className="cl-input">
                      <span className="cl-ic" dangerouslySetInnerHTML={{ __html: svgCora('ic-user.svg') }} />
                      <input type="text" id="identifier" name="identifier" required autoComplete="username" autoFocus
                        autoCapitalize="none" autoCorrect="off" spellCheck={false}
                        placeholder="es. mario.rossi o mario@azienda.it"
                        value={identificativo} onChange={(e) => setIdentificativo(e.target.value)} />
                    </div>
                  </div>

                  <button type="submit" className="cl-btn" disabled={invio}>Invia il link</button>

                  <div className="cl-info">
                    <span style={{ display: 'contents' }} dangerouslySetInnerHTML={{ __html: svgCora('ic-info.svg') }} />
                    <span>Se l'account esiste, riceverai via email un link per reimpostare la password, valido 1 ora.</span>
                  </div>
                </form>
              </>
            )}

            <div className="cl-opzioni cl-opzioni-sx">
              <a href="/auth/login">← Torna al login</a>
            </div>
          </div>
        </div>
      </main>
    </div>
  );
}
