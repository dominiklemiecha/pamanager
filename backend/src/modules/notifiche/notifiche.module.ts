import { Global, Module } from '@nestjs/common';
import { MailerService } from './mailer.service';
import { NotificheService } from './notifiche.service';
import { PushService } from './push.service';

@Global()
@Module({ providers: [MailerService, PushService, NotificheService], exports: [MailerService, PushService, NotificheService] })
export class NotificheModule {}
