// Generato da scripts/genera-entita.js dallo schema: non modificare a mano.
import { Column, Entity, PrimaryGeneratedColumn } from 'typeorm';

@Entity({ name: 'attendance_punches' })
export class AttendancePunches {
  @PrimaryGeneratedColumn({ name: 'id', type: 'integer' })
  id!: number;

  @Column({ name: 'company_id', type: 'integer' })
  companyId!: number;

  @Column({ name: 'employee_id', type: 'integer' })
  employeeId!: number;

  @Column({ name: 'punch_at', type: 'timestamptz' })
  punchAt!: Date;

  @Column({ name: 'kind', type: 'varchar', length: 3 })
  kind!: string;

  @Column({ name: 'source', type: 'citext' })
  source!: string;

  @Column({ name: 'ip', type: 'citext', nullable: true })
  ip!: string | null;

  @Column({ name: 'user_agent', type: 'citext', nullable: true })
  userAgent!: string | null;

  @Column({ name: 'notes', type: 'citext', nullable: true })
  notes!: string | null;

  @Column({ name: 'created_at', type: 'timestamptz' })
  createdAt!: Date;
}
