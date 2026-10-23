// Generato da scripts/genera-entita.js dallo schema: non modificare a mano.
import { Column, Entity, PrimaryGeneratedColumn } from 'typeorm';

@Entity({ name: 'audit_log' })
export class AuditLog {
  @PrimaryGeneratedColumn({ name: 'id', type: 'integer' })
  id!: number;

  @Column({ name: 'user_type', type: 'citext', nullable: true })
  userType!: string | null;

  @Column({ name: 'user_id', type: 'integer' })
  userId!: number;

  @Column({ name: 'action', type: 'citext' })
  action!: string;

  @Column({ name: 'entity_type', type: 'citext', nullable: true })
  entityType!: string | null;

  @Column({ name: 'entity_id', type: 'integer', nullable: true })
  entityId!: number | null;

  @Column({ name: 'old_values', type: 'json', nullable: true })
  oldValues!: string | null;

  @Column({ name: 'new_values', type: 'json', nullable: true })
  newValues!: string | null;

  @Column({ name: 'ip_address', type: 'citext', nullable: true })
  ipAddress!: string | null;

  @Column({ name: 'user_agent', type: 'text', nullable: true })
  userAgent!: string | null;

  @Column({ name: 'severity', type: 'varchar', length: 8, nullable: true })
  severity!: string | null;

  @Column({ name: 'session_id', type: 'citext', nullable: true })
  sessionId!: string | null;

  @Column({ name: 'created_at', type: 'timestamptz', nullable: true })
  createdAt!: Date | null;
}
