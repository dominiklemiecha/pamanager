# Documenti dipendente — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Aggiungere sistema documenti generici per dipendente (nome libero, rinomina, elimina, scadenza opzionale, flag visibilità), gestito da `admin` e `admin_reparto`, visibile al dipendente solo se flag attivo.

**Architecture:** Nuova tabella `employee_documents` + nuova classe `EmployeeDocument` (mirror leggero di `Document` senza vincolo mese/anno e senza watermark). Endpoint POST per azioni (upload/update/delete/download), sezione integrata nella scheda dipendente esistente (`?action=view`), nuova pagina lato dipendente. Notifiche push+email coerenti con `Document::upload`.

**Tech Stack:** PHP 8 + MySQL/MariaDB, classi in `src/classes/` (autoload PSR-0 via `spl_autoload_register` in `config/config.php`), endpoint single-file PHP con CSRF, layout `header-admin.php` / `header-admin-reparto.php` / `header-employee.php`, notifiche via `PushNotification` + `Mailer`.

**Convenzione test:** Il progetto non ha test automatici per classi simili. Ogni task ha uno step "Verifica manuale" con URL/azione/risultato atteso. Verificare nel browser locale (XAMPP) prima del commit.

---

## File map

**Create:**
- `database/migrations/017_employee_documents.sql` — schema `employee_documents` + `employee_document_downloads`
- `src/classes/EmployeeDocument.php` — classe core
- `public/admin/employee-documents.php` — endpoint POST azioni (admin)
- `public/admin-reparto/employee-documents.php` — endpoint POST azioni (admin_reparto)
- `public/employee/my-documents.php` — pagina lista lato dipendente

**Modify:**
- `public/admin/employees.php` — aggiungere sezione "Documenti dipendente" nel ramo `action === 'view'`
- `public/admin-reparto/employees.php` — stessa sezione (limitata al reparto)
- `public/includes/header-employee.php` — voce sidebar "I miei documenti"

---

## Task 1: Migration SQL

**Files:**
- Create: `database/migrations/017_employee_documents.sql`

- [ ] **Step 1: Creare il file migration**

```sql
-- Migration 017: tabella documenti generici per dipendente.
-- Separata da `documents` (buste paga/CUD) perche senza vincolo mese/anno.
-- Idempotente.

CREATE TABLE IF NOT EXISTS employee_documents (
    id INT AUTO_INCREMENT PRIMARY KEY,
    company_id INT NOT NULL DEFAULT 1,
    employee_id INT NOT NULL,
    name VARCHAR(255) NOT NULL,
    file_path VARCHAR(500) NOT NULL,
    file_name VARCHAR(255) NOT NULL,
    original_name VARCHAR(255) NOT NULL,
    file_size INT NOT NULL,
    mime_type VARCHAR(100) NOT NULL,
    visible_to_employee TINYINT(1) NOT NULL DEFAULT 0,
    expires_on DATE NULL,
    uploaded_by INT NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_ed_company_employee (company_id, employee_id),
    INDEX idx_ed_employee_visible (employee_id, visible_to_employee),
    INDEX idx_ed_expires (expires_on),
    CONSTRAINT fk_ed_employee FOREIGN KEY (employee_id) REFERENCES employees(id) ON DELETE CASCADE,
    CONSTRAINT fk_ed_uploader FOREIGN KEY (uploaded_by) REFERENCES users(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS employee_document_downloads (
    id INT AUTO_INCREMENT PRIMARY KEY,
    employee_document_id INT NOT NULL,
    user_type VARCHAR(32) NOT NULL,
    user_id INT NOT NULL,
    ip_address VARCHAR(45) NULL,
    user_agent VARCHAR(500) NULL,
    downloaded_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_edd_doc (employee_document_id),
    INDEX idx_edd_user (user_type, user_id),
    CONSTRAINT fk_edd_doc FOREIGN KEY (employee_document_id) REFERENCES employee_documents(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

- [ ] **Step 2: Applicare la migration in locale**

Run via browser: `http://localhost/app-gestionali/gestionalepa/public/migrate.php?token=pamanager_migrate_2024`

Expected: output mostra "Migration 017_employee_documents.sql: OK" (o equivalente al runner esistente).

Alternativa CLI: `mysql -u root gestionalepa < database/migrations/017_employee_documents.sql` (XAMPP locale, root senza password).

- [ ] **Step 3: Verifica schema**

```bash
mysql -u root gestionalepa -e "DESCRIBE employee_documents; DESCRIBE employee_document_downloads;"
```

Expected: due tabelle elencate con tutte le colonne sopra.

- [ ] **Step 4: Commit**

```bash
git add database/migrations/017_employee_documents.sql
git commit -m "feat(db): migration 017 - employee_documents tables"
```

---

## Task 2: Classe `EmployeeDocument` — scaffolding e CRUD base

**Files:**
- Create: `src/classes/EmployeeDocument.php`

- [ ] **Step 1: Creare la classe con metodi di lettura e upload**

