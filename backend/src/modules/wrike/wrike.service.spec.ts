import { WrikeService } from './wrike.service';

/** Token Wrike cifrati: leggibili dopo il passaggio dal PHP al nuovo backend. */
describe('WrikeService cifratura', () => {
  const env = { ...process.env };
  afterEach(() => { process.env = { ...env }; });
  const servizio = () => new WrikeService({} as any);

  it('cifra e decifra con APP_ENCRYPTION_KEY', () => {
    process.env.APP_ENCRYPTION_KEY = 'chiave-di-prova-lunga-abbastanza';
    const s = servizio();
    expect(s.decifra(s.cifra('token-segreto'))).toBe('token-segreto');
  });

  it('legge i token salvati dal PHP con la chiave delle credenziali del database', () => {
    delete process.env.APP_ENCRYPTION_KEY;
    process.env.LEGACY_DB_PASSWORD = 'pw-vecchio-db';
    process.env.LEGACY_DB_NAME = 'pamanager';
    const salvatoDalPhp = servizio().cifra('token-del-php');
    process.env.APP_ENCRYPTION_KEY = 'chiave-nuova-di-produzione-123';
    expect(servizio().decifra(salvatoDalPhp)).toBe('token-del-php');
  });

  it('senza la chiave giusta non restituisce nulla', () => {
    process.env.APP_ENCRYPTION_KEY = 'prima-chiave-di-prova-123456';
    const salvato = servizio().cifra('x-token');
    process.env.APP_ENCRYPTION_KEY = 'altra-chiave-di-prova-1234567';
    delete process.env.LEGACY_DB_PASSWORD;
    expect(servizio().decifra(salvato)).toBeNull();
  });
});
