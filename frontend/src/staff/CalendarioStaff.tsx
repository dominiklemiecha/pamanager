import { useEffect, useMemo, useState } from 'react';
import css from '../pages/condivise/calendario.css?inline';
import { DatiCalendario, htmlContatti, htmlGriglia } from '../pages/condivise/calendario-html';
import { htmlModale } from '../pages/condivise/calendario-modale';
import { api } from '../services/api';
import { StaffShell, useCorniceStaff } from './StaffShell';
import { useCorniceHr } from '../cora/HrShell';
import { CorniceClassicaHr, ricolora } from '../pages/hr/Classica';

/**
 * Calendario dello staff (admin/calendar.php e delle altre aree): stesso HTML
 * e stesso script del calendario condiviso (_calendar.inc.php), dentro la
 * cornice dello staff.
 */
export default function CalendarioStaff() {
  const q = useMemo(() => new URLSearchParams(location.search), []);
  const dati = useMemo(() => api.get('/calendario', { params: { view: q.get('view') ?? undefined, d: q.get('d') ?? undefined } }), [q]);
  const cornice = useCorniceStaff(dati);
  const hrArea = location.pathname.startsWith('/admin/');
  const corniceHr = useCorniceHr(hrArea);
  const [d, setD] = useState<DatiCalendario | null>(null);
  useEffect(() => {
    dati.then((r) => setD(r.data)).catch((e) => { if (e?.response?.status === 403) location.replace('/auth/login'); });
  }, [dati]);
  const html = useMemo(() => (d ? htmlGriglia(d, false) + '\n\n<!-- Modal Add/Detail Event -->\n' + htmlModale(htmlContatti(d)) : ''), [d]);
  if (!cornice || !d) return null;
  const globali = { CORA_CAL: d.chiamante };
  if (hrArea && cornice.ruolo === 'admin') {
    if (!corniceHr) return null;
    // HR con la vista Cora: stesso calendario dentro la cornice Cora.
    if (corniceHr.coraHr) {
      return (
        <CorniceClassicaHr file="calendar" titolo="Calendario" cornice={cornice} corniceHr={corniceHr} stile={ricolora(css)} script={['/assets/js/cora-calendario.js']} globali={globali}>
          <div style={{ display: 'contents' }} dangerouslySetInnerHTML={{ __html: ricolora(html) }} />
        </CorniceClassicaHr>
      );
    }
  }
  return (
    <StaffShell pagina="calendar" titolo="Calendario" cornice={cornice} stile={css} script={['/assets/js/cora-calendario.js']} globali={globali}>
      <div style={{ display: 'contents' }} dangerouslySetInnerHTML={{ __html: html }} />
    </StaffShell>
  );
}
