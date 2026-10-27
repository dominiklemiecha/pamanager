import { Module } from '@nestjs/common';
import { ImpostazioniService } from './impostazioni.service';

/** Impostazioni per azienda. */
@Module({ providers: [ImpostazioniService], exports: [ImpostazioniService] })
export class ImpostazioniModule {}
