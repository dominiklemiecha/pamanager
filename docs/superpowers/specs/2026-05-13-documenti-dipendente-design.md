# Documenti dipendente — Design

Data: 2026-05-13
Stato: approvato dall'utente, pronto per piano di implementazione

## Obiettivo

Permettere ad `admin` e `admin_reparto` di caricare, rinominare ed eliminare documenti generici associati a un dipendente (contratto, documento d'identità, certificati, attestati, …), con nome libero e senza vincolo di mese/anno. Per ogni documento si decide singolarmente se è visibile o no al dipendente nella sua area personale.

Funzionalità separata e parallela al sistema buste paga/CUD esistente (`Document` + tabella `documents`), che resta invariato.

## Out of scope

- Versionamento / storico versioni di un documento.
- Categorie o tag (lista piatta confermata).
- Watermark PDF su questi documenti (i documenti del sistema buste paga continuano ad averlo).
- Firma elettronica, richieste di firma.
- Bulk upload (un file alla volta).

## Permessi

| Ruolo | Carica | Rinomina | Toggle visibilità | Elimina | Vede |
|---|---|---|---|---|---|
| `admin` | sì (tutti) | sì | sì | sì | tutti |
| `admin_reparto` | sì (solo dipendenti del proprio reparto) | sì (idem) | sì (idem) | sì (idem) | solo dipendenti del proprio reparto |
| `accountant` | no | no | no | no | no |
| `employee` | no | no | no | no | sì, **solo** i propri documenti con `visible_to_employee = 1` |

L'accesso del dipendente è one-to-one come per `Document::download`: tentativi su documenti altrui o non visibili → `AuditLog::logUnauthorizedAccess` e rifiuto.

## Modello dati

Nuova tabella `employee_documents`:

| Colonna | Tipo | Note |
|---|---|---|
| `id` | INT PK AI | |
| `company_id` | INT NOT NULL | FK `companies(id)`, ereditato dall'employee |
| `employee_id` | INT NOT NULL | FK `employees(id)` ON DELETE CASCADE |
| `name` | VARCHAR(255) NOT NULL | nome libero scelto dall'admin |
| `file_path` | VARCHAR(500) NOT NULL | path assoluto su filesystem |
| `file_name` | VARCHAR(255) NOT NULL | nome random sul disco |
| `original_name` | VARCHAR(255) NOT NULL | nome file originale |
| `file_size` | INT NOT NULL | byte |
| `mime_type` | VARCHAR(100) NOT NULL | |
| `visible_to_employee` | TINYINT(1) NOT NULL DEFAULT 0 | flag |
| `expires_on` | DATE NULL | scadenza opzionale (patente, certificato medico…) |
| `uploaded_by` | INT NOT NULL | FK `users(id)` |
| `created_at` | TIMESTAMP DEFAULT CURRENT_TIMESTAMP | |
| `updated_at` | TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP | |

Indici: `(company_id, employee_id)`, `(employee_id, visible_to_employee)`, `(expires_on)`.

Migration: nuovo file in `database/migrations/` (numerazione progressiva, da scegliere in fase di piano).

Storage file: `DOCUMENTS_PATH/employee-docs/<employee_id>/<random>.ext` (nome random come per `Document::upload`).

## Componenti software

**`src/classes/EmployeeDocument.php`** (nuova classe, mirror leggero di `Document`):
- `getById(int $id): ?array`
- `getByEmployee(int $employeeId, ?bool $onlyVisible = null): array`
- `upload(array $file, array $data): array` — valida file con la stessa logica MIME/estensione/dimensione di `Document::validateFile`. Dati: `employee_id`, `name`, `visible_to_employee`, `expires_on?`.
- `update(int $id, array $data): array` — rinomina (`name`), toggle `visible_to_employee`, modifica `expires_on`. Se la visibilità passa 0→1, trigger notifica.
- `delete(int $id): array` — elimina file fisico + record.
- `download(int $id): array` — controlli accesso (admin/admin_reparto: scope reparto; employee: ownership + `visible_to_employee=1`). Niente watermark. Track download nella stessa tabella `document_downloads` con una nuova `document_kind` per distinguere, **oppure** in una tabella separata `employee_document_downloads`. Decisione: nuova tabella per non sporcare la FK esistente di `document_downloads → documents(id)`.
- `getExpiringSoon(int $days = 30): array` — utility per eventuale widget futuro.
- `logAction(...)` via `AuditLog::logEntityChange` con entity `'employee_document'`.

**`src/classes/EmployeeDocumentNotifier.php`** (o helper privato dentro la classe): invio push + email coerente con `Document::upload`. Triggerato su:
- upload con `visible_to_employee = 1`
- update che porta `visible_to_employee` da 0 a 1

## Pagine / endpoint

Tutte le pagine usano `header-admin.php` o `header-employee.php` esistenti, niente nuovi layout.

**Lato admin / admin_reparto**, nella scheda dipendente esistente:
- Sezione "Documenti dipendente" aggiunta sotto/accanto a "Buste paga".
- Tabella con colonne: Nome · Dimensione · Visibile · Scadenza · Caricato da · Data · Azioni.
- Azioni per riga: Scarica · Rinomina (inline o modale) · Toggle visibilità · Elimina (con conferma).
- Pulsante "Carica documento" → modale con: file, nome (default = nome file senza estensione), checkbox "rendi visibile al dipendente", data scadenza opzionale.

Endpoint POST necessari (nuovi file in `public/admin/` o cartella analoga seguendo il pattern esistente):
- `employee-documents/upload.php`
- `employee-documents/update.php` (rinomina, toggle, scadenza)
- `employee-documents/delete.php`
- `employee-documents/download.php`

Tutti CSRF-protected come gli endpoint esistenti.

**Lato dipendente**:
- Nuova voce sidebar "I miei documenti" in `header-employee.php`, separata da "Buste paga".
- Pagina `public/employee/documents.php`: lista solo dei propri con `visible_to_employee = 1`, ordinati per `created_at DESC`. Mostra nome, dimensione, data caricamento, scadenza se presente, pulsante Scarica.
- Download via `public/employee/document-download.php` (o riuso dello stesso endpoint admin con controllo ruolo).

## Notifiche

Coerenza con `Document::upload`:
- **Push**: `PushNotification::notifyNewDocument(employee_id, type='altro', period=name)` — oppure nuovo metodo dedicato `notifyEmployeeDocument` con titolo "Nuovo documento disponibile" + nome del documento. Decisione preferita: metodo dedicato per testo più pulito, fallback al metodo esistente se non implementato in tempo.
- **Email**: se `Mailer::isConfigured()`, manda email "Nuovo documento disponibile: <nome>" con link al portale.
- Trigger su: upload con flag visibile, oppure update che attiva il flag.
- Nessuna notifica per documenti interni (`visible_to_employee = 0`).

Errori di notifica non bloccano l'operazione (try/catch + `error_log`), stesso pattern di `Document::upload`.

## Audit & sicurezza

- Ogni azione (upload, rename, toggle visibility, delete, download) → `AuditLog::logEntityChange` con entity `'employee_document'`.
- Tentativo di accesso non autorizzato (dipendente che richiede doc non suo o non visibile) → `AuditLog::logUnauthorizedAccess`.
- Validazione file: stesse costanti `MAX_FILE_SIZE`, `ALLOWED_EXTENSIONS`, `ALLOWED_MIME_TYPES` già usate da `Document`.
- Rate limiting download: riuso `checkDownloadRateLimit($userId, $userType)` esistente.

## Migration & rollout

1. Migration SQL: crea `employee_documents` + `employee_document_downloads`.
2. Nessun backfill (feature nuova, tabella vuota).
3. Filesystem: directory `DOCUMENTS_PATH/employee-docs/` creata on-demand al primo upload (come fa già `Document::upload`).
4. Niente feature flag: feature self-contained, attivabile direttamente in produzione dopo test locali.

## Rischi noti

- **Spazio disco**: documenti tipo scansioni CI o contratti firmati possono pesare. Mitigation: stessa `MAX_FILE_SIZE` esistente (80MB lato `.htaccess` produzione) e monitoraggio manuale.
- **`admin_reparto` scope**: la verifica "dipendente appartiene al mio reparto" va fatta in tutti gli endpoint, non solo in UI. Va prevista una helper centralizzata (probabilmente esiste già — verificare in fase di piano).
- **company_id multi-tenant**: documenti devono filtrare per `company_id` come fa `Document::getAll`.
