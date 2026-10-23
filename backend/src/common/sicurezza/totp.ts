import { createHmac, timingSafeEqual } from 'crypto';
import { verificaPassword } from './password';

/**
 * Codici a tempo (TOTP) e codici di riserva, come la classe MFA del PHP:
 * 6 cifre, passo di 30 secondi, HMAC-SHA1, accettato un passo prima e dopo.
 */
const CIFRE = 6;
const PASSO = 30;
const FINESTRA = 1;
const BASE32 = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';

function base32(dati: string): Buffer {
  const pulito = dati.toUpperCase().replace(/=/g, '');
  const byte: number[] = [];
  let buffer = 0;
  let bit = 0;
  for (const ch of pulito) {
    const v = BASE32.indexOf(ch);
    if (v < 0) continue;
    buffer = (buffer << 5) | v;
    bit += 5;
    if (bit >= 8) {
      bit -= 8;
      byte.push((buffer >> bit) & 0xff);
    }
  }
  return Buffer.from(byte);
}

export function codiceTotp(segreto: string, istante = Date.now()): string {
  const contatore = Math.floor(istante / 1000 / PASSO);
  const msg = Buffer.alloc(8);
  msg.writeBigUInt64BE(BigInt(contatore));
  const h = createHmac('sha1', base32(segreto)).update(msg).digest();
  const o = h[19] & 0x0f;
  const n = ((h[o] & 0x7f) << 24) | (h[o + 1] << 16) | (h[o + 2] << 8) | h[o + 3];
  return String(n % 10 ** CIFRE).padStart(CIFRE, '0');
}

export function verificaTotp(segreto: string, inserito: string, istante = Date.now()): boolean {
  const codice = inserito.replace(/\s+/g, '');
  if (!new RegExp(`^\\d{${CIFRE}}$`).test(codice)) return false;
  for (let i = -FINESTRA; i <= FINESTRA; i++) {
    const atteso = codiceTotp(segreto, istante + i * PASSO * 1000);
    if (timingSafeEqual(Buffer.from(atteso), Buffer.from(codice))) return true;
  }
  return false;
}

/**
 * Codice di riserva: confrontato con gli hash salvati (JSON di password_hash).
 * Se e' valido restituisce l'elenco senza quel codice, da salvare al suo posto.
 */
export async function usaCodiceRiserva(inserito: string, salvati: string | null): Promise<string | null> {
  if (!salvati) return null;
  let hash: unknown;
  try { hash = JSON.parse(salvati); } catch { return null; }
  if (!Array.isArray(hash)) return null;
  const normale = inserito.replace(/[-\s]/g, '').toUpperCase();
  for (let i = 0; i < hash.length; i++) {
    if (await verificaPassword(normale, String(hash[i]))) {
      const resto = hash.filter((_, j) => j !== i);
      return JSON.stringify(resto);
    }
  }
  return null;
}
