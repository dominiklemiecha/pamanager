<?php
/**
 * Chat della vista Cora: dati nella stessa forma delle API della chat interna
 * del CRM Connecteed (GET /chat/conversations, /messages, /contacts), cosi'
 * cora-chat.js lavora come ChatPage.tsx.
 *
 * Le persone si identificano con "tipo:id" (employee:12, admin:3): nel
 * gestionale dipendenti e utenti stanno in due tabelle diverse.
 */
class CoraChat
{
    public const ROLE_LABELS = [
        'admin'             => 'HR',
        'admin_reparto'     => 'Responsabile di reparto',
        'accountant'        => 'Commercialista',
        'consulente_lavoro' => 'Consulente del lavoro',
        'employee'          => 'Dipendente',
    ];

    /** @var array<string, array> */
    private static array $people = [];

    /** Nome, foto e ruolo di una persona (con cache per richiesta). */
    public static function person(string $type, int $id): array
    {
        $key = $type . ':' . $id;
        if (isset(self::$people[$key])) return self::$people[$key];

        $name = Chat::getParticipantName($type, $id);
        $photo = null;
        $role = self::ROLE_LABELS[$type] ?? '';
        if ($type === 'employee') {
            $emp = Employee::getById($id);
            if ($emp) {
                $name = CoraUi::name(trim(($emp['first_name'] ?? '') . ' ' . ($emp['last_name'] ?? '')));
                if (!empty($emp['photo_path'])) $photo = PUBLIC_URL . '/' . ltrim($emp['photo_path'], '/');
                if (!empty($emp['position'])) $role = CoraUi::name((string) $emp['position']);
                elseif (!empty($emp['department_name'])) $role = (string) $emp['department_name'];
            }
        }
        return self::$people[$key] = ['id' => $key, 'name' => $name, 'avatarUrl' => $photo, 'role' => $role];
    }

    /** Contatti per "Nuova chat" e "Aggiungi persone", in ordine alfabetico. */
    public static function contacts(array $groups): array
    {
        $out = [];
        foreach ($groups as $type => $list) {
            foreach ((array) $list as $u) $out[] = self::person((string) $type, (int) $u['id']);
        }
        usort($out, fn($a, $b) => strcasecmp($a['name'], $b['name']));
        return $out;
    }

    /** Riga dell'elenco conversazioni (forma di GET /chat/conversations). */
    public static function conversation(array $c, string $userType, int $userId): array
    {
        $members = [];
        foreach ($c['others'] ?? [] as $p) $members[] = self::person($p['type'], $p['id']);
        $isGroup = ($c['conv_type'] ?? 'direct') === 'group' || count($members) > 1;
        $first = $members[0] ?? self::person((string) $c['other_type'], (int) $c['other_id']);

        $title = $first['name'];
        $avatar = $first['avatarUrl'];
        if ($isGroup) {
            $title = trim((string) ($c['name'] ?? '')) !== '' ? (string) $c['name']
                : implode(', ', array_map(fn($m) => explode(' ', $m['name'])[0], $members));
            $avatar = !empty($c['avatar_path']) ? '?group_avatar=' . (int) $c['id'] . '&v=' . substr(md5((string) $c['avatar_path']), 0, 8) : null;
        }

        $preview = trim((string) ($c['last_message'] ?? ''));
        if ($preview === '' && !empty($c['last_attachment'])) {
            $preview = preg_match('/\.(jpe?g|png|gif|webp)$/i', (string) $c['last_attachment']) ? '🖼️ Foto' : '📎 ' . $c['last_attachment'];
        }

        return [
            'id'                 => (int) $c['id'],
            'type'               => $isGroup ? 'group' : 'direct',
            'title'              => $title,
            'avatarUrl'          => $avatar,
            'members'            => $isGroup ? $members : [$first],
            'memberCount'        => count($members) + 1,
            'lastMessagePreview' => $preview !== '' ? mb_substr(preg_replace('/\s+/', ' ', $preview), 0, 300) : null,
            'lastMessageAt'      => self::iso($c['last_message_time'] ?? null),
            'unread'             => (int) ($c['unread_count'] ?? 0),
            'mentioned'          => (int) ($c['mention_count'] ?? 0) > 0,
            'pinned'             => !empty($c['pinned_at']),
            'createdBy'          => $c['created_by'] ?? null,
            'canEdit'            => Chat::canManageGroup($c, $userType, $userId),
        ];
    }

