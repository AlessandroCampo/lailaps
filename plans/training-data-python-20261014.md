# Dati di training dalle run di Lailaps Python

Data: 14 ottobre 2026. Stato: proposta, senza implementazione.
Perimetro: repository corrente, `agent/pentest-agent` con Pydantic AI e preparazione Laravel. Escluso il rewrite Lailaps 2.

## Decisione proposta

Ogni run deve poter contribuire a un archivio di esperienza; soltanto i contenuti autorizzati e gli esempi selezionati diventano dati di training. Il dato principale è la traiettoria: contesto disponibile, risposta del modello, azioni, osservazioni, decisioni e risultati verificati. Il report finale è uno dei suoi prodotti.

La soluzione minima consigliata è:

- cattura comune nel runtime Python, indipendente dall'avvio CLI/UI/benchmark;
- tracce sanificate e allegati in storage separato, prima filesystem e poi object storage;
- un piccolo database separato per catalogo, etichette e provenienza;
- esportazioni offline e versionate per SFT, preferenze e valutazione;
- adapter LoRA/QLoRA su un modello esistente, iniziando da un ruolo;
- reinforcement learning interattivo successivamente, su sandbox riproducibili.

Non occorrono ora un nuovo agente, un event bus, un vector DB, una piattaforma ML o un trainer collegato alle run degli utenti. La cattura non deve cambiare prompt, budget, decisioni o contratti model-facing.

## 1. Cosa esiste già e cosa manca

Nel codice corrente:

| Componente | Dato già disponibile | Limite per il training |
| --- | --- | --- |
| `ArtifactStore` e `RunStorage` | Outcome aggiornato atomicamente, transcript, telemetria, note e prodotti persistiti | Lo stato finale non ricostruisce tutti i contesti intermedi |
| `BenchmarkStageArtifact` | Payload strutturati, parent, provenance, usage e metriche | Boundary utili, senza history completa |
| `_stream_role` e conversazioni in `triple_agent.py` | Messaggi, risposte, chiamate, retry e stato conversazionale | Principalmente in memoria; console con rappresentazioni abbreviate |
| `ToolOutputManager` | Output acquisito, preview, stato di consegna, riferimenti e scope | LRU RAM da 32 MiB; acquisizione e contesto presentato sono differenti |
| `model_trace.py` | Codice preesistente per osservare request/response provider | `append_trace()` è attualmente un no-op, anche con il flag debug |
| `run_events.py` | Call site che documentano milestone | `emit_event()` è attualmente un no-op |
| Oracle/evaluator benchmark | Esiti e alcuni controlli sulle evidenze | Qualità della supervisione variabile; non equivalgono tutti a ground truth indipendente |

La sezione iniziale di `ARCHITECTURE.md` descrive il contratto a due file e la RAM bounded. Il riferimento ai vecchi eventi console va interpretato alla luce del codice attuale, dove il relativo hook è disattivato.

I log storici restano utili per recuperare finding, esiti e boundary. Non prometterei di trasformarli retroattivamente in traiettorie esatte quando input o tool result non sono stati conservati. Gli import devono dichiarare `capture_quality=legacy_partial` e le relative limitazioni.

## 2. Unità da conservare

La run è il contenitore. L'unità di apprendimento è normalmente un turno o un episodio di un ruolo su un incarico: area Reader, lead Confirmer, candidate Worker, blocco deduper. Questo permette di addestrare un ruolo mantenendo gli altri invariati.

### Lead normalizzata e dossier investigativo

Decisione concordata: conservare nel DB training anche la lead su cui si svolge l'indagine, con tutte le informazioni già disponibili e autorizzate. La lead collega ipotesi, traiettoria ed esito; non è sufficiente un ID che rimandi a un finding finale mutabile nel DB operativo.

Per ogni lead raccogliere:

