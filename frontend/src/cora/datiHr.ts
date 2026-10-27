import { api } from '../services/api';

/**
 * Dati delle pagine Cora dell'HR tenuti in memoria finche' l'app resta aperta
 * (cambio azienda e uscita ricaricano la pagina, quindi non si mescolano).
 * Una pagina gia' vista o precaricata si disegna subito con questi dati e
 * intanto chiede quelli aggiornati.
 */
const visti = new Map<string, { dati: unknown; quando: number }>();
const inCorso = new Map<string, Promise<unknown>>();

export function datoVisto<T>(percorso: string): T | null {
  return (visti.get(percorso)?.dati as T | undefined) ?? null;
}

export function salvaDato(percorso: string, dati: unknown): void {
  visti.set(percorso, { dati, quando: Date.now() });
}

/** Scarica i dati di una pagina (una richiesta alla volta per indirizzo, salvo `nuova`). */
export function scaricaDato<T>(percorso: string, nuova = false): Promise<T> {
  const gia = inCorso.get(percorso);
  if (gia && !nuova) return gia as Promise<T>;
  const p: Promise<unknown> = api.get(percorso).then((r) => {
    // Una risposta di una richiesta superata non sovrascrive quella piu' recente.
    if (inCorso.get(percorso) === p || !inCorso.has(percorso)) salvaDato(percorso, r.data);
    return r.data;
  }).finally(() => { if (inCorso.get(percorso) === p) inCorso.delete(percorso); });
  inCorso.set(percorso, p);
  return p as Promise<T>;
}

/** Dati delle pagine del dock, per indirizzo della pagina. */
const DATI_PAGINA: Record<string, string> = {
  '/admin/': '/hr/home',
  '/admin/employees': '/hr/dipendenti',
  '/admin/hire-requests': '/hr/assunzioni',
  '/admin/attendance': '/hr/presenze?d=',
  '/admin/departments': '/hr/reparti',
  '/admin/leave-requests': '/hr/ferie',
  '/admin/communications': '/hr/comunicazioni',
  '/admin/documenti-aziendali': '/hr/documenti-aziendali',
  '/admin/consulente-lavoro': '/hr/accessi?ruolo=consulente_lavoro',
  '/admin/formatori': '/hr/accessi?ruolo=formatore',
};

/** Precarica i dati della pagina di un link (passaggio del mouse, tocco) se non sono recenti. */
export function precaricaPagina(href: string): void {
  let u: URL;
  try { u = new URL(href, location.href); } catch { return; }
  if (u.origin !== location.origin || u.search !== '') return;
  const percorso = DATI_PAGINA[u.pathname === '/admin' ? '/admin/' : u.pathname];
  if (!percorso) return;
  const v = visti.get(percorso);
  if (v && Date.now() - v.quando < 15000) return;
  scaricaDato(percorso).catch(() => undefined);
}

/** Dopo la prima pagina, a browser libero, i dati delle voci principali del dock. */
export function precaricaDock(): void {
  const via = () => ['/admin/', '/admin/employees', '/admin/leave-requests', '/admin/communications', '/admin/consulente-lavoro']
    .forEach((p) => precaricaPagina(p));
  const w = window as any;
  if (typeof w.requestIdleCallback === 'function') w.requestIdleCallback(via, { timeout: 3000 });
  else setTimeout(via, 1500);
}
