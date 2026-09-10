#!/bin/bash
set -e

echo "[entrypoint] PAManager container starting..."

# === 1. Fix permessi sui volumi montati ===
# (i Docker volumes nascono root-owned; serve renderli scrivibili da www-data)
for d in /var/www/html/public/uploads /var/www/html/logs /var/www/html/storage; do
    if [ -d "$d" ]; then
        chown -R www-data:www-data "$d" 2>/dev/null || true
        chmod -R 775 "$d" 2>/dev/null || true
    fi
done

# === 2. Attesa database pronto ===
DB_HOST="${DB_HOST:-db}"
DB_PORT="${DB_PORT:-3306}"
DB_USER="${DB_USER:-pamanager}"
DB_PASS="${DB_PASS:-}"

# === 1b. Protezione directory di upload ===
# uploads/ e' dentro il DocumentRoot: qui dentro possono stare solo immagini pubbliche
# (foto profilo). Certificati medici e documenti di assunzione sono negati via HTTP e
# restano leggibili solo dalle pagine PHP autenticate.
UPLOADS_DIR=/var/www/html/public/uploads
if [ -d "$UPLOADS_DIR" ]; then
    cat > "$UPLOADS_DIR/.htaccess" <<'HTACCESS'
# Generato automaticamente dall'entrypoint: non modificare a mano.
Options -Indexes

# Nessuna esecuzione di script dentro gli upload
<IfModule mod_php.c>
    php_flag engine off
</IfModule>
<FilesMatch "\.(php|phtml|php[0-9]|phar|cgi|pl|py|sh|inc)$">
    <IfModule mod_authz_core.c>
        Require all denied
    </IfModule>
    <IfModule !mod_authz_core.c>
        Order allow,deny
        Deny from all
    </IfModule>
</FilesMatch>

# Documenti: mai serviti direttamente, si scaricano dalle pagine autenticate
<FilesMatch "\.(pdf|docx?|xlsx?|pptx?|zip|rar|7z|csv|txt|heic|heif)$">
    <IfModule mod_authz_core.c>
        Require all denied
    </IfModule>
    <IfModule !mod_authz_core.c>
        Order allow,deny
        Deny from all
    </IfModule>
</FilesMatch>
HTACCESS
    chown www-data:www-data "$UPLOADS_DIR/.htaccess" 2>/dev/null || true

    # Directory con contenuti riservati: deny completo (anche immagini)
    find "$UPLOADS_DIR" -type d \( -name medical_certificates -o -name hire-requests \) 2>/dev/null | while read -r d; do
        printf '%s\n' \
            '<IfModule mod_authz_core.c>' '    Require all denied' '</IfModule>' \
            '<IfModule !mod_authz_core.c>' '    Order allow,deny' '    Deny from all' '</IfModule>' \
            > "$d/.htaccess"
        chown www-data:www-data "$d/.htaccess" 2>/dev/null || true
    done
fi

echo "[entrypoint] Waiting for database ${DB_HOST}:${DB_PORT}..."
MAX_TRIES=90
COUNT=0
# Prima testa la connettivita TCP (no auth), poi prova una SELECT 1
# Wrapper mysql: forza --skip-ssl perche il client MariaDB 11+ richiede SSL di default,
# ma il server (10.11 di default) non lo offre.
MYSQL="mysql --skip-ssl -h${DB_HOST} -P${DB_PORT} -u${DB_USER} -p${DB_PASS}"

while true; do
    if (echo > /dev/tcp/$DB_HOST/$DB_PORT) 2>/dev/null; then
        if $MYSQL -e "SELECT 1" >/dev/null 2>&1; then
            break
        fi
    fi
    COUNT=$((COUNT+1))
    if [ "$COUNT" -ge "$MAX_TRIES" ]; then
        echo "[entrypoint] ERROR: database non raggiungibile dopo ${MAX_TRIES}s" >&2
        exit 1
    fi
    sleep 1
done
echo "[entrypoint] Database ready (auth OK)."

# === 3. Inizializzazione DB ===
# Se la tabella `users` non esiste = primo avvio: carica install_full.sql (schema completo + admin seed).
# Poi esegue le migrazioni incrementali (idempotenti). Su DB esistenti salta install e va dritto alle migrations.

DB_NAME="${DB_NAME:-pamanager}"
TABLES=$($MYSQL -N -e "SHOW TABLES FROM \`$DB_NAME\`" 2>/dev/null | wc -l)

if [ "$TABLES" -eq 0 ] || ! $MYSQL -N -e "SELECT 1 FROM \`$DB_NAME\`.users LIMIT 1" >/dev/null 2>&1; then
    echo "[entrypoint] DB vuoto — carico schema iniziale (install_full.sql + admin seed)..."
    if [ -f /var/www/html/database/install_full.sql ]; then
        if $MYSQL "$DB_NAME" < /var/www/html/database/install_full.sql; then
            echo "[entrypoint] Schema iniziale OK."

            # Password dell'admin iniziale: da env SEED_ADMIN_PASSWORD, altrimenti generata a caso.
            ADMIN_PASS="${SEED_ADMIN_PASSWORD:-}"
            ADMIN_PASS_GENERATED=0
            if [ -z "$ADMIN_PASS" ]; then
                ADMIN_PASS=$(php -r 'echo bin2hex(random_bytes(9));')
                ADMIN_PASS_GENERATED=1
            fi
            ADMIN_HASH=$(ADMIN_PASS="$ADMIN_PASS" php -r 'echo password_hash(getenv("ADMIN_PASS"), PASSWORD_BCRYPT, ["cost" => 12]);')
            $MYSQL "$DB_NAME" -e "UPDATE users SET password_hash = '${ADMIN_HASH}' WHERE username = 'connecteed';"

            if [ "$ADMIN_PASS_GENERATED" = "1" ]; then
                echo "[entrypoint] Admin iniziale: connecteed / ${ADMIN_PASS}"
                echo "[entrypoint] ATTENZIONE: cambiare questa password al primo accesso."
            else
                echo "[entrypoint] Admin iniziale: connecteed (password da SEED_ADMIN_PASSWORD)."
            fi
        else
            echo "[entrypoint] WARN: install_full.sql con errori (continuo con migrations)" >&2
        fi
    fi
fi

if [ "${RUN_MIGRATIONS:-true}" = "true" ]; then
    echo "[entrypoint] Running incremental migrations..."
    cd /var/www/html
    php database/migrate.php 2>&1 | tail -50 || echo "[entrypoint] migrazioni terminate (alcune gia applicate)"
fi

# === 4. Avvia Apache (o comando passato) ===
echo "[entrypoint] Starting Apache..."
exec "$@"
