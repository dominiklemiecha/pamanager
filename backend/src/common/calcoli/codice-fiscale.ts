import * as fs from 'fs';
import { cartellaRisorse } from '../../config/app';
import { oggiRoma } from './php';

const MESI: Record<string, number> = { A: 1, B: 2, C: 3, D: 4, E: 5, H: 6, L: 7, M: 8, P: 9, R: 10, S: 11, T: 12 };

let tabelle: { comuni: Record<string, [string, string]>; esteri: Record<string, string> } | null = null;
function catasto() {
  tabelle ??= JSON.parse(fs.readFileSync(cartellaRisorse('codici_catastali.json'), 'utf8'));
  return tabelle!;
}

/**
 * FiscalCodeDecoder::decode: data di nascita, sesso, comune/provincia/stato
 * di nascita dal codice fiscale (tabella dei codici catastali in risorse/).
 */
export function decodificaCodiceFiscale(valore: string) {
  const cf = valore.replace(/\s+/g, '').toUpperCase();
  if (!/^[A-Z]{6}[0-9]{2}[A-Z][0-9]{2}[A-Z][0-9]{3}[A-Z]$/.test(cf)) return null;
  const anno2 = Number(cf.slice(6, 8));
  const adesso = Number(oggiRoma().slice(2, 4));
  const anno = (anno2 > adesso + 5 ? 1900 : 2000) + anno2;
  const mese = MESI[cf[8]];
  if (!mese) return null;
  let giorno = Number(cf.slice(9, 11));
  const sesso = giorno > 40 ? 'F' : 'M';
  if (giorno > 40) giorno -= 40;
  const d = new Date(Date.UTC(anno, mese - 1, giorno));
  if (giorno < 1 || d.getUTCMonth() !== mese - 1 || d.getUTCDate() !== giorno) return null;
  const codice = cf.slice(11, 15);
  let comune = '';
  let provincia = '';
  let stato = '';
  if (codice[0] === 'Z') {
    stato = catasto().esteri[codice] ?? '';
    comune = stato || 'Estero';
    provincia = 'EE';
  } else {
    const c = catasto().comuni[codice];
    if (c) [comune, provincia, stato] = [c[0], c[1], 'Italia'];
  }
  return {
    valid: true, birth_date: `${String(anno).padStart(4, '0')}-${String(mese).padStart(2, '0')}-${String(giorno).padStart(2, '0')}`, sex: sesso,
    birth_city: comune, birth_province: provincia, birth_state: stato, cadastral_code: codice,
  };
}
