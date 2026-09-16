# Handoff — Graft su Recon e confronto con Codebase Memory

## Obiettivo e perimetro

Implementare una prova eliminabile di Graft, inizialmente soltanto nel percorso Recon-only, e confrontarla con Codebase Memory (CBM) usando il benchmark esistente. La domanda è se migliora la copertura delle superfici o riduce il costo a copertura equivalente. Non assumere che Graft debba essere mantenuto.

Questo documento è un handoff di implementazione, non il resoconto di un esperimento già eseguito. La richiesta corrente riguarda la sua preparazione. L'implementazione successiva deve seguire AGENTS.md e leggere lo stato corrente: il workspace contiene già modifiche indipendenti, da preservare.

Scelta P0: un solo branch sperimentale e un flag `--recon-code-intelligence=cbm|graft`, default `cbm`. Entrambi i bracci devono usare lo stesso commit. Non creare un framework di provider, un nuovo agente, una UI o due implementazioni del loop Recon. Non estendere Graft a Reader, Confirmer o alla run completa in questa fase.

## Punti di partenza verificati

- `app/Console/Commands/BenchmarkRecon.php`: comando `benchmark:recon`, ripetizioni, materializzazione sorgente sanitizzata, valutazione e registrazione degli artifact; seleziona anche un Golden Recon.
- `app/Services/Pentest/BenchmarkReconEvaluator.php`: strict/guided/weak recall, normalized score, recall@1/3/5 e dettagli per caso. È un evaluator euristico di copertura, non una misura di vulnerabilità confermate.
- `agent/pentest-agent/src/pentest_agent/cli.py`: `recon_only` inizializza attualmente CBM e chiama il vero `run_recon_only()`.
- `agent/pentest-agent/src/pentest_agent/triple_agent.py`: `category_recon_tools`, prompt Recon, validazione dei locator e costruzione Surface Context. La prima richiesta con CBM è riservata a `codebase_architecture`.
- `agent/pentest-agent/src/pentest_agent/tools/graph.py`: wrapper CBM, proiezioni bounded e gestione output/provenienza.
- `agent/pentest-agent/src/pentest_agent/codebase_memory/`: bootstrap, client e stato CBM da lasciare utilizzabili senza Graft.
- `ARCHITECTURE.md`: sezioni Ruoli e output, Benchmark dedicato Category Recon e AgentBench.

Il comando Laravel espone `--reader-model`, ma Python seleziona Recon con `recon_model or settings.recon_model or settings.model`: non presumere che `--reader-model` controlli il modello del test. Aggiungere e inoltrare `--recon-model` e registrare il modello effettivo.

## Verifica del backend prima di implementare

Fonti ufficiali consultate durante la preparazione:

- https://trailhq.com/graft
- https://github.com/trailhq/Graft
- https://github.com/trailhq/Graft#agent-integration
- https://github.com/trailhq/Graft#what-runs-where

La documentazione distingue grafo strutturale Tree-sitter senza LLM e `build --deep` con sintesi LLM. Espone mappa repository, ricerca, firme e call graph tramite CLI/MCP. Verificare sulla versione effettivamente scelta gli argomenti, l'output macchina, le esclusioni dei file e la possibilità di tenere la cache fuori dal source root. Non copiare API ipotizzate dalla landing page.

Fissare versione e dipendenze riproducibili a build-time. Niente `npx ...@latest` o download automatici durante l'audit. La landing dichiara assenza di telemetria, mentre il README consultato descrive telemetria disattivabile: verificare e disabilitare telemetria e controlli di aggiornamento nella configurazione dell'esperimento.

## P0 — Implementazione minima

### 1. Selezione e isolamento

- Inoltrare il flag da Laravel al comando Python `recon`, nei runtime locale e container. Rifiutare valori sconosciuti. Il default deve conservare il comportamento CBM attuale.
- In Recon-only inizializzare esclusivamente il backend scelto. Non installare dinamicamente Graft se manca; la modalità CBM deve funzionare anche senza il binario Graft.
- Aggiungere un piccolo client/bootstrap Graft e wrapper specifici Recon. Riutilizzare gestione subprocess, timeout e artifact dove adatto; non rifattorizzare tutti i tool CBM per ottenere simmetria astratta.
- Impedire contaminazioni: nel braccio Graft, né prompt né Surface Context devono contenere architettura, simboli o risultati CBM. Conservare lo stesso inventory Semgrep e gli stessi listing/search testuali nei due bracci.
- Mantenere il backend e i relativi metadati nel contesto della run Recon-only; non cambiare globalmente il significato di `cbm_runtime` o dei flag CBM usati dagli altri ruoli.

