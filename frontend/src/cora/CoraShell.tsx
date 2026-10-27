import { ReactNode, useEffect, useLayoutEffect, useState } from 'react';
import { useClasseBody, useCss, useFoglioEsterno, useStile, useTitolo } from '../componenti/stile';
import { useTesta } from '../componenti/testa';
import { api } from '../services/api';
import { fa, iniziali } from './formato';
import { svgCora } from './svg';
import { segnaScriptPhp } from '../services/navigazione';

/**
 * Cornice delle pagine Cora del dipendente (header.inc.php + footer.inc.php).
 *
 * React disegna lo stesso markup del PHP; il comportamento (campanella, anello
 * dei moduli, foglio "Altro", modali, filtri, caricamento file, toast, guida)
 * lo danno gli stessi script del PHP (cora.js e quelli della pagina), caricati
 * una volta sola quando i dati sono arrivati, come a fine pagina nel PHP.
 * Dopo il caricamento la pagina non si ridisegna piu': gli script possono
 * spostare nodi (le modali vanno nel body) senza conflitti con React.
 */
export interface Cornice {
  nome: string;
  cognome: string;
  foto: string;
  disponibilita: string;
  contatori: { comunicazioni: number; documenti: number; chat: number; academyDaFare: number; attestatiNuovi: number; inviti: number };
  notifiche: Array<{ id: number; titolo: string; messaggio: string; link: string; letta: boolean; creata: string }>;
  notificheNonLette: number;
  guida: { id: number; sessione: string; stato: string | null };
  contrattoDaFirmare: number | null;
  widget: WidgetAssistenza | null;
}

export interface WidgetAssistenza { src: string; appId: string; sessione: string }

/** Widget assistenza del CRM (ConnecteedWidget::scriptTag), in fondo alla pagina. */
export function caricaWidget(w: WidgetAssistenza | null | undefined) {
  if (!w || document.querySelector(`script[data-app-id]`)) return;
  const s = document.createElement('script');
  s.src = w.src;
  s.async = true;
  s.dataset.appId = w.appId;
  s.dataset.sessionUrl = w.sessione;
  document.body.appendChild(s);
}

const FONT = 'https://fonts.googleapis.com/css2?family=Host+Grotesk:wght@400;500;600;700&display=swap';
const S = ({ n, t, c }: { n: string; t?: boolean; c?: string }) => <span className={c} dangerouslySetInnerHTML={{ __html: svgCora(n, t) }} />;
const I = ({ n, t }: { n: string; t?: boolean }) => <span style={{ display: 'contents' }} dangerouslySetInnerHTML={{ __html: svgCora(n, t) }} />;

const TESTA_CORA = {
  manifest: '/manifest-cora.json.php',
  themeColor: '#f6f8f7',
  appleTitle: 'Cora',
  appleIcon: '/assets/images/cora-app/apple-touch-icon.png?v=1',
  statusBar: 'default',
  icone: [
    { href: '/assets/images/cora-app/favicon.ico?v=1', sizes: '48x48' },
    { href: '/assets/images/cora-app/favicon.svg?v=1', type: 'image/svg+xml' },
  ],
};

const DOCK: Array<[string, string, string, string]> = [
  ['index', 'Home', 'dock-home.svg', '/employee/'],
  ['documents', 'Documenti', 'dock-documenti.svg', '/employee/documents'],
  ['leave-requests', 'Richieste', 'dock-richieste.svg', '/employee/leave-requests'],
  ['communications', 'Avvisi', 'dock-avvisi.svg', '/employee/communications'],
  ['academy', 'Academy', 'dock-academy.svg', '/employee/academy'],
  ['calendar', 'Calendario', 'dock-calendario.svg', '/employee/calendar'],
  ['chat', 'Chat', 'dock-chat.svg', '/employee/chat'],
];
const MODULI: Array<[string, string]> = [['Cora', 'cora'], ['Fase', 'fase'], ['Banche', 'lock'], ['Analytics', 'lock'], ['Chat', 'lock'], ['Academy', 'lock']];
const ALTRO = ['communications', 'calendar', 'chat', 'academy'];

