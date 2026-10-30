import { useEffect, useMemo, useState } from 'react';
import { CoraShell, Topbar, useCornice } from '../../cora/CoraShell';
import { dataBreve, dimensione } from '../../cora/formato';
import { svgCora } from '../../cora/svg';
import { api } from '../../services/api';

interface Busta { id: number; type: string; title: string; year: number; month: number; file_name: string; original_name: string | null; file_size: number; mime_type: string | null; created_at: string; is_downloaded: boolean }
interface Personale { id: number; name: string; file_name: string; original_name: string | null; file_size: number; mime_type: string | null; created_at: string; expires_on: string | null; bulk_group: string | null; visto: boolean }
interface Dati { buste: Busta[]; personali: Personale[]; anni: number[] }
interface Voce { title: string; meta: string; cat: string; tag: string; nuovo: boolean; data: string; anno: number; url: string }

const I = ({ n }: { n: string }) => <span style={{ display: 'contents' }} dangerouslySetInnerHTML={{ __html: svgCora(n) }} />;
const ERRORI: Record<string, string> = {
  non_trovato: 'Documento non trovato',
  non_autorizzato: 'Accesso non autorizzato',
  limite: 'Limite download raggiunto. Riprova tra 60 minuti.',
  file_mancante: 'File non trovato sul server',
};
const FILTRI: Array<[string, string]> = [['all', 'Tutti'], ['unread', 'Da leggere'], ['payslip', 'Buste paga'], ['cu', 'CU'], ['contract', 'Contratti'], ['company', 'Aziendali']];

/** Estensione in maiuscolo dal nome del file o dal mime (CoraUi::ext). */
function ext(nome: string, mime: string | null): string {
  const base = nome.split('/').pop() ?? '';
  const i = base.lastIndexOf('.');
  const e = i >= 0 ? base.slice(i + 1).toUpperCase() : '';
  if (e !== '') return e;
  return mime === 'application/pdf' ? 'PDF' : 'FILE';
}
const annoRoma = (dt: string) => Number(new Intl.DateTimeFormat('en-CA', { timeZone: 'Europe/Rome', year: 'numeric' }).format(new Date(dt)));
const dmy = (d: string) => `${d.slice(8, 10)}/${d.slice(5, 7)}/${d.slice(0, 4)}`;

