import { Route } from 'react-router-dom';
import { PaginaSuperadmin } from '../../superadmin/PaginaSuperadmin';
import { IndiceHr } from '../hr/Indice';
import { SceltaHr } from '../hr/comune';
import { AnagraficaHr, AssunzioniHr, PresenzeHr, RepartiHr } from '../hr/Dipendenti';
import { FerieHr } from '../hr/Ferie';
import { AccessiHr } from '../hr/Accessi';
import { BuoniPastoHr, OrarioHr, ProfiloHr, ResetPasswordHr, TimbratureImpostazioniHr } from '../hr/Profilo';
import { ComunicazioniHr, DocumentiHr } from '../hr/Archivio';
import { ChatHr } from '../hr/Chat';
import type { CorniceHr } from '../../cora/HrShell';
import { P } from '../../php/runtime';
const adminBuoniPasto: Template = { carica: () => import('../../php/pagine/admin/buoni-pasto') };
const adminPortaLog: Template = { carica: () => import('../../php/pagine/admin/door-log') };
const adminPorta: Template = { carica: () => import('../../php/pagine/admin/door-settings') };
const adminComunicazioni: Template = { carica: () => import('../../php/pagine/admin/communications') };
const adminDocumentiAziendali: Template = { carica: () => import('../../php/pagine/admin/documenti-aziendali') };
const adminPresenzeExport: Template = { carica: () => import('../../php/pagine/admin/presenze-export') };
const adminTimbratureGiorno: Template = { carica: () => import('../../php/pagine/admin/attendance') };
const adminUtentiEsterni: Template = { carica: () => import('../../php/pagine/admin/user-role-manager') };
const adminDipendenti: Template = { carica: () => import('../../php/pagine/admin/employees') };
const adminAssunzioni: Template = { carica: () => import('../../php/pagine/admin/hire-requests') };
const staffAcademy: Template = { carica: () => import('../../php/pagine/staff/academy') };
const esterniIndexConsulente: Template = { carica: () => import('../../php/pagine/esterni/index-consulente') };
const esterniDipendenti: Template = { carica: () => import('../../php/pagine/esterni/employees') };
const esterniDocumentiDipendente: Template = { carica: () => import('../../php/pagine/esterni/employee-documents') };
const esterniDocumenti: Template = { carica: () => import('../../php/pagine/esterni/documents') };
const esterniFerieConsulente: Template = { carica: () => import('../../php/pagine/esterni/leave-requests-consulente') };
const esterniPresenze: Template = { carica: () => import('../../php/pagine/esterni/presenze-export') };
const esterniProfiloFormatore: Template = { carica: () => import('../../php/pagine/esterni/profile-accountant') };
const esterniProfiloConsulente: Template = { carica: () => import('../../php/pagine/esterni/profile-consulente') };
const esterniAssunzioni: Template = { carica: () => import('../../php/pagine/esterni/hire-requests') };
const staffModelliAttestato: Template = { carica: () => import('../../php/pagine/staff/academy-templates') };
const adminAziende: Template = { carica: () => import('../../php/pagine/admin/companies') };
const adminRepartoDettaglio: Template = { carica: () => import('../../php/pagine/admin/department-detail') };
const adminReparti: Template = { carica: () => import('../../php/pagine/admin/departments') };
const adminIndex: Template = { carica: () => import('../../php/pagine/admin/index') };
const adminFerie: Template = { carica: () => import('../../php/pagine/admin/leave-requests') };
const adminPassword: Template = { carica: () => import('../../php/pagine/admin/password-resets') };
const adminProfilo: Template = { carica: () => import('../../php/pagine/admin/profile') };
const adminTimbrature: Template = { carica: () => import('../../php/pagine/admin/punch-settings') };
const adminOrario: Template = { carica: () => import('../../php/pagine/admin/work-schedule') };
const staffChat: Template = { carica: () => import('../../php/pagine/staff/chat') };
import CalendarioStaff from '../../staff/CalendarioStaff';
import { PaginaStaff, type Template } from '../../staff/PaginaStaff';

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


const pagina = (percorso: string, render: Template, opz: { prepara?: (V: any) => void; scarica?: string[] } = {}) => (
  <Route key={percorso} path={percorso} element={<PaginaStaff render={render} {...opz} />} />
);

/** Pagina dell'HR con la vista Cora (se attiva) e la pagina classica per il resto. */
const hr = (percorso: string, Cora: (p: { cornice: CorniceHr }) => React.ReactNode, classica: React.ComponentProps<typeof PaginaStaff>,
  usaCora?: (q: URLSearchParams) => boolean) => (
  <Route key={percorso} path={percorso} element={<SceltaHr cora={(c) => <Cora cornice={c} />} classica={classica} usaCora={usaCora} />} />
);

