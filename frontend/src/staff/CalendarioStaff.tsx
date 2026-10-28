import { useEffect, useMemo, useState } from 'react';
import css from '../pages/condivise/calendario.css?inline';
import { DatiCalendario, htmlContatti, htmlGriglia } from '../pages/condivise/calendario-html';
import { htmlModale } from '../pages/condivise/calendario-modale';
import { useLocation } from 'react-router-dom';
import { api } from '../services/api';
import { prendiPrecaricato } from '../cora/datiHr';
import { StaffShell, useCorniceStaff } from './StaffShell';
import { useCorniceHr } from '../cora/HrShell';
import { CorniceClassicaHr, ricolora } from '../pages/hr/Classica';

/**
 * Calendario dello staff (admin/calendar.php e delle altre aree): stesso HTML
 * e stesso script del calendario condiviso (_calendar.inc.php), dentro la
 * cornice dello staff.
 */
export default function CalendarioStaff() {
  // Vista o giorno diversi (parametri dell'indirizzo): pagina nuova, come nel PHP.
  const l = useLocation();
  return <Calendario key={l.pathname + l.search} />;
}

function Calendario() {
  const q = useMemo(() => new URLSearchParams(location.search), []);
  const dati = useMemo(() => {
    const pronti = location.search ? null : prendiPrecaricato('/calendario');
    return pronti ? pronti.then((data) => ({ data })) : api.get('/calendario', { params: { view: q.get('view') ?? undefined, d: q.get('d') ?? undefined } });
  }, [q]);
  const cornice = useCorniceStaff(dati);
  const hrArea = /^\/(admin|consulente-lavoro)\//.test(location.pathname);
  const corniceHr = useCorniceHr(hrArea);
  const [d, setD] = useState<DatiCalendario | null>(null);
  useEffect(() => {
    dati.then((r) => setD(r.data)).catch((e) => { if (e?.response?.status === 403) location.replace('/auth/login'); });
  }, [dati]);
  const html = useMemo(() => (d ? htmlGriglia(d, false) + '\n\n<!-- Modal Add/Detail Event -->\n' + htmlModale(htmlContatti(d)) : ''), [d]);
  if (!cornice || !d) return null;
  const globali = { CORA_CAL: d.chiamante };
  if (hrArea && ['admin', 'consulente_lavoro'].includes(cornice.ruolo)) {
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
