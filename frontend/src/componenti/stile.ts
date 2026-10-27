import { useLayoutEffect } from 'react';

/**
 * Fogli di stile delle pagine, presi cosi' come sono dal vecchio PHP.
 *
 * Ogni pagina PHP aveva i suoi CSS (anche con regole su body): qui il foglio
 * si aggiunge quando la pagina si apre e si toglie quando si chiude, cosi' gli
 * stili di una pagina non si mescolano con quelli di un'altra.
 */
export function useStile(...fogli: string[]): void {
  useLayoutEffect(() => {
    const nodi = fogli.map((css) => {
      const el = document.createElement('style');
      el.setAttribute('data-cora', '');
      el.textContent = css;
      document.head.appendChild(el);
      return el;
    });
    return () => nodi.forEach((n) => n.remove());
    // I fogli sono costanti importate: cambiano solo con la pagina.
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, fogli);
}

/** Collegamenti a fogli esterni (font Google) per la durata della pagina. */
export function useFoglioEsterno(href: string): void {
  useLayoutEffect(() => {
    if (document.querySelector(`link[href="${href}"]`)) return;
    const el = document.createElement('link');
    el.rel = 'stylesheet';
    el.href = href;
    document.head.appendChild(el);
    return () => el.remove();
  }, [href]);
}

export function useTitolo(titolo: string): void {
  useLayoutEffect(() => {
    document.title = titolo;
  }, [titolo]);
}

/**
 * Fogli CSS del vecchio PHP serviti da /assets/css (stessi file, stesso ordine
 * dei <link> nelle pagine PHP). Restano solo finche' la pagina e' aperta.
 */
export function useCss(...href: string[]): void {
  useLayoutEffect(() => {
    const fini: Array<() => void> = [];
    const nodi = href.map((h) => {
      const el = document.createElement('link');
      el.rel = 'stylesheet';
      el.href = h;
      el.setAttribute('data-cora', '');
      fini.push(inAttesa(el));
      document.head.appendChild(el);
      return el;
    });
    // Un foglio tolto prima di arrivare non manda piu' "load": non si aspetta.
    return () => {
      nodi.forEach((n) => n.remove());
      fini.forEach((f) => f());
    };
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, href);
}

/**
 * Pagina nascosta finche' i suoi fogli di stile non sono arrivati: senza,
 * per un attimo si vedeva il contenuto senza stile (i <link> si aggiungono
 * dopo il primo disegno). Dopo 3 secondi si mostra comunque.
 */
let attesi = 0;
function inAttesa(el: HTMLLinkElement): () => void {
  attesi++;
  document.documentElement.classList.add('cora-stili-in-arrivo');
  let fatto = false;
  const fine = () => {
    if (fatto) return;
    fatto = true;
    attesi = Math.max(0, attesi - 1);
    if (attesi === 0) document.documentElement.classList.remove('cora-stili-in-arrivo');
  };
  el.addEventListener('load', fine);
  el.addEventListener('error', fine);
  setTimeout(fine, 3000);
  return fine;
}

/** Classe sul <body> per la durata della pagina (es. "login-page", "cora-body"). */
export function useClasseBody(classe: string): void {
  useLayoutEffect(() => {
    const c = classe.split(' ').filter(Boolean);
    document.body.classList.add(...c);
    return () => document.body.classList.remove(...c);
  }, [classe]);
}
