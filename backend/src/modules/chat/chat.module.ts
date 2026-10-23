import { Module } from '@nestjs/common';
import { AuthModule } from '../auth/auth.module';
import { ContattiModule } from '../contatti/contatti.module';
import { WrikeModule } from '../wrike/wrike.module';
import { ChatAzioniService } from './chat-azioni.service';
import { ChatController } from './chat.controller';
import { ChatService } from './chat.service';
import { RealtimeService } from './realtime.service';

@Module({ imports: [AuthModule, ContattiModule, WrikeModule], controllers: [ChatController], providers: [ChatService, RealtimeService, ChatAzioniService], exports: [ChatService, RealtimeService, ChatAzioniService] })
export class ChatModule {}
