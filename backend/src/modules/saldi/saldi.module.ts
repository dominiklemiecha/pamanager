import { Global, Module } from '@nestjs/common';
import { SaldiService } from './saldi.service';

@Global()
@Module({ providers: [SaldiService], exports: [SaldiService] })
export class SaldiModule {}
