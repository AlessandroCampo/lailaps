# Archivio delle run di test per training futuro — v2

Data: 17 ottobre 2026. Stato: proposta, senza implementazione.

Questa versione rivede [il piano del 14 ottobre](training-data-python-20261014.md), che resta conservato come documento storico. Perimetro: repository corrente, runtime Python `agent/pentest-agent` con Pydantic AI e avvio/preparazione Laravel. Escluso il rewrite Lailaps 2.

## 1. Obiettivo e decisione

Iniziare a conservare le run interne di test in modo che, in futuro, sia possibile revisionarle, selezionare esempi e sperimentare il training di un modello open-weight eseguito localmente.

Il risultato del P0 è un archivio di esperienza verificabile, non un dataset già etichettato. Deve permettere di capire, anche dopo la cancellazione del target temporaneo:

- quale incarico aveva il modello e quali informazioni aveva ricevuto;
- quale risposta o azione ha prodotto, compresi errori e tentativi falliti;
- quali osservazioni sono state acquisite e quali effettivamente mostrate;
- quali trasformazioni e decisioni ha applicato l'orchestratore;
- come si è evoluta la lead e quale esito è stato registrato.

Rimandare catalogazione e labeling è possibile; recuperare in futuro contenuti mai conservati non lo è. La raccolta non modifica prompt, budget, contratti model-facing o decisioni investigative e non aggiunge chiamate LLM.

### P0 — necessario ora

1. Raccolta esplicitamente abilitata per test interni con target autorizzati e dati sintetici.
2. Manifest, traccia incrementale, revisioni delle lead e allegati durevoli su filesystem.
3. Cattura comune delle chiamate modello e riuso delle fonti operative già disponibili.
4. Conservazione della versione del target e dei riferimenti necessari alla revisione futura.
5. Diagnosi delle perdite e verifica offline della leggibilità e completezza dell'archivio.
6. Conservazione degli artifact storici già disponibili, con limiti dichiarati.

### P1+ — escluso dall'implementazione iniziale

Catalogo SQL, tabelle di label, annotazione umana/LLM, export SFT/Parquet, split dei dataset, training, scelta del modello base, adapter, preferenze, RL, raccolta multiutente, object storage e deduplicazione dei prefissi delle richieste. Non occorrono nuovi agenti, event bus, vector DB o sistemi distribuiti.

## 2. Fotografia del codice e riuso

La review è riferita al checkout locale del 17 ottobre, incluse le modifiche presenti; la versione effettiva del motore andrà identificata per ogni raccolta.

| Fonte esistente | Dati riutilizzabili | Limite da colmare |
| --- | --- | --- |
| `ArtifactStore`, `RunStorage` | Outcome, transcript, telemetria e prodotti persistiti | Il risultato finale non descrive ogni contesto intermedio |
| `ArtifactStore.append_tool_trace` | Journal redatto per lead, con input, risultati acquisiti, stati e collegamenti alle subcall | Non è una registrazione completa delle richieste modello; va preservato oltre il cleanup operativo |
| `ToolOutputManager` | Risultati prima della preview, metadati, consegna e recupero scoped; archivio per output associati alle lead | La LRU è limitata; la proiezione della history può modificare ancora il contenuto inviato |
| `BenchmarkStageArtifact` | Prodotti strutturati, lineage, configurazione/provenance e metriche disponibili | Non contiene l'intera traiettoria |
| `_stream_role`, `ProcessHistory`, compaction | Conversazioni, retry e transizioni operative | Non basta leggere `all_messages()` alla fine |
| `LeadLedger` e prodotti dei ruoli | Identità, ipotesi, aggiornamenti e verdetti | Gli aggiornamenti possono sostituire lo stato precedente |
| `model_trace.append_trace`, `run_events.emit_event` | Call site utili per individuare punti di cattura | Gli hook sono disattivati; non costituiscono oggi un archivio |

