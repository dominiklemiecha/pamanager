// Generato da scripts/genera-entita.js dallo schema: non modificare a mano.
import { Column, Entity, PrimaryColumn } from 'typeorm';

@Entity({ name: 'sessions' })
export class Sessions {
  @PrimaryColumn({ name: 'id', type: 'citext' })
  id!: string;

  @Column({ name: 'user_id', type: 'integer', nullable: true })
  userId!: number | null;

  @Column({ name: 'employee_id', type: 'integer', nullable: true })
  employeeId!: number | null;

  @Column({ name: 'ip_address', type: 'citext' })
  ipAddress!: string;

  @Column({ name: 'user_agent', type: 'text', nullable: true })
  userAgent!: string | null;

  @Column({ name: 'payload', type: 'text' })
  payload!: string;

  @Column({ name: 'last_activity', type: 'integer' })
  lastActivity!: number;

  @Column({ name: 'created_at', type: 'timestamptz', nullable: true })
  createdAt!: Date | null;
}
