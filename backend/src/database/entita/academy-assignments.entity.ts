// Generato da scripts/genera-entita.js dallo schema: non modificare a mano.
import { Column, Entity, PrimaryGeneratedColumn } from 'typeorm';

@Entity({ name: 'academy_assignments' })
export class AcademyAssignments {
  @PrimaryGeneratedColumn({ name: 'id', type: 'integer' })
  id!: number;

  @Column({ name: 'company_id', type: 'integer' })
  companyId!: number;

  @Column({ name: 'course_id', type: 'integer' })
  courseId!: number;

  @Column({ name: 'employee_id', type: 'integer' })
  employeeId!: number;

  @Column({ name: 'assigned_by_user_id', type: 'integer', nullable: true })
  assignedByUserId!: number | null;

  @Column({ name: 'assigned_at', type: 'timestamptz' })
  assignedAt!: Date;

  @Column({ name: 'notify_pending', type: 'boolean' })
  notifyPending!: boolean;

  @Column({ name: 'notify_email', type: 'boolean' })
  notifyEmail!: boolean;

  @Column({ name: 'started_at', type: 'timestamptz', nullable: true })
  startedAt!: Date | null;

  @Column({ name: 'started_ip', type: 'citext', nullable: true })
  startedIp!: string | null;

  @Column({ name: 'completed_at', type: 'timestamptz', nullable: true })
  completedAt!: Date | null;

  @Column({ name: 'completed_ip', type: 'citext', nullable: true })
  completedIp!: string | null;

  @Column({ name: 'completed_user_agent', type: 'citext', nullable: true })
  completedUserAgent!: string | null;

  @Column({ name: 'declaration_text', type: 'text', nullable: true })
  declarationText!: string | null;

  @Column({ name: 'reminder_count', type: 'integer' })
  reminderCount!: number;

  @Column({ name: 'last_reminder_at', type: 'timestamptz', nullable: true })
  lastReminderAt!: Date | null;

  @Column({ name: 'due_reminder_sent_at', type: 'timestamptz', nullable: true })
  dueReminderSentAt!: Date | null;
}
