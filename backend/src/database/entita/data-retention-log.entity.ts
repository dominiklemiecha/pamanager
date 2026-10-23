// Generato da scripts/genera-entita.js dallo schema: non modificare a mano.
import { Column, Entity, PrimaryGeneratedColumn } from 'typeorm';

@Entity({ name: 'data_retention_log' })
export class DataRetentionLog {
  @PrimaryGeneratedColumn({ name: 'id', type: 'integer' })
  id!: number;

  @Column({ name: 'entity_type', type: 'citext' })
  entityType!: string;

  @Column({ name: 'entity_id', type: 'integer', nullable: true })
  entityId!: number | null;

  @Column({ name: 'action', type: 'varchar', length: 10 })
  action!: string;

  @Column({ name: 'reason', type: 'citext', nullable: true })
  reason!: string | null;

  @Column({ name: 'old_data', type: 'json', nullable: true })
  oldData!: string | null;

  @Column({ name: 'performed_by', type: 'integer', nullable: true })
  performedBy!: number | null;

  @Column({ name: 'performed_at', type: 'timestamptz', nullable: true })
  performedAt!: Date | null;
}
