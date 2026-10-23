import { execFileSync } from 'child_process';
import { hashPassword, verificaPassword, erroriPassword } from './password';
import { codiceTotp, usaCodiceRiserva, verificaTotp } from './totp';

/**
 * Equivalenza con il PHP: i valori attesi sono stati prodotti da
 * password_hash() e MFA::generateCode() del vecchio codice.
 */
const PHP = {
  argon: '$argon2id$v=19$m=65536,t=4,p=3$V2hLYkJrY1IwUUVzM29DaA$aqZYQ8Yb3PdAfsZTpFqjTQBFZhPznpic95euBkVOXgI',
  bcrypt: '$2y$12$EvrQaBzHmwTEdZR99cOy1.O2Bx/0kJ8LK034Q.n8kz6/bUqBrk4H.',
  backup: JSON.stringify([
    '$2y$10$0/C6I0TWhkNzZuAGcBCzaO2t2Xt4nnPqt8Sihcb1ttiUQpiYyXmoW',
    '$2y$10$sP0C04cBWod0K/V9Ac2KYei8S6Lsj.YlDEX4vFKhy5RhMnFOr/G6y',
  ]),
};

describe('password compatibili con il PHP', () => {
  it('accetta gli hash Argon2id e bcrypt $2y$ scritti dal PHP', async () => {
    expect(await verificaPassword('Prova!Cora2026', PHP.argon)).toBe(true);
    expect(await verificaPassword('Prova!Cora2026', PHP.bcrypt)).toBe(true);
    expect(await verificaPassword('prova!cora2026', PHP.bcrypt)).toBe(false);
  });

  it('un hash rotto o vuoto non fa entrare', async () => {
    expect(await verificaPassword('x', '')).toBe(false);
    expect(await verificaPassword('x', 'testo-a-caso')).toBe(false);
  });

  it('le password nuove sono Argon2id con i parametri del PHP', async () => {
    const h = await hashPassword('Nuova!Password1');
    expect(h).toMatch(/^\$argon2id\$v=19\$m=65536,t=4,p=3\$/);
    expect(await verificaPassword('Nuova!Password1', h)).toBe(true);
  });

  it('stesse regole e stessi messaggi di Auth::validatePassword', () => {
    expect(erroriPassword('Prova!Cora2026')).toEqual([]);
    expect(erroriPassword('corta')).toEqual([
      'La password deve contenere almeno 12 caratteri',
      'La password deve contenere almeno una lettera maiuscola',
      'La password deve contenere almeno un numero',
      'La password deve contenere almeno un carattere speciale (!@#$%^&*...)',
    ]);
    // Lettere accentate: contano come byte per la lunghezza ma non come minuscole.
    expect(erroriPassword('àààààààààA1!')).toEqual(['La password deve contenere almeno una lettera minuscola']);
  });
});

describe('codici a tempo come MFA.php', () => {
  const segreto = 'JBSWY3DPEHPK3PXP';
  it('stessi codici del PHP agli stessi istanti', () => {
    expect(codiceTotp(segreto, 1761200000 * 1000)).toBe('800316');
    expect(codiceTotp(segreto, 1761200030 * 1000)).toBe('428574');
    expect(codiceTotp(segreto, 59 * 1000)).toBe('996554');
  });
  it('accetta un passo prima e dopo, non due', () => {
    const t = 1761200000 * 1000;
    expect(verificaTotp(segreto, '428574', t)).toBe(true);
    expect(verificaTotp(segreto, '428 574', t)).toBe(true);
    expect(verificaTotp(segreto, codiceTotp(segreto, t + 60_000), t)).toBe(false);
    expect(verificaTotp(segreto, '12345', t)).toBe(false);
  });
  it('codice di riserva: valido una volta sola', async () => {
    const resto = await usaCodiceRiserva('abcd-1234', PHP.backup);
    expect(resto).not.toBeNull();
    expect(JSON.parse(resto!)).toHaveLength(1);
    expect(await usaCodiceRiserva('ABCD1234', resto)).toBeNull();
  });
});

describe('hash di Node letti dal PHP (rollback)', () => {
  const php = (() => { try { execFileSync('docker', ['exec', 'cora-baseline-app-1', 'php', '-v']); return true; } catch { return false; } })();
  (php ? it : it.skip)('password_verify del PHP accetta un hash Argon2id creato da Node', async () => {
    const h = await hashPassword('Rollback!Prova1');
    const out = execFileSync('docker', ['exec', 'cora-baseline-app-1', 'php', '-r', `echo password_verify('Rollback!Prova1', '${h}') ? 'si' : 'no';`]).toString();
    expect(out).toBe('si');
  });
});