- identità qualificata per run/scope/pass, origine Reader o fixture e riferimento al prodotto originale;
- titolo, ipotesi e narrativa, categoria/weakness/CWE quando presenti, operazione sospetta, asset e confine di sicurezza;
- posizioni e simboli sorgente, input controllabile, source/propagation/sink, raggiungibilità e invariante di sicurezza quando disponibili;
- evidenze iniziali, unknowns, prerequisiti, attori/privilegi, route e piano di verifica con segnali positivo/negativo;
- checkpoint, handoff, enrichment, osservazioni statiche/dinamiche, note pertinenti e impedimenti;
- evoluzione di stato, motivazioni, esito, riferimenti a finding e a evidenze HTTP/browser/source;
- attribuzione ai ruoli/modelli, timestamp e provenienza di ciascun aggiornamento;
- relazioni di deduplica, canonicalizzazione e parent/resume già stabilite dall'orchestratore.

Questa è una proiezione dello stato autorevole e dei suoi riferimenti, non una nuova analisi LLM durante la raccolta. Un campo sconosciuto resta sconosciuto; non si inferiscono dettagli per completare una scheda. La normalizzazione riguarda identità, percorsi, alias e relazioni ricostruibili; le ipotesi e spiegazioni restano narrative. Non si uniscono automaticamente lead di run diverse perché sembrano descrivere la stessa vulnerabilità.

Conservare la proposta originale e revisioni immutabili della scheda ai boundary che cambiano il dossier: creazione, enrichment/checkpoint pertinente, handoff, chiusura e verdetto terminale. `training_leads` può indicizzare lo stato più recente, ma ogni episodio/label punta alla revisione e all'intervallo di traccia valutati. I contenuti voluminosi restano allegati referenziati e persistiti nel training storage, anche quando gli artifact operativi o il target temporaneo vengono eliminati. La traccia registra le osservazioni intermedie senza creare una revisione SQL per ogni tool call.

Questo preserva, per esempio, l'ipotesi iniziale Reader prima che `LeadLedger.promote()` ne aggiorni descrizione e source refs, e il piano Confirmer prima del verdetto Worker. La revisione finale non deve sostituire il contesto disponibile ai turni precedenti. Anche le proposte scartate prima della creazione della lead restano nella traccia con il prodotto d'origine, senza inventare un'identità autorevole mai assegnata.

### Identità e configurazione della run

Conservare:

- ID globale di raccolta e `run_id` operativo, provenienza test/benchmark/utente, parent e resume;
- identificatore pseudonimo del progetto e fingerprint dello snapshot realmente auditato;
- commit del motore, stato del checkout/image digest quando il commit non identifica tutto il codice, versione dipendenze, configurazione effettiva;
- modelli richiesti e serviti quando osservabili, provider, impostazioni di generazione, prompt e schemi effettivi;
- disponibilità del runtime, stato terminale e causa di interruzione;
- policy di raccolta e ammissibilità all'uso dei dati.

Il run ID leggibile non va assunto unico tra installazioni. Il fingerprint dello snapshot non contiene automaticamente il sorgente: conservare un hash non permette di ricostruirlo.

### Per ogni chiamata al modello

1. Contesto effettivamente passato al modello dopo i processor di history: istruzioni, messaggi ordinati, risultati tool consegnati, tool disponibili e schema di output.
2. Risposta osservabile prima delle riparazioni e della normalizzazione dell'orchestratore: testo, tool call, output strutturato, finish reason e stato completo/parziale.
3. Collegamento a conversazione, ruolo operativo, modalità/phase, scope, request, tentativo ed epoch di compaction.
4. Usage e tempi disponibili; assenza di un campo resta assenza, non uno zero inventato.
5. Eventuale retry con il feedback realmente mostrato al modello.

Il contesto effettivo va catturato al confine del modello; `all_messages()` a fine run, da solo, non garantisce di ricostruire ogni versione di history processata. Per la copia persistita si applica la sanificazione: essa deve preservare ruoli, struttura e riferimenti e dichiarare la trasformazione applicata.

Per il P0 si possono salvare snapshot completi per request e comprimere la traccia. È più semplice e meno ambiguo di una nuova infrastruttura di deduplicazione. Se il volume lo richiede, P1 può sostituire i prefissi ripetuti con riferimenti a contenuti immutabili.

### Per ogni azione e osservazione

Conservare nome del tool, argomenti sanificati, call ID, stato, risultato acquisito e risultato realmente consegnato. Registrare errori, timeout, troncamenti e acquisizioni incomplete esplicitamente.

