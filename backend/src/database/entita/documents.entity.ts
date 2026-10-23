// Generato da scripts/genera-entita.js dallo schema: non modificare a mano.
import { Column, Entity, PrimaryGeneratedColumn } from 'typeorm';

@Entity({ name: 'documents' })
export class Documents {
  @PrimaryGeneratedColumn({ name: 'id', type: 'integer' })
  id!: number;

  @Column({ name: 'company_id', type: 'integer' })
  companyId!: number;

  @Column({ name: 'employee_id', type: 'integer' })
  employeeId!: number;

  @Column({ name: 'type', type: 'varchar', length: 7 })
  type!: string;

  @Column({ name: 'title', type: 'citext' })
  title!: string;

  @Column({ name: 'description', type: 'text', nullable: true })
  description!: string | null;

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

  @Column({ name: 'month', type: 'smallint' })
  month!: number;

  @Column({ name: 'year', type: 'smallint' })
  year!: number;

  @Column({ name: 'uploaded_by', type: 'integer' })
  uploadedBy!: number;

  @Column({ name: 'created_at', type: 'timestamptz', nullable: true })
  createdAt!: Date | null;

  @Column({ name: 'notify_employee', type: 'boolean' })
  notifyEmployee!: boolean;
}