```php
<?php
/**
 * EmployeeDocument - documenti generici associati a un dipendente (contratti,
 * documenti identita, certificati). Separato dal sistema buste paga/CUD.
 */

class EmployeeDocument
{
    public static function getById(int $id): ?array
    {
        return Database::fetchOne(
            "SELECT ed.*, u.name AS uploaded_by_name,
                    e.first_name, e.last_name, e.fiscal_code,
                    CONCAT(e.last_name, ' ', e.first_name) AS employee_name
             FROM employee_documents ed
             JOIN employees e ON ed.employee_id = e.id
             JOIN users u ON ed.uploaded_by = u.id
             WHERE ed.id = ?",
            [$id]
        );
    }

    public static function getByEmployee(int $employeeId, ?bool $onlyVisible = null): array
    {
        $sql = "SELECT ed.*, u.name AS uploaded_by_name
                FROM employee_documents ed
                JOIN users u ON ed.uploaded_by = u.id
                WHERE ed.employee_id = ?";
        $params = [$employeeId];

        if ($onlyVisible === true) {
            $sql .= " AND ed.visible_to_employee = 1";
        }

        $sql .= " ORDER BY ed.created_at DESC";

        return Database::fetchAll($sql, $params);
    }

    public static function upload(array $file, array $data): array
    {
        if (empty($data['employee_id'])) {
            return ['success' => false, 'error' => 'Dipendente non specificato'];
        }

        $name = trim($data['name'] ?? '');
        if ($name === '') {
            return ['success' => false, 'error' => 'Nome documento obbligatorio'];
        }
        if (mb_strlen($name) > 255) {
            $name = mb_substr($name, 0, 255);
        }

        $employee = Employee::getById((int) $data['employee_id']);
        if (!$employee) {
            return ['success' => false, 'error' => 'Dipendente non trovato'];
        }

        $fileValidation = self::validateFile($file);
        if (!$fileValidation['valid']) {
            return ['success' => false, 'error' => $fileValidation['error']];
        }

        $expiresOn = !empty($data['expires_on']) ? $data['expires_on'] : null;
        if ($expiresOn !== null && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $expiresOn)) {
            return ['success' => false, 'error' => 'Data scadenza non valida'];
        }

        $visible = !empty($data['visible_to_employee']) ? 1 : 0;

        $extension = pathinfo($file['name'], PATHINFO_EXTENSION);
        $fileName = bin2hex(random_bytes(16)) . '.' . strtolower($extension);

        $directory = DOCUMENTS_PATH . '/employee-docs/' . (int) $data['employee_id'];
        if (!is_dir($directory)) {
            mkdir($directory, 0755, true);
        }

        $filePath = $directory . '/' . $fileName;

        if (!move_uploaded_file($file['tmp_name'], $filePath)) {
            return ['success' => false, 'error' => 'Errore durante il salvataggio del file'];
        }

        $user = Auth::getUser();
        $companyId = (int) ($employee['company_id'] ?? (class_exists('Tenant') ? Tenant::currentCompanyId() : 1));

        try {
            $id = Database::insert('employee_documents', [
                'company_id' => $companyId,
                'employee_id' => (int) $data['employee_id'],
                'name' => $name,
                'file_path' => $filePath,
                'file_name' => $fileName,
                'original_name' => $file['name'],
                'file_size' => $file['size'],
                'mime_type' => $fileValidation['mime_type'],
                'visible_to_employee' => $visible,
                'expires_on' => $expiresOn,
                'uploaded_by' => $user['id']
            ]);

            self::logAction('employee_document_uploaded', $id, null, [
                'employee_id' => $data['employee_id'],
                'name' => $name,
                'visible' => $visible
            ]);

            if ($visible) {
                self::notifyEmployee($employee, $name);
            }

            return ['success' => true, 'id' => $id];
        } catch (Exception $e) {
            if (file_exists($filePath)) {
                unlink($filePath);
            }
            return ['success' => false, 'error' => 'Errore durante il salvataggio: ' . $e->getMessage()];
        }
    }

    private static function validateFile(array $file): array
    {
        if ($file['error'] !== UPLOAD_ERR_OK) {
            return ['valid' => false, 'error' => 'Errore upload (codice ' . $file['error'] . ')'];
        }
        if ($file['size'] > MAX_FILE_SIZE) {
            $maxMb = MAX_FILE_SIZE / 1024 / 1024;
            return ['valid' => false, 'error' => "File troppo grande. Massimo {$maxMb}MB"];
        }
        $extension = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        if (!in_array($extension, ALLOWED_EXTENSIONS, true)) {
            return ['valid' => false, 'error' => 'Estensione file non consentita'];
        }
        $finfo = new finfo(FILEINFO_MIME_TYPE);
        $mimeType = $finfo->file($file['tmp_name']);
        if (!in_array($mimeType, ALLOWED_MIME_TYPES, true)) {
            return ['valid' => false, 'error' => 'Tipo file non consentito'];
        }
        return ['valid' => true, 'mime_type' => $mimeType];
    }

    private static function logAction(string $action, int $entityId, ?array $oldValues, ?array $newValues): void
    {
        $user = Auth::getUser();
        $employee = Auth::getEmployee();
        $userType = 'employee';
        $userId = 0;
        if ($user) {
            $userType = $user['role'];
            $userId = $user['id'];
        } elseif ($employee) {
            $userId = $employee['id'];
        }
        try {
            AuditLog::logEntityChange($action, $userType, $userId, 'employee_document', $entityId, $oldValues, $newValues);
        } catch (Throwable $e) {
            error_log('[EmployeeDocument] audit log failed: ' . $e->getMessage());
        }
    }

    private static function notifyEmployee(array $employee, string $documentName): void
    {
        try {
            if (class_exists('PushNotification')) {
                PushNotification::notifyNewDocument(
                    (int) $employee['id'],
                    'altro',
                    $documentName
                );
            }
        } catch (Throwable $e) {
            error_log('[EmployeeDocument] push error: ' . $e->getMessage());
        }

        try {
            if (!empty($employee['email']) && class_exists('Mailer') && Mailer::isConfigured()) {
                $loginUrl = function_exists('buildPublicUrl')
                    ? buildPublicUrl('/auth/login-employee.php')
                    : (defined('PUBLIC_URL') ? PUBLIC_URL . '/auth/login-employee.php' : '');
                $fullName = trim($employee['first_name'] . ' ' . $employee['last_name']);
                $nameSafe = htmlspecialchars($fullName);
                $docSafe = htmlspecialchars($documentName);
                $html = "<p>Ciao {$nameSafe},</p>"
                      . "<p>E' stato caricato un nuovo documento nella tua area personale:</p>"
                      . "<p><strong>{$docSafe}</strong></p>"
                      . "<p><a href=\"{$loginUrl}\">Accedi al portale per consultarlo</a></p>";
                $text = "Ciao {$fullName},\n\nNuovo documento disponibile: {$documentName}\n\nAccedi: {$loginUrl}";
                Mailer::send($employee['email'], $fullName, "Nuovo documento disponibile: {$documentName}", $html, $text);
            }
        } catch (Throwable $e) {
            error_log('[EmployeeDocument] mail error: ' . $e->getMessage());
        }
    }
}
```