    /** Messaggi nella forma di GET /chat/conversations/:id/messages. */
    public static function messages(array $rows, string $userType, int $userId): array
    {
        if (!$rows) return [];
        $convId = (int) $rows[0]['conversation_id'];
        $conv = Database::fetchOne("SELECT * FROM chat_conversations WHERE id = ?", [$convId]);
        $reads = Chat::readsOf($convId);

        $replyIds = array_values(array_unique(array_filter(array_map(fn($m) => (int) ($m['reply_to_id'] ?? 0), $rows))));
        $replies = [];
        if ($replyIds) {
            $in = implode(',', array_fill(0, count($replyIds), '?'));
            foreach (Database::fetchAll("SELECT * FROM chat_messages WHERE id IN ($in)", $replyIds) as $r) $replies[(int) $r['id']] = $r;
        }

        $me = $userType . ':' . $userId;
        $out = [];
        foreach ($rows as $m) {
            $sender = self::person($m['sender_type'], (int) $m['sender_id']);
            $mine = $sender['id'] === $me;

            $reply = null;
            $rid = (int) ($m['reply_to_id'] ?? 0);
            if ($rid) {
                $r = $replies[$rid] ?? null;
                if ($r && !($userType === 'employee' && !empty($r['is_internal']))) {
                    $rp = self::person($r['sender_type'], (int) $r['sender_id']);
                    $body = trim((string) $r['message']) !== '' ? (string) $r['message'] : '📎 ' . ($r['attachment_name'] ?: 'Allegato');
                    $reply = ['id' => $rid, 'senderId' => $rp['id'], 'senderName' => $rp['name'], 'body' => mb_substr($body, 0, 120)];
                } else {
                    $reply = ['id' => $rid, 'senderId' => null, 'senderName' => '', 'body' => 'Messaggio eliminato'];
                }
            }

            $reactions = [];
            foreach ((json_decode((string) ($m['reactions'] ?? ''), true) ?: []) as $emoji => $who) {
                if (!$who) continue;
                $users = [];
                foreach ($who as $k) {
                    [$t, $i] = array_pad(explode(':', (string) $k, 2), 2, 0);
                    $users[] = self::person($t, (int) $i)['name'];
                }
                $reactions[] = ['emoji' => (string) $emoji, 'count' => count($who), 'mine' => in_array($me, $who, true), 'users' => $users];
            }

            $att = null;
            if (!empty($m['attachment_path'])) {
                $path = (string) $m['attachment_path'];
                $mime = (string) ($m['attachment_mime'] ?? '');
                if (str_starts_with($path, 'giphy:')) {
                    $att = ['name' => (string) ($m['attachment_name'] ?: 'GIF'), 'type' => 'image/gif', 'size' => 0, 'isImage' => true, 'url' => substr($path, 6)];
                } else {
                    // I vocali registrati da Chrome hanno tipo video/webm: si capisce dal nome
                    if (preg_match('/^Messaggio vocale/i', (string) $m['attachment_name']) && !str_starts_with($mime, 'audio/')) {
                        $mime = 'audio/' . (str_contains($mime, 'mp4') ? 'mp4' : 'webm');
                    }
                    $att = [
                        'name'    => (string) ($m['attachment_name'] ?: 'Allegato'),
                        'type'    => $mime ?: null,
                        'size'    => (int) ($m['attachment_size'] ?? 0) ?: null,
                        'isImage' => (bool) preg_match('#^image/(jpeg|png|gif|webp)$#', $mime),
                        'url'     => '?download_attachment=' . (int) $m['id'],
                    ];
                }
            }

            $seenBy = [];
            if ($mine && $conv) {
                foreach (Chat::seenBy($m, $conv, $reads) as $s) {
                    $sp = self::person($s['type'], $s['id']);
                    $seenBy[] = ['id' => $sp['id'], 'name' => $sp['name'], 'avatarUrl' => $sp['avatarUrl'], 'seenAt' => self::iso($s['seen_at'])];
                }
            }

            $out[] = [
                'id'              => (int) $m['id'],
                'body'            => (string) $m['message'],
                'senderId'        => $sender['id'],
                'senderName'      => $sender['name'],
                'senderAvatarUrl' => $sender['avatarUrl'],
                'mine'            => $mine,
                'createdAt'       => self::iso($m['created_at']),
                'editedAt'        => self::iso($m['edited_at'] ?? null),
                'forwarded'       => !empty($m['forwarded']),
                'internal'        => !empty($m['is_internal']),
                'mentions'        => json_decode((string) ($m['mentions'] ?? ''), true) ?: [],
                'replyTo'         => $reply,
                'attachment'      => $att,
                'kind'            => 'testo',
                'reactions'       => $reactions,
                'seenBy'          => $seenBy,
            ];
        }
        return $out;
    }

    private static function iso($ts): ?string
    {
        if (empty($ts)) return null;
        $t = strtotime((string) $ts);
        return $t ? date('c', $t) : null;
    }
}
