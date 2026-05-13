# Anteprima design v2 — Container parallelo

Permette di testare il nuovo design (palette blu Factorial-style + sidebar
collassabile + tenant switcher in sidebar + footer Connecteed) **senza
toccare la produzione**. Il container preview gira affiancato a quello
attuale, attaccato allo **stesso database** — vedi i tuoi dati reali.

```
┌───────────────────────┐         ┌───────────────────────┐
│  localhost:8888       │         │  localhost:8889       │
│  gestionalepa-app-1   │         │  gestionalepa-app-    │
│  (main, design legacy)│         │  preview (v2 design)  │
└────────────┬──────────┘         └────────────┬──────────┘
             │                                 │
             └───────────────┬─────────────────┘
                             ▼
                   ┌──────────────────────┐
                   │  gestionalepa-db-1   │
                   │  (stesso MariaDB)    │
                   └──────────────────────┘
```

## Avvio

Devi avere il container principale già in esecuzione (`docker compose up -d`).

```bash
# 1. Crea worktree separato con la branch v2 checked out
git worktree add ../gestionalepa-preview preview/v2-design

# 2. Entra nella directory worktree
cd ../gestionalepa-preview

# 3. Build + avvia il container preview
docker compose -f docker-compose.preview.yml up -d --build
```

Apri il browser: **http://localhost:8889**

Login con qualsiasi utente reale (admin, dipendente, ecc.) — sono gli stessi
account del container principale.

In cima vedrai una **barra blu** con scritto _"Anteprima nuovo design v2"_
così non confondi mai il preview con la produzione.

## Cosa è cambiato

- **Palette**: blu Factorial-style (`#2563eb`) sostituisce il vecchio blu
- **Tipografia**: font Inter al posto di Geist
- **Sidebar**: bottone toggle in alto per ridurla a 72px (icone only + tooltip)
- **Tenant switcher** (admin/accountant/consulente): card in cima alla sidebar
  con dropdown delle aziende assegnate, click cambia azienda al volo
- **Footer**: "Powered by Connecteed" con logo SVG su ogni pagina
- Card, badge, bottoni con radius e ombre nuove

Tutto il resto (layout pagine, contenuti, funzioni) **funziona identico**
al container principale.

## Confronto side-by-side

Apri 2 finestre del browser:
- Una su `localhost:8888` (vecchio)
- Una su `localhost:8889` (nuovo)

Fai le stesse azioni in entrambe: dashboard, anagrafica dipendenti, ferie,
chat, profilo. Cambia azienda dal tenant switcher (solo nuovo).

## Fermare il preview

```bash
cd ../gestionalepa-preview
docker compose -f docker-compose.preview.yml down
```

Per rimuovere completamente il worktree quando hai deciso:

```bash
git worktree remove ../gestionalepa-preview
git branch -D preview/v2-design   # se decidi di scartarla
```

## Se ti piace → produzione

```bash
git checkout main
git merge preview/v2-design
git push origin main
# Dokploy redeploya il container principale con il nuovo design
```

## Se NON ti piace

Niente da fare lato produzione: la branch resta locale, il container
principale e Dokploy continuano col design attuale.

## Note tecniche

- Il container preview NON esegue migration (`RUN_MIGRATIONS=false`):
  usa il DB esistente del container principale così com'è
- Volumi `uploads` e `storage` condivisi → vedi le stesse foto profilo,
  documenti, allegati chat
- Volume `logs` separato per non sporcare i log di produzione
- `JWT_SECRET` diverso: sessioni separate (devi fare login a parte sul :8889)
- Banner v2 attivo solo nelle pagine che usano `header-admin.php`,
  `header-employee.php` o `header-admin-reparto.php`. Le pagine di login
  e public sono in comune e non mostrano il banner
