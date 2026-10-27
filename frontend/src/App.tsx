import { ReactNode } from 'react';
import { Navigate, Route, Routes, useLocation } from 'react-router-dom';
import { SessioneProvider, useSessione } from './componenti/sessione';
import { percorsoPulito } from './services/indirizzi';
import ForgotPassword from './pages/auth/ForgotPassword';
import Login from './pages/auth/Login';
import Logout from './pages/auth/Logout';
import MfaVerify from './pages/auth/MfaVerify';
import ResetPassword from './pages/auth/ResetPassword';
import Academy from './pages/employee/Academy';
import Calendario from './pages/employee/Calendario';
import CambioPassword from './pages/employee/CambioPassword';
import Chat from './pages/employee/Chat';
import Comunicazioni from './pages/employee/Comunicazioni';
import ContrattoFirma from './pages/employee/ContrattoFirma';
import Disiscrizione from './pages/employee/Disiscrizione';
import Documenti from './pages/employee/Documenti';
import Home from './pages/employee/Home';
import Profilo from './pages/employee/Profilo';
import Richieste from './pages/employee/Richieste';
import Stato from './pages/Stato';
import { rotteStaff } from './pages/staff/rotte';

/** Pagina iniziale: chi e' dentro va alla sua area, gli altri al login (come index.php). */
function Radice() {
  const s = useSessione();
  if (!s.caricata) return null;
  return <Navigate to={s.utente && s.destinazione ? s.destinazione : '/auth/login'} replace />;
}

/**
 * Le pagine non hanno piu' l'estensione .php: i vecchi indirizzi (email gia'
 * inviate, notifiche salvate, segnalibri, redirect del backend) portano a
 * quelli nuovi con gli stessi parametri. /x/index.php diventa /x/.
 */
function SenzaPhp({ children }: { children: ReactNode }) {
  const l = useLocation();
  const pulito = percorsoPulito(l.pathname);
  if (pulito !== null) return <Navigate to={pulito + l.search + l.hash} replace />;
  return <>{children}</>;
}

export default function App() {
  return (
    <SessioneProvider>
      <SenzaPhp>
      <Routes>
        <Route path="/" element={<Radice />} />
        <Route path="/auth/login" element={<Login />} />
        <Route path="/auth/login-employee" element={<Navigate to="/auth/login" replace />} />
        <Route path="/auth/forgot-password" element={<ForgotPassword />} />
        <Route path="/auth/reset-password" element={<ResetPassword />} />
        <Route path="/auth/mfa-verify" element={<MfaVerify />} />
        <Route path="/auth/logout" element={<Logout />} />
        <Route path="/employee" element={<Home />} />
        <Route path="/employee/" element={<Home />} />
        <Route path="/employee/leave-requests" element={<Richieste />} />
        <Route path="/employee/documents" element={<Documenti />} />
        <Route path="/employee/communications" element={<Comunicazioni />} />
        <Route path="/employee/profile" element={<Profilo />} />
        <Route path="/employee/calendar" element={<Calendario />} />
        <Route path="/employee/chat" element={<Chat />} />
        <Route path="/employee/academy" element={<Academy />} />
        <Route path="/employee/contract-sign" element={<ContrattoFirma />} />
        <Route path="/employee/change-password" element={<CambioPassword />} />
        <Route path="/employee/unsubscribe" element={<Disiscrizione />} />
        {rotteStaff}
        <Route path="*" element={<Stato />} />
      </Routes>
      </SenzaPhp>
    </SessioneProvider>
  );
}
