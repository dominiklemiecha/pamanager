import { Module } from '@nestjs/common';
import { FerieDipendenteController } from './ferie-dipendente.controller';
import { FerieService } from './ferie.service';

@Module({ controllers: [FerieDipendenteController], providers: [FerieService], exports: [FerieService] })
export class FerieModule {}
