// Generato da scripts/genera-entita.js dallo schema: non modificare a mano.
import { Column, Entity, PrimaryGeneratedColumn } from 'typeorm';

@Entity({ name: 'chat_conversations' })
export class ChatConversations {
  @PrimaryGeneratedColumn({ name: 'id', type: 'integer' })
  id!: number;

  @Column({ name: 'company_id', type: 'integer' })
  companyId!: number;

  @Column({ name: 'participant1_type', type: 'varchar', length: 17 })
  participant1Type!: string;

  @Column({ name: 'participant1_id', type: 'integer' })
  participant1Id!: number;

  @Column({ name: 'participant2_type', type: 'varchar', length: 17 })
  participant2Type!: string;

  @Column({ name: 'participant2_id', type: 'integer' })
  participant2Id!: number;

  @Column({ name: 'participant3_type', type: 'varchar', length: 17, nullable: true })
  participant3Type!: string | null;

  @Column({ name: 'participant3_id', type: 'integer', nullable: true })
  participant3Id!: number | null;

  @Column({ name: 'last_message_at', type: 'timestamptz', nullable: true })
  lastMessageAt!: Date | null;

  @Column({ name: 'created_at', type: 'timestamptz', nullable: true })
  createdAt!: Date | null;

  @Column({ name: 'conv_type', type: 'varchar', length: 6 })
  convType!: string;

  @Column({ name: 'name', type: 'citext', nullable: true })
  name!: string | null;

  @Column({ name: 'avatar_path', type: 'citext', nullable: true })
  avatarPath!: string | null;

  @Column({ name: 'created_by', type: 'citext', nullable: true })
  createdBy!: string | null;

  @Column({ name: 'group_key', type: 'citext' })
  groupKey!: string;
}
