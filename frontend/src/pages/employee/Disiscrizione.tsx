import { useEffect, useMemo, useState } from 'react';
import { useStile, useTitolo } from '../../componenti/stile';
import { api } from '../../services/api';

type Stato = 'invalid' | 'confirm' | 'already' | 'unsubscribed' | 'error';

const CSS = `
    body { font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif; background: #f7fafc; color: #2d3748; margin: 0; padding: 40px 20px; }
    .box { max-width: 500px; margin: 0 auto; background: #fff; border-radius: 12px; padding: 2rem; box-shadow: 0 4px 12px rgba(0,0,0,.06); text-align: center; }
    h1 { font-size: 1.25rem; margin: 0 0 1rem; }
    p { line-height: 1.6; color: #4a5568; }
    .btn { display: inline-block; padding: .75rem 1.5rem; border-radius: 8px; background: #c53030; color: white; text-decoration: none; font-weight: 600; border: none; cursor: pointer; font-size: 1rem; }
    .btn:hover { background: #9b2c2c; }
    .ok { color: #2f855a; }
    .err { color: #c53030; }
`;

/** Disattivazione delle email dal link nei messaggi (employee/unsubscribe.php). Pagina pubblica. */
export default function Disiscrizione() {
  useStile(CSS);
  useTitolo('Disattiva email - PAManager');
  const token = useMemo(() => new URLSearchParams(location.search).get('token') ?? '', []);
  const [esito, setEsito] = useState<{ stato: Stato; nome?: string } | null>(null);
  useEffect(() => {
    api.get('/pubblico/disiscrizione', { params: { token } }).then((r) => setEsito(r.data)).catch(() => setEsito({ stato: 'error' }));
  }, [token]);

  if (!esito) return null;
  const conferma = (e: React.FormEvent) => {
    e.preventDefault();
    api.post('/pubblico/disiscrizione', { token }).then((r) => setEsito(r.data)).catch(() => setEsito({ stato: 'error' }));
  };
  const nome = <strong>{esito.nome}</strong>;

  return (
    <>
      <div className="box">
        {esito.stato === 'confirm' ? (
          <>
            <h1>Disattivare le notifiche email?</h1>
            <p>Ciao {nome}, confermi di voler smettere di ricevere email di notifica dal portale?</p>
            <p style={{ fontSize: '.85rem', color: '#718096' }}>Potrai sempre riattivarle dal tuo profilo dopo aver effettuato l'accesso.</p>
            <form method="POST" onSubmit={conferma}>
              <button type="submit" className="btn">Si, disattiva le email</button>
            </form>
          </>
        ) : esito.stato === 'unsubscribed' ? (
          <>
            <h1 className="ok">Email disattivate</h1>
            <p>Ciao {nome}, non riceverai piu' email di notifica dal portale.</p>
            <p style={{ fontSize: '.85rem', color: '#718096' }}>Puoi riattivarle dal tuo profilo accedendo al portale.</p>
          </>
        ) : esito.stato === 'already' ? (
          <>
            <h1>Email gia' disattivate</h1>
            <p>Ciao {nome}, le notifiche email sono gia' disattivate per il tuo account.</p>
          </>
        ) : esito.stato === 'error' ? (
          <>
            <h1 className="err">Errore</h1>
            <p>Si e' verificato un errore. Riprova piu' tardi o contatta l'amministratore.</p>
          </>
        ) : (
          <>
            <h1 className="err">Link non valido</h1>
            <p>Il link che hai usato non e' valido o e' scaduto.</p>
          </>
        )}
      </div>
    </>
  );
}
