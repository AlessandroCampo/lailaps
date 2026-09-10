# Handoff P0.3 — Benchmark condizionale del solo Worker

## Obiettivo e autorizzazione

Implementare un solo percorso: CandidateHandoff congelato → Worker su target pristine → valutazione post-run. Il solo ruolo agente esercitato è il Worker. Non eseguire Dynamic Judge, Recon, Reader o Confirmer e non creare benchmark o scorecard del Judge.

Questo handoff è autonomo: non richiede P0.2 sugli estratti probatori né P0.1 sugli esperimenti. Usare i contratti correnti, registrando i loro limiti. Se saranno estesi in futuro, versionare i nuovi dataset senza reinterpretare quelli precedenti.

La proposta deriva dalla sola lettura di ARCHITECTURE.md. Prima dell'implementazione leggere istruzioni applicabili, architettura aggiornata e codice pertinente. Verificare quali parti sono già disponibili e riusarle.

## Contesto

Esistono benchmark condizionali Recon, Reader e Confirmer e un registry project-scoped di boundary immutabili con lineage. Riutilizzarli per aggiungere il boundary Worker. Il benchmark per singola CVE avvia anche Confirmer e Judge e usa informazioni del caso: non sostituisce questo benchmark condizionale.

Lo scopo è misurare qualità dell'esecuzione dinamica, evidenze raccolte e proposta del Worker, separandole da discovery, handoff e adjudication. Una proposta ConfirmedDecision del Worker rimane una proposta, mai una conferma attestata del report ordinario.

## P0: Candidate congelato → Worker

1. Riutilizzare un CandidateHandoff canonico prodotto o curato tramite il registry esistente, con provenienza, progetto, categoria e commit sorgente compatibili. Congelare anche le dipendenze necessarie all'assignment, incluse source reference recuperabili; non introdurre un prompt alternativo per il benchmark.
2. Preparare il target con lo stesso harness di provisioning, readiness, actor e fixture probe. Ogni ripetizione usa runtime/DB pristine; vietare il riuso stateful nei confronti dichiarati ripetibili.
3. Avviare il Worker reale con il normale assignment, prompt, toolset, accesso sorgente, sessioni actor, compression, retry tecnici e persistenza delle prove. Il gate di promozione dei verdetti del Judge non viene eseguito: manca intenzionalmente l'adjudication.
4. ContinueInvestigation e boundary operativi senza verdetto mantengono la normale continuità deterministica finché il budget lo consente. Terminare alla prima proposta terminale valida ConfirmedDecision, RejectedDecision, BlockedDecision o NeedsInfoDecision, oppure per esaurimento operativo o failure tecnico. NeedsInfoDecision è un risultato da misurare: non avviare Confirmer. Non concedere retry semantici che nella run ordinaria richiedono il Judge. Registrare questa policy nella firma di confronto.
5. Salvare la proposta Worker come boundary immutabile child del candidate e conservare le prove nel ledger/outcome esistente. Se il Worker termina senza una proposta valida, conservare comunque le prove e la causa di terminazione, senza fabbricare un boundary valido. Non creare un secondo formato di outcome o archivio di raw history.

## Valutazione post-run del Worker

1. Separare correttezza della proposta e qualità/sufficienza delle prove. Un Worker che dichiara successo senza prove adeguate non ottiene credito pieno; un Worker che raccoglie prove utili ma formula una proposta errata mantiene soltanto il credito relativo alle evidenze.
2. Usare oracle di caso versionati e controlli post-run dell'harness, con revisione manuale versionata dove l'oracle non è automatizzabile. Nessun nuovo agente evaluator o chiamata al Judge nel P0. Risultati non decidibili automaticamente restano provisional/unclassified fino alla revisione.
3. La ground truth resta nei metadata dell'evaluator e non entra in prompt, descrizioni del subject o tool output. Usare, quando necessario, un insieme motivato di decisioni accettabili.
4. Esporre metriche benchmark-specifiche per la proposta e le prove, senza attestazione adjudication.role=dynamic_judge e senza impostare dynamically_confirmed nel report ordinario. Non indebolire i requisiti dell'evaluator end-to-end per adattarli a questo percorso.

