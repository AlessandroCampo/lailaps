# C2 su lailaps-2 — dal progetto caricato al Lab pronto per Recon

Data: 9 ottobre 2026. Documento di progettazione per il rewrite prevalentemente manuale su **lailaps-2**. Lailaps/Python è un riferimento di comportamento e di qualità, non la base da estendere o una lista di componenti da copiare.

Il checkout disponibile in questa conversazione è Lailaps; la base di lailaps-2 non è stata ispezionata perché non è presente nei progetti/cartelle disponibili. L'upload e la persistenza ZIP in lailaps-2 sono il punto di partenza dichiarato dall'utente. I nomi di modelli/tabelle sotto sono proposti e vanno adattati alle convenzioni del nuovo progetto.

Questo documento sostituisce, **per la progettazione di C2 su lailaps-2**, le indicazioni precedenti di `c2a-runtime-minimo.md` su stdout NDJSON, outcome autorevole e polling della UI. Non modifica il runtime Python né ARCHITECTURE.md, che deve continuare a descrivere l'implementazione attuale.

## 1. Risultato richiesto e decisioni fissate

C2 è completo quando da un progetto ZIP si ottiene un Lab verificato e riproducibile, e il launcher può consegnarne una nuova istanza attiva al punto di ingresso di Recon con lettura sorgente e comandi nel target funzionanti. La produzione e la valutazione del piano Recon appartengono a C3.

- Laravel + Vue/Inertia: prodotto, autorizzazione, DB, queue e supervisione.
- Runtime Node/TypeScript, Express nel modulo runtime dove previsto, Vercel AI SDK per le chiamate modello e i tool. Nessun ORM o seconda queue TS.
- TS invia eventi/checkpoint/decisioni alle API interne Laravel. Laravel è l'unico writer del DB e conferma le scritture dopo il commit. stdout/stderr sono diagnostica.
- Laravel espone SSE alla UI. SSE è indipendente dal processo agente e dalla durata del job.
- Il Doctor termina con `ask`, `prepare` o `blocked`. Dopo le risposte si avvia una nuova esecuzione con summary, risposte e artifact correnti; non si riprende una tool call sospesa.
- C2 consegna la modalità sandbox locale. URL e combinazione URL + Lab sono estensioni successive del target dell'audit: il Lab locale rimane utile anche con URL remoto.

## 2. Che cosa è un Lab e a cosa si lega
w
**Un Lab è la definizione verificata dell'ambiente di test di una specifica revisione del sorgente. Un'istanza del Lab è l'ambiente effettivamente acceso.**

```text
Utente → Progetto → SourceRevision immutabile → Lab
                                               ├─ AgentRun: Doctor, round 1
                                               ├─ AgentRun: Doctor, round 2
                                               └─ LabInstance: ambiente attivo
                                                    └─ AgentRun: Recon (C3)
```

Il FK principale del Lab è `source_revision_id`. Il progetto e il proprietario si raggiungono dalla revisione. Per P0 non duplicare `project_id`/`user_id` sul Lab senza una necessità concreta.

Una revisione può avere più Lab, ad esempio configurazioni PHP/database differenti. Inizialmente basta poterne creare uno senza costruire gestione di profili multipli. Un Lab non segue automaticamente l'ultimo upload: una nuova revisione richiede un nuovo Lab.

La definizione è modificabile durante preparazione/diagnosi; dopo l'accettazione è congelata. Un cambio significativo di recipe, seed o configurazione produce un nuovo Lab. Si possono trasferire artifact come punto di partenza, ma il nuovo Lab deve essere verificato.

`Lab.status = ready` significa che la ricetta è stata verificata e riprodotta. `LabInstance.status = ready` significa che i servizi di QUELLA istanza sono avviati e controllati. Una istanza fermata/scaduta non invalida automaticamente la ricetta; una ricetta ready non autorizza Recon a usare un container inesistente.

## 3. Persistenza minima, introdotta progressivamente

### SourceRevision: conservare il percorso di import già costruito

Se lailaps-2 ha già un modello equivalente, usarlo. Altrimenti aggiungere qui il concetto di revisione, senza ricostruire il CRUD del progetto.

DB: `project_id`, stato import, hash del contenuto materializzato, riferimento privato allo snapshot, sottodirectory applicativa, provenienza/original filename, errore e data di import.

