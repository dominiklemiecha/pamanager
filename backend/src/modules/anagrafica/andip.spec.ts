import { dataAndip, leggiAndip, leggiCsv } from './andip';

// Dati inventati, solo con le colonne del tracciato ANDIP che vengono lette (più una ignorata).
const INTEST = 'Anno;Mese;Azienda;Filiale;Ragione sociale;Descr.filiale;Matricola;Cognome;Nome;Data nascita;Codice fiscale;Data assunzione;Data licenziamento;'
  + 'Indirizzo residenza;C.A.P. residenza;Comune residenza;Provincia residenza;E-mail;Telefono;Cellulare;IBAN 1° accredito;'
  + 'Desc.qualifica Inps 1;Desc.qualifica Inps 2;% part-time;Aliquota contr.Inps azie';
const riga = (campi: string[]) => campi.join(';');

describe('tracciato ANDIP', () => {
  it('legge CSV con BOM, CRLF e virgolette', () => {
    expect(leggiCsv('﻿a;b\r\n"x;y";"z ""q"""\r\n')).toEqual([['a', 'b'], ['x;y', 'z "q"']]);
  });

  it('converte le date e tratta 00/00/0000 come vuota', () => {
    expect(dataAndip('18/11/1994')).toBe('1994-11-18');
    expect(dataAndip('00/00/0000')).toBeNull();
    expect(dataAndip('')).toBeNull();
    expect(dataAndip('31/02/2026')).toBeUndefined();
  });

  it('mappa una riga del tracciato', () => {
    const csv = `﻿${INTEST}\r\n${riga(['2026', 'OTTOBRE', '999', '1', 'AZIENDA PROVA', 'DIPENDENTI', '7', 'ROSSI', 'MARIO', '01/02/1990', 'RSSMRA90B01H501X',
      '01/07/2026', '13/10/2026', 'VIA ROMA 1', '165', 'ROMA', 'RM', '', '', '', '', 'Impiegato', 'Part-time orizzontale', '37,5', '29,06'])}\r\n`;
    const r = leggiAndip(csv);
    expect(r.ok).toBe(true);
    if (!r.ok) return;
    expect(r.righe).toHaveLength(1);
    expect(r.righe[0]).toMatchObject({
      riga: 2, azienda: '999', filiale: 1, descrFiliale: 'DIPENDENTI', matricola: '7', cognome: 'ROSSI', nome: 'MARIO',
      codiceFiscale: 'RSSMRA90B01H501X', dataNascita: '1990-02-01', dataAssunzione: '2026-07-01', dataCessazione: '2026-10-13',
      indirizzo: 'VIA ROMA 1, 00165 ROMA (RM)', email: null, telefono: null, iban: null, partTime: 37.5,
      qualifica: 'Impiegato · Part-time orizzontale', errori: [],
    });
    // Tutte le colonne restano, anche quelle che Cora non usa
    expect(Object.keys(r.righe[0].dati)).toEqual(INTEST.split(';'));
    expect(r.righe[0].dati['Aliquota contr.Inps azie']).toBe('29,06');
    expect(r.righe[0].dati['Data licenziamento']).toBe('13/10/2026');
  });

  it('segnala colonne mancanti e valori non validi', () => {
    expect(leggiAndip('Nome;Cognome\r\nA;B\r\n')).toMatchObject({ ok: false });
    const csv = `${INTEST}\r\n${riga(['2026', 'OTTOBRE', '999', '1', 'X', 'DIPENDENTI', '', 'BIANCHI', 'LUCA', '99/99/1990', '', '00/00/0000', '00/00/0000',
      '', '', '', '', '', '', '', '', '', '', '120', ''])}\r\n`;
    const r = leggiAndip(csv);
    expect(r.ok && r.righe[0].errori).toEqual(['% part-time non valida', 'Codice fiscale mancante', 'Matricola mancante', 'Data di nascita non valida']);
  });
});
