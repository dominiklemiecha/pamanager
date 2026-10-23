import { Route } from 'react-router-dom';
import { P } from '../../php/runtime';
import adminIndex from '../../php/pagine/admin/index';
import { PaginaStaff } from '../../staff/PaginaStaff';

/** Date della settimana del widget presenze come DateTime (il template usa ->format). */
const conSettimana = (V: any) => {
  if (V.weekStart) V.weekStart = P.nuovo('DateTime', [V.weekStart]);
  if (V.weekEnd) V.weekEnd = P.nuovo('DateTime', [V.weekEnd]);
};

/** Pagine dell'area staff (stessi indirizzi del PHP). */
export const rotteStaff = [
  <Route key="admin" path="/admin" element={<PaginaStaff render={adminIndex} prepara={conSettimana} />} />,
  <Route key="admin/" path="/admin/" element={<PaginaStaff render={adminIndex} prepara={conSettimana} />} />,
  <Route key="admin/index" path="/admin/index.php" element={<PaginaStaff render={adminIndex} prepara={conSettimana} />} />,
];
