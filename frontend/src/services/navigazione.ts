import { useEffect } from 'react';
import { useNavigate } from 'react-router-dom';
import { percorsoPulito } from './indirizzi';

/**
 * Cambio pagina senza ricaricare l'app (niente nuovo download di script,
 * stili e font): i link interni diventano navigazioni del router.
 *
 * Le pagine convertite dal PHP eseguono script che restano attivi (listener
 * su document, variabili globali): dopo averne aperta una si torna al
 * caricamento completo, cosi' ogni pagina parte pulita come nel PHP.
 */
let paginaConScript = false;

/** Da chiamare quando una pagina esegue gli script del vecchio PHP. */
export function segnaScriptPhp(): void {
  paginaConScript = true;
}

/** Indirizzi che non sono pagine dell'app: restano richieste normali. */
const NON_APP = /^\/(api|uploads|assets|realtime)\//;

/** Percorso interno su cui navigare senza ricaricare, o null se il clic va lasciato al browser. */
function destinazione(e: MouseEvent): string | null {
  if (paginaConScript || e.defaultPrevented || e.button !== 0 || e.metaKey || e.ctrlKey || e.shiftKey || e.altKey) return null;
  const a = (e.target as HTMLElement | null)?.closest?.('a[href]') as HTMLAnchorElement | null;
  if (!a || a.hasAttribute('download') || (a.target && a.target !== '_self')) return null;
  const href = a.getAttribute('href') ?? '';
  if (href === '' || href.startsWith('#') || /^(mailto|tel|javascript):/i.test(href)) return null;
  const u = new URL(a.href, location.href);
  if (u.origin !== location.origin || NON_APP.test(u.pathname)) return null;
  // Solo pagine: niente file (pdf, immagini) ne' servizi .php che non sono pagine.
  const ultimo = u.pathname.split('/').pop() ?? '';
  let percorso = u.pathname;
  if (ultimo.includes('.')) {
    const pulito = ultimo.endsWith('.php') ? percorsoPulito(u.pathname) : null;
    if (pulito === null) return null;
    percorso = pulito;
  }
  // Stessa pagina, cambia solo il frammento: lo gestisce il browser.
  if (percorso === location.pathname && u.search === location.search && u.hash !== '') return null;
  return percorso + u.search + u.hash;
}

/** Intercetta i clic sui link interni (in ascolto su window: chi gestisce il clic prima lo ha gia' visto). */
export function useNavigazioneVeloce(): void {
  const naviga = useNavigate();
  useEffect(() => {
    const clic = (e: MouseEvent) => {
      const dove = destinazione(e);
      if (dove === null) return;
      e.preventDefault();
      naviga(dove);
      window.scrollTo(0, 0);
    };
    // Indietro/avanti dopo una pagina con gli script del PHP: pagina ricaricata, pulita.
    const storia = () => { if (paginaConScript) location.reload(); };
    window.addEventListener('click', clic);
    window.addEventListener('popstate', storia);
    return () => {
      window.removeEventListener('click', clic);
      window.removeEventListener('popstate', storia);
    };
  }, [naviga]);
}
