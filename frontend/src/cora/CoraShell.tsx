import { ReactNode, useEffect, useLayoutEffect, useState } from 'react';
import { useClasseBody, useCss, useFoglioEsterno, useTitolo } from '../componenti/stile';
import { useTesta } from '../componenti/testa';
import { api } from '../services/api';
import { fa, iniziali } from './formato';
import { svgCora } from './svg';

export interface Cornice {
  nome: string;
  cognome: string;
  foto: string;
  disponibilita: string;
  contatori: { comunicazioni: number; documenti: number; chat: number; academyDaFare: number; attestatiNuovi: number; inviti: number };
  notifiche: Array<{ id: number; titolo: string; messaggio: string; link: string; letta: boolean; creata: string }>;
  notificheNonLette: number;
  guida: { id: number; sessione: string; stato: string | null };
}

const FONT = 'https://fonts.googleapis.com/css2?family=Host+Grotesk:wght@400;500;600;700&display=swap';
const S = ({ n, t, c }: { n: string; t?: boolean; c?: string }) => <span className={c} dangerouslySetInnerHTML={{ __html: svgCora(n, t) }} />;

/** Testa delle pagine Cora (header.inc.php): manifest "Cora", icone dell'app. */
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
  ['documents', 'Documenti', 'dock-documenti.svg', '/employee/documents.php'],
  ['leave-requests', 'Richieste', 'dock-richieste.svg', '/employee/leave-requests.php'],
  ['communications', 'Avvisi', 'dock-avvisi.svg', '/employee/communications.php'],
  ['academy', 'Academy', 'dock-academy.svg', '/employee/academy.php'],
  ['calendar', 'Calendario', 'dock-calendario.svg', '/employee/calendar.php'],
  ['chat', 'Chat', 'dock-chat.svg', '/employee/chat.php'],
];
const MODULI: Array<[string, string]> = [['Cora', 'cora'], ['Fase', 'fase'], ['Banche', 'lock'], ['Analytics', 'lock'], ['Chat', 'lock'], ['Academy', 'lock']];
const ALTRO = ['communications', 'calendar', 'chat', 'academy'];

/** Pallino e numero di ogni voce del dock, come in footer.inc.php. */
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

function Avatar({ c }: { c: Cornice }) {
  return c.foto ? <img src={c.foto} alt="" decoding="async" /> : <span>{iniziali(c.nome, c.cognome)}</span>;
}

/** Barra in alto delle pagine Cora (_topbar.inc.php). */
export function Topbar({ titolo, sottotitolo = '', azioni, centro, cornice }: { titolo: string; sottotitolo?: string; azioni?: ReactNode; centro?: ReactNode; cornice: Cornice }) {
  const [aperta, setAperta] = useState(false);
  const [puntino, setPuntino] = useState(cornice.notificheNonLette > 0);
  const [segnate, setSegnate] = useState(false);
  useEffect(() => {
    if (!aperta) return;
    const chiudi = (e: MouseEvent) => { if (!(e.target as HTMLElement).closest('.cora-bell')) setAperta(false); };
    const esc = (e: KeyboardEvent) => { if (e.key === 'Escape') setAperta(false); };
    document.addEventListener('click', chiudi);
    document.addEventListener('keydown', esc);
    return () => { document.removeEventListener('click', chiudi); document.removeEventListener('keydown', esc); };
  }, [aperta]);
  function apri(e: React.MouseEvent) {
    e.stopPropagation();
    const ora = !aperta;
    setAperta(ora);
    // All'apertura le notifiche diventano lette.
    if (ora && puntino && !segnate) {
      setSegnate(true);
      api.post('/dipendente/notifiche/lette').then(() => setPuntino(false)).catch(() => undefined);
    }
  }
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
        <div className={'cora-bell' + (aperta ? ' is-open' : '')} data-cora-bell>
          <button type="button" className="cora-icon-btn" aria-haspopup="true" aria-expanded={aperta} aria-label="Notifiche" onClick={apri}>
            <span style={{ display: 'contents' }} dangerouslySetInnerHTML={{ __html: svgCora('ic-bell.svg') }} />
            {puntino && <span className="cora-bell-dot" data-cora-bell-dot />}
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
        <a href="/employee/profile.php" className="cora-avatar" data-tour="profile-link" title={`${cornice.nome} ${cornice.cognome}`}>
          <Avatar c={cornice} />
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
        const interno = (
          <>
            <S n={`mod-${tipo}.svg`} c="cora-module-ic" />
            <span className="cora-module-name">{nome}</span>
          </>
        );
        return tipo === 'cora'
          ? <a key={nome} href="/employee/" className={`cora-module is-${tipo}`} style={stile}>{interno}</a>
          : <span key={nome} className={`cora-module is-${tipo}`} style={stile}>{interno}</span>;
      })}
    </div>
  );
}

