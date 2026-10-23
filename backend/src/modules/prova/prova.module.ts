import { Module } from '@nestjs/common';
import { ProvaService } from './prova.service';

@Module({ providers: [ProvaService], exports: [ProvaService] })
export class ProvaModule {}