### 2. Capacità esposte a Recon

P0 usa Graft strutturale, senza `--deep`: è il test più semplice del backend e del retrieval. Un risultato negativo riguarda questa configurazione, non dimostra l'inutilità dell'arricchimento semantico opzionale.

Esporre una mappa iniziale e una ricerca strutturale mirata, oltre agli attuali `list_dir` e `search_source`. Conservare la stessa politica della prima richiesta dedicata alla mappa; adattare solo le istruzioni necessarie ai tool del backend. Non aggiungere un obbligo di chiamata per area.

Recon continua a non leggere implementazioni, usare HTTP o eseguire comandi arbitrari. Graft può includere sorgente nei risultati: il wrapper deve proiettare soltanto mappa, firme/locator e metadati strutturali ammessi, rimuovendo corpi, snippet e crux prima dell'esposizione. Non offrire tutti i tool MCP soltanto perché disponibili. Conservare limiti comparabili di output e budget dei due bracci, rendendo espliciti risultati parziali o troncati.

Prima di introdurre un output model-facing verificare esplicitamente se bastano testo breve e locator piatti. Non esporre wiring JSON, nodi interni annidati o nuovi DTO da far compilare al modello. Il modello sceglie le aree e scrive `next_check`; l'orchestratore valida e collega i riferimenti.

### 3. Handoff e provenienza

- Preservare `CategoryRecon` v4 e il limite/ordine delle aree.
- In Graft usare come locator canonici i path reali osservati; `qualified_names=[]`. Non far passare identificatori Graft per simboli CBM e non obbligare Reader a risolverli con CBM.
- Registrare i path restituiti nel registry Recon esistente soltanto dopo averli validati contro il source root sanitizzato. Escludere cache, path esterni, symlink fuori root e riferimenti inesistenti. Non espandere una directory in tutti i discendenti per gonfiare la copertura.
- La mappa non è evidence e l'assenza nel grafo non dimostra assenza della superficie. Un eventuale replay futuro sul Reader mantiene la verifica del sorgente.

### 4. Esecuzione e fallback

- Indicizzazione source-only: non eseguire codice, build script o installazione delle dipendenze del target. Verificare anche sorgenti senza `.git`, perché l'input può derivare da uno zip.
- Cache scrivibile fuori dalla vista sorgente read-only, isolata per backend e fingerprint sorgente/versione/configurazione. Non usare `graft init` per modificare istruzioni, configurazioni utente o repository del target.
- Timeout di bootstrap/query e output bounded; misurare il consumo nel provisioning attuale senza aumentare implicitamente RAM o concorrenza.
- Su errore del backend, Recon può proseguire con listing e ricerca testuale. Nessun fallback silenzioso da Graft a CBM. Registrare modalità richiesta, backend effettivo e degradazione.
- Un braccio degradato non vale come esecuzione sana del backend richiesto: conservarlo nel conteggio dei fallimenti e separarlo nell'analisi delle prestazioni.

### 5. Artifact e telemetria

Riutilizzare outcome, log e contatori esistenti. Aggiungere solo i metadati mancanti per identificare esperimento, backend/versione, modalità structural, fingerprint, modello effettivo, budget, cache hit/miss, bootstrap/query time e stato operativo. Registrare token input/output/cache, costo economico, wall time completo e picco memoria quando misurabile; dichiarare il perimetro della misura, non spacciare RSS del solo Python per memoria dell'intero backend.

Non mescolare artifact CBM e Graft nelle aggregazioni per modello o nella selezione Golden. Soluzione P0 preferita: rendere esplicite le run sperimentali, escluderle dalla promozione automatica Golden e confrontarle tramite una lista di run ID. Mantenere invariato il comportamento del benchmark ordinario. Evitare migrazioni generalizzate del registry se bastano i metadati già estensibili; verificare anche gli eventuali consumer AgentBench.

## P0 — Protocollo A/B

