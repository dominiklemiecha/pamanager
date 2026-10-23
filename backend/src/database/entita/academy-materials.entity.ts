// Generato da scripts/genera-entita.js dallo schema: non modificare a mano.
import { Column, Entity, PrimaryGeneratedColumn } from 'typeorm';

@Entity({ name: 'academy_materials' })
export class AcademyMaterials {
  @PrimaryGeneratedColumn({ name: 'id', type: 'integer' })
  id!: number;

  @Column({ name: 'company_id', type: 'integer' })
  companyId!: number;

  @Column({ name: 'course_id', type: 'integer' })
  courseId!: number;

  @Column({ name: 'kind', type: 'varchar', length: 4 })
  kind!: string;

  @Column({ name: 'title', type: 'citext' })
  title!: string;

  @Column({ name: 'file_path', type: 'citext', nullable: true })
  filePath!: string | null;

  @Column({ name: 'original_name', type: 'citext', nullable: true })
  originalName!: string | null;

  @Column({ name: 'mime_type', type: 'citext', nullable: true })
  mimeType!: string | null;

  @Column({ name: 'file_size', type: 'integer', nullable: true })
  fileSize!: number | null;

  @Column({ name: 'url', type: 'citext', nullable: true })
  url!: string | null;

  @Column({ name: 'sort_order', type: 'integer' })
  sortOrder!: number;

  @Column({ name: 'created_at', type: 'timestamptz', nullable: true })
  createdAt!: Date | null;
}
