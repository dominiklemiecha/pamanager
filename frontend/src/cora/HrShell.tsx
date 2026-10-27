import { ReactNode, useEffect, useLayoutEffect, useState } from 'react';
import { useClasseBody, useCss, useTitolo } from '../componenti/stile';
import { useTesta } from '../componenti/testa';
import { api } from '../services/api';
import type { CorniceStaff } from '../staff/tipi';
import { caricaWidget } from './CoraShell';
import { fa } from './formato';
import { svgCora } from './svg';

/**
 * Cornice della vista Cora dell'HR (Figma "Modulo Cora · HR", sezione 2):
 * barra in alto con saluto, selettore azienda, campanella e avatar; dock in
 * basso con le aree dell'HR e l'anello dei moduli Cora. Campanella e anello
 * li gestisce lo stesso cora.js della vista dipendente.
 */
export interface CorniceHr extends CorniceStaff {
  coraHr: boolean;
  dipendentiAttivi: number;
  notifiche: Array<{ id: number; titolo: string; messaggio: string; link: string; letta: boolean; creata: string }>;
  notificheNonLette: number;
}

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

/** Voci del dock: solo pagine che esistono gia' per l'HR. */
const DOCK: Array<[string, string, string, string]> = [
  ['index', 'Home', 'dock-home.svg', '/admin/'],
  ['dipendenti', 'Dipendenti', 'dock-dipendenti.svg', '/admin/employees'],
  ['ferie', 'Ferie', 'dock-ferie.svg', '/admin/leave-requests'],
  ['archivio', 'Archivio', 'dock-archivio.svg', '/admin/communications'],
  ['chat', 'Chat', 'dock-chat.svg', '/admin/chat'],
  ['accessi', 'Accessi esterni', 'dock-accessi.svg', '/admin/consulente-lavoro'],
];
/** Foglio "Altro" del telefono (Figma "Menu mobile · Altro aperto · HR"). */
const ALTRO = ['archivio', 'accessi', 'chat'];
const SOTTO: Record<string, string> = { archivio: 'Comunicazioni e documenti', accessi: 'Consulenti e formatori' };
const MODULI: Array<[string, string]> = [['Cora', 'cora'], ['Fase', 'fase'], ['Banche', 'lock'], ['Analytics', 'lock'], ['Chat', 'lock'], ['Academy', 'lock']];

function AnelloModuli() {
  return (
    <div className="cora-modules" aria-hidden="true">
      {MODULI.map(([nome, tipo], i) => {
        const stile = { '--a': `${-90 + i * (360 / MODULI.length)}deg` } as React.CSSProperties;
        const interno = (<><S n={`mod-${tipo}.svg`} c="cora-module-ic" /><span className="cora-module-name">{nome}</span></>);
        return tipo === 'cora'
          ? <a key={nome} href="/admin/" className={`cora-module is-${tipo}`} style={stile}>{interno}</a>
          : <span key={nome} className={`cora-module is-${tipo}`} style={stile}>{interno}</span>;
      })}
    </div>
  );
}

/** Selettore dell'azienda di lavoro (stesse aziende e stesso cambio della testata classica). */
function SelettoreAzienda({ c }: { c: CorniceHr }) {
  const [aperto, setAperto] = useState(false);
  const altre = c.aziende.filter((a) => a.id !== c.azienda?.id);
  useEffect(() => {
    if (!aperto) return;
    const chiudi = (e: MouseEvent) => { if (!(e.target as HTMLElement).closest('.hr-azienda')) setAperto(false); };
    document.addEventListener('click', chiudi);
    return () => document.removeEventListener('click', chiudi);
  }, [aperto]);
  const cambia = async (id: number) => {
    await api.post('/auth/azienda', { azienda: id });
    location.reload();
  };
  const testo = (
    <>
      <span className="hr-azienda-logo">{c.aziendaIniziali}</span>
      <span className="hr-azienda-txt">
        <strong>{c.azienda?.name ?? ''}</strong>
        <span><i />Azienda attiva · {c.dipendentiAttivi} dipendent{c.dipendentiAttivi === 1 ? 'e' : 'i'}</span>
      </span>
    </>
  );
  if (!altre.length) return <div className="hr-azienda">{testo}</div>;
  return (
    <div className={'hr-azienda' + (aperto ? ' is-open' : '')}>
      <button type="button" className="hr-azienda-btn" aria-haspopup="true" aria-expanded={aperto} onClick={() => setAperto(!aperto)}>
        {testo}
        <S n="ic-chev-d.svg" c="hr-azienda-chev" />
      </button>
      {aperto && (
        <div className="hr-azienda-menu" role="menu">
          {altre.map((a) => (
            <button key={a.id} type="button" role="menuitem" className="hr-azienda-item" onClick={() => cambia(a.id)}>
              <span>{a.name}</span>
              {a.attivita > 0 && <span className="cora-dock-badge">{a.attivita > 9 ? '9+' : a.attivita}</span>}
            </button>
          ))}
        </div>
      )}
    </div>
  );
}