/** Pagine dell'area staff (stessi indirizzi del PHP). */
export const rotteStaff = [
  ...['/admin', '/admin/'].map((x) => (
    <Route key={x} path={x} element={<IndiceHr classica={{ render: adminIndex, prepara: conSettimana }} />} />
  )),
  hr('/admin/leave-requests', FerieHr, { render: adminFerie, scarica: ['download_attachment', 'download_cert'] }, (q) => !q.has('classica')),
  hr('/admin/work-schedule', OrarioHr, { render: adminOrario }, (q) => !q.has('classica')),
  hr('/admin/punch-settings', TimbratureImpostazioniHr, { render: adminTimbrature }, (q) => !q.has('classica')),
  hr('/admin/buoni-pasto', BuoniPastoHr, { render: adminBuoniPasto }, (q) => !q.has('classica')),
  pagina('/admin/door-settings', adminPorta),
  pagina('/admin/door-log', adminPortaLog),
  pagina('/admin/smtp-settings', soloRedirect),
  hr('/admin/profile', ProfiloHr, { render: adminProfilo }, (q) => !q.has('classica')),
  hr('/admin/password-resets', ResetPasswordHr, { render: adminPassword }, (q) => !q.has('classica')),
  hr('/admin/departments', RepartiHr, { render: adminReparti }, (q) => !q.has('edit') && q.get('action') !== 'new'),
  pagina('/admin/department-detail', adminRepartoDettaglio),
  pagina('/admin/companies', adminAziende),
  hr('/admin/communications', ComunicazioniHr, { render: adminComunicazioni }, (q) => !q.has('action')),
  hr('/admin/employees', AnagraficaHr, { render: adminDipendenti }, (q) => !q.has('action') && !q.has('id')),
  hr('/admin/hire-requests', AssunzioniHr, { render: adminAssunzioni }, (q) => !q.has('action') && !q.has('id')),
  hr('/admin/attendance', PresenzeHr, { render: adminTimbratureGiorno }, (q) => !q.has('classica')),
  <Route key="/admin/consulente-lavoro" path="/admin/consulente-lavoro" element={<SceltaHr cora={(c) => <AccessiHr cornice={c} ruolo="consulente_lavoro" />}
    classica={{ render: adminUtentiEsterni, prepara: conOspiti }} usaCora={(q) => !q.has('action') && !q.has('id')} />} />,
  <Route key="/admin/formatori" path="/admin/formatori" element={<SceltaHr cora={(c) => <AccessiHr cornice={c} ruolo="formatore" />}
    classica={{ render: adminUtentiEsterni, prepara: conOspiti }} usaCora={(q) => !q.has('action') && !q.has('id')} />} />,
  // HR: chat Cora (come il dipendente); ?classica=1 per quella del PHP.
  hr('/admin/chat', ChatHr, { render: staffChat, scarica: ['download_attachment', 'group_avatar'] }, (q) => !q.has('classica')),
  pagina('/consulente-lavoro/chat', staffChat, { scarica: ['download_attachment', 'group_avatar'] }),
  ...['admin', 'consulente-lavoro'].map((a) => <Route key={`${a}/calendar`} path={`/${a}/calendar`} element={<CalendarioStaff />} />),
  pagina('/admin/presenze-export', adminPresenzeExport, { scarica: ['action'], prepara: (V) => { V.now = P.nuovo('DateTime', [V.now]); } }),
  hr('/admin/documenti-aziendali', DocumentiHr, { render: adminDocumentiAziendali, scarica: ['download'] }, (q) => !q.has('classica')),
  ...['admin', 'consulente-lavoro', 'formatore'].map((a) => pagina(`/${a}/academy`, staffAcademy, { scarica: ['export', 'zip'] })),
  ...['admin', 'consulente-lavoro', 'formatore'].map((a) => pagina(`/${a}/academy-templates`, staffModelliAttestato)),
  ...['/consulente-lavoro', '/consulente-lavoro/'].map((x) => pagina(x, esterniIndexConsulente)),
  ...['/formatore', '/formatore/'].map((x) => pagina(x, soloRedirect)),
  ...['consulente-lavoro'].map((a) => pagina(`/${a}/employees`, esterniDipendenti)),
  ...['consulente-lavoro'].map((a) => pagina(`/${a}/employee-documents`, esterniDocumentiDipendente, { scarica: ['download'] })),
  ...['admin', 'consulente-lavoro'].map((a) => pagina(`/${a}/documents`, esterniDocumenti)),
  pagina('/consulente-lavoro/leave-requests', esterniFerieConsulente, { scarica: ['download_cert'] }),
  ...['consulente-lavoro'].map((a) => pagina(`/${a}/presenze-export`, esterniPresenze, { scarica: ['action'], prepara: (V) => { V.now = P.nuovo('DateTime', [V.now]); } })),
  pagina('/formatore/profile', esterniProfiloFormatore),
  pagina('/consulente-lavoro/profile', esterniProfiloConsulente),
  pagina('/consulente-lavoro/hire-requests', esterniAssunzioni, { scarica: ['action'] }),
  ...['/superadmin', '/superadmin/'].map((x) => <Route key={x} path={x} element={<PaginaSuperadmin quale="index" />} />),
  <Route key="sa-login" path="/superadmin/login" element={<PaginaSuperadmin quale="login" />} />,
  <Route key="sa-totp" path="/superadmin/totp" element={<PaginaSuperadmin quale="totp" />} />,
  <Route key="sa-clic" path="/superadmin/clic" element={<PaginaSuperadmin quale="clic" />} />,
  <Route key="sa-logout" path="/superadmin/logout" element={<PaginaSuperadmin quale="logout" />} />,
];