/** Documenti del dipendente (employee/documents.php, interfaccia Cora). */
export default function Documenti() {
  const cornice = useCornice();
  const [d, setD] = useState<Dati | null>(null);
  const q = useMemo(() => new URLSearchParams(location.search), []);
  const annoFiltro = Number.parseInt(q.get('year') ?? '', 10) || null;
  useEffect(() => {
    // Vecchi link del PHP (?download=, ?download_personal=): vanno al download.
    const vecchio = q.get('download') ?? q.get('download_personal');
    if (vecchio && /^\d+$/.test(vecchio)) {
      location.replace(q.has('download') ? `/api/v1/dipendente/documenti/${vecchio}/scarica` : `/api/v1/dipendente/documenti/personali/${vecchio}/scarica`);
      return;
    }
    const tipo = q.get('type');
    api.get('/dipendente/documenti', { params: tipo ? { type: tipo } : {} }).then((r) => setD(r.data)).catch(() => undefined);
  }, [q]);

  if (!cornice || !d) return null;

  let voci: Voce[] = [];
  for (const x of d.buste) {
    const contratto = x.type === 'other' && /contratt/i.test(x.title);
    const [cat, tag] = x.type === 'payslip' ? ['Busta paga', 'payslip'] : x.type === 'cud' ? ['CU', 'cu'] : contratto ? ['Contratto', 'contract'] : ['Documento', 'other'];
    voci.push({
      title: x.title,
      meta: `${ext(x.original_name ?? x.file_name ?? '', x.mime_type)} - ${dimensione(x.file_size)} - ${dataBreve(x.created_at)}`,
      cat, tag, nuovo: !x.is_downloaded, data: x.created_at, anno: x.year ?? annoRoma(x.created_at),
      url: `/api/v1/dipendente/documenti/${x.id}/scarica`,
    });
  }
  for (const x of d.personali) {
    const aziendale = !!x.bulk_group;
    const contratto = !aziendale && /contratt/i.test(x.name);
    const [cat, tag] = aziendale ? ['Aziendale', 'company'] : contratto ? ['Contratto', 'contract'] : ['Personale', 'personal'];
    let meta = `${ext(x.original_name ?? x.file_name ?? '', x.mime_type)} - ${dimensione(x.file_size)} - ${dataBreve(x.created_at)}`;
    if (x.expires_on) meta += ` - scade il ${dmy(x.expires_on)}`;
    voci.push({
      title: x.name, meta, cat, tag, nuovo: !x.visto, data: x.created_at, anno: annoRoma(x.created_at),
      url: `/api/v1/dipendente/documenti/personali/${x.id}/scarica`,
    });
  }

  // Menu "Tutti gli anni": anni delle voci e delle buste paga, dal piu' recente.
  const anni = [...new Set([...voci.map((v) => v.anno), ...d.anni.map(Number)])].sort((a, b) => b - a);
  if (annoFiltro) voci = voci.filter((v) => v.anno === annoFiltro);
  voci.sort((a, b) => new Date(b.data).getTime() - new Date(a.data).getTime());
  const daLeggere = voci.filter((v) => v.nuovo);
  const archivio = voci.filter((v) => !v.nuovo);
  const errore = ERRORI[q.get('errore') ?? ''] ?? '';

  const selettore = anni.length ? (
    <form method="GET" className="cora-select">
      <select name="year" defaultValue={annoFiltro ? String(annoFiltro) : ''} onChange={(e) => e.currentTarget.form?.submit()} aria-label="Anno">
        <option value="">Tutti gli anni</option>
        {anni.map((a) => <option key={a} value={a}>{a}</option>)}
      </select>
      <I n="ic-chev-d.svg" />
    </form>
  ) : null;

  const scheda = (v: Voce, i: number) => (
    <a key={i} className="cora-doc" href={v.url} data-cora-filterable="" data-tags={v.tag + (v.nuovo ? ' unread' : '')} data-search={(v.title + ' ' + v.cat).toLowerCase()}>
      <span className="cora-doc-top">
        <span className="cora-doc-ic"><I n="ic-file.svg" /></span>
        {v.nuovo && <span className="cora-doc-new">Nuovo</span>}
      </span>
      <span className="cora-doc-txt">
        <strong>{v.title}</strong>
        <span>{v.meta}</span>
      </span>
      <span className="cora-doc-bottom">
        <span className="cora-doc-cat">{v.cat}</span>
        <span className="cora-doc-dl" title="Scarica"><I n="ic-down.svg" /></span>
      </span>
    </a>
  );

  return (
    <CoraShell pagina="documents" titolo="Documenti" cornice={cornice}>
      <div className="cora-reveal" style={{ '--i': 0 } as React.CSSProperties}>
        <Topbar titolo="I tuoi documenti" sottotitolo="Buste paga, CU, contratto e documenti aziendali: sempre disponibili da scaricare." cornice={cornice}
          azioni={voci.length || annoFiltro ? selettore : undefined} />
      </div>

      {errore && <div className="cora-alert is-error">{errore}</div>}

      {!voci.length ? (
        <section className="cora-card cora-empty cora-empty-page cora-reveal" style={{ '--i': 1 } as React.CSSProperties}>
          <span className="cora-empty-ic"><I n="ic-empty.svg" /></span>
          <h3>Nessun documento</h3>
          <p>{annoFiltro ? 'Non ci sono documenti per questo anno.' : 'Quando l’azienda caricherà buste paga o documenti per te, li troverai qui.'}</p>
        </section>
      ) : (
        <section className="cora-card cora-docs cora-reveal" style={{ '--i': 1 } as React.CSSProperties} id="coraDocs">
          <div className="cora-filters" data-cora-filter-group="#coraDocs" data-tour="docs-filters">
            {FILTRI.map(([k, l]) => (
              <button key={k} type="button" className={'cora-tab' + (k === 'all' ? ' is-active' : '')} data-filter={k}>
                {l}
                {k === 'unread' && daLeggere.length > 0 && <span className="cora-tab-count">{daLeggere.length}</span>}
              </button>
            ))}
            <span className="cora-filters-spacer" />
            <label className="cora-search">
              <I n="ic-search.svg" />
              <input type="search" placeholder="Cerca un documento" data-cora-search="" aria-label="Cerca un documento" />
            </label>
            {/* Sul telefono l'anno sta accanto alla ricerca (Figma 1198:1798) */}
            {selettore && <div className="cora-docs-anno">{selettore}</div>}
          </div>

          {([['Da leggere', daLeggere], ['Archivio', archivio]] as const).map(([titolo, elenco]) => elenco.length > 0 && (
            <div key={titolo} className="cora-doc-section" data-cora-section="">
              <h2 className="cora-section-h">{titolo} <span data-cora-section-count="">{elenco.length}</span></h2>
              <div className="cora-doc-grid">{elenco.map(scheda)}</div>
            </div>
          ))}

          <div className="cora-empty" data-cora-filter-empty="" hidden style={{ minHeight: 260 }}>
            <span className="cora-empty-ic"><I n="ic-empty.svg" /></span>
            <h3>Nessun documento</h3>
            <p>Nessun documento corrisponde al filtro scelto.</p>
          </div>
        </section>
      )}
    </CoraShell>
  );
}