Filesystem/storage privato: snapshot sorgente immutabile. Lo ZIP può rimanere temporaneo: il vecchio materializer lo elimina dopo l'import. La riproducibilità dipende dalla conservazione dello snapshot, non dal tenere sia ZIP sia copia estratta. La copia usata per installare dipendenze/generare cache è distinta dalla vista sorgente analizzata.

### labs: definizione e stato del Doctor

| Gruppo | Dati necessari |
|---|---|
| Identità | `id`, `source_revision_id`, nome opzionale, `status` |
| Preparazione | summary narrativo, domande correnti, riferimento al round che le ha prodotte, risposte cifrate |
| Ricetta | configurazione validata JSON, riferimento agli artifact, manifest con hash, fingerprint |
| Verifica | risultati/provenance dei check, data di verifica e dell'accettazione di riproducibilità |
| Errori/lifecycle | motivo di blocco/fallimento, richiesta di cancellazione, timestamp |

Configurazione validata: servizi logici, servizio web, recipe/Compose/Dockerfile effettivi, bootstrap/seed, readiness e reset, riferimenti ai segreti. Gli artifact contengono il testo dei file; il DB contiene identità, hash e configurazione necessaria al controllo di flusso. Niente copia di container ID o porte correnti nella ricetta.

Le risposte segrete restano cifrate e fuori da SSE/log/output. Il modello riceve disponibilità o riferimenti; l'executor le inietta soltanto nei servizi/operazioni autorizzati. Evitare l'environment globale del worker Laravel.

Non serve una nuova entità `Preparation` soltanto perché esiste nel vecchio progetto: per il P0 nuovo, il Lab contiene lo stato durevole del Doctor e le sue singole invocazioni sono AgentRun.

### agent_runs: una esecuzione, non tutto il dialogo del Doctor

Un record per invocazione Doctor; poi la stessa struttura ospita Recon. DB: `lab_id`, eventuale `lab_instance_id`, ruolo, stato, input/checkpoint/output JSON, provider/modello/configurazione effettiva, versione runtime/prompt/tool, limiti, usage e tempi, errore, cancellazione, start/end.

Usare il modello Run già presente in lailaps-2 se sufficiente, evitando una seconda infrastruttura di esecuzione. Il nome `agent_runs` è concettuale.

Misure: chiamate iniziate/completate, token input/output/cache quando disponibili, EP con regola versionata, costo stimato separato, durata chiamate/tool/processo. Distinguere attesa queue, attesa utente, tempo Doctor e tempo provisioning. Usage mancante resta sconosciuto, non zero. I consumi del Doctor sono cumulativi sulle sue invocazioni: una risposta utente non azzera il budget del Lab. Non duplicare i totali su molte tabelle: inizialmente derivarli dalle run.

### agent_events: storia utile e feed recuperabile

DB: ID/cursore assegnato da Laravel, `agent_run_id`, ID evento del mittente, tipo, timestamp sorgente/ricezione, payload JSON. Vincolo unico su `(agent_run_id, sender_event_id)` per retry HTTP idempotenti.

Persistenza di eventi significativi: chiamata modello conclusa, messaggio/output disponibile, tool iniziato/concluso/errore con correlazione, checkpoint, errore tecnico e risultato finale. Eventi lifecycle del provisioning sono associati alla run/job che li esegue; lo stato corrente rimane su Lab/istanza.

Non inserire un record per ogni token. Aggregare il testo e salvare ai boundary; distinguere reasoning/testo/tool attraverso i tipi SDK quando presenti, senza inferirli dalla prosa. Reasoning assente non è un errore. Per risultati grandi: riferimento ad artifact privato con hash, durata/exit code, preview redatta e indicazione di troncamento. Non serve una tabella autonoma per ogni tool o modello in C2.

La UI riceve una vista selezionata degli eventi. I payload diagnostici privati e i segreti non entrano nel feed pubblico della pagina.

### lab_instances: una accensione concreta

Introdotta alla milestone 3. DB: `lab_id`, stato, fingerprint della ricetta usata, workspace/volume/network di proprietà dell'istanza, servizi logici e relativi riferimenti container, immagini realmente risolte, URL interno/raggiungibile dall'executor, capability con risultati dei check, start/stop/scadenza ed errore.

