# Buoni pasto (ticket restaurant) — conteggio mensile

**Data:** 2026-06-16
**Stato:** implementato (2026-07-02) — migration 047, classe MealVoucher + unit test, config in work-schedule.php ed employees.php, colonna "Buoni pasto (nr)" nell'export

## Obiettivo

Introdurre il conteggio dei **buoni pasto** (ticket restaurant) basato sulle ore
lavorate. Per ogni dipendente si calcola **quanti ticket spettano nel mese** e il
numero compare nell'**export presenze mensile**.

Per ora **non interessa l'importo/ammontare**: solo il conteggio dei ticket.

## Decisioni (dal brainstorming)

| Tema | Scelta |
|------|--------|
| Fonte ore | Presenza **dedotta** (no timbrature): giorno lavorativo non assente = `hours_per_day` |
| Smart working | Giorni SW **ricorrenti configurati nel profilo dipendente** |
| Regola base | **1 ticket/giorno con soglia ore minima** configurabile |
| SW e ticket | Configurabile, **default NO** (SW non dà ticket) |
| Livello config | **Azienda** + **override per dipendente** |
| Permessi a ore | **Sottrae e ricontrolla soglia** (`ore_eff = hours_per_day − permessi`) |
| Visualizzazione | **Colonna riepilogo "Buoni pasto (nr)"** nell'export, calcolata al volo |

## Modello di calcolo

Per ogni dipendente, per ogni giorno del mese si assegna **1 ticket** se e solo se
tutte le condizioni sono vere:

1. **Giorno lavorativo** — il giorno della settimana è nei `working_days` del
   dipendente (fallback: default azienda). Esclusi automaticamente: weekend,
   festività italiane (`ItalianHolidays`), chiusure aziendali (causale `chiusura`).
2. **Non assenza piena** — nessuna assenza a giornata intera: ferie, malattia,
   permesso_104, congedo_parentale, altro, permesso a giornata intera.
3. **Ore effettive ≥ soglia** — `ore_eff = hours_per_day − ore_di_permesso_a_ore_del_giorno`.
   Deve essere `ore_eff ≥ soglia_ore`.
4. **Smart working** — se il giorno è un giorno SW ricorrente del dipendente:
   - regola "SW non dà ticket" (default) → **0 ticket**
   - regola "SW dà ticket" → si comporta come un giorno in sede (vale la soglia)

Totale mese = somma dei giorni idonei. Calcolato **al volo durante l'export**:
nessuna tabella saldi, nessuno storico (YAGNI).

I dati per-giorno (assenze, permessi a ore) sono già caricati da `PresenzeExport`
da `leave_requests`; il calcolo li riusa senza nuove query.

## Configurazione

### Livello azienda — `companies` (UI: `public/admin/work-schedule.php`)

Accanto a giorni/ore lavorative già presenti:

- `buoni_pasto_enabled` TINYINT(1) DEFAULT 0 — attiva la feature per l'azienda
- `buoni_pasto_min_hours` DECIMAL(4,2) DEFAULT 6.00 — soglia ore minima
- `buoni_pasto_sw_eligible` TINYINT(1) DEFAULT 0 — i giorni SW danno ticket?

### Livello dipendente — `employees` (UI: `public/admin/employees.php`)

Tutte NULL = eredita dall'azienda:

- `smart_working_days` SET('mon'…'sun') NULL — giorni SW ricorrenti
- `buoni_pasto_min_hours_override` DECIMAL(4,2) NULL — soglia personalizzata
- `buoni_pasto_sw_eligible_override` TINYINT(1) NULL — regola SW personalizzata
- `buoni_pasto_excluded` TINYINT(1) NOT NULL DEFAULT 0 — escludi del tutto

## Componenti / codice

- **`src/classes/MealVoucher.php`** (nuova) — logica pura e testabile.
  `MealVoucher::monthlyCount(array $cfg, int $year, int $month, array $dailyLeaveByDate): int`
  dove `$cfg` è la config già risolta (azienda + override): `working_days`,
  `hours_per_day`, `smart_working_days`, `min_hours`, `sw_eligible`, `excluded`.
  Isolata dall'export per essere testabile da sola.
- **`PresenzeExport`** — risolve la config per dipendente, chiama
  `MealVoucher::monthlyCount` riusando le celle/dati già caricati, e aggiunge una
  **5ª colonna riepilogo "Buoni pasto (nr)"** dopo Ferie/Permessi/Malattia/104
  (`fillSheetXml`, sezione 3b, a `lastDayColNum + 6`).
- **Risoluzione config** — riuso del pattern di fallback employee→company già usato
  per `working_days`/`hours_per_day` (`LeaveBalance`/`Employee`).
- **Migration `database/migrations/047_buoni_pasto.sql`** — aggiunge le colonne a
  `companies` ed `employees`, idempotente (pattern `IF NOT EXISTS` come le altre).

## Test

`MealVoucher` essendo pura si presta a unit test sui casi chiave:

- giorno pieno lavorativo → 1
- ore sotto soglia (part-time) → 0
- permesso a ore che fa scendere sotto soglia → 0; permesso a ore che resta sopra → 1
- assenza a giornata intera (ferie/malattia/104/…) → 0
- giorno SW con regola NO → 0; con regola SÌ e sopra soglia → 1
- weekend / festività / chiusura → 0
- dipendente `buoni_pasto_excluded` → 0
- feature disattivata a livello azienda → colonna vuota / 0

## Flusso amministrazione (da validare con lo staff)

Step-by-step lato admin, per capire se l'impianto va bene:

1. **Attivazione azienda** — Admin → *Orario di lavoro* (`work-schedule.php`):
   spunta "Abilita buoni pasto", imposta la **soglia ore** (default 6) e sceglie se
   lo **smart working dà diritto al ticket** (default no). Salva.
2. **Configurazione dipendente (opzionale)** — Admin → *Dipendenti* → modifica:
   imposta i **giorni di smart working ricorrenti** (es. mar/gio) e, se serve, una
   soglia/regola SW personalizzata oppure esclude il dipendente.
3. **Lavoro normale** — durante il mese i dipendenti registrano ferie/permessi/
   malattia come sempre. Nessun nuovo inserimento richiesto per i buoni pasto.
4. **Export presenze** — a fine mese Admin/Consulente paghe → *Export presenze*:
   il file XLSX mostra, accanto a Ferie/Permessi/Malattia/104, la nuova colonna
   **"Buoni pasto (nr)"** con il totale ticket del mese per ciascun dipendente.
5. **Verifica** — il consulente paghe legge il numero di ticket e lo usa per
   l'elaborazione, senza dover fare conti a mano.

## Fuori scope (per ora)

- Importo/valore economico dei ticket.
- Tracciamento giornaliero dello smart working occasionale (solo ricorrente).
- Uso delle timbrature NFC reali (`attendance_punches`) come fonte ore.
- Storico/saldo dei buoni pasto a DB.
