import { Module } from '@nestjs/common';
import { ImpostazioniService, VisibilitaCampiService } from './impostazioni.service';

/** Impostazioni per azienda e visibilita' dei campi anagrafici. */
@Module({ providers: [ImpostazioniService, VisibilitaCampiService], exports: [ImpostazioniService, VisibilitaCampiService] })
export class ImpostazioniModule {}
