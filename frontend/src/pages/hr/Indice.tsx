import { useCorniceHr } from '../../cora/HrShell';
import { PaginaStaff } from '../../staff/PaginaStaff';
import HomeHr from './Home';

/**
 * Home dell'HR: vista Cora se attiva (HR_CORA_UI), altrimenti la dashboard
 * classica convertita dal PHP.
 */
export function IndiceHr({ classica }: { classica: React.ComponentProps<typeof PaginaStaff> }) {
  const cornice = useCorniceHr();
  if (!cornice) return null;
  return cornice.coraHr ? <HomeHr cornice={cornice} /> : <PaginaStaff {...classica} />;
}
