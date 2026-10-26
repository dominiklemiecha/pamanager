import { Module } from '@nestjs/common';
import { ConfigModule } from '@nestjs/config';
import { TypeOrmModule } from '@nestjs/typeorm';
import { getEnvironmentConfig } from './config/environments';
import { opzioniDatabase } from './database/data-source';
import { AuditModule } from './modules/audit/audit.module';
import { AuthModule } from './modules/auth/auth.module';
import { CompatModule } from './modules/compat/compat.module';
import { SuperadminModule } from './modules/superadmin/superadmin.module';
import { PagineModule } from './modules/pagine/pagine.module';
import { StaffModule } from './modules/staff/staff.module';
import { WidgetModule } from './modules/widget/widget.module';
import { ContrattoModule } from './modules/contratto/contratto.module';
import { AcademyModule } from './modules/academy/academy.module';
import { ChatModule } from './modules/chat/chat.module';
import { CalendarioModule } from './modules/calendario/calendario.module';
import { ComunicazioniModule } from './modules/comunicazioni/comunicazioni.module';
import { DocumentiModule } from './modules/documenti/documenti.module';
import { ProfiloModule } from './modules/profilo/profilo.module';
import { DipendenteModule } from './modules/dipendente/dipendente.module';
import { FerieModule } from './modules/ferie/ferie.module';
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
    SuperadminModule,
    FerieModule,
    DocumentiModule,
    ComunicazioniModule,
    ProfiloModule,
    CalendarioModule,
    ChatModule,
    AcademyModule,
    ContrattoModule,
    WidgetModule,
    StaffModule,
    PagineModule,
  ],
})
export class AppModule {}
