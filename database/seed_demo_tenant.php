<?php
/**
 * Seeder tenant DEMO: crea un'azienda finta isolata con utenze pronte per le demo
 * commerciali (HR + consulente del lavoro collegato + dipendente collegato) e dati
 * finti realistici (colleghi, presenze, ferie/permessi, chat, comunicazioni, eventi).
 *
 * Eseguito dall'entrypoint Docker dopo le migrazioni. Idempotente: se l'azienda demo
 * (slug "demo") esiste gia non fa nulla.
 *
 * ENV:
 *   SEED_DEMO_TENANT=false   disattiva il seeder (default: attivo)
 *   SEED_DEMO_RESET=true     cancella il tenant demo e lo ricrea (dati rinfrescati sulle date di oggi)
 *   DEMO_PASSWORD=...        password delle 3 utenze demo (default: Demo2026!)
 *
 * USO CLI:  php database/seed_demo_tenant.php [--reset]
 *
 * Utenze create:
 *   demo.hr          -> HR / admin dell'azienda demo
 *   demo.consulente  -> consulente del lavoro collegato all'azienda demo
 *   demo.dipendente  -> dipendente dell'azienda demo
 */

if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    exit('Solo da CLI.');
}

require_once __DIR__ . '/../config/config.php';

const DEMO_SLUG = 'demo';
const DEMO_COMPANY_NAME = 'Azienda Demo S.r.l.';

function demoOut(string $msg): void
{
    echo '[demo-seed] ' . $msg . "\n";
}

function envFlag(string $key, bool $default): bool
{
    $v = getenv($key);
    if ($v === false || $v === '') return $default;
    return in_array(strtolower(trim($v, " \t\"'")), ['1', 'true', 'yes', 'on'], true);
}

/** Codice fiscale formalmente valido (carattere di controllo corretto) per persone fittizie. */
function demoFiscalCode(string $first, string $last, string $birth, string $gender, string $catastale): string
{
    $letters = function (string $s): array {
        $s = strtoupper(preg_replace('/[^A-Za-z]/', '', iconv('UTF-8', 'ASCII//TRANSLIT', $s)));
        return [preg_replace('/[AEIOU]/', '', $s), preg_replace('/[^AEIOU]/', '', $s)];
    };
    [$lc, $lv] = $letters($last);
    $cog = substr(str_pad(substr($lc . $lv, 0, 3), 3, 'X'), 0, 3);
    [$fc, $fv] = $letters($first);
    $nom = strlen($fc) >= 4 ? $fc[0] . $fc[2] . $fc[3] : substr(str_pad($fc . $fv, 3, 'X'), 0, 3);

    $d = new DateTime($birth);
    $months = 'ABCDEHLMPRST';
    $day = (int)$d->format('d') + ($gender === 'F' ? 40 : 0);
    $code = $cog . $nom . $d->format('y') . $months[(int)$d->format('n') - 1] . sprintf('%02d', $day) . $catastale;

    $odd = [1, 0, 5, 7, 9, 13, 15, 17, 19, 21, 2, 4, 18, 20, 11, 3, 6, 8, 12, 14, 16, 10, 22, 25, 24, 23];
    $sum = 0;
    for ($i = 0; $i < 15; $i++) {
        $c = $code[$i];
        $n = ctype_digit($c) ? (int)$c : ord($c) - 65;
        $sum += ($i % 2 === 0) ? $odd[$n] : $n;
    }
    return $code . chr(65 + ($sum % 26));
}

