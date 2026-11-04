import { FormEvent, useEffect, useState } from 'react';
import { useSearchParams } from 'react-router-dom';
import { messaggioErrore } from '../../componenti/sessione';
import { useStile, useTitolo } from '../../componenti/stile';
import { TESTA_CLASSICA, useTesta } from '../../componenti/testa';
import { api } from '../../services/api';
import css from './cora-login.css?inline';
import { svgCora } from '../../cora/svg';

const IC_OK = <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round"><circle cx="12" cy="12" r="10" /><polyline points="8 12.5 11 15.5 16 9.5" /></svg>;
const IC_ERRORE = <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round"><circle cx="12" cy="12" r="10" /><line x1="12" y1="8" x2="12" y2="12" /><line x1="12" y1="16" x2="12.01" y2="16" /></svg>;

/** Nuova password dal link ricevuto via email (auth/reset-password.php) con la grafica Cora del login. */
export default function ResetPassword() {
  useStile(css);
  useTitolo('Reimposta password · Cora');
  useTesta(TESTA_CLASSICA);
  const [parametri] = useSearchParams();
  const token = parametri.get('token') ?? '';
  const [dati, setDati] = useState<{ valido: boolean; nome?: string; username?: string } | null>(null);
  const [password, setPassword] = useState('');
  const [conferma, setConferma] = useState('');
  const [mostra, setMostra] = useState(false);
  const [errore, setErrore] = useState('');
  const [fatto, setFatto] = useState(false);
  const [invio, setInvio] = useState(false);

  useEffect(() => {
    api.post('/auth/reset/verifica', { token }).then((r) => setDati(r.data)).catch(() => setDati({ valido: false }));
  }, [token]);

  async function invia(e: FormEvent) {
    e.preventDefault();
    if (invio) return;
    setInvio(true);
    setErrore('');
    try {
      await api.post('/auth/reset', { token, password, conferma });
      setFatto(true);
    } catch (err) {
      setErrore(messaggioErrore(err, 'Errore sconosciuto'));
    }
    setInvio(false);
  }

  if (!dati) return null;

  const occhio = (
    <button type="button" className={'cl-occhio' + (mostra ? ' is-on' : '')} aria-label={mostra ? 'Nascondi password' : 'Mostra password'}
      onClick={() => setMostra(!mostra)} dangerouslySetInnerHTML={{ __html: svgCora('ic-eye.svg') }} />
  );

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
            {fatto ? (
              <>
                <div className="cl-titolo">
                  <h1>Password aggiornata</h1>
                  <p>Ora puoi accedere con la nuova password.</p>
                </div>
                <div className="cl-ok" role="status">{IC_OK}<span>La tua password è stata reimpostata con successo.</span></div>
                <a href="/auth/login" className="cl-btn cl-btn-link">Vai al login</a>
              </>
            ) : !dati.valido ? (
              <>
                <div className="cl-titolo">
                  <h1>Link non valido</h1>
                  <p>Il link è scaduto o è già stato usato.</p>
                </div>
                <div className="cl-errore" role="alert">{IC_ERRORE}<span>Questo link per il reset password non è valido o è scaduto. Richiedi un nuovo link di reset.</span></div>
                <a href="/auth/forgot-password" className="cl-btn cl-btn-link">Richiedi un nuovo link</a>
              </>
            ) : (
              <>
                <div className="cl-titolo">
                  <h1>Nuova password</h1>
                  <p>Ciao <strong>{dati.nome || 'Utente'}</strong>, scegli la nuova password per l'account <strong>{dati.username}</strong>.</p>
                </div>

                {errore && <div className="cl-errore" role="alert">{IC_ERRORE}<span>{errore}</span></div>}

                <form method="POST" onSubmit={invia} className="cl-campi">
                  <div className="cl-campo">
                    <label htmlFor="password">Nuova password</label>
                    <div className="cl-input">
                      <input type={mostra ? 'text' : 'password'} id="password" name="password" required autoComplete="new-password" minLength={12}
                        value={password} onChange={(e) => setPassword(e.target.value)} />
                      {occhio}
                    </div>
                  </div>

                  <div className="cl-campo">
                    <label htmlFor="confirm_password">Conferma password</label>
                    <div className="cl-input">
                      <input type={mostra ? 'text' : 'password'} id="confirm_password" name="confirm_password" required autoComplete="new-password" minLength={12}
                        value={conferma} onChange={(e) => setConferma(e.target.value)} />
                    </div>
                  </div>

                  <div className="cl-requisiti">
                    <span>Requisiti password</span>
                    <ul>
                      <li>Almeno 12 caratteri</li>
                      <li>Almeno una lettera maiuscola e una minuscola</li>
                      <li>Almeno un numero</li>
                      <li>Almeno un carattere speciale (!@#$%^&amp;*)</li>
                    </ul>
                  </div>

                  <button type="submit" className="cl-btn" disabled={invio}>Reimposta password</button>
                </form>
              </>
            )}

            {!fatto && (
              <div className="cl-opzioni cl-opzioni-sx">
                <a href="/auth/login">← Torna al login</a>
              </div>
            )}
          </div>
        </div>
      </main>
    </div>
  );
}
