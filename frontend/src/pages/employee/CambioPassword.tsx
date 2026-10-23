import { FormEvent, useMemo, useState } from 'react';
import { useSessione } from '../../componenti/sessione';
import { useCss, useClasseBody, useTitolo } from '../../componenti/stile';
import { CoraShell, useCornice } from '../../cora/CoraShell';
import { api } from '../../services/api';

interface Errore { errore?: string; errori?: string[] }

/** Campi e invio comuni alle due versioni della pagina. */
function useModulo() {
  const [errore, setErrore] = useState<Errore>({});
  async function invia(e: FormEvent<HTMLFormElement>) {
    e.preventDefault();
    const modulo = e.currentTarget;
    const f = new FormData(modulo);
    try {
      const { data } = await api.post('/auth/password', {
        attuale: String(f.get('current_password') ?? ''), nuova: String(f.get('new_password') ?? ''), conferma: String(f.get('confirm_password') ?? ''),
      });
      location.assign(data?.destinazione ?? '/employee/change-password.php?success=1');
    } catch (err: any) {
      const d = err?.response?.data ?? {};
      setErrore(d.errori?.length ? { errori: d.errori } : { errore: d.message ?? 'Errore durante il cambio password' });
      // Come la pagina PHP ricaricata: campi vuoti, cursore sulla password attuale.
      modulo.reset();
      modulo.querySelector<HTMLInputElement>('#current_password')?.focus();
    }
  }
  return { errore, invia };
}

function Errori({ e }: { e: Errore }) {
  return (
    <>
      {e.errore && <div className="alert alert-error">{e.errore}</div>}
      {e.errori && (
        <div className="alert alert-error">
          <ul style={{ margin: 0, paddingLeft: '1.2rem' }}>{e.errori.map((x) => <li key={x}>{x}</li>)}</ul>
        </div>
      )}
    </>
  );
}

/** Primo accesso: pagina a parte, senza menu, finche' la password non e' cambiata. */
function Obbligatoria() {
  useCss('/assets/css/style.css', '/assets/css/theme.css');
  useClasseBody('login-page');
  useTitolo('Imposta nuova password - PAManager');
  const { errore, invia } = useModulo();
  return (
    <div className="login-container">
      <div className="login-card">
        <div className="login-header">
          <h1>PAManager</h1>
          <p>Primo accesso: imposta una nuova password</p>
        </div>
        <div className="alert alert-warning">
          Per motivi di sicurezza devi impostare una nuova password personale prima di poter accedere all'area dipendente.
        </div>
        <Errori e={errore} />
        <form method="POST" className="login-form" onSubmit={invia}>
          <div className="form-group">
            <label htmlFor="current_password">Password attuale</label>
            <input type="password" id="current_password" name="current_password" required autoComplete="current-password" autoFocus />
          </div>
          <div className="form-group">
            <label htmlFor="new_password">Nuova password</label>
            <input type="password" id="new_password" name="new_password" required autoComplete="new-password" />
            <small className="text-muted">Minimo 12 caratteri, con maiuscole, minuscole, numeri e caratteri speciali.</small>
          </div>
          <div className="form-group">
            <label htmlFor="confirm_password">Conferma nuova password</label>
            <input type="password" id="confirm_password" name="confirm_password" required autoComplete="new-password" />
          </div>
          <button type="submit" className="btn btn-primary btn-block">Imposta Password</button>
        </form>
        <div className="login-footer">
          <a href="/auth/logout.php" className="back-link">Esci</a>
        </div>
      </div>
    </div>
  );
}

/** Cambio volontario, dentro l'area del dipendente. */
function Volontaria() {
  const cornice = useCornice();
  const riuscito = useMemo(() => new URLSearchParams(location.search).has('success'), []);
  const { errore, invia } = useModulo();
  if (!cornice) return null;
  return (
    <CoraShell pagina="change-password" titolo="Cambia Password" cornice={cornice} classica>
      <div className="container">
        <div className="page-header">
          <h2>Cambia Password</h2>
          <p className="text-muted">Aggiorna la tua password di accesso. Per sicurezza ti consigliamo di cambiarla periodicamente.</p>
        </div>
        {riuscito && !errore.errore && !errore.errori && <div className="alert alert-success">Password aggiornata con successo.</div>}
        <Errori e={errore} />
        <div className="card" style={{ maxWidth: 520 }}>
          <div className="card-body">
            <form method="POST" onSubmit={invia}>
              <div className="form-group">
                <label htmlFor="current_password">Password attuale</label>
                <input type="password" id="current_password" name="current_password" required autoComplete="current-password" />
              </div>
              <div className="form-group">
                <label htmlFor="new_password">Nuova password</label>
                <input type="password" id="new_password" name="new_password" required autoComplete="new-password" />
                <small className="text-muted">Minimo 12 caratteri, con maiuscole, minuscole, numeri e caratteri speciali.</small>
              </div>
              <div className="form-group">
                <label htmlFor="confirm_password">Conferma nuova password</label>
                <input type="password" id="confirm_password" name="confirm_password" required autoComplete="new-password" />
              </div>
              <div className="form-actions">
                <button type="submit" className="btn btn-primary">Aggiorna Password</button>
              </div>
            </form>
          </div>
        </div>
      </div>
    </CoraShell>
  );
}

/** Cambio password del dipendente (employee/change-password.php): obbligatorio o volontario. */
export default function CambioPassword() {
  const s = useSessione();
  if (!s.caricata) return null;
  if (!s.utente) { location.replace('/auth/login.php'); return null; }
  return s.deveCambiarePassword ? <Obbligatoria /> : <Volontaria />;
}