Gli output acquisiti più grandi possono essere allegati, entro limiti di raccolta dichiarati. Un risultato acquisito ma mai mostrato non deve entrare nel prompt del relativo esempio. La distinzione è necessaria anche quando il modello ha recuperato solo una finestra con `inspect_tool_output`.

Per HTTP/browser/source servono riferimenti stabili, identità logiche degli actor, osservazioni e finestre decisive sanificate. Screenshot effettivamente usati come input richiedono anche l'allegato immagine per esempi multimodali; se mancante, l'esempio non è completo per quel training. Non serve conservare ogni screenshot o tutto il traffico di rete.

Per tool composti come `run_code`, distinguere azione scelta dal modello e subcall eseguite dal broker: non trasformare una subcall in una scelta diretta che il modello non ha fatto.

### Decisioni, normalizzazione ed esiti

Conservare separatamente:

- proposta originale del modello;
- decisione applicata e normalizzazione dell'orchestratore;
- evidenze e collegamento a lead/finding;
- eventuale valutazione indipendente e relativa versione.

Non addestrare implicitamente il modello a produrre ID o DTO che oggi vengono aggiunti dal runtime. Il recorder normalizza, collega e persiste senza chiedere al modello nuovi campi. La narrativa semantica resta testo libero.

La compaction va registrata come transizione: input del summarizer, summary risultante e nuovo contesto operativo. Le informazioni eliminate restano nella traccia storica, ma non vengono attribuite al contesto dei turni successivi.

Le chiamate di summarizer, deduper ed evaluator vanno catturate con un `purpose` distinto. Il loro output non diventa automaticamente supervisione corretta. Non è necessario raccogliere reasoning nascosto: si possono usare solo contenuti disponibili e ammessi; il primo dataset può concentrarsi su azioni, spiegazioni esplicite e decisioni finali.

## 3. Struttura di storage

Flusso proposto:

```text
Run Python
  -> copia sanificata dei boundary e degli allegati
  -> spool locale append-only fuori dagli artifact operativi
  -> import idempotente / upload
  -> storage training + catalogo DB
  -> selezione, valutazione, deduplicazione e split offline
  -> dataset versionato
  -> training adapter ed evaluation
```

### Storage dei contenuti

P0: directory dedicata, senza cambiare il contratto dei due file operativi. Migrazione a bucket S3-compatible quando occorre conservazione centralizzata o più worker. Stesso formato logico:

```text
training/runs/{collection_id}/manifest.json
training/runs/{collection_id}/trace.jsonl.gz
training/runs/{collection_id}/attachments/{artifact_id}
training/datasets/{dataset_version}/manifest.json
training/datasets/{dataset_version}/train.parquet
training/datasets/{dataset_version}/validation.parquet
training/datasets/{dataset_version}/test.parquet
```

Durante la run lo spool è JSONL non compresso; al sealing diventa immutabile e può essere compresso. Le parti già raccolte sopravvivono a una run interrotta. Le valutazioni successive non riscrivono la traccia.

JSONL è adatto all'acquisizione incrementale e all'ispezione. Parquet è una scelta per esportazioni compatte interrogabili; JSONL può essere prodotto quando richiesto dal trainer. Conservare il formato canonico dei messaggi, evitando di vincolare la raccolta al tokenizer o al chat template di un futuro modello.

L'event envelope contiene soltanto identità e collegamenti necessari; il payload può contenere i messaggi standard e la narrativa. Esempio concettuale di turno, non DTO da esporre al modello:

```json
{
  "schema_version": 1,
  "event_id": "ev-0021",
  "conversation_id": "conv-03",
  "request_id": "req-07",
  "purpose": "investigation",
  "role": "worker",
  "scope_id": "lead-04",
  "epoch": 2,
  "context_ref": "ctx-07",
  "response_ref": "response-07",
  "status": "complete"
}
```

Nel caso P0 i contenuti referenziati sono record della stessa traccia; un contesto comprende anche tool e output schema. Ordine per conversazione e call ID prevalgono sull'ordine di completamento globale nei flussi concorrenti. Gli ID tecnici sono assegnati dal runtime.

### Database separato

Consiglio una separazione logica dal DB operativo: connessione, credenziali e backup distinti. Un server separato può aspettare esigenze di carico o isolamento. Per il catalogo multiutente PostgreSQL è una scelta ragionevole; nel prototipo basta anche un SQLite dedicato. Non serve cambiare il database applicativo.

