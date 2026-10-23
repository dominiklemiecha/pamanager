// Generato da scripts/genera-entita.js dallo schema: non modificare a mano.
import { Column, Entity, PrimaryGeneratedColumn } from 'typeorm';

@Entity({ name: 'medical_certificates' })
export class MedicalCertificates {
  @PrimaryGeneratedColumn({ name: 'id', type: 'integer' })
  id!: number;

  @Column({ name: 'company_id', type: 'integer' })
  companyId!: number;

  @Column({ name: 'employee_id', type: 'integer' })
  employeeId!: number;

  @Column({ name: 'file_path', type: 'citext' })
  filePath!: string;

  @Column({ name: 'original_name', type: 'citext' })
  originalName!: string;

  @Column({ name: 'issued_at', type: 'date', nullable: true })
  issuedAt!: string | null;

  @Column({ name: 'valid_until', type: 'date', nullable: true })
  validUntil!: string | null;

  @Column({ name: 'notes', type: 'text', nullable: true })
  notes!: string | null;

  @Column({ name: 'uploaded_by_user_id', type: 'integer', nullable: true })
  uploadedByUserId!: number | null;

  @Column({ name: 'uploaded_by_employee_id', type: 'integer', nullable: true })
  uploadedByEmployeeId!: number | null;

  @Column({ name: 'created_at', type: 'timestamptz', nullable: true })
  createdAt!: Date | null;
}
