import { FormEvent, useEffect, useMemo, useState } from 'react';
import { CoraShell, useCornice } from '../../cora/CoraShell';
import { api } from '../../services/api';
import css from './academy.css?inline';

const BADGE: Record<string, string> = { not_started: 'badge-neutral', in_progress: 'badge-primary', completed: 'badge-success', overdue: 'badge-danger' };
const ERRORI: Record<string, string> = {
  dichiarazione: 'Per completare devi spuntare la dichiarazione',
  materiali: 'Apri tutti i materiali del corso prima di completarlo',
};
const TIPO: Record<string, string> = { link: 'Link esterno', video: 'Video', audio: 'Audio', image: 'Immagine', pdf: 'Documento PDF', download: 'File da scaricare' };
const dmy = (d: string | null) => (d ? `${d.slice(8, 10)}/${d.slice(5, 7)}/${d.slice(0, 4)}` : '');
/** 4.00 -> "4", 1.50 -> "1,5" (number_format con le cifre a zero tolte). */
const ore = (n: unknown) => Number(n).toFixed(2).replace('.', ',').replace(/0+$/, '').replace(/,$/, '');

function vista(m: any): string {
  const mime = String(m.mime_type ?? '');
  if (m.kind === 'link') return 'link';
  if (mime.startsWith('video/')) return 'video';
  if (mime.startsWith('audio/')) return 'audio';
  if (mime.startsWith('image/')) return 'image';
  return mime === 'application/pdf' ? 'pdf' : 'download';
}