Il contratto operativo comprende già journal Investigator oltre a transcript e outcome: il riferimento precedente ai soli due file va superato. Anche la distinzione fra ruoli e fasi deve seguire il runtime reale, inclusa l'indagine unificata, senza presupporre un handoff Confirmer/Worker in ogni run.

Riutilizzare acquisizione, identità e provenance esistenti. Non implementare una seconda logica di esecuzione o registrazione dei tool. L'archivio di raccolta deve però possedere i contenuti necessari, tramite copie durevoli, senza dipendere da file operativi cancellabili.

## 3. Contratto minimo dei dati

### Manifest della run

Scrivere un manifest iniziale prima della prima chiamata modello. Conservare:

- `schema_version`, ID globale di raccolta, run ID operativo, origine del test e collegamenti parent/resume;
- identificatore del progetto, riferimento alla versione sorgente conservata e fingerprint del contenuto auditato;
- versione del motore: commit e modifiche locali necessarie a identificarlo, oppure riferimento a un'immagine conservata; lockfile/versioni delle dipendenze;
- configurazione effettiva sanificata, modelli/provider richiesti e serviti quando osservabili;
- policy di raccolta/sanificazione, timestamp, stato operativo ed esito finale disponibili;
- stato della raccolta, limiti di acquisizione e perdite note.

Il run ID leggibile non è una chiave globale. Campi non osservabili restano assenti o esplicitamente sconosciuti; usage mancante non diventa zero. Non salvare dump indiscriminati delle variabili d'ambiente.

### Ogni richiesta modello

Conservare tre livelli distinti, collegati tramite identità assegnate dal runtime:

| Livello | Contenuto |
| --- | --- |
| Richiesta | Istruzioni, messaggi ordinati dopo i processor, tool effettivamente disponibili con schemi, schema/modalità di output e impostazioni di generazione |
| Risposta osservabile | Testo, argomenti originali delle tool call e output prima delle riparazioni/normalizzazioni applicative, stato completo/parziale, errori, finish reason e usage disponibili |
| Applicazione | Riparazioni, normalizzazione, decisione effettivamente applicata e riferimenti ai prodotti/lead aggiornati |

Registrare conversazione, ruolo, fase, area/lead/scope, request, tentativo e transizione di compaction quando pertinenti. Collegare i retry al tentativo precedente e conservare il feedback effettivamente mostrato. Includere richieste di recon, Reader, Investigator, reviewer, deduper, summarizer ed evaluator effettivamente eseguite, con purpose distinto; non introdurne di nuove.

Il punto comune di costruzione del modello in `make_model` è il candidato iniziale. Verificare l'API della versione Pydantic AI in uso e coprire streaming, non streaming e client HTTP personalizzati. Un wrapper può osservare il confine del modello, ma non garantisce da solo la cattura del payload provider o di risposte che falliscono prima di diventare messaggi Pydantic. Distinguere esplicitamente il livello osservato, comprese eventuali trasformazioni dell'adapter.

Verificare errori di parsing e stream interrotti: conservare le parti osservabili quando il punto di cattura lo permette; altrimenti registrare l'errore e la lacuna. Non dichiarare una risposta completa soltanto perché è presente un evento finale. Non è richiesta la durabilità di ogni token prima di un crash improvviso: richieste avviate senza risposta persistita restano riconoscibili come incomplete.

Per il P0 usare snapshot completi per richiesta, senza deduplicare i prefissi. Serializzare una copia al momento della cattura: successive mutazioni della history non devono alterare lo snapshot. Non vincolare il formato al tokenizer/chat template di un futuro modello e non richiedere logits o reasoning nascosto.

### Azioni, osservazioni e allegati

Conservare nome del tool, argomenti sanificati, call ID qualificato per richiesta/conversazione, stato e collegamenti a risultato acquisito e risultato consegnato. L'ordine nella conversazione e i collegamenti causali prevalgono sull'ordine globale di completamento.

