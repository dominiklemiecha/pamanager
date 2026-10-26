import { Route } from 'react-router-dom';
import { PaginaSuperadmin } from '../../superadmin/PaginaSuperadmin';
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
import staffAcademy from '../../php/pagine/staff/academy';
import repartoIndex from '../../php/pagine/reparto/index';
import esterniIndexCommercialista from '../../php/pagine/esterni/index-accountant';
import esterniIndexConsulente from '../../php/pagine/esterni/index-consulente';
import esterniDipendenti from '../../php/pagine/esterni/employees';
import esterniDocumentiDipendente from '../../php/pagine/esterni/employee-documents';
import esterniDocumenti from '../../php/pagine/esterni/documents';
import esterniFerieCommercialista from '../../php/pagine/esterni/leave-requests-accountant';
import esterniFerieConsulente from '../../php/pagine/esterni/leave-requests-consulente';
import esterniPresenze from '../../php/pagine/esterni/presenze-export';
import esterniProfiloCommercialista from '../../php/pagine/esterni/profile-accountant';
import esterniProfiloConsulente from '../../php/pagine/esterni/profile-consulente';
import esterniAssunzioni from '../../php/pagine/esterni/hire-requests';
import repartoDipendenti from '../../php/pagine/reparto/employees';
import repartoFerie from '../../php/pagine/reparto/leave-requests';
import repartoComunicazioni from '../../php/pagine/reparto/communications';
import repartoPassword from '../../php/pagine/reparto/password-resets';
import repartoDocumenti from '../../php/pagine/reparto/employee-documents';
import staffModelliAttestato from '../../php/pagine/staff/academy-templates';
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

/** Responsabile senza reparto: il PHP mostrava solo questo avviso. */
const conReparto = (render: (V: any, F: any) => void) => (V: any, F: any) => {
  if (V.__senzaReparto) {
    P.o(`<div style="padding: 2rem; text-align: center;"><h2>Nessun reparto assegnato</h2><p>Contatta l'amministratore per essere assegnato a un reparto.</p></div>`);
    return;
  }
  render(V, F);
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
  ...['admin', 'consulente-lavoro', 'formatore'].map((a) => pagina(`/${a}/academy.php`, staffAcademy, { scarica: ['export', 'zip'] })),
  ...['admin', 'consulente-lavoro', 'formatore'].map((a) => pagina(`/${a}/academy-templates.php`, staffModelliAttestato)),
  pagina('/admin-reparto', conReparto(repartoIndex), { prepara: conSettimana }),
  pagina('/admin-reparto/', conReparto(repartoIndex), { prepara: conSettimana }),
  pagina('/admin-reparto/index.php', conReparto(repartoIndex), { prepara: conSettimana }),
  pagina('/admin-reparto/employees.php', conReparto(repartoDipendenti)),
  pagina('/admin-reparto/leave-requests.php', conReparto(repartoFerie), { scarica: ['download_attachment', 'download_cert'] }),
  pagina('/admin-reparto/communications.php', conReparto(repartoComunicazioni)),
  pagina('/admin-reparto/password-resets.php', conReparto(repartoPassword)),
  pagina('/admin-reparto/employee-documents.php', repartoDocumenti, { scarica: ['download'] }),
  pagina('/admin-reparto/academy.php', staffAcademy, { scarica: ['export', 'zip'] }),
  pagina('/admin-reparto/chat.php', staffChat, { scarica: ['download_attachment', 'group_avatar'] }),
  <Route key="admin-reparto/calendar" path="/admin-reparto/calendar.php" element={<CalendarioStaff />} />,
  ...['/accountant', '/accountant/', '/accountant/index.php'].map((x) => pagina(x, esterniIndexCommercialista)),
  ...['/consulente-lavoro', '/consulente-lavoro/', '/consulente-lavoro/index.php'].map((x) => pagina(x, esterniIndexConsulente)),
  ...['/formatore', '/formatore/', '/formatore/index.php'].map((x) => pagina(x, soloRedirect)),
  ...['accountant', 'consulente-lavoro'].map((a) => pagina(`/${a}/employees.php`, esterniDipendenti)),
  ...['accountant', 'consulente-lavoro'].map((a) => pagina(`/${a}/employee-documents.php`, esterniDocumentiDipendente, { scarica: ['download'] })),
  ...['accountant', 'consulente-lavoro'].map((a) => pagina(`/${a}/documents.php`, esterniDocumenti)),
  pagina('/accountant/leave-requests.php', esterniFerieCommercialista, { scarica: ['download_cert'] }),
  pagina('/consulente-lavoro/leave-requests.php', esterniFerieConsulente, { scarica: ['download_cert'] }),
  ...['accountant', 'consulente-lavoro'].map((a) => pagina(`/${a}/presenze-export.php`, esterniPresenze, { scarica: ['action'], prepara: (V) => { V.now = P.nuovo('DateTime', [V.now]); } })),
  pagina('/accountant/profile.php', esterniProfiloCommercialista),
  pagina('/formatore/profile.php', esterniProfiloCommercialista),
  pagina('/consulente-lavoro/profile.php', esterniProfiloConsulente),
  pagina('/consulente-lavoro/hire-requests.php', esterniAssunzioni, { scarica: ['action'] }),
  ...['/superadmin', '/superadmin/', '/superadmin/index.php'].map((x) => <Route key={x} path={x} element={<PaginaSuperadmin quale="index" />} />),
  <Route key="sa-login" path="/superadmin/login.php" element={<PaginaSuperadmin quale="login" />} />,
  <Route key="sa-totp" path="/superadmin/totp.php" element={<PaginaSuperadmin quale="totp" />} />,
  <Route key="sa-clic" path="/superadmin/clic.php" element={<PaginaSuperadmin quale="clic" />} />,
  <Route key="sa-logout" path="/superadmin/logout.php" element={<PaginaSuperadmin quale="logout" />} />,
];
