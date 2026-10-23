import * as path from 'path';

/**
 * Indirizzo pubblico dell'app, usato nei link delle email (reset password,
 * disiscrizione, attestati). Nel PHP si ricavava dall'header Host della
 * richiesta: chi lo falsificava poteva farsi mandare link verso il proprio
 * dominio. Qui viene solo dalla configurazione.
 */
export function urlPubblico(percorso = ''): string {
  const base = (process.env.APP_URL || 'http://localhost:5180').replace(/\/+$/, '');
  return base + (percorso ? '/' + percorso.replace(/^\/+/, '') : '');
}

/** Cartella dei file privati (documenti, allegati, chiavi push): mai servita direttamente. */
export function cartellaStorage(...parti: string[]): string {
  return path.resolve(process.env.STORAGE_DIR || './storage', ...parti);
}

/** Cartella dei caricamenti (foto, certificati): servita solo da endpoint con controllo. */
export function cartellaUpload(...parti: string[]): string {
  return path.resolve(process.env.UPLOAD_DIR || './uploads', ...parti);
}
