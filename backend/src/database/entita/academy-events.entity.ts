// Generato da scripts/genera-entita.js dallo schema: non modificare a mano.
import { Column, Entity, PrimaryGeneratedColumn } from 'typeorm';

@Entity({ name: 'academy_events' })
export class AcademyEvents {
  @PrimaryGeneratedColumn({ name: 'id', type: 'bigint' })
  id!: string;

  @Column({ name: 'company_id', type: 'integer' })
  companyId!: number;

  @Column({ name: 'course_id', type: 'integer', nullable: true })
  courseId!: number | null;

  @Column({ name: 'assignment_id', type: 'integer', nullable: true })
  assignmentId!: number | null;

  @Column({ name: 'employee_id', type: 'integer', nullable: true })
  employeeId!: number | null;

  @Column({ name: 'material_id', type: 'integer', nullable: true })
  materialId!: number | null;

  @Column({ name: 'event', type: 'citext' })
  event!: string;

  @Column({ name: 'actor_type', type: 'citext' })
  actorType!: string;

  @Column({ name: 'actor_id', type: 'integer' })
  actorId!: number;

  @Column({ name: 'details', type: 'citext', nullable: true })
  details!: string | null;

  @Column({ name: 'ip_address', type: 'citext', nullable: true })
  ipAddress!: string | null;

  @Column({ name: 'user_agent', type: 'citext', nullable: true })
  userAgent!: string | null;

  @Column({ name: 'created_at', type: 'timestamptz' })
  createdAt!: Date;

  @Column({ name: 'prev_hash', type: 'citext', nullable: true })
  prevHash!: string | null;

  @Column({ name: 'hash', type: 'citext' })
  hash!: string;
}
