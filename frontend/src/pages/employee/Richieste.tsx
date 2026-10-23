import { useEffect, useMemo, useState } from 'react';
import { CoraShell, Topbar, useCornice } from '../../cora/CoraShell';
import { dataBreve, fa, giorniLunVen, num, partiRoma, TIPI_ASSENZA } from '../../cora/formato';
import { esitoPrecedente, useInvioModuli } from '../../cora/moduli';
import { svgCora } from '../../cora/svg';
import { api } from '../../services/api';

interface Richiesta {
  id: number; leave_type: string; start_date: string; end_date: string; is_full_day: boolean; start_time: string | null; end_time: string | null;
  reason: string | null; notes: string | null; status: string; created_at: string; rejection_reason: string | null;
  protocol_number: string | null; certificate_path: string | null; certificate_waived: boolean | null;
}
interface Dati { richieste: Richiesta[]; residui: { ferie: number; permesso: number }; malattieSenzaDocumenti: number; festivita: string[] }

const I = ({ n }: { n: string }) => <span style={{ display: 'contents' }} dangerouslySetInnerHTML={{ __html: svgCora(n) }} />;
const COLORE: Record<string, string> = {
  ferie: 'ferie', permesso: 'permesso', permesso_104: 'permesso', malattia: 'malattia', smart_working: 'sw',
  congedo_parentale: 'altro', congedo_separazione: 'altro', congedo_mestruale: 'altro', altro: 'altro', chiusura: 'altro',
};
const STATO: Record<string, string> = { pending: 'In attesa', approved: 'Approvata', rejected: 'Rifiutata', cancelled: 'Annullata' };
const TIPI_MODALE: Array<[string, string, boolean]> = [
  ['ferie', 'Ferie', true], ['permesso', 'Permesso', false], ['malattia', 'Malattia', true],
  ['permesso_104', 'L.104', false], ['smart_working', 'Smart working', true], ['altro', 'Altro', true],
];
const FLASH: Record<string, [string, string]> = {
  created: ['Richiesta inviata', "L'HR riceverà una notifica. Ti avviseremo quando sarà approvata."],
  cancelled: ['Richiesta annullata', ''],
  docs_saved: ['Documenti salvati', 'Protocollo e certificato sono stati aggiornati.'],
};
const OROLOGIO = '<svg viewBox="0 0 18 18" width="18" height="18" fill="none" aria-hidden="true"><circle cx="9" cy="9" r="6.75" stroke="#8A9794" stroke-width="1.35"/><path d="M9 5.25V9l2.25 1.5" stroke="#8A9794" stroke-width="1.35" stroke-linecap="round" stroke-linejoin="round"/></svg>';

const etichetta = (t: string) => TIPI_ASSENZA[t] ?? t.charAt(0).toUpperCase() + t.slice(1);

/** "23 ott → 29 ott 2026", "8 ott 2026", "3 ott 2026 - 14:00 – 18:00" */
function periodo(r: Richiesta): string {
  const s = r.start_date;
  const e = r.end_date;
  let out = s === e ? dataBreve(s) : `${dataBreve(s, s.slice(0, 4) !== e.slice(0, 4))} → ${dataBreve(e)}`;
  if (!r.is_full_day && r.start_time) out += ` - ${r.start_time.slice(0, 5)} – ${String(r.end_time ?? '').slice(0, 5)}`;
  return out;
}

/** "5 giorni", "1 giorno", "4 ore" */
function durata(r: Richiesta): string {
  const giorni = giorniLunVen(r.start_date, r.end_date);
  if (!r.is_full_day && r.start_time && r.end_time) {
    const min = (t: string) => Number(t.slice(0, 2)) * 60 + Number(t.slice(3, 5)) + Number(t.slice(6, 8) || 0) / 60;
    const h = num(Math.round(Math.max(0, (min(r.end_time) - min(r.start_time)) / 60) * Math.max(1, giorni) * 10) / 10);
    return h + (h === '1' ? ' ora' : ' ore');
  }
  return giorni + (giorni === 1 ? ' giorno' : ' giorni');
}