/** Barra in alto dell'HR. */
export function HrTopbar({ titolo, sottotitolo = '', centro, cornice }: { titolo: string; sottotitolo?: ReactNode; centro?: ReactNode; cornice: CorniceHr }) {
  return (
    <header className="cora-topbar">
      <a href="/admin/" className="cora-topbar-logo" aria-label="Cora · Home" dangerouslySetInnerHTML={{ __html: svgCora('logo-cora-mobile.svg') }} />
      <div className="cora-topbar-title">
        <h1>{titolo}</h1>
        {sottotitolo !== '' && <p>{sottotitolo}</p>}
      </div>
      {centro && <div className="cora-topbar-center">{centro}</div>}
      <div className="cora-topbar-actions"><SelettoreAzienda c={cornice} /></div>
      <div className="cora-topbar-user">
        <div className="cora-bell" data-cora-bell>
          <button type="button" className="cora-icon-btn hr-icon-btn" aria-haspopup="true" aria-expanded="false" aria-label="Notifiche">
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
        <a href="/admin/profile" className="cora-avatar hr-avatar" title={cornice.nome}>
          {cornice.foto ? <img src={cornice.foto} alt="" decoding="async" /> : <span>{cornice.iniziali}</span>}
        </a>
      </div>
    </header>
  );
}

