#!/usr/bin/env bash
# Ricrea da zero i due ambienti dei test di equivalenza con gli stessi dati sintetici:
#  1. baseline PHP: MariaDB nuovo (install_full + migrazioni) + seed sintetico;
#  2. nuovo backend: Postgres con le migrazioni TypeORM + copia verificata dei dati;
#  3. istantanee dei due database per ripristinarli prima di ogni gruppo di test.
# Uso (dalla cartella backend): bash scripts/prepara-equivalenza.sh
set -euo pipefail
cd "$(dirname "$0")/.."
RADICE=..

echo "== Baseline PHP"
docker compose -f $RADICE/docker-compose.baseline.yml -p cora-baseline down -v >/dev/null 2>&1 || true
docker compose -f $RADICE/docker-compose.baseline.yml -p cora-baseline up -d >/dev/null
for i in $(seq 1 60); do
  if docker logs cora-baseline-app-1 2>&1 | grep -q "Starting Apache"; then break; fi
  sleep 2
done
docker logs cora-baseline-app-1 2>&1 | grep -E "Fallite:" || { echo "Migrazioni PHP non concluse"; exit 1; }
node scripts/seed-sintetico.js

echo "== Postgres"
docker compose -f $RADICE/docker-compose.node.yml -p cora-dev up -d >/dev/null
docker exec cora-dev-postgres-1 psql -q -U cora -d cora -c "DROP SCHEMA public CASCADE; CREATE SCHEMA public;" >/dev/null 2>&1
npm run --silent migration:run >/dev/null
node scripts/converti-dati.js | tail -1

echo "== Istantanee"
rm -rf test/equivalenza/.dati
npx ts-node -e "require('./test/equivalenza/ambiente').preparaIstantanee()"
echo "Pronto."