/** Ferie e permessi del dipendente (employee/leave-requests.php, interfaccia Cora). */
export default function Richieste() {
  const cornice = useCornice();
  const [d, setD] = useState<Dati | null>(null);
  const precedente = useMemo(() => esitoPrecedente(), []);
  // Parametri dell'indirizzo al caricamento: cora.js toglie ?new e ?message
  // subito dopo (history.replaceState), la pagina deve restare com'era.
  const q = useMemo(() => new URLSearchParams(location.search), []);
  useEffect(() => { api.get('/dipendente/richieste').then((r) => setD(r.data)).catch(() => undefined); }, []);
  useInvioModuli({
    crea: { url: () => '/dipendente/richieste', messaggio: 'created' },
    annulla: { url: (f) => `/dipendente/richieste/${f.get('request_id')}/annulla`, messaggio: 'cancelled' },
    documenti: { url: (f) => `/dipendente/richieste/${f.get('request_id')}/documenti`, messaggio: 'docs_saved' },
  }, !!(cornice && d));

  if (!cornice || !d) return null;

  const conteggi = { pending: 0, approved: 0, rejected: 0 } as Record<string, number>;
  for (const r of d.richieste) if (r.status in conteggi) conteggi[r.status]++;
  const errore = precedente?.errore ?? '';
  const post = precedente?.modulo === 'crea' ? precedente.valori : {};
  const preset0 = post.leave_type ?? q.get('new') ?? '';
  const preset = TIPI_MODALE.some(([k]) => k === preset0) ? preset0 : 'ferie';
  const intera = post.is_full_day !== undefined ? post.is_full_day === '1' : TIPI_MODALE.find(([k]) => k === preset)![2];
  const flash = FLASH[q.get('message') ?? ''];
  const iniziale = ['pending', 'approved', 'rejected'].includes(q.get('status') ?? '') ? q.get('status')! : 'all';

  return (
    <CoraShell pagina="leave-requests" titolo="Ferie e permessi" cornice={cornice}
      script={['/assets/js/cora-pickers.js', '/assets/js/cora-leave.js']}
      globali={{ CORA_LEAVE: { holidays: d.festivita, residual: { ferie: d.residui.ferie, permesso: d.residui.permesso }, open: (!!precedente && precedente.modulo === 'crea' && !!errore) || q.has('new') } }}>
      {flash && <span data-cora-flash data-title={flash[0]} data-message={flash[1]} hidden />}

      <div className="cora-reveal" style={{ '--i': 0 } as React.CSSProperties}>
        <Topbar titolo="Ferie e permessi" sottotitolo="Le tue richieste di ferie, permessi e malattia, con lo stato di approvazione." cornice={cornice}
          azioni={<button type="button" className="cora-btn cora-btn-primary" data-cora-open="coraLeaveModal" data-tour="leave-form"><I n="logo-cora-white.svg" />Nuova richiesta</button>} />
      </div>

      {errore && <div className="cora-alert is-error">{errore}</div>}
      {d.malattieSenzaDocumenti > 0 && (
        <div className="cora-alert is-error">
          Hai {d.malattieSenzaDocumenti} richiest{d.malattieSenzaDocumenti === 1 ? 'a' : 'e'} di malattia senza documenti: apri la richiesta e carica protocollo INPS e certificato.
        </div>
      )}

      <section className="cora-stats cora-reveal" style={{ '--i': 1 } as React.CSSProperties}>
        <div className="cora-card cora-stat">
          <span className="cora-stat-l">Ferie residue</span>
          <span className="cora-stat-v"><strong>{num(d.residui.ferie)}</strong><span>giorni</span></span>
        </div>
        <div className="cora-card cora-stat">
          <span className="cora-stat-l">Permessi residui</span>
          <span className="cora-stat-v"><strong>{num(d.residui.permesso)}</strong><span>ore</span></span>
        </div>
        <div className="cora-card cora-stat">
          <span className="cora-stat-l"><i className="cora-stat-dot" />In attesa di approvazione</span>
          <span className="cora-stat-v"><strong>{conteggi.pending}</strong><span>{conteggi.pending === 1 ? 'richiesta' : 'richieste'}</span></span>
        </div>
      </section>

      <section className="cora-card cora-table-card cora-reveal" style={{ '--i': 2 } as React.CSSProperties} id="coraLeaveList" data-tour="leave-list">
        <div className="cora-filters" data-cora-filter-group="#coraLeaveList">
          {([['all', 'Tutte'], ['pending', 'In attesa'], ['approved', 'Approvate'], ['rejected', 'Rifiutate']] as const).map(([k, l]) => (
            <button key={k} type="button" className={'cora-tab' + (iniziale === k ? ' is-active' : '')} data-filter={k}>
              {l}
              {k === 'pending' && conteggi.pending > 0 && <span className="cora-tab-count">{conteggi.pending}</span>}
            </button>
          ))}
        </div>

        {!d.richieste.length ? (
          <div className="cora-empty" style={{ minHeight: 320 }}>
            <span className="cora-empty-ic"><I n="ic-empty.svg" /></span>
            <h3>Nessuna richiesta</h3>
            <p>Quando invierai una richiesta di ferie o permesso la troverai qui.</p>
          </div>
        ) : (
          <>
            <div className="cora-table" data-cora-section>
              <div className="cora-tr cora-th">
                <span className="c-type">Tipo</span><span className="c-period">Periodo</span><span className="c-dur">Durata</span>
                <span className="c-status">Stato</span><span className="c-sent">Inviata</span><span className="c-chev" />
              </div>
              {d.richieste.map((r) => {
                const c = partiRoma(r.created_at);
                const sotto = r.leave_type === 'malattia' ? `comunicata il ${c.g}/${c.m}` : `creata ${fa(r.created_at)}`;
                return (
                  <button key={r.id} type="button" className="cora-tr" data-cora-filterable data-tags={r.status} data-cora-open={`coraLeave${r.id}`}>
                    <span className="c-type">
                      <i className={`cora-type-dot is-${COLORE[r.leave_type] ?? 'altro'}`} />
                      <span><strong>{etichetta(r.leave_type)}</strong><small>{sotto}</small></span>
                    </span>
                    <span className="c-period">{periodo(r)}</span>
                    <span className="c-dur">{durata(r)}</span>
                    <span className="c-status"><span className={`cora-status is-${r.status}`}>{STATO[r.status] ?? r.status}</span></span>
                    <span className="c-sent">Inviata il {c.g}/{c.m} - {c.ore}:{c.min}</span>
                    <span className="c-chev"><I n="ic-chev-r-light.svg" /></span>
                  </button>
                );
              })}
            </div>
            <div className="cora-empty" data-cora-filter-empty hidden style={{ minHeight: 200 }}>
              <h3>Nessuna richiesta</h3>
              <p>Non ci sono richieste con questo stato.</p>
            </div>
          </>
        )}
      </section>

      {d.richieste.map((r) => <Dettaglio key={r.id} r={r} />)}

      <NuovaRichiesta preset={preset} intera={intera} post={post} errore={precedente?.modulo === 'crea' ? errore : ''} />
    </CoraShell>
  );
}

