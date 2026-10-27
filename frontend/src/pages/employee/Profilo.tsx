import { useEffect, useMemo, useState } from 'react';
import { CoraShell, Topbar, useCornice } from '../../cora/CoraShell';
import { nome } from '../../cora/formato';
import { esitoPrecedente, useInvioModuli } from '../../cora/moduli';
import { svgCora } from '../../cora/svg';
import { api } from '../../services/api';

interface Dati {
  first_name: string; last_name: string; email: string | null; username: string | null; fiscal_code: string | null; position: string | null;
  hire_date: string | null; iban: string; foto: string; notify_email: boolean | null; notify_push: boolean | null;
  availability_status: string | null; company_name: string | null;
}

const I = ({ n, t = false }: { n: string; t?: boolean }) => <span style={{ display: 'contents' }} dangerouslySetInnerHTML={{ __html: svgCora(n, t) }} />;
const FLASH: Record<string, string> = { updated: 'Dati aggiornati', photo_updated: 'Foto aggiornata', photo_removed: 'Foto rimossa', notifications_updated: 'Preferenze salvate' };
const DISPONIBILITA: Record<string, string> = { operative: 'Operativo', in_call: 'In chiamata', in_meeting: 'In riunione' };
const dmy = (d: string) => `${d.slice(8, 10)}/${d.slice(5, 7)}/${d.slice(0, 4)}`;
/** Moduli che si inviano da soli al cambio (onchange="this.form.submit()" del PHP), passando dall'invio intercettato. */
const inviaModulo = (e: React.ChangeEvent<HTMLInputElement>) => e.currentTarget.form?.requestSubmit();

