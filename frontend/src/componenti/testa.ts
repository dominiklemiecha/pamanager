import { useLayoutEffect } from 'react';

export interface Testa {
  manifest?: string;
  themeColor?: string;
  appleTitle?: string;
  appleIcon?: string;
  statusBar?: string;
  icone?: Array<{ href: string; sizes?: string; type?: string }>;
}

/**
 * Meta e link dell'<head> di ogni area, come li metteva il PHP: il manifest
 * della web app (classico o Cora), il colore della barra e le icone.
 * Si tolgono quando si cambia area.
 */
export function useTesta(t: Testa): void {
  const chiave = JSON.stringify(t);
  useLayoutEffect(() => {
    const nodi: HTMLElement[] = [];
    const aggiungi = (tag: string, attr: Record<string, string>) => {
      const el = document.createElement(tag);
      for (const [k, v] of Object.entries(attr)) el.setAttribute(k, v);
      el.setAttribute('data-cora-testa', '');
      document.head.appendChild(el);
      nodi.push(el);
    };
    if (t.manifest) aggiungi('link', { rel: 'manifest', href: t.manifest });
    if (t.themeColor) aggiungi('meta', { name: 'theme-color', content: t.themeColor });
    if (t.appleTitle) aggiungi('meta', { name: 'apple-mobile-web-app-title', content: t.appleTitle });
    if (t.appleIcon) aggiungi('link', { rel: 'apple-touch-icon', href: t.appleIcon });
    if (t.statusBar) {
      aggiungi('meta', { name: 'apple-mobile-web-app-capable', content: 'yes' });
      aggiungi('meta', { name: 'apple-mobile-web-app-status-bar-style', content: t.statusBar });
    }
    for (const i of t.icone ?? []) aggiungi('link', { rel: 'icon', href: i.href, ...(i.sizes ? { sizes: i.sizes } : {}), ...(i.type ? { type: i.type } : {}) });
    return () => nodi.forEach((n) => n.remove());
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [chiave]);
}

/** Testa delle pagine "classiche" (login, staff): manifest PAManager. */
export const TESTA_CLASSICA: Testa = {
  manifest: '/manifest.json.php',
  themeColor: '#1a365d',
  appleTitle: 'Connecteed HR',
  appleIcon: '/assets/images/icon.php?size=180&v=4',
  statusBar: 'black-translucent',
};
