import { Module } from '@nestjs/common';
import { PresenzeExportService } from './presenze-export.service';
import { TimbraturaService } from './timbratura.service';

/** Presenze: export mensile per il consulente paghe. */
@Module({ providers: [PresenzeExportService, TimbraturaService], exports: [PresenzeExportService, TimbraturaService] })
export class PresenzeModule {}
