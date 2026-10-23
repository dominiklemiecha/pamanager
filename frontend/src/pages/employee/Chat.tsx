import { useEffect, useMemo, useState } from 'react';
import { CoraShell, Topbar, useCornice } from '../../cora/CoraShell';
import { api } from '../../services/api';

/**
 * Chat del dipendente (employee/chat.php, _cora/chat.inc.php): il disegno lo
 * fa cora-chat.js a partire da window.CORA_CHAT, come nel PHP.
 */
export default function Chat() {
  const q = useMemo(() => new URLSearchParams(location.search), []);
  // Aprire una chat la segna come letta: i contatori della cornice si chiedono dopo.
  const dati = useMemo(() => api.get('/chat', { params: { conv: q.get('conv') ?? undefined, with_admin: q.get('with_admin') ?? undefined } }), [q]);
  const cornice = useCornice(dati);
  const [cfg, setCfg] = useState<any>(null);
  useEffect(() => {
    dati.then((r) => {
      // "Scrivi all'HR": si apre la chat con l'HR (redirect a ?conv= nel PHP).
      if (r.data?.vaiA) { location.replace(`/employee/chat.php?conv=${r.data.vaiA}`); return; }
      setCfg(r.data);
    }).catch(() => undefined);
  }, [dati]);

  if (!cornice || !cfg) return null;
  const A = '/assets/images/cora-chat/';
  return (
    <CoraShell pagina="chat" titolo="Chat" cornice={cornice} css={['cora-chat.css']}
      script={[...(cfg.realtime ? ['/realtime/socket.io/socket.io.min.js'] : []), '/assets/js/cora-chat.js']} globali={{ CORA_CHAT: cfg }}>
      <Topbar titolo="Chat" cornice={cornice} azioni={<button type="button" className="cc-nuovo" data-cc-new=""><img src={`${A}nuovo-piu.svg`} alt="" />Nuova chat</button>} />
      <div className="cc-frame cora-reveal" id="coraChat" />
    </CoraShell>
  );
}