/**
 * Cornice delle pagine Cora del dipendente (header.inc.php + footer.inc.php):
 * contenuto, dock (desktop), barra del telefono, foglio "Altro", notifiche.
 */
export function CoraShell({ pagina, titolo, css = [], children, cornice }: { pagina: string; titolo: string; css?: string[]; children: ReactNode; cornice: Cornice }) {
  useFoglioEsterno(FONT);
  useCss('/assets/css/cora.css', '/assets/css/cora-pages.css', ...css.map((c) => `/assets/css/${c}`));
  useClasseBody('cora-body');
  useTitolo(`${titolo} · Cora`);
  useTesta(TESTA_CORA);
  useLayoutEffect(() => { document.body.dataset.page = pagina; return () => { delete document.body.dataset.page; }; }, [pagina]);
  // Ingresso a cascata: gli elementi .cora-reveal compaiono riga per riga.
  useEffect(() => {
    document.querySelectorAll<HTMLElement>('.cora-reveal').forEach((el, i) => { if (!el.style.getPropertyValue('--i')) el.style.setProperty('--i', String(i)); });
    const r = requestAnimationFrame(() => requestAnimationFrame(() => document.body.classList.add('cora-revealed')));
    return () => { cancelAnimationFrame(r); document.body.classList.remove('cora-revealed'); };
  }, []);

  useScriptLegacy(pagina, cornice);
  const [modAperto, setModAperto] = useState<string | null>(null);
  const [altro, setAltro] = useState(false);
  const [altroVisibile, setAltroVisibile] = useState(false);
  useEffect(() => {
    const chiudi = (e: MouseEvent) => { if (!(e.target as HTMLElement).closest('.cora-dock-cora')) setModAperto(null); };
    const esc = (e: KeyboardEvent) => { if (e.key === 'Escape') { setModAperto(null); setAltro(false); } };
    document.addEventListener('click', chiudi);
    document.addEventListener('keydown', esc);
    return () => { document.removeEventListener('click', chiudi); document.removeEventListener('keydown', esc); };
  }, []);
  useEffect(() => {
    if (altro) { setAltroVisibile(true); return; }
    const t = setTimeout(() => setAltroVisibile(false), 220);
    return () => clearTimeout(t);
  }, [altro]);

  const c = cornice.contatori;
  const altroAttivo = ALTRO.includes(pagina) || pagina === 'profile';
  const altroPuntino = ALTRO.some((k) => { const [d, b] = segnali(c, k); return d || b > 0; });
  const vociDock = DOCK.filter(([k]) => ['index', 'leave-requests', 'documents'].includes(k));
  const voce = (k: string) => vociDock.find((v) => v[0] === k)!;

  return (
    <>
      <div className="cora-app">
        <main className="cora-main">{children}</main>
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
        <div className={'cora-dock-cora' + (modAperto === 'dock' ? ' is-open' : '')} data-cora-modules>
          <button type="button" className="cora-dock-item" aria-expanded={modAperto === 'dock'} aria-label="Moduli Cora"
            onClick={(e) => { e.stopPropagation(); setModAperto(modAperto === 'dock' ? null : 'dock'); }}>
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
              <div key={k} className={'cora-tabbar-cora cora-dock-cora' + (modAperto === 'tab' ? ' is-open' : '')} data-cora-modules>
                <button type="button" className="cora-tabbar-btn" aria-expanded={modAperto === 'tab'} aria-label="Moduli Cora"
                  onClick={(e) => { e.stopPropagation(); setAltro(false); setModAperto(modAperto === 'tab' ? null : 'tab'); }}
                  dangerouslySetInnerHTML={{ __html: svgCora('logo-cora-white-20.svg') }} />
                <AnelloModuli />
              </div>
            );
          }
          if (k === 'altro') {
            return (
              <button key={k} type="button" className={'cora-dock-item' + (altroAttivo ? ' is-active' : '')} data-cora-more aria-haspopup="dialog" aria-expanded={altro}
                onClick={(e) => { e.stopPropagation(); setModAperto(null); setAltro(!altro); }}>
                <S n="dock-altro.svg" t c="cora-dock-icon" />
                <span className="cora-dock-label">Altro</span>
                {altroPuntino && <span className="cora-dock-dot" data-cora-more-dot />}
              </button>
            );
          }
          const [, label, icona, href] = voce(k);
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

      <div className={'cora-more' + (altro && altroVisibile ? ' is-open' : '')} data-cora-more-sheet hidden={!altroVisibile}
        onClick={(e) => { if (!(e.target as HTMLElement).closest('.cora-more-box')) setAltro(false); }}>
        <div className="cora-more-box" role="dialog" aria-modal="true" aria-label="Altre sezioni">
          <div className="cora-more-grip" aria-hidden="true" />
          {[...ALTRO, 'profile'].map((k) => {
            const v = DOCK.find((d) => d[0] === k);
            const [label, icona, href] = k === 'profile' ? ['Profilo', '', '/employee/profile.php'] : [v![1], v![2], v![3]];
            const [puntino, numero] = segnali(c, k);
            const attivo = pagina === k;
            return (
              <a key={k} className={'cora-more-item' + (attivo ? ' is-active' : '')} href={href} aria-current={attivo ? 'page' : undefined}>
                <span className="cora-more-ic">
                  {k === 'profile'
                    ? <span className="cora-avatar">{cornice.foto ? <img src={cornice.foto} alt="" /> : iniziali(cornice.nome, cornice.cognome)}</span>
                    : <span style={{ display: 'contents' }} dangerouslySetInnerHTML={{ __html: svgCora(icona, true) }} />}
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
 * Script del vecchio PHP che lavorano sul markup gia' disegnato (guida
 * interattiva): stessa configurazione (window.PAM_TOUR) e stesso token CSRF
 * nel meta "csrf-token", che qui e' il valore del cookie cora_csrf.
 */
function useScriptLegacy(pagina: string, cornice: Cornice) {
  useEffect(() => {
    const w = window as any;
    w.PAM = { baseUrl: '' };
    w.PAM_TOUR = {
      employeeId: cornice.guida.id, session: cornice.guida.sessione, status: cornice.guida.stato, page: pagina,
      figmaHome: false, cora: true, api: '/api/employee-tour.php', baseUrl: '/employee/',
    };
    const csrf = decodeURIComponent(document.cookie.match(/(?:^|;\s*)cora_csrf=([^;]+)/)?.[1] ?? '');
    let meta = document.querySelector<HTMLMetaElement>('meta[name="csrf-token"]');
    if (!meta) { meta = document.createElement('meta'); meta.name = 'csrf-token'; document.head.appendChild(meta); }
    meta.content = csrf;
    // Lo script si esegue una volta per pagina caricata (come nel PHP), anche se
    // React rimonta il componente.
    if (w.__coraGuida) return;
    w.__coraGuida = true;
    const link = document.createElement('link');
    link.rel = 'stylesheet';
    link.href = '/assets/css/employee-tour.css';
    document.head.appendChild(link);
    const script = document.createElement('script');
    script.src = '/assets/js/employee-tour.js';
    document.body.appendChild(script);
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, []);
}

/** Carica la cornice; la pagina compare solo quando i dati ci sono (il PHP rendeva tutto insieme). */
export function useCornice(): Cornice | null {
  const [c, setC] = useState<Cornice | null>(null);
  useEffect(() => { api.get('/dipendente/cornice').then((r) => setC(r.data)).catch(() => undefined); }, []);
  return c;
}
