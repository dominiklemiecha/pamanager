/**
 * Scrittura del tracciato RILPRE di TeamSystem (rilevazione presenze
 * mensile, CSV separato da ";"). Funzione pura: riceve sigle e ore gia'
 * calcolate per giorno e produce il file con la stessa struttura del
 * modello fornito dal consulente:
 *
 *   intestazione Column1..Column69
 *   INTM  codice azienda e mese ("ott-26"), riga vuota
 *   per dipendente: DIPE (matricola, nominativo), GG01 (giorni),
 *   GG02 (Sigla/Ore), 7 righe PRES con 31 coppie sigla;ore,
 *   riga vuota tra un dipendente e l'altro
 *
 * Ogni riga ha 69 campi; UTF-8 con BOM, righe CRLF, ore con la virgola.
 */

export const RIGHE_PRES = 7;
const CAMPI = 69;
const MESI = ['gen', 'feb', 'mar', 'apr', 'mag', 'giu', 'lug', 'ago', 'set', 'ott', 'nov', 'dic'];
const GIORNI = ['Domenica', 'Lunedì', 'Martedì', 'Mercoledì', 'Giovedì', 'Venerdì', 'Sabato'];

export interface VoceGiorno { sigla: string; ore: number }
export interface DipendenteRilpre {
  matricola: string;
  filiale: number;
  nominativo: string;
  /** Giustificativi per giorno del mese (1-31), al massimo 7 per giorno. */
  giorni: Record<number, VoceGiorno[]>;
}
export interface DatiRilpre { azienda: string; anno: number; mese: number; dipendenti: DipendenteRilpre[] }

/** "ott-26" */
export const meseRilpre = (anno: number, mese: number) => `${MESI[mese - 1]}-${String(anno % 100).padStart(2, '0')}`;

/** 8 -> "8", 2.4 -> "2,4", 6.25 -> "6,25" */
export const oreRilpre = (h: number) => String(Math.round(h * 100) / 100).replace('.', ',');

const riga = (campi: string[]) => {
  const r = campi.slice(0, CAMPI);
  while (r.length < CAMPI) r.push('');
  return r.join(';');
};

export function scriviRilpre(d: DatiRilpre): string {
  const mese = meseRilpre(d.anno, d.mese);
  const nGiorni = new Date(Date.UTC(d.anno, d.mese, 0)).getUTCDate();
  const giorni: string[] = [];
  const intestGiorni: string[] = [];
  for (let g = 1; g <= 31; g++) {
    giorni.push(g <= nGiorni ? `${g} - ${GIORNI[new Date(Date.UTC(d.anno, d.mese - 1, g)).getUTCDay()]}` : '', '');
    intestGiorni.push(g <= nGiorni ? 'Sigla' : '', g <= nGiorni ? 'Ore' : '');
  }
  const out: string[] = [
    Array.from({ length: CAMPI }, (_, i) => `Column${i + 1}`).join(';'),
    riga(['INTM', 'Mese', 'Azienda', 'Filiale', 'Matricola', '', 'Codice Azienda:', '', d.azienda, '', 'Mese Presenze:', '', mese]),
  ];
  for (const p of d.dipendenti) {
    out.push(riga([]));
    out.push(riga(['DIPE', '', '', '', '', '', 'Matricola:', '', p.matricola, '', 'Nominativo:', '', p.nominativo]));
    out.push(riga(['GG01', '', '', '', '', '', ...giorni]));
    out.push(riga(['GG02', '', '', '', '', '', ...intestGiorni]));
    for (let i = 0; i < RIGHE_PRES; i++) {
      const coppie: string[] = [];
      for (let g = 1; g <= 31; g++) {
        const v = g <= nGiorni ? p.giorni[g]?.[i] : undefined;
        coppie.push(v ? v.sigla : '', v ? oreRilpre(v.ore) : '');
      }
      out.push(riga(['PRES', mese, d.azienda, String(p.filiale), p.matricola, '', ...coppie]));
    }
  }
  return `﻿${out.join('\r\n')}\r\n`;
}
