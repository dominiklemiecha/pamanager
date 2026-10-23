import { randomInt } from 'crypto';
import * as argon2 from 'argon2';
import * as bcrypt from 'bcrypt';

/**
 * Password: stesse regole e stessi formati del vecchio PHP (Auth.php).
 *
 * Nel database convivono hash Argon2id ($argon2id$) e bcrypt ($2y$, scritti da
 * PHP). Le nuove password si salvano in Argon2id con gli stessi parametri del
 * PHP, cosi' restano leggibili anche dal vecchio codice (rollback).
 */

export const PASSWORD_MIN = 12;

/** Errori della password, con gli stessi testi del PHP (Auth::validatePassword). */
export function erroriPassword(password: string): string[] {
  const errori: string[] = [];
  // strlen di PHP conta i byte, non i caratteri.
  if (Buffer.byteLength(password, 'utf8') < PASSWORD_MIN) errori.push(`La password deve contenere almeno ${PASSWORD_MIN} caratteri`);
  if (!/[A-Z]/.test(password)) errori.push('La password deve contenere almeno una lettera maiuscola');
  if (!/[a-z]/.test(password)) errori.push('La password deve contenere almeno una lettera minuscola');
  if (!/[0-9]/.test(password)) errori.push('La password deve contenere almeno un numero');
  if (!/[!@#$%^&*()_+\-=[\]{}|;:,.<>?]/.test(password)) errori.push('La password deve contenere almeno un carattere speciale (!@#$%^&*...)');
  if (['password', '123456', 'qwerty', 'admin', 'letmein'].includes(password.toLowerCase())) errori.push('La password è troppo comune');
  return errori;
}

/** Hash nuovo: Argon2id m=65536 t=4 p=3, come password_hash(PASSWORD_ARGON2ID) del PHP. */
export function hashPassword(password: string): Promise<string> {
  return argon2.hash(password, { type: argon2.argon2id, memoryCost: 65536, timeCost: 4, parallelism: 3 });
}

/** Verifica su qualsiasi formato presente nel database. Mai eccezioni: un hash rotto vale "no". */
export async function verificaPassword(password: string, hash: string | null | undefined): Promise<boolean> {
  if (!hash) return false;
  try {
    if (hash.startsWith('$argon2')) return await argon2.verify(hash, password);
    // $2y$ (PHP) e $2b$ sono lo stesso algoritmo: la libreria conosce solo il secondo.
    if (/^\$2[aby]\$/.test(hash)) return await bcrypt.compare(password, hash.replace(/^\$2y\$/, '$2b$'));
  } catch {
    return false;
  }
  return false;
}

/** Password temporanea come Auth::generateSecurePassword: 16 caratteri, una per tipo. */
export function passwordTemporanea(lunghezza = 16): string {
  const maiuscole = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ';
  const minuscole = 'abcdefghijklmnopqrstuvwxyz';
  const numeri = '0123456789';
  const speciali = '!@#$%^&*()_+-=';
  const scegli = (s: string) => s[randomInt(s.length)];
  const tutti = maiuscole + minuscole + numeri + speciali;
  const c = [scegli(maiuscole), scegli(minuscole), scegli(numeri), scegli(speciali)];
  while (c.length < lunghezza) c.push(scegli(tutti));
  for (let i = c.length - 1; i > 0; i--) {
    const j = randomInt(i + 1);
    [c[i], c[j]] = [c[j], c[i]];
  }
  return c.join('');
}
