import { meseRilpre, oreRilpre, scriviRilpre } from './rilpre';

describe('tracciato RILPRE', () => {
  const file = scriviRilpre({
    azienda: '999', anno: 2026, mese: 10,
    dipendenti: [
      { matricola: '1', filiale: 1, nominativo: 'ROSSI MARIO', giorni: { 1: [{ sigla: 'ORD', ore: 8 }], 2: [{ sigla: 'ORD', ore: 2.4 }, { sigla: 'XX', ore: 1 }] } },
      { matricola: '2', filiale: 1, nominativo: 'BIANCHI LUCA', giorni: {} },
    ],
  });
  const righe = file.replace(/^﻿/, '').split('\r\n');

  it('formati di mese e ore', () => {
    expect(meseRilpre(2026, 10)).toBe('ott-26');
    expect(meseRilpre(2027, 1)).toBe('gen-27');
    expect(oreRilpre(8)).toBe('8');
    expect(oreRilpre(6.6)).toBe('6,6');
  });

  it('struttura: BOM, CRLF, 69 campi per riga, 7 righe PRES per dipendente', () => {
    expect(file.startsWith('﻿Column1;Column2;')).toBe(true);
    expect(file.endsWith('\r\n')).toBe(true);
    righe.slice(0, -1).forEach((r) => expect(r.split(';')).toHaveLength(69));
    expect(righe.map((r) => r.split(';')[0])).toEqual([
      'Column1', 'INTM',
      '', 'DIPE', 'GG01', 'GG02', 'PRES', 'PRES', 'PRES', 'PRES', 'PRES', 'PRES', 'PRES',
      '', 'DIPE', 'GG01', 'GG02', 'PRES', 'PRES', 'PRES', 'PRES', 'PRES', 'PRES', 'PRES',
      '',
    ]);
  });

  it('intestazione, giorni e coppie sigla/ore', () => {
    expect(righe[1].split(';').slice(0, 13)).toEqual(['INTM', 'Mese', 'Azienda', 'Filiale', 'Matricola', '', 'Codice Azienda:', '', '999', '', 'Mese Presenze:', '', 'ott-26']);
    expect(righe[3].split(';').slice(6, 13)).toEqual(['Matricola:', '', '1', '', 'Nominativo:', '', 'ROSSI MARIO']);
    expect(righe[4].split(';').slice(6, 10)).toEqual(['1 - Giovedì', '', '2 - Venerdì', '']);
    expect(righe[6].split(';').slice(0, 10)).toEqual(['PRES', 'ott-26', '999', '1', '1', '', 'ORD', '8', 'ORD', '2,4']);
    expect(righe[7].split(';').slice(6, 10)).toEqual(['', '', 'XX', '1']);
  });

  it('mese di 30 giorni: il giorno 31 resta vuoto', () => {
    const r = scriviRilpre({ azienda: '1', anno: 2026, mese: 11, dipendenti: [{ matricola: '1', filiale: 1, nominativo: 'X', giorni: { 31: [{ sigla: 'ORD', ore: 8 }] } }] })
      .split('\r\n');
    expect(r[4].split(';').slice(64, 68)).toEqual(['30 - Lunedì', '', '', '']);
    expect(r[6].split(';').slice(64, 68)).toEqual(['', '', '', '']);
  });
});
