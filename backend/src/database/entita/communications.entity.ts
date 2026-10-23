// Generato da scripts/genera-entita.js dallo schema: non modificare a mano.
import { Column, Entity, PrimaryGeneratedColumn } from 'typeorm';

@Entity({ name: 'communications' })
export class Communications {
  @PrimaryGeneratedColumn({ name: 'id', type: 'integer' })
  id!: number;

  @Column({ name: 'company_id', type: 'integer' })
  companyId!: number;

  @Column({ name: 'title', type: 'citext' })
  title!: string;

  @Column({ name: 'content', type: 'text' })
  content!: string;

  @Column({ name: 'priority', type: 'varchar', length: 6, nullable: true })
  priority!: string | null;

  @Column({ name: 'is_published', type: 'boolean', nullable: true })
  isPublished!: boolean | null;

  @Column({ name: 'publish_date', type: 'date' })
  publishDate!: string;

  @Column({ name: 'expire_date', type: 'date', nullable: true })
  expireDate!: string | null;

  @Column({ name: 'attachment_path', type: 'citext', nullable: true })
  attachmentPath!: string | null;

  @Column({ name: 'attachment_name', type: 'citext', nullable: true })
  attachmentName!: string | null;

  @Column({ name: 'is_global', type: 'boolean', nullable: true })
  isGlobal!: boolean | null;

  @Column({ name: 'department_id', type: 'integer', nullable: true })
  departmentId!: number | null;

  @Column({ name: 'created_by', type: 'integer' })
  createdBy!: number;

  @Column({ name: 'created_at', type: 'timestamptz', nullable: true })
  createdAt!: Date | null;

  @Column({ name: 'updated_at', type: 'timestamptz', nullable: true })
  updatedAt!: Date | null;
}
