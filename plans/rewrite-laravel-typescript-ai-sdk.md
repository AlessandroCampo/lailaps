# Roadmap di rewrite Lailaps: Laravel + TypeScript + Vercel AI SDK

> **Aggiornamento del 17 ottobre 2026:** l'ordine operativo di questo documento è sostituito dalla [roadmap prodotto prima, migrazione agente dopo](roadmap-prodotto-prima-migrazione-agente-dopo.md). In lailaps-2 upload/persistenza e preparazione sono completati secondo l'utente; login GitHub e collegamento repository restano da fare. Si completa il nuovo prodotto usando temporaneamente il motore Python e si migra il core TS soltanto alla fine. Questo documento conserva l'analisi originaria del rewrite; gli stati e le priorità sotto sono storici.

Data: 7 ottobre 2026. Stato: C0 completato nel perimetro concordato di congelamento delle due run Python; C1–C12 da implementare.

Obiettivo: ricostruire il prodotto completo e rendere comprensibile il core agentico, preservando la qualità verificabile del percorso attuale. La parità riguarda capacità e risultati, non il numero di classi, tool, flag o varianti storiche riprodotte.

Il perimetro funzionale del risultato è descritto in [Requisiti di prodotto e demo completa](requisiti-prodotto-lailaps.md): quel documento parte dal percorso utente e dai criteri di accettazione; questa roadmap ne organizza la costruzione.

Stack fissato: Laravel + Vue/Inertia per il prodotto; Node/TypeScript + Vercel AI SDK per il runtime; laboratorio isolato per analisi ed esecuzione. La roadmap usa il codice del checkout, incluse le modifiche non committate, e ARCHITECTURE.md come riferimenti. HEAD osservato: `36f468c691b1d631e299fb15e3f63a4704f0d7dd`; quel commit, da solo, non identifica il comportamento del working tree.

## 1. Decisioni e perimetro

**P0 della roadmap**: arrivare a un prodotto utilizzabile da browser, con ZIP e GitHub, revisioni immutabili, preparazione guidata, target URL/sandbox/ibrido, pipeline agentica, evidenze, report, cancellazione e confronto con Python. L'implementazione procede per i checkpoint sotto, senza costruire tutto prima di verificare il primo risultato.

**P1+**: capacità opzionali e infrastruttura di scala. Non sono prerequisiti della prima versione completa. Una capacità opzionale torna nel P0 del checkpoint interessato se il confronto dimostra che è necessaria per conservare qualità.

Confini di responsabilità:

| Responsabilità | Proprietario |
|---|---|
| Utenti, permessi, progetti, import e credenziali GitHub | Laravel |
| Snapshot sorgente e relazione progetto → revisione → preparazione → audit | Laravel |
| Queue, provisioning, readiness, cancellazione e teardown | Worker Laravel |
| Ragionamento del doctor e artefatti di preparazione proposti | TypeScript, con applicazione/verifica dell'ambiente sotto supervisione Laravel |
| Recon, Reader, investigatore, memoria, ledger, deduplica e budget operativo | TypeScript |
| Esecuzione delle operazioni autorizzate nel laboratorio | Executor fidato, distinto dai programmi generati |
| Report e checkpoint agentici autorevoli | Runtime TypeScript |
| Proiezioni a DB, UI e valutazioni benchmark | Laravel; evaluator separato dall'agente |

Il runtime iniziale è un eseguibile supervisionato, senza Nest/Adonis, propria autenticazione, ORM o seconda queue. Un job Laravel supervisiona un audit; i task interni vengono coordinati nel processo TS. Eventi JSON su stdout e diagnostica separata sono un trasporto, non un nuovo archivio permanente di eventi a DB.

Durante il passaggio esistono due motori selezionabili dal launcher, Python e TS. Il nuovo motore non contiene rami per eseguire le vecchie topologie. Il supporto temporaneo di selezione motore viene rimosso a C12.

## 2. Cosa esiste già e cosa va verificato

L'esistenza del codice non certifica la completezza del percorso reale, né la qualità della UX. Nel checkout sono già presenti:

| Modulo | Riferimenti principali | Trattamento proposto |
|---|---|---|
| Account e impostazioni | Fortify, `routes/settings.php`, test Auth/Settings | Riutilizzare, verificare accesso e proprietà dei dati |
| Progetti e ZIP | `ProjectController`, `ImportZipRevision`, `SourceRevisionMaterializer`, pagine projects, `SourceImportTest` | Completare percorso e feedback utente, conservare la materializzazione sicura |
| GitHub | `GithubConnectionController`, `GithubAppClient`, `ImportGithubRevision`, `GithubConnection` | Validare la connessione reale e completare gestione di errori/permessi |
| Preparazione | `PreparationController`, `RunPreparation`, `PreparationDoctorRunner`, `doctor.py`, pagina preparations | Conservare lifecycle PHP e riscrivere il ragionamento in TS |
| Sandbox e URL | `SandboxService`, driver Compose/Image, servizi Target, `PentestRun` | Riutilizzare provisioning; separare target remoto e laboratorio locale |
| Esecuzione e persistenza | `ExecuteAuditRun`, `AuditCommandBuilder`, `AuditRunFactory`, `RunStorage`, `AuditRunFinalizer` | Semplificare il lancio e fissare ownership, non duplicare infrastruttura |
| Browser | `BrowserSidecar`, `agent/playwright-worker`, gateway e tool browser Python | Riutilizzare il worker Node; riscrivere l'integrazione del core |
| Valutazione | `BenchmarkStageArtifactRegistry`, evaluator Laravel, fixture e replay | Conservare corpus/oracle, adattare una volta i risultati TS |
| Core | `global_pipeline.py`, `triple_agent.py`, `ledger.py`, `budget.py`, `runtime_tools.py` | Riscrivere responsabilità e contratti; non tradurre i file riga per riga |

