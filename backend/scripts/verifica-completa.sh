#!/usr/bin/env bash
# Verifica completa della migrazione in locale, sui dati sintetici:
#  1. test unitari (calcoli, sicurezza, pulizia HTML);
#  2. test di equivalenza PHP / nuovo backend;
#  3. confronto visivo PHP / nuovo frontend (Vite su :5180 gia' avviato).
# Il backend viene riavviato prima di ogni fase: i limiti in memoria (es.
# recupero password 3 ogni 15 minuti) non devono passare da una fase all'altra.
# Uso (dalla cartella backend): bash scripts/verifica-completa.sh
set -uo pipefail
cd "$(dirname "$0")/.."
ESITO=0

ferma() {
  powershell -NoProfile -Command "\$c = Get-NetTCPConnection -LocalPort 3100 -State Listen -ErrorAction SilentlyContinue; if (\$c) { Stop-Process -Id \$c.OwningProcess -Force }" >/dev/null 2>&1 || true
}
avvia() {
  ferma
  npm run --silent start >/tmp/cora-backend.log 2>&1 &
  for _ in $(seq 1 60); do
    [ "$(curl -s -o /dev/null -w '%{http_code}' http://localhost:3100/api/v1/auth/sessione)" = 200 ] && return 0
    ping -n 3 127.0.0.1 >/dev/null 2>&1 || sleep 2
  done
  echo "Backend non partito"; tail -20 /tmp/cora-backend.log; exit 1
}

echo "== Test unitari"
npx jest 2>&1 | grep -E "^(PASS|FAIL)|^Tests:" || ESITO=1

echo "== Equivalenza"
avvia
npm run --silent test:equivalenza 2>&1 | grep -E "^(PASS|FAIL)|^Tests:|^  ●" ; [ "${PIPESTATUS[0]}" = 0 ] || ESITO=1

echo "== Confronto visivo"
npx ts-node -e "require('./test/equivalenza/ambiente').ripristina()"
avvia
(cd ../frontend && node test/visivo/confronta.mjs) 2>&1 | grep -E "^(PASS|FAIL)|Error" ; [ "${PIPESTATUS[0]}" = 0 ] || ESITO=1

ferma
echo "== Esito: $([ $ESITO = 0 ] && echo OK || echo ERRORI)"
exit $ESITO
