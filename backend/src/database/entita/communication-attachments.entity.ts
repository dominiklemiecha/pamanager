// Generato da scripts/genera-entita.js dallo schema: non modificare a mano.
import { Column, Entity, PrimaryGeneratedColumn } from 'typeorm';

@Entity({ name: 'communication_attachments' })
export class CommunicationAttachments {
  @PrimaryGeneratedColumn({ name: 'id', type: 'integer' })
  id!: number;

  @Column({ name: 'communication_id', type: 'integer' })
  communicationId!: number;

  @Column({ name: 'original_name', type: 'citext' })
  originalName!: string;

  @Column({ name: 'stored_name', type: 'citext' })
  storedName!: string;

  @Column({ name: 'mime_type', type: 'citext' })
  mimeType!: string;

  @Column({ name: 'size_bytes', type: 'integer' })
  sizeBytes!: number;

  @Column({ name: 'is_inline_image', type: 'boolean' })
  isInlineImage!: boolean;

  @Column({ name: 'created_at', type: 'timestamptz' })
  createdAt!: Date;
}
