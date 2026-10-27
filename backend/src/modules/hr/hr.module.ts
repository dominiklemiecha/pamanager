import { Module } from '@nestjs/common';
import { AuthModule } from '../auth/auth.module';
import { ChatModule } from '../chat/chat.module';
import { FerieModule } from '../ferie/ferie.module';
import { NotificheModule } from '../notifiche/notifiche.module';
import { HeatmapService } from '../pagine/widget/heatmap.service';
import { ProvaModule } from '../prova/prova.module';
import { StaffModule } from '../staff/staff.module';
import { HrController } from './hr.controller';
import { HrService } from './hr.service';

@Module({
  imports: [AuthModule, StaffModule, NotificheModule, FerieModule, ChatModule, ProvaModule],
  controllers: [HrController],
  providers: [HrService, HeatmapService],
})
export class HrModule {}
