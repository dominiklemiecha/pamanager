import { useEffect, useLayoutEffect, useMemo, useRef, useState } from 'react';
import { esegui, P } from '../php/runtime';
import { api } from '../services/api';
import './funzioni-cora';
import { StaffShell, useCorniceStaff } from './StaffShell';

type Render = (V: any, F: any) => void;

const leggiCsrf = () => decodeURIComponent(document.cookie.match(/(?:^|;\s*)cora_csrf=([^;]+)/)?.[1] ?? '');

// Classi e funzioni di Cora usate dai template.
P.estendi('CSRF::field', () => `<input type="hidden" name="csrf_token" value="${P.fn.htmlspecialchars(leggiCsrf())}">`);
P.estendi('CSRF::token', () => leggiCsrf());
P.estendi('CSRF::getToken', () => leggiCsrf());
P.estendi('CSRF::generate', () => leggiCsrf());

/** Indirizzo API della pagina PHP: /admin/index.php -> /pagine/admin/index. */
export function apiDiPagina(percorso: string): string | null {
  const m = /^\/([a-z-]+)\/(?:([a-z0-9-]+)\.php)?$/.exec(percorso);
  if (!m) return null;
  return `/pagine/${m[1]}/${m[2] ?? 'index'}`;
}

/** HTML del template con gli script eseguiti in ordine (come durante il caricamento della pagina PHP). */
export function Contenuto({ html }: { html: string }) {
  const rif = useRef<HTMLDivElement>(null);
  useLayoutEffect(() => {
    const box = rif.current;
    if (!box) return;
    box.innerHTML = html;
    for (const vecchio of Array.from(box.querySelectorAll('script'))) {
      const s = document.createElement('script');
      for (const a of Array.from(vecchio.attributes)) s.setAttribute(a.name, a.value);
      if (vecchio.src) s.async = false;
      s.text = vecchio.text;
      vecchio.replaceWith(s);
    }
    // Nel PHP gli script misuravano la pagina gia' completa: qui la cornice si
    // assesta dopo, quindi quando cambia la larghezza del contenuto gli script
    // che si adattano alla finestra ricalcolano (come a un resize).
    const contenitore = box.parentElement;
    if (!contenitore || typeof ResizeObserver === 'undefined') return undefined;
    let larghezza = contenitore.clientWidth;
    const osserva = new ResizeObserver(() => {
      if (contenitore.clientWidth === larghezza) return;
      larghezza = contenitore.clientWidth;
      window.dispatchEvent(new Event('resize'));
    });
    osserva.observe(contenitore);
    return () => osserva.disconnect();
  }, [html]);
  return <div ref={rif} style={{ display: 'contents' }} />;
}

/**
 * Pagina dell'area staff portata dal PHP: variabili dal backend, HTML dal
 * template convertito, cornice dello staff intorno. I moduli POST vanno al
 * backend (stesso ramo POST della pagina PHP): redirect o pagina ridisegnata.
 * `prepara` adatta le variabili prima del template (es. date come DateTime).
 * `scarica` elenca i parametri GET con cui la pagina PHP serviva un file:
 * quei link vanno direttamente al backend.
 */