Riutilizzare il journal per le osservazioni già archiviate; integrare la raccolta dove i tool o i ruoli non sono coperti. Il contesto effettivo della richiesta resta la fonte per stabilire cosa è stato mostrato, comprese finestre di recupero, preview e risultati ridotti da `project_history`.

Conservare i contenuti acquisiti entro i limiti esistenti, dichiarando troncamenti, timeout ed eviction senza archivio. Un risultato acquisito ma non mostrato resta disponibile per la revisione, senza diventare parte del prompt storico.

Persistire gli allegati realmente necessari, incluse immagini usate come input e contenuti cui rimandano evidenze decisive. Un URL temporaneo o un path nel container non è un allegato conservato. Non occorre acquisire ogni screenshot o tutto il traffico di rete.

Per tool composti, conservare la scelta originale del modello e le subcall come esecuzioni figlie, senza attribuire al modello scelte dirette che non ha fatto. Non rieseguire azioni per completare una traccia mancante.

### Lead, decisioni e compaction

Conservare proposta originale e snapshot immutabili dello stato autorevole ai boundary che lo modificano: creazione, checkpoint/enrichment pertinente, eventuale handoff, chiusura e verdetto. Gli snapshot sono record della traccia, non nuove tabelle SQL.

Mantenere narrativa, source reference, evidenze, piano, unknowns e motivazioni già disponibili. Non aggiungere analisi LLM, tassonomie obbligatorie o campi inferiti. Le proposte scartate prima dell'assegnazione di una lead restano collegate al prodotto d'origine, senza inventare identità autorevoli.

Il recorder assegna identità e collegamenti. Non richiede DTO o campi aggiuntivi al modello. Le revisioni precedenti non vengono sostituite dallo stato finale; ID e campi aggiunti dall'orchestratore restano distinguibili dall'output del modello.

Registrare la compaction come transizione, collegando input/output del summarizer al nuovo contesto operativo. Un fallback senza chiamata LLM resta una trasformazione dell'orchestratore. La storia eliminata dal contesto sopravvive nell'archivio, ma non viene attribuita alle richieste successive.

Conservare separatamente stato della run, verdetto dell'agente ed eventuali valutazioni/oracle già esistenti, con versione e provenienza. `confirmed` non equivale a label gold; assenza di finding, budget esaurito e blocco ambientale non equivalgono a esempi negativi certi. Raccogliere anche errori e run senza finding.

## 4. Storage, durabilità e guasti

Struttura proposta, senza database aggiuntivo:

```text
training/
  runs/{collection_id}/
    manifest.json
    trace.jsonl                 # durante la raccolta
    attachments/{artifact_id}
  targets/{snapshot_id}/        # sorgente conservato, condivisibile fra run
  legacy/{archive_id}/          # copie dello storico non ancora importato
```

Al sealing la traccia può diventare `trace.jsonl.gz`. Conservare checksum, dimensione e tipo degli allegati nel manifest o nella traccia; i riferimenti interni devono essere risolvibili anche spostando l'archivio. Le future annotazioni non riscrivono il contenuto raccolto.

Lo spool risiede direttamente su filesystem persistente: mount host esplicito per CLI/UI/benchmark eseguiti in Docker, esterno ai container effimeri e alle directory eliminate durante il cleanup del target. Riutilizzare il lifecycle esistente. Documentare destinazione, esclusione dal cleanup e procedura di backup/ripristino; usare l'infrastruttura di backup disponibile, senza costruire un servizio nuovo.

Serializzare le append concorrenti e definire confini di flush/durabilità, almeno per la registrazione della richiesta prima dell'invio e ai boundary di completamento. Il lettore conserva il prefisso valido di una traccia interrotta e segnala l'ultima riga incompleta. Se il finalizer non è stato eseguito, l'archivio resta non sealed e non viene presentato come completo.

Il finalizer verifica i riferimenti, preserva le fonti operative necessarie e pubblica lo stato `sealed` o `partial`. Se ripetuto, non duplica eventi o allegati. Il contenuto sealed non viene riaperto per append: una ripresa crea una raccolta collegata alla precedente. Non serve un importer SQL nel P0.

