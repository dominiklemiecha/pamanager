<?php
/**
 * Helper Validazione
 * PAManager - Comune
 */

/**
 * Valida un indirizzo email
 */
function validateEmail(string $email): bool
{
    return filter_var($email, FILTER_VALIDATE_EMAIL) !== false;
}

/**
 * Valida un codice fiscale italiano
 */
function validateFiscalCode(string $fiscalCode): bool
{
    return Employee::validateFiscalCode($fiscalCode);
}

/**
 * Valida una password (complessità)
 */
function validatePassword(string $password): array
{
    $errors = [];

    if (strlen($password) < 8) {
        $errors[] = 'La password deve contenere almeno 8 caratteri';
    }

    if (!preg_match('/[a-z]/', $password)) {
        $errors[] = 'La password deve contenere almeno una lettera minuscola';
    }

    if (!preg_match('/[A-Z]/', $password)) {
        $errors[] = 'La password deve contenere almeno una lettera maiuscola';
    }

    if (!preg_match('/\d/', $password)) {
        $errors[] = 'La password deve contenere almeno un numero';
    }

    if (!preg_match('/[\W_]/', $password)) {
        $errors[] = 'La password deve contenere almeno un carattere speciale';
    }

    return [
        'valid' => empty($errors),
        'errors' => $errors
    ];
}

/**
 * Valida una data
 */
function validateDate(string $date, string $format = 'Y-m-d'): bool
{
    $d = DateTime::createFromFormat($format, $date);
    return $d && $d->format($format) === $date;
}

/**
 * Valida un numero di telefono italiano
 */
function validatePhone(string $phone): bool
{
    // Rimuove spazi e trattini
    $phone = preg_replace('/[\s\-]/', '', $phone);

    // Pattern per numeri italiani
    return preg_match('/^(\+39)?[0-9]{6,12}$/', $phone) === 1;
}

/**
 * Valida un username
 */
function validateUsername(string $username): array
{
    $errors = [];

    if (strlen($username) < 3) {
        $errors[] = 'L\'username deve contenere almeno 3 caratteri';
    }

    if (strlen($username) > 50) {
        $errors[] = 'L\'username non può superare 50 caratteri';
    }

    if (!preg_match('/^[a-zA-Z0-9_\.]+$/', $username)) {
        $errors[] = 'L\'username può contenere solo lettere, numeri, underscore e punti';
    }

    return [
        'valid' => empty($errors),
        'errors' => $errors
    ];
}

/**
 * Valida un anno
 */
function validateYear(int $year, int $minYear = 2000, int $maxYear = 2100): bool
{
    return $year >= $minYear && $year <= $maxYear;
}

/**
 * Valida un mese
 */
function validateMonth(int $month): bool
{
    return $month >= 1 && $month <= 12;
}

/**
 * Sanifica e valida un intero
 */
function validateInt(mixed $value, ?int $min = null, ?int $max = null): ?int
{
    $int = filter_var($value, FILTER_VALIDATE_INT);

    if ($int === false) {
        return null;
    }

    if ($min !== null && $int < $min) {
        return null;
    }

    if ($max !== null && $int > $max) {
        return null;
    }

    return $int;
}

/**
 * Sanifica una stringa
 */
function sanitizeString(string $value, ?int $maxLength = null): string
{
    $value = trim($value);
    $value = strip_tags($value);

    if ($maxLength !== null && strlen($value) > $maxLength) {
        $value = substr($value, 0, $maxLength);
    }

    return $value;
}

/**
 * Valida un file upload
 */