function Dettaglio({ r }: { r: Richiesta }) {
  const malattia = r.leave_type === 'malattia';
  const conProt = !!r.protocol_number;
  const conCert = !!r.certificate_path;
  const esonero = !!r.certificate_waived;
  const servonoDoc = malattia && (!conProt || (!conCert && !esonero)) && ['pending', 'approved'].includes(r.status);
  const c = partiRoma(r.created_at);
  return (
    <div className="cora-modal" id={`coraLeave${r.id}`} hidden>
      <div className="cora-modal-box cora-modal-sm" role="dialog" aria-modal="true">
        <div className="cora-modal-h">
          <div>
            <h2>{etichetta(r.leave_type)}</h2>
            <p>{periodo(r)} · {durata(r)}</p>
          </div>
          <button type="button" className="cora-modal-x" data-cora-close aria-label="Chiudi"><I n="ic-x-modal.svg" /></button>
        </div>
        <dl className="cora-detail">
          <div><dt>Stato</dt><dd><span className={`cora-status is-${r.status}`}>{STATO[r.status] ?? r.status}</span></dd></div>
          <div><dt>Inviata</dt><dd>{c.g}/{c.m}/{c.a} - {c.ore}:{c.min}</dd></div>
          {r.reason && <div><dt>Motivazione</dt><dd>{r.reason}</dd></div>}
          {r.notes && <div><dt>Note</dt><dd>{r.notes}</dd></div>}
          {r.status === 'rejected' && r.rejection_reason && <div><dt>Motivo del rifiuto</dt><dd>{r.rejection_reason}</dd></div>}
          {malattia && (
            <>
              <div><dt>Protocollo INPS</dt><dd>{conProt ? r.protocol_number : '—'}</dd></div>
              <div><dt>Certificato</dt><dd>
                {conCert ? <a className="cora-link" href={`/api/v1/dipendente/richieste/${r.id}/certificato`}>Scarica</a> : esonero ? 'Non richiesto' : '—'}
              </dd></div>
            </>
          )}
        </dl>
        {servonoDoc && (
          <form method="POST" encType="multipart/form-data" className="cora-form" data-invio="documenti">
            <input type="hidden" name="action" value="add_sick_docs" />
            <input type="hidden" name="request_id" value={r.id} />
            <div className="cora-alert is-info">Carica numero di protocollo INPS e certificato medico.</div>
            {!conProt && (
              <label className="cora-field"><span className="cora-label">Numero di protocollo</span>
                <input className="cora-input" type="text" name="protocol_number" maxLength={100} placeholder="Es. 1234567890" /></label>
            )}
            {!conCert && (
              <label className="cora-field"><span className="cora-label">Certificato medico</span>
                <input className="cora-input cora-file" type="file" name="certificate" accept=".pdf,.jpg,.jpeg,.png" /></label>
            )}
            <div className="cora-modal-actions"><button type="submit" className="cora-btn cora-btn-primary"><I n="logo-cora-white.svg" />Salva documenti</button></div>
          </form>
        )}
        {r.status === 'pending' && (
          <form method="POST" className="cora-modal-actions" data-invio="annulla" data-conferma="Annullare questa richiesta?">
            <input type="hidden" name="action" value="cancel" />
            <input type="hidden" name="request_id" value={r.id} />
            <button type="submit" className="cora-btn cora-btn-secondary cora-btn-danger-text">Annulla richiesta</button>
          </form>
        )}
      </div>
    </div>
  );
}

