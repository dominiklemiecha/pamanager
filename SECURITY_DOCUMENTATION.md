# PAManager - Documentazione Sicurezza

## Panoramica

Questo documento descrive tutte le funzionalità di sicurezza implementate nel sistema PAManager per la gestione documentale della Pubblica Amministrazione.

**Versione:** 1.0.0
**Data:** Gennaio 2026
**Classificazione:** Documento Tecnico per Project Manager

---

## Indice

1. [Architettura Sicurezza](#1-architettura-sicurezza)
2. [Fase 1: Sicurezza Core](#2-fase-1-sicurezza-core)
3. [Fase 2: Protezione File e Documenti](#3-fase-2-protezione-file-e-documenti)
4. [Fase 3: Autenticazione Multi-Fattore (MFA)](#4-fase-3-autenticazione-multi-fattore-mfa)
5. [Fase 4: Conformità GDPR](#5-fase-4-conformità-gdpr)
6. [Fase 5: Monitoraggio e Alert](#6-fase-5-monitoraggio-e-alert)
7. [Guida all'Installazione](#7-guida-allinstallazione)
8. [API e Integrazioni](#8-api-e-integrazioni)
9. [Checklist Compliance](#9-checklist-compliance)

---

## 1. Architettura Sicurezza

### 1.1 Schema Generale

```
┌─────────────────────────────────────────────────────────────────┐
│                        LAYER PRESENTAZIONE                       │
│  ┌─────────────┐  ┌─────────────┐  ┌─────────────────────────┐  │
│  │    Admin    │  │ Commerc.    │  │   Dipendente (SPID)     │  │
│  └──────┬──────┘  └──────┬──────┘  └───────────┬─────────────┘  │
└─────────┼────────────────┼─────────────────────┼────────────────┘
          │                │                     │
┌─────────▼────────────────▼─────────────────────▼────────────────┐
│                      LAYER SICUREZZA                             │
│  ┌──────────────────────────────────────────────────────────┐   │
│  │  HTTP Headers (CSP, HSTS, CORP, X-Frame-Options, etc.)   │   │
│  └──────────────────────────────────────────────────────────┘   │
│  ┌─────────────┐  ┌─────────────┐  ┌─────────────────────────┐  │
│  │    CSRF     │  │ Rate Limit  │  │    Session Manager      │  │
│  └─────────────┘  └─────────────┘  └─────────────────────────┘  │
│  ┌─────────────┐  ┌─────────────┐  ┌─────────────────────────┐  │
│  │     MFA     │  │  AuditLog   │  │    SecurityMonitor      │  │
│  └─────────────┘  └─────────────┘  └─────────────────────────┘  │
└─────────────────────────────────────────────────────────────────┘
          │
┌─────────▼───────────────────────────────────────────────────────┐
│                      LAYER APPLICATIVO                           │
│  ┌─────────────┐  ┌─────────────┐  ┌─────────────────────────┐  │
│  │    Auth     │  │  Document   │  │      GDPR               │  │
│  └─────────────┘  └─────────────┘  └─────────────────────────┘  │
└─────────────────────────────────────────────────────────────────┘
          │
┌─────────▼───────────────────────────────────────────────────────┐
│                        LAYER DATI                                │
│  ┌──────────────────────────────────────────────────────────┐   │
│  │                    MySQL Database                         │   │
│  │  (Encrypted connections, Prepared statements, Audit)      │   │
│  └──────────────────────────────────────────────────────────┘   │
└─────────────────────────────────────────────────────────────────┘
```

### 1.2 Ruoli e Permessi

| Ruolo | MFA | Timeout Sessione | Accesso Documenti | Gestione Utenti |
|-------|-----|------------------|-------------------|-----------------|
| Admin | Obbligatorio | 10 minuti | Tutti | Sì |
| Commercialista | Obbligatorio | 10 minuti | Tutti | No |
| Dipendente | Opzionale | 15 minuti | Solo propri | No |

---

## 2. Fase 1: Sicurezza Core

### 2.1 Policy Password

**File:** `config/config.php`, `src/classes/Auth.php`

**Requisiti implementati:**
- Lunghezza minima: 12 caratteri
- Almeno 1 lettera maiuscola
- Almeno 1 lettera minuscola
- Almeno 1 numero
- Almeno 1 carattere speciale (!@#$%^&*...)
- Hashing con Argon2id (fallback bcrypt)

**Esempio di validazione:**

```php
// Validazione password
$result = Auth::validatePassword('MiaPassword123!');

// Risposta OK
['valid' => true]

// Risposta con errori
[
    'valid' => false,
    'errors' => [
        'La password deve contenere almeno un carattere speciale'
    ]
]
```

**Configurazione (config.php):**

```php
define('PASSWORD_MIN_LENGTH', 12);
define('PASSWORD_REQUIRE_UPPERCASE', true);
define('PASSWORD_REQUIRE_LOWERCASE', true);
define('PASSWORD_REQUIRE_NUMBER', true);
define('PASSWORD_REQUIRE_SPECIAL', true);
```

### 2.2 Session Timeout per Ruolo

**Implementazione:**

```php
// Timeout differenziati
define('SESSION_TIMEOUT_ADMIN', 600);      // 10 minuti
define('SESSION_TIMEOUT_ACCOUNTANT', 600); // 10 minuti
define('SESSION_TIMEOUT_EMPLOYEE', 900);   // 15 minuti
```

**Funzionamento:**
1. Ad ogni richiesta, viene verificato il tempo dall'ultima attività
2. Se supera il timeout del ruolo, la sessione viene invalidata
3. L'utente viene reindirizzato al login con messaggio appropriato

**Esempio pratico:**

```php
// Verifica automatica in Auth::check()
if (Auth::check()) {
    // Sessione valida, timeout non scaduto
    $user = Auth::getUser();
} else {
    // Sessione scaduta o non autenticato
    redirect('/auth/login.php?expired=1');
}
```

### 2.3 Blocco Account e Rate Limiting

**Meccanismo di blocco:**
- Dopo 5 tentativi falliti: account bloccato per 15 minuti
- Contatore reset dopo login riuscito
- Log di tutti i tentativi

**Rate Limiting (doppio livello):**

```php
// 1. Session-based (veloce, per richieste normali)
checkRateLimitSession($key, $maxAttempts, $windowSeconds);

// 2. Database-based (persistente, cross-session)
checkRateLimitDb($identifier, $action, $maxAttempts, $windowSeconds);

// Esempio: Rate limiting login
$rateCheck = checkLoginRateLimit($username);
if (!$rateCheck['allowed']) {
    // Risposta: "Troppi tentativi. Riprova tra 5 minuti."
    die($rateCheck['message']);
}
```

**Configurazione limiti:**

```php
define('RATE_LIMIT_LOGIN', 5);           // tentativi login
define('RATE_LIMIT_LOGIN_WINDOW', 300);  // 5 minuti
define('RATE_LIMIT_API', 100);           // richieste API
define('RATE_LIMIT_API_WINDOW', 60);     // 1 minuto
define('RATE_LIMIT_DOWNLOAD', 50);       // download
define('RATE_LIMIT_DOWNLOAD_WINDOW', 3600); // 1 ora
```

### 2.4 HTTP Security Headers

**File:** `src/helpers/security.php`

**Headers implementati:**

| Header | Valore | Scopo |
|--------|--------|-------|
| Content-Security-Policy | Vedi sotto | Previene XSS, injection |
| Strict-Transport-Security | max-age=31536000; includeSubDomains; preload | Forza HTTPS |
| X-Content-Type-Options | nosniff | Previene MIME sniffing |
| X-Frame-Options | DENY | Previene clickjacking |
| X-XSS-Protection | 1; mode=block | Protezione XSS browser |
| Referrer-Policy | strict-origin-when-cross-origin | Limita info referrer |
| Cross-Origin-Opener-Policy | same-origin | Isolamento cross-origin |
| Cross-Origin-Embedder-Policy | require-corp | Isolamento risorse |
| Permissions-Policy | camera=(), microphone=(), etc. | Disabilita API non usate |

**Content Security Policy dettagliata:**

```
default-src 'self';
script-src 'self' 'nonce-{random}' 'strict-dynamic';
style-src 'self' 'nonce-{random}';
img-src 'self' data:;
font-src 'self';
connect-src 'self';
frame-src 'none';
object-src 'none';
base-uri 'self';
form-action 'self';
frame-ancestors 'none';
upgrade-insecure-requests;
```

**Utilizzo con nonce per script inline:**

```php
<?php $nonce = generateNonce(); ?>
<script nonce="<?= $nonce ?>">
    // Questo script è autorizzato
    console.log('Script sicuro');
</script>
```

### 2.5 Audit Logging

**File:** `src/classes/AuditLog.php`

**Eventi tracciati:**

| Categoria | Eventi |
|-----------|--------|
| Autenticazione | login_success, login_failed, logout, session_expired |
| Password | password_change, password_reset |
| MFA | mfa_enabled, mfa_disabled, mfa_failed |
| Documenti | document_upload, document_download, document_delete |
| Dipendenti | employee_create, employee_update, employee_delete |
| Sicurezza | suspicious_activity, rate_limit_exceeded, unauthorized_access |

**Livelli di severità:**
- `info`: Operazioni normali
- `warning`: Potenziali problemi (login falliti, rate limit)
- `critical`: Problemi gravi (hijack sessione, accessi non autorizzati)

**Esempio di logging:**

```php
// Log login riuscito
AuditLog::logLogin('admin', $userId, ['method' => 'password']);

// Log login fallito
AuditLog::logLoginFailed('employee', 0, 'invalid_password', [
    'attempted_username' => $username
]);

// Log download documento
AuditLog::logDocumentDownload('employee', $employeeId, $documentId, [
    'title' => $document['title'],
    'file_size' => $document['file_size']
]);

// Log attività sospetta
AuditLog::logSuspiciousActivity('admin', $userId,
    'Multiple failed login attempts detected',
    ['failed_count' => 15, 'window' => '15 minutes']
);
```

---

## 3. Fase 2: Protezione File e Documenti

### 3.1 Download Logging

**File:** `src/classes/Document.php`

**Dati tracciati per ogni download:**
- ID documento
- Tipo e ID utente
- Indirizzo IP
- User Agent
- Timestamp

**Tabella database:**

```sql
CREATE TABLE document_downloads (
    id INT PRIMARY KEY AUTO_INCREMENT,
    document_id INT NOT NULL,
    user_type ENUM('admin', 'accountant', 'employee') NOT NULL,
    user_id INT NOT NULL,
    ip_address VARCHAR(45) NOT NULL,
    user_agent TEXT NULL,
    downloaded_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);
```

**Esempio di utilizzo:**

```php
// Ottieni statistiche download per un documento
$stats = Document::getDownloadStats($documentId);
// Risultato:
[
    'total_downloads' => 45,
    'recent_downloads' => [
        ['user_name' => 'Mario Rossi', 'downloaded_at' => '2026-01-21 10:30:00'],
        // ...
    ]
]

// Rileva download sospetti
$suspicious = Document::getSuspiciousDownloads();
// Utenti con >20 download nell'ultima ora
```

### 3.2 Watermark PDF

**Funzionalità:**
- Aggiunge watermark trasparente a ogni pagina PDF scaricata
- Contiene: nome utente, data/ora download, IP, ID documento
- Visibile ma non invasivo

**Formato watermark:**

```
Scaricato da: Mario Rossi (RSSMRA80A01H501Z) | Data: 21/01/2026 10:30:45 | IP: 192.168.1.100 | Doc ID: 1234
```

**Implementazione:**

```php
// Download con watermark (default)
$result = Document::download($documentId, applyWatermark: true);

// Download senza watermark (solo per admin se necessario)
$result = Document::download($documentId, applyWatermark: false);

// Pulizia file temporaneo dopo invio
Document::cleanupTempFile($result['temp_file']);
```

**Requisiti:**
- Libreria FPDI/FPDF (opzionale, fallback a file originale)
- Installazione: `composer require setasign/fpdi`

### 3.3 Controllo Accesso One-to-One

**Regola:** Ogni dipendente può accedere SOLO ai propri documenti.

**Implementazione:**

```php
// Verifica automatica in Document::download()
if ($employee && $document['employee_id'] !== $employee['id']) {
    // Log tentativo di accesso non autorizzato
    AuditLog::logUnauthorizedAccess('document', [
        'document_id' => $id,
        'employee_id' => $employee['id'],
        'owner_id' => $document['employee_id']
    ]);

    return ['success' => false, 'error' => 'Accesso non autorizzato'];
}

// Verifica esplicita
$canAccess = Document::canEmployeeAccess($documentId, $employeeId);
```

---

## 4. Fase 3: Autenticazione Multi-Fattore (MFA)

### 4.1 Panoramica

**File:** `src/classes/MFA.php`

**Caratteristiche:**
- Standard TOTP (RFC 6238)
- Compatibile con Google Authenticator, Microsoft Authenticator, Authy
- 10 codici di backup monouso
- Rate limiting su verifica

### 4.2 Setup MFA per Utente

**Flusso:**

```
1. Utente richiede attivazione MFA
         ↓
2. Sistema genera secret key
         ↓
3. Mostra QR code da scansionare
         ↓
4. Utente inserisce codice di verifica
         ↓
5. Sistema verifica e attiva MFA
         ↓
6. Mostra codici di backup (una sola volta)
```

**Esempio codice:**

```php
// 1. Genera secret
$secret = MFA::generateSecret();
// Esempio: "JBSWY3DPEHPK3PXP"

// 2. Genera URI per QR code
$uri = MFA::getProvisioningUri($secret, $userEmail, 'PAManager');
// otpauth://totp/PAManager:user@email.com?secret=JBSWY3DPEHPK3PXP&issuer=PAManager

// 3. Genera URL immagine QR
$qrUrl = MFA::getQrCodeUrl($uri, 200);

// 4. Verifica codice inserito dall'utente
if (MFA::verifyCode($secret, $_POST['code'])) {
    // 5. Genera backup codes
    $backupCodes = MFA::generateBackupCodes();

    // 6. Attiva MFA
    MFA::enable('users', $userId, $secret, $backupCodes);

    // 7. Mostra backup codes all'utente
    // IMPORTANTE: Mostrare solo una volta!
}
```

### 4.3 Verifica MFA al Login

**Flusso login con MFA:**

```
1. Inserimento username/password
         ↓
2. Verifica credenziali → OK
         ↓
3. MFA abilitato? → Sì → Richiedi codice OTP
         ↓
4. Utente inserisce codice (app o backup)
         ↓
5. Verifica codice → OK → Accesso consentito
```

**Esempio:**

```php
// Durante il login, dopo verifica password
if ($user['mfa_enabled']) {
    // Salva stato temporaneo
    $_SESSION['mfa_pending'] = [
        'user_id' => $user['id'],
        'user_type' => 'users'
    ];

    // Redirect a pagina verifica MFA
    redirect('/auth/mfa-verify.php');
}

// Pagina verifica MFA
$result = MFA::verifyForLogin('users', $userId, $_POST['code']);

if ($result['success']) {
    if ($result['used_backup']) {
        // Avvisa: "Hai usato un codice di backup. Ti restano X codici."
    }
    // Completa login
    Auth::completeLogin($user);
} else {
    // Mostra errore
    $error = $result['error']; // "Codice non valido" o "Troppi tentativi"
}
```

### 4.4 Backup Codes

**Caratteristiche:**
- 10 codici generati all'attivazione
- Formato: XXXX-XXXX (es. "A1B2-C3D4")
- Monouso (cancellato dopo utilizzo)
- Hashati nel database
- Rigenerabili dall'utente

**Esempio output:**

```
I tuoi codici di backup (salvali in un posto sicuro):

1. A1B2-C3D4
2. E5F6-G7H8
3. I9J0-K1L2
4. M3N4-O5P6
5. Q7R8-S9T0
6. U1V2-W3X4
7. Y5Z6-A7B8
8. C9D0-E1F2
9. G3H4-I5J6
10. K7L8-M9N0

ATTENZIONE: Questi codici non verranno più mostrati!
```

### 4.5 Configurazione

```php
// config.php
define('MFA_ENABLED', true);
define('MFA_ISSUER', 'PAManager');
define('MFA_REQUIRED_ROLES', ['admin', 'accountant']); // Obbligatorio per questi ruoli
```

---

## 5. Fase 4: Conformità GDPR

### 5.1 Panoramica

**File:** `src/classes/GDPR.php`

**Articoli GDPR implementati:**
- Art. 7: Gestione consensi
- Art. 15: Diritto di accesso
- Art. 17: Diritto alla cancellazione
- Art. 20: Diritto alla portabilità

### 5.2 Gestione Consensi

**Tipi di consenso:**

```php
GDPR::CONSENT_PRIVACY_POLICY    // Accettazione privacy policy
GDPR::CONSENT_DATA_PROCESSING   // Trattamento dati
GDPR::CONSENT_MARKETING         // Comunicazioni marketing
GDPR::CONSENT_THIRD_PARTY       // Condivisione terze parti
GDPR::CONSENT_ANALYTICS         // Analytics e tracciamento
```

**Esempio registrazione consenso:**

```php
// Utente accetta privacy policy
GDPR::recordConsent('employee', $employeeId, GDPR::CONSENT_PRIVACY_POLICY, true);

// Utente revoca consenso marketing
GDPR::recordConsent('employee', $employeeId, GDPR::CONSENT_MARKETING, false);

// Verifica consenso
if (GDPR::hasConsent('employee', $employeeId, GDPR::CONSENT_DATA_PROCESSING)) {
    // Procedi con elaborazione
}

// Ottieni tutti i consensi
$consents = GDPR::getConsents('employee', $employeeId);
// Risultato:
[
    'privacy_policy' => ['given' => true, 'date' => '2026-01-15 10:00:00'],
    'marketing' => ['given' => false, 'date' => '2026-01-20 14:30:00']
]
```

### 5.3 Diritto di Accesso (Art. 15)

**Esporta tutti i dati di un dipendente:**

```php
$data = GDPR::exportEmployeeData($employeeId);
```

**Struttura dati esportati:**

```json
{
    "anagrafica": {
        "codice_fiscale": "RSSMRA80A01H501Z",
        "nome": "Mario",
        "cognome": "Rossi",
        "email": "mario.rossi@email.it",
        "telefono": "333-1234567",
        "data_creazione": "2024-01-15 10:00:00"
    },
    "documenti": [
        {
            "id": 1,
            "type": "payslip",
            "title": "Busta Paga - Gennaio 2026",
            "created_at": "2026-02-01 09:00:00"
        }
    ],
    "comunicazioni_lette": [...],
    "download_effettuati": [...],
    "consensi": {...},
    "log_accessi": [...],
    "_metadata": {
        "export_date": "2026-01-21 10:30:00",
        "export_reason": "GDPR Art. 15 - Right of Access"
    }
}
```

### 5.4 Diritto alla Portabilità (Art. 20)

```php
// Genera JSON per download
$json = GDPR::generatePortableData($employeeId);

// Invio come download
header('Content-Type: application/json');
header('Content-Disposition: attachment; filename="miei_dati.json"');
echo $json;
```

### 5.5 Diritto alla Cancellazione (Art. 17)

**Due opzioni:**

**1. Anonimizzazione (consigliata):**

```php
$result = GDPR::anonymizeEmployee($employeeId, $adminId);
// Risultato:
[
    'success' => true,
    'anonymous_id' => 'ANON_a1b2c3d4e5f6g7h8'
]
```

Effetti:
- Codice fiscale → "ANON_xxxxx"
- Nome/Cognome → "Anonimo ANON_xxxxx"
- Email/Telefono → null
- Account disattivato
- Documenti mantenuti (per requisiti legali)

**2. Cancellazione completa:**

```php
$result = GDPR::deleteEmployeeData($employeeId, $adminId, 'Richiesta GDPR');
```

Effetti:
- Elimina tutti i documenti (file fisici + record DB)
- Elimina consensi
- Anonimizza audit log
- Elimina record dipendente

### 5.6 Data Retention Automatica

**Policy implementate:**

| Dato | Retention | Azione |
|------|-----------|--------|
| Audit log | 2 anni | Eliminazione |
| Security alerts (risolti) | 1 anno | Eliminazione |
| Sessioni | 30 giorni | Eliminazione |
| Rate limits | 1 giorno | Eliminazione |
| Documenti | 10 anni | Mantenimento (requisiti fiscali) |

**Esecuzione pulizia:**

```php
// Manuale
$report = GDPR::runRetentionCleanup();

// Automatica (via cron job o MySQL event)
// Vedi sezione installazione
```

### 5.7 Report Compliance

```php
$report = GDPR::generateComplianceReport();
```

**Output esempio:**

```json
{
    "generated_at": "2026-01-21 10:00:00",
    "period": "Last 12 months",
    "consents": {
        "total": 150,
        "by_type": [...]
    },
    "data_access_requests": 5,
    "deletion_requests": 2,
    "retention_cleanups": 52,
    "security_incidents": 3,
    "data_breaches": 0
}
```

---

## 6. Fase 5: Monitoraggio e Alert

### 6.1 Panoramica

**File:** `src/classes/SecurityMonitor.php`

**Funzionalità:**
- Dashboard sicurezza real-time
- Rilevamento automatico anomalie
- Gestione alert
- Report periodici

### 6.2 Dashboard Sicurezza

```php
$dashboard = SecurityMonitor::getDashboard();
```

**Dati restituiti:**

```json
{
    "summary": {
        "unresolved_alerts": 3,
        "failed_logins_24h": 15,
        "successful_logins_24h": 45,
        "downloads_today": 120,
        "active_users": 12,
        "critical_events_24h": 1
    },
    "recent_alerts": [...],
    "login_stats": [
        {"date": "2026-01-15", "successful": 42, "failed": 5},
        {"date": "2026-01-16", "successful": 38, "failed": 8},
        ...
    ],
    "threat_level": {
        "level": "medium",
        "score": 35,
        "color": "#ffc107",
        "factors": ["Alcuni alert non risolti", "Login falliti elevati"]
    },
    "active_sessions": 8,
    "recent_critical_events": [...]
}
```

### 6.3 Threat Level

**Livelli:**

| Livello | Score | Colore | Significato |
|---------|-------|--------|-------------|
| low | 0-19 | Verde | Situazione normale |
| medium | 20-39 | Giallo | Attenzione richiesta |
| high | 40-59 | Arancione | Intervento necessario |
| critical | 60+ | Rosso | Emergenza sicurezza |

**Fattori di calcolo:**
- Alert non risolti (+15/+30 punti)
- Login falliti recenti (+10/+25 punti)
- Eventi critici (+20 punti ciascuno)
- Accessi fuori orario (+15 punti)

### 6.4 Rilevamento Anomalie

```php
$anomalies = SecurityMonitor::detectAnomalies();
```

**Anomalie rilevate automaticamente:**

| Tipo | Soglia | Severità |
|------|--------|----------|
| Brute force | >10 login falliti in 15 min | Alta |
| Mass download | >30 download in 1 ora | Media |
| IP multipli | >5 IP diversi per stesso utente | Alta |
| Accesso fuori orario | Admin dopo le 20:00 | Bassa |

**Esempio output:**

```json
[
    {
        "type": "brute_force_attempt",
        "severity": "high",
        "user_type": "employee",
        "user_id": 123,
        "details": "15 tentativi falliti in 15 minuti"
    },
    {
        "type": "mass_download",
        "severity": "medium",
        "user_type": "accountant",
        "user_id": 5,
        "details": "45 download in 1 ora"
    }
]
```

### 6.5 Gestione Alert

**Creazione alert:**

```php
// Automatico (da SecurityMonitor o AuditLog)
// Manuale
$alertId = SecurityMonitor::createAlert(
    'custom_alert',
    'admin',
    $userId,
    ['description' => 'Comportamento sospetto rilevato']
);
```

**Risoluzione alert:**

```php
SecurityMonitor::resolveAlert($alertId, $adminId, 'Falso positivo verificato');
```

**Lista alert:**

```php
$alerts = SecurityMonitor::getRecentAlerts(20);
```

### 6.6 Report Periodici

```php
// Report giornaliero
$daily = SecurityMonitor::generatePeriodicReport('daily');

// Report settimanale
$weekly = SecurityMonitor::generatePeriodicReport('weekly');

// Report mensile
$monthly = SecurityMonitor::generatePeriodicReport('monthly');
```

**Contenuto report:**

```json
{
    "period": "weekly",
    "generated_at": "2026-01-21 00:00:00",
    "date_range": {
        "from": "2026-01-14",
        "to": "2026-01-21"
    },
    "summary": {
        "total_logins": 320,
        "failed_logins": 45,
        "documents_uploaded": 150,
        "documents_downloaded": 890,
        "security_alerts": 5,
        "critical_events": 2
    },
    "top_events": [
        {"action": "login_success", "count": 320},
        {"action": "document_download", "count": 890}
    ],
    "suspicious_ips": [
        {"ip_address": "192.168.1.100", "attempt_count": 15}
    ]
}
```

---

## 7. Guida all'Installazione

### 7.1 Prerequisiti

- PHP 8.0+
- MySQL 8.0+
- Composer
- HTTPS configurato (obbligatorio in produzione)

### 7.2 Installazione Dipendenze

```bash
cd gestionalepa
composer install
```

**Dipendenze opzionali:**

```bash
# Per watermark PDF
composer require setasign/fpdi

# Per QR code MFA locale
composer require endroid/qr-code
# oppure
composer require chillerlan/php-qrcode
```

### 7.3 Esecuzione Migrazioni Database

**Opzione 1: Da browser**

```
https://tuo-dominio.it/gestionalepa/database/migrate.php?token=pamanager_migrate_2024
```

**Opzione 2: Da CLI**

```bash
cd gestionalepa/database
php migrate.php
```

**Output atteso:**

```
=== PAManager Database Migration ===
Inizio: 2026-01-21 10:00:00

Trovate 1 migrazioni

[RUN] 001_security_update.sql
  ✓ Completata

=== Riepilogo ===
Eseguite: 1
Saltate: 0
Fallite: 0

Fine: 2026-01-21 10:00:05
```

### 7.4 Configurazione Cron Job (Opzionale)

Per pulizia automatica dati:

```bash
# Esegui cleanup ogni domenica alle 3:00
0 3 * * 0 php /path/to/gestionalepa/cron/cleanup.php

# Esegui scansione sicurezza ogni ora
0 * * * * php /path/to/gestionalepa/cron/security-scan.php
```

### 7.5 Configurazione Produzione

**1. Disabilita debug:**

```php
// config.php o .env
define('DEBUG_MODE', false);
```

**2. Configura HTTPS:**

```apache
# .htaccess
RewriteEngine On
RewriteCond %{HTTPS} off
RewriteRule ^(.*)$ https://%{HTTP_HOST}%{REQUEST_URI} [L,R=301]
```

**3. Configura token migrazione sicuro:**

```bash
# .env
MIGRATION_TOKEN=tuo_token_sicuro_random
```

---

## 8. API e Integrazioni

### 8.1 API Sicurezza (per integrazioni)

```php
// Verifica autenticazione
Auth::check(); // bool

// Ottieni utente corrente
$user = Auth::getUser(); // array|null
$employee = Auth::getEmployee(); // array|null

// Verifica ruolo
if (Auth::hasRole('admin')) { ... }

// Logout sicuro
Auth::logout();
```

### 8.2 API Documenti

```php
// Download con controllo accesso
$result = Document::download($id);
if ($result['success']) {
    // Invia file
    setDownloadHeaders($document['file_name'], $document['mime_type'], filesize($result['file_path']));
    readfile($result['file_path']);
    Document::cleanupTempFile($result['temp_file']);
}

// Statistiche
$stats = Document::getDownloadStats($documentId);
```

### 8.3 API Monitoraggio

```php
// Dashboard
$dashboard = SecurityMonitor::getDashboard();

// Threat level
$threat = SecurityMonitor::calculateThreatLevel();

// Anomalie
$anomalies = SecurityMonitor::detectAnomalies();

// Report
$report = SecurityMonitor::generatePeriodicReport('daily');
```

---

## 9. Checklist Compliance

### 9.1 Sicurezza Applicativa

- [x] Password policy (12+ caratteri, complessità)
- [x] Hashing password sicuro (Argon2id/bcrypt)
- [x] Session timeout per ruolo
- [x] Blocco account dopo tentativi falliti
- [x] Rate limiting (login, API, download)
- [x] CSRF protection
- [x] HTTP Security Headers completi
- [x] HTTPS enforcement
- [x] Prepared statements (SQL injection prevention)
- [x] Output escaping (XSS prevention)

### 9.2 Autenticazione

- [x] MFA obbligatorio per admin/commercialista
- [x] MFA opzionale per dipendenti
- [x] Backup codes
- [x] SPID integration per dipendenti

### 9.3 Audit e Monitoring

- [x] Logging completo operazioni
- [x] Logging download documenti
- [x] Security alerts automatici
- [x] Dashboard monitoraggio
- [x] Report periodici
- [x] Rilevamento anomalie

### 9.4 Protezione Dati

- [x] Watermark PDF
- [x] Controllo accesso one-to-one
- [x] Crittografia dati sensibili (password)
- [x] File storage fuori web root

### 9.5 GDPR

- [x] Gestione consensi
- [x] Diritto di accesso
- [x] Diritto alla portabilità
- [x] Diritto alla cancellazione
- [x] Data retention automatica
- [x] Report compliance

---

## Contatti

Per domande tecniche o segnalazioni di vulnerabilità:

- **Email:** security@comune.example.it
- **Repository:** [interno]

---

*Documento generato automaticamente - PAManager v1.0.0*
