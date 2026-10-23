// Generato da scripts/genera-entita.js dallo schema: non modificare a mano.
import { Column, Entity, PrimaryGeneratedColumn } from 'typeorm';
import { numero } from './numero';

@Entity({ name: 'hire_requests' })
export class HireRequests {
  @PrimaryGeneratedColumn({ name: 'id', type: 'integer' })
  id!: number;

  @Column({ name: 'company_id', type: 'integer' })
  companyId!: number;

  @Column({ name: 'status', type: 'varchar', length: 18 })
  status!: string;

  @Column({ name: 'created_by_user_id', type: 'integer' })
  createdByUserId!: number;

  @Column({ name: 'assigned_consulente_user_id', type: 'integer', nullable: true })
  assignedConsulenteUserId!: number | null;

  @Column({ name: 'employer_name', type: 'citext' })
  employerName!: string;

  @Column({ name: 'employee_first_name', type: 'citext', nullable: true })
  employeeFirstName!: string | null;

  @Column({ name: 'employee_last_name', type: 'citext', nullable: true })
  employeeLastName!: string | null;

  @Column({ name: 'employee_birth_date', type: 'date', nullable: true })
  employeeBirthDate!: string | null;

  @Column({ name: 'birth_state', type: 'citext', nullable: true })
  birthState!: string | null;

  @Column({ name: 'birth_city', type: 'citext', nullable: true })
  birthCity!: string | null;

  @Column({ name: 'fiscal_code', type: 'citext', nullable: true })
  fiscalCode!: string | null;

  @Column({ name: 'residence_address', type: 'citext', nullable: true })
  residenceAddress!: string | null;

  @Column({ name: 'residence_cap', type: 'citext', nullable: true })
  residenceCap!: string | null;

  @Column({ name: 'residence_city', type: 'citext', nullable: true })
  residenceCity!: string | null;

  @Column({ name: 'residence_province', type: 'citext', nullable: true })
  residenceProvince!: string | null;

  @Column({ name: 'marital_status', type: 'varchar', length: 13, nullable: true })
  maritalStatus!: string | null;

  @Column({ name: 'education_level', type: 'varchar', length: 18, nullable: true })
  educationLevel!: string | null;

  @Column({ name: 'contract_indeterminato', type: 'boolean' })
  contractIndeterminato!: boolean;

  @Column({ name: 'contract_determinato', type: 'boolean' })
  contractDeterminato!: boolean;

  @Column({ name: 'contract_apprendistato', type: 'boolean' })
  contractApprendistato!: boolean;

  @Column({ name: 'contract_tirocinio', type: 'boolean' })
  contractTirocinio!: boolean;

  @Column({ name: 'contract_agevolata', type: 'boolean' })
  contractAgevolata!: boolean;

  @Column({ name: 'start_date', type: 'date', nullable: true })
  startDate!: string | null;

  @Column({ name: 'end_date', type: 'date', nullable: true })
  endDate!: string | null;

  @Column({ name: 'role_description', type: 'text', nullable: true })
  roleDescription!: string | null;

  @Column({ name: 'weekly_hours', type: 'numeric', precision: 5, scale: 2, transformer: numero, nullable: true })
  weeklyHours!: number | null;

  @Column({ name: 'work_days', type: 'varchar', length: 27, nullable: true })
  workDays!: string | null;

  @Column({ name: 'workplace', type: 'citext', nullable: true })
  workplace!: string | null;

  @Column({ name: 'cost_center', type: 'citext', nullable: true })
  costCenter!: string | null;

  @Column({ name: 'iban', type: 'citext', nullable: true })
  iban!: string | null;

  @Column({ name: 'employee_email', type: 'citext', nullable: true })
  employeeEmail!: string | null;

  @Column({ name: 'personal_email', type: 'citext', nullable: true })
  personalEmail!: string | null;

  @Column({ name: 'generated_username', type: 'citext', nullable: true })
  generatedUsername!: string | null;

  @Column({ name: 'notes', type: 'text', nullable: true })
  notes!: string | null;

  @Column({ name: 'last_sent_at', type: 'timestamptz', nullable: true })
  lastSentAt!: Date | null;

  @Column({ name: 'send_count', type: 'integer' })
  sendCount!: number;

  @Column({ name: 'updated_after_send', type: 'boolean' })
  updatedAfterSend!: boolean;

  @Column({ name: 'rejection_reason', type: 'text', nullable: true })
  rejectionReason!: string | null;

  @Column({ name: 'decided_at', type: 'timestamptz', nullable: true })
  decidedAt!: Date | null;

  @Column({ name: 'decided_by_user_id', type: 'integer', nullable: true })
  decidedByUserId!: number | null;

  @Column({ name: 'employee_id', type: 'integer', nullable: true })
  employeeId!: number | null;

  @Column({ name: 'created_at', type: 'timestamptz' })
  createdAt!: Date;

  @Column({ name: 'updated_at', type: 'timestamptz' })
  updatedAt!: Date;
}
