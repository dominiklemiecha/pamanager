import { Module } from '@nestjs/common';
import { DisiscrizioneController } from './disiscrizione.controller';
import { FotoController } from './foto.controller';
import { ProfiloController } from './profilo.controller';
import { ProfiloService } from './profilo.service';

@Module({ controllers: [ProfiloController, FotoController, DisiscrizioneController], providers: [ProfiloService] })
export class ProfiloModule {}
