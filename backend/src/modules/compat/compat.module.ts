import { Module } from '@nestjs/common';
import { DipendenteModule } from '../dipendente/dipendente.module';
import { CompatController } from './compat.controller';

@Module({ imports: [DipendenteModule], controllers: [CompatController] })
export class CompatModule {}

/** Percorsi /api/*.php fuori dal prefisso api/v1 (stessi indirizzi del PHP). */
export const PERCORSI_COMPAT = ['api/employee-tour.php', 'api/availability.php'];
