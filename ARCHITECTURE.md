# Architettura del loop agentico di Lailaps

## Scopo e flusso generale

## Persistenza auditabile della run

CLI e UI condividono un unico contratto filesystem sotto
`storage/app/runs/{target}/{category}/{run_id}`. Il `run_id` è parlante e incorpora target,
categoria e timestamp filesystem-safe, per esempio `yeswiki-injection-20260822-173900`;
in caso di collisione riceve un suffisso numerico. Viene stampato all'inizio e alla fine.

La directory viene riservata prima della preparazione del target e contiene esattamente due
file con lo stesso prefisso del `run_id`:

- `{run_id}-logs.php`: header PHP con `run_id` e transcript byte-per-byte della console,
  inclusi gli eventi `LAILAPS_EVENT` usati dal feed live;
- `{run_id}-outcome.json`: oggetto con sole chiavi top-level `run_id`, `report` e
  `benchmark`. Report parziale/finale, telemetria ed environment state confluiscono nel
  campo `report`; l'evaluator aggiorna `benchmark` nello stesso file.

L'outcome viene sostituito atomicamente durante i checkpoint. Non esistono directory child,
snapshot, JSONL, raw response, manifest o file di telemetria persistenti. I body HTTP e gli
output completi dei tool restano bounded in memoria per la durata del processo. Il database
conserva lifecycle, metadati e proiezioni benchmark, ma non duplica il transcript in una
tabella eventi.

Lailaps esegue audit difensivi e autorizzati su applicazioni in un ambiente di test. Laravel prepara e valida sorgente, target e ambiente; il loop agentico Python legge il progetto assegnato e può inviare richieste HTTP soltanto al target autorizzato.

Le categorie OWASP sono elaborate in sequenza. Ogni categoria ha stato e budget propri. I risultati durevoli confluiscono infine in un report aggregato; non è presente un Judge globale.

Per ogni categoria il flusso è:

1. Category Recon costruisce una mappa iniziale focalizzata sulla categoria.
2. Reader svolge la discovery statica.
3. Confirmer approfondisce ogni lead, ricostruisce l'exploitability statica e prepara un candidate verificabile.
4. Worker verifica dinamicamente il candidate.
5. Il Reader riprende la breadth exploration; non può dichiarare conclusa la copertura.
6. Se segue un'altra categoria, Handoff Reader trasferisce il contesto riutilizzabile.

Il reasoning del modello è sempre attivo e viene configurato indipendentemente per ruolo
tramite `READER_REASONING_EFFORT`, `REVIEWER_REASONING_EFFORT`,
`CONFIRMER_REASONING_EFFORT` e `WORKER_REASONING_EFFORT`. I valori ammessi sono `low`,
`medium` e `high`; il default è rispettivamente `medium`, `low`, `high` e `high` (`high`
è il valore massimo supportato). Category Recon eredita il reasoning del Reader. L'opzione
`pentest:run --test` controlla soltanto il rebuild dell'immagine e il wire debug e non
modifica più il reasoning.

Non è attivo un protocollo positivo di `coverage_complete`: fino alla sua introduzione una
categoria che raggiunge il limite economico termina con `coverage.complete = false` e
`outcome = incomplete`. Questo evita che un ruolo di discovery trasformi autonomamente
l'assenza di nuove lead in una dichiarazione di copertura completa.

La console browser legge il transcript tramite SSE incrementale. Gli eventi di stato sono
pubblicati anche quando non esiste ancora output del processo, così una pagina aperta in coda
segue le transizioni `queued/preparing/running/finalizing` senza reload. Il client accorpa i
`reasoning_delta` consecutivi per ruolo e categoria, mantiene tool call/result distinti con il
nome del tool e rende il risultato richiudibile. I delta reasoning e output vengono emessi dal
runner accorpati per turno di modello, così il transcript conserva il contenuto completo senza
inserire un marker strutturato a ogni frammento nella console CLI. Gli eventi `usage` portano uno snapshot live
del budget economico del ruolo e della categoria (usato, limite e residuo); il pannello usa
questi snapshot durante la run e `report.telemetry` nell'outcome come fallback finale. Il feed è virtualizzato
per mantenere costante il numero di nodi DOM anche con transcript molto lunghi.

