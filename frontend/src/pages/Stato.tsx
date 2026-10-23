import { useEffect, useState } from 'react';
import { api } from '../services/api';

/** Pagina provvisoria: dice se il backend risponde. Sparisce con la prima pagina migrata. */
export default function Stato() {
  const [ok, setOk] = useState<boolean | null>(null);
  useEffect(() => {
    api.get('/health').then(() => setOk(true)).catch(() => setOk(false));
  }, []);
  return <p>Cora · backend {ok === null ? '...' : ok ? 'raggiungibile' : 'non raggiungibile'}</p>;
}