/** Cancella il tenant demo e tutti i record collegati (ogni tabella con company_id). */
function demoReset(int $companyId): void
{
    $pdo = Database::getInstance();
    $pdo->exec('SET FOREIGN_KEY_CHECKS = 0');
    try {
        Database::execute(
            "DELETE p FROM calendar_event_participants p JOIN calendar_events e ON e.id = p.event_id WHERE e.company_id = ?",
            [$companyId]
        );
        $userIds = array_map('intval', array_column(
            Database::fetchAll("SELECT id FROM users WHERE company_id = ?", [$companyId]), 'id'
        ));
        $tables = Database::fetchAll(
            "SELECT TABLE_NAME FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND COLUMN_NAME = 'company_id' AND TABLE_NAME <> 'companies'"
        );
        foreach ($tables as $t) {
            $name = $t['TABLE_NAME'];
            Database::execute("DELETE FROM `$name` WHERE company_id = ?", [$companyId]);
        }
        foreach ($userIds as $uid) {
            Database::execute("DELETE FROM user_companies WHERE user_id = ?", [$uid]);
        }
        Database::execute("DELETE FROM companies WHERE id = ?", [$companyId]);
    } finally {
        $pdo->exec('SET FOREIGN_KEY_CHECKS = 1');
    }
}

// ---------------------------------------------------------------------------

if (!envFlag('SEED_DEMO_TENANT', true)) {
    demoOut('disattivato (SEED_DEMO_TENANT=false).');
    exit(0);
}

$reset = in_array('--reset', $argv ?? [], true) || envFlag('SEED_DEMO_RESET', false);
$existing = Database::fetchOne("SELECT id FROM companies WHERE slug = ?", [DEMO_SLUG]);

if ($existing && !$reset) {
    demoOut('tenant demo gia presente (id=' . $existing['id'] . '), skip. Usa SEED_DEMO_RESET=true per ricrearlo.');
    exit(0);
}
if ($existing && $reset) {
    demoReset((int)$existing['id']);
    demoOut('tenant demo precedente eliminato.');
}

$password = trim((string)(getenv('DEMO_PASSWORD') ?: ''), " \t\"'");
if ($password === '') $password = 'Demo2026!';
$hash = password_hash($password, PASSWORD_BCRYPT, ['cost' => PASSWORD_COST]);
$now = date('Y-m-d H:i:s');

foreach (['demo.hr', 'demo.consulente'] as $u) {
    if (Database::exists('users', 'username = ?', [$u])) {
        demoOut("ERRORE: username $u gia usato da un utente non demo, interrompo.");
        exit(0);
    }
}

$today = new DateTime('today');
$year = (int)$today->format('Y');

/** Giorni lavorativi (lun-ven, no festivi) a ritroso da oggi escluso. */
$workingDaysBack = function (int $n) use ($today): array {
    $days = [];
    $d = clone $today;
    while (count($days) < $n) {
        $d->modify('-1 day');
        $ymd = $d->format('Y-m-d');
        if ((int)$d->format('N') >= 6) continue;
        if (class_exists('ItalianHolidays') && ItalianHolidays::isHoliday($ymd)) continue;
        $days[] = $ymd;
    }
    return array_reverse($days);
};
/** N-esimo giorno lavorativo da oggi (positivo = futuro, negativo = passato). */
$workingDayOffset = function (int $n) use ($today): string {
    $d = clone $today;
    $step = $n >= 0 ? '+1 day' : '-1 day';
    $left = abs($n);
    while ($left > 0) {
        $d->modify($step);
        if ((int)$d->format('N') >= 6) continue;
        if (class_exists('ItalianHolidays') && ItalianHolidays::isHoliday($d->format('Y-m-d'))) continue;
        $left--;
    }
    return $d->format('Y-m-d');
};