## Ruoli e output

Category Recon orienta la ricerca senza cercare finding e senza usare HTTP. Produce il
contratto breaking `CategoryRecon` v3: `schema_version`, `kind`, `status`, una lista ordinata
di una-cinque aree (`area_id`, `title`, `paths`, `next_check`) e `unknowns`. Il modello può
produrre soltanto `ready`; l'ordine delle aree è direttamente l'ordine di esplorazione. Gli
ID devono essere unici e ogni path deve esistere nel source root ed essere stato realmente
osservato nell'output di un tool Recon. La validazione resta esclusivamente strutturale e
provenienziale: nessuna funzione deterministica valuta qualità semantica, vulnerabilità o
progresso.

Con Codebase Memory disponibile Recon usa prima `codebase_architecture` e poi soltanto
`search_code_graph`; senza Codebase Memory dispone esclusivamente di `list_dir` e
`search_source`. Questi tool alimentano un registry dedicato dei path osservati, escludendo
path inesistenti, esterni al source root e identificatori `tool-output-*`. Recon non può
leggere implementazioni, eseguire command tool o usare HTTP. Se modello, provider o
validazione falliscono dopo tre retry di output riservati, l'orchestratore emette soltanto
`status=fallback`, `areas=[]` e `unknowns=["Category Recon non disponibile."]`: non recupera
output parziali e non costruisce hotspot deterministici. Prima del fallback applica anche
la policy comune di retry completo del turno modello.

Reader cerca piste statiche concrete e può produrre `ReaderLead`, `LeadEnrichment` o un checkpoint di compression richiesto dall'orchestratore. La soglia di `ReaderLead` è deliberatamente la plausibilità, non la conferma: richiede un sink o un'operazione sensibile, una source reference, un possibile input controllabile o trust boundary e un collegamento plausibile. Appena la soglia è visibile il Reader deve serializzare la lead, senza proseguire per dimostrare sanitizzazione, reachability completa, bypass delle mitigazioni, route completa, payload o piano HTTP; questi elementi restano unknown affidati al Confirmer. Una Recon `ready` fornisce la coda prioritaria ma revisionabile; con `fallback` il Reader può eseguire reconnaissance focalizzata dal briefing e non riceve supposizioni autorevoli. Quando raggiunge il boundary investigativo senza un output, l'orchestratore costruisce lo snapshot dal ledger e dagli strumenti già osservati e chiama direttamente il Reviewer. Un `continue_current` conserva integralmente conversazione, conversation ID, session ID ed epoch e accoda l'eventuale `next_focus` del Reviewer come nuova istruzione; un `pivot` apre invece una nuova epoch con la nuova direzione. Il pivot può indicare anche aree non previste dalla Recon.

Prima dell'inserimento nel ledger, ogni `ReaderLead` attraversa anche un quality gate
deterministico: titolo, ipotesi, operazione sospetta ed evidenza iniziale devono essere
informativi e non possono essere placeholder come `test`, `true`, `todo` o `unknown`; la
categoria deve coincidere con quella attiva. Un rifiuto produce un evento
`reader_output_rejected` con pressione di contesto e motivazioni e usa il retry di output
per richiedere la serializzazione concreta dalle evidenze già osservate, senza nuove letture.