- [ ] **Step 2: Verifica caricamento classe**

Apri qualsiasi pagina admin loggato (es. `http://localhost/app-gestionali/gestionalepa/public/admin/index.php`). Non devono apparire errori PHP. Se appare "Class not found", controllare che il file sia in `src/classes/EmployeeDocument.php` con nome esatto.

- [ ] **Step 3: Commit**

```bash
git add src/classes/EmployeeDocument.php
git commit -m "feat: add EmployeeDocument class with upload + read"
```

---

## Task 3: `EmployeeDocument` — update, delete, download

**Files:**
- Modify: `src/classes/EmployeeDocument.php`

- [ ] **Step 1: Aggiungere metodi `update`, `delete`, `download`**

Aggiungere prima del `private static function validateFile` esistente:

```php
    public static function update(int $id, array $data): array
    {
        $document = self::getById($id);
        if (!$document) {
            return ['success' => false, 'error' => 'Documento non trovato'];
        }

        $updateData = [];

        if (isset($data['name'])) {
            $name = trim($data['name']);
            if ($name === '') {
                return ['success' => false, 'error' => 'Il nome non puo essere vuoto'];
            }
            $updateData['name'] = mb_substr($name, 0, 255);
        }

        $visibilityChangedToVisible = false;
        if (array_key_exists('visible_to_employee', $data)) {
            $newVisible = !empty($data['visible_to_employee']) ? 1 : 0;
            $updateData['visible_to_employee'] = $newVisible;
            if ($newVisible === 1 && (int) $document['visible_to_employee'] === 0) {
                $visibilityChangedToVisible = true;
            }
        }

        if (array_key_exists('expires_on', $data)) {
            $exp = $data['expires_on'];
            if ($exp === '' || $exp === null) {
                $updateData['expires_on'] = null;
            } elseif (preg_match('/^\d{4}-\d{2}-\d{2}$/', $exp)) {
                $updateData['expires_on'] = $exp;
            } else {
                return ['success' => false, 'error' => 'Data scadenza non valida'];
            }
        }

        if (empty($updateData)) {
            return ['success' => false, 'error' => 'Nessun dato da aggiornare'];
        }

        try {
            Database::update('employee_documents', $updateData, 'id = ?', [$id]);
            self::logAction('employee_document_updated', $id, $document, $updateData);

            if ($visibilityChangedToVisible) {
                $employee = Employee::getById((int) $document['employee_id']);
                if ($employee) {
                    $newName = $updateData['name'] ?? $document['name'];
                    self::notifyEmployee($employee, $newName);
                }
            }

            return ['success' => true];
        } catch (Exception $e) {
            return ['success' => false, 'error' => 'Errore durante l\'aggiornamento'];
        }
    }

    public static function delete(int $id): array
    {
        $document = self::getById($id);
        if (!$document) {
            return ['success' => false, 'error' => 'Documento non trovato'];
        }
        try {
            if (file_exists($document['file_path'])) {
                unlink($document['file_path']);
            }
            Database::delete('employee_documents', 'id = ?', [$id]);
            self::logAction('employee_document_deleted', $id, $document, null);
            return ['success' => true];
        } catch (Exception $e) {
            return ['success' => false, 'error' => 'Errore durante l\'eliminazione'];
        }
    }

    public static function download(int $id): array
    {
        $document = self::getById($id);
        if (!$document) {
            return ['success' => false, 'error' => 'Documento non trovato'];
        }

        $user = Auth::getUser();
        $employee = Auth::getEmployee();
        $userType = 'unknown';
        $userId = 0;

        if ($user) {
            $userType = $user['role'];
            $userId = $user['id'];
            // Scope check per admin_reparto
            if ($userType === 'admin_reparto') {
                $emp = Employee::getById((int) $document['employee_id']);
                if (!$emp || (int) ($emp['department_id'] ?? 0) !== (int) ($user['department_id'] ?? -1)) {
                    AuditLog::logUnauthorizedAccess('employee_document', [
                        'document_id' => $id,
                        'user_id' => $userId,
                        'reason' => 'department_scope'
                    ]);
                    return ['success' => false, 'error' => 'Accesso non autorizzato'];
                }
            }
        } elseif ($employee) {
            $userType = 'employee';
            $userId = $employee['id'];
            if ((int) $document['employee_id'] !== (int) $employee['id']
                || (int) $document['visible_to_employee'] !== 1) {
                AuditLog::logUnauthorizedAccess('employee_document', [
                    'document_id' => $id,
                    'employee_id' => $employee['id'],
                    'owner_id' => $document['employee_id'],
                    'visible' => $document['visible_to_employee']
                ]);
                return ['success' => false, 'error' => 'Accesso non autorizzato'];
            }
        } else {
            return ['success' => false, 'error' => 'Autenticazione richiesta'];
        }

        if (function_exists('checkDownloadRateLimit')) {
            $rateCheck = checkDownloadRateLimit($userId, $userType);
            if (!$rateCheck['allowed']) {
                return ['success' => false, 'error' => $rateCheck['message']];
            }
        }

        if (!file_exists($document['file_path'])) {
            return ['success' => false, 'error' => 'File non trovato sul server'];
        }

        self::trackDownload($id, $userType, $userId);
        self::logAction('employee_document_downloaded', $id, null, ['user_type' => $userType, 'user_id' => $userId]);

        return [
            'success' => true,
            'document' => $document,
            'file_path' => $document['file_path']
        ];
    }

    private static function trackDownload(int $documentId, string $userType, int $userId): void
    {
        try {
            Database::insert('employee_document_downloads', [
                'employee_document_id' => $documentId,
                'user_type' => $userType,
                'user_id' => $userId,
                'ip_address' => function_exists('getClientIp') ? getClientIp() : ($_SERVER['REMOTE_ADDR'] ?? null),
                'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? null
            ]);
        } catch (Exception $e) {
            error_log('[EmployeeDocument] download tracking failed: ' . $e->getMessage());
        }
    }
```

