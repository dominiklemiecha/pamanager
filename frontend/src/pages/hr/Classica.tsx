import { ReactNode } from 'react';
import { useClasseBody, useCss } from '../../componenti/stile';
import type { CorniceHr } from '../../cora/HrShell';
import { useRisorseStaff } from '../../staff/StaffShell';
import type { CorniceStaff } from '../../staff/tipi';
import { SCHEDE as SCHEDE_ACCESSI } from './Accessi';
import { SCHEDE as SCHEDE_ARCHIVIO } from './Archivio';
import { SCHEDE_DIPENDENTI, SezioneHr } from './comune';
import { SCHEDE as SCHEDE_PROFILO } from './Profilo';

/**
 * Sezione Cora di ogni pagina dell'HR che il Figma non ridisegna (schede,
 * moduli, chat, academy...): stessa barra in alto, stesse schede e stesso
 * dock delle pagine Cora. `pagina` e' la voce del dock, `attiva` la scheda.
 */
const SEZIONI: Record<string, { pagina: string; titolo: string; schede?: Array<[string, string]>; attiva?: string }> = {
  employees: { pagina: 'dipendenti', titolo: 'Dipendenti', schede: SCHEDE_DIPENDENTI, attiva: 'Anagrafica' },
  'hire-requests': { pagina: 'dipendenti', titolo: 'Dipendenti', schede: SCHEDE_DIPENDENTI, attiva: 'Assunzioni' },
  attendance: { pagina: 'dipendenti', titolo: 'Dipendenti', schede: SCHEDE_DIPENDENTI, attiva: 'Presenze' },
  'presenze-export': { pagina: 'dipendenti', titolo: 'Dipendenti', schede: SCHEDE_DIPENDENTI, attiva: 'Presenze' },
  departments: { pagina: 'dipendenti', titolo: 'Dipendenti', schede: SCHEDE_DIPENDENTI, attiva: 'Reparti' },
  'department-detail': { pagina: 'dipendenti', titolo: 'Dipendenti', schede: SCHEDE_DIPENDENTI, attiva: 'Reparti' },
  'leave-requests': { pagina: 'ferie', titolo: 'Ferie e permessi' },
  communications: { pagina: 'archivio', titolo: 'Archivio', schede: SCHEDE_ARCHIVIO, attiva: 'Comunicazioni' },
  'documenti-aziendali': { pagina: 'archivio', titolo: 'Archivio', schede: SCHEDE_ARCHIVIO, attiva: 'Documenti' },
  documents: { pagina: 'archivio', titolo: 'Archivio', schede: SCHEDE_ARCHIVIO },
  'consulente-lavoro': { pagina: 'accessi', titolo: 'Accessi esterni', schede: SCHEDE_ACCESSI, attiva: 'Consulenti del lavoro' },
  formatori: { pagina: 'accessi', titolo: 'Accessi esterni', schede: SCHEDE_ACCESSI, attiva: 'Formatori' },
  chat: { pagina: 'chat', titolo: 'Chat' },
  profile: { pagina: 'profilo', titolo: 'Profilo e configurazione', schede: SCHEDE_PROFILO, attiva: 'Profilo' },
  'punch-settings': { pagina: 'profilo', titolo: 'Profilo e configurazione', schede: SCHEDE_PROFILO, attiva: 'Timbrature' },
  'door-settings': { pagina: 'profilo', titolo: 'Profilo e configurazione', schede: SCHEDE_PROFILO, attiva: 'Timbrature' },
  'door-log': { pagina: 'profilo', titolo: 'Profilo e configurazione', schede: SCHEDE_PROFILO, attiva: 'Timbrature' },
  'password-resets': { pagina: 'profilo', titolo: 'Profilo e configurazione', schede: SCHEDE_PROFILO, attiva: 'Reset password' },
  'work-schedule': { pagina: 'profilo', titolo: 'Profilo e configurazione', schede: SCHEDE_PROFILO, attiva: 'Orario lavorativo' },
  'buoni-pasto': { pagina: 'profilo', titolo: 'Profilo e configurazione', schede: SCHEDE_PROFILO, attiva: 'Buoni pasto' },
  calendar: { pagina: 'calendario', titolo: 'Calendario' },
  academy: { pagina: 'academy', titolo: 'Academy' },
  'academy-templates': { pagina: 'academy', titolo: 'Academy' },
  companies: { pagina: '', titolo: 'Aziende' },
};

/** Blu del vecchio tema scritti a mano nei template (stili e attributi style) e colori Cora corrispondenti. */
const COLORI: Array<[RegExp, string]> = [
  [/#(0b3aa4|2563eb|1e3a8a|044bff|3182ce)\b/gi, '#080808'],
  [/#(4fa1ff|93c5fd|3b82f6|60a5fa)\b/gi, '#4f5c59'],
  [/#(e0e7ff|fafbff)\b/gi, '#e2eae9'],
  [/rgba\(\s*79\s*,\s*161\s*,\s*255\s*,/gi, 'rgba(8,8,8,'],
  [/#082b7b\b/gi, '#262626'],
  [/#(eff6ff|eef3fb)\b/gi, '#f6f8f7'],
  [/#(dbeafe|bfdbfe)\b/gi, '#e2eae9'],
  [/rgba\(\s*11\s*,\s*58\s*,\s*164\s*,/gi, 'rgba(8,8,8,'],
];

/** HTML di una pagina classica con i colori della vista Cora. */
export function ricolora(html: string): string {
  return COLORI.reduce((h, [da, a]) => h.replace(da, a), html);
}

export function CorniceClassicaHr({ file, titolo, cornice, corniceHr, stile, script, globali, children }: {
  /** Nome della pagina PHP (es. "employees"). */
  file: string; titolo: string; cornice: CorniceStaff; corniceHr: CorniceHr; children: ReactNode;
  /** Come per la cornice classica: <style> della pagina, script e variabili globali che li precedono. */
  stile?: string; script?: string[]; globali?: Record<string, unknown>;
}) {
  useRisorseStaff({ cornice, stile, script, globali });
  useCss('/assets/css/cora-hr-classica.css');
  useClasseBody('hr-classica-body');
  const s = SEZIONI[file] ?? { pagina: '', titolo };
  return (
    <SezioneHr cornice={corniceHr} pagina={s.pagina} titolo={s.titolo} sottotitolo={titolo.toLowerCase() !== s.titolo.toLowerCase() ? titolo : ''} schede={s.schede} attiva={s.attiva}>
      <div className="hr-classica">{children}</div>
    </SezioneHr>
  );
}
