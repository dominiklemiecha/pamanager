import { ReactNode, useEffect, useLayoutEffect, useMemo, useState } from 'react';
import { useClasseBody, useCss, useFoglioEsterno, useStile } from '../componenti/stile';
import { TESTA_CLASSICA, useTesta } from '../componenti/testa';
import { caricaWidget } from '../cora/CoraShell';
import { api } from '../services/api';
import { htmlPiede } from './piede';
import { htmlTestata } from './testata';
import type { CorniceStaff } from './tipi';
import { pulisciScriptPhp, tracciaScriptPhp } from '../services/scriptPhp';

const FONT = 'https://fonts.googleapis.com/css2?family=Space+Grotesk:wght@500;600;700&family=Host+Grotesk:wght@400;500;600;700&display=swap';

/**
 * Ultima cornice ricevuta: finche' l'app resta aperta (cambio azienda e uscita
 * ricaricano la pagina) le pagine successive si disegnano subito con questa e
 * intanto chiedono quella aggiornata.
 */
let ultimaCornice: CorniceStaff | null = null;

/** Dati della cornice dello staff; la pagina si disegna quando ci sono. */
export function useCorniceStaff(dopo?: Promise<unknown>): CorniceStaff | null {
  const [c, setC] = useState<CorniceStaff | null>(ultimaCornice);
  useEffect(() => {
    Promise.resolve(dopo).catch(() => undefined).then(() => api.get('/staff/cornice')).then((r) => { ultimaCornice = r.data; setC(r.data); }).catch(() => undefined);
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, []);
  return c;
}

function carica(src: string, attr: Record<string, string> = {}): Promise<void> {
  return new Promise((ok) => {
    const s = document.createElement('script');
    s.src = src;
    s.async = false;
    for (const [k, v] of Object.entries(attr)) s.setAttribute(k, v);
    s.onload = () => ok();
    s.onerror = () => ok();
    document.body.appendChild(s);
  });
}

/** Contenitore che non crea un livello nel layout (le regole CSS restano quelle del PHP). */
const Html = ({ html }: { html: string }) => <div style={{ display: 'contents' }} dangerouslySetInnerHTML={{ __html: html }} />;

/**
 * Fogli di stile, font, script del footer PHP e cambio azienda dell'area staff:
 * servono alle pagine convertite sia nella cornice classica sia in quella Cora dell'HR.
 */
export function useRisorseStaff({ cornice, stile, script = [], globali = {}, css = [] }: {
  cornice: CorniceStaff; stile?: string; script?: string[]; globali?: Record<string, unknown>; css?: string[];
}): void {
  useCss('https://rsms.me/inter/inter.css', '/assets/css/theme.css', '/assets/css/components.css', '/assets/css/style.css', ...css);
  useFoglioEsterno(FONT);
  useStile(...(stile ? [stile] : []));
  useEffect(() => {
    const w = window as any;
    if (w.__staffScript) return;
    w.__staffScript = true;
    tracciaScriptPhp();
    let uscita = false;
    w.PAM = { baseUrl: '' };
    Object.assign(w, globali);
    const csrf = decodeURIComponent(document.cookie.match(/(?:^|;\s*)cora_csrf=([^;]+)/)?.[1] ?? '');
    let meta = document.querySelector<HTMLMetaElement>('meta[name="csrf-token"]');
    if (!meta) { meta = document.createElement('meta'); meta.name = 'csrf-token'; document.head.appendChild(meta); }
    meta.content = csrf;
    (async () => {
      for (const s of ['/assets/js/staff-cornice.js', ...script, '/assets/js/app.js', '/assets/js/push.js', '/assets/js/availability-heatmap.js']) {
        if (uscita) return;
        await carica(s);
      }
      if (cornice.ruolo === 'admin' && !uscita) await carica('/assets/js/click-log.js', { 'data-endpoint': '/api/ui-click.php' });
      if (uscita) return;
      document.dispatchEvent(new Event('DOMContentLoaded'));
      caricaWidget(cornice.widget);
    })();

    // Cambio azienda e uscita da un'azienda (form del selettore): al backend.
    const invio = async (e: SubmitEvent) => {
      const f = e.target as HTMLFormElement;
      const azione = f.getAttribute('action') ?? '';
      if (!/\/auth\/(switch|leave)-tenant\.php$/.test(azione) || e.defaultPrevented) return;
      e.preventDefault();
      const dati = new FormData(f);
      const bottone = (e.submitter as HTMLButtonElement | null);
      try {
        if (azione.endsWith('switch-tenant.php')) {
          await api.post('/auth/azienda', { azienda: Number(bottone?.value ?? dati.get('id')) });
          location.reload();
        } else {
          await api.post('/staff/lascia-azienda', { azienda: Number(dati.get('company_id')) });
          location.assign(`/${cornice.area}/?tenant=left`);
        }
      } catch (err: any) {
        location.assign(`/${cornice.area}/?tenant=err:${encodeURIComponent(err?.response?.data?.message ?? '')}`);
      }
    };
    document.addEventListener('submit', invio);
    // Uscita dalla pagina: via gli effetti degli script, la prossima li riesegue da capo.
    return () => {
      uscita = true;
      document.removeEventListener('submit', invio);
      pulisciScriptPhp();
      w.__staffScript = false;
    };
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, []);
}

/**
 * Cornice dell'area staff (header-admin.php + footer-admin.php): menu laterale
 * per ruolo, selettore azienda, finestra Export presenze, barra in basso su
 * telefono e script del footer. `pagina` e' il nome del file PHP senza
 * estensione (per la voce attiva del menu). `stile` e' lo <style> della pagina.
 */
export function StaffShell({ pagina, titolo, cornice, stile, script = [], globali = {}, children }: {
  pagina: string; titolo: string; cornice: CorniceStaff; stile?: string; script?: string[]; globali?: Record<string, unknown>; children: ReactNode;
}) {
  useRisorseStaff({ cornice, stile, script, globali, css: ['/assets/css/mobile-staff.css'] });
  useClasseBody('admin-body');
  useTesta({ ...TESTA_CLASSICA, themeColor: '#2563eb' });
  useLayoutEffect(() => { document.title = titolo; }, [titolo]);

  const testata = useMemo(() => htmlTestata(cornice, pagina), [cornice, pagina]);
  // La testata va divisa: menu laterale (figlio di .app) e testata (dentro .app-main).
  const [laterale, interno] = useMemo(() => {
    const corpo = testata.slice(testata.indexOf('<div class="app">') + '<div class="app">'.length);
    const i = corpo.indexOf('<div class="app-main">');
    const resto = corpo.slice(i + '<div class="app-main">'.length).replace(/<script>[\s\S]*?<\/script>/g, '');
    return [corpo.slice(0, i), resto];
  }, [testata]);

  return (
    <>
      <div className="app">
        <Html html={laterale} />
        <div className="app-main">
          <Html html={interno} />
          <main className="app-content">{children}</main>
          <div className="powered">
            Powered by
            <a href="https://www.connecteed.com" target="_blank" rel="noopener">
              <img src="https://www.connecteed.com/assets/Logon-BsucV_4E.svg" alt="Connecteed" />
            </a>
          </div>
        </div>
      </div>
      <Html html={htmlPiede(cornice, pagina)} />
    </>
  );
}