Confirmer è il principale analista statico della singola lead e riceve intenzionalmente anche lead acerbe. Può svolgere discovery verticale nell'intera codebase, purché ogni ricerca resti motivata dalla lead assegnata e non diventi discovery orizzontale di vulnerabilità indipendenti. Ricostruisce source e controllabilità, propagation, sink, reachability, route, autenticazione, middleware, mitigazioni, precondizioni e piano di conferma HTTP. Gli unici esiti terminali del suo protocollo sono `CandidateHandoff` e `LeadClosure`; `ContinueInvestigation` è esclusivamente un checkpoint interno non terminale tra tranche. Una chiusura ordinaria richiede evidenza statica positiva di flusso interrotto, sanitizzazione efficace, costante sicura o irraggiungibilità. La basis `not_exploitable` distingue invece una debolezza reale per cui il threat model ricostruito dimostra l'assenza di una primitive sfruttabile, perché il controllo necessario appartiene a stato o privilegi non posseduti dall'attore; resta una lead `closed` nel bucket `rejected_inconclusive` e non può derivare dalla sola assenza di payload, conferma dinamica o budget. La controllabilità viene valutata al sink dopo trasformazioni e gate: se il contenuto pericoloso può passare soltanto quando è già presente in stato non modificabile dall'attore, e tale stato non contiene una primitive utile, il Confirmer deve chiudere esplicitamente come `not_exploitable` pur conservando nel motivo l'esistenza della costruzione insicura. Configurazioni ipotetiche fuori dal target non mantengono aperta la lead; resta invece decisiva l'incertezza evidence-backed su chi possa modificare lo stato richiesto. Non esiste più un output o uno stato `NeedsReaderEvidence`, quindi il Confirmer non può rimandare automaticamente una lead al Reader.

Dopo ogni `ContinueInvestigation` o boundary del Confirmer, l'orchestratore costruisce uno snapshot della lead e invoca lo stesso ruolo Reviewer, stateless e configurato per lo stadio Confirmer. Lo snapshot contiene il transcript deterministico accumulato dall'ultima review (alla prima review tutta la history disponibile), con limite di 6.000 caratteri per messaggio e 32.000 complessivi conservando entrambe le estremità, oltre ai metadata delle source reference iniziali e di quelle create durante il refinement e al checkpoint disponibile. Un cursore per lead ed epoch separa le tranche; compression o reset invalidano il cursore e fanno ripartire dalla history corrente. Il Reviewer sceglie semanticamente `continue_current`, `redirect` o `terminalize`. `redirect` cambia il focus senza cambiare lead; `terminalize` ricrea l'Agent sulla stessa history, epoch, conversation ID e session ID, disabilita i tool e usa uno schema che espone esclusivamente `CandidateHandoff | LeadClosure`. Se il Reviewer non è invocabile o fallisce, l'orchestratore applica deterministicamente `terminalize`, così un guasto della supervisione non finanzia altra esplorazione. Il Reviewer decide quindi la fine dell'esplorazione, non il verdetto tecnico, che resta al Confirmer. L'esclusione di `ContinueInvestigation` è strutturale e non affidata al solo prompt. Prima di ogni continuazione cross-run l'orchestratore marca come interrotta un'eventuale risposta finale con tool call non processate, rendendo valida la history per il nuovo prompt.

Ogni conversazione Confirmer ha uno `scope_id` immutabile uguale alla `lead_id`. Factory,
assignment, checkpoint di compression, snapshot e guidance del Reviewer e validator ricevono
esplicitamente quello scope e respingono mismatch. I payload includono soltanto finding,
source reference, feedback Worker, transazioni HTTP successive al marker e blocker della
lead corrente; non includono lead concorrenti o blocker globali. Il Confirmer dispone
sempre di `read_source_ref`, `search_source`, `list_dir`, `read_file` e, quando il command
executor è disponibile, `run_workspace_command` per micro-esperimenti statici non
distruttivi nel workspace read-only e `run_target_command` per osservazioni runtime
read-only strettamente necessarie alla lead, incluse query `SELECT` e comandi framework
che enumerano configurazione o stato esistente. Il Confirmer non usa il target command
per exploit, scritture o alterazioni del setup; queste restano responsabilità del Worker.
Quando Codebase
Memory è disponibile riceve anche `search_code_graph`, `get_graph_code_snippet` e
`trace_code_path`, ma non `codebase_architecture`. Ricerca testuale, listing, letture e graph
restano liberi sull'intera codebase per completare la lead, mentre `read_source_ref` resta
limitato alle reference dell'handoff attivo. Gli snippet e gli edge del grafo sono solo
indici euristici e ogni conclusione terminale deve essere verificata sul sorgente originale
con `read_file`.