Il catalogo P0 include cinque tabelle, aggiungendo lead e revisioni alla proposta iniziale:

| Tabella | Contenuto essenziale |
| --- | --- |
| `training_runs` | collection ID, run ID operativo, project fingerprint, origine, versioni/configuration ref, policy ref, manifest URI, capture status, timestamp |
| `training_artifacts` | artifact ID, collection ID, tipo, URI, hash, dimensione, completezza e sanificazione |
| `training_leads` | training lead ID, collection ID, identità operativa qualificata, origine, categoria, stato più recente e current revision ID |
| `training_lead_revisions` | revision ID, training lead ID, evento/posizione nella traccia, timestamp, schema version, payload normalizzato completo e riferimenti persistenti agli allegati |
| `training_labels` | soggetto/revisione/intervallo valutati, dimensione, valore, motivazione, autore/tipo di valutatore, versione, evidence refs, eventuale label precedente revisionata e timestamp |

La scheda completa può essere un payload JSON/JSONB nella revisione; si promuovono a colonne soltanto identità e campi necessari alle query. Nessuna tabella per ogni dettaglio narrativo e nessuna nuova tassonomia obbligatoria per dati oggi assenti. Il dato originale sanificato rimane referenziato nella traccia, così la normalizzazione è verificabile.

Una label può riguardare run, episodio, turno, finding o coppia di alternative. Per una preferenza indica entrambe le alternative e il contesto comune. Correzioni e nuove valutazioni mantengono la storia; il dataset congela quali revisioni ha usato.

Le membership del dataset possono stare nel manifest/export: non occorre una tabella per ogni messaggio o token. Il DB risponde a «quali run e label voglio selezionare?», lo storage conserva i contenuti.

Ogni dataset deve dichiarare selezione, esempi e source refs, versioni di label/exporter/sanitizer, split, deduplica, schema e checksum. L'adapter registra a sua volta base model/revision, tokenizer/template, dataset, recipe e risultati di evaluation.

### Gestione dei guasti

La raccolta non effettua altre chiamate LLM e non rende la disponibilità del DB un prerequisito di ogni token/tool. Scrive localmente e importa fuori dal percorso delle richieste, riusando il lifecycle esistente quando possibile. Il finalizer pubblica un manifest sealed o partial; resume/import sono idempotenti.

Disco pieno o recorder fallito devono lasciare una diagnosi e marcare la raccolta incompleta; l'audit può continuare. La perdita di training data non deve essere silenziosa né cambiare il verdetto tecnico del finding. Per il P0 basta registrare cosa manca, senza costruire un sistema di code distribuite.

## 4. Supervisione: raccogliere tutto non significa imparare da tutto

Non assegnerei un unico reward positivo a ogni turno di una run che contiene un finding confermato. Una conferma può seguire molti errori; una run senza finding può avere svolto una lettura corretta, oppure aver mancato la vulnerabilità.

Distinguere almeno:

- stato operativo: completa, budget esaurito, cancellata, errore provider/runtime;
- verdetto dell'audit: sospetto, confermato, respinto, bloccato/inconclusivo;
- verifica indipendente: risultato supportato, contraddetto o ancora sconosciuto.

Per il training, la qualità dovrebbe essere valutata su dimensioni: correttezza della decisione, supporto nelle evidenze, corretto uso dei tool, qualità dell'handoff e gestione appropriata dell'incertezza. Efficienza e costo sono secondari rispetto alla correttezza.

Ordine di fiducia consigliato: benchmark con ground truth revisionata e oracle indipendente; revisione umana; evidenza runtime verificata; giudizio di un altro modello; dichiarazione dello stesso agente. Conservare sempre la provenienza. Un HTTP 200 o il conteggio dei riferimenti citati non dimostra da solo l'impatto della vulnerabilità.

Gli oracle attuali sono basi riutilizzabili, ma vanno valutati prima di promuoverli a reward di training: controlli testuali o strutturali possono essere incompleti o ottimizzabili senza una vera conferma. L'LLM può interpretare il valore semantico di un test, mentre il runtime conserva i fatti verificabili. Un reward LLM non deve essere l'unico criterio per assegnare una conferma.

