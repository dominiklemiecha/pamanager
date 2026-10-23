// Generato da scripts/genera-entita.js dallo schema: non modificare a mano.
import { Column, Entity, PrimaryGeneratedColumn } from 'typeorm';

@Entity({ name: 'employee_documents' })
export class EmployeeDocuments {
  @PrimaryGeneratedColumn({ name: 'id', type: 'integer' })
  id!: number;

  @Column({ name: 'company_id', type: 'integer' })
  companyId!: number;

  @Column({ name: 'bulk_group', type: 'citext', nullable: true })
  bulkGroup!: string | null;

  @Column({ name: 'employee_id', type: 'integer' })
  employeeId!: number;

  @Column({ name: 'name', type: 'citext' })
  name!: string;

  @Column({ name: 'file_path', type: 'citext' })
  filePath!: string;

  @Column({ name: 'file_name', type: 'citext' })
  fileName!: string;

  @Column({ name: 'original_name', type: 'citext' })
  originalName!: string;

  @Column({ name: 'file_size', type: 'integer' })
  fileSize!: number;

  @Column({ name: 'mime_type', type: 'citext' })
  mimeType!: string;

  @Column({ name: 'visible_to_employee', type: 'boolean' })
  visibleToEmployee!: boolean;

  @Column({ name: 'notify_pending', type: 'boolean' })
  notifyPending!: boolean;

  @Column({ name: 'notify_email', type: 'boolean' })
  notifyEmail!: boolean;

  @Column({ name: 'expires_on', type: 'date', nullable: true })
  expiresOn!: string | null;

  @Column({ name: 'uploaded_by', type: 'integer' })
  uploadedBy!: number;

  @Column({ name: 'created_at', type: 'timestamptz', nullable: true })
  createdAt!: Date | null;

  @Column({ name: 'updated_at', type: 'timestamptz', nullable: true })
  updatedAt!: Date | null;
}
