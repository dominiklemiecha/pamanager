import { ReactNode, useEffect, useLayoutEffect, useRef, useState } from 'react';
import { useLocation } from 'react-router-dom';
import { useClasseBody, useCss, useTitolo } from '../componenti/stile';
import { useTesta } from '../componenti/testa';
import { api } from '../services/api';
import type { CorniceStaff } from '../staff/tipi';
import { caricaWidget } from './CoraShell';
import { fa } from './formato';
import { svgCora } from './svg';
import { precaricaDock, precaricaPagina } from './datiHr';
import { toastCora } from './toast';

/**
 * Cornice della vista Cora dell'HR (Figma "Modulo Cora · HR", sezione 2):
 * barra in alto con saluto, selettore azienda, campanella e avatar; dock in
 * basso con le aree dell'HR e l'anello dei moduli Cora. Campanella, anello,
 * foglio "Altro" e toast sono qui in React (stesso comportamento di cora.js),
 * cosi' si cambia pagina senza ricaricare l'app.
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
  ['calendario', 'Calendario', 'dock-calendario.svg', '/admin/calendar'],
  ['academy', 'Academy', 'dock-academy.svg', '/admin/academy'],
  ['accessi', 'Accessi esterni', 'dock-accessi.svg', '/admin/consulente-lavoro'],
];
/** Foglio "Altro" del telefono (Figma "Menu mobile · Altro aperto · HR"). */
const ALTRO = ['archivio', 'accessi', 'chat', 'calendario', 'academy'];
const SOTTO: Record<string, string> = { archivio: 'Comunicazioni e documenti', accessi: 'Consulenti e formatori', calendario: 'Eventi e riunioni', academy: 'Corsi e attestati' };
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
  // Come nel selettore classico: l'HR ha sempre "Gestisci aziende" in fondo al menu.
  if (!altre.length && c.ruolo !== 'admin') return <div className="hr-azienda">{testo}</div>;
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
          {c.ruolo === 'admin' && <a className="hr-azienda-item hr-azienda-gestisci" role="menuitem" href="/admin/companies">Gestisci aziende</a>}
        </div>
      )}
    </div>
  );
}