1. Scegliere dal catalogo tre coppie target/categoria rappresentative, con almeno due stack e almeno un caso di wrapper tra file. Fissare commit e manifest prima delle run. Nessun nuovo target è richiesto per partire.
2. Eseguire prima uno smoke per backend, poi tre ripetizioni per braccio e coppia: 18 run misurate. Alternare ordine A/B e B/A. Usare lo stesso commit della harness, modello effettivo, reasoning, budget, retry, inventory Semgrep, sorgente sanitizzato e contesto pubblico.
3. Misurare il caso cold come principale: cache dell'indice vuota a ogni run, includendo bootstrap nel costo/tempo totale. Preparare allo stesso modo Semgrep nei due bracci e dichiarare eventuale prompt caching del provider, che non coincide con la cache dell'indice. Una prova warm è secondaria e va etichettata separatamente.
4. Tenere ground truth, manifest privati, risposte precedenti e artifact dell'altro braccio fuori dal processo agente e dagli input indicizzati Graft. Riutilizzare `BenchmarkAuditSource` e la sanitizzazione esistente.
5. Riutilizzare l'evaluator senza modificarne i pesi in base ai risultati. Riportare strict/guided recall, recall@1/3/5, normalized score, aree/locator, errori, costo e tempo per run, quindi mediana e range per target. Non presentare tre ripetizioni come prova statistica conclusiva.
6. Ispezionare le differenze per caso: lo strict corrente può assegnare punti per somiglianza tra `qualified_names` e nome del file; guided/weak usano overlap testuale. Poiché Graft usa path, verificare manualmente i casi discordanti sul sorgente prima di attribuire il gain al backend. Documentare eventuali distorsioni, senza cambiare scoring a posteriori per favorire un braccio.

Interfaccia attesa dopo implementazione (opzioni nuove, non disponibili oggi; sostituire i placeholder):

```powershell
php artisan benchmark:recon <target-id> --category=<categoria> --recon-model=<modello> --recon-code-intelligence=cbm --experimental --repetitions=1
php artisan benchmark:recon <target-id> --category=<categoria> --recon-model=<modello> --recon-code-intelligence=graft --experimental --repetitions=1
```

Ripetere alternando i comandi; può bastare uno script piccolo che raccolga run ID. Non serve un nuovo servizio di benchmark. Un report Markdown con tabella dei risultati e link agli outcome è sufficiente.

### Regola di decisione proposta, da fissare prima delle run

- Promuovere a P1 se, senza nuove superfici perse in modo ricorrente, Graft riduce almeno del 15% il costo totale mediano a copertura comparabile, oppure migliora di almeno 5 punti percentuali guided recall/recall@3 su almeno due coppie senza peggiorare sistematicamente strict recall. Nel secondo caso rendere esplicito il costo aggiuntivo: un gain di copertura non implica convenienza economica.
- Non promuovere se aumenta in modo ricorrente fallimenti o consumo oltre il provisioning disponibile. Se le differenze sono instabili, risultato inconclusivo: estendere solo le coppie discordanti a cinque ripetizioni, senza ampliare subito il prodotto.
- Se non emergono gain reali, rimuovere l'esperimento. Non giustificare una dipendenza permanente con il solo calo delle tool call.

Queste soglie sono criteri pratici iniziali, non garanzie statistiche. Un benchmark Recon misura copertura e costo di Recon, non finding o exploit confermati.

## Verifiche e consegna

Test mirati: default CBM e propagazione flag/modello nei due runtime; nessun bootstrap o contesto CBM nel braccio Graft; assenza di implementazioni negli output Graft; path/provenienza e handoff validi; timeout/fallback espliciti; sorgenti senza Git e read-only; metadati esperimento e nessuna promozione Golden. Aggiungere un controllo di regressione che gli altri ruoli conservino i tool attuali. Non eseguire lint sull'intero progetto.

Consegnare codice minimo, comandi riproducibili, versione pinned, risultati completi incluse run degradate, e una decisione keep/drop/inconclusivo motivata. Se l'ambiente impedisce il benchmark, dichiarare il blocco concreto e separare implementazione verificata da gain non misurato.

Aggiornare `ARCHITECTURE.md` durante l'implementazione effettiva per descrivere flag, scope Recon-only, provenienza, fallback e isolamento degli artifact. Questo handoff da solo non cambia l'architettura in esecuzione.

## P1/P2 e rimozione

- P1 facoltativo: variante `graft --deep` se il test strutturale lascia un'ipotesi concreta sul valore della mappa semantica. Registrare modello, prompt/configurazione, token e costo della sintesi nel totale cold; applicare lo stesso isolamento dalla ground truth e il divieto di snippet nel contesto Recon. Non confrontare sintesi gratuita/precalcolata contro bootstrap cold CBM.
- P1 dopo gain: replay Reader con handoff congelati per verificare se la migliore copertura produce più lead utili. Solo in seguito valutare Recon con Graft nella run completa, lasciando CBM agli altri ruoli e misurando il costo di entrambi gli indici.
- P2: estensione agli altri ruoli o astrazione comune soltanto dopo vantaggi ripetuti. Non parte del P0.
- Drop: mantenere dipendenza, client e wiring Graft in commit isolati; rimuoverli insieme a flag e configurazioni sperimentali, conservando report e risultati. Il percorso CBM deve restare utilizzabile durante tutta la prova. Non creare un'architettura permanente per una tecnologia che potrebbe essere scartata.
