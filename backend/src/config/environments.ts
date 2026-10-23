/**
 * Ambienti dell'applicazione.
 *
 * A differenza del CRM lo schema del database non si aggiorna mai da solo
 * (niente synchronize): cambia solo con le migrazioni in database/migrations,
 * lanciate a mano o all'avvio con RUN_MIGRATIONS=true.
 */
export type NomeAmbiente = 'development' | 'test' | 'staging' | 'production';

export interface EnvironmentConfig {
  logging: boolean;
  corsOrigin: string;
}

export const environments: Record<NomeAmbiente, EnvironmentConfig> = {
  development: { logging: false, corsOrigin: 'http://localhost:5180' },
  test: { logging: false, corsOrigin: 'http://localhost:5180' },
  staging: { logging: false, corsOrigin: '' },
  production: { logging: false, corsOrigin: '' },
};

export function nomeAmbiente(): NomeAmbiente {
  const env = (process.env.APP_ENV || '').toLowerCase();
  if (env in environments) return env as NomeAmbiente;
  // Senza APP_ENV non si parte: e' l'unico modo per sapere se si e' in produzione.
  throw new Error(`APP_ENV mancante o non valida ("${env}"): usa development, test, staging o production`);
}

export function getEnvironmentConfig(): EnvironmentConfig {
  return environments[nomeAmbiente()];
}

/** Host del database ammessi fuori dalla produzione: solo macchine locali o container di sviluppo. */
const HOST_SVILUPPO = ['localhost', '127.0.0.1', '::1', 'postgres', 'db', 'postgres-test'];

/**
 * Barriera contro il collegamento accidentale ai dati veri.
 *
 * Fuori dalla produzione il backend rifiuta di partire se il database non e'
 * locale, o se trova variabili che si usano solo in produzione. In produzione
 * pretende che i segreti siano impostati.
 */
export function verificaAmbiente(): void {
  const ambiente = nomeAmbiente();
  const host = (process.env.DB_HOST || 'localhost').toLowerCase();
  if (ambiente === 'development' || ambiente === 'test') {
    if (!HOST_SVILUPPO.includes(host)) {
      throw new Error(`Ambiente ${ambiente}: il database "${host}" non e' locale. Avvio bloccato per proteggere i dati di produzione.`);
    }
    if (process.env.SMTP_HOST && !/^(localhost|127\.0\.0\.1|mailpit)$/i.test(process.env.SMTP_HOST)) {
      throw new Error(`Ambiente ${ambiente}: SMTP_HOST "${process.env.SMTP_HOST}" invierebbe email vere. Usa mailpit.`);
    }
    return;
  }
  for (const nome of ['JWT_SECRET', 'JWT_REFRESH_SECRET', 'APP_ENCRYPTION_KEY', 'DB_PASSWORD']) {
    if (!process.env[nome] || String(process.env[nome]).length < 16) {
      throw new Error(`Ambiente ${ambiente}: ${nome} mancante o troppo corta (almeno 16 caratteri)`);
    }
  }
}