/** Profilo del dipendente (employee/profile.php, interfaccia Cora). */
export default function Profilo() {
  const cornice = useCornice();
  const [d, setD] = useState<Dati | null>(null);
  const q = useMemo(() => new URLSearchParams(location.search), []);
  const precedente = useMemo(() => esitoPrecedente(), []);
  const versione = useMemo(() => Math.floor(Date.now() / 1000), []);
  useEffect(() => { api.get('/dipendente/profilo').then((r) => setD(r.data)).catch(() => undefined); }, []);
  useInvioModuli({
    dati: { url: () => '/dipendente/profilo/dati', messaggio: 'updated' },
    foto: { url: () => '/dipendente/profilo/foto', messaggio: 'photo_updated' },
    rimuovi: { url: () => '/dipendente/profilo/foto/rimuovi', messaggio: 'photo_removed' },
    notifiche: { url: () => '/dipendente/profilo/notifiche', messaggio: 'notifications_updated' },
  }, !!(cornice && d));

  if (!cornice || !d) return null;

  const primo = nome(d.first_name);
  const ultimo = nome(d.last_name);
  const ruolo = (d.position ?? '').trim();
  const sottotitolo = [ruolo, d.company_name ?? ''].filter((x) => x !== '').join(' - ');
  const stato = d.availability_status ?? 'operative';
  const flash = FLASH[q.get('message') ?? ''];
  const errore = precedente?.errore ?? '';

  return (
    <CoraShell pagina="profile" titolo="Il mio profilo" cornice={cornice}>
      {flash && <span data-cora-flash="" data-title={flash} hidden />}

      <div className="cora-reveal" style={{ '--i': 0 } as React.CSSProperties}>
        <Topbar titolo="Il mio profilo" sottotitolo="Dati personali, foto e preferenze di notifica." cornice={cornice} />
      </div>

      {errore && <div className="cora-alert is-error">{errore}</div>}

      <div className="cora-profile">
        <div className="cora-profile-col">
          <section className="cora-card cora-profile-card cora-reveal" style={{ '--i': 1 } as React.CSSProperties}>
            <span className="cora-profile-photo">
              {d.foto ? <img src={`${d.foto}?v=${versione}`} alt="" /> : <span>{(primo.slice(0, 1) + ultimo.slice(0, 1)).toUpperCase()}</span>}
            </span>
            <span className="cora-profile-name">
              <strong>{primo} {ultimo}</strong>
              {sottotitolo !== '' && <span>{sottotitolo}</span>}
            </span>

            <div className="cora-avail availability-toggle" data-availability-toggle="" data-tour="availability" data-current={stato}>
              <button type="button" className="availability-pill cora-avail-pill" aria-haspopup="true" aria-expanded="false">
                <i className="cora-avail-dot" />
                <span className="availability-label">{DISPONIBILITA[stato] ?? 'Operativo'}</span>
                <I n="ic-chev-d.svg" t />
              </button>
              <div className="availability-menu cora-avail-menu" role="menu">
                <a href="#" data-status="operative" role="menuitem"><i className="cora-avail-dot is-operative" />Operativo</a>
                <a href="#" data-status="in_call" role="menuitem"><i className="cora-avail-dot is-in_call" />In chiamata</a>
                <a href="#" data-status="in_meeting" role="menuitem"><i className="cora-avail-dot is-in_meeting" />In riunione</a>
              </div>
            </div>

            <div className="cora-profile-photo-actions">
              <form method="POST" encType="multipart/form-data" data-invio="foto">
                <input type="hidden" name="action" value="upload_photo" />
                <label className="cora-btn cora-btn-secondary">
                  <I n="ic-up.svg" />Carica foto
                  <input type="file" name="photo" accept="image/jpeg,image/png,image/webp,image/gif" hidden data-cora-drop-target="" onChange={inviaModulo} />
                </label>
              </form>
              {d.foto && (
                <form method="POST" data-invio="rimuovi" data-conferma="Rimuovere la foto profilo?">
                  <input type="hidden" name="action" value="remove_photo" />
                  <button type="submit" className="cora-btn cora-btn-secondary cora-btn-danger-text">Rimuovi</button>
                </form>
              )}
            </div>
            <span className="cora-hint">JPG, PNG, WEBP o GIF - max 3 MB</span>
          </section>

          <section className="cora-card cora-profile-box cora-reveal" style={{ '--i': 2 } as React.CSSProperties}>
            <h2 className="cora-box-h"><I n="ic-lock.svg" />Dati contrattuali</h2>
            <dl className="cora-detail">
              <div><dt>Codice fiscale</dt><dd>{d.fiscal_code ?? '—'}</dd></div>
              <div><dt>Posizione</dt><dd>{ruolo !== '' ? ruolo : '—'}</dd></div>
              <div><dt>Data assunzione</dt><dd>{d.hire_date ? dmy(d.hire_date) : '—'}</dd></div>
              <div><dt>IBAN</dt><dd>{d.iban}</dd></div>
            </dl>
            <span className="cora-hint">Per modificare questi dati scrivi all’HR.</span>
            <a className="cora-btn cora-btn-secondary" href="/employee/chat?with_admin=1"><I n="ic-chat.svg" />Scrivi all’HR</a>
          </section>
        </div>

        <div className="cora-profile-col is-wide">
          <form className="cora-card cora-profile-box cora-reveal" style={{ '--i': 1 } as React.CSSProperties} method="POST" data-invio="dati">
            <input type="hidden" name="action" value="update_info" />
            <h2 className="cora-box-h">Dati personali</h2>
            <div className="cora-grid-2">
              <label className="cora-field"><span className="cora-label">Nome<span className="req">*</span></span>
                <input className="cora-input" type="text" name="first_name" defaultValue={d.first_name} required maxLength={50} /></label>
              <label className="cora-field"><span className="cora-label">Cognome<span className="req">*</span></span>
                <input className="cora-input" type="text" name="last_name" defaultValue={d.last_name} required maxLength={50} /></label>
              <label className="cora-field"><span className="cora-label">Email</span>
                <span className="cora-locked"><input className="cora-input" type="text" defaultValue={d.email ?? ''} readOnly /><I n="ic-lock-sm.svg" /></span></label>
              <label className="cora-field"><span className="cora-label">Username</span>
                <span className="cora-locked"><input className="cora-input" type="text" defaultValue={d.username ?? ''} readOnly /><I n="ic-lock-sm.svg" /></span></label>
            </div>
            <div><button type="submit" className="cora-btn cora-btn-primary"><I n="logo-cora-white.svg" />Salva modifiche</button></div>
          </form>

          <div className="cora-profile-pair">
            <form className="cora-card cora-profile-box cora-reveal" style={{ '--i': 2 } as React.CSSProperties} method="POST" data-tour="profile-notify" data-invio="notifiche">
              <input type="hidden" name="action" value="update_notifications" />
              <h2 className="cora-box-h"><I n="ic-bell-sm.svg" />Notifiche</h2>
              <label className="cora-toggle-row">
                <span><strong>Email</strong><span>Nuovi documenti, comunicazioni, esiti delle richieste</span></span>
                <input type="checkbox" name="notify_email" value="1" defaultChecked={d.notify_email ?? true} onChange={inviaModulo} />
                <i className="cora-switch" />
              </label>
              <label className="cora-toggle-row">
                <span><strong>Push</strong><span>Notifiche sul browser e sul telefono</span></span>
                <input type="checkbox" name="notify_push" value="1" defaultChecked={d.notify_push ?? true} onChange={inviaModulo} />
                <i className="cora-switch" />
              </label>
            </form>

            <section className="cora-card cora-profile-box cora-reveal" style={{ '--i': 2 } as React.CSSProperties} data-tour="profile-guide">
              <h2 className="cora-box-h"><I n="ic-book.svg" />Guida interattiva</h2>
              <p className="cora-box-p">Rivedi passo per passo come usare Cora: menu, richieste di ferie e permessi, documenti, calendario e chat.</p>
              <div><button type="button" className="cora-btn cora-btn-secondary" data-tour-restart="">Rivedi la guida</button></div>
            </section>
          </div>

          <section className="cora-card cora-profile-box cora-session cora-reveal" style={{ '--i': 3 } as React.CSSProperties}>
            <div className="cora-session-txt">
              <h2 className="cora-box-h">Sessione</h2>
              <p className="cora-box-p">Esci da questa sessione. Dovrai inserire di nuovo le credenziali al prossimo accesso.</p>
            </div>
            <div><a className="cora-btn cora-btn-danger" href="/auth/logout"><I n="ic-logout.svg" />Esci dall’account</a></div>
          </section>
        </div>
      </div>
    </CoraShell>
  );
}
