# Run globale CLI

La modalità globale esegue una sola audit run sequenziale su OWASP 2025 A01-A10, con
inventario e Global Recon condivisi. Non avvia agenti concorrenti e non include il playbook
benchmark `XSS` come categoria aggiuntiva.

## Prerequisiti

- Docker deve essere disponibile al processo Laravel.
- PHP e le dipendenze Composer del repository devono essere installati.
- Il sorgente YesWiki deve corrispondere al descriptor in
  `agent/pentest-agent/benchmarks/targets/yeswiki`.
- Il provider deve essere configurato tramite le variabili già previste dal progetto.
- Modelli e reasoning effettivi sono risolti da `agent/pentest-agent/Models.json` e vengono
  stampati all'avvio. Per la prima misura non cambiare contemporaneamente modelli o sensori.

Verifiche rapide:

```powershell
docker version
php --version
php artisan benchmark:validate yeswiki
```

## Prima run YesWiki

Usare inizialmente il preset `small` per limitare il costo. Il TTL deve coprire provisioning
e timeout agente: il timeout configurato per categoria viene moltiplicato per dieci; con il
default corrente il limite agente è dieci ore, quindi `43200` secondi lascia margine.

```powershell
php artisan benchmark:run yeswiki --global --budget-category=small --test --keep=false --ttl=43200 --tool-output
```

## Selezione dei modelli

`--model` imposta lo stesso modello per Global Recon, Category Recon, Reader, Reviewer,
Confirmer, Worker e Dynamic Judge. È intenzionalmente accettato soltanto con `--global`.

```powershell
php artisan benchmark:run yeswiki --global --model=openai/gpt-5 --budget-category=small --ttl=43200
```

Gli override individuali hanno precedenza su `--model`:

```powershell
php artisan benchmark:run yeswiki --global `
  --model=openai/gpt-5 `
  --recon-model=openai/gpt-5-mini `
  --reviewer-model=openai/gpt-5-mini `
  --worker-model=openai/gpt-5.1 `
  --budget-category=small --ttl=43200
```

Sono disponibili `--recon-model`, `--reader-model`, `--reviewer-model`,
`--confirmer-model`, `--worker-model` e `--judge-model`. `--operative-model` resta una
scorciatoia Laravel per Confirmer e Worker ed è anch'esso più specifico di `--model`.
Se un ruolo non ha un override e `--model` è assente, viene usato `Models.json`.

Il comando materializza una sola vista sorgente sanitizzata, ricostruisce l'immagine agente,
prepara una sandbox nuova, esegue una sola audit run e valuta lo stesso report contro tutti i
manifest. Con `--keep=false` rimuove la sandbox alla fine. Gli oracle restano esterni ai
prompt. La run reale non fa parte dei test automatici.

All'avvio controllare modelli effettivi, categorie, preset e cap globale. Nell'outcome sotto
`storage/app/runs/yeswiki/global/{run_id}` verificare soprattutto:

- `global_recon`, `effective_category_order` e `coverage_by_category`;
- `global_budget`, incluse riserve, residuo e snapshot del broker staged;
- lead fuori categoria tramite `discovery_category` e `owasp_category`;
- `lead_relations` e `duplicate_suppressions`, con motivazione;
- bucket `confirmed`, `suspected`, `rejected_inconclusive` e gap residui.

`global_recon.status=fallback` indica che la ricognizione model-driven non è riuscita: le
categorie restano esplorabili usando l'inventario deterministico, e il report non deve essere
letto come dichiarazione di copertura completa.
