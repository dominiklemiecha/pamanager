import { useEffect, useMemo, useState } from 'react';
import css from '../pages/condivise/calendario.css?inline';
import { DatiCalendario, htmlContatti, htmlGriglia } from '../pages/condivise/calendario-html';
import { htmlModale } from '../pages/condivise/calendario-modale';
import { api } from '../services/api';
import { StaffShell, useCorniceStaff } from './StaffShell';

/**
 * Calendario dello staff (admin/calendar.php e delle altre aree): stesso HTML
 * e stesso script del calendario condiviso (_calendar.inc.php), dentro la
 * cornice dello staff.
 */
export default function CalendarioStaff() {
  const q = useMemo(() => new URLSearchParams(location.search), []);
  const dati = useMemo(() => api.get('/calendario', { params: { view: q.get('view') ?? undefined, d: q.get('d') ?? undefined } }), [q]);
  const cornice = useCorniceStaff(dati);
  const [d, setD] = useState<DatiCalendario | null>(null);
  useEffect(() => {
    dati.then((r) => setD(r.data)).catch((e) => { if (e?.response?.status === 403) location.replace('/auth/login.php'); });
  }, [dati]);
  const html = useMemo(() => (d ? htmlGriglia(d, false) + '\n\n<!-- Modal Add/Detail Event -->\n' + htmlModale(htmlContatti(d)) : ''), [d]);
  if (!cornice || !d) return null;
  return (
    <StaffShell pagina="calendar" titolo="Calendario" cornice={cornice} stile={css} script={['/assets/js/cora-calendario.js']} globali={{ CORA_CAL: d.chiamante }}>
      <div style={{ display: 'contents' }} dangerouslySetInnerHTML={{ __html: html }} />
    </StaffShell>
  );
}