Una closure senza evidenza positiva non diventa un esempio negativo certo. Un blocco ambientale non diventa «nessuna vulnerabilità». I fallimenti sono utili come alternative rifiutate, casi di recovery o materiale da correggere; non come target positivo indiscriminato.

### Labelling offline: LLM frontier e revisione umana

La raccolta P0 conserva i dati e lascia le label semantiche non assegnate. Il labelling successivo combina un LLM frontier come primo annotatore e una revisione umana; non introduce richieste di valutazione aggiuntive nel loop investigativo.

1. Preparare un episodio su una lead: revisioni, incarico, contesti, azioni, osservazioni ed esito. Allegare ground truth/oracle soltanto quando disponibili e con provenienza distinta dalle affermazioni degli agenti.
2. Il modello frontier propone un giudizio sull'episodio: corretto e supportato, errato/non supportato, inconclusivo o non valutabile. Motiva citando eventi ed evidenze e segnala le decisioni particolarmente utili o problematiche. Non deve etichettare ogni azione per forza.
3. Un revisore umano può accettare, correggere o lasciare irrisolto il giudizio, consultando le evidenze originali sanificate. Conservare entrambe le annotazioni: la revisione umana non sovrascrive la proposta LLM. Una correzione della risposta da usare per training è un prodotto separato dalla label.
4. L'export seleziona esempi secondo una policy versionata: per il primo corpus gold richiedere revisione umana degli esempi inclusi. Le annotazioni soltanto LLM rimangono identificate come tali; un eventuale uso successivo richiede calibrazione su campioni revisionati e una policy esplicita.

Per un turno, il giudizio sull'appropriatezza della scelta considera solo le informazioni disponibili al momento; la valutazione dell'esito può usare anche gli eventi successivi. Le due valutazioni non devono confondersi né introdurre osservazioni future nel prompt esportato. Un test HTTP che riceve 403 può essere un'azione appropriata pur non confermando la vulnerabilità.

Ogni annotazione registra soggetto/revisione, rubric version, giudizio, reason narrativa ed evidence refs; per il modello registra anche modello/provider e prompt di valutazione, per l'umano identità del revisore e collegamento al giudizio revisionato. Non serve un punteggio di confidence per decidere automaticamente quali esempi sono corretti. L'assenza di label e il verdetto inconclusivo sono stati distinti.

L'annotatore legge contenuti target non fidati, come il resto della pipeline: la scheda e la traccia sono dati da valutare, non istruzioni autorevoli. La qualità di una conferma non deriva semplicemente dal fatto che il ledger la chiama `confirmed`.

## 5. Come usare gli stessi dati per diversi training

Adapter e obiettivo di training sono scelte diverse: LoRA/QLoRA indica quali parametri addestrare; SFT, DPO o RL indica da quale segnale imparare. Non si deve scegliere oggi un unico formato irreversibile.

| Metodo | Esempi necessari | Applicazione a Lailaps | Priorità |
| --- | --- | --- | --- |
| SFT / imitation learning | Contesto e continuazione corretta, tool schema e risultati pertinenti | Tool calling, lettura sorgente, decisioni motivate, handoff e conferme documentate | Primo esperimento |
| Distillazione dalle risposte API | Esempi selezionati del teacher, eventualmente corretti | Trasferire procedure di modelli forti a un modello accessibile con adapter | Insieme allo SFT |
| DPO | Stesso contesto e due continuazioni, preferita e rifiutata | Evidenza corretta contro conclusione non supportata; test discriminante contro test inconcludente | Dopo un dataset SFT affidabile |
| KTO | Contesto, risposta ed etichetta desiderabile/non desiderabile | Utile se si hanno giudizi puntuali senza alternative confrontabili | Alternativa al DPO |
| Reward model / verifier | Preferenze o giudizi con evidenze | Valutare supporto di un finding o ordinare continuazioni | Opzionale |
| RL interattivo, per esempio GRPO/PPO | Task riproducibile, nuove azioni del modello, tool/environment, reward | Migliorare la scelta dei test e le sequenze investigative | P2 |
| Continued pretraining su dominio | Corpus di codice/testo autorizzato e pulito | Conoscenze generali di cybersecurity | P2, meno diretto per i flussi specifici |

