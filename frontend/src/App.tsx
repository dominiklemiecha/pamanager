import { Navigate, Route, Routes } from 'react-router-dom';
import { SessioneProvider, useSessione } from './componenti/sessione';
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
  return <Navigate to={s.utente && s.destinazione ? s.destinazione : '/auth/login.php'} replace />;
}

export default function App() {
  return (
    <SessioneProvider>
      <Routes>
        <Route path="/" element={<Radice />} />
        <Route path="/index.php" element={<Radice />} />
        <Route path="/auth/login.php" element={<Login />} />
        <Route path="/auth/login-employee.php" element={<Navigate to="/auth/login.php" replace />} />
        <Route path="/auth/forgot-password.php" element={<ForgotPassword />} />
        <Route path="/auth/reset-password.php" element={<ResetPassword />} />
        <Route path="/auth/mfa-verify.php" element={<MfaVerify />} />
        <Route path="/auth/logout.php" element={<Logout />} />
        <Route path="/employee" element={<Home />} />
        <Route path="/employee/" element={<Home />} />
        <Route path="/employee/index.php" element={<Home />} />
        <Route path="/employee/leave-requests.php" element={<Richieste />} />
        <Route path="/employee/documents.php" element={<Documenti />} />
        <Route path="/employee/communications.php" element={<Comunicazioni />} />
        <Route path="/employee/profile.php" element={<Profilo />} />
        <Route path="/employee/calendar.php" element={<Calendario />} />
        <Route path="/employee/chat.php" element={<Chat />} />
        <Route path="/employee/academy.php" element={<Academy />} />
        <Route path="/employee/contract-sign.php" element={<ContrattoFirma />} />
        <Route path="/employee/change-password.php" element={<CambioPassword />} />
        <Route path="/employee/unsubscribe.php" element={<Disiscrizione />} />
        {rotteStaff}
        <Route path="*" element={<Stato />} />
      </Routes>
    </SessioneProvider>
  );
}
