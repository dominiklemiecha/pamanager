import { Route } from 'react-router-dom';
import { PaginaSuperadmin } from '../../superadmin/PaginaSuperadmin';
import { IndiceHr } from '../hr/Indice';
import { SceltaHr } from '../hr/comune';
import { AnagraficaHr, AssunzioniHr, PresenzeHr, RepartiHr } from '../hr/Dipendenti';
import { FerieHr } from '../hr/Ferie';
import { ComunicazioniHr, DocumentiHr } from '../hr/Archivio';
import type { CorniceHr } from '../../cora/HrShell';
import { P } from '../../php/runtime';
import adminBuoniPasto from '../../php/pagine/admin/buoni-pasto';
import adminPortaLog from '../../php/pagine/admin/door-log';
import adminPorta from '../../php/pagine/admin/door-settings';
import adminComunicazioni from '../../php/pagine/admin/communications';
import adminDocumentiAziendali from '../../php/pagine/admin/documenti-aziendali';
import adminPresenzeExport from '../../php/pagine/admin/presenze-export';
import adminTimbratureGiorno from '../../php/pagine/admin/attendance';
import adminUtentiEsterni from '../../php/pagine/admin/user-role-manager';
import adminDipendenti from '../../php/pagine/admin/employees';
import adminAssunzioni from '../../php/pagine/admin/hire-requests';
import staffAcademy from '../../php/pagine/staff/academy';
import esterniIndexConsulente from '../../php/pagine/esterni/index-consulente';
import esterniDipendenti from '../../php/pagine/esterni/employees';
import esterniDocumentiDipendente from '../../php/pagine/esterni/employee-documents';
import esterniDocumenti from '../../php/pagine/esterni/documents';
import esterniFerieConsulente from '../../php/pagine/esterni/leave-requests-consulente';
import esterniPresenze from '../../php/pagine/esterni/presenze-export';
import esterniProfiloFormatore from '../../php/pagine/esterni/profile-accountant';
import esterniProfiloConsulente from '../../php/pagine/esterni/profile-consulente';
import esterniAssunzioni from '../../php/pagine/esterni/hire-requests';
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


const pagina = (percorso: string, render: (V: any, F: any) => void, opz: { prepara?: (V: any) => void; scarica?: string[] } = {}) => (
  <Route key={percorso} path={percorso} element={<PaginaStaff render={render} {...opz} />} />
);

/** Pagina dell'HR con la vista Cora (se attiva) e la pagina classica per il resto. */
const hr = (percorso: string, Cora: (p: { cornice: CorniceHr }) => React.ReactNode, classica: React.ComponentProps<typeof PaginaStaff>,
  usaCora?: (q: URLSearchParams) => boolean) => (
  <Route key={percorso} path={percorso} element={<SceltaHr cora={(c) => <Cora cornice={c} />} classica={classica} usaCora={usaCora} />} />
);