/** Script della vista Cora (campanella, anello dei moduli), una volta per pagina. */
function useScript(cornice: CorniceHr) {
  useEffect(() => {
    const w = window as any;
    if (w.__coraScript) return;
    w.__coraScript = true;
    w.PAM = { baseUrl: '' };
    const s = document.createElement('script');
    s.src = '/assets/js/cora.js';
    s.onload = () => { document.dispatchEvent(new Event('DOMContentLoaded')); caricaWidget(cornice.widget as any); };
    document.body.appendChild(s);
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, []);
}

export function HrShell({ pagina, titolo, children, cornice }: { pagina: string; titolo: string; children: ReactNode; cornice: CorniceHr }) {
  useCss('/assets/css/cora.css', '/assets/css/cora-pages.css', '/assets/css/cora-hr.css');
  useClasseBody('cora-body cora-hr');
  useTitolo(`${titolo} · Cora`);
  useTesta(TESTA_CORA);
  useLayoutEffect(() => { document.body.dataset.page = pagina; }, [pagina]);
  useScript(cornice);
  const badge = (k: string) => (k === 'chat' ? cornice.contatori.chat : k === 'ferie' ? cornice.contatori.ferie : 0);

  return (
    <>
      <div className="cora-app">
        <main className="cora-main">{children}</main>
      </div>
      <nav className="cora-dock hr-dock" aria-label="Menu principale">
        <div className="cora-dock-items">
          {DOCK.map(([k, label, icona, href]) => {
            const attivo = pagina === k;
            const n = badge(k);
            return (
              <a key={k} className={'cora-dock-item' + (attivo ? ' is-active' : '') + (k === 'accessi' ? ' is-wide' : '')} href={href} aria-current={attivo ? 'page' : undefined}>
                <S n={icona} t c="cora-dock-icon" />
                <span className="cora-dock-label">{label}</span>
                {n > 0 && <span className="cora-dock-badge">{n > 9 ? '9+' : n}</span>}
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
      <nav className="cora-tabbar" aria-label="Menu principale">
        {(['index', 'dipendenti', 'cora', 'ferie', 'altro'] as const).map((k) => {
          if (k === 'cora') {
            return (
              <div key={k} className="cora-tabbar-cora cora-dock-cora" data-cora-modules>
                <button type="button" className="cora-tabbar-btn" aria-expanded="false" aria-label="Moduli Cora" dangerouslySetInnerHTML={{ __html: svgCora('logo-cora-white-20.svg') }} />
                <AnelloModuli />
              </div>
            );
          }
          if (k === 'altro') {
            const attivo = ALTRO.includes(pagina) || pagina === 'profilo';
            return (
              <button key={k} type="button" className={'cora-dock-item' + (attivo ? ' is-active' : '')} data-cora-more aria-haspopup="dialog" aria-expanded="false">
                <S n="dock-altro.svg" t c="cora-dock-icon" />
                <span className="cora-dock-label">Altro</span>
                {cornice.contatori.chat > 0 && <span className="cora-dock-dot" />}
              </button>
            );
          }
          const [, label, icona, href] = DOCK.find((v) => v[0] === k)!;
          const attivo = pagina === k;
          return (
            <a key={k} className={'cora-dock-item' + (attivo ? ' is-active' : '')} href={href} aria-current={attivo ? 'page' : undefined}>
              <S n={icona} t c="cora-dock-icon" />
              <span className="cora-dock-label">{label}</span>
              {badge(k) > 0 && <span className="cora-dock-dot" />}
            </a>
          );
        })}
      </nav>

      <div className="cora-more" data-cora-more-sheet hidden>
        <div className="cora-more-box" role="dialog" aria-modal="true" aria-label="Altre sezioni">
          <div className="cora-more-grip" aria-hidden="true" />
          <h2 className="hr-more-titolo">Altro</h2>
          {ALTRO.map((k) => {
            const [, label, icona, href] = DOCK.find((v) => v[0] === k)!;
            const chat = k === 'chat' ? cornice.contatori.chat : 0;
            return (
              <a key={k} className={'cora-more-item' + (pagina === k ? ' is-active' : '')} href={href} aria-current={pagina === k ? 'page' : undefined}>
                <span className="cora-more-ic"><I n={icona} t /></span>
                <span className="hr-more-txt">
                  <span className="cora-more-label">{label}</span>
                  <span className="hr-more-sub">{k === 'chat' ? (chat > 0 ? `${chat} messagg${chat === 1 ? 'io' : 'i'} non lett${chat === 1 ? 'o' : 'i'}` : 'Nessun messaggio non letto') : SOTTO[k]}</span>
                </span>
                {chat > 0 && <span className="cora-more-dot" />}
                <S n="ic-chev-r.svg" c="cora-more-chev" />
              </a>
            );
          })}
          <span className="hr-more-sep" aria-hidden="true" />
          <a className={'cora-more-item' + (pagina === 'profilo' ? ' is-active' : '')} href="/admin/profile">
            <span className="cora-more-ic"><span className="cora-avatar">{cornice.foto ? <img src={cornice.foto} alt="" /> : cornice.iniziali}</span></span>
            <span className="hr-more-txt"><span className="cora-more-label">{cornice.nome}</span><span className="hr-more-sub">Profilo e configurazione</span></span>
            <S n="ic-chev-r.svg" c="cora-more-chev" />
          </a>
          <a className="cora-more-item hr-more-esci" href="/auth/logout">
            <span className="cora-more-ic"><I n="ic-logout.svg" t /></span>
            <span className="cora-more-label">Esci dall'account</span>
          </a>
        </div>
      </div>

      <div className="cora-toast-host" id="coraToasts" aria-live="polite" />
    </>
  );
}

/** Dati della cornice HR; null finche' non arrivano. */
export function useCorniceHr(): CorniceHr | null {
  const [c, setC] = useState<CorniceHr | null>(null);
  useEffect(() => { api.get('/hr/cornice').then((r) => setC(r.data)).catch(() => undefined); }, []);
  return c;
}