function validateUploadedFile(array $file, array $options = []): array
{
    $defaults = [
        'max_size' => MAX_FILE_SIZE,
        'allowed_types' => ALLOWED_MIME_TYPES,
        'allowed_extensions' => ALLOWED_EXTENSIONS
    ];

    $options = array_merge($defaults, $options);
    $errors = [];

    // Verifica errore upload
    if ($file['error'] !== UPLOAD_ERR_OK) {
        $errorMessages = [
            UPLOAD_ERR_INI_SIZE => 'File troppo grande (limite server)',
            UPLOAD_ERR_FORM_SIZE => 'File troppo grande (limite form)',
            UPLOAD_ERR_PARTIAL => 'Upload incompleto',
            UPLOAD_ERR_NO_FILE => 'Nessun file caricato',
            UPLOAD_ERR_NO_TMP_DIR => 'Directory temporanea mancante',
            UPLOAD_ERR_CANT_WRITE => 'Errore di scrittura',
            UPLOAD_ERR_EXTENSION => 'Upload bloccato da estensione'
        ];
        $errors[] = $errorMessages[$file['error']] ?? 'Errore sconosciuto';
        return ['valid' => false, 'errors' => $errors];
    }

    // Verifica dimensione
    if ($file['size'] > $options['max_size']) {
        $maxMb = round($options['max_size'] / 1024 / 1024, 2);
        $errors[] = "File troppo grande. Massimo {$maxMb}MB";
    }

    // Verifica estensione
    $extension = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    if (!in_array($extension, $options['allowed_extensions'])) {
        $errors[] = 'Estensione file non consentita';
    }

    // Verifica MIME type reale
    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mimeType = $finfo->file($file['tmp_name']);

    if (!in_array($mimeType, $options['allowed_types'])) {
        $errors[] = 'Tipo file non consentito';
    }

    return [
        'valid' => empty($errors),
        'errors' => $errors,
        'mime_type' => $mimeType,
        'extension' => $extension
    ];
}

/**
 * Valida e ottiene parametri GET
 */
function getParam(string $key, $default = null, string $filter = 'string'): mixed
{
    if (!isset($_GET[$key])) {
        return $default;
    }

    return filterValue($_GET[$key], $filter, $default);
}

/**
 * Valida e ottiene parametri POST
 */
function postParam(string $key, $default = null, string $filter = 'string'): mixed
{
    if (!isset($_POST[$key])) {
        return $default;
    }

    return filterValue($_POST[$key], $filter, $default);
}

/**
 * Filtra un valore
 */
function filterValue(mixed $value, string $filter, mixed $default = null): mixed
{
    return match ($filter) {
        'int' => filter_var($value, FILTER_VALIDATE_INT) !== false
            ? (int) $value
            : $default,
        'float' => filter_var($value, FILTER_VALIDATE_FLOAT) !== false
            ? (float) $value
            : $default,
        'email' => filter_var($value, FILTER_VALIDATE_EMAIL) ?: $default,
        'url' => filter_var($value, FILTER_VALIDATE_URL) ?: $default,
        'bool' => filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) ?? $default,
        'string' => sanitizeString((string) $value),
        default => $value
    };
}

/**
 * Valida un array di campi
 */
function validateFields(array $data, array $rules): array
{
    $errors = [];
    $validated = [];

    foreach ($rules as $field => $rule) {
        $value = $data[$field] ?? null;
        $fieldErrors = [];

        // Campo obbligatorio
        if (($rule['required'] ?? false) && ($value === null || $value === '')) {
            $fieldErrors[] = ($rule['label'] ?? $field) . ' è obbligatorio';
        }

        if ($value !== null && $value !== '') {
            // Tipo
            $type = $rule['type'] ?? 'string';

            switch ($type) {
                case 'email':
                    if (!validateEmail($value)) {
                        $fieldErrors[] = 'Email non valida';
                    }
                    break;

                case 'int':
                    $intVal = validateInt($value, $rule['min'] ?? null, $rule['max'] ?? null);
                    if ($intVal === null) {
                        $fieldErrors[] = ($rule['label'] ?? $field) . ' non valido';
                    } else {
                        $value = $intVal;
                    }
                    break;

                case 'date':
                    if (!validateDate($value, $rule['format'] ?? 'Y-m-d')) {
                        $fieldErrors[] = 'Data non valida';
                    }
                    break;

                case 'string':
                default:
                    // Lunghezza minima
                    if (isset($rule['min_length']) && strlen($value) < $rule['min_length']) {
                        $fieldErrors[] = ($rule['label'] ?? $field) . ' deve avere almeno ' . $rule['min_length'] . ' caratteri';
                    }
                    // Lunghezza massima
                    if (isset($rule['max_length']) && strlen($value) > $rule['max_length']) {
                        $fieldErrors[] = ($rule['label'] ?? $field) . ' non può superare ' . $rule['max_length'] . ' caratteri';
                    }
                    // Pattern
                    if (isset($rule['pattern']) && !preg_match($rule['pattern'], $value)) {
                        $fieldErrors[] = ($rule['label'] ?? $field) . ' formato non valido';
                    }
                    break;
            }

            // Valori consentiti
            if (isset($rule['in']) && !in_array($value, $rule['in'])) {
                $fieldErrors[] = ($rule['label'] ?? $field) . ' valore non consentito';
            }
        }

        if (!empty($fieldErrors)) {
            $errors[$field] = $fieldErrors;
        } else {
            $validated[$field] = $value;
        }
    }

    return [
        'valid' => empty($errors),
        'errors' => $errors,
        'data' => $validated
    ];
}