Disco pieno o recorder fallito non devono cambiare il verdetto tecnico: segnalare la perdita sul canale diagnostico disponibile e marcare la raccolta incompleta appena possibile. Non promettere una scrittura su disco quando il disco stesso è indisponibile; la mancanza di sealing rimane un segnale verificabile.

Questa policy vale solo per la raccolta training. Il journal operativo mantiene le garanzie esistenti: gli errori di `append_tool_trace` non devono essere intercettati come semplici errori facoltativi del recorder, permettendo effetti investigativi senza la persistenza oggi richiesta.

## 5. Target, sanificazione e storico

### Versione del target

Conservare una copia recuperabile del sorgente realmente auditato, usando un archivio o un repository conservato con commit, modifiche locali e file pertinenti non tracciati. Riutilizzare lo stesso snapshot per run identiche. Hash o URL upstream, da soli, non garantiscono la disponibilità futura.

Conservare anche fixture/configurazioni sintetiche, identità logiche degli actor e riferimenti a immagini, seed e setup già disponibili. Il P0 non implementa reset/replay del runtime e non garantisce ambienti RL riproducibili; conserva i materiali esistenti utili a costruirli in seguito, senza esportare segreti reali o volumi indiscriminati.

Il sorgente completo può servire alla revisione futura. Le parti non viste dall'agente non entrano retroattivamente nel contesto delle sue decisioni.

### Sanificazione proporzionata

Limitare inizialmente l'abilitazione ai test interni con dati sintetici e target autorizzati alla conservazione. Non attivare implicitamente la raccolta sui contenuti degli utenti.

Rimuovere credenziali provider, segreti infrastrutturali e credenziali/sessioni reali da richieste, risposte, allegati e configurazione. Preservare il significato tecnico dei dati sintetici e delle prove. Applicare sostituzioni coerenti quando necessarie, mantenendo distinguibili actor, risorse e relazioni di proprietà.

Non applicare indiscriminatamente `_redact_sensitive` all'intero envelope: il redattore operativo intercetta sottostringhe nei nomi dei campi e potrebbe oscurare usage, impostazioni o schemi contenenti `token`. Riutilizzare la logica pertinente sui valori sensibili, preservando struttura, nomi delle proprietà e metadati innocui. Non usare un unico placeholder quando collasserebbe identità diverse necessarie a capire una prova.

Registrare la versione e i limiti della sanificazione. Se un contenuto non può essere conservato senza perdere significato o includere dati non ammessi, ometterlo con una motivazione e segnalare la lacuna. Non introdurre un annotatore LLM o una promessa di anonimizzazione generale.

### Run storiche

Preservare copie autorizzate di transcript, outcome, journal, fixture, prodotti benchmark e snapshot target ancora disponibili, fuori dal cleanup ordinario. Accompagnarle con un manifest minimale di provenienza e disponibilità, senza inventare dati mancanti.

Usare `capture_quality=legacy_partial`; distinguere ciò che è presente da ciò che è stato eventualmente ricostruito. Non promettere contesti esatti dove prompt, tool schema o risultati sono mancanti. L'importatore e l'estrazione di episodi storici sono P1; la preservazione dei file disponibili appartiene al P0.

## 6. Verifica offline e criteri di accettazione

Realizzare un comando locale che legga manifest/traccia/allegati e mostri una richiesta o un episodio con input, risposta, tool e revisioni collegate. Non è un exporter di training e non assegna giudizi semantici.

La completezza è valutata per richiesta/episodio oltre che per run: una raccolta interrotta può contenere turni completi. Elencare lacune concrete, come risposta parziale, allegato mancante, schema non catturato o risultato non disponibile; la qualità tecnica della cattura non misura la correttezza del modello.

Verifiche con messaggi salvati, modelli e tool finti:

