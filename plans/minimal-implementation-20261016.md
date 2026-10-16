# Minimal: implementazione P0-S / A / B / C / D

Branch: `minimal`. Il catalogo minimal è l'unico esposto; nessun selettore di
versione o fallback ai tool rimossi. Browser e relativo worker sono invariati
rispetto a `3435bb3`. Non sono stati avviati benchmark o richieste ai modelli.

## Riferimenti e consegna

- `08a0f5a`: snapshot dello stato applicativo precedente, comprese le modifiche
  locali necessarie al runtime unificato. Il branch di partenza non viene spostato.
- `3435bb3`: P0-S, catalogo precedente con SQL già abilitato alle modifiche dei
  record. È il riferimento sorgente per il futuro confronto controllato.
- Il commit successivo consegna A-D insieme, compresi gli aggiustamenti finali SQL.
  Non sono disponibili revisioni o benchmark separati per ciascuna rimozione A-D.

## Modifiche applicate

| Passaggio | Risultato |
|---|---|
| S | Unico `query_database`: letture e INSERT/UPDATE/DELETE; parser nel dialetto del motore, singola istruzione, rifiuto delle modifiche strutturali/amministrative e delle procedure non riconosciute. Policy narrativa su setup realistico, privilegio dell'attaccante e separazione tra preparazione e prova. |
| A | Rimossi `list_dir`, `run_code` e relativi binding. Bash, find, rg e Python nel workspace; citazioni tramite `read_file` e `read_source_ref`. |
| B | Rimossi helper crypto, JSON/HTML e ispezioni specializzate. Recupero con `inspect_tool_output`; esportazione on demand degli output autorizzati e redatti nella shell. Inventari `surface-signals` e `source-references` nello stesso percorso. |
| C | Unico `http_call`: JSON object/array, form, raw e multipart binario; binding token integrato, cookie e sessioni per actor. |
| D | Rimossi `search_source` e tool di grafo, call path, data flow e navigazione Phpactor. Bootstrap disabilitato senza possibilità di riattivazione tramite le vecchie impostazioni. Rimossi launcher CBM, Java, Joern e Phpactor dall'immagine. Semgrep conservato. |

Ogni comando workspace usa un toolbox effimero, source read-only, nessuna rete o
credenziale e `/tmp` nuovo. Gli input esportati sono root-owned e read-only; lo
stdout non produce source reference o prove HTTP. Il journal Investigator esistente
rimane l'archivio durevole e permette il recupero scoped dopo eviction dalla LRU.

Launcher Laravel e configurazioni di esempio puntano a
`lailaps-pentest-agent:minimal`. I launcher non creano o montano più cache CBM/data
flow. `ARCHITECTURE.md`, README e prompt documentano il catalogo effettivo. I campi
legacy di configurazione/telemetria rimasti per i dati storici non abilitano tool o
backend. Budget, checkpoint, deduper e contratti di output dei ruoli non cambiano.

## Verifiche eseguite, senza modelli

- Gruppo di 13 moduli su primitive, esecuzione, output, harness, Investigator,
  pipeline unificata, CLI, storia, surface context e Recon/Reader: **280 passati,
  1 skip** (Semgrep nativo non disponibile sull'host Windows).
- Ultimo controllo SQL/cataloghi: **105 passati**, con sovrapposizione al gruppo
  precedente. Include parser per quattro motori, scritture SQLite reali con trigger,
  payload HTTP, scope/redazione degli export e prompt principali.
- Smoke Docker sul runtime minimal: shell con rg/Python e parsing HTML, input non
  modificabili, nessun file temporaneo riutilizzato nella chiamata successiva.
- Container DB dedicati e senza rete: MySQL 8.4, MariaDB 11.4 e PostgreSQL 16;
  INSERT/UPDATE/DELETE persistono, i trigger esistenti funzionano e i comandi
  strutturali vengono rifiutati. Verificato anche RETURNING su PostgreSQL.
  Solo i container temporanei creati dalle prove sono stati rimossi.
- PHP: 16 test del comando PentestRun e 1 test del runner benchmark riportati
  passati; il processo termina con codice 1 e il warning locale del modulo `gd`
  caricato due volte. Sintassi PHP dei launcher/config verificata.
- Compile Python, controlli F401/F821/F811 sui file interessati e
  `git diff --check` superati. Nessun lint globale.
- Build Docker finale e `pentest --help` senza rete superati.

Il modulo storico `test_triple_agent.py` non è complessivamente verde. Sette
fallimenti sono stati riprodotti anche sul sorgente congelato `3435bb3`:

1. `test_worker_validator_normalizes_response_id_to_request_ref`;
2. `test_candidate_requires_the_three_flat_verification_fields`;
3. `test_candidate_model_schema_exposes_only_the_flat_verification_contract`;
4. `test_confirmer_checkpoint_contract_is_flat_and_enforces_continue_fields`;
5. `test_checkpoint_close_of_last_area_completes_category`;
6. `test_confirmer_self_checkpoint_controls_extensions_and_terminalization`;
7. `test_yeswiki_terminalization_closes_not_exploitable`.

Riguardano DTO, campi checkpoint o aspettative sul prompt precedenti al runtime
unificato. Non sono stati corretti insieme a minimal. Una prova estesa del modulo
è stata interrotta mentre attraversava i vecchi flussi simulati; non si dichiara
quindi superata l'intera suite. I controlli dei cataloghi modificati sono aggiornati
e passano separatamente.

Immagine finale locale: `lailaps-pentest-agent:minimal`, ID
`sha256:f2286ae2599267bd3a0f4585e955a01d4f48e21b860af1b111e9b920d3b3a8c4`,
dimensione riportata da Docker **264.308.362 byte**. Conservata anche l'immagine
preesistente con tag `lailaps-pentest-agent:pre-minimal-20261016`; non è verificata
come build di `3435bb3` e non va usata come baseline controllata senza ricostruzione.

## Validazione ancora da eseguire esplicitamente

La matrice Cacti, YesWiki e Gitea del piano resta la verifica necessaria di qualità,
copertura, errori operativi e costo. Prima costruire la baseline da `3435bb3` e
congelare snapshot, fixture, modelli e budget. Le verifiche locali dimostrano i
contratti delle primitive, non la parità dei finding o una riduzione dei token.
Non sono stati inclusi P1/P2 o ulteriori cambiamenti del loop.
