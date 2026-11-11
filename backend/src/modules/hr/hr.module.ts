import { Module } from '@nestjs/common';
import { AnagraficaModule } from '../anagrafica/anagrafica.module';
import { AuthModule } from '../auth/auth.module';
import { ChatModule } from '../chat/chat.module';
import { ComunicazioniModule } from '../comunicazioni/comunicazioni.module';
import { DocumentiModule } from '../documenti/documenti.module';
import { FerieModule } from '../ferie/ferie.module';
import { NotificheModule } from '../notifiche/notifiche.module';
import { HeatmapService } from '../pagine/widget/heatmap.service';
import { PresenzeModule } from '../presenze/presenze.module';
import { ProvaModule } from '../prova/prova.module';
import { StaffModule } from '../staff/staff.module';
import { HrController } from './hr.controller';
import { HrService } from './hr.service';

@Module({
  imports: [AnagraficaModule, AuthModule, StaffModule, NotificheModule, FerieModule, ChatModule, ProvaModule, ComunicazioniModule, DocumentiModule, PresenzeModule],
  controllers: [HrController],
  providers: [HrService, HeatmapService],
})
export class HrModule {}
