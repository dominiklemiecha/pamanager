// Generato da scripts/genera-entita.js dallo schema: non modificare a mano.
import { Column, Entity, PrimaryGeneratedColumn } from 'typeorm';

@Entity({ name: 'users' })
export class Users {
  @PrimaryGeneratedColumn({ name: 'id', type: 'integer' })
  id!: number;

  @Column({ name: 'username', type: 'citext' })
  username!: string;

  @Column({ name: 'password_hash', type: 'citext' })
  passwordHash!: string;

  @Column({ name: 'must_change_password', type: 'boolean' })
  mustChangePassword!: boolean;

  @Column({ name: 'role', type: 'varchar', length: 17 })
  role!: string;

  @Column({ name: 'company_id', type: 'integer', nullable: true })
  companyId!: number | null;

  @Column({ name: 'employee_id', type: 'integer', nullable: true })
  employeeId!: number | null;

  @Column({ name: 'department_id', type: 'integer', nullable: true })
  departmentId!: number | null;

  @Column({ name: 'name', type: 'citext' })
  name!: string;

  @Column({ name: 'email', type: 'citext', nullable: true })
  email!: string | null;

  @Column({ name: 'photo_path', type: 'citext', nullable: true })
  photoPath!: string | null;

  @Column({ name: 'mfa_secret', type: 'citext', nullable: true })
  mfaSecret!: string | null;

  @Column({ name: 'mfa_enabled', type: 'boolean', nullable: true })
  mfaEnabled!: boolean | null;

  @Column({ name: 'mfa_backup_codes', type: 'text', nullable: true })
  mfaBackupCodes!: string | null;

  @Column({ name: 'password_changed_at', type: 'timestamptz', nullable: true })
  passwordChangedAt!: Date | null;

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
}
