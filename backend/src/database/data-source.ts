import '../common/config/pulisci-env';
import { types } from 'pg';
import { DataSource, DataSourceOptions } from 'typeorm';
import * as dotenv from 'dotenv';
import * as path from 'path';
import { verificaAmbiente } from '../config/environments';

dotenv.config({ path: path.resolve(__dirname, '../../.env') });

// Le date senza ora restano stringhe "AAAA-MM-GG", come le restituiva il PHP:
// convertite in Date cambierebbero giorno a seconda del fuso del server.
types.setTypeParser(1082, (v: string) => v);

/**
 * Connessione unica per l'app e per la CLI delle migrazioni.
 *
 * synchronize resta sempre spento: lo schema cambia solo con i file in
 * database/migrations. Le migrazioni di TypeORM hanno una tabella propria,
 * cosi' non si confondono con quella del vecchio PHP.
 */
export function opzioniDatabase(): DataSourceOptions {
  verificaAmbiente();
  return {
    type: 'postgres',
    host: process.env.DB_HOST || 'localhost',
    port: parseInt(process.env.DB_PORT || '5432', 10),
    username: process.env.DB_USERNAME || 'cora',
    password: process.env.DB_PASSWORD || '',
    database: process.env.DB_DATABASE || 'cora',
    entities: [path.resolve(__dirname, '../**/*.entity{.ts,.js}')],
    migrations: [path.resolve(__dirname, './migrations/*{.ts,.js}')],
    migrationsTableName: 'typeorm_migrations',
    synchronize: false,
    migrationsRun: false,
    // Ogni connessione ragiona in ora di Roma, come il vecchio PHP.
    extra: { options: '-c timezone=Europe/Rome' },
  };
}

export default new DataSource(opzioniDatabase());
