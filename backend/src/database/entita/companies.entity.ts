// Generato da scripts/genera-entita.js dallo schema: non modificare a mano.
import { Column, Entity, PrimaryGeneratedColumn } from 'typeorm';
import { numero } from './numero';

@Entity({ name: 'companies' })
export class Companies {
  @PrimaryGeneratedColumn({ name: 'id', type: 'integer' })
  id!: number;

  @Column({ name: 'name', type: 'citext' })
  name!: string;

  @Column({ name: 'slug', type: 'citext', nullable: true })
  slug!: string | null;

  @Column({ name: 'logo_path', type: 'citext', nullable: true })
  logoPath!: string | null;

  @Column({ name: 'needs_setup', type: 'boolean' })
  needsSetup!: boolean;

  @Column({ name: 'is_active', type: 'boolean' })
  isActive!: boolean;

  @Column({ name: 'created_at', type: 'timestamptz', nullable: true })
  createdAt!: Date | null;

  @Column({ name: 'working_days', type: 'varchar', length: 27 })
  workingDays!: string;

  @Column({ name: 'hours_per_day', type: 'numeric', precision: 4, scale: 2, transformer: numero })
  hoursPerDay!: number;

  @Column({ name: 'smart_working_days', type: 'citext', nullable: true })
  smartWorkingDays!: string | null;

  @Column({ name: 'lunch_break_start', type: 'time', nullable: true })
  lunchBreakStart!: string | null;

  @Column({ name: 'lunch_break_end', type: 'time', nullable: true })
  lunchBreakEnd!: string | null;

  @Column({ name: 'buoni_pasto_enabled', type: 'boolean' })
  buoniPastoEnabled!: boolean;

  @Column({ name: 'buoni_pasto_min_hours_enabled', type: 'boolean' })
  buoniPastoMinHoursEnabled!: boolean;

  @Column({ name: 'buoni_pasto_min_hours', type: 'numeric', precision: 4, scale: 2, transformer: numero })
  buoniPastoMinHours!: number;

  @Column({ name: 'buoni_pasto_sw_eligible', type: 'boolean' })
  buoniPastoSwEligible!: boolean;

  @Column({ name: 'default_ccnl_id', type: 'integer', nullable: true })
  defaultCcnlId!: number | null;

  @Column({ name: 'timbratura_enabled', type: 'boolean' })
  timbraturaEnabled!: boolean;

  @Column({ name: 'door_enabled', type: 'boolean' })
  doorEnabled!: boolean;

  @Column({ name: 'door_api_key', type: 'citext', nullable: true })
  doorApiKey!: string | null;

  @Column({ name: 'door_open_duration_ms', type: 'integer' })
  doorOpenDurationMs!: number;
}
