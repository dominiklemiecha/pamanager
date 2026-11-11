import { Module } from '@nestjs/common';
import { AuthModule } from '../auth/auth.module';
import { AnagraficaService } from './anagrafica.service';
import { AndipService } from './andip.service';

/** Anagrafica dei dipendenti lato HR. */
@Module({ imports: [AuthModule], providers: [AnagraficaService, AndipService], exports: [AnagraficaService, AndipService] })
export class AnagraficaModule {}