- ricostruzione di una richiesta dopo compaction, senza reintrodurre storia rimossa;
- streaming/non streaming, client personalizzato, output invalido, retry ed errore di parsing;
- ruoli concorrenti e call ID uguali in conversazioni diverse, senza associazioni errate;
- distinzione tra output acquisito, preview e finestra effettivamente consegnata;
- conservazione dell'ipotesi iniziale e delle revisioni successive della lead;
- crash prima del finalizer, coda JSONL incompleta, richiesta senza risposta e finalizzazione ripetuta;
- archivio ancora leggibile dopo la rimozione di container, target temporaneo e artifact operativi;
- rimozione dei segreti senza corrompere tool schema, usage o relazioni sintetiche;
- nessuna variazione di prompt, budget, chiamate o output model-facing con recorder attivo;
- errore del recorder facoltativo distinto dall'errore di persistenza del journal operativo;
- ripristino di una raccolta dal backup con riferimenti risolvibili.

Non avviare loop agentici con API, benchmark costosi o lint dell'intero progetto per verificare questa implementazione. I controlli tecnici locali non devono diventare labeling semantico deterministico.

## 7. Sequenza di implementazione P0

1. Definire manifest/envelope v1, abilitazione per test interni e destinazione persistente; preservare lo storico disponibile.
2. Verificare il punto di cattura nella versione Pydantic AI in uso con richieste finte e fissare il livello di fedeltà effettivamente ottenibile.
3. Integrare snapshot delle richieste/risposte, scope e transizioni senza modificare i contratti dei modelli.
4. Collegare journal, osservazioni e revisioni delle lead; preservare allegati e snapshot target.
5. Completare sealing, diagnosi, lettura/verifica offline e procedura di backup; eseguire i controlli mirati.
6. Aggiornare `ARCHITECTURE.md` quando la persistenza sarà implementata, descrivendo il comportamento reale. Questa proposta non modifica l'architettura operativa.

## 8. P1 e P2

**P1 — rendere il corpus selezionabile e sperimentare.** Importare lo storico, aggiungere un catalogo SQL solo quando servono query, introdurre annotazioni separate e versionate, selezione e split per famiglie di progetti/derivazioni. Preparare export per un solo ruolo e confrontare base/adapter su progetti esclusi dal training. Label LLM, revisione umana ed evidenza/oracle restano distinguibili. Decidere modello, hardware, template e LoRA/QLoRA in questa fase.

Storage remoto, deduplicazione dei prefissi e UI di labeling entrano solo quando volume o operatività li giustificano. La raccolta multiutente richiede una policy e un perimetro dedicati, senza estendere automaticamente l'abilitazione dei test interni.

**P2 — preferenze e RL quando motivati.** Costruire alternative confrontabili dallo stesso contesto; per RL preparare sandbox con reset e verifica indipendente e generare nuovi rollout del modello da allenare. Le traiettorie storiche non sostituiscono gli ambienti né i rollout richiesti dal training on-policy.

## 9. Riferimento DeepSeek Harness

Consultato nella review del 17 ottobre alla revisione `687f282e8625c6b8d11ff58211a90524be2e4317`:

- [`Session` e `deriveMessages`](https://github.com/deepseek-ai/deepseek-harness/blob/687f282e8625c6b8d11ff58211a90524be2e4317/packages/core/session/src/index.ts);
- [proiezione della superficie dei messaggi](https://github.com/deepseek-ai/deepseek-harness/blob/687f282e8625c6b8d11ff58211a90524be2e4317/packages/core/session/src/surface.ts);
- [backend di persistenza JSONL](https://github.com/deepseek-ai/deepseek-harness/blob/687f282e8625c6b8d11ff58211a90524be2e4317/packages/session/session-persistence-jsonl/src/index.ts).

Il concetto trasferibile è separare storia conservata e contesto proiettato, soprattutto dopo compaction, e rendere espliciti i confini di durabilità. Non è necessario trasformare Lailaps in un sistema event-sourced o adottare il framework/plugin Harness. Per questo P0 bastano una raccolta separata e il riuso delle fonti operative esistenti.
