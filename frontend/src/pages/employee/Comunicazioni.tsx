import { useEffect, useMemo, useState } from 'react';
import { CoraShell, Topbar, useCornice } from '../../cora/CoraShell';
import { dataLunga, dimensione } from '../../cora/formato';
import { useInvioModuli } from '../../cora/moduli';
import { svgCora } from '../../cora/svg';
import { api } from '../../services/api';

interface Voce { id: number; title: string; priority: string; publish_date: string; author_name: string | null; is_read: number | null }
interface Allegato { id: number; original_name: string; mime_type: string | null; size_bytes: number }
interface Aperta { id: number; title: string; priority: string; publish_date: string; author_name: string | null; content: string; letta: boolean; allegati: Allegato[] }

const I = ({ n }: { n: string }) => <span style={{ display: 'contents' }} dangerouslySetInnerHTML={{ __html: svgCora(n) }} />;
const livello = (p: string) => (['high', 'urgent'].includes(p) ? 'high' : p === 'low' ? 'low' : 'normal');
const ETICHETTA: Record<string, string> = { high: 'Priorità alta', normal: 'Priorità normale', low: 'Priorità bassa' };
const BANDIERA: Record<string, string> = { high: 'alta', normal: 'normale', low: 'bassa' };
const autore = (c: { author_name: string | null }) => (c.author_name ?? '').trim() || 'HR';
const dmy = (d: string) => `${d.slice(8, 10)}/${d.slice(5, 7)}/${d.slice(0, 4)}`;
function ext(nome: string, mime: string | null): string {
  const i = nome.lastIndexOf('.');
  const e = i >= 0 ? nome.slice(i + 1).toUpperCase() : '';
  return e !== '' ? e : mime === 'application/pdf' ? 'PDF' : 'FILE';
}