function NuovaRichiesta({ preset, intera, post, errore }: { preset: string; intera: boolean; post: Record<string, string>; errore: string }) {
  return (
    <div className="cora-modal" id="coraLeaveModal" hidden>
      <form className="cora-modal-box" method="POST" encType="multipart/form-data" id="coraLeaveForm" role="dialog" aria-modal="true" aria-labelledby="coraLeaveTitle" data-invio="crea">
        <input type="hidden" name="action" value="create" />
        <input type="hidden" name="leave_type" defaultValue={preset} data-leave-type />

        <div className="cora-modal-h">
          <div>
            <h2 id="coraLeaveTitle">Nuova richiesta</h2>
            <p>Scegli il tipo e il periodo: la richiesta arriva all’HR per l’approvazione.</p>
          </div>
          <button type="button" className="cora-modal-x" data-cora-close aria-label="Chiudi"><I n="ic-x-modal.svg" /></button>
        </div>

        {errore && <div className="cora-alert is-error">{errore}</div>}

        <div className="cora-fieldset" data-tour="leave-types">
          <span className="cora-label">Tipo di richiesta</span>
          <div className="cora-chips" data-leave-types>
            {TIPI_MODALE.map(([k, l, piena]) => (
              <button key={k} type="button" className={'cora-chip' + (preset === k ? ' is-selected' : '')} data-type={k} data-full={piena ? '1' : '0'}>{l}</button>
            ))}
          </div>
        </div>

        <div className="cora-fieldset">
          <span className="cora-label">Periodo rapido</span>
          <div className="cora-chips" data-leave-presets>
            <button type="button" className="cora-chip" data-preset="today">Oggi</button>
            <button type="button" className="cora-chip" data-preset="tomorrow">Domani</button>
            <button type="button" className="cora-chip" data-preset="rest-of-week">Resto della settimana</button>
            <button type="button" className="cora-chip" data-preset="next-week">Settimana prossima</button>
          </div>
        </div>

        <div className="cora-fieldset">
          <span className="cora-label">Date</span>
          <div className="cora-grid-2">
            <label className="cora-field"><span className="cora-sublabel">Inizio</span>
              <span className="cora-picker" data-cora-date data-role="start"><input className="cora-input" type="text" inputMode="numeric" autoComplete="off" placeholder="gg/mm/aaaa" aria-label="Data di inizio" /><input type="hidden" name="start_date" defaultValue={post.start_date ?? ''} data-start /><span className="cora-field-ic"><I n="ic-cal.svg" /></span></span></label>
            <label className="cora-field"><span className="cora-sublabel">Fine</span>
              <span className="cora-picker" data-cora-date data-role="end"><input className="cora-input" type="text" inputMode="numeric" autoComplete="off" placeholder="gg/mm/aaaa" aria-label="Data di fine" /><input type="hidden" name="end_date" defaultValue={post.end_date ?? ''} data-end /><span className="cora-field-ic"><I n="ic-cal.svg" /></span></span></label>
          </div>
        </div>

        <div className="cora-fieldset">
          <span className="cora-label">Durata</span>
          <div className="cora-grid-2">
            <label className="cora-radio"><input type="radio" name="is_full_day" value="1" defaultChecked={intera} /><i />Giornata intera</label>
            <label className="cora-radio"><input type="radio" name="is_full_day" value="0" defaultChecked={!intera} /><i />Solo alcune ore</label>
          </div>
          <div className="cora-grid-2" data-leave-times hidden={intera}>
            <label className="cora-field"><span className="cora-sublabel">Dalle ore</span>
              <span className="cora-picker" data-cora-time><input className="cora-input" type="text" inputMode="numeric" autoComplete="off" maxLength={5} placeholder="hh:mm" name="start_time" defaultValue={post.start_time ?? '09:00'} data-start-time /><span className="cora-field-ic" dangerouslySetInnerHTML={{ __html: OROLOGIO }} /></span></label>
            <label className="cora-field"><span className="cora-sublabel">Alle ore</span>
              <span className="cora-picker" data-cora-time><input className="cora-input" type="text" inputMode="numeric" autoComplete="off" maxLength={5} placeholder="hh:mm" name="end_time" defaultValue={post.end_time ?? '13:00'} data-end-time /><span className="cora-field-ic" dangerouslySetInnerHTML={{ __html: OROLOGIO }} /></span></label>
          </div>
        </div>

        <div className="cora-fieldset" data-leave-sick hidden>
          <span className="cora-label">Documenti malattia</span>
          <div className="cora-grid-2">
            <label className="cora-field"><span className="cora-sublabel">Numero di protocollo INPS</span>
              <input className="cora-input" type="text" name="protocol_number" maxLength={100} placeholder="Es. 1234567890" defaultValue={post.protocol_number ?? ''} /></label>
            <label className="cora-field"><span className="cora-sublabel">Certificato medico</span>
              <input className="cora-input cora-file" type="file" name="certificate" accept=".pdf,.jpg,.jpeg,.png" /></label>
          </div>
          <span className="cora-hint">Se non li hai ancora puoi aggiungerli dopo, aprendo la richiesta.</span>
        </div>

        <div className="cora-fieldset">
          <span className="cora-label">Motivazione (facoltativa)</span>
          <textarea className="cora-input cora-textarea" name="reason" rows={3} placeholder="Es. viaggio in famiglia" defaultValue={post.reason ?? ''} />
        </div>

        <div className="cora-info" data-leave-info hidden>
          <I n="ic-info.svg" />
          <span data-leave-info-text />
        </div>

        <div className="cora-modal-actions">
          <button type="button" className="cora-btn cora-btn-ghost" data-cora-close>Annulla</button>
          <button type="submit" className="cora-btn cora-btn-primary"><I n="logo-cora-white.svg" />Invia richiesta</button>
        </div>
      </form>
    </div>
  );
}
