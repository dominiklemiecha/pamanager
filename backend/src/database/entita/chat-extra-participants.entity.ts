// Generato da scripts/genera-entita.js dallo schema: non modificare a mano.
import { Column, Entity, PrimaryGeneratedColumn } from 'typeorm';

@Entity({ name: 'chat_extra_participants' })
export class ChatExtraParticipants {
  @PrimaryGeneratedColumn({ name: 'id', type: 'integer' })
  id!: number;

  @Column({ name: 'conversation_id', type: 'integer' })
  conversationId!: number;

  @Column({ name: 'participant_type', type: 'varchar', length: 17 })
  participantType!: string;

  @Column({ name: 'participant_id', type: 'integer' })
  participantId!: number;

  @Column({ name: 'added_by_type', type: 'varchar', length: 17, nullable: true })
  addedByType!: string | null;

  @Column({ name: 'added_by_id', type: 'integer', nullable: true })
  addedById!: number | null;

  @Column({ name: 'created_at', type: 'timestamptz', nullable: true })
  createdAt!: Date | null;
}
