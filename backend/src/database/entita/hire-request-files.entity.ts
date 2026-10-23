// Generato da scripts/genera-entita.js dallo schema: non modificare a mano.
import { Column, Entity, PrimaryGeneratedColumn } from 'typeorm';

@Entity({ name: 'hire_request_files' })
export class HireRequestFiles {
  @PrimaryGeneratedColumn({ name: 'id', type: 'integer' })
  id!: number;

  @Column({ name: 'hire_request_id', type: 'integer' })
  hireRequestId!: number;

  @Column({ name: 'category', type: 'varchar', length: 15 })
  category!: string;

  @Column({ name: 'file_path', type: 'citext' })
  filePath!: string;

  @Column({ name: 'original_name', type: 'citext' })
  originalName!: string;

  @Column({ name: 'display_name', type: 'citext', nullable: true })
  displayName!: string | null;

  @Column({ name: 'mime_type', type: 'citext', nullable: true })
  mimeType!: string | null;

  @Column({ name: 'file_size', type: 'integer', nullable: true })
  fileSize!: number | null;

  @Column({ name: 'uploaded_by_user_id', type: 'integer', nullable: true })
  uploadedByUserId!: number | null;

  @Column({ name: 'uploaded_by_employee_id', type: 'integer', nullable: true })
  uploadedByEmployeeId!: number | null;

  @Column({ name: 'uploaded_at', type: 'timestamptz' })
  uploadedAt!: Date;

  @Column({ name: 'signed_ip', type: 'citext', nullable: true })
  signedIp!: string | null;

  @Column({ name: 'signed_user_agent', type: 'citext', nullable: true })
  signedUserAgent!: string | null;

  @Column({ name: 'signature_hash', type: 'citext', nullable: true })
  signatureHash!: string | null;
}
