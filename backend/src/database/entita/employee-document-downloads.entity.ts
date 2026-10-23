// Generato da scripts/genera-entita.js dallo schema: non modificare a mano.
import { Column, Entity, PrimaryGeneratedColumn } from 'typeorm';

@Entity({ name: 'employee_document_downloads' })
export class EmployeeDocumentDownloads {
  @PrimaryGeneratedColumn({ name: 'id', type: 'integer' })
  id!: number;

  @Column({ name: 'employee_document_id', type: 'integer' })
  employeeDocumentId!: number;

  @Column({ name: 'user_type', type: 'citext' })
  userType!: string;

  @Column({ name: 'user_id', type: 'integer' })
  userId!: number;

  @Column({ name: 'ip_address', type: 'citext', nullable: true })
  ipAddress!: string | null;

  @Column({ name: 'user_agent', type: 'citext', nullable: true })
  userAgent!: string | null;

  @Column({ name: 'downloaded_at', type: 'timestamptz', nullable: true })
  downloadedAt!: Date | null;
}
