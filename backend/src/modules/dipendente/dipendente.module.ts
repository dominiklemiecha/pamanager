import { Module } from '@nestjs/common';
import { DipendenteController } from './dipendente.controller';
import { DipendenteService } from './dipendente.service';

@Module({ controllers: [DipendenteController], providers: [DipendenteService], exports: [DipendenteService] })
export class DipendenteModule {}
