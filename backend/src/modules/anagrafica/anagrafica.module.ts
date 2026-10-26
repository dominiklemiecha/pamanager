import { Module } from '@nestjs/common';
import { AuthModule } from '../auth/auth.module';
import { AnagraficaService } from './anagrafica.service';

/** Anagrafica dei dipendenti lato HR. */
@Module({ imports: [AuthModule], providers: [AnagraficaService], exports: [AnagraficaService] })
export class AnagraficaModule {}
