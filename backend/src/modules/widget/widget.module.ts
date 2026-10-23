import { Global, Module } from '@nestjs/common';
import { WidgetController } from './widget.controller';
import { WidgetService } from './widget.service';

@Global()
@Module({ controllers: [WidgetController], providers: [WidgetService], exports: [WidgetService] })
export class WidgetModule {}
