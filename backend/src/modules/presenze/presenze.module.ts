import { Module } from '@nestjs/common';
import { PresenzeExportService } from './presenze-export.service';

/** Presenze: export mensile per il consulente paghe. */
@Module({ providers: [PresenzeExportService], exports: [PresenzeExportService] })
export class PresenzeModule {}