- [ ] **Step 2: Verifica caricamento**

Ricarica una pagina admin. Nessun errore PHP. Lint manuale: la classe deve avere ora i metodi `getById`, `getByEmployee`, `upload`, `update`, `delete`, `download` + helpers privati.

- [ ] **Step 3: Commit**

```bash
git add src/classes/EmployeeDocument.php
git commit -m "feat: add update/delete/download to EmployeeDocument"
```

---

## Task 4: Endpoint admin per azioni

**Files:**
- Create: `public/admin/employee-documents.php`

- [ ] **Step 1: Creare l'endpoint**

```php
<?php
/**
 * Endpoint azioni Documenti Dipendente - Admin.
 * Gestisce upload, rename, toggle visibilita, scadenza, delete, download.
 * Redirect su employees.php?action=view&id=X#docs dopo le azioni POST.
 */

require_once dirname(__DIR__, 2) . '/config/config.php';

Auth::init();
setSecurityHeaders();
Auth::requireUser('admin');

$user = Auth::getUser();

function ed_redirect_back(int $employeeId, string $status = ''): void
{
    $url = 'employees.php?action=view&id=' . $employeeId . '#docs';
    if ($status !== '') {
        $url .= '&ed_status=' . urlencode($status);
    }
    header('Location: ' . $url);
    exit;
}

// GET download
if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['download'])) {
    $docId = (int) $_GET['download'];
    $result = EmployeeDocument::download($docId);
    if (!$result['success']) {
        http_response_code(403);
        echo htmlspecialchars($result['error']);
        exit;
    }
    $doc = $result['document'];
    $downloadName = preg_replace('/[^a-zA-Z0-9._-]/', '_', $doc['original_name'] ?? $doc['file_name']);
    if (function_exists('setDownloadHeaders')) {
        setDownloadHeaders($downloadName, $doc['mime_type'], filesize($result['file_path']));
    } else {
        header('Content-Type: ' . $doc['mime_type']);
        header('Content-Disposition: attachment; filename="' . $downloadName . '"');
        header('Content-Length: ' . filesize($result['file_path']));
    }
    if (ob_get_level()) { ob_end_clean(); }
    readfile($result['file_path']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit('Method not allowed');
}

CSRF::verifyOrDie();

$action = $_POST['action'] ?? '';
$employeeId = (int) ($_POST['employee_id'] ?? 0);

if (!$employeeId) {
    http_response_code(400);
    exit('employee_id mancante');
}

if ($action === 'upload') {
    if (!isset($_FILES['document']) || $_FILES['document']['error'] === UPLOAD_ERR_NO_FILE) {
        ed_redirect_back($employeeId, 'no_file');
    }
    $result = EmployeeDocument::upload($_FILES['document'], [
        'employee_id' => $employeeId,
        'name' => $_POST['name'] ?? '',
        'visible_to_employee' => !empty($_POST['visible_to_employee']) ? 1 : 0,
        'expires_on' => $_POST['expires_on'] ?? null
    ]);
    ed_redirect_back($employeeId, $result['success'] ? 'uploaded' : 'error_' . urlencode($result['error']));
}

if ($action === 'rename' || $action === 'toggle_visibility' || $action === 'update_expiry') {
    $docId = (int) ($_POST['document_id'] ?? 0);
    if (!$docId) { ed_redirect_back($employeeId, 'invalid'); }
    $payload = [];
    if ($action === 'rename') {
        $payload['name'] = $_POST['name'] ?? '';
    } elseif ($action === 'toggle_visibility') {
        $current = EmployeeDocument::getById($docId);
        if (!$current) { ed_redirect_back($employeeId, 'notfound'); }
        $payload['visible_to_employee'] = (int) $current['visible_to_employee'] === 1 ? 0 : 1;
    } elseif ($action === 'update_expiry') {
        $payload['expires_on'] = $_POST['expires_on'] ?? '';
    }
    $result = EmployeeDocument::update($docId, $payload);
    ed_redirect_back($employeeId, $result['success'] ? 'updated' : 'error');
}

if ($action === 'delete') {
    $docId = (int) ($_POST['document_id'] ?? 0);
    if (!$docId) { ed_redirect_back($employeeId, 'invalid'); }
    $result = EmployeeDocument::delete($docId);
    ed_redirect_back($employeeId, $result['success'] ? 'deleted' : 'error');
}

ed_redirect_back($employeeId, 'unknown_action');
```

