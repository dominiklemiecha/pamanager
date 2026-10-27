import { FormEvent, useEffect, useMemo, useState } from 'react';
import { useCss, useStile, useTitolo } from '../../componenti/stile';
import { CoraShell, useCornice } from '../../cora/CoraShell';
import { api } from '../../services/api';
import cssBenvenuto from './contratto-benvenuto.css?inline';
import cssFirma from './contratto-firma.css?inline';

interface Vista { id: number; stato: string; azienda: string; contratto: boolean; firma: { uploaded_at: string; signed_ip: string; signature_hash: string } | null }

const dmyhm = (s: string | null | undefined) => {
  const v = s ?? new Intl.DateTimeFormat('sv-SE', { timeZone: 'Europe/Rome', dateStyle: 'short', timeStyle: 'short' }).format(new Date());
  return `${v.slice(8, 10)}/${v.slice(5, 7)}/${v.slice(0, 4)} ${v.slice(11, 16)}`;
};

/** Script di una pagina a se' (senza cornice), una volta sola. */
function useScript(src: string) {
  useEffect(() => {
    if (document.querySelector(`script[src="${src}"]`)) return;
    const s = document.createElement('script');
    s.src = src;
    document.body.appendChild(s);
  }, [src]);
}

/** Schermata dopo la firma, a tutto schermo con i coriandoli. */
function Benvenuto({ v }: { v: Vista }) {
  useCss('/assets/css/style.css');
  useStile(cssBenvenuto);
  useTitolo(`Benvenuto in ${v.azienda}!`);
  useScript('/assets/js/cora-coriandoli.js');
  return (
    <>
      <canvas id="confetti-canvas" />
      <div className="welcome">
        <div className="welcome__emoji">🎉</div>
        <h1 className="welcome__title">
          Benvenuto nella famiglia<br />
          <span className="welcome__company">{v.azienda}</span>!
        </h1>
        <p className="welcome__sub">
          Il tuo contratto è stato firmato con successo.<br />
          Siamo davvero felici di averti con noi. Ora puoi accedere al portale e iniziare il tuo viaggio.
        </p>
        <a href="/employee/" className="welcome__btn">
          Entra nel portale
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2.5" strokeLinecap="round" strokeLinejoin="round"><line x1="5" y1="12" x2="19" y2="12" /><polyline points="12 5 19 12 12 19" /></svg>
        </a>
        <div className="welcome__footnote">Firmato il {dmyhm(v.firma?.uploaded_at)}</div>
      </div>
    </>
  );
}

/** Finestra di firma: niente menu finche' il contratto non e' firmato. */
function Firma({ v }: { v: Vista }) {
  useCss('/assets/css/style.css');
  useStile(cssFirma);
  useTitolo('Firma contratto - Connecteed HR');
  useScript('/assets/js/cora-firma.js');
  const [errore, setErrore] = useState('');
  const invia = async (e: FormEvent<HTMLFormElement>) => {
    // Lo script della firma blocca l'invio se il riquadro e' vuoto, altrimenti mette l'immagine nel campo.
    if (e.defaultPrevented) return;
    e.preventDefault();
    const firma = String(new FormData(e.currentTarget).get('signature') ?? '');
    try {
      await api.post(`/dipendente/contratto/${v.id}/firma`, { signature: firma });
      location.assign(`/employee/contract-sign?id=${v.id}&signed=1`);
    } catch (err: any) {
      setErrore(err?.response?.data?.message ?? 'Errore firma');
    }
  };
  return (
    <div className="sign-modal" role="dialog" aria-modal="true" aria-labelledby="modalTitle">
      <div className="sign-modal__header">
        <svg viewBox="0 0 24 24" width="26" height="26" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z" /><polyline points="14 2 14 8 20 8" /><line x1="16" y1="13" x2="8" y2="13" /><line x1="16" y1="17" x2="8" y2="17" /></svg>
        <div style={{ flex: 1 }}>
          <h2 id="modalTitle" className="sign-modal__title">Contratto di assunzione da firmare</h2>
          <div className="sign-modal__subtitle">Sfoglia il documento, poi firma in fondo per accedere al portale.</div>
        </div>
        <a href={`contract-pdf.php?id=${v.id}`} target="_blank" style={{ fontSize: '.8rem', color: '#044bff' }}>Apri PDF in nuova scheda</a>
      </div>

      <div className="sign-modal__warning">
        <strong>Non puoi accedere alle altre sezioni</strong> finché non firmi il contratto.
      </div>

      <div className="sign-modal__body">
        <div className="sign-modal__pdf">
          <iframe src={`contract-pdf.php?id=${v.id}#view=FitH`} title="Contratto" />
        </div>

        <div className="sign-modal__sign">
          <h3>Firma nello spazio sotto</h3>
          <p>Usa il dito su mobile o il mouse su desktop.</p>
          {errore && <div className="sign-modal__err">{errore}</div>}
          <div className="sig-wrap">
            <canvas id="sigCanvas" />
            <button type="button" id="clearSig">Cancella</button>
          </div>
          <div className="sign-modal__legal">
            Firmando dichiari di aver letto e accettato il contratto. Vengono registrati data, ora, IP e hash SHA256 del documento ai fini legali.
          </div>
          <form method="POST" action={`contract-sign.php?id=${v.id}`} id="signForm" className="sign-modal__actions" onSubmit={invia}>
            <input type="hidden" name="action" value="sign" />
            <input type="hidden" name="signature" id="signatureData" />
            <button type="submit" id="submitSig" disabled>Firma e conferma</button>
          </form>
        </div>
      </div>
    </div>
  );
}

