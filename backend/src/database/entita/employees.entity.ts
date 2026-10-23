// Generato da scripts/genera-entita.js dallo schema: non modificare a mano.
import { Column, Entity, PrimaryGeneratedColumn } from 'typeorm';
import { numero } from './numero';

@Entity({ name: 'employees' })
export class Employees {
  @PrimaryGeneratedColumn({ name: 'id', type: 'integer' })
  id!: number;

  @Column({ name: 'company_id', type: 'integer' })
  companyId!: number;

  @Column({ name: 'username', type: 'citext' })
  username!: string;

  @Column({ name: 'password_hash', type: 'citext' })
  passwordHash!: string;

  @Column({ name: 'must_change_password', type: 'boolean' })
  mustChangePassword!: boolean;

  @Column({ name: 'fiscal_code', type: 'citext' })
  fiscalCode!: string;

  @Column({ name: 'first_name', type: 'citext' })
  firstName!: string;

  @Column({ name: 'last_name', type: 'citext' })
  lastName!: string;

  @Column({ name: 'email', type: 'citext', nullable: true })
  email!: string | null;

  @Column({ name: 'photo_path', type: 'citext', nullable: true })
  photoPath!: string | null;

  @Column({ name: 'availability_status', type: 'varchar', length: 10 })
  availabilityStatus!: string;

  @Column({ name: 'availability_set_at', type: 'timestamptz', nullable: true })
  availabilitySetAt!: Date | null;

  @Column({ name: 'mfa_secret', type: 'citext', nullable: true })
  mfaSecret!: string | null;

  @Column({ name: 'mfa_enabled', type: 'boolean', nullable: true })
  mfaEnabled!: boolean | null;

  @Column({ name: 'mfa_backup_codes', type: 'text', nullable: true })
  mfaBackupCodes!: string | null;

  @Column({ name: 'password_changed_at', type: 'timestamptz', nullable: true })
  passwordChangedAt!: Date | null;

  @Column({ name: 'phone', type: 'citext', nullable: true })
  phone!: string | null;

  @Column({ name: 'address', type: 'citext', nullable: true })
  address!: string | null;

  @Column({ name: 'birth_date', type: 'date', nullable: true })
  birthDate!: string | null;

  @Column({ name: 'job_level', type: 'citext', nullable: true })
  jobLevel!: string | null;

  @Column({ name: 'ral_amount', type: 'numeric', precision: 10, scale: 2, transformer: numero, nullable: true })
  ralAmount!: number | null;

  @Column({ name: 'monthly_salary', type: 'numeric', precision: 10, scale: 2, transformer: numero, nullable: true })
  monthlySalary!: number | null;

  @Column({ name: 'iban', type: 'citext', nullable: true })
  iban!: string | null;

  @Column({ name: 'department', type: 'citext', nullable: true })
  department!: string | null;

  @Column({ name: 'department_id', type: 'integer', nullable: true })
  departmentId!: number | null;

  @Column({ name: 'ccnl_id', type: 'integer', nullable: true })
  ccnlId!: number | null;

  @Column({ name: 'ferie_year_override', type: 'numeric', precision: 5, scale: 2, transformer: numero, nullable: true })
  ferieYearOverride!: number | null;

  @Column({ name: 'permessi_year_override', type: 'numeric', precision: 6, scale: 2, transformer: numero, nullable: true })
  permessiYearOverride!: number | null;

  @Column({ name: 'position', type: 'citext', nullable: true })
  position!: string | null;

  @Column({ name: 'hire_date', type: 'date', nullable: true })
  hireDate!: string | null;

  @Column({ name: 'probation_end_date', type: 'date', nullable: true })
  probationEndDate!: string | null;

  @Column({ name: 'probation_decision', type: 'varchar', length: 13, nullable: true })
  probationDecision!: string | null;

  @Column({ name: 'probation_decided_at', type: 'timestamptz', nullable: true })
  probationDecidedAt!: Date | null;

  @Column({ name: 'probation_decided_by_user_id', type: 'integer', nullable: true })
  probationDecidedByUserId!: number | null;

  @Column({ name: 'probation_alert_notified_at', type: 'timestamptz', nullable: true })
  probationAlertNotifiedAt!: Date | null;

  @Column({ name: 'probation_deactivated_at', type: 'timestamptz', nullable: true })
  probationDeactivatedAt!: Date | null;

  @Column({ name: 'is_active', type: 'boolean', nullable: true })
  isActive!: boolean | null;

  @Column({ name: 'created_at', type: 'timestamptz', nullable: true })
  createdAt!: Date | null;

  @Column({ name: 'updated_at', type: 'timestamptz', nullable: true })
  updatedAt!: Date | null;

  @Column({ name: 'last_login', type: 'timestamptz', nullable: true })
  lastLogin!: Date | null;

  @Column({ name: 'failed_attempts', type: 'integer', nullable: true })
  failedAttempts!: number | null;

  @Column({ name: 'locked_until', type: 'timestamptz', nullable: true })
  lockedUntil!: Date | null;

  @Column({ name: 'created_by', type: 'integer', nullable: true })
  createdBy!: number | null;

  @Column({ name: 'notify_email', type: 'boolean' })
  notifyEmail!: boolean;

  @Column({ name: 'notify_push', type: 'boolean' })
  notifyPush!: boolean;

  @Column({ name: 'email_unsubscribe_token', type: 'citext', nullable: true })
  emailUnsubscribeToken!: string | null;

  @Column({ name: 'working_days', type: 'varchar', length: 27, nullable: true })
  workingDays!: string | null;

  @Column({ name: 'hours_per_day', type: 'numeric', precision: 4, scale: 2, transformer: numero, nullable: true })
  hoursPerDay!: number | null;

  @Column({ name: 'smart_working_days', type: 'citext', nullable: true })
  smartWorkingDays!: string | null;

  @Column({ name: 'buoni_pasto_min_hours_override', type: 'numeric', precision: 4, scale: 2, transformer: numero, nullable: true })
  buoniPastoMinHoursOverride!: number | null;

  @Column({ name: 'buoni_pasto_sw_eligible_override', type: 'boolean', nullable: true })
  buoniPastoSwEligibleOverride!: boolean | null;

  @Column({ name: 'buoni_pasto_excluded', type: 'boolean' })
  buoniPastoExcluded!: boolean;

  @Column({ name: 'nfc_uid', type: 'citext', nullable: true })
  nfcUid!: string | null;

  @Column({ name: 'tour_status', type: 'varchar', length: 9, nullable: true })
  tourStatus!: string | null;

  @Column({ name: 'tour_status_at', type: 'timestamptz', nullable: true })
  tourStatusAt!: Date | null;
}
