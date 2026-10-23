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
function Contenuto({ html }: { html: string }) {
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
