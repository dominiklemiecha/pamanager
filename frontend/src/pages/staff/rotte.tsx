import { Route } from 'react-router-dom';
import { P } from '../../php/runtime';
import adminBuoniPasto from '../../php/pagine/admin/buoni-pasto';
import adminPortaLog from '../../php/pagine/admin/door-log';
import adminPorta from '../../php/pagine/admin/door-settings';
import adminComunicazioni from '../../php/pagine/admin/communications';
import adminDocumentiAziendali from '../../php/pagine/admin/documenti-aziendali';
import adminVisibilita from '../../php/pagine/admin/field-visibility';
import adminPresenzeExport from '../../php/pagine/admin/presenze-export';
import adminTimbratureGiorno from '../../php/pagine/admin/attendance';
import adminUtentiEsterni from '../../php/pagine/admin/user-role-manager';
import adminDipendenti from '../../php/pagine/admin/employees';
import adminAssunzioni from '../../php/pagine/admin/hire-requests';
import adminAziende from '../../php/pagine/admin/companies';
import adminRepartoDettaglio from '../../php/pagine/admin/department-detail';
import adminReparti from '../../php/pagine/admin/departments';
import adminIndex from '../../php/pagine/admin/index';
import adminFerie from '../../php/pagine/admin/leave-requests';
import adminPassword from '../../php/pagine/admin/password-resets';
import adminProfilo from '../../php/pagine/admin/profile';
import adminTimbrature from '../../php/pagine/admin/punch-settings';
import adminOrario from '../../php/pagine/admin/work-schedule';
import staffChat from '../../php/pagine/staff/chat';
import CalendarioStaff from '../../staff/CalendarioStaff';
import { PaginaStaff } from '../../staff/PaginaStaff';

/** Date della settimana del widget presenze come DateTime (il template usa ->format). */
const conSettimana = (V: any) => {
  if (V.weekStart) V.weekStart = P.nuovo('DateTime', [V.weekStart]);
  if (V.weekEnd) V.weekEnd = P.nuovo('DateTime', [V.weekEnd]);
};

/** Pagina che il PHP serviva solo con un redirect: lo chiede al backend. */
const soloRedirect = () => undefined;

/** Utenti esterni: "condiviso" se appartiene a un'azienda che l'HR non gestisce ($isGuestUser del PHP). */
const conOspiti = (V: any) => {
  const gestite = new Set((V.accessibleCids ?? []).map(Number));
  V.isGuestUser = (u: any) => { const cid = Number(P.g(u, 'company_id') ?? 0); return cid > 0 && !gestite.has(cid); };
};

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
  pagina('/admin/companies.php', adminAziende),
  pagina('/admin/communications.php', adminComunicazioni),
  pagina('/admin/field-visibility.php', adminVisibilita),
  pagina('/admin/employees.php', adminDipendenti),
  pagina('/admin/hire-requests.php', adminAssunzioni),
  pagina('/admin/attendance.php', adminTimbratureGiorno),
  pagina('/admin/accountant.php', adminUtentiEsterni, { prepara: conOspiti }),
  pagina('/admin/consulente-lavoro.php', adminUtentiEsterni, { prepara: conOspiti }),
  pagina('/admin/formatori.php', adminUtentiEsterni, { prepara: conOspiti }),
  ...['admin', 'accountant', 'consulente-lavoro'].map((a) => pagina(`/${a}/chat.php`, staffChat, { scarica: ['download_attachment', 'group_avatar'] })),
  ...['admin', 'accountant', 'consulente-lavoro'].map((a) => <Route key={`${a}/calendar`} path={`/${a}/calendar.php`} element={<CalendarioStaff />} />),
  pagina('/admin/presenze-export.php', adminPresenzeExport, { scarica: ['action'], prepara: (V) => { V.now = P.nuovo('DateTime', [V.now]); } }),
  pagina('/admin/documenti-aziendali.php', adminDocumentiAziendali, { scarica: ['download'] }),
];
