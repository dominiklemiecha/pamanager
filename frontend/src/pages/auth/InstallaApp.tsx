import { useEffect, useState } from 'react';

/**
 * Invito a installare l'app (come nel login PHP): pulsante dove il browser lo
 * permette, istruzioni su iPhone/iPad. Registra anche il service worker.
 */
export function InstallaApp() {
  const [richiesta, setRichiesta] = useState<any>(null);
  const isIOS = /iPad|iPhone|iPod/.test(navigator.userAgent) && !(window as any).MSStream;
  const installata = window.matchMedia('(display-mode: standalone)').matches
    || (window.navigator as any).standalone === true
    || document.referrer.includes('android-app://');

  useEffect(() => {
    if (installata) return;
    const prima = (e: Event) => { e.preventDefault(); setRichiesta(e); };
    const fatto = () => setRichiesta(null);
    window.addEventListener('beforeinstallprompt', prima);
    window.addEventListener('appinstalled', fatto);
    if ('serviceWorker' in navigator) navigator.serviceWorker.register('/sw.js', { scope: '/' }).catch(() => undefined);
    return () => {
      window.removeEventListener('beforeinstallprompt', prima);
      window.removeEventListener('appinstalled', fatto);
    };
  }, [installata]);

  if (installata) return null;

  async function installa() {
    if (!richiesta) return;
    try {
      await richiesta.prompt();
      await richiesta.userChoice;
    } catch { /* l'utente ha chiuso */ }
    setRichiesta(null);
  }

  return (
    <>
      <div id="pwaInstallContainer" className="pwa-install" style={{ display: richiesta ? 'block' : 'none' }}>
        <button id="pwaInstallBtn" className="pwa-install-btn" type="button" onClick={installa}>
          <svg viewBox="0 0 24 24" fill="currentColor"><path d="M19 9h-4V3H9v6H5l7 7 7-7zM5 18v2h14v-2H5z" /></svg>
          Installa l'app sul dispositivo
        </button>
      </div>
      <div id="iosInstallHint" className="pwa-install" style={{ display: isIOS ? 'block' : 'none' }}>
        <div className="ios-install-guide">
          <div className="ios-install-title">
            <svg viewBox="0 0 24 24" fill="currentColor"><path d="M17 1.01L7 1c-1.1 0-2 .9-2 2v18c0 1.1.9 2 2 2h10c1.1 0 2-.9 2-2V3c0-1.1-.9-1.99-2-1.99zM17 19H7V5h10v14z" /></svg>
            Installa su iPhone/iPad
          </div>
          <div className="ios-install-steps">
            <div className="ios-step"><span className="ios-step-num">1</span><span>Tocca <strong>Condividi</strong> in basso</span></div>
            <div className="ios-step"><span className="ios-step-num">2</span><span>Tocca <strong>Aggiungi a Home</strong></span></div>
            <div className="ios-step"><span className="ios-step-num">3</span><span>Tocca <strong>Aggiungi</strong> in alto a destra</span></div>
          </div>
        </div>
      </div>
    </>
  );
}
