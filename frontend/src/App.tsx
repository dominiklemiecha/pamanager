import { lazy, ReactNode, Suspense } from 'react';
import { Navigate, Route, Routes, useLocation } from 'react-router-dom';
import { SessioneProvider, useSessione } from './componenti/sessione';
import { percorsoPulito } from './services/indirizzi';
import { useNavigazioneVeloce } from './services/navigazione';
const ForgotPassword = lazy(() => import('./pages/auth/ForgotPassword'));
import Login from './pages/auth/Login';
const Logout = lazy(() => import('./pages/auth/Logout'));
const MfaVerify = lazy(() => import('./pages/auth/MfaVerify'));
const ResetPassword = lazy(() => import('./pages/auth/ResetPassword'));
const Calendario = lazy(() => import('./pages/employee/Calendario'));
const CambioPassword = lazy(() => import('./pages/employee/CambioPassword'));
const Chat = lazy(() => import('./pages/employee/Chat'));
const Comunicazioni = lazy(() => import('./pages/employee/Comunicazioni'));
const ContrattoFirma = lazy(() => import('./pages/employee/ContrattoFirma'));
const Disiscrizione = lazy(() => import('./pages/employee/Disiscrizione'));
const Documenti = lazy(() => import('./pages/employee/Documenti'));
const Home = lazy(() => import('./pages/employee/Home'));
const Profilo = lazy(() => import('./pages/employee/Profilo'));
const Richieste = lazy(() => import('./pages/employee/Richieste'));
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
  useNavigazioneVeloce();
  return (
    <SessioneProvider>
      <SenzaPhp>
      <Suspense fallback={null}>
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
        {/* Academy tolta per ora: dai vecchi link si torna alla home. */}
        <Route path="/employee/academy" element={<Navigate to="/employee/" replace />} />
        <Route path="/employee/contract-sign" element={<ContrattoFirma />} />
        <Route path="/employee/change-password" element={<CambioPassword />} />
        <Route path="/employee/unsubscribe" element={<Disiscrizione />} />
        {rotteStaff}
        <Route path="*" element={<Stato />} />
      </Routes>
      </Suspense>
      </SenzaPhp>
    </SessioneProvider>
  );
}
