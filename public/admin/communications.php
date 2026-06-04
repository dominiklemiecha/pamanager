<?php
/**
 * Gestione Comunicazioni - Admin
 * PAManager - Comune
 */

require_once dirname(__DIR__, 2) . '/config/config.php';

Auth::init();
setSecurityHeaders();
Auth::requireUser('admin');

$user = Auth::getUser();
$action = $_GET['action'] ?? 'list';
$id = isset($_GET['id']) ? (int) $_GET['id'] : (isset($_POST['id']) ? (int) $_POST['id'] : null);
$message = '';
$error = '';

// Gestione azioni POST
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    CSRF::verifyOrDie();

    $postAction = $_POST['action'] ?? '';

    // helper: salva attachments uploadati nel form (multipart)
    $saveAttachments = function (int $commId): void {
        if (empty($_FILES['attachments']['name'])) return;
        $count = count($_FILES['attachments']['name']);
        for ($i = 0; $i < $count; $i++) {
            if (empty($_FILES['attachments']['name'][$i])) continue;
            $f = [
                'name'     => $_FILES['attachments']['name'][$i],
                'type'     => $_FILES['attachments']['type'][$i] ?? '',
                'tmp_name' => $_FILES['attachments']['tmp_name'][$i],
                'error'    => $_FILES['attachments']['error'][$i],
                'size'     => $_FILES['attachments']['size'][$i],
            ];
            if ($f['error'] !== UPLOAD_ERR_OK) continue;
            Communication::addUploadedFile($f, $commId, false);
        }
    };

    switch ($postAction) {
        case 'create':
            $rawContent = $_POST['content'] ?? '';
            $cleanContent = sanitizeRichHtml($rawContent);
            $result = Communication::create([
                'title' => $_POST['title'] ?? '',
                'content' => $cleanContent,
                'priority' => $_POST['priority'] ?? 'normal',
                'is_published' => isset($_POST['is_published']),
                'publish_date' => $_POST['publish_date'] ?? date('Y-m-d'),
                'expire_date' => $_POST['expire_date'] ?: null
            ]);

            if ($result['success']) {
                $saveAttachments((int)$result['id']);
                header('Location: communications.php?action=edit&id=' . (int)$result['id'] . '&message=created');
                exit;
            }
            $error = $result['error'];
            $action = 'new';
            break;

        case 'update':
            if ($id) {
                $rawContent = $_POST['content'] ?? '';
                $cleanContent = sanitizeRichHtml($rawContent);
                $result = Communication::update($id, [
                    'title' => $_POST['title'] ?? '',
                    'content' => $cleanContent,
                    'priority' => $_POST['priority'] ?? 'normal',
                    'is_published' => isset($_POST['is_published']),
                    'publish_date' => $_POST['publish_date'] ?? '',
                    'expire_date' => $_POST['expire_date'] ?: null
                ]);

                if ($result['success']) {
                    $saveAttachments($id);
                    header('Location: communications.php?action=edit&id=' . $id . '&message=updated');
                    exit;
                }
                $error = $result['error'];
                $action = 'edit';
            }
            break;

        case 'delete_attachment':
            $attId = isset($_POST['attachment_id']) ? (int) $_POST['attachment_id'] : 0;
            if ($attId && $id) {
                Communication::deleteAttachment($attId);
                header('Location: communications.php?action=edit&id=' . $id . '&message=updated');
                exit;
            }
            break;

        case 'delete':
            if ($id) {
                $result = Communication::delete($id);
                if ($result['success']) {
                    header('Location: communications.php?message=deleted');
                    exit;
                }
                $error = $result['error'];
            }
            break;

        case 'toggle_publish':
            if ($id) {
                $result = Communication::togglePublish($id);
                if ($result['success']) {
                    header('Location: communications.php?message=updated');
                    exit;
                }
                $error = $result['error'];
            }
            break;
    }
}

// Messaggi di conferma
if (isset($_GET['message'])) {
    $messages = [
        'created' => 'Comunicazione creata con successo',
        'updated' => 'Comunicazione aggiornata con successo',
        'deleted' => 'Comunicazione eliminata con successo'
    ];
    $message = $messages[$_GET['message']] ?? '';
}

// Carica dati in base all'azione
$communication = null;
$communications = [];
$search = $_GET['search'] ?? '';
$includePast = true; // mostra sempre tutte le comunicazioni, anche scadute

if ($action === 'list') {
    $communications = Communication::getAll($includePast, $search);
} elseif ($action === 'edit' && $id) {
    $communication = Communication::getById($id);
    if (!$communication) {
        header('Location: communications.php?error=not_found');
        exit;
    }
    $attachments = Communication::getAttachments($id);
} elseif ($action === 'view' && $id) {
    $communication = Communication::getById($id);
    if (!$communication) {
        header('Location: communications.php?error=not_found');
        exit;
    }
    $readStats = Communication::getReadStats($id);
    $attachments = Communication::getAttachments($id);
}

// Stats per banner
$__commCid = class_exists('Tenant') ? Tenant::currentCompanyId() : 1;
$__commPublished = 0; $__commDraft = 0; $__commActive = 0;
try {
    $__commPublished = (int) Database::fetchColumn("SELECT COUNT(*) FROM communications WHERE company_id = ? AND is_published = TRUE", [$__commCid]);
    $__commDraft     = (int) Database::fetchColumn("SELECT COUNT(*) FROM communications WHERE company_id = ? AND is_published = FALSE", [$__commCid]);
    $__commActive    = (int) Database::fetchColumn("SELECT COUNT(*) FROM communications WHERE company_id = ? AND is_published = TRUE AND publish_date <= CURDATE() AND (expire_date IS NULL OR expire_date >= CURDATE())", [$__commCid]);
} catch (Throwable $e) {}

$pageTitle = $action === 'new' ? 'Nuova Comunicazione'
           : ($action === 'edit' ? 'Modifica Comunicazione'
           : ($action === 'view' ? 'Dettaglio Comunicazione'
           : 'Gestione Comunicazioni'));
