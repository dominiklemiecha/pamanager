import { Navigate, Route, Routes } from 'react-router-dom';
import { SessioneProvider, useSessione } from './componenti/sessione';
import ForgotPassword from './pages/auth/ForgotPassword';
import Login from './pages/auth/Login';
import MfaVerify from './pages/auth/MfaVerify';
import ResetPassword from './pages/auth/ResetPassword';
import Documenti from './pages/employee/Documenti';
import Home from './pages/employee/Home';
import Richieste from './pages/employee/Richieste';
import Stato from './pages/Stato';

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
        <Route path="/employee" element={<Home />} />
        <Route path="/employee/" element={<Home />} />
        <Route path="/employee/index.php" element={<Home />} />
        <Route path="/employee/leave-requests.php" element={<Richieste />} />
        <Route path="/employee/documents.php" element={<Documenti />} />
        <Route path="*" element={<Stato />} />
      </Routes>
    </SessioneProvider>
  );
}
