// Generato da scripts/genera-entita.js dallo schema: non modificare a mano.
import { Column, Entity, PrimaryGeneratedColumn } from 'typeorm';

@Entity({ name: 'chat_conversation_prefs' })
export class ChatConversationPrefs {
  @PrimaryGeneratedColumn({ name: 'id', type: 'integer' })
  id!: number;

  @Column({ name: 'conversation_id', type: 'integer' })
  conversationId!: number;

  @Column({ name: 'user_type', type: 'citext' })
  userType!: string;

  @Column({ name: 'user_id', type: 'integer' })
  userId!: number;

  @Column({ name: 'pinned_at', type: 'timestamptz', nullable: true })
  pinnedAt!: Date | null;

  @Column({ name: 'hidden_at', type: 'timestamptz', nullable: true })
  hiddenAt!: Date | null;
}