/** Chiude un menu a tendina al clic fuori da `selettore` o con Esc. */
function useChiudiFuori(aperto: boolean, selettore: string, chiudi: () => void) {
  useEffect(() => {
    if (!aperto) return;
    const clic = (e: MouseEvent) => { if (!(e.target as HTMLElement).closest?.(selettore)) chiudi(); };
    const tasto = (e: KeyboardEvent) => { if (e.key === 'Escape') chiudi(); };
    document.addEventListener('click', clic);
    document.addEventListener('keydown', tasto);
    return () => { document.removeEventListener('click', clic); document.removeEventListener('keydown', tasto); };
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [aperto, selettore]);
}

/** Campanella: all'apertura le notifiche diventano lette. */
function Campanella({ cornice }: { cornice: CorniceHr }) {
  const [aperta, setAperta] = useState(false);
  const [punto, setPunto] = useState(cornice.notificheNonLette > 0);
  const segnate = useRef(false);
  useChiudiFuori(aperta, '.cora-bell', () => setAperta(false));
  const apri = () => {
    const ora = !aperta;
    setAperta(ora);
    if (ora && punto && !segnate.current) {
      segnate.current = true;
      api.post('/api/notifications-read.php', null, { baseURL: '' }).then(() => {
        setPunto(false);
        if (cache) cache = { ...cache, notificheNonLette: 0, notifiche: cache.notifiche.map((n) => ({ ...n, letta: true })) };
      }).catch(() => undefined);
    }
  };
  return (
    <div className={'cora-bell' + (aperta ? ' is-open' : '')}>
      <button type="button" className="cora-icon-btn hr-icon-btn" aria-haspopup="true" aria-expanded={aperta} aria-label="Notifiche" onClick={apri}>
        <I n="ic-bell.svg" />
        {punto && <span className="cora-bell-dot" />}
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
  );
}

/** Barra in alto dell'HR. */
export function HrTopbar({ titolo, sottotitolo = '', centro, azioni, cornice }: { titolo: string; sottotitolo?: ReactNode; centro?: ReactNode; azioni?: ReactNode; cornice: CorniceHr }) {
  return (
    <header className="cora-topbar">
      <a href="/admin/" className="cora-topbar-logo" aria-label="Cora · Home" dangerouslySetInnerHTML={{ __html: svgCora('logo-cora-mobile.svg') }} />
      <div className="cora-topbar-title">
        <h1>{titolo}</h1>
        {sottotitolo !== '' && <p>{sottotitolo}</p>}
      </div>
      {centro && <div className="cora-topbar-center">{centro}</div>}
      <div className="cora-topbar-actions">{azioni}<SelettoreAzienda c={cornice} /></div>
      <div className="cora-topbar-user">
        <Campanella cornice={cornice} />
        <a href="/admin/profile" className="cora-avatar hr-avatar" title={cornice.nome}>
          {cornice.foto ? <img src={cornice.foto} alt="" decoding="async" /> : <span>{cornice.iniziali}</span>}
        </a>
      </div>
    </header>
  );
}

/** Anello dei moduli (voce Cora del dock e della barra del telefono). */
function VoceModuli({ classe, bottone }: { classe: string; bottone: (p: { 'aria-expanded': boolean; onClick: () => void }) => ReactNode }) {
  const [aperto, setAperto] = useState(false);
  useChiudiFuori(aperto, '.cora-dock-cora', () => setAperto(false));
  return (
    <div className={classe + (aperto ? ' is-open' : '')}>
      {bottone({ 'aria-expanded': aperto, onClick: () => setAperto(!aperto) })}
      <AnelloModuli />
    </div>
  );
}

/** Foglio "Altro" del telefono, con la stessa animazione di cora.js. */
function useFoglioAltro(): [boolean, boolean, (aperto: boolean) => void] {
  const [visibile, setVisibile] = useState(false);
  const [aperto, setAperto] = useState(false);
  const stato = useRef(false);
  const imposta = (a: boolean) => {
    stato.current = a;
    if (a) {
      setVisibile(true);
      requestAnimationFrame(() => setAperto(true));
    } else {
      setAperto(false);
      setTimeout(() => { if (!stato.current) setVisibile(false); }, 220);
    }
  };
  useEffect(() => {
    if (!visibile) return;
    const tasto = (e: KeyboardEvent) => { if (e.key === 'Escape') imposta(false); };
    document.addEventListener('keydown', tasto);
    return () => document.removeEventListener('keydown', tasto);
  }, [visibile]);
  return [visibile, aperto, imposta];
}

/** Dock scorrevole su telefono: voce attiva visibile, dissolvenza solo se c'e' altro a destra. */
function useDockScorrevole() {
  const rif = useRef<HTMLDivElement>(null);
  useLayoutEffect(() => {
    const d = rif.current;
    if (!d) return;
    const attiva = d.querySelector<HTMLElement>('.is-active');
    if (attiva && d.scrollWidth > d.clientWidth) d.scrollLeft = Math.max(0, attiva.offsetLeft - d.clientWidth / 2 + attiva.offsetWidth / 2);
    const fine = () => d.classList.toggle('is-end', d.scrollLeft + d.clientWidth >= d.scrollWidth - 2);
    d.addEventListener('scroll', fine, { passive: true });
    fine();
    return () => d.removeEventListener('scroll', fine);
  }, []);
  return rif;
}

export function HrShell({ pagina, titolo, children, cornice }: { pagina: string; titolo: string; children: ReactNode; cornice: CorniceHr }) {
  useCss('/assets/css/cora.css', '/assets/css/cora-pages.css', '/assets/css/cora-hr.css');
  useClasseBody('cora-body cora-hr');
  useTitolo(`${titolo} · Cora`);
  useTesta(TESTA_CORA);
  useLayoutEffect(() => { document.body.dataset.page = pagina; }, [pagina]);
  const [altroVisibile, altroAperto, setAltro] = useFoglioAltro();
  const dock = useDockScorrevole();
  const { key } = useLocation();
  // eslint-disable-next-line react-hooks/exhaustive-deps
  useEffect(() => setAltro(false), [key]);
  useEffect(() => {
    // API per le pagine e la guida (come il window.Cora di cora.js).
    (window as any).Cora = { toast: toastCora, more: (a: boolean) => setAltro(!!a) };
    caricaWidget(cornice.widget as any);
    // Ingresso a cascata degli elementi .cora-reveal (frame "Intro" del Figma)
    // a ogni pagina, anche cambiando pagina senza ricaricare.
    document.body.classList.remove('cora-revealed');
    requestAnimationFrame(() => requestAnimationFrame(() => document.body.classList.add('cora-revealed')));
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, []);
  // Dati delle pagine pronti prima del clic: a browser libero quelli del dock,
  // al passaggio del mouse (o al tocco) quelli del link.
  useEffect(() => {
    precaricaDock();
    const sopra = (e: Event) => {
      const a = (e.target as HTMLElement | null)?.closest?.('a[href]') as HTMLAnchorElement | null;
      if (a) precaricaPagina(a.href);
    };
    document.addEventListener('pointerover', sopra, { passive: true });
    document.addEventListener('touchstart', sopra, { passive: true });
    return () => {
      document.removeEventListener('pointerover', sopra);
      document.removeEventListener('touchstart', sopra);
    };
  }, []);
  const badge = (k: string) => (k === 'chat' ? cornice.contatori.chat : k === 'ferie' ? cornice.contatori.ferie : 0);

  return (
    <>
      <div className="cora-app">
        <main className="cora-main">{children}</main>
      </div>
      <nav className="cora-dock hr-dock" aria-label="Menu principale">
        <div className="cora-dock-items" ref={dock}>
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
        <VoceModuli classe="cora-dock-cora" bottone={(p) => (
          <button type="button" className="cora-dock-item" aria-label="Moduli Cora" {...p}>
            <S n="dock-cora.svg" c="cora-dock-icon cora-dock-logo" />
            <span className="cora-dock-label">Cora</span>
          </button>
        )} />
      </nav>
      <nav className="cora-tabbar" aria-label="Menu principale">
        {(['index', 'dipendenti', 'cora', 'ferie', 'altro'] as const).map((k) => {
          if (k === 'cora') {
            return (
              <VoceModuli key={k} classe="cora-tabbar-cora cora-dock-cora" bottone={(p) => (
                <button type="button" className="cora-tabbar-btn" aria-label="Moduli Cora" {...p} dangerouslySetInnerHTML={{ __html: svgCora('logo-cora-white-20.svg') }} />
              )} />
            );
          }
          if (k === 'altro') {
            const attivo = ALTRO.includes(pagina) || pagina === 'profilo';
            return (
              <button key={k} type="button" className={'cora-dock-item' + (attivo ? ' is-active' : '')} aria-haspopup="dialog" aria-expanded={altroVisibile} onClick={() => setAltro(!altroVisibile)}>
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

      <div className={'cora-more' + (altroAperto ? ' is-open' : '')} hidden={!altroVisibile}
        onClick={(e) => { if (!(e.target as HTMLElement).closest('.cora-more-box')) setAltro(false); }}>
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

/** Ultima cornice ricevuta: la pagina successiva si disegna subito e intanto la aggiorna. */
let cache: CorniceHr | null = null;

/** Dati della cornice HR; null solo alla prima apertura, finche' non arrivano. */
export function useCorniceHr(attiva = true): CorniceHr | null {
  const [c, setC] = useState<CorniceHr | null>(attiva ? cache : null);
  useEffect(() => {
    if (attiva) api.get('/hr/cornice').then((r) => { cache = r.data; setC(r.data); }).catch(() => undefined);
  }, [attiva]);
  return c;
}