### SFT con adapter

Esportare messaggi conversazionali e tool schema, oppure `prompt` e `completion`. Per il primo esperimento preferire contesto -> prossima risposta corretta, entro la finestra del modello; gli episodi completi possono seguire se compatibili.

Applicare la loss ai target assistant selezionati, non indiscriminatamente a user/system/tool o a tutti i tentativi errati. Tool result e codice sono contesto; il modello impara a scegliere chiamate e a produrre decisioni. TRL documenta training conversazionale con tool calling e integrazione PEFT: [SFTTrainer](https://huggingface.co/docs/trl/sft_trainer).

La distillazione mediante SFT delle risposte non richiede logits del teacher. Non equivale a ricostruirne il ragionamento nascosto. Riutilizzabilità degli output e delle licenze va verificata per le fonti impiegate.

LoRA aggiunge parametri addestrabili alla base; QLoRA consente di addestrare adapter attraverso una base congelata e quantizzata. Il riferimento originale descrive questa impostazione: [QLoRA](https://arxiv.org/abs/2305.14314). L'adapter resta legato alla base e alla sua architettura/revisione; non è un file universale applicabile a qualunque modello.

### Preferenze

Per DPO non basta confrontare due run sullo stesso progetto: il contesto e le risorse disponibili al punto di scelta devono essere confrontabili. Si possono creare alternative con una correzione umana o generazioni offline dallo stesso checkpoint. Conservare l'origine della correzione e non attribuire alla risposta informazioni che il suo contesto non conteneva.

Il formato è `prompt`, `chosen`, `rejected`; non è obbligatorio un reward model separato: [DPOTrainer](https://huggingface.co/docs/trl/dpo_trainer). Se le preferenze appaiate sono scarse ma si hanno giudizi affidabili su risposte singole, KTO usa un segnale binario: [KTOTrainer](https://huggingface.co/docs/trl/kto_trainer).

### Reinforcement learning

Le run API storiche sono traiettorie generate da altre policy. Sono utili per dimostrazioni, preferenze, sviluppo di verifier e scelta dei task; non sostituiscono automaticamente i nuovi rollout richiesti dal normale training on-policy del modello da adattare.

Per RL di un agente multi-turn servono un ambiente che esegua realmente i tool, reset e isolamento, e un reward che valuti il risultato osservato. TRL documenta tool, environment e rollout per GRPO; l'integrazione precisa dovrà essere verificata e fissata a una versione quando si implementerà: [GRPOTrainer](https://huggingface.co/docs/trl/grpo_trainer).

Le fixture riutilizzabili devono specificare snapshot sorgente, immagini/runtime, seed e reset, attori/privilegi iniziali, incarico, tool e versione dell'oracle. Il segreto operativo viene rigenerato, non recuperato dalla traccia di un utente. Un sito o uno zip ormai eliminato non diventa un ambiente RL soltanto perché ne abbiamo conservato un report.

Non occorre acquisire logits/logprob dei teacher nel P0. Quando arriverà un algoritmo RL che ne ha bisogno, andranno prodotti i dati di policy richiesti dai rollout del modello che si sta addestrando; probabilità mancanti o di un altro modello non sono sostituti equivalenti.

Il primo reward dovrebbe premiare conferma supportata o rifiuto corretto, penalizzare affermazioni contraddette e rispettare i confini autorizzati. Solo dopo può premiare efficienza. Nessun bonus diretto per numero di finding, volume di HTTP o lunghezza del reasoning.

## 6. Consenso, sanificazione e integrità del dataset

Per le run utente distinguere autorizzazione all'audit e autorizzazione alla raccolta/uso per training. In assenza di quest'ultima, conservare per questo archivio soltanto metadati non contenutistici consentiti; non accumulare sorgenti o conversazioni «per decidere dopo». Le run interne seguono una policy esplicita e le licenze dei target.

La policy deve distinguere uso di snippet e conversazioni dall'eventuale conservazione integrale del progetto/runtime per replay. Il P0 non richiede di conservare ogni zip caricato.

Prima della scrittura training:

- rimuovere credenziali provider, token, cookie/session value e segreti;
- sanificare dati personali e dati applicativi sensibili in argomenti, risultati, prompt, report e immagini;
- usare placeholder coerenti nell'episodio: `actor-A`, `resource-A`, `secret-A`;
- preservare le relazioni tecniche utili, inclusi privilegi e proprietari delle risorse;
- escludere o revisionare casi in cui la sanificazione distrugge il significato del test.

Il contenuto realmente osservato e la copia training sanificata sono distinti. P0 conserva la seconda con la relativa versione del sanitizer; eventuali originali richiederebbero una policy separata. La sanificazione riduce il rischio, non garantisce automaticamente anonimato o assenza di codice proprietario.

Accessi e chiavi separati, retention esplicita, propagazione di cancellazione/revoca a esportazioni e tracciabilità degli adapter già addestrati sono requisiti della raccolta multiutente. Cancellare una riga non elimina automaticamente l'informazione da un adapter: in quei casi serve decidere ritiro/retraining, senza promettere un unlearning che non abbiamo.

Codice target, HTTP e commenti sono contenuti non fidati: non promuoverli a istruzioni system durante l'esportazione. Per le run utente, nessuna dichiarazione «questo finding è corretto» deve conferire automaticamente qualità gold. Il dataset ufficiale proviene da selezione/revisione versionata.

## 7. Evaluation e split

Dividere train/validation/test per famiglia di progetto e derivazioni, includendo fork, versioni vicine, stessa vulnerabilità/patch e fixture provenienti dalla medesima run. Una divisione casuale per turno produrrebbe facilmente leakage.

La ground truth e le annotazioni del benchmark restano fuori dagli input dell'agente. Un target pubblico può essere già noto alla base: oltre ai benchmark esistenti occorre, in P1, una parte di evaluation privata o con varianti curate. Un hash diverso non garantisce indipendenza semantica.

Valutare base contro adapter con stesso incarico, tool e condizioni di budget; registrare variabilità delle run quando si svolgono valutazioni live. Metriche utili:

- correttezza di lead e decisioni, precisione dei finding ed evidenza delle conferme;
- recall solo dove la ground truth è sufficientemente completa;
- distinzione tra sospetto, confermato, respinto e bloccato;
- uso valido dei tool, recovery e qualità degli handoff;
- token, costo e durata, dopo il confronto di qualità;
- tenuta su stack e autenticazioni differenti e regressioni fuori dal ruolo allenato.

Non esiste una soglia universale di numero di run che garantisca un adapter utile. Contano esempi revisionati, varietà e risultato sui progetti esclusi dal training. Iniziare da un dataset piccolo e controllabile e misurare la curva di miglioramento.

## 8. Piano incrementale

### P0 — raccolta utile subito, senza training live

1. Definire policy e manifest v1: identità, versioni, completezza e ammissibilità. Run interne abilitate; contenuti utente subordinati alla policy esplicita.
2. Inserire una cattura comune delle chiamate nel punto condiviso da `make_model`, con scope propagato dal runtime. Coprire sia streaming operativo sia chiamate non streaming di deduper, evaluator e compaction, anche quando viene fornito un client HTTP personalizzato. Usare il confine model request per il contesto processato; la factory del client HTTP da sola non basta.
3. Collegare azioni/output acquisiti in `ToolOutputManager` e i boundary/handoff esistenti. Conservare separatamente risposta originale e trasformazione applicata; registrare tool fuori dal manager e subcall dei tool composti attraverso i loro riferimenti esistenti. Proiettare il dossier della lead dal ledger e dai prodotti autorevoli, salvando le revisioni dei boundary senza perdere lo stato iniziale.
4. Scrivere spool sanificato separato, seal/partial e import idempotente, senza dipendenze remote per ogni tool call.
5. Catalogare run, lead/revisioni e allegati; predisporre le label separate e un export revisionabile, senza assegnare automaticamente giudizi semantici. Riutilizzare artifact e metriche benchmark disponibili con provenance. L'export destinato a training positivo richiede la successiva selezione/annotazione.
6. Aggiornare `ARCHITECTURE.md` quando si implementa la nuova persistenza; questa proposta non descrive ancora un comportamento esistente.

Il wrapper del modello è una possibilità nativa documentata da Pydantic AI: [WrapperModel](https://ai.pydantic.dev/api/models/wrapper/). Va verificata l'API della versione installata prima di scegliere l'hook preciso. Non propongo di riattivare semplicemente il vecchio debug wire: non copre da solo scope, label, consenso e differenza tra output originale e normalizzato.

Criteri di accettazione P0: ricostruire un turno dopo compaction; associare ogni tool call al suo risultato senza collisioni fra ruoli concorrenti; distinguere preview e output completo; recuperare ipotesi iniziale, handoff ed esito della lead come revisioni distinte; conservare un retry e una run interrotta senza promuoverli a target corretti; importare due volte senza duplicare; escludere contenuti non autorizzati; non modificare prompt, output model-facing o budget.

Le verifiche di implementazione possono usare messaggi salvati e modelli/tool finti: streaming, compaction, redazione, idempotenza, partial e split. Nessun loop agentico con API deve essere lanciato autonomamente. La comparazione live base/adapter resta un'attività futura con budget esplicito.

### P1 — labelling ibrido, primo adapter e preferenze

Avviare piccoli batch di annotazione LLM frontier e revisione umana per costruire il primo corpus gold. Usare rubric ed esempi revisionati per verificare coerenza del labelling, costo e utilità; non assumere che la disponibilità di un modello frontier elimini la necessità di ground truth o revisione. Un'interfaccia dedicata può aspettare: inizialmente bastano schede/export con riferimenti alle evidenze e annotazioni versionate.

Scegliere un solo ruolo in base al corpus gold disponibile e al comportamento da migliorare. Confirmer è un buon candidato se le decisioni statiche e gli handoff sono revisionati; un deduper può essere un esperimento ancora più piccolo se i suoi giudizi hanno supervisione migliore. Non assumere che una label gold dell'evaluator sia corretta senza verifica.

Preparare SFT con LoRA, oppure QLoRA secondo hardware/base scelti; adattare messaggi e tool al chat template della base senza inserire osservazioni future. Provare l'adapter sul solo ruolo e mantenere la possibilità di tornare alla base. Successivamente creare alternative confrontabili per DPO/KTO.

Aggiungere storage remoto e deduplica dei prefissi solo quando distribuzione o volume li richiedono. Lo stesso vale per interfaccia di annotazione e indici analitici per turno: inizialmente bastano export revisionabili.

### P2 — RL su sandbox e miglioramento multi-ruolo

Materializzare task/ambienti autorizzati e riproducibili; sviluppare un reward robusto con benchmark indipendente; integrare rollout multi-turn del modello da allenare. Iniziare da un ruolo e task brevi con reset affidabile, poi valutare traiettorie più lunghe e coordinamento fra ruoli.

Per i progetti utente non riproducibili, usare le tracce per SFT/preferenze e, quando possibile, derivare fixture sintetiche curate con provenance. Non eseguire training esplorativo sui target live degli utenti.

## 9. Riferimento DeepSeek Harness

Consultato upstream al commit `571902028a75c64c1d767f4ae5fd099485f0ddf0` il 14 ottobre 2026. `Session` conserva un log append-only e `deriveMessages()` ricava la superficie dei messaggi dal log; il backend JSONL fornisce persistenza separata.

Codice verificato: [Session e deriveMessages](https://github.com/deepseek-ai/deepseek-harness/blob/571902028a75c64c1d767f4ae5fd099485f0ddf0/packages/core/session/src/index.ts), [backend JSONL](https://github.com/deepseek-ai/deepseek-harness/blob/571902028a75c64c1d767f4ae5fd099485f0ddf0/packages/session/session-persistence-jsonl/src/index.ts).

Il concetto trasferibile è distinguere storia conservata e contesto proiettato, soprattutto dopo compaction. Non è una pipeline di training da importare. Per Lailaps basta una traccia di raccolta separata: non occorre trasformare ora tutta l'orchestrazione in event sourcing o adottare il sistema di plugin Harness.

## Raccomandazione finale

Implementare prima la raccolta dei contenuti autorizzati, con contesti verificabili ed esiti collegati, e un export SFT ispezionabile. Questo preserva oggi il valore delle run per molte forme di post-training. La prima scelta di training è un adapter supervisionato su un ruolo; preferenze e RL diventano estensioni motivate da dati ed evaluation, non prerequisiti dell'archivio.
