// Generato da scripts/genera-entita.js dallo schema: non modificare a mano.
import { Column, Entity, PrimaryGeneratedColumn } from 'typeorm';

@Entity({ name: 'security_alerts' })
export class SecurityAlerts {
  @PrimaryGeneratedColumn({ name: 'id', type: 'integer' })
  id!: number;

  @Column({ name: 'alert_type', type: 'citext' })
  alertType!: string;

  @Column({ name: 'user_type', type: 'varchar', length: 10 })
  userType!: string;

  @Column({ name: 'user_id', type: 'integer' })
  userId!: number;

  @Column({ name: 'details', type: 'json', nullable: true })
  details!: string | null;

  @Column({ name: 'ip_address', type: 'citext', nullable: true })
  ipAddress!: string | null;

  @Column({ name: 'is_resolved', type: 'boolean', nullable: true })
  isResolved!: boolean | null;

  @Column({ name: 'resolved_by', type: 'integer', nullable: true })
  resolvedBy!: number | null;

  @Column({ name: 'resolved_at', type: 'timestamptz', nullable: true })
  resolvedAt!: Date | null;

  @Column({ name: 'resolution_notes', type: 'text', nullable: true })
  resolutionNotes!: string | null;

  @Column({ name: 'created_at', type: 'timestamptz', nullable: true })
  createdAt!: Date | null;
}