/**
 * Formatta una data per visualizzazione
 */
function formatDate(?string $date, string $format = 'd/m/Y'): string
{
    if (empty($date)) {
        return '-';
    }

    $d = new DateTime($date);
    return $d->format($format);
}

/**
 * Formatta una data/ora per visualizzazione
 */
function formatDateTime(?string $datetime, string $format = 'd/m/Y H:i'): string
{
    if (empty($datetime)) {
        return '-';
    }

    $d = new DateTime($datetime);
    return $d->format($format);
}

/**
 * Formatta dimensione file
 */
function formatFileSize(int $bytes): string
{
    $units = ['B', 'KB', 'MB', 'GB'];
    $i = 0;

    while ($bytes >= 1024 && $i < count($units) - 1) {
        $bytes /= 1024;
        $i++;
    }

    return round($bytes, 2) . ' ' . $units[$i];
}

/**
 * Nomi mesi in italiano
 */
function getMonthName(int $month): string
{
    $months = [
        1 => 'Gennaio', 2 => 'Febbraio', 3 => 'Marzo', 4 => 'Aprile',
        5 => 'Maggio', 6 => 'Giugno', 7 => 'Luglio', 8 => 'Agosto',
        9 => 'Settembre', 10 => 'Ottobre', 11 => 'Novembre', 12 => 'Dicembre'
    ];

    return $months[$month] ?? '';
}

/**
 * Array mesi per select
 */
function getMonthsArray(): array
{
    return [
        1 => 'Gennaio', 2 => 'Febbraio', 3 => 'Marzo', 4 => 'Aprile',
        5 => 'Maggio', 6 => 'Giugno', 7 => 'Luglio', 8 => 'Agosto',
        9 => 'Settembre', 10 => 'Ottobre', 11 => 'Novembre', 12 => 'Dicembre'
    ];
}

/**
 * Data completa in italiano
 */
function getItalianDate(?string $date = null): string
{
    $days = [
        'Sunday' => 'Domenica', 'Monday' => 'Lunedì', 'Tuesday' => 'Martedì',
        'Wednesday' => 'Mercoledì', 'Thursday' => 'Giovedì', 'Friday' => 'Venerdì', 'Saturday' => 'Sabato'
    ];

    $months = [
        'January' => 'Gennaio', 'February' => 'Febbraio', 'March' => 'Marzo',
        'April' => 'Aprile', 'May' => 'Maggio', 'June' => 'Giugno',
        'July' => 'Luglio', 'August' => 'Agosto', 'September' => 'Settembre',
        'October' => 'Ottobre', 'November' => 'Novembre', 'December' => 'Dicembre'
    ];

    $timestamp = $date ? strtotime($date) : time();
    $dayName = $days[date('l', $timestamp)];
    $day = date('j', $timestamp);
    $monthName = $months[date('F', $timestamp)];
    $year = date('Y', $timestamp);

    return "{$dayName} {$day} {$monthName} {$year}";
}
