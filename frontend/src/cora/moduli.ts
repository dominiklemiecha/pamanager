import { useEffect } from 'react';
import { api } from '../services/api';

/**
 * Invio dei moduli delle pagine Cora.
 *
 * Nel PHP i moduli facevano POST alla pagina: in caso di successo redirect con
 * ?message=..., in caso di errore la pagina si ridisegnava con l'errore e i
 * valori inviati. Qui:
 *  - il modulo (marcato con data-invio="chiave") viene intercettato a livello
 *    di documento, dopo gli script della pagina (che possono bloccarlo con
 *    preventDefault per la loro validazione);
 *  - successo: si ricarica la pagina con lo stesso ?message= del PHP;
 *  - errore: messaggio e valori inviati restano per il ricaricamento, e la
 *    pagina li mostra come faceva il PHP.
 */
const CHIAVE = 'cora-esito-modulo';

export interface EsitoPrecedente { modulo: string; errore: string; valori: Record<string, string> }

/** Errore e valori dell'invio precedente (una volta sola). */
export function esitoPrecedente(): EsitoPrecedente | null {
  try {
    const v = sessionStorage.getItem(CHIAVE);
    if (!v) return null;
    sessionStorage.removeItem(CHIAVE);
    const e = JSON.parse(v);
    return e?.percorso === location.pathname ? e : null;
  } catch {
    return null;
  }
}

export function useInvioModuli(gestori: Record<string, { url: (dati: FormData) => string; messaggio: string; mantieni?: string[] }>, attivo: boolean) {
  useEffect(() => {
    if (!attivo) return;
    const invio = async (e: SubmitEvent) => {
      const form = e.target as HTMLFormElement;
      const chiave = form?.dataset?.invio;
      if (!chiave || !gestori[chiave] || e.defaultPrevented) return;
      e.preventDefault();
      // onsubmit="return confirm(...)" del PHP
      if (form.dataset.conferma && !window.confirm(form.dataset.conferma)) return;
      const dati = new FormData(form);
      try {
        await api.post(gestori[chiave].url(dati), dati);
        const u = new URL(location.href);
        const prima = new URLSearchParams(u.search);
        u.search = '';
        // Parametri da conservare (es. ?id= della comunicazione aperta).
        for (const k of gestori[chiave].mantieni ?? []) { const v = prima.get(k); if (v !== null) u.searchParams.set(k, v); }
        u.searchParams.set('message', gestori[chiave].messaggio);
        location.assign(u.pathname + u.search);
      } catch (err: any) {
        const valori: Record<string, string> = {};
        dati.forEach((v, k) => { if (typeof v === 'string') valori[k] = v; });
        try {
          sessionStorage.setItem(CHIAVE, JSON.stringify({
            percorso: location.pathname, modulo: chiave, errore: err?.response?.data?.message ?? 'Errore imprevisto', valori,
          }));
        } catch { /* niente */ }
        const u = new URL(location.href);
        u.searchParams.delete('message');
        u.searchParams.delete('new');
        location.assign(u.pathname + u.search);
      }
    };
    document.addEventListener('submit', invio);
    return () => document.removeEventListener('submit', invio);
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [attivo]);
}
