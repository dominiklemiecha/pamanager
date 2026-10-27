/**
 * Indirizzi delle pagine senza l'estensione .php. Il backend risponde ancora
 * con gli indirizzi del PHP (stessi redirect, confrontati nei test di
 * equivalenza): qui diventano quelli nuovi prima di navigare.
 */

/** Indirizzi che finiscono in .php ma non sono pagine (servizi, file, carte NFC): restano al backend. */
const NON_PAGINE = /^\/(api|uploads)\/|^\/(punch|verifica-attestato|communication_file|academy-file)\.php$|^\/employee\/contract-pdf\.php$|^\/auth\/(switch|leave)-tenant\.php$|\.json\.php$/;
const PAGINA_PHP = /^((?:\/[a-z0-9-]+)*\/)([a-z0-9_-]+)\.php$/;

/** Percorso della pagina senza .php (/x/index.php diventa /x/), o null se non c'e' niente da cambiare. */
export function percorsoPulito(percorso: string): string | null {
  const m = PAGINA_PHP.exec(percorso);
  if (!m || NON_PAGINE.test(percorso)) return null;
  return m[2] === 'index' ? m[1] : m[1] + m[2];
}

/** Indirizzo (assoluto o relativo) con il percorso della pagina pulito, stessa query e stesso frammento. */
export function indirizzoPulito(url: string, base: string = location.href): string {
  const u = new URL(url, base);
  if (u.origin !== location.origin) return url;
  const p = percorsoPulito(u.pathname);
  return p === null ? u.pathname + u.search + u.hash : p + u.search + u.hash;
}
