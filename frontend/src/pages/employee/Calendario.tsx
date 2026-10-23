import { useEffect, useMemo, useState } from 'react';
import { useStile } from '../../componenti/stile';
import { CoraShell, Topbar, useCornice } from '../../cora/CoraShell';
import { api } from '../../services/api';
import css from '../condivise/calendario.css?inline';
import { DatiCalendario, htmlContatti, htmlGriglia } from '../condivise/calendario-html';
import { htmlModale } from '../condivise/calendario-modale';

/**
 * Calendario del dipendente (employee/calendar.php, vista Cora): stesso HTML e
 * stesso script del calendario condiviso, dati dal nuovo backend.
 */
export default function Calendario() {
  useStile(css);
  const cornice = useCornice();
  const q = useMemo(() => new URLSearchParams(location.search), []);
  const [d, setD] = useState<DatiCalendario | null>(null);
  useEffect(() => {
    api.get('/calendario', { params: { view: q.get('view') ?? undefined, d: q.get('d') ?? undefined } }).then((r) => setD(r.data)).catch(() => undefined);
  }, [q]);
  const html = useMemo(() => (d ? htmlGriglia(d, true) + '\n\n<!-- Modal Add/Detail Event -->\n' + htmlModale(htmlContatti(d)) : ''), [d]);

  if (!cornice || !d) return null;
  return (
    <CoraShell pagina="calendar" titolo="Calendario" cornice={cornice} css={['cora-calendar.css']}
      script={['/assets/js/cora-calendario.js']} globali={{ CORA_CAL: d.chiamante }}>
      <Topbar titolo="Calendario" sottotitolo="I tuoi impegni e gli inviti dei colleghi" cornice={cornice} />
      <div style={{ display: 'contents' }} dangerouslySetInnerHTML={{ __html: html }} />
    </CoraShell>
  );
}
