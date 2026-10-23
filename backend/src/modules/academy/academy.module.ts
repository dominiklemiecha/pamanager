import { Module } from '@nestjs/common';
import { AcademyDipendenteController, AcademyFileController } from './academy.controller';
import { AcademyService } from './academy.service';

@Module({ controllers: [AcademyDipendenteController, AcademyFileController], providers: [AcademyService], exports: [AcademyService] })
export class AcademyModule {}
