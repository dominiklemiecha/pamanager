// Generato da scripts/genera-entita.js dallo schema: non modificare a mano.
import { Column, Entity, PrimaryGeneratedColumn } from 'typeorm';

@Entity({ name: 'document_downloads' })
export class DocumentDownloads {
  @PrimaryGeneratedColumn({ name: 'id', type: 'integer' })
  id!: number;

  @Column({ name: 'document_id', type: 'integer' })
  documentId!: number;

  @Column({ name: 'user_type', type: 'varchar', length: 10 })
  userType!: string;

  @Column({ name: 'user_id', type: 'integer' })
  userId!: number;

  @Column({ name: 'ip_address', type: 'citext' })
  ipAddress!: string;

  @Column({ name: 'user_agent', type: 'text', nullable: true })
  userAgent!: string | null;

  @Column({ name: 'downloaded_at', type: 'timestamptz', nullable: true })
  downloadedAt!: Date | null;
}
