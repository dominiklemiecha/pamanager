import { useEffect, useMemo, useState } from 'react';
import { useCss } from '../../componenti/stile';
import { prendiPrecaricato } from '../../cora/datiHr';
import { CorniceHr, HrShell, HrTopbar } from '../../cora/HrShell';
import { api } from '../../services/api';
import { pulisciScriptPhp, tracciaScriptPhp } from '../../services/scriptPhp';

/** Carica uno script (in ordine) e lo toglie all'uscita dalla pagina. */
function carica(src: string, aggiunti: HTMLScriptElement[]): Promise<void> {
  return new Promise((ok) => {
    const s = document.createElement('script');
    s.src = src;
    s.async = false;
    s.onload = () => ok();
    s.onerror = () => ok();
    aggiunti.push(s);
    document.body.appendChild(s);
  });
}

/**
 * Chat dell'HR con la vista Cora: la stessa chat del dipendente
 * (cora-chat.js disegna tutto a partire da window.CORA_CHAT), aperta e
 * lasciata senza ricaricare l'app. Conversazioni, invio e gruppi non
 * cambiano indirizzo di pagina.
 */
export function ChatHr({ cornice }: { cornice: CorniceHr }) {
  useCss('/assets/css/cora-chat.css');
  const conv = useMemo(() => new URLSearchParams(location.search).get('conv') ?? '', []);
  const [cfg, setCfg] = useState<any>(null);
  useEffect(() => {
    // Senza conversazione aperta la richiesta non segna nulla come letto: puo' essere gia' arrivata.
    const pronti = conv ? null : prendiPrecaricato<any>('/chat');
    (pronti ?? api.get('/chat', { params: { conv: conv || undefined } }).then((r) => r.data))
      .then(setCfg).catch(() => undefined);
  }, [conv]);

  useEffect(() => {
    if (!cfg) return undefined;
    const w = window as any;
    w.CORA_CHAT = cfg;
    const csrf = decodeURIComponent(document.cookie.match(/(?:^|;\s*)cora_csrf=([^;]+)/)?.[1] ?? '');
    let meta = document.querySelector<HTMLMetaElement>('meta[name="csrf-token"]');
    if (!meta) { meta = document.createElement('meta'); meta.name = 'csrf-token'; document.head.appendChild(meta); }
    meta.content = csrf;
    tracciaScriptPhp();
    const aggiunti: HTMLScriptElement[] = [];
    let uscita = false;
    (async () => {
      if (cfg.realtime && typeof w.io !== 'function') await carica('/realtime/socket.io/socket.io.min.js', aggiunti);
      if (!uscita) await carica('/assets/js/cora-chat.js', aggiunti);
    })();
    return () => {
      uscita = true;
      // Socket, timer e listener della chat: via, come lasciando la pagina nel PHP.
      pulisciScriptPhp();
      aggiunti.forEach((s) => s.remove());
    };
  }, [cfg]);

  return (
    <HrShell pagina="chat" titolo="Chat" cornice={cornice}>
      <div className="cora-reveal" style={{ '--i': 0 } as React.CSSProperties}>
        <HrTopbar titolo="Chat" cornice={cornice}
          azioni={<button type="button" className="cc-nuovo" data-cc-new=""><img src="/assets/images/cora-chat/nuovo-piu.svg" alt="" />Nuova chat</button>} />
      </div>
      <div className="cc-frame cora-reveal" id="coraChat" style={{ '--i': 1 } as React.CSSProperties} />
    </HrShell>
  );
}
