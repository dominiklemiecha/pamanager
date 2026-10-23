import { Route } from 'react-router-dom';
import { P } from '../../php/runtime';
import adminBuoniPasto from '../../php/pagine/admin/buoni-pasto';
import adminPortaLog from '../../php/pagine/admin/door-log';
import adminPorta from '../../php/pagine/admin/door-settings';
import adminRepartoDettaglio from '../../php/pagine/admin/department-detail';
import adminReparti from '../../php/pagine/admin/departments';
import adminIndex from '../../php/pagine/admin/index';
import adminFerie from '../../php/pagine/admin/leave-requests';
import adminPassword from '../../php/pagine/admin/password-resets';
import adminProfilo from '../../php/pagine/admin/profile';
import adminTimbrature from '../../php/pagine/admin/punch-settings';
import adminOrario from '../../php/pagine/admin/work-schedule';
import { PaginaStaff } from '../../staff/PaginaStaff';

/** Date della settimana del widget presenze come DateTime (il template usa ->format). */
const conSettimana = (V: any) => {
  if (V.weekStart) V.weekStart = P.nuovo('DateTime', [V.weekStart]);
  if (V.weekEnd) V.weekEnd = P.nuovo('DateTime', [V.weekEnd]);
};

/** Pagina che il PHP serviva solo con un redirect: lo chiede al backend. */
const soloRedirect = () => undefined;

const pagina = (percorso: string, render: (V: any, F: any) => void, opz: { prepara?: (V: any) => void; scarica?: string[] } = {}) => (
  <Route key={percorso} path={percorso} element={<PaginaStaff render={render} {...opz} />} />
);

/** Pagine dell'area staff (stessi indirizzi del PHP). */
export const rotteStaff = [
  pagina('/admin', adminIndex, { prepara: conSettimana }),
  pagina('/admin/', adminIndex, { prepara: conSettimana }),
  pagina('/admin/index.php', adminIndex, { prepara: conSettimana }),
  pagina('/admin/leave-requests.php', adminFerie, { scarica: ['download_attachment', 'download_cert'] }),
  pagina('/admin/work-schedule.php', adminOrario),
  pagina('/admin/punch-settings.php', adminTimbrature),
  pagina('/admin/buoni-pasto.php', adminBuoniPasto),
  pagina('/admin/door-settings.php', adminPorta),
  pagina('/admin/door-log.php', adminPortaLog),
  pagina('/admin/smtp-settings.php', soloRedirect),
  pagina('/admin/profile.php', adminProfilo),
  pagina('/admin/password-resets.php', adminPassword),
  pagina('/admin/departments.php', adminReparti),
  pagina('/admin/department-detail.php', adminRepartoDettaglio),
];
