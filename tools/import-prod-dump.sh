#!/usr/bin/env bash
# Importa un dump SQL della produzione nel DB del container Docker.
#
# USO:
#   1. Scarica il dump da prod:
#      https://<prod>/public/export-db.php?token=<MIGRATION_TOKEN>
#      → salvalo come prod-dump.sql in questa cartella tools/
#
#   2. Lancia:
#      bash tools/import-prod-dump.sh prod-dump.sql
#
# COSA FA:
#   - Stop del container app (per evitare scritture concorrenti durante l'import)
#   - DROP del DB Docker e ricreazione vuota
#   - Importa il dump
#   - Esegue le migrazioni incrementali (per portare la prod allo schema multi-tenant)
#   - Restart del container app

set -e

DUMP_FILE="${1:-prod-dump.sql}"
DB_USER="${DB_USER:-pamanager}"
DB_PASS="${DB_PASS:-devpassword123}"
DB_ROOT_PASS="${DB_ROOT_PASS:-devrootpassword456}"
DB_NAME="${DB_NAME:-pamanager}"

if [ ! -f "$DUMP_FILE" ]; then
    echo "ERRORE: file dump '$DUMP_FILE' non trovato." >&2
    echo "Scaricalo prima da prod: https://<prod>/public/export-db.php?token=<MIGRATION_TOKEN>" >&2
    exit 1
fi

cd "$(dirname "$0")/.."

echo "[1/5] Stop app container..."
docker compose stop app

echo "[2/5] Drop + ricrea DB '$DB_NAME'..."
docker compose exec -T db mariadb --skip-ssl -uroot -p"$DB_ROOT_PASS" -e "
    DROP DATABASE IF EXISTS \`$DB_NAME\`;
    CREATE DATABASE \`$DB_NAME\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
    GRANT ALL ON \`$DB_NAME\`.* TO '$DB_USER'@'%';
    FLUSH PRIVILEGES;
"

echo "[3/5] Importa dump ($(du -h "$DUMP_FILE" | cut -f1)) ..."
# Pre-processing: MySQL 8 -> MariaDB compat (collation utf8mb4_0900_ai_ci non esiste in MariaDB)
TMP_DUMP=$(mktemp -t prod-dump.XXXXXX.sql)
sed -e 's/utf8mb4_0900_ai_ci/utf8mb4_unicode_ci/g' \
    -e 's/utf8mb3_general_ci/utf8_general_ci/g' \
    "$DUMP_FILE" > "$TMP_DUMP"
docker compose exec -T db mariadb --skip-ssl -uroot -p"$DB_ROOT_PASS" "$DB_NAME" < "$TMP_DUMP"
rm -f "$TMP_DUMP"

echo "[4/5] Avvia app (eseguira le migration incrementali automaticamente)..."
docker compose up -d app

# Aspetta che migrations finiscano
echo "    Attendo migrations..."
until docker compose logs app 2>&1 | grep -q "Starting Apache"; do
    sleep 2
done

echo "[5/5] Stato finale:"
docker compose ps
echo ""
echo "Conteggi tabelle (sanity check):"
docker compose exec -T db mariadb --skip-ssl -u"$DB_USER" -p"$DB_PASS" "$DB_NAME" -e "
    SELECT 'employees'     AS tab, COUNT(*) AS n FROM employees
    UNION ALL SELECT 'users',           COUNT(*) FROM users
    UNION ALL SELECT 'departments',     COUNT(*) FROM departments
    UNION ALL SELECT 'documents',       COUNT(*) FROM documents
    UNION ALL SELECT 'leave_requests',  COUNT(*) FROM leave_requests
    UNION ALL SELECT 'communications',  COUNT(*) FROM communications
    UNION ALL SELECT 'companies',       COUNT(*) FROM companies;
"
echo ""
echo "Import completato. Vai su http://localhost:8888/"
