// Generato da scripts/genera-entita.js dallo schema: non modificare a mano.
import { Column, Entity, PrimaryGeneratedColumn } from 'typeorm';

@Entity({ name: 'chat_messages' })
export class ChatMessages {
  @PrimaryGeneratedColumn({ name: 'id', type: 'integer' })
  id!: number;

  @Column({ name: 'company_id', type: 'integer' })
  companyId!: number;

  @Column({ name: 'conversation_id', type: 'integer' })
  conversationId!: number;

  @Column({ name: 'sender_type', type: 'varchar', length: 17 })
  senderType!: string;

  @Column({ name: 'sender_id', type: 'integer' })
  senderId!: number;

  @Column({ name: 'message', type: 'text' })
  message!: string;

  @Column({ name: 'attachment_path', type: 'citext', nullable: true })
  attachmentPath!: string | null;

  @Column({ name: 'attachment_name', type: 'citext', nullable: true })
  attachmentName!: string | null;

  @Column({ name: 'is_read', type: 'boolean', nullable: true })
  isRead!: boolean | null;

  @Column({ name: 'is_internal', type: 'boolean' })
  isInternal!: boolean;

  @Column({ name: 'created_at', type: 'timestamptz', nullable: true })
  createdAt!: Date | null;

  @Column({ name: 'attachment_size', type: 'integer', nullable: true })
  attachmentSize!: number | null;

  @Column({ name: 'attachment_mime', type: 'citext', nullable: true })
  attachmentMime!: string | null;

  @Column({ name: 'reply_to_id', type: 'integer', nullable: true })
  replyToId!: number | null;

  @Column({ name: 'edited_at', type: 'timestamptz', nullable: true })
  editedAt!: Date | null;

  @Column({ name: 'forwarded', type: 'boolean' })
  forwarded!: boolean;

  @Column({ name: 'reactions', type: 'text', nullable: true })
  reactions!: string | null;

  @Column({ name: 'read_at', type: 'timestamptz', nullable: true })
  readAt!: Date | null;

  @Column({ name: 'client_id', type: 'citext', nullable: true })
  clientId!: string | null;

  @Column({ name: 'mentions', type: 'text', nullable: true })
  mentions!: string | null;
}
