import { useEffect } from 'react';
import { api } from '../../services/api';

/** Uscita (auth/logout.php): chiude la sessione sul server e torna alla pagina iniziale. */
export default function Logout() {
  useEffect(() => {
    api.post('/auth/logout', {}).catch(() => undefined).finally(() => location.replace('/'));
  }, []);
  return null;
}