/** Comunicazioni del dipendente (employee/communications.php, interfaccia Cora). */
export default function Comunicazioni() {
  const q = useMemo(() => new URLSearchParams(location.search), []);
  const id = Number.parseInt(q.get('id') ?? '', 10) || 0;
  // Aprire una comunicazione la segna come letta: la cornice si chiede dopo.
  const apertura = useMemo(() => (id ? api.get(`/dipendente/comunicazioni/${id}`) : null), [id]);
  const cornice = useCornice(apertura ?? undefined);
  const [aperta, setAperta] = useState<Aperta | null>(null);
  const [elenco, setElenco] = useState<Voce[] | null>(null);
  useEffect(() => {
    const carica = () => api.get('/dipendente/comunicazioni').then((r) => setElenco(r.data)).catch(() => undefined);
    // Comunicazione non visibile o inesistente: si mostra l'elenco, come il PHP.
    if (apertura) apertura.then((r) => setAperta(r.data)).catch(carica);
    else carica();
  }, [apertura]);
  useInvioModuli({ conferma: { url: () => `/dipendente/comunicazioni/${id}/conferma`, messaggio: 'ack', mantieni: ['id'] } }, !!(cornice && aperta));

  if (!cornice || (!aperta && !elenco)) return null;

  if (aperta) {
    const p = livello(aperta.priority);
    const parole = autore(aperta).split(/\s+/);
    const iniziali = ((parole[0] ?? '').slice(0, 1) + (parole[1] ?? '').slice(0, 1)).toUpperCase();
    return (
      <CoraShell pagina="communications" titolo={aperta.title} cornice={cornice}>
        {q.get('message') === 'ack' && <span data-cora-flash="" data-title="Lettura confermata" data-message="L’HR vedrà che hai letto questa comunicazione." hidden />}
        {/* Sul telefono la barra con logo, campanella e avatar resta in alto (Figma 1198:2718) */}
        <div className="cora-solo-tel cora-reveal" style={{ '--i': 0 } as React.CSSProperties}><Topbar titolo={aperta.title} cornice={cornice} /></div>
        <a className="cora-back cora-reveal" style={{ '--i': 0 } as React.CSSProperties} href="/employee/communications"><I n="ic-arr-l.svg" />Torna alle comunicazioni</a>

        <article className="cora-card cora-comm cora-reveal" style={{ '--i': 1 } as React.CSSProperties}>
          <span className={`cora-prio-pill is-${p}`}><I n={`ic-flag-${p === 'high' ? 'alta-sm' : BANDIERA[p]}.svg`} />{ETICHETTA[p]}</span>
          <h1>{aperta.title}</h1>
          <div className="cora-comm-meta">
            <span className="cora-comm-author"><i>{iniziali}</i>{autore(aperta)}</span>
            <span className="cora-comm-date"><I n="ic-cal-sm.svg" />{dataLunga(aperta.publish_date)}</span>
          </div>
          <hr />
          {/* HTML gia' ripulito dal backend (stesse regole di sanitizeRichHtml). */}
          <div className="cora-comm-body cm-rich" dangerouslySetInnerHTML={{ __html: aperta.content }} />

          {aperta.allegati.map((a) => (
            <a key={a.id} className="cora-attach" href={`/communication_file.php?id=${a.id}`} target="_blank" rel="noopener">
              <span className="cora-attach-ic"><I n="ic-file-orange.svg" /></span>
              <span className="cora-attach-txt">
                <strong>{a.original_name}</strong>
                <span>{ext(a.original_name, a.mime_type)} - {dimensione(a.size_bytes)}</span>
              </span>
              <span className="cora-attach-dl"><I n="ic-down-sm.svg" /></span>
            </a>
          ))}

          {p === 'high' && !aperta.letta && (
            <form method="POST" className="cora-ack" data-invio="conferma">
              <input type="hidden" name="action" value="ack" />
              <span className="cora-ack-txt">
                <strong>Conferma di presa visione</strong>
                <span>L’HR ha chiesto di confermare la lettura di questa comunicazione.</span>
              </span>
              <button type="submit" className="cora-btn cora-btn-primary"><I n="logo-cora-white.svg" />Ho letto</button>
            </form>
          )}
        </article>
      </CoraShell>
    );
  }

  const voci = elenco!;
  const gruppi: Record<string, Voce[]> = { high: [], normal: [], low: [] };
  let nonLette = 0;
  for (const c of voci) {
    gruppi[livello(c.priority)].push(c);
    if (!c.is_read) nonLette++;
  }
  const filtri: Array<[string, string, number]> = [['all', 'Tutte', 0], ['unread', 'Non lette', nonLette], ['high', 'Alta', gruppi.high.length], ['normal', 'Normale', 0], ['low', 'Bassa', 0]];

  return (
    <CoraShell pagina="communications" titolo="Comunicazioni" cornice={cornice}>
      <div className="cora-reveal" style={{ '--i': 0 } as React.CSSProperties}>
        <Topbar titolo="Comunicazioni" sottotitolo="Avvisi e news dall’azienda. Le più importanti sono sempre in cima." cornice={cornice} />
      </div>

      {!voci.length ? (
        <section className="cora-card cora-empty cora-empty-page cora-reveal" style={{ '--i': 1 } as React.CSSProperties}>
          <span className="cora-empty-ic"><I n="ic-empty.svg" /></span>
          <h3>Nessuna comunicazione</h3>
          <p>Sei aggiornato: quando l’azienda pubblicherà un avviso lo vedrai qui.</p>
        </section>
      ) : (
        <section className="cora-card cora-comms cora-reveal" style={{ '--i': 1 } as React.CSSProperties} id="coraComms">
          <div className="cora-filters" data-cora-filter-group="#coraComms" data-tour="comms-filters">
            {filtri.map(([k, l, n]) => (
              <button key={k} type="button" className={'cora-tab' + (k === 'all' ? ' is-active' : '')} data-filter={k}>
                {l}{n > 0 && <span className="cora-tab-count">{n}</span>}
              </button>
            ))}
          </div>

          {Object.entries(gruppi).map(([p, lista]) => lista.length > 0 && (
            <div key={p} className="cora-comm-group" data-cora-section="">
              <h2 className="cora-section-h is-flag"><I n={`ic-flag-${BANDIERA[p]}.svg`} />{ETICHETTA[p]}</h2>
              <div className="cora-comm-grid">
                {lista.map((c) => (
                  <a key={c.id} className="cora-comm-card" href={`/employee/communications?id=${c.id}`}
                    data-cora-filterable="" data-tags={p + (!c.is_read ? ' unread' : '')}>
                    <span className="cora-row-ic"><I n="ic-mega.svg" /></span>
                    <span className="cora-row-txt">
                      <strong>{c.title}</strong>
                      <span>{autore(c)} - {dmy(c.publish_date)}</span>
                    </span>
                    {!c.is_read && <i className="cora-unread-dot" title="Non letta" />}
                    <span className="cora-row-chev"><I n="ic-chev-r.svg" /></span>
                  </a>
                ))}
              </div>
            </div>
          ))}

          <div className="cora-empty" data-cora-filter-empty="" hidden style={{ minHeight: 220 }}>
            <h3>Nessuna comunicazione</h3>
            <p>Nessuna comunicazione corrisponde al filtro scelto.</p>
          </div>
        </section>
      )}
    </CoraShell>
  );
}
