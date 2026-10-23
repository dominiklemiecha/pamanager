import { createContext, ReactNode, useCallback, useContext, useEffect, useState } from 'react';
import { api } from '../services/api';

export interface UtenteSessione {
  tipo: 'user' | 'employee';
  id: number;
  ruolo: string;
  nome: string;
  username: string;
  email: string | null;
  azienda: number;
  reparto: number | null;
}

interface StatoSessione {
  caricata: boolean;
  utente: UtenteSessione | null;
  mfa: boolean;
  deveCambiarePassword: boolean;
  destinazione: string | null;
  ricarica: () => Promise<void>;
}

const Contesto = createContext<StatoSessione | null>(null);

/** Stato dell'accesso letto dal backend (la sessione vive in un cookie httpOnly). */
export function SessioneProvider({ children }: { children: ReactNode }) {
  const [s, setS] = useState<Omit<StatoSessione, 'ricarica'>>({ caricata: false, utente: null, mfa: false, deveCambiarePassword: false, destinazione: null });
  const ricarica = useCallback(async () => {
    try {
      const { data } = await api.get('/auth/sessione');
      setS({
        caricata: true,
        utente: data.autenticato ? data.utente : null,
        mfa: !!data.mfa,
        deveCambiarePassword: !!data.deveCambiarePassword,
        destinazione: data.destinazione ?? null,
      });
    } catch {
      setS({ caricata: true, utente: null, mfa: false, deveCambiarePassword: false, destinazione: null });
    }
  }, []);
  useEffect(() => { ricarica(); }, [ricarica]);
  return <Contesto.Provider value={{ ...s, ricarica }}>{children}</Contesto.Provider>;
}

export function useSessione(): StatoSessione {
  const c = useContext(Contesto);
  if (!c) throw new Error('useSessione fuori da SessioneProvider');
  return c;
}

/** Messaggio d'errore dalla risposta del backend. */
export function messaggioErrore(e: any, predefinito = 'Errore imprevisto. Riprova.'): string {
  return e?.response?.data?.message || predefinito;
}
