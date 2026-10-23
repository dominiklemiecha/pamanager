// Generato da scripts/genera-entita.js dallo schema: non modificare a mano.
import { Column, Entity, PrimaryColumn } from 'typeorm';

@Entity({ name: 'chat_reads' })
export class ChatReads {
  @PrimaryColumn({ name: 'conversation_id', type: 'integer' })
  conversationId!: number;

  @PrimaryColumn({ name: 'user_type', type: 'citext' })
  userType!: string;

  @PrimaryColumn({ name: 'user_id', type: 'integer' })
  userId!: number;

  @Column({ name: 'last_read_at', type: 'timestamptz' })
  lastReadAt!: Date;
}