/** Pagine dell'area staff (stessi indirizzi del PHP). */
export const rotteStaff = [
  ...['/admin', '/admin/', '/admin/index.php'].map((x) => (
    <Route key={x} path={x} element={<IndiceHr classica={{ render: adminIndex, prepara: conSettimana }} />} />
  )),
  hr('/admin/leave-requests.php', FerieHr, { render: adminFerie, scarica: ['download_attachment', 'download_cert'] }, (q) => !q.has('classica')),
  pagina('/admin/work-schedule.php', adminOrario),
  pagina('/admin/punch-settings.php', adminTimbrature),
  pagina('/admin/buoni-pasto.php', adminBuoniPasto),
  pagina('/admin/door-settings.php', adminPorta),
  pagina('/admin/door-log.php', adminPortaLog),
  pagina('/admin/smtp-settings.php', soloRedirect),
  pagina('/admin/profile.php', adminProfilo),
  pagina('/admin/password-resets.php', adminPassword),
  hr('/admin/departments.php', RepartiHr, { render: adminReparti }, (q) => !q.has('edit') && q.get('action') !== 'new'),
  pagina('/admin/department-detail.php', adminRepartoDettaglio),
  pagina('/admin/companies.php', adminAziende),
  hr('/admin/communications.php', ComunicazioniHr, { render: adminComunicazioni }, (q) => !q.has('action')),
  hr('/admin/employees.php', AnagraficaHr, { render: adminDipendenti }, (q) => !q.has('action') && !q.has('id')),
  hr('/admin/hire-requests.php', AssunzioniHr, { render: adminAssunzioni }, (q) => !q.has('action') && !q.has('id')),
  hr('/admin/attendance.php', PresenzeHr, { render: adminTimbratureGiorno }, (q) => !q.has('classica')),
  pagina('/admin/consulente-lavoro.php', adminUtentiEsterni, { prepara: conOspiti }),
  pagina('/admin/formatori.php', adminUtentiEsterni, { prepara: conOspiti }),
  ...['admin', 'consulente-lavoro'].map((a) => pagina(`/${a}/chat.php`, staffChat, { scarica: ['download_attachment', 'group_avatar'] })),
  ...['admin', 'consulente-lavoro'].map((a) => <Route key={`${a}/calendar`} path={`/${a}/calendar.php`} element={<CalendarioStaff />} />),
  pagina('/admin/presenze-export.php', adminPresenzeExport, { scarica: ['action'], prepara: (V) => { V.now = P.nuovo('DateTime', [V.now]); } }),
  hr('/admin/documenti-aziendali.php', DocumentiHr, { render: adminDocumentiAziendali, scarica: ['download'] }, (q) => !q.has('classica')),
  ...['admin', 'consulente-lavoro', 'formatore'].map((a) => pagina(`/${a}/academy.php`, staffAcademy, { scarica: ['export', 'zip'] })),
  ...['admin', 'consulente-lavoro', 'formatore'].map((a) => pagina(`/${a}/academy-templates.php`, staffModelliAttestato)),
  ...['/consulente-lavoro', '/consulente-lavoro/', '/consulente-lavoro/index.php'].map((x) => pagina(x, esterniIndexConsulente)),
  ...['/formatore', '/formatore/', '/formatore/index.php'].map((x) => pagina(x, soloRedirect)),
  ...['consulente-lavoro'].map((a) => pagina(`/${a}/employees.php`, esterniDipendenti)),
  ...['consulente-lavoro'].map((a) => pagina(`/${a}/employee-documents.php`, esterniDocumentiDipendente, { scarica: ['download'] })),
  ...['admin', 'consulente-lavoro'].map((a) => pagina(`/${a}/documents.php`, esterniDocumenti)),
  pagina('/consulente-lavoro/leave-requests.php', esterniFerieConsulente, { scarica: ['download_cert'] }),
  ...['consulente-lavoro'].map((a) => pagina(`/${a}/presenze-export.php`, esterniPresenze, { scarica: ['action'], prepara: (V) => { V.now = P.nuovo('DateTime', [V.now]); } })),
  pagina('/formatore/profile.php', esterniProfiloFormatore),
  pagina('/consulente-lavoro/profile.php', esterniProfiloConsulente),
  pagina('/consulente-lavoro/hire-requests.php', esterniAssunzioni, { scarica: ['action'] }),
  ...['/superadmin', '/superadmin/', '/superadmin/index.php'].map((x) => <Route key={x} path={x} element={<PaginaSuperadmin quale="index" />} />),
  <Route key="sa-login" path="/superadmin/login.php" element={<PaginaSuperadmin quale="login" />} />,
  <Route key="sa-totp" path="/superadmin/totp.php" element={<PaginaSuperadmin quale="totp" />} />,
  <Route key="sa-clic" path="/superadmin/clic.php" element={<PaginaSuperadmin quale="clic" />} />,
  <Route key="sa-logout" path="/superadmin/logout.php" element={<PaginaSuperadmin quale="logout" />} />,
];
