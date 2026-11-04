#!/bin/sh
# Copia una tantum dei dati di produzione dal PHP (MariaDB + file) a Cora.
# Lo lancia il servizio "migrazione" di docker-compose.node.prod.yml.
#
# Parte solo con MIGRA_DATI=si. Il database di arrivo deve essere appena
# creato: converti-dati.js rifiuta tabelle non vuote, quindi rilanciarlo per
# sbaglio dopo l'avvio non tocca nulla. I file vecchi si copiano senza
# sovrascrivere quelli gia' presenti.
set -e

# Dopo la copia: solo le righe cambiate sul PHP da RECUPERA_DA (vedi recupera-modifiche.js).
if [ "$MIGRA_DATI" = "recupero" ]; then
  echo "== Recupero delle modifiche fatte sul PHP dopo la copia =="
  node scripts/recupera-modifiche.js
  exit 0
fi

if [ "$MIGRA_DATI" != "si" ]; then
  echo "MIGRA_DATI non impostata a 'si': nessuna copia."
  exit 0
fi

echo "== Copia del database (MariaDB -> Postgres) =="
node scripts/converti-dati.js

echo "== Copia dei file (storage e uploads) =="
cp -a -n /vecchio/storage/. /data/storage/
cp -a -n /vecchio/uploads/. /data/uploads/
# Il backend gira come utente "node" (uid 1000)
chown -R 1000:1000 /data/storage /data/uploads

echo "== Copia completata =="