/** Academy del dipendente (employee/academy.php): pagina classica dentro la cornice Cora. */
export default function Academy() {
  const cornice = useCornice();
  const q = useMemo(() => new URLSearchParams(location.search), []);
  const idCorso = Number.parseInt(q.get('course') ?? '', 10) || 0;
  const [d, setD] = useState<any>(null);
  useEffect(() => {
    api.get('/dipendente/academy', { params: idCorso ? { course: idCorso } : {} }).then((r) => setD(r.data))
      // Corso non assegnato: si torna all'elenco, come il PHP.
      .catch((e) => { if (e?.response?.status === 404) location.replace('/employee/academy.php'); });
  }, [idCorso]);

  if (!cornice || !d) return null;

  if (!d.corso) {
    return (
      <CoraShell stile={css} pagina="academy" titolo="Academy" cornice={cornice} classica>
        <div className="welcome-card ea-hero">
          <h2>Academy</h2>
          <p>I corsi che ti sono stati assegnati. Apri tutti i materiali e alla fine premi "Completato": quando HR genera l'attestato lo trovi qui.</p>
        </div>
        {!d.corsi.length ? (
          <section className="card ea-section"><p style={{ margin: 0, color: '#6e7191' }}>Non hai corsi assegnati al momento.</p></section>
        ) : (
          <div className="ea-list">
            {d.corsi.map((c: any) => {
              const st = c.stato;
              const tot = Number(c.material_count);
              const pct = st === 'completed' ? 100 : tot > 0 ? Math.round((Math.min(Number(c.opened_count), tot) / tot) * 100) : 0;
              return (
                <a key={c.id} className="card ea-item" href={`?course=${c.course_id}`}>
                  <div className="body">
                    <h4>{c.title}</h4>
                    <div className="meta">
                      <span className={`badge badge-dot ${BADGE[st]}`}>{d.etichette[st]}</span>
                      {c.due_date && st !== 'completed' && `  entro il ${dmy(c.due_date)}`}
                      {c.certificate_id && !c.employee_downloaded_at
                        ? <>{'  '}<span className="badge badge-dot badge-success">Nuovo attestato</span></>
                        : c.certificate_id ? '  · attestato disponibile' : null}
                    </div>
                    <div className="ea-progress"><span style={{ width: `${pct}%` }} /></div>
                  </div>
                  <span className="arrow">›</span>
                </a>
              );
            })}
          </div>
        )}
      </CoraShell>
    );
  }

  const { corso, assegnazione: a, materiali, aperti, stato } = d;
  const tuttiAperti = materiali.every((m: any) => aperti.includes(m.id));
  const nAperti = aperti.filter((x: number) => materiali.some((m: any) => m.id === x)).length;
  const errore = ERRORI[q.get('err') ?? ''] ?? (q.get('err') ? 'Operazione non riuscita' : '');

  const avvia = async (e: FormEvent) => {
    e.preventDefault();
    await api.post(`/dipendente/academy/${idCorso}/avvia`, {}).catch(() => undefined);
    location.assign(`/employee/academy.php?course=${idCorso}`);
  };
  const completa = async (e: FormEvent<HTMLFormElement>) => {
    e.preventDefault();
    const dich = new FormData(e.currentTarget).get('declaration');
    const r = await api.post(`/dipendente/academy/${idCorso}/completa`, { declaration: !!dich }).catch(() => ({ data: { ok: false, errore: 'altro' } }));
    location.assign(`/employee/academy.php?course=${idCorso}` + (r.data.ok ? '&done=1' : `&err=${r.data.errore}`));
  };
  const fine = a.completed_at ? `${dmy(a.completed_at)} alle ${String(a.completed_at).slice(11, 16)}` : '';

  return (
    <CoraShell stile={css} pagina="academy" titolo={corso.title} cornice={cornice} classica
      script={a.started_at ? ['/assets/js/cora-academy.js'] : []} globali={{ CORA_ACADEMY: { total: materiali.length, opened: aperti } }}>
      <div className="welcome-card ea-hero">
        <a className="ea-back" href="/employee/academy.php">&larr; I miei corsi</a>
        <h2>{corso.title}</h2>
        <p>
          <span className={`badge badge-dot ${BADGE[stato]}`}>{d.etichette[stato]}</span>
          {corso.duration_hours !== null && `  ${ore(corso.duration_hours)} ore`}
          {corso.due_date && stato !== 'completed' && `  · da completare entro il ${dmy(corso.due_date)}`}
        </p>
      </div>

      {q.has('done') && <div className="ea-alert ok">Corso completato. Riceverai una notifica quando l'attestato sarà disponibile.</div>}
      {errore && <div className="ea-alert err">{errore}</div>}

      {corso.description && (
        <section className="card ea-section">
          <p style={{ margin: 0, whiteSpace: 'pre-line', color: '#334155', fontSize: '0.92rem' }}>{corso.description}</p>
        </section>
      )}

      {!a.started_at ? (
        <section className="card ea-section" style={{ textAlign: 'center' }}>
          <p style={{ margin: '0 0 1rem', color: '#475569' }}>Quando sei pronto premi <strong>Inizia</strong>: da quel momento vengono registrati data e ora dei tuoi accessi ai materiali.</p>
          <form method="POST" onSubmit={avvia}>
            <input type="hidden" name="action" value="start" />
            <input type="hidden" name="course_id" value={idCorso} />
            <button type="submit" className="btn btn-primary">Inizia il corso</button>
          </form>
        </section>
      ) : (
        <>
          <section className="card ea-section">
            <h3>Materiali <span style={{ fontWeight: 500, color: '#94a3b8', fontSize: '0.85rem' }}>({nAperti}/{materiali.length} aperti)</span></h3>
            {materiali.map((m: any) => {
              const aperto = aperti.includes(m.id);
              const src = `/academy-file.php?m=${m.id}`;
              const v = vista(m);
              const ext = m.kind === 'link' ? 'link' : String(m.original_name ?? '').split('.').slice(1).pop()?.toLowerCase() ?? '';
              return (
                <div key={m.id} className="ea-mat" data-material={m.id}>
                  <span className={`ic ${aperto ? 'done' : ''}`}>{aperto ? '✓' : [...ext].slice(0, 4).join('')}</span>
                  <div className="tt">
                    <strong>{m.title}</strong>
                    <span>{TIPO[v]}{aperto ? ' · aperto' : ''}</span>
                  </div>
                  {v === 'link' ? <a className="btn btn-sm btn-secondary ea-open" href={src} target="_blank" rel="noopener">Apri</a>
                    : v === 'download' ? <a className="btn btn-sm btn-secondary ea-open" href={`${src}&dl=1`}>Scarica</a>
                      : <button type="button" className="btn btn-sm btn-primary ea-view" data-src={src} data-view={v} data-title={m.title}>Apri</button>}
                </div>
              );
            })}
            <div className="ea-viewer" id="eaViewer">
              <div className="ea-viewer-head">
                <strong id="eaViewerTitle" />
                <span style={{ display: 'flex', gap: '0.4rem' }}>
                  <a className="btn btn-sm btn-ghost" id="eaViewerNewTab" target="_blank" rel="noopener">Apri a schermo intero</a>
                  <button type="button" className="btn btn-sm btn-ghost" id="eaViewerClose">Chiudi</button>
                </span>
              </div>
              <div id="eaViewerBody" />
            </div>
          </section>

          <section className="card ea-section" id="eaComplete">
            {a.completed_at ? (
              <>
                <h3>Corso completato</h3>
                <div className="ea-done">
                  <span style={{ color: '#475569', fontSize: '0.9rem' }}>Completato il {fine}.</span>
                  {a.certificate_id ? (
                    <>
                      <a className="btn btn-primary" href={`/academy-file.php?cert=${a.certificate_id}`} target="_blank" rel="noopener"
                        onClick={() => document.getElementById('eaNewCert')?.remove()}>Scarica attestato</a>
                      {!a.employee_downloaded_at && <span className="badge badge-dot badge-success" id="eaNewCert">Nuovo</span>}
                    </>
                  ) : <span className="badge badge-dot badge-neutral">Attestato in preparazione</span>}
                </div>
              </>
            ) : (
              <>
                <h3>Conferma il completamento</h3>
                <form method="POST" id="eaCompleteForm" onSubmit={completa}>
                  <input type="hidden" name="action" value="complete" />
                  <input type="hidden" name="course_id" value={idCorso} />
                  <label className="ea-check">
                    <input type="checkbox" name="declaration" value="1" required />
                    <span>{d.dichiarazione}</span>
                  </label>
                  <button type="submit" className="btn btn-primary" id="eaCompleteBtn" disabled={!tuttiAperti}>Completato</button>
                  <p id="eaCompleteHint" style={{ margin: '0.6rem 0 0', fontSize: '0.8rem', color: '#94a3b8', ...(tuttiAperti ? { display: 'none' } : {}) }}>
                    Il pulsante si attiva dopo aver aperto tutti i materiali.
                  </p>
                </form>
              </>
            )}
          </section>
        </>
      )}
    </CoraShell>
  );
}
