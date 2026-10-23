import { Module } from '@nestjs/common';
import { UtentiService } from './utenti.service';

/** Account dello staff (User.php). */
@Module({ providers: [UtentiService], exports: [UtentiService] })
export class UtentiModule {}
