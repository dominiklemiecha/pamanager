import { Module } from '@nestjs/common';
import { AuthModule } from '../auth/auth.module';
import { ContattiService } from './contatti.service';

@Module({ imports: [AuthModule], providers: [ContattiService], exports: [ContattiService] })
export class ContattiModule {}