## Dataset iniziale

Creare un set piccolo di candidate e ambienti riproducibili, revisionabile e versionato, riusando progetti e fixture esistenti. Le risposte sono ottenute dal Worker attraverso il runtime, non consegnate come dossier precompilati. Includere positivi confermabili, negativi con prova sufficiente e casi inconcludenti. Esempi prioritari:

- Risposta 200 che contiene una pagina di login.
- Input riflesso senza evidenza di esecuzione.
- Record legittimamente accessibile all'attore.
- Timeout o differenza temporale spiegabile dal rumore.
- Control/test con precondizioni o stato non confrontabili.
- Effetto parziale che deve rimanere suspected.
- Prova positiva sufficiente con baseline pertinente, quando richiesta.

Distinguere candidate curati da candidate provenienti da run reali mediante provenienza esplicita. Le fixture non devono inventare un successo attribuito a una run reale. Per i casi temporali usare condizioni controllabili e dichiarare i limiti di ripetibilità.

## Metriche e confrontabilità

- Proposte di conferma errate, proposte di rejection errate e gestione corretta dei casi inconcludenti, distinguendo errore da astensione.
- Quota dei candidate positivi per cui sono raccolte prove sufficienti secondo l'oracle, qualità dei controlli/baseline, necessità di informazioni e blocker ambientali.
- Costo, durata, richieste HTTP, retry, tool call e stabilità tra ripetizioni.
- Failure tecnici riportati separatamente e non contati come verdetti corretti; includere sempre i denominatori e il numero di failure.
- Proposta Worker, evidenza sufficiente/parziale/assente e termination reason esposti separatamente. Budget esaurito o assenza di prova non equivalgono a rejection.

Riutilizzare comparison_signature e label_set_version includendo harness, evaluator/oracle, candidate congelato, snapshot target, budget effettivo, policy di stop/continuità, toolset e flag di accesso sorgente. Variare il modello Worker mantenendo fissi gli altri fattori. La misura riguarda l'episodio Worker prima di qualsiasi feedback del Judge, non la resa dell'intero loop dinamico.

## Vincoli e consegna

- Contratti model-facing semplici e correnti; metadata di valutazione solo nell'evaluator.
- Payload e provenance immutabili; label ed evaluator version ricalcolabili e separati.
- Non alterare lo score end-to-end esistente né presentare lo score condizionale come equivalente.
- Implementare entry point coerenti con le convenzioni del repository; i nomi dei nuovi comandi sono una scelta locale da documentare.
- Aggiornare ARCHITECTURE.md con il percorso Worker-only, boundary congelati, policy di stop e distinzione tra proposta, prove e conferma attestata.
- Non eseguire lint sull'intero progetto.

Verificare con test mirati che nessun Judge/Confirmer/Reader/Recon venga invocato; controllare continuità su ContinueInvestigation, stop sulle proposte terminali, assenza di oracle nel subject, compatibilità commit/fixture, persistenza/lineage e separazione dei failure tecnici. Verificare che ConfirmedDecision non promuova il report ordinario a dynamically_confirmed. Eseguire uno smoke test del percorso e un confronto ripetuto su un piccolo set se provider e runtime sono disponibili. Documentare impedimenti reali senza inventare risultati.

Consegnare comandi utilizzabili, dataset iniziale e istruzioni di riproduzione, risultati delle verifiche e aggiornamento dell'architettura.

## Fuori P0

Benchmark del Judge, benchmark congiunto Worker/Judge, retry semantici guidati da supervisori, dashboard dedicate, tuning automatico, grandi matrici multi-stack, nuovi agenti e modifica del protocollo probatorio. Il P0 misura soltanto il Worker.
