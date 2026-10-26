import { Module } from '@nestjs/common';
import { AcademyDipendenteController, AcademyFileController } from './academy.controller';
import { AcademyStaffService } from './academy-staff.service';
import { AcademyService } from './academy.service';
import { AttestatiService } from './attestati.service';

@Module({ controllers: [AcademyDipendenteController, AcademyFileController], providers: [AcademyService, AcademyStaffService, AttestatiService], exports: [AcademyService, AcademyStaffService, AttestatiService] })
export class AcademyModule {}