- [ ] **Step 2: Verifica accesso endpoint**

Apri `http://localhost/app-gestionali/gestionalepa/public/admin/employee-documents.php` da browser non loggato → deve redirigere al login admin. Loggato come admin con GET → ritorna `Method not allowed` (405) perché manca download.

- [ ] **Step 3: Commit**

```bash
git add public/admin/employee-documents.php
git commit -m "feat: add admin endpoint for employee documents"
```

---

## Task 5: Sezione UI nella scheda dipendente (admin)

**Files:**
- Modify: `public/admin/employees.php` (ramo `action === 'view'`, prima del tag di chiusura del wrapper view — vedi `?action=view&id=` esistente intorno alle righe 597-650)

- [ ] **Step 1: Inserire blocco HTML nel ramo view**

Aprire `public/admin/employees.php`, individuare il ramo `<?php elseif ($action === 'view' && $employee): ?>` (intorno alla riga 597). Subito prima del `<?php endif; ?>` finale di quel ramo (o prima della chiusura della card delle azioni — usare grep per trovare il punto), aggiungere:

```php
<?php
// Sezione Documenti Dipendente (generici, non buste paga)
$_edDocs = EmployeeDocument::getByEmployee((int) $employee['id']);
$_edStatus = $_GET['ed_status'] ?? '';
?>
<div id="docs" class="card" style="margin-top: 1.5rem;">
    <div class="card-header" style="display:flex;justify-content:space-between;align-items:center;">
        <h3 style="margin:0;">Documenti dipendente</h3>
        <button type="button" class="btn btn-primary btn-sm" onclick="document.getElementById('ed-upload-modal').style.display='flex';">
            Carica documento
        </button>
    </div>
    <div class="card-body">
        <?php if ($_edStatus === 'uploaded'): ?>
            <div class="alert alert-success">Documento caricato.</div>
        <?php elseif ($_edStatus === 'updated'): ?>
            <div class="alert alert-success">Documento aggiornato.</div>
        <?php elseif ($_edStatus === 'deleted'): ?>
            <div class="alert alert-success">Documento eliminato.</div>
        <?php elseif (strpos($_edStatus, 'error') === 0): ?>
            <div class="alert alert-danger">Errore: <?= htmlspecialchars(substr($_edStatus, 6)) ?></div>
        <?php endif; ?>

        <?php if (empty($_edDocs)): ?>
            <p style="color:#666;">Nessun documento caricato.</p>
        <?php else: ?>
        <table class="table">
            <thead>
                <tr>
                    <th>Nome</th>
                    <th>Dimensione</th>
                    <th>Visibile al dipendente</th>
                    <th>Scadenza</th>
                    <th>Caricato</th>
                    <th>Azioni</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($_edDocs as $d): ?>
                <tr>
                    <td><?= htmlspecialchars($d['name']) ?></td>
                    <td><?= number_format($d['file_size'] / 1024, 1) ?> KB</td>
                    <td>
                        <form method="post" action="employee-documents.php" style="display:inline;">
                            <?= CSRF::field() ?>
                            <input type="hidden" name="action" value="toggle_visibility">
                            <input type="hidden" name="employee_id" value="<?= (int) $employee['id'] ?>">
                            <input type="hidden" name="document_id" value="<?= (int) $d['id'] ?>">
                            <button type="submit" class="btn btn-sm <?= $d['visible_to_employee'] ? 'btn-success' : 'btn-secondary' ?>">
                                <?= $d['visible_to_employee'] ? 'Si' : 'No' ?>
                            </button>
                        </form>
                    </td>
                    <td><?= $d['expires_on'] ? htmlspecialchars($d['expires_on']) : '-' ?></td>
                    <td><?= htmlspecialchars(date('d/m/Y', strtotime($d['created_at']))) ?><br><small><?= htmlspecialchars($d['uploaded_by_name']) ?></small></td>
                    <td>
                        <a href="employee-documents.php?download=<?= (int) $d['id'] ?>" class="btn btn-sm btn-info">Scarica</a>
                        <button type="button" class="btn btn-sm btn-secondary"
                                onclick="var n=prompt('Nuovo nome:', <?= json_encode($d['name']) ?>); if(n){var f=document.getElementById('ed-rename-' + <?= (int) $d['id'] ?>); f.querySelector('input[name=name]').value=n; f.submit();}">
                            Rinomina
                        </button>
                        <form id="ed-rename-<?= (int) $d['id'] ?>" method="post" action="employee-documents.php" style="display:none;">
                            <?= CSRF::field() ?>
                            <input type="hidden" name="action" value="rename">
                            <input type="hidden" name="employee_id" value="<?= (int) $employee['id'] ?>">
                            <input type="hidden" name="document_id" value="<?= (int) $d['id'] ?>">
                            <input type="hidden" name="name" value="">
                        </form>
                        <form method="post" action="employee-documents.php" style="display:inline;" onsubmit="return confirm('Eliminare definitivamente?');">
                            <?= CSRF::field() ?>
                            <input type="hidden" name="action" value="delete">
                            <input type="hidden" name="employee_id" value="<?= (int) $employee['id'] ?>">
                            <input type="hidden" name="document_id" value="<?= (int) $d['id'] ?>">
                            <button type="submit" class="btn btn-sm btn-danger">Elimina</button>
                        </form>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <?php endif; ?>
    </div>
</div>

<!-- Modale upload -->
<div id="ed-upload-modal" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.5);z-index:1000;align-items:center;justify-content:center;">
    <div style="background:#fff;padding:2rem;border-radius:8px;max-width:480px;width:90%;">
        <h3>Carica documento</h3>
        <form method="post" action="employee-documents.php" enctype="multipart/form-data">
            <?= CSRF::field() ?>
            <input type="hidden" name="action" value="upload">
            <input type="hidden" name="employee_id" value="<?= (int) $employee['id'] ?>">
            <div style="margin-bottom:1rem;">
                <label>Nome documento *</label>
                <input type="text" name="name" required maxlength="255" class="form-control" placeholder="es. Contratto 2026">
            </div>
            <div style="margin-bottom:1rem;">
                <label>File *</label>
                <input type="file" name="document" required class="form-control">
            </div>
            <div style="margin-bottom:1rem;">
                <label>Scadenza (opzionale)</label>
                <input type="date" name="expires_on" class="form-control">
            </div>
            <div style="margin-bottom:1rem;">
                <label><input type="checkbox" name="visible_to_employee" value="1"> Rendi visibile al dipendente</label>
            </div>
            <div style="display:flex;gap:.5rem;justify-content:flex-end;">
                <button type="button" class="btn btn-secondary" onclick="document.getElementById('ed-upload-modal').style.display='none';">Annulla</button>
                <button type="submit" class="btn btn-primary">Carica</button>
            </div>
        </form>
    </div>
</div>
```

