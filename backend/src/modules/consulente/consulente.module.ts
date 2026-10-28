import { Module } from '@nestjs/common';
import { AuthModule } from '../auth/auth.module';
import { ChatModule } from '../chat/chat.module';
import { NotificheModule } from '../notifiche/notifiche.module';
import { StaffModule } from '../staff/staff.module';
import { ConsulenteController } from './consulente.controller';
import { ConsulenteService } from './consulente.service';

@Module({
  imports: [AuthModule, StaffModule, NotificheModule, ChatModule],
  controllers: [ConsulenteController],
  providers: [ConsulenteService],
})
export class ConsulenteModule {}