export function PaginaStaff({ render, prepara, scarica = [] }: { render: Render; prepara?: (V: any) => void; scarica?: string[] }) {
  const percorso = location.pathname;
  const indirizzo = apiDiPagina(percorso);
  const download = (u: URL) => scarica.some((k) => u.searchParams.has(k));
  if (download(new URL(location.href))) location.replace('/api/v1' + indirizzo + location.search);
  const dati = useMemo(() => api.get(indirizzo + location.search), [indirizzo]);
  const cornice = useCorniceStaff(dati);
  const [stato, setStato] = useState<{ titolo: string; V: any } | null>(null);

  const mostra = (d: any) => {
    if (d?.redirect) { location.assign(new URL(d.redirect, location.href).toString()); return; }
    const V = d.V ?? {};
    prepara?.(V);
    setStato({ titolo: d.titolo, V });
  };

  useEffect(() => {
    dati.then((r) => mostra(r.data)).catch((e) => {
      if (e?.response?.status === 403) location.replace('/auth/login.php');
    });
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [dati]);

  // Moduli POST della pagina (anche quelli che puntano ad altre pagine PHP dell'area).
  useEffect(() => {
    /** Invia al backend un modulo POST della pagina; false se non e' da gestire qui. */
    const inviaModulo = (f: HTMLFormElement, bottone: HTMLButtonElement | null): boolean => {
      if ((f.getAttribute('method') ?? 'get').toLowerCase() !== 'post') return false;
      const destinazione = new URL(f.getAttribute('action') || location.href, location.href);
      if (/\/auth\/(switch|leave)-tenant\.php$/.test(destinazione.pathname)) return false;
      const api2 = apiDiPagina(destinazione.pathname);
      if (!api2) return false;
      const corpo = new FormData(f);
      if (bottone?.name) corpo.append(bottone.name, bottone.value);
      // Modulo verso un'altra scheda (es. anteprima PDF): la scheda si apre subito
      // (niente blocco dei popup) e riceve il file prodotto dal backend.
      const bersaglio = f.getAttribute('target');
      if (bersaglio && bersaglio !== '_self') {
        const scheda = window.open('', bersaglio);
        (async () => {
          try {
            const r = await api.post(api2 + destinazione.search, corpo, { responseType: 'blob' });
            const url = URL.createObjectURL(r.data as Blob);
            if (scheda) scheda.location.href = url; else window.open(url, '_blank');
            setTimeout(() => URL.revokeObjectURL(url), 60_000);
          } catch {
            scheda?.close();
            alert('Operazione non riuscita');
          }
        })();
        return true;
      }
      (async () => {
        try {
          const r = await api.post(api2 + destinazione.search, corpo);
          if (r.data?.redirect) location.assign(new URL(r.data.redirect, destinazione).toString());
          else {
            // Come il PHP: la pagina ridisegnata dopo il POST riparte dall'alto.
            mostra(r.data);
            window.scrollTo(0, 0);
          }
        } catch (err: any) {
          alert(err?.response?.data?.message ?? 'Operazione non riuscita');
        }
      })();
      return true;
    };
    const invio = (e: SubmitEvent) => {
      if (e.defaultPrevented) return;
      if (inviaModulo(e.target as HTMLFormElement, e.submitter as HTMLButtonElement | null)) e.preventDefault();
    };
    // Chiamate AJAX degli script della pagina (fetch verso gli indirizzi .php):
    // il backend vuole il token CSRF nell'header, il PHP lo leggeva dal corpo.
    const fetchNativo = window.fetch;
    window.fetch = (input: RequestInfo | URL, init?: RequestInit) => {
      const metodo = (init?.method ?? (input instanceof Request ? input.method : 'GET')).toUpperCase();
      const url = new URL(input instanceof Request ? input.url : String(input), location.href);
      if (url.origin === location.origin && !['GET', 'HEAD', 'OPTIONS'].includes(metodo)) {
        const h = new Headers(init?.headers ?? (input instanceof Request ? input.headers : undefined));
        if (!h.has('X-CSRF-Token')) h.set('X-CSRF-Token', leggiCsrf());
        init = { ...init, headers: h, credentials: init?.credentials ?? 'same-origin' };
      }
      return fetchNativo(input, init);
    };
    // form.submit() dagli script della pagina non genera l'evento submit (come nel browser):
    // senza questo andrebbe al server come POST classico.
    const submitNativo = HTMLFormElement.prototype.submit;
    HTMLFormElement.prototype.submit = function (this: HTMLFormElement) {
      if (!inviaModulo(this, null)) submitNativo.call(this);
    };
    const clic = (e: MouseEvent) => {
      const a = (e.target as HTMLElement | null)?.closest?.('a[href]') as HTMLAnchorElement | null;
      if (!a || e.defaultPrevented) return;
      const u = new URL(a.href, location.href);
      const api2 = u.origin === location.origin ? apiDiPagina(u.pathname) : null;
      if (!api2 || !download(u)) return;
      e.preventDefault();
      location.assign('/api/v1' + api2 + u.search);
    };
    document.addEventListener('submit', invio);
    document.addEventListener('click', clic);
    return () => {
      document.removeEventListener('submit', invio);
      document.removeEventListener('click', clic);
      HTMLFormElement.prototype.submit = submitNativo;
      window.fetch = fetchNativo;
    };
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, []);

  const html = useMemo(() => (stato ? esegui(render, stato.V) : ''), [stato, render]);
  if (!cornice || !stato) return null;
  const pagina = percorso.split('/').pop()?.replace(/\.php$/, '') || 'index';
  return (
    <StaffShell pagina={pagina} titolo={stato.titolo} cornice={cornice}>
      <Contenuto html={html} />
    </StaffShell>
  );
}
