// Per primo: toglie gli spazi intorno ai valori delle variabili d'ambiente.
import './common/config/pulisci-env';
import { attivaIsolamento, contestoRichiesta } from './common/database/isolamento';
import { Logger, ValidationPipe } from '@nestjs/common';
import { NestFactory } from '@nestjs/core';
import cookieParser = require('cookie-parser');
import { json, urlencoded } from 'express';
import helmet from 'helmet';
import { DataSource } from 'typeorm';
import { AppModule } from './app.module';
import { bodyGuard } from './common/middleware/body-guard.middleware';
import { limiteRichieste } from './common/middleware/limite-richieste.middleware';
import { getEnvironmentConfig, nomeAmbiente, verificaAmbiente } from './config/environments';

async function bootstrap() {
  // Prima di aprire qualsiasi connessione: niente database di produzione fuori da production.
  verificaAmbiente();

  const app = await NestFactory.create(AppModule, { bodyParser: false });
  const logger = new Logger('Avvio');
  const ds = app.get(DataSource);

  if (String(process.env.RUN_MIGRATIONS).toLowerCase() === 'true') {
    const eseguite = await ds.runMigrations({ transaction: 'each' });
    logger.log(`Migrazioni applicate: ${eseguite.length}`);
  }

  // Dietro Traefik: l'IP del client e il protocollo arrivano dagli header del proxy.
  app.getHttpAdapter().getInstance().set('trust proxy', 1);
  app.use(helmet());
  app.use(cookieParser());
  // Tentativi a raffica su login, recuperi, API esterne e link pubblici (vedi limite-richieste).
  app.use(limiteRichieste);
  app.use(json({ limit: '15mb' }));
  app.use(urlencoded({ extended: true, limit: '15mb' }));
  // Il client non decide mai azienda, id della riga e date di sistema (vedi body-guard).
  app.use(bodyGuard);
  // Contesto della richiesta per l'isolamento nel database (dopo i parser del corpo).
  app.use(contestoRichiesta);
  await attivaIsolamento(ds);

  const envConfig = getEnvironmentConfig();
  const origine = process.env.FRONTEND_URL || envConfig.corsOrigin;
  if (origine) app.enableCors({ origin: origine, credentials: true });

  app.setGlobalPrefix('api/v1');
  app.useGlobalPipes(new ValidationPipe({ whitelist: true, transform: true }));

  const port = process.env.APP_PORT || 3100;
  await app.listen(port);
  logger.log(`Cora API (${nomeAmbiente()}) in ascolto sulla porta ${port}`);
}

bootstrap();
