import { Module } from '@nestjs/common';
import { ConfigModule } from '@nestjs/config';
import { TypeOrmModule } from '@nestjs/typeorm';
import { getEnvironmentConfig } from './config/environments';
import { opzioniDatabase } from './database/data-source';
import { AuditModule } from './modules/audit/audit.module';
import { AuthModule } from './modules/auth/auth.module';
import { CompatModule } from './modules/compat/compat.module';
import { DipendenteModule } from './modules/dipendente/dipendente.module';
import { HealthModule } from './modules/health/health.module';
import { NotificheModule } from './modules/notifiche/notifiche.module';
import { SaldiModule } from './modules/saldi/saldi.module';

@Module({
  imports: [
    ConfigModule.forRoot({ isGlobal: true, envFilePath: '.env' }),
    TypeOrmModule.forRootAsync({
      useFactory: () => ({
        ...opzioniDatabase(),
        // Le entita' si registrano dai moduli (forFeature), come nel CRM.
        entities: [],
        autoLoadEntities: true,
        logging: getEnvironmentConfig().logging,
      }),
    }),
    AuditModule,
    AuthModule,
    HealthModule,
    SaldiModule,
    NotificheModule,
    DipendenteModule,
    CompatModule,
  ],
})
export class AppModule {}