Ogni ruolo, non soltanto il Confirmer, dispone di due retry completi del turno per
`UnexpectedModelBehavior`, `ModelAPIError` e l'eventuale `APIError` OpenAI non ancora
normalizzato. Questi retry sono distinti sia dai retry transport sia dai retry Pydantic di
validazione dell'output. Per il Reader il primo retry conserva l'intera history; prima del
secondo l'orchestratore richiede un `ReaderCompaction` tool-free e apre una nuova epoch dal
checkpoint risultante. Se anche la compaction fallisce, usa un checkpoint deterministico:
ledger, source reference, file osservati, CategoryRecon e stato della categoria restano
autorevoli, mentre i messaggi potenzialmente corrotti vengono eliminati. L'evento
`reader_retry_compaction` distingue summary del modello e fallback deterministico.
`UsageLimitExceeded` rappresenta invece i guardrail locali di
budget o richieste e non viene ritentato. Se il Confirmer non riesce comunque a produrre
un esito, l'orchestratore conserva la lead come `suspected/blocked` con ragione tecnica:
non viene emessa alcuna `LeadClosure` e la pista resta tra le lead pendenti. Tipo e
messaggio reali dell'errore vengono salvati nella lead, nel transcript unico, negli
eventi della run, nei blocker del report e in un warning rosso della console.

Worker usa il candidate e sessioni actor separate per verificare dinamicamente il finding. Restituisce un verdetto tipizzato: `ConfirmedDecision`, `RejectedDecision`, `BlockedDecision`, `NeedsInfoDecision` oppure `ContinueInvestigation`. Worker non modifica direttamente il ledger: l'orchestratore valida riferimenti e transazioni e applica la transizione. Dopo un enrichment statico riprende la stessa conversazione e conserva route alternative, actor e test precedenti.

Ogni transazione HTTP conserva in memoria il body completo durante la run e associa un
`response_id` stabile alla response finale osservata. Il riepilogo nel prompt contiene
status, content type, dimensione, response id e preview; il body completo resta fuori
dalla context. Il Worker dispone di `inspect_response`, `search_response`,
`read_response`, `find_json_records` (ricerca guidata case-insensitive per campi e valori)
e `query_html_response` (lxml/CSS selector sul DOM statico). `inspect_response` include
anche un riepilogo bounded delle collezioni JSON, dei campi, dei tipi e dei valori scalari
a bassa cardinalita'. JMESPath non e' esposto al Worker. Questi tool risolvono soltanto response della
propria `Deps`/run, non accettano path filesystem e non effettuano nuove richieste HTTP.
Gli output sono bounded e marcano il troncamento. Le response restano disponibili tra epoch
boundary della stessa esecuzione, ma non generano file post-run.

Worker Finalizer è stateless e tool-free. Valuta il checkpoint persistito e le transazioni già osservate quando Worker non produce un verdetto definitivo. Se fallisce anche dopo i retry riservati, il finding resta suspected con un blocco tecnico deterministico.

Handoff Reader produce il contesto riutilizzabile dalla categoria successiva. Se non termina correttamente, l'orchestratore genera l'handoff dal report e dallo stato durevole.

## Ledger e resilienza

Il ledger è la fonte canonica di lead, source reference, transazioni e finding. Ledger,
report di categoria e report aggregato usano lo schema 8; i precedenti artefatti
`CategoryRecon` v2 non vengono migrati o riletti. Solo l'orchestratore applica i verdetti
Worker. `blocked` è riservato a impedimenti ambientali, tecnici o informativi reali:
l'esaurimento locale di richieste o score non trasforma da solo una lead in blocked.

Dopo ogni tranche e transizione vengono aggiornati checkpoint, report parziale e telemetria. I fallback entrano in funzione solo dopo il turno terminale e i retry, senza ulteriori chiamate al modello. Se manca il report finale, il benchmark usa i finding durevoli del report parziale, conserva i costi effettivi e marca le metriche come parziali. Lo schema 8 aggiunge osservazioni sorgente deduplicate (`role`, `tool`, `file`, intervallo di righe) e milestone storiche `suspected`, `statically_validated` e `dynamically_confirmed`; non persiste una seconda copia degli snippet.

## Valutazione benchmark resiliente