include dirname(__DIR__) . '/includes/header-admin.php';
?>

<style>
.cm-hero {
    margin-bottom: 1.25rem;
    display: flex; justify-content: space-between; align-items: center;
    gap: 24px; flex-wrap: wrap;
}
.cm-hero h2 {
    font-family: 'Space Grotesk', sans-serif;
    font-size: 2rem; font-weight: 700;
    letter-spacing: -0.025em;
    margin: 0 0 4px;
    line-height: 1.1;
}
.cm-hero p { margin: 0; opacity: 0.85; max-width: 560px; }
.cm-hero-btn {
    background: #0b3aa4 !important;
    border: 1px solid #0b3aa4 !important;
    color: white !important;
    backdrop-filter: blur(10px);
    padding: 12px 20px !important;
    border-radius: 10px !important;
    font-weight: 600 !important;
    display: inline-flex; align-items: center; gap: 8px;
    text-decoration: none;
}
.cm-hero-btn:hover { background: #082b7b !important; color: white !important; text-decoration: none; }
@media (max-width: 700px) {
    .cm-hero { padding: 22px 24px !important; }
    .cm-hero h2 { font-size: 1.5rem; }
}

.cm-filters {
    background: white;
    border: 1px solid var(--border);
    border-radius: 12px;
    padding: 12px 16px;
    display: flex; align-items: center; gap: 12px;
    margin-bottom: 16px; flex-wrap: wrap;
}
.cm-search { flex: 1; min-width: 200px; position: relative; }
.cm-search svg {
    position: absolute; left: 12px; top: 50%; transform: translateY(-50%);
    width: 16px; height: 16px; color: var(--muted); pointer-events: none;
}
.cm-search input {
    width: 100%;
    padding: 9px 12px 9px 36px;
    border: 1px solid var(--border);
    border-radius: 8px;
    font-size: 13px; font-family: inherit;
    background: white;
}
.cm-search input:focus { outline: none; border-color: #0b3aa4; box-shadow: 0 0 0 3px rgba(11,58,164,0.12); }
.cm-check {
    display: inline-flex; align-items: center; gap: 8px;
    font-size: 13px; color: var(--ink-2); cursor: pointer;
    padding: 6px 12px;
    border: 1px solid var(--border);
    border-radius: 999px;
    background: white;
    transition: all .12s ease;
}
.cm-check:hover { border-color: #0b3aa4; color: #0b3aa4; }
.cm-check input { accent-color: #0b3aa4; }

.cm-table-wrap {
    background: white;
    border: 1px solid var(--border);
    border-radius: 12px;
    overflow: hidden;
}
.cm-table { width: 100%; border-collapse: collapse; font-size: 13px; table-layout: auto; }
.cm-table th, .cm-table td { white-space: nowrap; }
.cm-table thead th {
    background: #fafbfc;
    padding: 12px 14px;
    text-align: left;
    font-size: 11px;
    text-transform: uppercase;
    color: var(--muted);
    font-weight: 600;
    letter-spacing: 0.06em;
    border-bottom: 1px solid var(--border);
}
.cm-table tbody td {
    padding: 12px 14px;
    border-bottom: 1px solid var(--border);
    vertical-align: middle;
}
.cm-table tbody tr:hover { background: #fafbfc; }
.cm-table tbody tr:last-child td { border-bottom: none; }
.cm-table td[data-label="Titolo"] {
    max-width: 360px; overflow: hidden; text-overflow: ellipsis;
}
.cm-table td[data-label="Titolo"] a {
    color: var(--ink); font-weight: 600; text-decoration: none;
}
.cm-table td[data-label="Titolo"] a:hover { color: #0b3aa4; }

.cm-pill {
    display: inline-flex; align-items: center; gap: 4px;
    padding: 3px 10px; border-radius: 999px;
    font-size: 11px; font-weight: 600; white-space: nowrap;
}
.cm-pill::before {
    content: ""; width: 6px; height: 6px; border-radius: 50%;
    background: currentColor;
}
.cm-priority-low      { background: rgba(100,116,139,0.10); color: #475569; }
.cm-priority-normal   { background: rgba(11,58,164,0.10);   color: #0b3aa4; }
.cm-priority-high     { background: rgba(255,187,85,0.10); color: #e09938; }
.cm-priority-urgent   { background: rgba(247,92,108,0.10);  color: #f75c6c; }
.cm-status-published  { background: rgba(11,58,164,0.10);  color: #0b3aa4; }
.cm-status-draft      { background: rgba(100,116,139,0.10); color: #475569; }

.cm-reads {
    font-family: 'Space Grotesk', sans-serif;
    font-weight: 600; color: var(--ink);
    font-variant-numeric: tabular-nums;
}
.cm-reads small { color: var(--muted); font-weight: 500; margin-left: 4px; }

.cm-actions { display: flex; gap: 4px; justify-content: flex-end; }
.cm-ibtn {
    display: inline-flex; align-items: center; justify-content: center;
    width: 28px; height: 28px;
    border-radius: 6px;
    border: 1px solid var(--border);
    background: white;
    color: var(--ink-2);
    cursor: pointer;
    font-family: inherit;
    padding: 0;
    transition: all .12s ease;
    text-decoration: none;
}
.cm-ibtn:hover { border-color: #0b3aa4; color: #0b3aa4; background: rgba(11,58,164,0.04); }
.cm-ibtn.cm-pub { background: rgba(11,58,164,0.10); color: #0b3aa4; border-color: rgba(11,58,164,0.25); }
.cm-ibtn.cm-pub:hover { background: #0b3aa4; color: white; border-color: #0b3aa4; }
.cm-ibtn.cm-unpub { background: rgba(255,187,85,0.10); color: #e09938; border-color: rgba(255,187,85,0.25); }
.cm-ibtn.cm-unpub:hover { background: #e09938; color: white; border-color: #e09938; }
.cm-ibtn.cm-edit { background: rgba(11,58,164,0.08); color: #0b3aa4; border-color: rgba(11,58,164,0.20); }
.cm-ibtn.cm-edit:hover { background: #0b3aa4; color: white; border-color: #0b3aa4; }
.cm-ibtn.cm-del { background: rgba(247,92,108,0.08); color: #f75c6c; border-color: rgba(247,92,108,0.20); }
.cm-ibtn.cm-del:hover { background: #f75c6c; color: white; border-color: #f75c6c; }
.cm-actions form { display: inline-flex; margin: 0; padding: 0; }

@media (max-width: 1100px) {
    .cm-table th:nth-child(4),
    .cm-table td[data-label="Scadenza"] { display: none; }
}
@media (max-width: 900px) {
    .cm-table th:nth-child(3),
    .cm-table td[data-label="Pubblicazione"] { display: none; }
}
/* ===== Mobile: tabella → card stacked ===== */
@media (max-width: 700px) {
    .cm-table-wrap { background: transparent; border: 0; }
    .cm-table { display: block; font-size: 13px; }
    .cm-table thead { display: none; }
    .cm-table tbody, .cm-table tr, .cm-table td { display: block; width: 100%; }
    .cm-table tbody tr {
        background: white;
        border: 1px solid var(--border);
        border-radius: 12px;
        margin-bottom: 10px;
        padding: 12px 14px;
    }
    .cm-table tbody tr:hover { background: white; }
    .cm-table tbody tr:last-child { margin-bottom: 0; }
    .cm-table tbody td {
        padding: 5px 0 !important;
        border: 0;
        display: flex; justify-content: space-between; align-items: center;
        gap: 10px; white-space: normal;
    }
    .cm-table tbody td::before {
        content: attr(data-label);
        font-size: 10px; font-weight: 700;
        color: #6e7191;
        text-transform: uppercase; letter-spacing: 0.04em;
        flex-shrink: 0;
    }
    /* Titolo full-width sopra senza label */
    .cm-table tbody td[data-label="Titolo"] {
        flex-direction: column; align-items: stretch;
        padding-bottom: 8px !important;
        border-bottom: 1px solid #f1f5f9;
        margin-bottom: 6px;
        max-width: 100%;
    }
    .cm-table tbody td[data-label="Titolo"]::before { display: none; }
    .cm-table tbody td[data-label="Titolo"] a {
        font-size: 14px; font-weight: 700;
        white-space: normal; overflow: visible;
    }
    /* Azioni in fondo allineate a destra */
    .cm-table tbody td[data-label="Azioni"] {
        padding-top: 8px !important;
        margin-top: 6px;
        border-top: 1px solid #f1f5f9;
        justify-content: flex-end;
    }
    .cm-table tbody td[data-label="Azioni"]::before { display: none; }
    /* Mostra di nuovo Scadenza e Pubblicazione su mobile (sono utili) */
    .cm-table td[data-label="Scadenza"],
    .cm-table td[data-label="Pubblicazione"] { display: flex !important; }
}

/* ============ VIEW DETAIL ============ */
.cm-view { display: grid; gap: 16px; }
.cm-view-card {
    background: white;
    border: 1px solid var(--border);
    border-radius: 14px;
    padding: 24px 28px;
}
.cm-view-h { border-bottom: 1px solid var(--border); padding-bottom: 18px; margin-bottom: 20px; }
.cm-view-pills { display: flex; gap: 8px; margin-bottom: 12px; flex-wrap: wrap; }
.cm-view-title {
    font-family: 'Host Grotesk', 'Inter', sans-serif;
    font-size: 26px; font-weight: 700; color: var(--ink);
    margin: 0 0 12px; line-height: 1.25;
    letter-spacing: -0.02em;
}
.cm-view-meta {
    display: flex; flex-wrap: wrap; gap: 16px;
    font-size: 13px; color: var(--muted);
}
.cm-view-meta span { display: inline-flex; align-items: center; gap: 6px; }
.cm-view-content {
    font-size: 15px; line-height: 1.7; color: var(--ink);
    white-space: pre-wrap;
    padding: 4px 0 20px;
}
.cm-view-actions { display: flex; gap: 10px; align-items: center; }
.cm-btn {
    display: inline-flex; align-items: center; gap: 8px;
    padding: 9px 16px; border-radius: 8px;
    font-family: inherit; font-size: 13px; font-weight: 600;
    border: 1px solid transparent; cursor: pointer;
    text-decoration: none; transition: all .12s ease;
}
.cm-btn-primary { background: #0b3aa4; color: white; border-color: #0b3aa4; }
.cm-btn-primary:hover { background: #0b3aa4; border-color: #0b3aa4; }
.cm-btn-danger { background: rgba(247,92,108,0.08); color: #f75c6c; border-color: rgba(247,92,108,0.20); }
.cm-btn-danger:hover { background: #f75c6c; color: white; border-color: #f75c6c; }

.cm-view-section-h {
    display: flex; align-items: center; justify-content: space-between;
    margin-bottom: 16px;
}
.cm-view-section-h h3 {
    font-family: 'Host Grotesk', 'Inter', sans-serif;
    font-size: 16px; font-weight: 700; color: var(--ink);
    margin: 0; letter-spacing: -0.01em;
}
.cm-stats {
    display: grid; grid-template-columns: repeat(3, 1fr); gap: 12px;
    margin-bottom: 20px;
}
.cm-stat {
    background: linear-gradient(180deg, #f8fafe 0%, #f1f5fd 100%);
    border: 1px solid rgba(11,58,164,0.10);
    border-radius: 10px; padding: 16px;
    text-align: center;
}
.cm-stat .v {
    font-family: 'Space Grotesk', sans-serif;
    font-size: 28px; font-weight: 700; color: var(--ink);
    line-height: 1; font-variant-numeric: tabular-nums;
}
.cm-stat .l {
    margin-top: 6px; font-size: 11px; font-weight: 600;
    text-transform: uppercase; letter-spacing: 0.06em;
    color: var(--muted);
}

.cm-readers { border-top: 1px solid var(--border); padding-top: 18px; }
.cm-readers-h {
    font-size: 12px; font-weight: 700; color: var(--muted);
    text-transform: uppercase; letter-spacing: 0.06em;
    margin-bottom: 10px;
}
.cm-reader {
    display: flex; align-items: center; justify-content: space-between;
    padding: 10px 12px; border-radius: 8px;
    font-size: 13px;
}
.cm-reader:hover { background: #fafbfc; }
.cm-reader .t { color: var(--muted); font-size: 12px; font-variant-numeric: tabular-nums; }

@media (max-width: 640px) {
    .cm-view-card { padding: 18px; }
    .cm-view-title { font-size: 22px; }
    .cm-stats { grid-template-columns: 1fr; }
}

/* ============ RICH CONTENT (view body) ============ */
.cm-rich { white-space: normal; }
.cm-rich p { margin: 0 0 12px; }
.cm-rich p:last-child { margin-bottom: 0; }
.cm-rich strong, .cm-rich b { font-weight: 700; color: var(--ink); }
.cm-rich em, .cm-rich i { font-style: italic; }
.cm-rich u { text-decoration: underline; }
.cm-rich ul, .cm-rich ol { margin: 0 0 14px; padding-left: 28px; }
.cm-rich ul li, .cm-rich ol li { margin-bottom: 6px; line-height: 1.6; }
.cm-rich h1, .cm-rich h2, .cm-rich h3, .cm-rich h4 {
    font-family: 'Host Grotesk','Inter',sans-serif;
    color: var(--ink); font-weight: 700;
    margin: 18px 0 10px; letter-spacing: -0.01em;
}
.cm-rich h3 { font-size: 17px; }
.cm-rich h4 { font-size: 15px; }
.cm-rich blockquote {
    margin: 12px 0;
    padding: 10px 16px;
    border-left: 3px solid #0b3aa4;
    background: rgba(11,58,164,0.04);
    border-radius: 0 8px 8px 0;
    color: var(--ink-2); font-style: italic;
}
.cm-rich a { color: #0b3aa4; text-decoration: underline; }
.cm-rich a:hover { color: #0b3aa4; }
.cm-rich img {
    max-width: 100%; height: auto;
    border-radius: 8px; margin: 8px 0;
    border: 1px solid var(--border);
}

/* ============ ATTACHMENTS (list + view) ============ */
.cm-view-attachments { margin-top: 22px; padding-top: 18px; border-top: 1px solid var(--border); }
.cm-view-att-h {
    font-size: 12px; font-weight: 700; color: var(--muted);
    text-transform: uppercase; letter-spacing: 0.06em;
    margin-bottom: 10px;
}
.cm-att-list { display: grid; gap: 8px; }
.cm-att-item {
    display: flex; align-items: center; gap: 12px;
    padding: 10px 12px;
    background: #fafbfc;
    border: 1px solid var(--border);
    border-radius: 10px;
    text-decoration: none;
    transition: all .12s ease;
}
.cm-att-link:hover { border-color: #0b3aa4; background: rgba(11,58,164,0.04); }
.cm-att-icon {
    width: 36px; height: 36px;
    display: flex; align-items: center; justify-content: center;
    background: rgba(11,58,164,0.10); color: #0b3aa4;
    border-radius: 8px; flex-shrink: 0;
}
.cm-att-info { flex: 1; min-width: 0; display: flex; flex-direction: column; gap: 2px; }
.cm-att-name { font-size: 13px; font-weight: 600; color: var(--ink); text-decoration: none; overflow: hidden; text-overflow: ellipsis; }
.cm-att-meta { font-size: 11px; color: var(--muted); }
.cm-att-dl { color: var(--muted); }
.cm-att-link:hover .cm-att-dl { color: #0b3aa4; }

/* ============ FORM ============ */
.cm-form { display: grid; gap: 16px; }
.cm-form-card {
    background: white;
    border: 1px solid var(--border);
    border-radius: 14px;
    padding: 22px 24px;
}
.cm-fg { display: flex; flex-direction: column; gap: 6px; margin-bottom: 16px; }
.cm-fg:last-child { margin-bottom: 0; }
.cm-fg label { font-size: 12px; font-weight: 600; color: var(--ink-2); text-transform: uppercase; letter-spacing: 0.04em; }
.cm-fg label .req { color: #f75c6c; }
.cm-fg input[type=text], .cm-fg input[type=date], .cm-fg select, .cm-fg textarea {
    width: 100%;
    padding: 10px 12px;
    border: 1px solid var(--border);
    border-radius: 8px;
    font-family: inherit; font-size: 14px;
    background: white;
    transition: border-color .12s ease;
}
.cm-fg input:focus, .cm-fg select:focus, .cm-fg textarea:focus {
    outline: none; border-color: #0b3aa4;
    box-shadow: 0 0 0 3px rgba(11,58,164,0.10);
}
.cm-fg-grid { display: grid; grid-template-columns: repeat(4, 1fr); gap: 14px; }
.cm-fg-grid .cm-fg { margin-bottom: 0; }
.cm-fg-check { justify-content: flex-end; }
.cm-check-lbl {
    display: inline-flex; align-items: center; gap: 8px;
    padding: 10px 12px;
    border: 1px solid var(--border); border-radius: 8px;
    background: #fafbfc; cursor: pointer; font-size: 13px;
}
.cm-check-lbl input { accent-color: #0b3aa4; }
.cm-hint { display: block; margin-top: 8px; font-size: 12px; color: var(--muted); }

/* Editor */
.cm-editor {
    border: 1px solid var(--border);
    border-radius: 10px;
    overflow: hidden;
    background: white;
    transition: border-color .12s ease, box-shadow .12s ease;
}
.cm-editor:focus-within { border-color: #0b3aa4; box-shadow: 0 0 0 3px rgba(11,58,164,0.10); }
.cm-toolbar {
    display: flex; flex-wrap: wrap; gap: 2px;
    padding: 6px 8px;
    border-bottom: 1px solid var(--border);
    background: #fafbfc;
}
.cm-toolbar button {
    display: inline-flex; align-items: center; justify-content: center;
    width: 30px; height: 30px;
    border: none; background: transparent;
    color: var(--ink-2); border-radius: 6px;
    cursor: pointer; font-family: inherit;
    transition: all .1s ease;
}
.cm-toolbar button:hover { background: rgba(11,58,164,0.08); color: #0b3aa4; }
.cm-toolbar button b, .cm-toolbar button i, .cm-toolbar button u { font-size: 14px; }
.cm-tb-sep { width: 1px; background: var(--border); margin: 4px 4px; }
.cm-editable {
    min-height: 220px;
    padding: 14px 16px;
    font-size: 14px; line-height: 1.7;
    color: var(--ink);
    outline: none;
    overflow-y: auto;
    max-height: 540px;
}
.cm-editable p { margin: 0 0 10px; }
.cm-editable ul, .cm-editable ol { padding-left: 26px; margin: 0 0 10px; }
.cm-editable img { max-width: 100%; height: auto; border-radius: 6px; }
.cm-editable blockquote {
    margin: 8px 0; padding: 6px 14px;
    border-left: 3px solid #0b3aa4;
    background: rgba(11,58,164,0.04);
    color: var(--ink-2);
}

/* Upload zone */
.cm-section-h { display: flex; align-items: baseline; justify-content: space-between; margin-bottom: 14px; }
.cm-section-h h3 { font-family: 'Host Grotesk','Inter',sans-serif; font-size: 16px; font-weight: 700; margin: 0; color: var(--ink); }
.cm-section-h small { color: var(--muted); font-size: 12px; }
.cm-upload-zone {
    display: flex; flex-direction: column; align-items: center; justify-content: center;
    gap: 4px; padding: 28px 16px;
    border: 2px dashed var(--border);
    border-radius: 12px;
    cursor: pointer;
    transition: all .12s ease;
    color: var(--muted);
}
.cm-upload-zone:hover { border-color: #0b3aa4; background: rgba(11,58,164,0.03); color: #0b3aa4; }
.cm-upload-main { font-size: 14px; font-weight: 600; color: var(--ink); }
.cm-upload-zone:hover .cm-upload-main { color: #0b3aa4; }
.cm-upload-sub { font-size: 12px; }
.cm-att-preview { display: grid; gap: 6px; margin-top: 10px; }
.cm-att-pending {
    display: flex; justify-content: space-between; align-items: center;
    padding: 8px 12px; background: rgba(11,58,164,0.06);
    border-radius: 8px; font-size: 13px; color: var(--ink);
}

.cm-form-actions { display: flex; gap: 10px; justify-content: flex-end; }
.cm-btn-ghost { background: white; color: var(--ink-2); border-color: var(--border); }
.cm-btn-ghost:hover { border-color: var(--ink-2); color: var(--ink); }

@media (max-width: 880px) {
    .cm-fg-grid { grid-template-columns: 1fr 1fr; }
}
@media (max-width: 540px) {
    .cm-fg-grid { grid-template-columns: 1fr; }
    .cm-form-card { padding: 18px; }
}
</style>

<?php if ($action === 'list'): ?>
<div class="welcome-card cm-hero">
    <div>
        <h2>Comunicazioni</h2>
        <p>Pubblica avvisi e aggiornamenti per i dipendenti.
        <?php if ($__commActive > 0): ?>
            <strong>Hai <?= $__commActive ?> comunicazion<?= $__commActive === 1 ? 'e' : 'i' ?> attiv<?= $__commActive === 1 ? 'a' : 'e' ?>.</strong>
        <?php else: ?>
            <strong>Nessuna comunicazione attiva al momento.</strong>
        <?php endif; ?>
        </p>
    </div>
    <a href="?action=new" class="cm-hero-btn">
        <svg viewBox="0 0 24 24" width="16" height="16" fill="currentColor"><path d="M19 13h-6v6h-2v-6H5v-2h6V5h2v6h6v2z"/></svg>
        Nuova comunicazione
    </a>
</div>
<?php endif; ?>

<div class="admin-page">
    <?php if ($action !== 'list'): ?>
        <div class="page-header" style="margin-bottom: 1.25rem;">
            <a href="communications.php" class="btn-back">Indietro</a>
        </div>
    <?php endif; ?>

    <?php if ($message): ?>
        <div class="alert alert-success"><?= e($message) ?></div>
    <?php endif; ?>

    <?php if ($error): ?>
        <div class="alert alert-error"><?= e($error) ?></div>
    <?php endif; ?>

    <?php if ($action === 'list'): ?>
        <!-- Lista Comunicazioni -->
        <div class="cm-filters">
            <form method="GET" class="cm-search">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
                <input type="search" name="search" value="<?= e($search) ?>" placeholder="Cerca per titolo o contenuto…">
            </form>
        </div>

        <?php if (empty($communications)): ?>
            <div style="background:white; border:1px solid var(--border); border-radius:12px; padding:48px 20px; text-align:center; color:var(--muted);">
                <svg width="40" height="40" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" style="margin-bottom:12px; opacity:0.5;"><path d="M3 11l18-5v12L3 14v-3z"/><path d="M11.6 16.8a3 3 0 1 1-5.8-1.6"/></svg>
                <p style="margin:0;">Nessuna comunicazione trovata</p>
            </div>
        <?php else: ?>
            <div class="cm-table-wrap">
                <table class="cm-table">
                    <thead>
                        <tr>
                            <th>Titolo</th>
                            <th>Priorità</th>
                            <th>Pubblicazione</th>
                            <th>Scadenza</th>
                            <th>Letture</th>
                            <th>Stato</th>
                            <th style="text-align:right;">Azioni</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($communications as $comm):
                            $__pctRead = $comm['total_employees'] > 0 ? round(($comm['read_count'] / $comm['total_employees']) * 100) : 0;
                        ?>
                            <tr>
                                <td data-label="Titolo">
                                    <a href="?action=view&id=<?= $comm['id'] ?>"><?= e($comm['title']) ?></a>
                                </td>
                                <td data-label="Priorità">
                                    <span class="cm-pill cm-priority-<?= e($comm['priority']) ?>">
                                        <?= e(Communication::PRIORITIES[$comm['priority']] ?? $comm['priority']) ?>
                                    </span>
                                </td>
                                <td data-label="Pubblicazione" style="color:var(--ink-2); font-size:12px;"><?= formatDate($comm['publish_date']) ?></td>
                                <td data-label="Scadenza" style="color:var(--ink-2); font-size:12px;"><?= $comm['expire_date'] ? formatDate($comm['expire_date']) : '—' ?></td>
                                <td data-label="Letture">
                                    <span class="cm-reads"><?= $comm['read_count'] ?>/<?= $comm['total_employees'] ?> <small><?= $__pctRead ?>%</small></span>
                                </td>
                                <td data-label="Stato">
                                    <span class="cm-pill <?= $comm['is_published'] ? 'cm-status-published' : 'cm-status-draft' ?>">
                                        <?= $comm['is_published'] ? 'Pubblicata' : 'Bozza' ?>
                                    </span>
                                </td>
                                <td data-label="Azioni">
                                    <div class="cm-actions">
                                        <a href="?action=view&id=<?= $comm['id'] ?>" class="cm-ibtn" title="Vedi">
                                            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>
                                        </a>
                                        <a href="?action=edit&id=<?= $comm['id'] ?>" class="cm-ibtn cm-edit" title="Modifica">
                                            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"/></svg>
                                        </a>
                                        <form method="POST" onsubmit="return confirm('Eliminare definitivamente la comunicazione \&quot;<?= e($comm['title']) ?>\&quot;? L\'operazione non è reversibile.');">
                                            <?= CSRF::field() ?>
                                            <input type="hidden" name="action" value="delete">
                                            <input type="hidden" name="id" value="<?= $comm['id'] ?>">
                                            <button type="submit" class="cm-ibtn cm-del" title="Elimina">
                                                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="3 6 5 6 21 6"/><path d="M19 6l-2 14a2 2 0 0 1-2 2H9a2 2 0 0 1-2-2L5 6"/><path d="M10 11v6M14 11v6"/><path d="M9 6V4a1 1 0 0 1 1-1h4a1 1 0 0 1 1 1v2"/></svg>
                                            </button>
                                        </form>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>

    <?php elseif ($action === 'new' || $action === 'edit'): ?>
        <!-- Form Comunicazione -->
        <form method="POST" enctype="multipart/form-data" class="cm-form" id="cmForm">
            <?= CSRF::field() ?>
            <input type="hidden" name="action" value="<?= $action === 'new' ? 'create' : 'update' ?>">

            <div class="cm-form-card">
                <div class="cm-fg">
                    <label for="title">Titolo <span class="req">*</span></label>
                    <input type="text" id="title" name="title" required maxlength="255"
                           value="<?= e($communication['title'] ?? $_POST['title'] ?? '') ?>">
                </div>

                <div class="cm-fg">
                    <label>Contenuto <span class="req">*</span></label>
                    <div class="cm-editor">
                        <div class="cm-toolbar" role="toolbar">
                            <button type="button" data-cmd="bold" title="Grassetto (Ctrl+B)"><b>B</b></button>
                            <button type="button" data-cmd="italic" title="Corsivo (Ctrl+I)"><i>I</i></button>
                            <button type="button" data-cmd="underline" title="Sottolineato"><u>U</u></button>
                            <span class="cm-tb-sep"></span>
                            <button type="button" data-cmd="insertUnorderedList" title="Elenco puntato">
                                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="8" y1="6" x2="21" y2="6"/><line x1="8" y1="12" x2="21" y2="12"/><line x1="8" y1="18" x2="21" y2="18"/><circle cx="4" cy="6" r="1"/><circle cx="4" cy="12" r="1"/><circle cx="4" cy="18" r="1"/></svg>
                            </button>
                            <button type="button" data-cmd="insertOrderedList" title="Elenco numerato">
                                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="10" y1="6" x2="21" y2="6"/><line x1="10" y1="12" x2="21" y2="12"/><line x1="10" y1="18" x2="21" y2="18"/><path d="M4 6h1v4"/><path d="M4 10h2"/><path d="M6 18H4c0-1 2-2 2-3s-1-1.5-2-1"/></svg>
                            </button>
                            <span class="cm-tb-sep"></span>
                            <button type="button" data-cmd="formatBlock" data-arg="H3" title="Titolo">H</button>
                            <button type="button" data-cmd="formatBlock" data-arg="blockquote" title="Citazione">
                                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 21c3 0 7-1 7-8V5c0-1.25-.75-2-2-2H4c-1.25 0-2 .75-2 2v6c0 1.25.75 2 2 2h3c0 4-3 5-4 5"/><path d="M15 21c3 0 7-1 7-8V5c0-1.25-.75-2-2-2h-4c-1.25 0-2 .75-2 2v6c0 1.25.75 2 2 2h3c0 4-3 5-4 5"/></svg>
                            </button>
                            <button type="button" id="cmBtnLink" title="Inserisci link">
                                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M10 13a5 5 0 0 0 7.54.54l3-3a5 5 0 0 0-7.07-7.07l-1.72 1.71"/><path d="M14 11a5 5 0 0 0-7.54-.54l-3 3a5 5 0 0 0 7.07 7.07l1.71-1.71"/></svg>
                            </button>
                            <?php if ($action === 'edit'): ?>
                            <span class="cm-tb-sep"></span>
                            <button type="button" id="cmBtnImage" title="Inserisci immagine">
                                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="3" width="18" height="18" rx="2" ry="2"/><circle cx="8.5" cy="8.5" r="1.5"/><polyline points="21 15 16 10 5 21"/></svg>
                            </button>
                            <?php endif; ?>
                            <span class="cm-tb-sep"></span>
                            <button type="button" data-cmd="removeFormat" title="Rimuovi formattazione">
                                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M4 7V4h16v3"/><path d="M5 20h6"/><path d="M13 4L8 20"/><line x1="15" y1="15" x2="22" y2="22"/><line x1="22" y1="15" x2="15" y2="22"/></svg>
                            </button>
                        </div>
                        <div id="cmEditor" class="cm-editable" contenteditable="true"><?= sanitizeRichHtml($communication['content'] ?? $_POST['content'] ?? '') ?></div>
                        <textarea name="content" id="content" style="display:none;" required></textarea>
                    </div>
                    <?php if ($action === 'new'): ?>
                        <small class="cm-hint">💡 Per inserire immagini inline, salva prima la comunicazione: tornerai qui in modalità modifica.</small>
                    <?php endif; ?>
                </div>

                <div class="cm-fg-grid">
                    <div class="cm-fg">
                        <label for="priority">Priorità</label>
                        <select id="priority" name="priority">
                            <?php foreach (Communication::PRIORITIES as $key => $label): ?>
                                <option value="<?= $key ?>" <?= ($communication['priority'] ?? $_POST['priority'] ?? 'normal') === $key ? 'selected' : '' ?>>
                                    <?= e($label) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="cm-fg">
                        <label for="publish_date">Data Pubblicazione <span class="req">*</span></label>
                        <input type="date" id="publish_date" name="publish_date" required
                               value="<?= e($communication['publish_date'] ?? $_POST['publish_date'] ?? date('Y-m-d')) ?>">
                    </div>
                    <div class="cm-fg">
                        <label for="expire_date">Data Scadenza</label>
                        <input type="date" id="expire_date" name="expire_date"
                               value="<?= e($communication['expire_date'] ?? $_POST['expire_date'] ?? '') ?>">
                    </div>
                    <div class="cm-fg cm-fg-check">
                        <label class="cm-check-lbl">
                            <input type="checkbox" name="is_published"
                                   <?= ($communication['is_published'] ?? $_POST['is_published'] ?? true) ? 'checked' : '' ?>>
                            <span>Pubblica immediatamente</span>
                        </label>
                    </div>
                </div>
            </div>

            <!-- Allegati -->
            <div class="cm-form-card">
                <div class="cm-section-h">
                    <h3>Allegati</h3>
                    <small>PDF, immagini, documenti — max <?= round(MAX_FILE_SIZE/1024/1024) ?>MB ciascuno</small>
                </div>

                <?php if ($action === 'edit' && !empty($attachments)): ?>
                    <div class="cm-att-list">
                        <?php foreach ($attachments as $att): ?>
                            <div class="cm-att-item">
                                <div class="cm-att-icon">
                                    <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/></svg>
                                </div>
                                <div class="cm-att-info">
                                    <a href="<?= e(Communication::attachmentUrl((int)$att['id'])) ?>" target="_blank" class="cm-att-name"><?= e($att['original_name']) ?></a>
                                    <span class="cm-att-meta"><?= number_format($att['size_bytes']/1024, 0) ?> KB</span>
                                </div>
                                <form method="POST" onsubmit="return confirm('Eliminare questo allegato?');" style="margin:0;">
                                    <?= CSRF::field() ?>
                                    <input type="hidden" name="action" value="delete_attachment">
                                    <input type="hidden" name="id" value="<?= $communication['id'] ?>">
                                    <input type="hidden" name="attachment_id" value="<?= (int)$att['id'] ?>">
                                    <button type="submit" class="cm-ibtn cm-del" title="Elimina allegato">
                                        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="3 6 5 6 21 6"/><path d="M19 6l-2 14a2 2 0 0 1-2 2H9a2 2 0 0 1-2-2L5 6"/><path d="M10 11v6M14 11v6"/></svg>
                                    </button>
                                </form>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>

                <label class="cm-upload-zone" for="cmAttFiles">
                    <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="17 8 12 3 7 8"/><line x1="12" y1="3" x2="12" y2="15"/></svg>
                    <span class="cm-upload-main">Clicca per allegare file</span>
                    <span class="cm-upload-sub">o trascina qui i file</span>
                    <input type="file" id="cmAttFiles" name="attachments[]" multiple style="display:none;">
                </label>
                <div id="cmAttPreview" class="cm-att-preview"></div>
            </div>

            <div class="cm-form-actions">
                <a href="communications.php" class="cm-btn cm-btn-ghost">Annulla</a>
                <button type="submit" class="cm-btn cm-btn-primary">
                    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M19 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11l5 5v11a2 2 0 0 1-2 2z"/><polyline points="17 21 17 13 7 13 7 21"/><polyline points="7 3 7 8 15 8"/></svg>
                    <?= $action === 'new' ? 'Crea Comunicazione' : 'Salva Modifiche' ?>
                </button>
            </div>
        </form>

        <script>
        (function () {
            const ed = document.getElementById('cmEditor');
            const ta = document.getElementById('content');
            const form = document.getElementById('cmForm');
            if (!ed || !ta || !form) return;

            // Toolbar commands
            document.querySelectorAll('.cm-toolbar button[data-cmd]').forEach(btn => {
                btn.addEventListener('click', e => {
                    e.preventDefault();
                    ed.focus();
                    const cmd = btn.dataset.cmd;
                    const arg = btn.dataset.arg || null;
                    document.execCommand(cmd, false, arg);
                });
            });

            // Link
            const lnk = document.getElementById('cmBtnLink');
            if (lnk) lnk.addEventListener('click', () => {
                const url = prompt('URL del link:');
                if (url) { ed.focus(); document.execCommand('createLink', false, url); }
            });

            // Image upload (only in edit mode)
            const imgBtn = document.getElementById('cmBtnImage');
            if (imgBtn) {
                const commId = <?= json_encode((int)($communication['id'] ?? 0)) ?>;
                const csrf = <?= json_encode(CSRF::getToken()) ?>;
                imgBtn.addEventListener('click', () => {
                    const input = document.createElement('input');
                    input.type = 'file';
                    input.accept = 'image/*';
                    input.onchange = async () => {
                        const file = input.files[0];
                        if (!file) return;
                        const fd = new FormData();
                        fd.append('csrf_token', csrf);
                        fd.append('communication_id', commId);
                        fd.append('type', 'image');
                        fd.append('file', file);
                        try {
                            const r = await fetch('communications_upload.php', { method: 'POST', body: fd });
                            const j = await r.json();
                            if (j.success) {
                                ed.focus();
                                document.execCommand('insertImage', false, j.url);
                            } else {
                                alert('Errore: ' + (j.error || 'upload fallito'));
                            }
                        } catch (err) {
                            alert('Errore di rete');
                        }
                    };
                    input.click();
                });
            }

            // Sync editor → textarea on submit
            form.addEventListener('submit', () => {
                ta.value = ed.innerHTML.trim();
            });

            // Attachments preview
            const fi = document.getElementById('cmAttFiles');
            const preview = document.getElementById('cmAttPreview');
            if (fi && preview) {
                fi.addEventListener('change', () => {
                    preview.innerHTML = '';
                    Array.from(fi.files).forEach(f => {
                        const div = document.createElement('div');
                        div.className = 'cm-att-pending';
                        div.innerHTML = '<span>📎 ' + f.name + '</span><span class="cm-att-meta">' + Math.round(f.size/1024) + ' KB</span>';
                        preview.appendChild(div);
                    });
                });
            }
        })();
        </script>

    <?php elseif ($action === 'view' && $communication): ?>
        <!-- Dettaglio Comunicazione -->
        <div class="cm-view">
            <div class="cm-view-card">
                <div class="cm-view-h">
                    <div class="cm-view-pills">
                        <span class="cm-pill cm-priority-<?= e($communication['priority']) ?>">
                            <?= e(Communication::PRIORITIES[$communication['priority']] ?? $communication['priority']) ?>
                        </span>
                        <span class="cm-pill <?= $communication['is_published'] ? 'cm-status-published' : 'cm-status-draft' ?>">
                            <?= $communication['is_published'] ? 'Pubblicata' : 'Bozza' ?>
                        </span>
                    </div>
                    <h2 class="cm-view-title"><?= e($communication['title']) ?></h2>
                    <div class="cm-view-meta">
                        <span>👤 <?= e($communication['author_name']) ?></span>
                        <span>📅 Pubblicata il <?= formatDate($communication['publish_date']) ?></span>
                        <?php if (!empty($communication['expire_date'])): ?>
                            <span>⏳ Scade il <?= formatDate($communication['expire_date']) ?></span>
                        <?php endif; ?>
                    </div>
                </div>

                <div class="cm-view-content cm-rich"><?= sanitizeRichHtml($communication['content']) ?></div>

                <?php if (!empty($attachments)): ?>
                    <div class="cm-view-attachments">
                        <div class="cm-view-att-h">Allegati (<?= count($attachments) ?>)</div>
                        <div class="cm-att-list">
                            <?php foreach ($attachments as $att): ?>
                                <a href="<?= e(Communication::attachmentUrl((int)$att['id'])) ?>" target="_blank" class="cm-att-item cm-att-link">
                                    <div class="cm-att-icon">
                                        <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/></svg>
                                    </div>
                                    <div class="cm-att-info">
                                        <span class="cm-att-name"><?= e($att['original_name']) ?></span>
                                        <span class="cm-att-meta"><?= number_format($att['size_bytes']/1024, 0) ?> KB</span>
                                    </div>
                                    <svg class="cm-att-dl" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/></svg>
                                </a>
                            <?php endforeach; ?>
                        </div>
                    </div>
                <?php endif; ?>

                <div class="cm-view-actions">
                    <a href="?action=edit&id=<?= $communication['id'] ?>" class="cm-btn cm-btn-primary">
                        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"/></svg>
                        Modifica
                    </a>
                    <form method="POST" style="display:inline; margin:0;" onsubmit="return confirm('Eliminare questa comunicazione?')">
                        <?= CSRF::field() ?>
                        <input type="hidden" name="action" value="delete">
                        <input type="hidden" name="id" value="<?= $communication['id'] ?>">
                        <button type="submit" class="cm-btn cm-btn-danger">
                            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="3 6 5 6 21 6"/><path d="M19 6l-2 14a2 2 0 0 1-2 2H9a2 2 0 0 1-2-2L5 6"/><path d="M10 11v6M14 11v6"/></svg>
                            Elimina
                        </button>
                    </form>
                </div>
            </div>

            <!-- Statistiche Lettura -->
            <div class="cm-view-card">
                <div class="cm-view-section-h">
                    <h3>Statistiche di lettura</h3>
                    <span class="cm-pill cm-status-published"><?= $readStats['percentage'] ?>% lette</span>
                </div>
                <div class="cm-stats">
                    <div class="cm-stat">
                        <div class="v"><?= $readStats['read_count'] ?></div>
                        <div class="l">Letture</div>
                    </div>
                    <div class="cm-stat">
                        <div class="v"><?= $readStats['unread_count'] ?></div>
                        <div class="l">Non lette</div>
                    </div>
                    <div class="cm-stat">
                        <div class="v" style="color:#0b3aa4;"><?= $readStats['percentage'] ?>%</div>
                        <div class="l">Tasso</div>
                    </div>
                </div>

                <?php if (!empty($readStats['readers'])): ?>
                    <div class="cm-readers">
                        <div class="cm-readers-h">Chi ha letto</div>
                        <?php foreach ($readStats['readers'] as $reader): ?>
                            <div class="cm-reader">
                                <span><?= e($reader['last_name'] . ' ' . $reader['first_name']) ?></span>
                                <span class="t"><?= formatDateTime($reader['read_at']) ?></span>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    <?php endif; ?>
</div>

<?php include dirname(__DIR__) . '/includes/footer-admin.php'; ?>
