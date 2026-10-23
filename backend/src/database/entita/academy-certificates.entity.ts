// Generato da scripts/genera-entita.js dallo schema: non modificare a mano.
import { Column, Entity, PrimaryGeneratedColumn } from 'typeorm';

@Entity({ name: 'academy_certificates' })
export class AcademyCertificates {
  @PrimaryGeneratedColumn({ name: 'id', type: 'integer' })
  id!: number;

  @Column({ name: 'company_id', type: 'integer' })
  companyId!: number;

  @Column({ name: 'assignment_id', type: 'integer' })
  assignmentId!: number;

  @Column({ name: 'number', type: 'citext' })
  number!: string;

  @Column({ name: 'seq_year', type: 'integer' })
  seqYear!: number;

  @Column({ name: 'seq_number', type: 'integer' })
  seqNumber!: number;

  @Column({ name: 'issued_at', type: 'timestamptz' })
  issuedAt!: Date;

  @Column({ name: 'issued_by_user_id', type: 'integer', nullable: true })
  issuedByUserId!: number | null;

  @Column({ name: 'file_path', type: 'citext' })
  filePath!: string;

  @Column({ name: 'sha256', type: 'citext' })
  sha256!: string;

  @Column({ name: 'verify_token', type: 'citext' })
  verifyToken!: string;

  @Column({ name: 'employee_downloaded_at', type: 'timestamptz', nullable: true })
  employeeDownloadedAt!: Date | null;
}