L'evaluator benchmark v3 assegna a ogni caso il miglior livello raggiunto:
`not_reached`, `file_reached`, `anchor_reached`, `suspected`,
`statically_validated`, `dynamically_confirmed`, con valori assoluti
`0`, `0.2`, `1`, `2`, `3`, `5`. Le metriche principali di reach usano soltanto
osservazioni del Reader; le osservazioni degli altri ruoli restano nel report per
attribuzione. `file_reached` richiede codice restituito da un file con anchor e
`anchor_reached` almeno una riga sovrapposta: listing e path discovery non contano.

Lo score normalizzato è il netto dei punti dei casi positivi meno il miglior livello
dichiarativo raggiunto sui soli casi esplicitamente negativi, limitato tra zero e il
massimo `5 × positivi`. I finding senza match restano open-world e rendono
l'adjudication provisional senza penalità automatica. Detection e confirmation restano
alias compatibili rispettivamente di suspected e dynamic confirmation.
Il risultato espone inoltre recall di file/anchor/suspected/static/dynamic, confusion
matrix ai tre livelli dichiarativi, conversioni del funnel, focus di lettura Reader,
costo/token/durata per true positive e termination reason. I confronti tra ripetizioni
mostrano mediana e intervallo interquartile, durata e costo; lo score 0-100 e' confrontato
solo entro lo stesso snapshot e la stessa configurazione di benchmark.

Completezza dell'artefatto, lifecycle della run, adjudication e validità dell'ambiente
sono assi indipendenti (`artifact_state`, `run_state`, `adjudication_state`,
`environment_state`). Un report parziale usa l'intero denominatore e produce metriche
numeriche; senza report vengono materializzati casi `not_reached` ma lo score resta null.
Il finalizer ricostruisce idempotentemente il campo `benchmark` dell'outcome dal report anche
dopo failure, timeout o cancellazione. La readiness e' una misura dell'harness, non un
verdetto dell'agente: viene registrata prima della run e ripetuta con probe non mutanti al
termine, sia per sandbox locali sia per target remoti verificati. Per i benchmark, i manifest
possono dichiarare `fixture_probes` per case: sono probe interni dell'harness, non passati al
modello, eseguiti dopo setup/readiness e prima dell'avvio dell'agente. Verificano che le
precondizioni sintetiche del caso (record, form, campi, baseline innocue) siano raggiungibili
nel runtime; un fallimento invalida l'ambiente e blocca la run come setup/fixture failure,
non come mancata conferma del Worker. Il teardown avviene
soltanto dopo il probe post-run. La proiezione DB/API conserva score automatico e
adjudicated separati, livello e milestone per caso, reach, conversioni e i quattro stati
indipendenti. Le run con readiness invalida restano consultabili
ma sono escluse di default dai confronti di qualità.

La resilienza modello ha tre livelli separati: il transport OpenRouter gestisce gli errori
HTTP transitori, Pydantic AI corregge parsing e validazione nello stesso episodio, quindi
l'orchestratore può rilanciare l'intero turno due volte. Ogni tentativo viene contabilizzato
separatamente. Quando un tentativo Worker ha già prodotto transazioni HTTP, il retry riceve
il loro riepilogo e il divieto di ripeterle. La telemetria espone per ruolo retry tentati,
recuperati ed esauriti.

Un ciclo Reader esaurito apre una nuova epoch ricostruita dal ledger e dallo stato durevole.
Due cicli consecutivi esauriti, pari a sei tentativi complessivi, attivano il circuit breaker:
la categoria viene pubblicata incompleta con `termination_reason=model_unavailable`, viene
prodotto un handoff deterministico e l'audit prosegue con le categorie successive. Un output
Reader valido azzera il contatore. Recon, Reviewer, Confirmer, Worker, Finalizer e Handoff
restano contenuti nei rispettivi fallback descritti nelle sezioni dei ruoli.

## Budget e richieste

Il budget economico è creato per ciascuna categoria e non è condiviso cumulativamente dall'intero audit. Non esiste alcun hard cap cumulativo sui raw token per nessun ruolo. Input non cached, input cached e output contribuiscono allo score con pesi distinti, configurabili e registrati nel run manifest; in assenza di metriche cache affidabili, tutto l'input è conteggiato conservativamente come non cached.