- [ ] **Step 2: Verifica nel browser**

1. Login admin → `Dipendenti` → click su un dipendente esistente.
2. Scrolla in fondo: deve apparire la card "Documenti dipendente" con "Nessun documento caricato.".
3. Click "Carica documento" → si apre la modale.
4. Inserisci nome "Test contratto", scegli un piccolo PDF, lascia "visibile" off, submit.
5. Dopo redirect: la riga compare in tabella con dimensione, "No" sulla visibilità, scadenza "-".
6. Click "Si/No" sulla colonna visibilità → toggle funziona.
7. Click "Rinomina" → prompt → conferma → nome aggiornato.
8. Click "Scarica" → download del file originale.
9. Click "Elimina" → conferma → la riga sparisce, e in `database/` la riga è eliminata.

Tutti i passi devono funzionare senza errori PHP.

- [ ] **Step 3: Commit**

```bash
git add public/admin/employees.php
git commit -m "feat(admin): wire employee documents section into employee view"
```

---

## Task 6: Endpoint e UI lato admin_reparto

**Files:**
- Create: `public/admin-reparto/employee-documents.php`
- Modify: `public/admin-reparto/employees.php` (aggiungere link/sezione)

- [ ] **Step 1: Creare l'endpoint admin_reparto con scope check sul reparto**

```php
<?php
/**
 * Endpoint Documenti Dipendente - Admin Reparto.
 * Stesse azioni dell'endpoint admin, ma con scope check sul reparto.
 */

require_once dirname(__DIR__, 2) . '/config/config.php';

Auth::init();
setSecurityHeaders();
Auth::requireUser('admin_reparto');

$user = Auth::getUser();
$departmentId = (int) ($user['department_id'] ?? 0);
if (!$departmentId) {
    http_response_code(403);
    exit('Nessun reparto assegnato');
}

function ed_assert_employee_in_dept(int $employeeId, int $departmentId): void
{
    $emp = Employee::getById($employeeId);
    if (!$emp || (int) ($emp['department_id'] ?? 0) !== $departmentId) {
        AuditLog::logUnauthorizedAccess('employee_document', [
            'employee_id' => $employeeId,
            'reason' => 'cross_department'
        ]);
        http_response_code(403);
        exit('Dipendente fuori dal tuo reparto');
    }
}

function ed_redirect_back_reparto(int $employeeId, string $status = ''): void
{
    $url = 'employees.php?action=view&id=' . $employeeId . '#docs';
    if ($status !== '') {
        $url .= '&ed_status=' . urlencode($status);
    }
    header('Location: ' . $url);
    exit;
}

// GET download
if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['download'])) {
    $docId = (int) $_GET['download'];
    $doc = EmployeeDocument::getById($docId);
    if ($doc) {
        ed_assert_employee_in_dept((int) $doc['employee_id'], $departmentId);
    }
    $result = EmployeeDocument::download($docId);
    if (!$result['success']) {
        http_response_code(403);
        echo htmlspecialchars($result['error']);
        exit;
    }
    $d = $result['document'];
    $downloadName = preg_replace('/[^a-zA-Z0-9._-]/', '_', $d['original_name'] ?? $d['file_name']);
    if (function_exists('setDownloadHeaders')) {
        setDownloadHeaders($downloadName, $d['mime_type'], filesize($result['file_path']));
    } else {
        header('Content-Type: ' . $d['mime_type']);
        header('Content-Disposition: attachment; filename="' . $downloadName . '"');
        header('Content-Length: ' . filesize($result['file_path']));
    }
    if (ob_get_level()) { ob_end_clean(); }
    readfile($result['file_path']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit('Method not allowed');
}

CSRF::verifyOrDie();

$action = $_POST['action'] ?? '';
$employeeId = (int) ($_POST['employee_id'] ?? 0);
if (!$employeeId) {
    http_response_code(400);
    exit('employee_id mancante');
}

ed_assert_employee_in_dept($employeeId, $departmentId);

if ($action === 'upload') {
    if (!isset($_FILES['document']) || $_FILES['document']['error'] === UPLOAD_ERR_NO_FILE) {
        ed_redirect_back_reparto($employeeId, 'no_file');
    }
    $result = EmployeeDocument::upload($_FILES['document'], [
        'employee_id' => $employeeId,
        'name' => $_POST['name'] ?? '',
        'visible_to_employee' => !empty($_POST['visible_to_employee']) ? 1 : 0,
        'expires_on' => $_POST['expires_on'] ?? null
    ]);
    ed_redirect_back_reparto($employeeId, $result['success'] ? 'uploaded' : 'error_' . urlencode($result['error']));
}

if (in_array($action, ['rename', 'toggle_visibility', 'update_expiry', 'delete'], true)) {
    $docId = (int) ($_POST['document_id'] ?? 0);
    if (!$docId) { ed_redirect_back_reparto($employeeId, 'invalid'); }
    $current = EmployeeDocument::getById($docId);
    if (!$current) { ed_redirect_back_reparto($employeeId, 'notfound'); }
    ed_assert_employee_in_dept((int) $current['employee_id'], $departmentId);

    if ($action === 'delete') {
        $result = EmployeeDocument::delete($docId);
    } else {
        $payload = [];
        if ($action === 'rename') {
            $payload['name'] = $_POST['name'] ?? '';
        } elseif ($action === 'toggle_visibility') {
            $payload['visible_to_employee'] = (int) $current['visible_to_employee'] === 1 ? 0 : 1;
        } elseif ($action === 'update_expiry') {
            $payload['expires_on'] = $_POST['expires_on'] ?? '';
        }
        $result = EmployeeDocument::update($docId, $payload);
    }
    ed_redirect_back_reparto($employeeId, $result['success'] ? 'ok' : 'error');
}

ed_redirect_back_reparto($employeeId, 'unknown_action');
```

