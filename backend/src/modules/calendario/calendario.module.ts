import { Module } from '@nestjs/common';
import { ContattiModule } from '../contatti/contatti.module';
import { CalendarioController } from './calendario.controller';
import { CalendarioService } from './calendario.service';
import { GiornateService } from './giornate.service';

@Module({ imports: [ContattiModule], controllers: [CalendarioController], providers: [CalendarioService, GiornateService] })
export class CalendarioModule {}