Le quote di Recon, Reader, Reviewer, Confirmer, Worker e Handoff sono soft. La distribuzione predefinita del milione di punti è: Recon 40k, Reader 320k, Reviewer 80k, Confirmer 280k, Worker 240k e Handoff 40k. Una lead attiva può usare il residuo della categoria, con priorità alla produzione dell'output terminale e poi a Worker, Confirmer/enrichment, Reader discovery e Handoff. La fase terminale viene attivata prima dell'ammissione economica e della preparazione dello schema tool, così la richiesta riservata è già tool-free quando raggiunge il provider. Un turno terminale è sempre ammesso anche quando la richiesta investigativa precedente ha consumato o superato il residuo: lo sforamento è registrato separatamente come overshoot terminale e resta limitato dai request limit e dai retry di output.

I request limit sono guardrail anti-loop, non budget economici. Le estensioni Confirmer sono concesse dalla valutazione semantica del Reviewer e restano subordinate al cap economico globale. Il cap controlla esclusivamente il consumo e non esprime un verdetto tecnico: quando non consente un'altra tranche, l'orchestratore applica il fallback deterministico `reviewer_stop`, mantenendo la lead suspected. La terminalizzazione tool-free con due retry di output riservati avviene invece quando il Reviewer emette `terminalize`. Il limite della singola risposta è 5.500 token per i ruoli generici, 7.000 per Recon e 14.000 per il Confirmer. Tutti i ruoli dispongono inoltre di due retry completi per gli errori tecnici retryable del modello. Recon conserva quattro richieste investigative e dispone di tre retry Pydantic di output dedicati. Reviewer e Worker Finalizer sono già terminali e stateless. Se il budget a score termina durante un task attivo, la produzione dell'output ha priorità sul consumo raw.

Restano validi soltanto limiti token non economici: capacità della context corrente, riserva di risposta e margine di sicurezza, dimensione della singola risposta e troncamento o paginazione degli output dei tool.

## History, cache e compression

Reader mantiene una conversazione append-only per categoria, Confirmer per lead e Worker per candidate; Recon e Handoff la mantengono per il rispettivo episodio. L'uscita eccezionale da uno stream, inclusi i boundary che invocano il Reviewer, persiste tutti i messaggi già osservati prima di trasferire il controllo. Il turno terminale usa la stessa history, conversation ID e session ID, così il prefisso resta riutilizzabile dalla cache. Reviewer e Worker Finalizer restano stateless perché ricevono checkpoint già persistiti. Il Reviewer viene invocato sia sui boundary Reader sia sui checkpoint `ContinueInvestigation` del Confirmer, ma non modifica direttamente ledger o finding.

La compression dipende soltanto dalla pressione reale della context, non dallo score
economico, dalla concessione di budget extra o dal semplice completamento di un turno. Le soglie episodiche del Worker,
Confirmer e delle history generiche sono alte e lasciano integra la history a pressione
bassa/moderata; la soglia hard resta il guardrail. Quando serve, apre una nuova epoch
che conserva ledger, source reference, transazioni, response id, sessioni actor e
checkpoint investigativo. Il checkpoint Worker conserva finding e comportamento da
discriminare, precondizioni, evidenze dimostrate/escluse, interpretazioni plausibili,
inspection/query già eseguite e il prossimo esperimento, senza duplicare il raw body.
Il suo costo viene attribuito al ruolo attivo e la telemetria espone il conteggio per
ruolo.

Category Recon è l'eccezione: essendo una fase breve di quattro richieste investigative,
non usa la compression episodica. Se la history raggiunge la soglia hard del 90%,
l'orchestratore disabilita i tool e richiede immediatamente l'output terminale sulla
history integra. La telemetria registra `recon_status`, `recon_terminal_reason` e rende
quindi esplicita qualsiasi futura regressione che introducesse una compaction Recon.

## Perimetro e guardrail

### Esecuzione nel target

`run_target_command` resta l'unica primitive di command execution nel container che serve
il target HTTP. Container, utente, environment e working directory di base provengono
dall'audit e non sono parametri del modello. Le modalita' sono mutuamente esclusive:
`argv` esegue direttamente programma e argomenti senza shell; `script` usa Bash o SH solo
se la capability e' stata rilevata durante il setup.