- [ ] **Step 2: Verificare presenza ramo `action === 'view'` in `public/admin-reparto/employees.php`**

```bash
grep -n "action.*view\|view.*action" public/admin-reparto/employees.php
```

Se NON esiste un ramo view (la pagina è solo lista), aggiungerlo: dopo il blocco lista, prima della chiusura, inserire:

```php
<?php } elseif (($_GET['action'] ?? '') === 'view' && !empty($_GET['id'])):
    $employee = Employee::getById((int) $_GET['id']);
    if (!$employee || (int) ($employee['department_id'] ?? 0) !== (int) $departmentId) {
        echo '<div class="alert alert-danger">Dipendente non trovato o fuori dal tuo reparto.</div>';
    } else {
        // Riusa lo stesso blocco "Documenti dipendente" del Task 5, ma puntando
        // a employee-documents.php (relativo a admin-reparto/, quindi stesso path)
        ?>
        <h2>Dettaglio dipendente: <?= htmlspecialchars($employee['first_name'] . ' ' . $employee['last_name']) ?></h2>
        <?php
        // [Inserire QUI lo stesso blocco card+modale del Task 5, con stesse action="employee-documents.php"]
    }
?>
```

In alternativa, se la view dipendente per admin_reparto esiste già altrove, individuarla con `grep -rn "action.*view" public/admin-reparto/` e inserire la sezione lì.

**Nota:** Il blocco HTML della sezione documenti (card + tabella + modale) è identico al Task 5. Copiare verbatim quel blocco, sostituendo SOLO l'eventuale path action (resta `employee-documents.php` perché siamo nella stessa cartella admin-reparto).

- [ ] **Step 3: Verifica nel browser**

1. Login come `admin_reparto` (utente con `department_id` valorizzato).
2. Aprire un dipendente del proprio reparto → sezione "Documenti dipendente" presente.
3. Caricare, rinominare, toggle, scaricare, eliminare. Tutto funziona.
4. Tentare di accedere a `employee-documents.php?download=<id-di-doc-altro-reparto>` → deve dare 403.

- [ ] **Step 4: Commit**

```bash
git add public/admin-reparto/employee-documents.php public/admin-reparto/employees.php
git commit -m "feat(admin-reparto): wire employee documents with department scope"
```

---

## Task 7: Pagina lato dipendente

**Files:**
- Create: `public/employee/my-documents.php`
- Modify: `public/includes/header-employee.php` (aggiungere voce sidebar)

- [ ] **Step 1: Creare `public/employee/my-documents.php`**

```php
<?php
/**
 * I miei documenti (generici, non buste paga) - Dipendente.
 * Mostra solo documenti con visible_to_employee = 1.
 */

require_once dirname(__DIR__, 2) . '/config/config.php';

Auth::init();
setSecurityHeaders();
Auth::requireEmployee();

$employee = Auth::getEmployee();
$error = '';

// Download
if (isset($_GET['download'])) {
    $docId = (int) $_GET['download'];
    $result = EmployeeDocument::download($docId);
    if ($result['success']) {
        $doc = $result['document'];
        $downloadName = preg_replace('/[^a-zA-Z0-9._-]/', '_', $doc['original_name'] ?? $doc['file_name']);
        if (function_exists('setDownloadHeaders')) {
            setDownloadHeaders($downloadName, $doc['mime_type'], filesize($result['file_path']));
        } else {
            header('Content-Type: ' . $doc['mime_type']);
            header('Content-Disposition: attachment; filename="' . $downloadName . '"');
            header('Content-Length: ' . filesize($result['file_path']));
        }
        if (ob_get_level()) { ob_end_clean(); }
        readfile($result['file_path']);
        exit;
    }
    $error = $result['error'];
}

$documents = EmployeeDocument::getByEmployee((int) $employee['id'], true);

$pageTitle = 'I miei documenti';
include dirname(__DIR__) . '/includes/header-employee.php';
?>

<div class="dashboard">
    <h2>I miei documenti</h2>
    <?php if ($error): ?>
        <div class="alert alert-danger"><?= htmlspecialchars($error) ?></div>
    <?php endif; ?>

    <?php if (empty($documents)): ?>
        <p style="color:#666;">Non ci sono ancora documenti disponibili per te.</p>
    <?php else: ?>
        <table class="table">
            <thead>
                <tr>
                    <th>Nome</th>
                    <th>Dimensione</th>
                    <th>Scadenza</th>
                    <th>Data caricamento</th>
                    <th>Azione</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($documents as $d): ?>
                <tr>
                    <td><?= htmlspecialchars($d['name']) ?></td>
                    <td><?= number_format($d['file_size'] / 1024, 1) ?> KB</td>
                    <td><?= $d['expires_on'] ? htmlspecialchars($d['expires_on']) : '-' ?></td>
                    <td><?= htmlspecialchars(date('d/m/Y', strtotime($d['created_at']))) ?></td>
                    <td><a class="btn btn-sm btn-info" href="?download=<?= (int) $d['id'] ?>">Scarica</a></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>
</div>

<?php include dirname(__DIR__) . '/includes/footer-employee.php'; ?>
```

