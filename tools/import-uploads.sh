#!/usr/bin/env bash
# Importa nel container Docker un .zip degli upload (foto, documenti, certificati)
# scaricato dalla produzione tramite /public/export-uploads.php
#
# USO:  bash tools/import-uploads.sh tools/pamanager_uploads_YYYY-MM-DD_HHMMSS.zip

set -e

ZIP_FILE="${1:-prod-uploads.zip}"

if [ ! -f "$ZIP_FILE" ]; then
    echo "ERRORE: file '$ZIP_FILE' non trovato." >&2
    echo "Scaricalo prima da prod: https://<prod>/public/export-uploads.php?token=<MIGRATION_TOKEN>" >&2
    exit 1
fi

cd "$(dirname "$0")/.."

echo "[1/3] Copio zip nel container app..."
docker compose cp "$ZIP_FILE" app:/tmp/uploads.zip

echo "[2/3] Estraggo dentro il volume uploads..."
docker compose exec -T app bash -c '
    set -e
    cd /var/www/html/public
    apt-get install -y unzip >/dev/null 2>&1 || true
    # Pulisci il contenuto del volume (non rm sulla dir stessa, e un mountpoint)
    if [ -d uploads ]; then
        find uploads -mindepth 1 -delete 2>/dev/null || true
    else
        mkdir -p uploads
    fi
    # Lo zip contiene root "uploads/" → estrae direttamente sopra
    unzip -q -o /tmp/uploads.zip -d /var/www/html/public
    chown -R www-data:www-data /var/www/html/public/uploads
    chmod -R 775 /var/www/html/public/uploads
    rm -f /tmp/uploads.zip
    echo "    File estratti:"
    find /var/www/html/public/uploads -type f | wc -l
    du -sh /var/www/html/public/uploads
'

echo "[3/3] Verifica DB <-> file:"
docker compose exec -T db mariadb --skip-ssl -upamanager -pdevpassword123 pamanager -e "
    SELECT COUNT(*) AS dipendenti_con_foto FROM employees WHERE photo_path IS NOT NULL AND photo_path <> '';
    SELECT id, CONCAT(first_name,' ',last_name) AS nome, photo_path FROM employees WHERE photo_path IS NOT NULL AND photo_path <> '' LIMIT 5;
" 2>&1 | grep -v "warning\|Using a password"

echo ""
echo "Import upload completato. Ricarica http://localhost:8888/"
