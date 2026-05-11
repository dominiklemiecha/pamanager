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
        $MYSQL "$DB_NAME" < /var/www/html/database/install_full.sql \
            && echo "[entrypoint] Schema iniziale OK. Admin: connecteed / Cnctd!Admin#2026\$Kp9" \
            || echo "[entrypoint] WARN: install_full.sql con errori (continuo con migrations)" >&2
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