/** Contratto gia' firmato, dentro l'area del dipendente. */
function Firmato({ v, appena }: { v: Vista; appena: boolean }) {
  const cornice = useCornice();
  if (!cornice) return null;
  return (
    <CoraShell pagina="contract-sign" titolo="Firma contratto" cornice={cornice} classica>
      <div style={{ width: '100%', maxWidth: 1100, margin: '1.5rem auto', padding: '0 1rem' }}>
        <h1 style={{ fontSize: '1.5rem', margin: '0 0 .25rem' }}>Contratto di assunzione</h1>
        <p style={{ color: '#64748b', margin: '0 0 1rem' }}>Il tuo contratto firmato.</p>
        {(appena || v.stato === 'contract_signed') && (
          <div className="alert alert-success" style={{ marginBottom: '1rem' }}>
            <strong>✓ Contratto firmato</strong> il {dmyhm(v.firma?.uploaded_at)}.
            Il documento è ora disponibile nei tuoi documenti.
          </div>
        )}
        {!v.contratto ? <div className="alert alert-warning">Il contratto non è ancora disponibile.</div> : (
          <>
            <div className="card" style={{ marginBottom: '1rem' }}>
              <div className="card-body" style={{ padding: 0 }}>
                <div style={{ padding: '.85rem 1.25rem', borderBottom: '1px solid #e2e8f0', display: 'flex', alignItems: 'center', justifyContent: 'space-between' }}>
                  <strong style={{ fontSize: '.95rem' }}>Documento contratto</strong>
                  <a href={`contract-pdf.php?id=${v.id}`} target="_blank" style={{ fontSize: '.82rem' }}>Apri in nuova scheda</a>
                </div>
                <iframe src={`contract-pdf.php?id=${v.id}`} style={{ width: '100%', height: 600, border: 0 }} />
              </div>
            </div>
            {v.firma && (
              <div className="card">
                <div className="card-body" style={{ padding: '1.5rem' }}>
                  <h3 style={{ marginTop: 0, fontSize: '1rem' }}>La tua firma</h3>
                  <img src={`/api/v1/dipendente/contratto/${v.id}/firma`} style={{ maxWidth: 300, border: '1px solid #e2e8f0', borderRadius: 8, background: '#fff', padding: '.5rem' }} />
                  <div style={{ marginTop: '1rem', fontSize: '.82rem', color: '#64748b' }}>
                    Firmato il {dmyhm(v.firma.uploaded_at)}<br />
                    IP: <code>{v.firma.signed_ip}</code><br />
                    SHA256 contratto: <code style={{ fontSize: '.72rem' }}>{v.firma.signature_hash}</code>
                  </div>
                </div>
              </div>
            )}
          </>
        )}
      </div>
    </CoraShell>
  );
}

/** Firma del contratto di assunzione (employee/contract-sign.php). */
export default function ContrattoFirma() {
  const q = useMemo(() => new URLSearchParams(location.search), []);
  const id = Number.parseInt(q.get('id') ?? '', 10) || 0;
  const [v, setV] = useState<Vista | null>(null);
  const [assente, setAssente] = useState(false);
  useEffect(() => {
    api.get(`/dipendente/contratto/${id}`).then((r) => setV(r.data)).catch(() => setAssente(true));
  }, [id]);
  if (assente) return <>Contratto non trovato</>;
  if (!v) return null;
  const firmatoOra = q.has('signed');
  if (firmatoOra && v.stato === 'contract_signed') return <Benvenuto v={v} />;
  if (v.stato === 'contract_pending' && !firmatoOra) return <Firma v={v} />;
  return <Firmato v={v} appena={firmatoOra} />;
}
