// Generato da scripts/genera-entita.js dallo schema: non modificare a mano.
import { Column, Entity, PrimaryGeneratedColumn } from 'typeorm';

@Entity({ name: 'leave_requests' })
export class LeaveRequests {
  @PrimaryGeneratedColumn({ name: 'id', type: 'integer' })
  id!: number;

  @Column({ name: 'company_id', type: 'integer' })
  companyId!: number;

  @Column({ name: 'employee_id', type: 'integer' })
  employeeId!: number;

  @Column({ name: 'leave_type', type: 'varchar', length: 19 })
  leaveType!: string;

  @Column({ name: 'start_date', type: 'date' })
  startDate!: string;

  @Column({ name: 'end_date', type: 'date' })
  endDate!: string;

  @Column({ name: 'is_full_day', type: 'boolean', nullable: true })
  isFullDay!: boolean | null;

  @Column({ name: 'start_time', type: 'time', nullable: true })
  startTime!: string | null;

  @Column({ name: 'end_time', type: 'time', nullable: true })
  endTime!: string | null;

  @Column({ name: 'reason', type: 'text' })
  reason!: string;

  @Column({ name: 'notes', type: 'text', nullable: true })
  notes!: string | null;

  @Column({ name: 'attachment_path', type: 'citext', nullable: true })
  attachmentPath!: string | null;

  @Column({ name: 'attachment_name', type: 'citext', nullable: true })
  attachmentName!: string | null;

  @Column({ name: 'protocol_number', type: 'citext', nullable: true })
  protocolNumber!: string | null;

  @Column({ name: 'certificate_path', type: 'citext', nullable: true })
  certificatePath!: string | null;

  @Column({ name: 'certificate_name', type: 'citext', nullable: true })
  certificateName!: string | null;

  @Column({ name: 'certificate_waived', type: 'boolean' })
  certificateWaived!: boolean;

  @Column({ name: 'status', type: 'varchar', length: 9, nullable: true })
  status!: string | null;

  @Column({ name: 'created_by_admin', type: 'boolean' })
  createdByAdmin!: boolean;

  @Column({ name: 'approved_by', type: 'integer', nullable: true })
  approvedBy!: number | null;

  @Column({ name: 'approved_at', type: 'timestamptz', nullable: true })
  approvedAt!: Date | null;

  @Column({ name: 'rejection_reason', type: 'text', nullable: true })
  rejectionReason!: string | null;

  @Column({ name: 'created_at', type: 'timestamptz', nullable: true })
  createdAt!: Date | null;

  @Column({ name: 'updated_at', type: 'timestamptz', nullable: true })
  updatedAt!: Date | null;
}
