<?php
/**
 * Verifica pubblica di un attestato Academy (link del QR code).
 * Nessun login: mostra solo i dati essenziali e se il PDF è integro.
 */

require_once dirname(__DIR__) . '/config/config.php';

setSecurityHeaders();

$token = (string) ($_GET['t'] ?? '');
$data = null;
if (preg_match('/^[a-f0-9]{32}$/', $token) && checkRateLimitDb(getClientIp(), 'verify_certificate', 30, 300)) {
    $data = AcademyCertificate::verify($token);
}

// Codice fiscale parzialmente oscurato: basta a riconoscere la persona senza esporlo
$maskCf = function (?string $cf): string {
    $cf = (string) $cf;
    return strlen($cf) === 16 ? substr($cf, 0, 6) . '******' . substr($cf, -4) : '';
};
$fmt = fn($d) => $d ? date('d/m/Y', strtotime($d)) : '-';
?>
<!DOCTYPE html>
<html lang="it">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex">
    <title>Verifica attestato</title>
    <link rel="stylesheet" href="https://rsms.me/inter/inter.css">
    <style>
        :root { --accent: #0b3aa4; }
        * { box-sizing: border-box; }
        body { margin: 0; font-family: Inter, system-ui, sans-serif; background: #f4f6fb; color: #1f2937;
               min-height: 100vh; display: flex; align-items: center; justify-content: center; padding: 16px; }
        .card { background: #fff; border-radius: 16px; box-shadow: 0 10px 40px rgba(11,58,164,0.10);
                max-width: 760px; width: 100%; padding: 28px; }
        h2 { font-size: 1rem; margin: 28px 0 4px; color: #0f172a; }
        .sub { font-size: 0.8rem; color: #6b7280; margin: 0 0 12px; }
        .log { list-style: none; margin: 0; padding: 0; border-left: 2px solid #dbe3f4; }
        .log li { position: relative; padding: 0 0 14px 18px; font-size: 0.88rem; }
        .log li::before { content: ''; position: absolute; left: -6px; top: 4px; width: 10px; height: 10px; border-radius: 50%; background: #cbd5e1; }
        .log li.key::before { background: var(--accent); }
        .log li.done::before { background: #16a34a; }
        .log .when { color: #6b7280; font-size: 0.78rem; font-variant-numeric: tabular-nums; }
        .log .what { font-weight: 600; }
        .log .meta { color: #6b7280; font-size: 0.78rem; }
        .badge { display: inline-flex; align-items: center; gap: 8px; font-weight: 600; font-size: 0.9rem;
                 padding: 6px 12px; border-radius: 999px; }
        .badge .dot { width: 8px; height: 8px; border-radius: 50%; background: currentColor; }
        .ok { background: #dcfce7; color: #166534; }
        .warn { background: #fef3c7; color: #92400e; }
        .ko { background: #fee2e2; color: #991b1b; }
        h1 { font-size: 1.35rem; margin: 16px 0 4px; color: var(--accent); }
        dl { display: grid; grid-template-columns: 140px 1fr; gap: 8px 12px; margin: 20px 0 0; font-size: 0.92rem; }
        dt { color: #6b7280; }
        dd { margin: 0; font-weight: 500; }
        p.note { font-size: 0.8rem; color: #6b7280; margin: 20px 0 0; }
        @media (max-width: 480px) { dl { grid-template-columns: 1fr; gap: 2px; } dd { margin-bottom: 8px; } }
    </style>
</head>
<body>
<div class="card">
    <?php if (!$data): ?>
        <span class="badge ko"><span class="dot"></span>Attestato non trovato</span>
        <h1>Verifica non riuscita</h1>
        <p class="note">Il codice non corrisponde a nessun attestato emesso. Controlla di aver inquadrato il QR code corretto.</p>
    <?php else: ?>
        <?php if ($data['file_intact']): ?>
            <span class="badge ok"><span class="dot"></span>Attestato valido</span>
        <?php else: ?>
            <span class="badge warn"><span class="dot"></span>Attestato emesso, file originale non verificabile</span>
        <?php endif; ?>
        <h1><?= htmlspecialchars($data['course_title']) ?></h1>
        <dl>
            <dt>Partecipante</dt><dd><?= htmlspecialchars(trim($data['first_name'] . ' ' . $data['last_name'])) ?></dd>
            <dt>Codice fiscale</dt><dd><?= htmlspecialchars($maskCf($data['fiscal_code'])) ?></dd>
            <dt>Azienda</dt><dd><?= htmlspecialchars($data['company_name'] ?? '-') ?></dd>
            <dt>Completato il</dt><dd><?= $fmt($data['completed_at']) ?></dd>
            <dt>Attestato n.</dt><dd><?= htmlspecialchars($data['number']) ?></dd>
            <dt>Emesso il</dt><dd><?= $fmt($data['issued_at']) ?></dd>
        </dl>
        <h2>Registro di frequenza</h2>
        <p class="sub">
            <?php if ($data['chain']['ok']): ?>
                <span class="badge ok" style="font-size:0.75rem; padding:3px 9px;"><span class="dot"></span>Registro integro</span>
            <?php else: ?>
                <span class="badge ko" style="font-size:0.75rem; padding:3px 9px;"><span class="dot"></span>Registro alterato</span>
            <?php endif; ?>
            &nbsp;Ora del server, indirizzo IP parzialmente oscurato.
        </p>
        <ul class="log">
            <?php foreach ($data['events'] as $ev):
                $cls = in_array($ev['event'], ['started', 'certificate_generated'], true) ? 'key' : ($ev['event'] === 'completed' ? 'done' : ''); ?>
                <li class="<?= $cls ?>">
                    <div class="when"><?= date('d/m/Y H:i:s', strtotime($ev['at'])) ?></div>
                    <div class="what"><?= htmlspecialchars($ev['label']) ?><?= $ev['detail'] !== '' ? ': <span style="font-weight:400;">' . htmlspecialchars($ev['detail']) . '</span>' : '' ?></div>
                    <div class="meta"><?= htmlspecialchars($ev['actor']) ?><?= $ev['ip'] !== '' ? ' · IP ' . htmlspecialchars($ev['ip']) : '' ?></div>
                    <?php if ($ev['event'] === 'completed' && !empty($data['declaration_text'])): ?>
                        <div class="meta" style="font-style:italic;">“<?= htmlspecialchars($data['declaration_text']) ?>”</div>
                    <?php endif; ?>
                </li>
            <?php endforeach; ?>
        </ul>
        <p class="note">
            Ogni riga del registro contiene l'impronta (hash SHA-256) della precedente: una modifica successiva
            ai dati verrebbe segnalata qui sopra. L'impronta del PDF emesso è conservata per verificarne l'integrità.
        </p>
    <?php endif; ?>
</div>
</body>
</html>