Questi dati si rivalidano contro il runtime: il record DB non prova che un container sia ancora vivo. In P0 una sola run usa una istanza alla volta; niente pool di ambienti condivisi.

## 4. Responsabilità e flusso

```text
Upload → SourceRevision pronta → crea Lab
                              ↓
                   Laravel accoda/supervisiona Doctor TS
                              ↓
        AI SDK + primitive → POST API interne → commit DB
                              ↓
              notifica → SSE → pagina Vue/Inertia
                              ↓
   ask: mostra form → POST risposte → nuova AgentRun Doctor
   prepare: Laravel valida ricetta → avvia/verifica LabInstance
   blocked: motivo persistito → intervento utente
```

Laravel valida autenticazione scoped, proprietà, esecuzione attiva e transizioni: una callback tardiva di un round precedente non sovrascrive quello nuovo. Consegne duplicate non producono doppie run, eventi o doppi consumi. Un retry della persistenza ripete lo stesso payload, non la chiamata al modello né un setup con effetti incerti.

Il job supervisiona il processo; il server HTTP Laravel resta disponibile per le callback. Una decisione `ask` persistita porta il Lab ad `awaiting_input`: exit 0 conclude la singola AgentRun, senza trasformare il Lab in ready. Fermare Doctor ferma anche i tool avviati e preserva artifact/ultimo checkpoint validi.

SSE: snapshot e cursore iniziali, notifiche dopo commit, recupero dal DB degli eventi successivi al cursore, autorizzazione per proprietario e riconnessione. Redis Pub/Sub può svegliare il gestore SSE; il recupero dei dati resta sul DB. Gestire la finestra tra sottoscrizione e replay e controllare il recupero al reconnect. Pub/Sub da solo non è un archivio durevole; le notifiche mancate richiedono riconciliazione con il DB. Non confondere l'assenza di polling nel browser con una garanzia di delivery del trasporto. Outbox distribuita è P1, salvo problemi concreti emersi dal gate.

## 5. Milestone e accettazione

### C2.1 — Identità: progetto → revisione → Lab

Consegna: dalla pagina della revisione pronta si crea un Lab draft. Esiste una vista sorgente immutabile e un'area privata separata per gli artifact del Doctor. Il Lab è ricaricabile da DB.

**Gate:** ZIP importato → revisione con hash → Lab legato esattamente a quella revisione; un secondo upload non cambia il primo Lab. Un altro utente non può accedere a snapshot/Lab/artifact. Una scrittura di preparazione non modifica lo snapshot.

**Verifica senza LLM:** feature test mirati di relazioni/proprietà, import errato e confine fra snapshot e workspace. Riutilizzare le verifiche upload già presenti nel nuovo progetto.

### C2.2 — Prima AgentRun TS: AI SDK, API interne, DB e SSE

Consegna: job Laravel avvia un'esecuzione TS con provider simulato AI SDK. Il modello simulato richiede una lettura reale del sorgente e produce un risultato; run, tool call, checkpoint, usage e tempi sono persistiti da Laravel e visibili via SSE.