Un gap concreto: il percorso `PreparationController::audit` oggi costruisce un avvio sandbox con parametri diversi dal percorso globale della console. La nuova UI deve usare una sola specifica di audit e la stessa pipeline, indipendentemente dall'ingresso ZIP/GitHub/benchmark.

## 3. Filosofia DeepSeek applicata a Lailaps

Il riferimento corretto è [deepseek-ai/deepseek-harness](https://github.com/deepseek-ai/deepseek-harness). Versione consultata: `5badb15009ae1756c3afe0ae0cef1faafc290ccc`.

Il [profilo sdk-minimal](https://github.com/deepseek-ai/deepseek-harness/blob/5badb15009ae1756c3afe0ae0cef1faafc290ccc/packages/bundle/sdk-minimal/README.md) espone, senza MCP configurati, una shell persistente. Omette tra l'altro tool filesystem, compattazione, telemetria e subagent e usa una policy di accesso completo ai percorsi disponibili al processo. Il suo minimalismo è un riferimento per ridurre l'interfaccia al modello; non è una configurazione da trasferire direttamente a Lailaps.

Adottare:

- primitive generali di lettura, ricerca ed esecuzione, anziché un tool per ogni formato o tecnologia;
- separazione tra schema esposto al modello e metadati interni di esecuzione;
- catalogo stabile nel corso della conversazione;
- risultati di chiamate concorrenti collegati e consegnati in ordine coerente;
- errori ordinari come risultati utilizzabili e cancellazione con raccolta delle operazioni già avviate.

Il [dispatcher dei tool DeepSeek](https://github.com/deepseek-ai/deepseek-harness/blob/5badb15009ae1756c3afe0ae0cef1faafc290ccc/packages/core/agent-loop/src/tool-calls.ts) è un riferimento per ordine dei risultati e cancellazione. Non occorre adottare Cordis, il sistema plugin, gli entrypoint SDK o la persistenza JSONL del progetto. Sono problemi aggiuntivi rispetto al core richiesto.

Per Lailaps mantenere budget, compattazione narrativa, isolamento, autorizzazione dei tool, provenance ed evidenze strutturate. Una shell che esegue `curl` può fare una richiesta, ma da sola non fornisce la relazione autorevole fra attore, target, episodio e osservazione necessaria a una conferma.

Il primo catalogo è piccolo e concreto: lettura/ricerca sorgente con reference automatiche; esecuzione nel toolbox; lettura/ricerca di risultati autorizzati; output di decisione piatti. HTTP, browser e osservazioni del target vengono aggiunti nei rispettivi checkpoint. Non serve costruire un framework di plugin per questo catalogo.

## 4. Regola di completamento e testing

Ogni checkpoint produce una funzione utilizzabile e verificabile. Quando coinvolge un agente, si distingue fra **pronto per benchmark** e **accettato**: i mock dimostrano contratti e gestione degli errori, non la qualità del ragionamento.

Tre livelli di verifica:

1. **Offline, senza LLM**: test TS mirati con provider simulato, test Laravel mirati, fixture e replay dei contratti. CI senza credenziali provider; lint/typecheck limitati ai moduli toccati.
2. **Integrazione senza LLM**: browser e HTTP su fixture locali, Docker quando richiesto, persistenza e UI con runtime simulato. Le risposte simulate non contano come conferme di benchmark agentico.
3. **Accettazione con LLM**: esecuzioni controllate richieste esplicitamente, con budget e corpus fissati. Ogni checkpoint agentico passa il suo confronto prima di essere marcato accettato.

La documentazione [Testing di AI SDK](https://ai-sdk.dev/docs/ai-sdk-core/testing) fornisce le primitive di simulazione del modello. I test devono verificare comportamento del prodotto e invarianti, non rispecchiare semplicemente la nuova implementazione.

Ogni scheda di accettazione registra: revisione del codice/runtime, input, progetto/revisione, ambiente, modello/configurazione, risultato dei test, confronto benchmark e limitazioni residue. Non si lancia un nuovo audit completo per verificare ogni modifica locale.

## 5. Sequenza dei checkpoint

| Checkpoint | Incremento consegnato | Dipendenza | Evidenza principale di completamento |
|---|---|---|---|
| C0 | Baseline e perimetro di parità | — | Ultime run globali YesWiki/BookStack congelate; output LLM, metriche e provenance verificabili offline |
| C1 | Account → progetto → ZIP → revisione | C0 | Snapshot immutabile a DB, visibile e accessibile solo al proprietario |
| C2 | Runtime TS e primitive | C1 | Run supervisionata, eventi, checkpoint e cancellazione con modello simulato |
| C3 | Recon completa come percorso verticale | C2 | Recon prodotta, persistita a DB, collegata al progetto e benchmark comparabile |
| C4 | Reader, lead e checkpoint semantici | C3 | Lead con source reference, area ledger e risultati persistiti/valutati |
| C5 | Connessione e import GitHub | C1; ordine suggerito dopo C4 | Repository autorizzato e commit immutabile seguono lo stesso percorso ZIP |
| C6 | Target URL, sandbox e ibrido; attori | C2 | Ambienti pronti e distinti, sessioni isolate, cleanup verificato |
| C7 | Environment Doctor in TS | C6 | Preparazione guidata realmente avviabile, pausa/risposta e ripartenza verificate |
| C8 | Investigatore unificato e verifica HTTP | C4 + C6 | Milestone statica durevole e verdetti dinamici con prove valide |
| C9 | Browser e conferme che richiedono esecuzione | C8 | Login/browser/XSS confermati da osservazioni causali, controlli negativi superati |
| C10 | Pipeline globale concorrente e multi-pass | C8 + C9 | Code drenate, budget corretti, memoria e isolamento dei pass verificati |
| C11 | Flusso prodotto completo e report | C5 + C7 + C10 | Utente completa l'audit da browser, legge/esporta il report e gestisce gli errori |
| C12 | Accettazione finale, switch e ritiro | C11 | Suite comparativa accettata; nuovo flusso autonomo e legacy rimosso dal prodotto |

Non sono tredici sottoprogetti indipendenti: molti riusano servizi Laravel già presenti. Le UI essenziali e la persistenza si realizzano dentro C1–C10; C11 completa e verifica il percorso complessivo.

### C0 — Congelare baseline e contratto di parità

**P0 concordato ed eseguito**: conservare solamente l'ultima run globale end-to-end disponibile di YesWiki (`yeswiki-global-20260917-090105`) e BookStack (`bookstack-global-glm-limited-20261007-002549`). Gli output LLM Python sono la ground truth operativa della prima fase di parità, anche quando un verdict non è corretto rispetto a un oracle esterno. Differenze di configurazione e fallimenti tecnici vengono registrati, senza scegliere retroattivamente una run migliore.

Pacchetto autonomo: [benchmarks/baselines/python-c0-20261007](../benchmarks/baselines/python-c0-20261007/README.md), con manifest SHA-256, outcome/transcript originali compressi, output completi, metriche normalizzate, modelli, budget, pricing, errori, clock e indice tool/log. Il lookup DB è riuscito ma le due run non sono presenti: non è stato creato un secondo archivio a DB. Gli artifact originali non sono stati cancellati o ricalcolati.

**Ground truth aggiornata su istruzione dell'utente**: per BookStack il confronto operativo usa **4 conferme su 5 lead**, distinto da `0 confirmed / 4 suspected` persistiti. La [annotazione manuale](../benchmarks/baselines/python-c0-20261007-ground-truth.json) prevale al solo livello aggregato e identifica il manifest originale tramite hash; benchmark, consumi e archivi restano invariati. Le ultime 30 righe del log selezionato riportano il risultato persistito e non ricostruiscono il 4/5: mapping delle singole lead ancora da precisare, senza promozioni arbitrarie.

**Test e gate superati**: archivi byte-identici agli originali, proiezioni riproducibili offline, finding e disposizioni identici all'outcome; test mirati di denominatori, timezone, missing data e log concorrenti. Nessun agent loop eseguito. I totali derivati usano la telemetria globale senza sommare proiezioni sovrapposte. Il benchmark storico resta separato; le metriche mancanti restano `null`.

**Limiti**: clock YesWiki contraddittori, durata del processo non misurabile con certezza, snapshot storico di codice/prompt/immagini/laboratorio non disponibile. I tempi per lead e gli intervalli riportati sono conservati con il loro scope. Nessuna tolleranza statistica o soglia di tempo è dedotta da due campioni. L'identità del checkout odierno non è presentata come quella del runtime storico.

**Passo successivo**: costruire in TS il confronto semantico dei risultati per stadio/end-to-end, usando i riferimenti disponibili; mostrare risultati persi/cambiati/extra, milestone, errori, costo e tempi separatamente. Questo C0 congela il riferimento, non implementa il comparator. Nessuna perdita di una lead di riferimento è compensata automaticamente da lead nuove.

**P1**: ripetizioni appaiate e snapshot realmente riproducibili per stimare variabilità e soglie; ulteriori stack, controlli positivi/negativi e adjudication esterna nella successiva fase di qualità. Non sono prerequisiti del congelamento richiesto.

### C1 — Account, progetti e upload ZIP

**Lavoro**: completare il percorso Laravel esistente: accesso, creazione progetto, upload, import asincrono, stato/progresso, errore leggibile e revisione pronta. Riutilizzare `SourceRevisionMaterializer` e le relazioni esistenti. La revisione analizzata è immutabile e identificata dal content hash; le modifiche del doctor avvengono su una copia runtime.

**Test e gate**: upload valido → import completato → revisione a DB collegata all'utente/progetto → pagina aggiornata. Testare import fallito e pulizia del file temporaneo, archivi con traversal/symlink e limiti già previsti; un altro utente non può leggere revisioni, preparazioni o artifact. Il medesimo contenuto produce un hash coerente. Smoke browser con fixture locale, senza LLM.

**Semplificazione**: un solo processo di materializzazione per ZIP e futuro archivio GitHub; nessun import duplicato nel core TS. Niente editor remoto o sincronizzazione continua in questo checkpoint.

### C2 — Runtime TS, supervisione e primitive statiche

Il bootstrap è diviso in **C2a**, descritto nel [piano minimo con pseudocodice](c2a-runtime-minimo.md), e **C2b**, laboratorio applicativo e Doctor minimo. C2a verifica Laravel → processo TS → tool/output/checkpoint con modello simulato; C2b deve rendere disponibile un target realistico prima dell'accettazione C3. C6/C7 completano modalità/attori e capacità del Doctor oltre il bootstrap.

**Lavoro**: eseguibile Node con `RunSpec` interno piccolo, Vercel AI SDK/provider fissati, cancellazione cooperativa, accounting delle richieste e un solo percorso di finalizzazione. Laravel avvia direttamente il runtime o il suo container, riceve eventi con ID, mantiene lifecycle e salva le proiezioni. La CLI tecnica usa lo stesso servizio di lancio.

Implementare prima le primitive di acquisizione sorgente e toolbox isolato. Reference sorgente registrate dal runtime; output bounded con ID e recupero per finestre/ricerca. Iniziare con history preservata, note narrative scoped e limite del contesto; aggiungere la compattazione condivisa in C4, quando serve al Reader, senza ritardare la prima Recon per completare tutto il sistema di memoria. Conservare come punto di partenza la semantica EP attuale; prezzi e limiti provengono da una configurazione autorevole, non da copie PHP/TS divergenti.

Mantenere il contratto a due artifact della run. Report scritto dal runtime; transcript e lifecycle gestiti dal supervisore; arricchimento benchmark dopo il termine. Rendere idempotente la proiezione degli eventi a DB e ricostruibile dall'outcome se il trasporto si interrompe. Per i risultati di stadio del prodotto basta inizialmente un campo JSON su `AuditRun`, senza creare una tabella di tutti gli eventi. Il report è autorevole; la proiezione a DB è derivata.

**Test e gate**: con un provider simulato una run passa da queued a completed, produce tool call/result, checkpoint e proiezioni. Provare timeout, cancellazione, errore provider, errore di persistenza e consegna spezzata degli eventi. Usage registrato una volta per risposta, compresi retry; dati mancanti sono espliciti e le stime distinguibili. Verificare le richieste realmente serializzate prima/dopo checkpoint: stessi schemi e ordine del prefisso; una chiamata operativa vietata non viene eseguita. Verificare scope dei risultati e assenza di socket/credenziali/provider nei programmi generati.

**Semplificazione**: primitive SDK prima di wrapper propri. `ToolLoopAgent` è utilizzabile se rispetta i contratti; se serve controllare esplicitamente gli step, usare le API Core e una piccola funzione di loop. Il [loop control SDK](https://ai-sdk.dev/docs/agents/loop-control) non sostituisce budget e ledger applicativi. Non replicare workaround Pydantic senza un problema riprodotto sullo SDK nuovo.

### C3 — Recon verticale, persistita e comparabile

**Lavoro**: da una revisione ZIP pronta, l'utente avvia Recon e vede briefing, incarichi ordinati e limiti osservati. Output modello piatto: briefing narrativo, incarichi con titolo/path/prossima verifica, note di copertura. L'orchestratore assegna identità e normalizza l'Area Ledger. Il risultato compare nella proiezione a DB dell'audit e negli artifact benchmark quando si tratta di una run di valutazione.

**Test e gate**: letture/reference valide, locator non osservati distinti da quelli osservati, output parziale e Recon indisponibile conservati senza inventare un piano. Da browser: progetto → revisione → audit → Recon persistita e consultabile dopo ricaricamento. Valutazione LLM su sorgente congelato con stesso modello/configurazione/budget della baseline: normalized score, strict/guided recall, recall@k, utilità degli incarichi e costo. Una valutazione basata su locator va affiancata al Reader su piano congelato: cambiare formulazione non deve essere scambiato per peggioramento reale.

**Semplificazione**: iniziare con briefing leggero e primitive. Confrontare con/senza CBM e segnali Semgrep separatamente; se la Recon perde copertura protetta, aggiungere il sensore necessario prima di accettare C3. Nessun ranking deterministico che sostituisca la Recon.

### C4 — Reader, checkpoint, lead e deduplica

**Lavoro**: un solo Reader alla volta per la prima accettazione. Esplora l'area, emette lead, può chiedere un'area aggiuntiva e decide semanticamente continue/chiusura. Acquisizione, collegamento alle letture, milestone e stato della copertura sono persistiti. Le lead iniziali sono ipotesi non ancora validate; non sono conferme statiche. Aggiungere nel kernel C2 la compattazione narrativa, con riferimenti recuperabili e accounting; la stessa implementazione sarà riusata dall'investigatore, senza una variante per ruolo.

Riutilizzare la semantica del deduper deterministico attuale alla frontiera di acquisizione: ignoti passano, originali conservati, duplicati collegati al rappresentante. Il Reader continua dopo l'emissione di una lead, senza un secondo agente di review o un handoff che ricostruisca il dossier.

**Test e gate**: Reader simulato emette più lead nella stessa area, checkpoint stale non chiude l'area successiva, reference fuori scope non passa; budget/errore conserva lead e segna copertura incompleta. Una compattazione conserva note/reference, cambia epoch esplicitamente e non duplica usage; la richiesta che produce il summary legge ancora la history precedente e il catalogo stabile. Da browser: lead a DB consultabile e collegata a revisione, audit, area e sorgente. Benchmark LLM sul medesimo piano Recon congelato: recall/precision delle lead, casi nuovi adjudicati, duplicati, costo per lead utile. Separare il benchmark end-to-end che cambia anche la Recon.

**Semplificazione**: solo self-checkpoint, solo deduper canonico nel prodotto. I controlli semantici di progresso restano al Reader; contatori di ripetizione servono per diagnostica, non per dichiarare che è bloccato.

### C5 — GitHub completo nel percorso prodotto

**Lavoro**: completare la connessione GitHub App già abbozzata: autorizzazione, repository accessibili, branch/commit, selezione sottodirectory, import asincrono, scadenza/revoca e riconnessione. Branch/tag vengono risolti a SHA prima dell'import. Il risultato attraversa lo stesso modello `SourceRevision` e gli stessi C3/C4, senza passare token GitHub al runtime.

**Test e gate**: test Laravel con API GitHub simulate per callback/state, permessi, refresh, revoca, errori e download; contenuto ZIP/GitHub equivalente produce sorgente coerente. Smoke reale con GitHub App configurata e repository didattico privato autorizzato: connessione → commit → import → Recon/Reader nel progetto corretto. Questo smoke è distinto dai test simulati e non è già stato eseguito.

**Semplificazione**: import a snapshot, senza webhook, polling continuo, PR bot o scrittura sul repository. OAuth dell'utente e accesso/installazione della GitHub App vanno verificati nel setup reale: il nome del client non dimostra da solo quali repository può leggere.

### C6 — Ambienti, URL/sandbox/ibrido e attori

**Lavoro**: predisporre il laboratorio tools anche in URL mode. Consentire sandbox da configurazione esistente/operatori prima dell'automazione del doctor. Separare snapshot, target HTTP remoto, target locale e capacità disponibili. Riutilizzare Compose/Image driver e readiness; identificare ogni evidenza con l'ambiente effettivamente osservato.

Implementare HTTP generale con attori e cookie jar isolati, autenticazione configurabile, form/JSON/raw/multipart, redirect limitati al perimetro, timeout e redazione dei segreti. Mantenere osservazioni del target e query DB attraverso l'executor autorizzato, senza credenziali del database del prodotto nel runtime. Dichiarare capacità verificate soltanto dopo una verifica reale.

**Test e gate**: fixture locali con login, CSRF, upload e almeno due ruoli; nessuna contaminazione di cookie. URL mode funziona senza container dell'app; sandbox funziona senza URL remoto; ibrido distingue le due prove. Testare readiness fallita, indisponibilità DB, teardown e TTL, cancel durante build e vincoli degli output. Una query/configurazione dichiarata non basta a marcare DB verified. Test senza LLM.

**Semplificazione**: un solo descrittore delle capacità e un solo client HTTP; non duplicare loop per modalità. Condividere l'executor, conservando autorizzazioni distinte fra toolbox, osservazione target e micro-esperimenti.

### C7 — Environment Doctor TS

**Lavoro**: riscrivere `doctor.py` sul kernel C2. Preferire manifest, documentazione e setup esistenti; generare solo artefatti di ambiente in una copia runtime. Decisioni essenziali: preparare, chiedere informazioni, dichiarare un impedimento. Laravel applica il provisioning, rimanda diagnostica bounded, persiste memoria e domande e gestisce le risposte. Segreti forniti fuori dal prompt, con presenza dichiarata al modello quando sufficiente.

Quando l'ambiente ha già un profilo affidabile, eseguire i controlli prima di chiedere al doctor di reinventarlo. Il doctor non deve conoscere vulnerabilità del benchmark né modificare il codice applicativo per rendere un exploit possibile.

**Test e gate**: decisioni simulate attraversano prepare/ask/blocked; UI consente risposta e ripartenza senza perdere il lavoro. Verifica del sorgente immutato e dei segreti assenti da prompt/log/artefatti. Benchmark LLM su un piccolo set di progetti multi-stack: avvio e readiness, tempo/costo, richieste all'utente, recupero da un errore di setup, impossibilità spiegata. Una preparazione è completa quando la configurazione salvata permette di avviare di nuovo il target e passare una probe reale, non quando il doctor produce un Dockerfile.

**Semplificazione**: un solo doctor con memoria narrativa e verifiche Laravel. Non introdurre agenti separati per discovery, build, repair e review; non costruire un catalogo esaustivo di installer per framework.

### C8 — Investigatore unificato: statico → dinamico HTTP

**Lavoro**: una conversazione per lead, stesso modello/prompt/history fra fasi. Prima conferma statica o barriera positiva; persistere la milestone prima di abilitare la verifica dinamica. Dopo la promozione, usare un envelope dinamico unico. PHP non valuta se una vulnerabilità è confermata.

La mancanza di una prova dinamica conserva il risultato statico come sospetto con milestone e motivo. `rejected` richiede una confutazione positiva; `confirmed` richiede impatto dinamico osservato nell'episodio/ambiente corretto. Output del modello narrativo con pochi campi di controllo, normalizzato dal ledger. Query DB e shell possono corroborare una prova; effetti prodotti direttamente dal codice del probe non dimostrano un exploit HTTP.

**Test e gate**: fixture di transizioni e failure injection dopo la promozione; la milestone sopravvive a budget esaurito, provider down e checkpoint terminale fallito. Evidence ID appartenente ad altra lead/pass/ambiente viene respinto. Non si trasforma un verdetto generato da un mock in prova reale. Benchmark LLM su ReaderLead congelate positive/negative: static validation, dynamic confirmation, precision, disposizione dei blocchi e costo; la matrice conserva anche i risultati statici quando la verifica non termina.

**Semplificazione**: niente secondo agente Worker, seconda coda o recipe obbligatoria piena di dati ricostruibili. Confirmer/Worker restano nomi contabili delle fasi per confrontare gli score storici.

### C9 — Browser e prove di esecuzione

**Lavoro**: riusare il sidecar Playwright attuale e adattare gateway/tool TS. Sessioni scoped per attore/lead, azioni e osservazioni persistite, gestione del login e dei cookie con trasferimenti espliciti. Non spostare browser e sorgenti nel processo fidato che controlla Docker.

**Test e gate**: browser reale su fixture locale: login, navigazione, cambio attore, timeout, cancellazione e isolamento delle sessioni. Caso XSS positivo con marker causale e osservazione; controlli negativi di semplice reflection, DOM statico e log prodotti dal test stesso. Benchmark LLM delle lead che richiedono browser; evidenze browser valide senza inventare una transazione HTTP equivalente.

**Semplificazione**: mantenere inizialmente le capacità già coperte da `browser_flow`/`inspect_browser`, evitando un nuovo framework di browser agent. Il worker Node non viene riscritto per uniformità estetica.

### C10 — Pipeline globale, concorrenza e pass

**Lavoro**: comporre gli stessi componenti accettati. Prima un Reader e un investigatore, poi pool configurabili fino alla configurazione attuale di riferimento. Una Recon globale; una coda aree e una coda lead; un solo coordinatore publisher. Nessuna coda Worker distinta. Aggiungere `depth` come ripetizione della stessa pipeline, con pass/sessioni/ledger/budget separati.

Conservare cap Recon distinto, cap Reader aggregato, budget per lead e safety net globale. Stop discovery non revoca lavoro già ammesso. Gestire riserve terminali e overshoot senza doppio accounting. Compattazione narrativa e recupero degli output restano nello stesso kernel, non vengono implementati diversamente per ciascun ruolo.

**Test e gate**: con provider simulato, lead accodata mentre Reader prosegue, pool riempiti senza superare i limiti, aggiornamenti del ledger atomici, code drenate allo stop Reader; errore locale non cancella gli altri task, errore di persistenza interrompe pubblicazioni. Cancel e timeout conservano l'ultimo checkpoint valido. Con depth 2 non si contaminano history, cookie, evidence o budget; lo stato del target fra pass è dichiarato, senza promettere un reset implicito. Benchmark LLM completo con stessi modelli, budget e target: qualità, first useful finding, costo e failure rate rispetto al corpus C0.

**Semplificazione**: una sola topologia. Concorrenza interna, senza BullMQ, Temporal o job separato per richiesta LLM. Categoria OWASP come classificazione/filtro del report; non chiamare equivalente al vecchio audit categoriale una scansione globale filtrata a posteriori.

### C11 — Flusso prodotto e report

**Lavoro**: completare il percorso unico progetto → revisione → target/preparazione → audit → report. Portare gli avvii provenienti dalla preparazione sulla pipeline globale accettata. UI essenziale per avanzamento per stadio, consumi, impedimenti, domande del doctor e risultati parziali. Conservare la console tecnica come supporto ai benchmark, senza farne un secondo prodotto.

Report consultabile con evidenze e provenienza: sospetti, sospetti con milestone statica, confermati dinamicamente, copertura e lavoro incompleto. I rifiutati rimangono auditabili senza essere elencati come vulnerabilità. Indicare revisione, ambiente di ciascuna prova e differenza fra conferma sulla replica e sull'URL remoto. Esportazione JSON e gestione di cancellazione/rerun/storico. Politica esplicita di conservazione ed eliminazione dei sorgenti temporanei.

**Test e gate**: E2E browser senza LLM con runtime simulato per ZIP e GitHub, doctor ask/answer, live update/reload, errore e report. Test autorizzazione di pagine, stream, download e artifact. Cancellazione ripetuta idempotente; rerun crea nuova identità e ambiente appropriato, non ricontabilizza la vecchia run. Elimina un progetto di fixture e verifica la gestione dei suoi file senza rompere report ancora conservati. Smoke LLM controllato da browser per percorso sandbox e URL/ibrido; risultati persistiti e benchmark associato verificabili.

**Semplificazione**: una sola configurazione di avvio, un solo report/viewer, stessi servizi per UI e CLI. Niente chat generalista o report PDF sofisticato come prerequisito.

### C12 — Accettazione finale e ritiro del legacy

**Lavoro**: eseguire il confronto finale sul corpus fissato, risolvere regressioni bloccanti e rendere TS il motore ordinario. Conservare il riferimento Python congelato fuori dal prodotto per auditabilità; rimuovere launcher Python, varianti legacy, flag e dipendenze non più necessari. Preservare fixture, manifest, evaluator e risultati storici utili; adattamento di sola lettura degli outcome vecchi confinato al viewer/evaluator.

Rilasciare una procedura di avvio riproducibile: immagini/versioni fissate, migrazioni, queue worker, storage privato, credenziali provider e GitHub App. Per il contesto didattico iniziale resta sufficiente il laboratorio controllato; l'accettazione di codice di terzi richiede una collocazione isolata dal control plane. Su host distinti esplicitare trasferimento snapshot/artifact, senza assumere mount locali condivisi.

**Test e gate**: build/boot da ambiente pulito, test offline mirati e fixture di isolamento, ripristino di un checkpoint/report dopo crash e leggibilità dello storico. Suite LLM autorizzata accettata per stadi ed end-to-end; nessuna falsa conferma nei controlli protetti; nessuna regressione non spiegata sui casi protetti. Controllo dipendenze/referenze dopo la rimozione: il prodotto TS funziona senza ambiente Python del vecchio core. Eventuali interpreti Python nel toolbox hanno uno scopo operativo distinto. Rollback di deploy verificato prima del ritiro definitivo; nessuna migrazione distruttiva dei report storici.

**Semplificazione**: deletion pass sul prodotto e sulla configurazione. Aggiornare ARCHITECTURE.md a ogni checkpoint strutturale implementato; a fine rewrite descrive il solo flusso attuale, mentre storia e decisioni restano in documenti separati.

## 6. Semplificazioni candidate e condizione di rimozione

La decisione iniziale è **non portare automaticamente**. La cancellazione dalla repo attuale avviene solo al checkpoint di ritiro, dopo aver preservato baseline e dipendenze realmente usate.

| Zona attuale | Proposta | Verifica richiesta / checkpoint |
|---|---|---|
| `tools/more_tools.py` | Non portare: file sostanzialmente vuoto | Verifica di referenze e packaging a C12 |
| `tools/http_probe.py`, fetch/cache live in `tools/owasp.py` | Non portare vecchi helper non referenziati dal core osservato; usare client e registry OWASP attuali | Cercare riferimenti anche in script/CLI prima di eliminarli; C12 |
| `read_file`/`read_file_window`, helper di listing e ricerca | Una primitive di lettura e una ricerca, con acquisizione/reference affidabili | Fixture di righe, hash, path e output parziali; C2 |
| Sei varianti `http_*_call` | Un tool HTTP generale, conservando i formati realmente necessari | JSON, array, form, raw, multipart, cookie e redirect; C6/C8 |
| `inspect_response`, `search_response`, `read_response`, query HTML/JSON e `find_json_records` | Lettura/ricerca di artifact e piccoli programmi isolati per trasformazioni | Stessi dati recuperabili, encoding/body/errori, scope e provenance; C6/C8 |
| `crypto_artifact` | Preferire programmi nel toolbox e librerie standard per produrre payload | Casi JWT/encoding/crypto del corpus, senza usare payload generato come prova di successo; C8 |
| Tool separati di history, evidence e tool output | Backend comune e poche operazioni read/search, preservando distinzione fra memoria ed evidenza | Recupero dopo compaction, eviction esplicita, autorizzazione e citazioni; C2/C8 |
| `run_code` PTC statico | Non portare automaticamente il broker Python; iniziare con primitive e toolbox | Confronto su letture batch, costo e correttezza; se necessario, programma isolato TS con binding minimi, non `eval` nel processo fidato |
| CBM/grafo | Optional nella prima implementazione; reinserire se il confronto dimostra gain | Ablation su Recon/Reader multi-stack; C3/C4. Non basta il conteggio di chiamate |
| Joern/`trace_data_flow` | Fuori dal nucleo iniziale; mantenere come capacità candidata | Controlli sulla propagazione e sui wrapper; se necessario, entra nel P0 di C8 |
| `navigate_source`/Phpactor | Navigation opzionale, evitando un requisito PHP nel core generalista | Casi dove ricerca testuale perde flusso/reachability; C4/C8 |
| Semgrep/SurfaceContext | Nessun porting dell'intera infrastruttura prima dell'ablation | Confrontare con/senza sensore advisory, incluso wrapper custom; C3/C4 |
| Shell target, micro-probe e query DB | Condividere infrastruttura, preservare confini e osservazioni typed | Non sostituire con shell illimitata; prove di isolamento e read-only reale, C6/C8 |
| Browser | Riutilizzare sidecar; ridurre solo adapter e doppie rappresentazioni | C9, con prove browser protette |
| Pipeline categoriale sequenziale e vecchi Confirmer/Worker separati | Non portare come topologie del prodotto | C10/C12; se un audit limitato a categoria è richiesto, definire quel requisito separatamente |
| Deduper LLM e sue modalità sperimentali | Non portare nel runtime ordinario; conservare come baseline/esperimento | Deduper deterministico e casi di collisione accettati a C4 |
| Wrapper di compatibilità output, retry e accounting Pydantic | Riscrivere invarianti; usare prima SDK e una finalizzazione comune | Test provider/serializzazione/failure injection C2/C8 |
| Config PHP + env Python + Models.json + molti flag | Configurazione autorevole e piccolo RunSpec; parametri avanzati nel benchmark | Stessa risoluzione dei modelli/limiti nei percorsi web e CLI, C2/C11 |
| Numerosi comandi benchmark e metriche legacy reviewer/judge | Un entrypoint di valutazione per stadio, senza runtime alternativo; adapter storico confinato | Gli stessi artifact producono gli stessi score; C0/C12 |
| UI console/create/preparation con avvii diversi | Un percorso prodotto e servizi condivisi; console tecnica opzionale | Stesso input dà stessa run a prescindere dall'ingresso, C11 |

Due osservazioni dal campione locale, non una prova di causalità:

- `bookstack-global-glm-limited-20261007-002549`: `partial_failure`; CBM circa 314 s; 8 chiamate `search_code_graph`, 28 query DB, 15 browser flow. Grafo e verifiche runtime sono effettivamente usati.
- `bookstack-confirmer-global-20261006-192938`: `fatal_error`; CBM circa 446 s; zero `search_code_graph`, 217 query DB e 20 browser flow. L'indicizzazione preventiva in un replay merita verifica; DB e browser non sono capacità da eliminare senza sostituzione.

Non generalizzare l'assenza di uso in queste due run a tutto il corpus. Per ogni capacità rimossa, cambiare una variabile alla volta: stesso input, modello, budget e ambiente. Se cala un caso protetto o cresce un falso positivo, ripristinare la capacità necessaria. Meno chiamate o meno schemi non dimostrano qualità migliore.

## 7. Protocollo comparativo e soglie

**Prima fase concordata dopo la proposta iniziale**: per C0 e i primi confronti TS, i verdict delle due run Python congelate sono il riferimento operativo, senza richiedere adjudication esterna. Il gate iniziale è conservare semanticamente i risultati di riferimento e rendere esplicite le regressioni; non basta uguagliare il conteggio dei finding. I controlli esterni e le ripetizioni descritti sotto appartengono alla successiva validazione della qualità reale. Le soglie di performance si fissano su misure utilizzabili: il clock contraddittorio di YesWiki non diventa una soglia vincolante.

La comparabilità richiede sorgente/hash, setup e stato iniziale del target, attori, corpus/denominatori, modello e reasoning, budget e versione evaluator registrati. Registrare route/provider osservati quando disponibili. La versione runtime è una variabile del confronto, non deve bloccarlo; un fingerprint unico che include il motore va scomposto fra input comparabili e varianti sperimentali.

Conservare separatamente due modalità:

- **Controllata**: stesso modello, budget e input upstream congelato, per attribuire l'effetto al nuovo componente.
- **Operativa**: configurazione candidata finale ed end-to-end, per misurare qualità/costo del prodotto che useremo.

| Stadio | Qualità | Efficienza e affidabilità |
|---|---|---|
| Recon | normalized score, strict/guided recall, recall@k, utilità degli incarichi downstream | tempo, token/costo, errori di output, fallimenti del piano |
| Reader | recall/precision, lead nuove adjudicate, duplicati, validità della provenance | costo per lead utile, tempo alla prima lead, aree incomplete |
| Investigatore | milestone statiche corrette, conferme dinamiche valide, false conferme, disposition | costo per lead/caso confermato, errore tecnico, motivi di blocco |
| Doctor | ambiente riavviabile, readiness, autenticazione necessaria e capacità realmente verificate | tempo/costo, interventi utente, recovery e fallimenti spiegati |
| End-to-end | casi raggiunti e confermati, valore dei sospetti, copertura e report leggibile | costo totale, durata, time-to-first-finding, failure rate e correttezza dei limiti |

Non confrontare solo il numero di finding: un aumento può essere duplicazione o falsi positivi. I finding nuovi vanno adjudicati; match con il catalogo e novità valida hanno rendicontazioni distinte. Fallimenti tecnici e punteggi parziali restano visibili e non vengono selettivamente esclusi dal confronto.

Soglie da fissare in C0 prima di conoscere i risultati TS:

- **Invarianti**: tutti i test obbligatori devono passare; nessuna promozione senza evidence valida; nessuna perdita di milestone persistita; nessuna contaminazione di scope, attore o pass.
- **Casi protetti**: i controlli positivi e negativi scelti devono mantenere la disposizione corretta. Zero false conferme sui controlli negativi protetti.
- **Qualità aggregata**: intervallo di non regressione definito dalla variabilità osservata nella baseline; obiettivo almeno la qualità centrale Python, non uguaglianza del testo o delle tool call.
- **Costo e durata**: fissare un limite operativo per stadio prima del confronto; accettare un aumento solo se esplicito e giustificato da qualità maggiore. Riduzione del codice non compensa una regressione di qualità non accettata.

Primo smoke LLM: una ripetizione per verificare fattibilità. Accettazione iniziale: dove disponibili, almeno tre ripetizioni appaiate per stadio/input; riportare casi individuali, mediana e dispersione. Tre ripetizioni sono uno screening, non una dimostrazione statistica. Se l'esito è vicino alla soglia o molto variabile, servono ulteriori ripetizioni esplicitamente richieste; in quel caso lo stadio resta da accettare.

## 8. P1+ separati

**P1, guidati da dati**: capacità grafo/LSP/data-flow/sensori che mostrano un beneficio; audit scoped con semantica esplicita; resume esatto dopo crash se realmente richiesto; Git generico oltre GitHub; miglioramenti mirati della UX/report. Una capacità necessaria per i casi protetti viene anticipata, non rimandata per rispettare il calendario.

**P2, scala e prodotto esteso**: più audit contemporanei, pool remoto, Redis/Horizon e storage condiviso quando richiesti dal deployment; isolamento VM/microVM per il relativo modello di utenti; webhook, audit su PR e sincronizzazione continua; report PDF e integrazioni esterne. La sicurezza necessaria per il deployment effettivo resta P0: queste opzioni non autorizzano esecuzione di codice non fidato sul control plane.

Primo incremento consigliato: **C0 → C1 → C2 → C3**, con esito osservabile «carico un ZIP, avvio Recon, la vedo salvata nel mio progetto e confronto il risultato con Python». Chiudere C3 prima di investire nel porting dell'intero toolbox.

## 9. Verifiche svolte per questa proposta

Letti architettura, launcher, import/connessione GitHub, preparazione/doctor, registrazione tool, pipeline e componenti benchmark; ispezionati outcome locali recenti e sorgenti DeepSeek fissati al commit indicato. Non certificata l'operatività reale di GitHub/doctor né la parità TS, che non è implementata. Nessun agent loop, test a pagamento, test applicativo o lint dell'intero progetto eseguito. Nessun codice runtime modificato e nessuna cancellazione effettuata. ARCHITECTURE.md non viene riscritta per descrivere una proposta futura.