- [ ] **Step 2: Aggiungere voce sidebar in `public/includes/header-employee.php`**

Trovare la voce esistente "Documenti" (buste paga) che punta a `documents.php` e aggiungere subito sotto:

```php
<a href="<?= buildPublicUrl('/employee/my-documents.php') ?>" class="nav-item">
    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor" style="width:20px;height:20px;">
        <path d="M14 2H6c-1.1 0-2 .9-2 2v16c0 1.1.9 2 2 2h12c1.1 0 2-.9 2-2V8l-6-6zm2 16H8v-2h8v2zm0-4H8v-2h8v2zm-3-5V3.5L18.5 9H13z"/>
    </svg>
    I miei documenti
</a>
```

(Adattare la classe CSS e la sintassi del link al pattern esattamente usato dalle altre voci. Aprire prima `header-employee.php` per copiare il formato esistente.)

- [ ] **Step 3: Verifica nel browser**

1. Login come dipendente che NON ha documenti generici visibili → `I miei documenti` mostra "Non ci sono ancora documenti disponibili per te.".
2. Da admin, caricare un documento con flag "visibile" attivo per questo dipendente.
3. Ricaricare lato dipendente → il documento appare. Click "Scarica" → download corretto.
4. Da admin, togliere il flag visibile → ricarica lato dipendente → documento sparisce.
5. Provare `my-documents.php?download=<id-doc-altro-dipendente>` → 403 "Accesso non autorizzato".

- [ ] **Step 4: Commit**

```bash
git add public/employee/my-documents.php public/includes/header-employee.php
git commit -m "feat(employee): add 'I miei documenti' page"
```

---

## Task 8: Test end-to-end e fix finali

- [ ] **Step 1: Smoke test completo**

In ordine:
1. **Migration applicata** in locale (Task 1 step 3).
2. **Admin** carica 2 documenti per dipendente A: uno visibile, uno no.
3. **Dipendente A** vede solo quello visibile.
4. **Dipendente A** prova URL diretto del download del documento NON visibile → 403.
5. **Dipendente B** prova URL diretto del download di un documento di A → 403.
6. Admin **rinomina** il documento → cambia ovunque.
7. Admin **toggle visibilità** del documento nascosto → dipendente lo vede + (se SMTP configurato) arriva email + push.
8. Admin **imposta scadenza** → visibile in tabella admin.
9. Admin **elimina** documento → file fisico rimosso da `DOCUMENTS_PATH/employee-docs/<id>/`.
10. **admin_reparto** prova ad accedere a un dipendente fuori dal proprio reparto → 403.
11. **accountant** loggato: non deve avere voci/link per questa funzionalità (verifica: non ci sono entry point nei suoi menu).

- [ ] **Step 2: Audit log**

```bash
mysql -u root gestionalepa -e "SELECT action, entity_type, entity_id, created_at FROM audit_log WHERE entity_type='employee_document' ORDER BY id DESC LIMIT 10;"
```

Expected: righe `employee_document_uploaded`, `employee_document_updated`, `employee_document_deleted`, `employee_document_downloaded`.

- [ ] **Step 3: Verifica filesystem**

```bash
ls -la storage/documents/employee-docs/
```

Expected: una cartella per ogni `employee_id` che ha documenti, file con nomi random hex + estensione.

- [ ] **Step 4: Commit finale**

Se non sono stati necessari fix, niente da committare. Altrimenti:

```bash
git add -A
git commit -m "fix: address issues found in end-to-end testing"
```

---

## Self-review note

Coperture spec → task:
- Tabella `employee_documents` + indici + `employee_document_downloads` → Task 1.
- Classe `EmployeeDocument` (upload/get/update/delete/download + audit + notifiche) → Task 2-3.
- Endpoint admin → Task 4. Endpoint admin_reparto con scope check → Task 6.
- UI sezione admin → Task 5. UI sezione admin_reparto → Task 6.
- Pagina dipendente "I miei documenti" + sidebar → Task 7.
- Flag visibilità con notifica push+email sia su upload che su toggle 0→1 → Task 2 (`upload`) e Task 3 (`update`).
- Scadenza `expires_on` → schema (Task 1), parsing in upload (Task 2) e update (Task 3), UI admin (Task 5), UI dipendente (Task 7).
- Audit log + tracking download → Task 2-3 (metodi privati).
- Multi-tenant `company_id` ereditato dall'employee → Task 2 (`upload`).
- Rate limit download riusato da `checkDownloadRateLimit` → Task 3 (`download`).

Nessun placeholder TBD/TODO. Tutti i nomi metodo/parametro consistenti tra task (es. `visible_to_employee` ovunque, `employee_id` ovunque).
