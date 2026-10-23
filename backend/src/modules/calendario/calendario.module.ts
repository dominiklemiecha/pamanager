import { Module } from '@nestjs/common';
import { ContattiModule } from '../contatti/contatti.module';
import { CalendarioController } from './calendario.controller';
import { CalendarioService } from './calendario.service';

@Module({ imports: [ContattiModule], controllers: [CalendarioController], providers: [CalendarioService] })
export class CalendarioModule {}