Database::beginTransaction();
try {
    // --- Azienda ---
    $ccnl = Database::fetchOne("SELECT id FROM ccnl_templates WHERE code = 'commercio_terziario' LIMIT 1");
    $companyData = [
        'name'        => DEMO_COMPANY_NAME,
        'slug'        => DEMO_SLUG,
        'needs_setup' => 0,
        'is_active'   => 1,
        'working_days'      => 'mon,tue,wed,thu,fri',
        'hours_per_day'     => 8,
        'lunch_break_start' => '13:00:00',
        'lunch_break_end'   => '14:00:00',
        'buoni_pasto_enabled' => 1,
        'timbratura_enabled'  => 1,
    ];
    if ($ccnl) $companyData['default_ccnl_id'] = (int)$ccnl['id'];
    $cid = Database::insert('companies', $companyData);
    demoOut("azienda creata: " . DEMO_COMPANY_NAME . " (id=$cid)");

    // --- Reparti ---
    $deptIds = [];
    foreach ([
        ['Amministrazione', 'AMM'], ['Commerciale', 'COM'], ['Produzione', 'PRO'], ['Logistica', 'LOG'],
    ] as [$name, $code]) {
        $deptIds[$name] = Database::insert('departments', [
            'company_id' => $cid, 'name' => $name, 'code' => 'DEMO_' . $code, 'is_active' => 1,
        ]);
    }

    // --- Utenze staff ---
    $hrId = Database::insert('users', [
        'company_id' => $cid, 'username' => 'demo.hr', 'password_hash' => $hash,
        'role' => 'admin', 'name' => 'Laura Bianchi (HR Demo)', 'email' => 'hr@demo.example.com',
        'is_active' => 1, 'password_changed_at' => $now,
    ]);
    $consId = Database::insert('users', [
        'company_id' => $cid, 'username' => 'demo.consulente', 'password_hash' => $hash,
        'role' => 'consulente_lavoro', 'name' => 'Studio Verdi - Paolo Verdi', 'email' => 'consulente@demo.example.com',
        'is_active' => 1, 'password_changed_at' => $now,
    ]);
    Database::insert('user_companies', ['user_id' => $hrId, 'company_id' => $cid]);
    Database::insert('user_companies', ['user_id' => $consId, 'company_id' => $cid]);

    // --- Dipendenti (il primo e' l'utenza demo.dipendente) ---
    $people = [
        // username, nome, cognome, sesso, nascita, reparto, mansione, livello, assunzione, stato
        ['demo.dipendente', 'Marco', 'Rossi', 'M', '1990-04-12', 'Commerciale', 'Account manager', '3° livello', '-3 years', 'operative'],
        ['demo.giulia.ferri', 'Giulia', 'Ferri', 'F', '1994-09-03', 'Amministrazione', 'Impiegata amministrativa', '4° livello', '-5 years', 'operative'],
        ['demo.luca.moretti', 'Luca', 'Moretti', 'M', '1987-01-25', 'Produzione', 'Capo reparto', '2° livello', '-8 years', 'in_meeting'],
        ['demo.sara.conti', 'Sara', 'Conti', 'F', '1996-06-18', 'Commerciale', 'Sales specialist', '4° livello', '-2 years', 'in_call'],
        ['demo.davide.galli', 'Davide', 'Galli', 'M', '1992-11-07', 'Logistica', 'Magazziniere', '5° livello', '-4 years', 'operative'],
        ['demo.elena.marino', 'Elena', 'Marino', 'F', '1999-02-14', 'Produzione', 'Operatrice', '5° livello', '-50 days', 'operative'],
    ];
    $empIds = [];
    foreach ($people as $i => [$username, $first, $last, $gender, $birth, $dept, $position, $level, $hired, $status]) {
        $hireDate = (new DateTime($hired))->format('Y-m-d');
        $data = [
            'company_id'    => $cid,
            'username'      => $username,
            'password_hash' => $hash,
            'fiscal_code'   => demoFiscalCode($first, $last, $birth, $gender, 'H501'),
            'first_name'    => $first,
            'last_name'     => $last,
            'email'         => str_replace('demo.', '', $username) . '@demo.example.com',
            'phone'         => '+39 333 000 00' . sprintf('%02d', $i + 10),
            'birth_date'    => $birth,
            'department_id' => $deptIds[$dept],
            'department'    => $dept,
            'position'      => $position,
            'job_level'     => $level,
            'hire_date'     => $hireDate,
            'is_active'     => 1,
            'availability_status' => $status,
            'availability_set_at' => $now,
            'password_changed_at' => $now,
            'notify_email'  => 0,
            'notify_push'   => 0,
            'created_by'    => $hrId,
        ];
        if ($ccnl) $data['ccnl_id'] = (int)$ccnl['id'];
        // Neoassunta in periodo di prova: fa comparire il widget "fine prova" in dashboard
        if ($username === 'demo.elena.marino') {
            $data['probation_end_date'] = (new DateTime('+10 days'))->format('Y-m-d');
        }
        // Solo l'utenza demo.dipendente ha il login (gli altri colleghi solo dati)
        if ($i > 0) $data['password_hash'] = password_hash(bin2hex(random_bytes(16)), PASSWORD_BCRYPT, ['cost' => 10]);
        $empIds[$username] = Database::insert('employees', $data);
    }
    $mainEmp = $empIds['demo.dipendente'];
    demoOut('dipendenti creati: ' . count($empIds));

    // --- Saldi ferie/permessi (snapshot "residuo ad oggi") ---
    $balances = [
        'demo.dipendente'   => [14.5, 32],
        'demo.giulia.ferri' => [20, 48],
        'demo.luca.moretti' => [8, 16.5],
        'demo.sara.conti'   => [11, 24],
        'demo.davide.galli' => [17, 40],
        'demo.elena.marino' => [1.5, 7],
    ];
    foreach ($balances as $u => [$ferie, $perm]) {
        foreach (['ferie' => $ferie, 'permesso' => $perm] as $type => $val) {
            Database::insert('employee_leave_balances', [
                'company_id' => $cid, 'employee_id' => $empIds[$u], 'year' => $year, 'leave_type' => $type,
                'entitled' => 0, 'carried_over' => $val, 'balance_set_at' => $today->format('Y-m-d'),
                'manual_used' => 0, 'accrual_last_month' => $today->format('Y-m'), 'updated_by' => $hrId,
            ]);
        }
    }

    // --- Richieste ferie / permessi / malattia ---
    $leave = function (string $user, string $type, string $start, string $end, string $status, string $reason, array $extra = [])
        use ($cid, $empIds, $hrId, $now) {
        Database::insert('leave_requests', array_merge([
            'company_id'  => $cid,
            'employee_id' => $empIds[$user],
            'leave_type'  => $type,
            'start_date'  => $start,
            'end_date'    => $end,
            'is_full_day' => 1,
            'reason'      => $reason,
            'status'      => $status,
            'approved_by' => $status === 'approved' ? $hrId : null,
            'approved_at' => $status === 'approved' ? $now : null,
        ], $extra));
    };
    $past = $workingDaysBack(15);
    // Dipendente demo: storico + richieste in attesa (da far approvare all'HR in demo)
    $leave('demo.dipendente', 'ferie', $past[2], $past[3], 'approved', 'Weekend lungo in famiglia');
    $leave('demo.dipendente', 'smart_working', $past[8], $past[8], 'approved', 'Lavoro da casa');
    $leave('demo.dipendente', 'ferie', $workingDayOffset(12), $workingDayOffset(16), 'pending', 'Vacanze');
    $leave('demo.dipendente', 'permesso', $workingDayOffset(3), $workingDayOffset(3), 'pending', 'Visita medica',
        ['is_full_day' => 0, 'start_time' => '15:00:00', 'end_time' => '18:00:00']);
    // Colleghi: assenze oggi/prossimi giorni per popolare heatmap e dashboard
    $leave('demo.giulia.ferri', 'ferie', $today->format('Y-m-d'), $workingDayOffset(2), 'approved', 'Ferie');
    $leave('demo.davide.galli', 'malattia', $today->format('Y-m-d'), $workingDayOffset(1), 'approved', 'Malattia',
        ['protocol_number' => '1234567890', 'certificate_waived' => 1]);
    $leave('demo.luca.moretti', 'permesso', $workingDayOffset(1), $workingDayOffset(1), 'approved', 'Impegno personale',
        ['is_full_day' => 0, 'start_time' => '09:00:00', 'end_time' => '11:00:00']);
    $leave('demo.sara.conti', 'ferie', $workingDayOffset(5), $workingDayOffset(9), 'pending', 'Viaggio');
    $leave('demo.sara.conti', 'permesso', $past[5], $past[5], 'rejected', 'Uscita anticipata',
        ['is_full_day' => 0, 'start_time' => '16:00:00', 'end_time' => '18:00:00', 'rejection_reason' => 'Chiusura trimestrale, spostiamo?']);

    // --- Timbrature ultimi 15 giorni lavorativi ---
    $absent = [
        'demo.dipendente' => [$past[2], $past[3], $past[8]],
    ];
    $punches = 0;
    foreach ($empIds as $u => $eid) {
        foreach ($past as $ymd) {
            if (in_array($ymd, $absent[$u] ?? [], true)) continue;
            foreach ([['in', 8, 45, 15], ['out', 13, 0, 5], ['in', 13, 55, 10], ['out', 17, 58, 20]] as [$kind, $h, $m, $jitter]) {
                $t = sprintf('%s %02d:%02d:%02d', $ymd, $h, 0, 0);
                $ts = strtotime($t) + ($m + random_int(0, $jitter)) * 60 + random_int(0, 59);
                Database::insert('attendance_punches', [
                    'company_id' => $cid, 'employee_id' => $eid, 'punch_at' => date('Y-m-d H:i:s', $ts),
                    'kind' => $kind, 'source' => 'nfc', 'notes' => 'demo',
                ]);
                $punches++;
            }
        }
    }
    demoOut("timbrature create: $punches");

    // --- Chat: dipendente <-> HR, dipendente <-> consulente, HR <-> consulente ---
    $chat = function (string $t1, int $id1, string $t2, int $id2, array $messages) use ($cid) {
        if ($t1 > $t2 || ($t1 === $t2 && $id1 > $id2)) [$t1, $id1, $t2, $id2] = [$t2, $id2, $t1, $id1];
        $base = time() - count($messages) * 1800 - 3600;
        $convId = Database::insert('chat_conversations', [
            'company_id' => $cid, 'participant1_type' => $t1, 'participant1_id' => $id1,
            'participant2_type' => $t2, 'participant2_id' => $id2,
        ]);
        $last = null;
        foreach ($messages as $k => [$st, $sid, $text, $read]) {
            $last = date('Y-m-d H:i:s', $base + $k * 1800);
            Database::insert('chat_messages', [
                'company_id' => $cid, 'conversation_id' => $convId, 'sender_type' => $st, 'sender_id' => $sid,
                'message' => $text, 'is_read' => $read ? 1 : 0, 'created_at' => $last,
            ]);
        }
        Database::update('chat_conversations', ['last_message_at' => $last], 'id = ?', [$convId]);
    };
    $chat('admin', $hrId, 'employee', $mainEmp, [
        ['employee', $mainEmp, 'Buongiorno Laura, ho inserito la richiesta ferie per fine mese. Mi confermi quando puoi?', 1],
        ['admin', $hrId, 'Ciao Marco, la vedo. Verifico la copertura del reparto e ti rispondo entro domani.', 1],
        ['employee', $mainEmp, 'Perfetto, grazie! Ho anche caricato il modulo per le detrazioni.', 0],
    ]);
    $chat('consulente_lavoro', $consId, 'employee', $mainEmp, [
        ['employee', $mainEmp, 'Salve, nella busta paga di questo mese non vedo il rimborso chilometrico.', 1],
        ['consulente_lavoro', $consId, 'Buongiorno, il rimborso e\' stato registrato a fine mese: lo trova nel cedolino del mese prossimo.', 1],
    ]);
    $chat('admin', $hrId, 'consulente_lavoro', $consId, [
        ['consulente_lavoro', $consId, 'Buongiorno, mi servono le presenze del mese entro il 3 per elaborare i cedolini.', 1],
        ['admin', $hrId, 'Certo, le esporto dal gestionale e te le mando oggi pomeriggio.', 1],
        ['consulente_lavoro', $consId, 'Ricevute, grazie. Ricordo che il periodo di prova di Elena Marino scade a breve.', 0],
    ]);

    // --- Comunicazioni aziendali ---
    foreach ([
        ['Benvenuti nel nuovo gestionale HR', "Da oggi ferie, permessi, timbrature e buste paga si gestiscono da qui.\nScarica l'app sul telefono per ricevere le notifiche.", 'high', 0],
        ['Chiusura aziendale estiva', "L'azienda restera' chiusa dal 10 al 21 agosto. Le giornate verranno scalate dalle ferie.", 'normal', -20],
        ['Aggiornamento policy smart working', "Da questo mese e' possibile richiedere fino a 2 giorni di smart working a settimana direttamente dall'app.", 'normal', -7],
    ] as [$title, $content, $prio, $daysAgo]) {
        Database::insert('communications', [
            'company_id' => $cid, 'title' => $title, 'content' => $content, 'priority' => $prio,
            'is_published' => 1, 'publish_date' => (new DateTime("$daysAgo days"))->format('Y-m-d'),
            'is_global' => 1, 'created_by' => $hrId,
        ]);
    }

    // --- Calendario: riunione con inviti ---
    $meetDay = $workingDayOffset(2);
    $eventId = Database::insert('calendar_events', [
        'company_id' => $cid, 'owner_type' => 'admin', 'owner_id' => $hrId,
        'title' => 'Colloquio di metà anno', 'description' => 'Allineamento obiettivi e feedback.',
        'location' => 'Sala riunioni', 'start_at' => "$meetDay 10:00:00", 'end_at' => "$meetDay 11:00:00",
    ]);
    Database::insert('calendar_event_participants', [
        'event_id' => $eventId, 'user_type' => 'employee', 'user_id' => $mainEmp, 'status' => 'accepted', 'responded_at' => $now,
    ]);
    $eventId = Database::insert('calendar_events', [
        'company_id' => $cid, 'owner_type' => 'admin', 'owner_id' => $hrId,
        'title' => 'Chiusura mensile presenze', 'description' => 'Verifica presenze prima dell\'invio al consulente.',
        'start_at' => $workingDayOffset(4) . ' 15:00:00', 'end_at' => $workingDayOffset(4) . ' 16:00:00',
    ]);
    Database::insert('calendar_event_participants', [
        'event_id' => $eventId, 'user_type' => 'consulente_lavoro', 'user_id' => $consId, 'status' => 'pending',
    ]);

    // --- Notifiche iniziali ---
    foreach ([
        ['admin', $hrId, 'leave_request', 'Nuova richiesta ferie', 'Marco Rossi ha richiesto ferie.', null],
        ['admin', $hrId, 'leave_request', 'Nuova richiesta ferie', 'Sara Conti ha richiesto ferie.', null],
        ['employee', $mainEmp, 'communication', 'Nuova comunicazione', 'Benvenuti nel nuovo gestionale HR', null],
    ] as [$rt, $rid, $type, $title, $msg, $link]) {
        Database::insert('notifications', [
            'company_id' => $cid, 'recipient_type' => $rt, 'recipient_id' => $rid,
            'type' => $type, 'title' => $title, 'message' => $msg, 'link' => $link,
        ]);
    }

    Database::commit();
} catch (Throwable $e) {
    Database::rollback();
    demoOut('ERRORE, nessuna modifica salvata: ' . $e->getMessage());
    exit(0);
}

demoOut('tenant demo pronto. Utenze: demo.hr (HR), demo.consulente (consulente), demo.dipendente (dipendente).');
if (getenv('DEMO_PASSWORD') === false || getenv('DEMO_PASSWORD') === '') {
    demoOut('password demo di default: ' . $password . ' (personalizzabile con DEMO_PASSWORD).');
}
