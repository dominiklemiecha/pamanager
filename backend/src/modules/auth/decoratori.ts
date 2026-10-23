import { createParamDecorator, ExecutionContext, SetMetadata } from '@nestjs/common';
import type { RichiestaAutenticata, Ruolo, UtenteCorrente } from './auth.types';

export const CHIAVE_PUBBLICO = 'cora:pubblico';
export const CHIAVE_RUOLI = 'cora:ruoli';
export const CHIAVE_SENZA_CSRF = 'cora:senza-csrf';
export const CHIAVE_MFA_IN_CORSO = 'cora:mfa-in-corso';
export const CHIAVE_CAMBIO_PASSWORD = 'cora:cambio-password';

/** Rotta raggiungibile senza login (login, recupero password, link pubblici). */
export const Pubblico = () => SetMetadata(CHIAVE_PUBBLICO, true);

/** Solo questi ruoli; tutti gli altri ricevono 403 e finiscono nel registro. */
export const Ruoli = (...ruoli: Ruolo[]) => SetMetadata(CHIAVE_RUOLI, ruoli);

/**
 * Rotte chiamate da sistemi esterni con una propria chiave (CRM, porta):
 * non usano i cookie del browser, quindi il controllo CSRF non serve.
 */
export const SenzaCsrf = () => SetMetadata(CHIAVE_SENZA_CSRF, true);

/** Rotta usabile durante il secondo passo dell'MFA (solo la verifica del codice). */
export const DuranteMfa = () => SetMetadata(CHIAVE_MFA_IN_CORSO, true);

/**
 * Rotta usabile anche da chi deve ancora cambiare la password temporanea.
 * Tutte le altre rispondono 403 finche' non la cambia.
 */
export const DuranteCambioPassword = () => SetMetadata(CHIAVE_CAMBIO_PASSWORD, true);

export const Utente = createParamDecorator((_d: unknown, ctx: ExecutionContext): UtenteCorrente | undefined =>
  ctx.switchToHttp().getRequest<RichiestaAutenticata>().utente);
