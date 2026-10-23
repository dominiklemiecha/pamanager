import type { Request } from 'express';

export type RuoloStaff = 'admin' | 'accountant' | 'admin_reparto' | 'consulente_lavoro' | 'formatore';
export type Ruolo = RuoloStaff | 'employee';

/** Chi sta facendo la richiesta, ricavato dalla sessione a ogni chiamata. */
export interface UtenteCorrente {
  sessioneId: string;
  tipo: 'user' | 'employee';
  id: number;
  ruolo: Ruolo;
  /** Azienda su cui sta lavorando adesso (0 = nessuna accessibile: nessun dato). */
  companyId: number;
  /** Azienda "di casa" salvata sull'account (NULL per l'admin globale). */
  companyIdAccount: number | null;
  departmentId: number | null;
  username: string;
  nome: string;
  email: string | null;
  /** Password giusta ma manca ancora il codice MFA: vale solo per la verifica. */
  mfaInAttesa: boolean;
  /** Password temporanea da cambiare prima di usare il resto dell'app. */
  deveCambiarePassword: boolean;
}

export interface RichiestaAutenticata extends Request {
  utente?: UtenteCorrente;
}

/**
 * Timeout di inattivita' per ruolo (secondi), come config.php del PHP:
 * il consulente del lavoro non era nell'elenco e prendeva il default di 30 minuti.
 */
export const INATTIVITA: Record<string, number> = {
  admin: 600,
  accountant: 600,
  admin_reparto: 600,
  formatore: 600,
  employee: 900,
};
export const INATTIVITA_DEFAULT = 1800;
/** Durata massima della sessione dal login: il cookie PHP scadeva dopo 30 minuti. */
export const DURATA_MASSIMA = 1800;

export const MAX_TENTATIVI = 5;
export const BLOCCO_SECONDI = 900;
/** Ruoli staff con MFA obbligatoria se configurata sull'account (MFA_REQUIRED_ROLES). */
export const RUOLI_MFA: Ruolo[] = ['admin', 'accountant'];

export const COOKIE_SESSIONE = 'cora_sessione';
export const COOKIE_CSRF = 'cora_csrf';