Una invocation puo' aggiungere una mappa `files` path-relativo -> contenuto testuale. Il
command executor valida traversal, collisioni e limiti configurabili, costruisce tramite
l'API Docker un workspace casuale sotto `/tmp`, lo usa come cwd della sola invocation e
ne tenta sempre la rimozione in `finally`. L'archive accetta soltanto directory e file
regolari; non vengono creati symlink o bit executable. Senza `files` resta invariato il
working directory del target.

Il boundary pubblico restituisce un unico contract `target_runtime`: `ok`, `exit_code`,
`stdout`, `stderr`, `timed_out`, `duration_ms`, flag di troncamento separati, `fatal` ed
errore strutturato con codice e messaggio. Un exit code applicativo non-zero non e' un
errore infrastrutturale. Il timeout conserva la policy corrente: arresta il container,
produce `timed_out=true` e `fatal=true`; ogni risultato fatal imposta `hard_stop` e rende
terminali i successivi tool della run.

### Vincoli generali

## Policy operativa corrente (schema 8)

Questa sezione e' normativa e sostituisce le descrizioni legacy precedenti su quote,
terminalizzazione automatica, hard cap per-lead e compression deterministica.

Reader, Confirmer e Worker sono modelli operativi budget-unaware: non ricevono score,
costi, richieste residue o percentuali di pressione. Accounting e decisioni di
continuazione appartengono all'orchestratore e al Reviewer stateless.

Il cap economico globale per categoria e' l'unica safety net hard. Il Reviewer concede
preset accoppiati richieste/punti: Reader 16/160k, Confirmer 16/280k, Worker 8/160k;
gli episodi iniziali sono Reader 32/320k, Confirmer 16/280k e Worker 12/240k. I grant
sono sempre subordinati al residuo globale e possono essere prorogati solo entro tale
cap. Un'ultima richiesta gia' ammessa puo' produrre overshoot, che viene registrato.

Ogni boundary Reader, Confirmer o Worker passa al Reviewer. Quando il Reviewer ordina
`continue_current`, i modelli operativi riprendono la stessa history append-only, la stessa
epoch, lo stesso conversation ID e lo stesso session ID: il grant modifica esclusivamente
le allowance di richieste e punti. Un reset della history è ammesso soltanto dalla
compression controllata per pressione della context, da un pivot che abbandona il focus
corrente o dal cambio di categoria. I retry e i recovery tecnici conservano la history
parziale osservata. Un `redirect` interno alla stessa lead resta una guidance append-only;
se l'orchestratore termina l'agente o cambia scope, la conversazione successiva è invece
nuova per definizione. Per il Confirmer `terminalize` avvia sulla stessa conversazione il verdetto terminale tool-free; `stop_lead` non è esposto al modello. Il fallback deterministico `reviewer_stop` conserva il finding come suspected con lifecycle `reviewer_stopped` quando il cap globale non consente né un'altra tranche né una decisione semantica del Reviewer, rimuove la lead dalla coda attiva e restituisce il focus al Reader. Un guasto tecnico del Reviewer con cap ancora disponibile forza invece la terminalizzazione del Confirmer; se anche quel turno terminale fallisce, `blocked` conserva la lead come sospetta ed espone l'impedimento tecnico senza abortire semanticamente la pista.

Il profilo operativo predefinito e' 128k. La compression scatta al 90% della capacita'
utile della history, dopo prompt/schema, riserva output e safety margin. Sotto soglia la
history resta invariata per favorire cache hit. Confirmer e Worker usano un summarizer
LLM con prompt dedicato, checkpoint strutturato deterministico e coda raw recente; il
risultato mira al 15% della capacita' utile. I checkpoint preservano finding, source
reference, route/auth/middleware, evidenze, actor/session, request/response ID,
inspection/query e next experiment; i raw body restano soltanto in memoria durante la run.

L'isolamento del source root e del target URL, i health check, la separazione delle sessioni actor, i controlli sulle transazioni, la paginazione e i limiti di context restano invariati. Questi vincoli proteggono sicurezza e qualità, ma non sostituiscono il budget economico a score.