function segnali(c: Cornice['contatori'], chiave: string): [boolean, number] {
  switch (chiave) {
    case 'documents': return [c.documenti > 0, 0];
    case 'communications': return [c.comunicazioni > 0, 0];
    case 'academy': return [c.academyDaFare > 0 || c.attestatiNuovi > 0, 0];
    case 'calendar': return [c.inviti > 0, 0];
    case 'chat': return [false, c.chat];
    default: return [false, 0];
  }
}

/** Barra in alto delle pagine Cora (_topbar.inc.php). */
export function Topbar({ titolo, sottotitolo = '', azioni, centro, cornice }: { titolo: string; sottotitolo?: string; azioni?: ReactNode; centro?: ReactNode; cornice: Cornice }) {
  return (
    <header className="cora-topbar">
      <a href="/employee/" className="cora-topbar-logo" aria-label="Cora · Home" dangerouslySetInnerHTML={{ __html: svgCora('logo-cora-mobile.svg') }} />
      <div className="cora-topbar-title">
        <h1>{titolo}</h1>
        {sottotitolo !== '' && <p>{sottotitolo}</p>}
      </div>
      {centro && <div className="cora-topbar-center">{centro}</div>}
      {azioni && <div className="cora-topbar-actions">{azioni}</div>}
      <div className="cora-topbar-user">
        <div className="cora-bell" data-cora-bell>
          <button type="button" className="cora-icon-btn" aria-haspopup="true" aria-expanded="false" aria-label="Notifiche">
            <I n="ic-bell.svg" />
            {cornice.notificheNonLette > 0 && <span className="cora-bell-dot" data-cora-bell-dot />}
          </button>
          <div className="cora-bell-menu" role="menu">
            <div className="cora-bell-head">Notifiche</div>
            {cornice.notifiche.length === 0 ? (
              <div className="cora-bell-empty">Nessuna notifica</div>
            ) : cornice.notifiche.map((n) => (
              <a key={n.id} className={'cora-bell-item' + (n.letta ? '' : ' is-unread')} href={n.link !== '' ? n.link : '#'}>
                <span className="t">{n.titolo}</span>
                {n.messaggio && <span className="m">{n.messaggio}</span>}
                <span className="d">{fa(n.creata)}</span>
              </a>
            ))}
          </div>
        </div>
        <a href="/employee/profile" className="cora-avatar" data-tour="profile-link" title={`${cornice.nome} ${cornice.cognome}`}>
          {cornice.foto ? <img src={cornice.foto} alt="" decoding="async" /> : <span>{iniziali(cornice.nome, cornice.cognome)}</span>}
        </a>
      </div>
    </header>
  );
}

function AnelloModuli() {
  return (
    <div className="cora-modules" aria-hidden="true">
      {MODULI.map(([nome, tipo], i) => {
        const stile = { '--a': `${-90 + i * (360 / MODULI.length)}deg` } as React.CSSProperties;
        const interno = (<><S n={`mod-${tipo}.svg`} c="cora-module-ic" /><span className="cora-module-name">{nome}</span></>);
        return tipo === 'cora'
          ? <a key={nome} href="/employee/" className={`cora-module is-${tipo}`} style={stile}>{interno}</a>
          : <span key={nome} className={`cora-module is-${tipo}`} style={stile}>{interno}</span>;
      })}
    </div>
  );
}

/** Carica uno script in ordine, una sola volta per pagina caricata. */
function carica(src: string): Promise<void> {
  return new Promise((ok) => {
    segnaScriptPhp();
    const s = document.createElement('script');
    s.src = src;
    s.async = false;
    s.onload = () => ok();
    s.onerror = () => ok();
    document.body.appendChild(s);
  });
}

/**
 * Configurazione e script del footer PHP. `globali` sono le variabili che la
 * pagina PHP scriveva prima dei suoi script (es. window.CORA_LEAVE).
 */