Usare le primitive Core SDK prima di wrapper propri. Iniziare con una piccola funzione di loop e stop/budget ai boundary, modelli/provider fissati nel lockfile e AbortController. Il mock sostituisce il provider, non il tool né la persistenza. [Tool calling](https://ai-sdk.dev/docs/ai-sdk-core/tools-and-tool-calling), [Testing](https://ai-sdk.dev/docs/ai-sdk-core/testing).

**Gate:** pagina riaperta mostra stato/output corretti; replay dello stesso evento non duplica costi; callback da esecuzione estranea/scaduta rifiutata; errore DB non rilancia il modello. Cancel, timeout e crash sono distinguibili e conservano il checkpoint. Browser riconnesso recupera gli eventi persi; non occorre tenerlo aperto per proseguire il job.

**Verifica senza LLM:** mock SDK con tool reale, errore/ritardo simulati, test API/lifecycle/accounting, integrazione SSE con due eventi e disconnessione. Nessuno stream token-per-token richiesto.

### C2.3 — Prima LabInstance da ricetta fissa

Consegna: Laravel avvia una piccola applicazione fixture con DB da recipe nota, esegue bootstrap/readiness e consegna al runtime un executor limitato all'istanza. TS può leggere sorgente e invocare un comando nel servizio logico assegnato.

Separare control plane fidato, toolbox per comandi sul workspace e servizi target. Codice caricato/comandi generati girano nell'ambiente isolato; il processo Node con credenziali provider e il worker Laravel non sono il workspace eseguibile del target. I programmi generati non ricevono socket Docker o credenziali dell'app Lailaps.

**Gate:** fixture raggiungibile dal percorso reale dell'agente; comando nel servizio restituisce output/exit code/tempo; DB initialized e interrogabile dal progetto; teardown rimuove esclusivamente risorse dell'istanza. Un servizio appartenente a un altro Lab non è selezionabile.

**Verifica senza LLM:** smoke Docker/HTTP/exec e failure injection su startup/readiness/cancel. Questa milestone valida l'executor prima di far generare recipe al Doctor.

### C2.4 — Doctor minimo, domande e nuova invocazione

Consegna: Doctor usa lettura/ricerca sorgente e scrittura confinata agli artifact di ambiente. Preferisce setup/Compose esistenti. Restituisce un output piatto: `decision`, `reason`, `checkpoint_summary`, eventuali domande e pochi riferimenti di configurazione necessari a prepare.

IDs, ownership, fingerprint e capability provengono dall'orchestratore; nessun DTO Lab annidato nel prompt. Mantenere narrativa l'interpretazione dello stack e delle operazioni necessarie.

Flusso di test: `ask` → domanda salvata e mostrata SSE → processo chiuso → risposta cifrata/persistita → nuova AgentRun → `prepare` oppure un'altra domanda. Le risposte fanno riferimento al round/domande correnti, evitando form vecchi e doppia ripartenza. Summary/artifact precedenti e ultimo errore sono conservati; la nuova invocazione non ripete ciecamente setup già eseguiti.

**Gate:** il flusso sopravvive a refresh, chiusura browser e riavvio worker durante l'attesa; nessun processo rimane occupato mentre l'utente risponde; nessun segreto in prompt/log/SSE/artifact; usage cumulativo non si azzera. `blocked` mostra una motivazione utilizzabile.

**Verifica senza LLM:** sequenza di risposte mock AI SDK ask → prepare, un secondo ask, risposta duplicata/obsoleta, cancel durante attesa. Poi una breve prova live Doctor, soltanto su richiesta esplicita, con budget fissato.

### C2.5 — Preparazione verificata, repair e riproducibilità

Consegna: la recipe proposta è validata/applicata da Laravel; build/start/seed/readiness eseguiti nell'ambiente isolato. In caso di errore utile il Doctor riceve diagnostica redatta e propone una correzione entro limiti cumulativi. Il modello decide se chiedere, correggere o dichiarare blocked; deadline e autorizzazione restano vincoli tecnici.

**Ready richiede osservazioni reali:** app funzionante oltre una generica 200, setup concluso, stato seed atteso, comandi nel target disponibili; DB verificato se necessario al progetto. Browser/login sono capability opzionali per questo gate: non dichiararle verificate senza probe.

Congelare snapshot hash, recipe/artifact hash, configurazione non segreta, versioni dei riferimenti segreti, immagini risolte disponibili e versione dell'executor. Evitare fingerprint su segreti in chiaro. Il manifest dichiara le dipendenze esterne/residui non riproducibili.

**Gate:** due accensioni da workspace e volumi nuovi, entrambe con bootstrap completo, producono gli stessi invarianti applicativi senza nuova chiamata Doctor. Un dato scritto nella prima istanza non compare nella seconda; i seed previsti sì. La sorgente immutabile conserva il suo hash. La recipe resta utilizzabile dopo il riavvio di Laravel/Node.

Le immagini risolte devono essere effettivamente conservate o recuperabili: registrare un digest di un'immagine poi rimossa non è sufficiente. Il gate dimostra riproducibilità operativa dell'ambiente verificato, non identità byte per byte di qualsiasi rebuild futuro con dipendenze esterne mobili.

**Verifica senza LLM:** ricetta fixture e diagnostica mock per repair; due cold boot, reset e controlli dello stato. **Accettazione live separata:** Doctor porta una revisione reale di YesWiki e una di BookStack alla stessa condizione. Usare lo snapshot esatto di C0 se disponibile; altrimenti dichiarare la differenza, senza fingere la riproducibilità della vecchia run.

### C2.6 — Handoff verso la prima Recon

Consegna: il bottone Avvia Recon crea una run legata al Lab/revisione, avvia una nuova LabInstance dalla recipe congelata, rivalida readiness e prepara il contesto interno di Recon.

Il contesto interno comprende run/lab/instance ID, source root immutabile, artifact refs, viste redatte di servizi/capability, config modello/budget e accesso all'executor. Il modello riceve un briefing semplice e primitive; container ID, credenziali e DTO di provisioning rimangono interni.

**Gate:** il punto di ingresso Recon riceve la revisione corretta e un'istanza realmente pronta; un mock Recon legge un file, esegue un comando osservativo nel target e persiste l'ack/checkpoint attraverso le stesse API. Lab non ready, istanza scaduta/fallita e fingerprint incompatibile impediscono la chiamata modello. Stop/failure puliscono l'istanza conservando la ricetta.

**Verifica senza LLM:** test del launch/handoff e smoke delle capability sulla fixture. La prima chiamata Recon reale, se richiesta, usa questi stessi contratti; produrre briefing/assignment persistiti e confrontarli con Python è il primo lavoro C3, non un gate da simulare come già superato.

## 6. Riferimenti al vecchio Lailaps e al DS Harness

Riferimenti di comportamento, senza obbligo di riuso del codice:

- `SourceRevisionMaterializer`: estrazione controllata, snapshot con hash e ZIP temporaneo.
- `Preparation`, `RunPreparation`, `doctor.py`: domande/risposte durevoli e una nuova invocazione Doctor con summary/answers/artifact/runtime error.
- `RunPreparation::verifyEnvironment`: già verifica due avvii, ma chiude i container. Il nuovo handoff distingue ricetta accettata e istanza attiva. Il vecchio booleano capability va sostituito con evidenza del check realmente eseguito.
- `SandboxPreparationService`: distingue bootstrap, readiness e controllo DB.
- ARCHITECTURE.md: Recon/Reader nel percorso recente hanno comandi workspace e target quando il target è collegato; non assumere che Recon sia sempre solo statica.

DS Harness verificato al commit `d743267388641bc76f17c45ce8b4c231aed1d32c`: [session implementation](https://github.com/deepseek-ai/deepseek-harness/blob/d743267388641bc76f17c45ce8b4c231aed1d32c/packages/core/session/src/index.ts) separa log/eventi dalla conversazione derivata e prevede un checkpoint di durabilità atteso. Trasferimento minimo: diagnostica persistita distinta dal contesto del Doctor, ACK delle scritture critiche atteso prima di procedere. Non importare il framework event-sourced/plugin di DS.

Il [tool ask-user](https://github.com/deepseek-ai/deepseek-harness/blob/d743267388641bc76f17c45ce8b4c231aed1d32c/packages/interaction/tool-ask-user/src/index.ts) supporta un'attesa nel loop; il [README](https://github.com/deepseek-ai/deepseek-harness/blob/d743267388641bc76f17c45ce8b4c231aed1d32c/packages/interaction/tool-ask-user/README.md) descrive anche la modalità timed. Per il Doctor adottiamo la correlazione domanda/risposta, ma manteniamo la decisione finale ask e il riavvio concordati. Nessun resume generico da implementare per uniformarsi al riferimento.

## 7. Semplificazioni P0 e P1 successivi

P0: quattro concetti persistenti oltre a progetto/revisione — Lab, LabInstance, AgentRun ed eventi. Le tabelle si introducono quando serve la milestone, non tutte prima della prima lettura SDK. Nessuna tabella Preparation aggiuntiva, infrastruttura di plugin, queue TS, writer DB TS o parsing di stdout come protocollo.

P1/C6-C7: URL/ibrido, bootstrap/login multiattore, browser readiness, più configurazioni Lab nella UI, pool/concorrenza, immagini esportate per conservazione di lungo periodo, snapshot database avanzati, provider VM remoto, outbox se necessaria e compaction per dialoghi Doctor estesi. RAG e resume completo non sono prerequisiti C2.

Nessun codice runtime o test è stato implementato/eseguito da questo piano. Tutte le prove con provider reale e i benchmark restano attività esplicite di accettazione, non comandi automatici durante il rewrite.