function useScriptLegacy(pagina: string, cornice: Cornice, script: string[], globali: Record<string, unknown>) {
  useEffect(() => {
    const w = window as any;
    if (w.__coraScript) return;
    w.__coraScript = true;
    w.PAM = { baseUrl: '' };
    Object.assign(w, globali);
    const csrf = decodeURIComponent(document.cookie.match(/(?:^|;\s*)cora_csrf=([^;]+)/)?.[1] ?? '');
    let meta = document.querySelector<HTMLMetaElement>('meta[name="csrf-token"]');
    if (!meta) { meta = document.createElement('meta'); meta.name = 'csrf-token'; document.head.appendChild(meta); }
    meta.content = csrf;
    w.PAM_TOUR = {
      employeeId: cornice.guida.id, session: cornice.guida.sessione, status: cornice.guida.stato, page: pagina,
      figmaHome: false, cora: true, api: '/api/employee-tour.php', baseUrl: '/employee/',
    };
    const link = document.createElement('link');
    link.rel = 'stylesheet';
    link.href = '/assets/css/employee-tour.css';
    document.head.appendChild(link);
    (async () => {
      for (const s of ['/assets/js/app.js', '/assets/js/push.js', '/assets/js/availability-toggle.js', '/assets/js/cora.js', ...script, '/assets/js/employee-tour.js']) {
        await carica(s);
      }
      // Nel PHP gli script erano nella pagina e ricevevano DOMContentLoaded:
      // chi lo aspetta (es. riapertura della modale dopo un errore) parte ora.
      document.dispatchEvent(new Event('DOMContentLoaded'));
      caricaWidget(cornice.widget);
    })();
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, []);
}

/**
 * `classica`: pagina non ridisegnata (come senza $coraPage nel PHP): CSS
 * classici prima di quelli di Cora, classi "cora-legacy employee-body" e
 * contenuto dentro la barra in alto e div.app-content.
 */
export function CoraShell({ pagina, titolo, css = [], stile, script = [], globali = {}, children, cornice, classica = false }: {
  pagina: string; titolo: string; css?: string[]; stile?: string; script?: string[]; globali?: Record<string, unknown>; children: ReactNode; cornice: Cornice; classica?: boolean;
}) {
  useFoglioEsterno(FONT);
  useCss(...(classica ? ['https://rsms.me/inter/inter.css', '/assets/css/theme.css', '/assets/css/components.css', '/assets/css/style.css', '/assets/css/mobile-staff.css'] : []),
    '/assets/css/cora.css', '/assets/css/cora-pages.css', ...css.map((c) => `/assets/css/${c}`));
  // <style> della pagina PHP (nel body): dopo i fogli della cornice.
  useStile(...(stile ? [stile] : []));
  useClasseBody(classica ? 'cora-body cora-legacy employee-body' : 'cora-body');
  useTitolo(`${titolo} · Cora`);
  useTesta(TESTA_CORA);
  useLayoutEffect(() => { document.body.dataset.page = pagina; }, [pagina]);
  useScriptLegacy(pagina, cornice, script, globali);

  const c = cornice.contatori;
  const altroAttivo = ALTRO.includes(pagina) || pagina === 'profile';
  const altroPuntino = ALTRO.some((k) => { const [d, b] = segnali(c, k); return d || b > 0; });

  return (
    <>
      <div className="cora-app">
        <main className="cora-main">
          {classica ? (
            <>
              <Topbar titolo={titolo} cornice={cornice} />
              <div className="app-content cora-legacy-content">{children}</div>
            </>
          ) : children}
        </main>
      </div>

      <nav className="cora-dock" data-tour="nav-desktop" aria-label="Menu principale">
        <div className="cora-dock-items">
          {DOCK.map(([k, label, icona, href]) => {
            const [puntino, numero] = segnali(c, k);
            const attivo = pagina === k;
            return (
              <a key={k} className={'cora-dock-item' + (attivo ? ' is-active' : '')} href={href} aria-current={attivo ? 'page' : undefined}>
                <S n={icona} t c="cora-dock-icon" />
                <span className="cora-dock-label">{label}</span>
                {numero > 0 ? <span className="cora-dock-badge">{numero > 9 ? '9+' : numero}</span> : puntino ? <span className="cora-dock-dot" /> : null}
              </a>
            );
          })}
        </div>
        <span className="cora-dock-sep" aria-hidden="true" />
        <div className="cora-dock-cora" data-cora-modules>
          <button type="button" className="cora-dock-item" aria-expanded="false" aria-label="Moduli Cora">
            <S n="dock-cora.svg" c="cora-dock-icon cora-dock-logo" />
            <span className="cora-dock-label">Cora</span>
          </button>
          <AnelloModuli />
        </div>
      </nav>

      <nav className="cora-tabbar" data-tour="nav-mobile" aria-label="Menu principale">
        {(['index', 'leave-requests', 'cora', 'documents', 'altro'] as const).map((k) => {
          if (k === 'cora') {
            return (
              <div key={k} className="cora-tabbar-cora cora-dock-cora" data-cora-modules>
                <button type="button" className="cora-tabbar-btn" aria-expanded="false" aria-label="Moduli Cora" dangerouslySetInnerHTML={{ __html: svgCora('logo-cora-white-20.svg') }} />
                <AnelloModuli />
              </div>
            );
          }
          if (k === 'altro') {
            return (
              <button key={k} type="button" className={'cora-dock-item' + (altroAttivo ? ' is-active' : '')} data-cora-more aria-haspopup="dialog" aria-expanded="false">
                <S n="dock-altro.svg" t c="cora-dock-icon" />
                <span className="cora-dock-label">Altro</span>
                {altroPuntino && <span className="cora-dock-dot" data-cora-more-dot />}
              </button>
            );
          }
          const [, label, icona, href] = DOCK.find((v) => v[0] === k)!;
          const [puntino] = segnali(c, k);
          const attivo = pagina === k;
          return (
            <a key={k} className={'cora-dock-item' + (attivo ? ' is-active' : '')} href={href} aria-current={attivo ? 'page' : undefined}>
              <S n={icona} t c="cora-dock-icon" />
              <span className="cora-dock-label">{label}</span>
              {puntino && <span className="cora-dock-dot" />}
            </a>
          );
        })}
      </nav>

      <div className="cora-more" data-cora-more-sheet hidden>
        <div className="cora-more-box" role="dialog" aria-modal="true" aria-label="Altre sezioni">
          <div className="cora-more-grip" aria-hidden="true" />
          {[...ALTRO, 'profile'].map((k) => {
            const v = DOCK.find((d) => d[0] === k);
            const [label, icona, href] = k === 'profile' ? ['Profilo', '', '/employee/profile'] : [v![1], v![2], v![3]];
            const [puntino, numero] = segnali(c, k);
            const attivo = pagina === k;
            return (
              <a key={k} className={'cora-more-item' + (attivo ? ' is-active' : '')} href={href} aria-current={attivo ? 'page' : undefined}>
                <span className="cora-more-ic">
                  {k === 'profile'
                    ? <span className="cora-avatar">{cornice.foto ? <img src={cornice.foto} alt="" /> : iniziali(cornice.nome, cornice.cognome)}</span>
                    : <I n={icona} t />}
                </span>
                <span className="cora-more-label">{label}</span>
                {numero > 0 ? <span className="cora-dock-badge">{numero > 9 ? '9+' : numero}</span> : puntino ? <span className="cora-more-dot" /> : null}
                <S n="ic-chev-r.svg" c="cora-more-chev" />
              </a>
            );
          })}
        </div>
      </div>

      <div className="cora-toast-host" id="coraToasts" aria-live="polite" />
    </>
  );
}

/**
 * Dati della cornice; la pagina si disegna solo quando ci sono tutti (il PHP rendeva tutto insieme).
 * `dopo`: operazione della pagina da finire prima (es. comunicazione segnata come
 * letta), cosi' i contatori sono quelli che il PHP calcolava dopo di essa.
 */
export function useCornice(dopo?: Promise<unknown>): Cornice | null {
  const [c, setC] = useState<Cornice | null>(null);
  useEffect(() => {
    Promise.resolve(dopo).catch(() => undefined).then(() => api.get('/dipendente/cornice')).then((r) => {
      // Contratto da firmare: prima la firma, poi il resto del portale (header-employee.php).
      if (r.data?.contrattoDaFirmare && !['/employee/contract-sign', '/employee/change-password'].includes(location.pathname)) {
        location.replace(`/employee/contract-sign?id=${r.data.contrattoDaFirmare}`);
        return;
      }
      setC(r.data);
    }).catch(() => undefined);
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, []);
  return c;
}
