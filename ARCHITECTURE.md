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
- `{run_id}-outcome.json`: oggetto con sole chiavi top-level `run_id`, `started_at`, `report` e
  `benchmark`. `started_at` è l'istante di creazione della run in formato ISO 8601 con
  timezone `Europe/Rome`; report parziale/finale, telemetria ed environment state confluiscono nel
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
5. Il Reader riprende la stessa area per cercare sink fratelli; una lead non chiude l'area.
6. Il Reader propone la chiusura dell'area e, dopo tutte le aree, della discovery; Reviewer
   e orchestratore approvano le transizioni.
7. Se segue un'altra categoria, Handoff Reader trasferisce il contesto riutilizzabile.

Il reasoning del modello è sempre attivo e viene configurato indipendentemente per ruolo
tramite `READER_REASONING_EFFORT`, `REVIEWER_REASONING_EFFORT`,
`CONFIRMER_REASONING_EFFORT`, `WORKER_REASONING_EFFORT` e `JUDGE_REASONING_EFFORT`. I valori ammessi sono `low`,
`medium` e `high`; il default è rispettivamente `medium`, `low`, `high`, `high` e `low` (`high`
è il valore massimo supportato). Category Recon eredita il reasoning del Reader. L'opzione
`pentest:run --test` controlla soltanto il rebuild dell'immagine e il wire debug e non
modifica più il reasoning.

Il protocollo positivo di completamento richiede tre passaggi: il Reader emette una
`DiscoveryCompleteProposal` soltanto quando tutte le aree risultano chiuse, il Reviewer la
approva e l'orchestratore verifica deterministicamente aree, area attiva e lead pending.
Un limite economico non soddisfa il protocollo e conserva `coverage.complete = false`.

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
contratto breaking `CategoryRecon` v4: `schema_version`, `kind`, `status`, una lista ordinata
di una-cinque aree (`area_id`, `title`, `paths`, `qualified_names`, `next_check`) e `unknowns`.
Il modello può produrre soltanto `ready`; l'ordine delle aree è direttamente l'ordine di
esplorazione. Ogni area deve avere almeno un path oppure un simbolo qualificato: i path devono
esistere nel source root ed essere stati osservati nell'output di un tool Recon, mentre i
`qualified_names` devono essere stati restituiti da Codebase Memory durante Recon. Il Reader
risolve ogni simbolo privo di path e legge il sorgente prima di produrre una lead; un riferimento
del grafo non è mai evidence per un finding. La validazione resta esclusivamente strutturale e
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

Reader cerca piste statiche concrete e può produrre `ReaderLead`, `LeadEnrichment`,
`AreaClosedProposal`, `DiscoveryCompleteProposal` o un checkpoint di compression richiesto
dall'orchestratore. La soglia di `ReaderLead` è deliberatamente la plausibilità, non la
conferma: richiede un sink o un'operazione sensibile, una source reference, un possibile
input controllabile o trust boundary e un collegamento plausibile. Appena la soglia è
visibile il Reader serializza la lead senza completare il lavoro del Confirmer. Lo stato
durevole distingue `active_area_id`, `closed_area_ids` ed esplorazione granulare: una lead
non può chiudere un'area e, dopo il relativo handoff, il Reader riprende la stessa area per
cercare sink fratelli. Solo `AreaClosedProposal`, approvata dal Reviewer, sposta
l'orchestratore alla successiva area non chiusa. Al boundary il Reviewer può continuare,
cambiare focus entro l'area o emettere `require_area_decision`, che disabilita i tool e
obbliga il Reader a serializzare una lead già sostenuta oppure una proposta terminale.
`DiscoveryCompleteProposal` richiede approvazione del Reviewer e il gate deterministico
dell'orchestratore. Un pivot verso un'altra area non bypassa la chiusura esplicita di quella
attiva.

Una `LeadEnrichment` riapre la stessa ipotesi chiusa con una source reference nuova oppure,
una sola volta senza nuovi ref, con `closure_contradiction` sostanziale che dimostri come
l'evidenza già registrata contraddica la chiusura. Un sink distinto è sempre una nuova
`ReaderLead`, anche quando condivide file o intervallo sorgente. Un errore di applicazione
del ledger su output Reader viene respinto e corretto nella stessa discovery, senza
trasformarsi in shutdown fatale dell'orchestratore.

Prima dell'inserimento nel ledger, ogni `ReaderLead` attraversa anche un quality gate
deterministico: titolo, ipotesi, operazione sospetta ed evidenza iniziale devono essere
informativi e non possono essere placeholder come `test`, `true`, `todo` o `unknown`; la
categoria deve coincidere con quella attiva. Un rifiuto produce un evento
`reader_output_rejected` con pressione di contesto e motivazioni e usa il retry di output
per richiedere la serializzazione concreta dalle evidenze già osservate, senza nuove letture.

Confirmer è il principale analista statico della singola lead e riceve intenzionalmente anche lead acerbe. Può svolgere discovery verticale nell'intera codebase, purché ogni ricerca resti motivata dalla lead assegnata e non diventi discovery orizzontale di vulnerabilità indipendenti. Ricostruisce source e controllabilità, propagation, sink, reachability, route, autenticazione, middleware, mitigazioni, precondizioni e piano di conferma. Ogni `CandidateHandoff` contiene una `ConfirmationRecipeCard` minimale e tool-neutral: actor o livello di accesso, route/canale ancorato, input o stato controllabile, sink riferito al sorgente, un test discriminante e l'oracle; la baseline è obbligatoria soltanto per gli oracle differenziali. Payload definitivo, setup completo, fixture, formato esatto dell'autenticazione, alternative e fallback restano opzionali. La card non è un workflow rigido: il Worker può adattare il trasporto al runtime. Gli identificatori dei passi e i collegamenti control/test dell'oracle sono dettagli durevoli derivati deterministicamente dall'harness quando il Confirmer li omette o li serializza in modo incoerente; al modello resta il solo obbligo semantico di fornire i passi ordinati e, per un oracle differenziale, almeno una coppia controllo/test. Il Confirmer non promuove una lead quando il Worker dovrebbe ancora scoprire route/canale, actor, input controllabile o osservazione discriminante, ma i dettagli runtime non bloccano l'handoff. La fase investigativa ammette esclusivamente `ContinueInvestigation`, che dichiara sempre `provisional_verdict` e `decisive_question`; `CandidateHandoff` e `LeadClosure` sono ammessi soltanto nel turno terminale tool-free. Quando il verdetto provvisorio è `candidate` o `closure`, l'orchestratore blocca il relativo schema terminale e i retry possono correggere soltanto la serializzazione, senza cambiare tipo di esito. Una chiusura ordinaria richiede evidenza statica positiva di flusso interrotto, sanitizzazione efficace, costante sicura o irraggiungibilità. La basis `not_exploitable` distingue invece una debolezza reale per cui il threat model ricostruito dimostra l'assenza di una primitive sfruttabile, perché il controllo necessario appartiene a stato o privilegi non posseduti dall'attore; resta una lead `closed` nel bucket `rejected_inconclusive` e non può derivare dalla sola assenza di payload, conferma dinamica o budget. La controllabilità viene valutata al sink dopo trasformazioni e gate: se il contenuto pericoloso può passare soltanto quando è già presente in stato non modificabile dall'attore, e tale stato non contiene una primitive utile, il Confirmer deve chiudere esplicitamente come `not_exploitable` pur conservando nel motivo l'esistenza della costruzione insicura. Configurazioni ipotetiche fuori dal target non mantengono aperta la lead; resta invece decisiva l'incertezza evidence-backed su chi possa modificare lo stato richiesto. Non esiste più un output o uno stato `NeedsReaderEvidence`, quindi il Confirmer non può rimandare automaticamente una lead al Reader.

Dopo ogni tranche completa e non vuota del Confirmer, l'orchestratore può invocare al massimo una volta il Reviewer stateless e tool-free. Lo snapshot contiene lead originale senza Recipe Card completa, ultimo checkpoint, `provisional_verdict`, `decisive_question`, nuove source reference, probe della tranche e indicatori deterministici di ripetizione; non consegna l'intera storia cumulativa né excerpt estesi del codice. Una tranche senza transcript non genera una review. Il Reviewer è esclusivamente un supervisore di convergenza, usa di default `google/gemini-3.7-flash`, ha un cap dedicato di 2.000 token e sceglie `continue_current` o `force_verdict`: il primo richiede una questione decisiva aperta e un passo nuovo capace di cambiarne l'esito; il secondo obbliga il Confirmer, sulla stessa history, epoch, conversation ID e session ID ma con tool disabilitati, a serializzare il verdetto provvisorio nel relativo schema terminale. `force_verdict` non costituisce un verdetto tecnico. Un verdetto provvisorio senza questione decisiva o la ripetizione della stessa questione senza nuova evidenza attivano deterministicamente `force_verdict`; anche un guasto del Reviewer con budget disponibile usa questo fallback. Il Reviewer del Reader resta invece distinto e conserva guidance direzionale e `next_focus`. L'esclusione di `ContinueInvestigation` dal turno terminale Confirmer è strutturale e non affidata al solo prompt. Prima di ogni continuazione cross-run l'orchestratore marca come interrotta un'eventuale risposta finale con tool call non processate, rendendo valida la history per il nuovo prompt.

Ogni conversazione Confirmer ha uno `scope_id` immutabile uguale alla `lead_id`. Factory,
assignment, checkpoint di compression, snapshot e guidance del Reviewer e validator ricevono
esplicitamente quello scope e respingono mismatch. I payload includono soltanto finding,
source reference, feedback Worker, transazioni HTTP successive al marker e blocker della
lead corrente; non includono lead concorrenti o blocker globali. Il Confirmer dispone
sempre di `read_source_ref`, `search_source`, `list_dir`, `read_file` e, quando il command
executor è disponibile, `run_workspace_command` per micro-esperimenti statici non
distruttivi nel workspace read-only, `query_database` per `SELECT` strutturate tramite il
dossier runtime persistente e `run_target_command` per le altre osservazioni runtime
read-only strettamente necessarie alla lead nel servizio logico scelto esplicitamente da un
enum generato dai container della sandbox, inclusi comandi framework che enumerano
configurazione o stato esistente. Dispone inoltre di `run_target_probe` per
micro-esperimenti deterministici descritti da `argv` e da uno script inline oppure file temporanei: l'executor risolve
automaticamente l'executable tra i container running con lo stesso audit id, esclude il
toolbox e non espone al modello la scelta del servizio. Un runtime assente produce
`RUNTIME_NOT_FOUND` e conclude la discovery dell'executable. Il Confirmer non usa i target
command per exploit, scritture o alterazioni del setup; queste restano responsabilità del
Worker.
Quando Codebase
Memory è disponibile riceve anche `search_code_graph`, `get_graph_code_snippet` e
`trace_code_path`, ma non `codebase_architecture`. Ricerca testuale, listing, letture e graph
restano liberi sull'intera codebase per completare la lead, mentre `read_source_ref` resta
limitato alle reference scoped alla lead attiva. Ogni reference prodotta da `read_file` o
`search_source` durante il refinement entra immediatamente nel registry canonico della lead
e diventa recuperabile on demand. Range e `sha256` validati costituiscono verifica durevole
del sorgente originale anche dopo compression: una rilettura è necessaria soltanto per un
range diverso, un hash cambiato o una contraddizione concreta. Gli snippet e gli edge del
grafo restano indici euristici e non acquisiscono questa validità.

Ogni ruolo, non soltanto il Confirmer, dispone di due retry completi del turno per
`UnexpectedModelBehavior`, `ModelAPIError` e l'eventuale `APIError` OpenAI non ancora
normalizzato. Questi retry sono distinti sia dal singolo retry transport del provider sia dai retry Pydantic di
validazione dell'output. Un errore riconducibile a context/output token exhaustion forza
immediatamente la compression della conversation operativa prima del primo retry e riduce
il reasoning effort del tentativo successivo; gli altri errori tecnici seguono il retry
ordinario. Dopo token exhaustion l'orchestratore calcola una fingerprint di prompt,
history e settings e non reinvia un tentativo ancora identico; i retry di errori provider
transitori possono invece conservare il payload. Un rifiuto locale del guardrail input del
Reviewer avviene prima di contattare il provider e non entra nella retry ladder: lo snapshot
viene già adattato prima dell'admission e un ulteriore rifiuto attiva direttamente il fallback
conservativo. Ogni tentativo Reviewer, inclusi i retry di validazione dell'output, ripassa
dallo stesso fitting; il retry context è strutturato, limita il messaggio d'errore e sostituisce
quello precedente invece di accodare nuovamente l'intero prompt. La soppressione viene
registrata nella telemetria. Ogni rifiuto di validazione Pydantic, per tutti i ruoli, è inoltre
stampato e persistito come `[role:validation-retry] <motivo>` / evento `validation_retry` prima
del retry. Il client HTTP del provider applica un timeout duro configurabile di 120 secondi per
richiesta, incluso il canale stealth. Per il Reader
il primo retry conserva l'intera history; prima del
secondo l'orchestratore richiede un `ReaderCompaction` tool-free e apre una nuova epoch dal
checkpoint risultante. Se anche la compaction fallisce, usa un checkpoint deterministico:
ledger, source reference, file osservati, CategoryRecon e stato della categoria restano
autorevoli, mentre i messaggi potenzialmente corrotti vengono eliminati. L'evento
`reader_retry_compaction` distingue summary del modello e fallback deterministico.
`UsageLimitExceeded` rappresenta invece i guardrail locali di budget o richieste e non
viene ritentato dall'orchestratore. Durante la serializzazione terminale del Confirmer le
risposte raw vivono fuori dalla history comprimibile: l'orchestratore recupera soltanto un
`CandidateHandoff` o `LeadClosure` pienamente valido per schema, scope e source reference.
Se i cinque tentativi terminali terminano senza un output recuperabile, la lead resta
`suspected` con lifecycle `terminal_output_exhausted`; gli output emessi vengono persistiti
nel finding e il limite locale non produce un blocker tecnico.

Worker usa il candidate e sessioni actor separate per verificare dinamicamente il finding. Il contratto minimo Confirmer→Worker non richiede più una Recipe Card annidata: `verification_plan`, `success_signal` e `rejection_signal` sono tre stringhe piatte obbligatorie, complete ma adattabili al runtime. La Recipe Card strutturata è un acceleratore opzionale; se valida il Worker può seguirla dal primo step non completato, mentre se è assente l'harness proietta deterministicamente il piano piatto in una recipe compatibile a singolo step. Una recipe opzionale malformata non blocca la promozione di un candidate che soddisfa il nucleo statico e i tre campi piatti. Il Worker conserva l'evidenza, non ripete test riusciti e deve comunque raccogliere una baseline per prove differenziali o temporali. `ContinueInvestigation` persiste gli eventuali identificatori degli step strutturati completati, oltre al progresso narrativo e al prossimo esperimento già presenti nel checkpoint. L'handoff iniziale non duplica gli snippet integrali raccolti dal Confirmer: consegna il piano di conferma e i metadata delle source reference autorizzate, mentre `read_source_ref` permette al Worker di recuperare on demand soltanto il codice necessario al prossimo test. L'access set del Worker include deterministicamente sia `finding.source_ref_ids` sia ogni source reference citata nella proiezione del piano, così un ref usato dal piano è sempre recuperabile. I candidate storici con Recipe Card o campi setup/baseline/exploit restano adattati in lettura. Se i metadata eccedono il budget del singolo prompt, l'orchestratore conserva tutti gli identificatori recuperabili e rimuove la proiezione ridondante di path e range. Quando il command executor è disponibile il Worker riceve sia `query_database`, per `SELECT` strutturate tramite il dossier runtime persistente, sia `run_target_command` per altre osservazioni read-only strettamente pertinenti nel servizio scelto dall'enum, incluse configurazione, route e verifica di effetti già prodotti tramite l'applicazione; non può usarli per fabbricare direttamente l'impatto. Il contratto di `run_target_command` rende obbligatori sia `argv` sia `script`: la modalità non scelta viene rappresentata rispettivamente da lista o stringa vuota, evitando parametri opzionali nello schema esposto al modello. Ogni ruolo che dispone di `run_target_command` dispone anche di `query_database`. Il Worker restituisce una proposta tipizzata (`ConfirmedDecision`, `RejectedDecision`, `BlockedDecision`, `NeedsInfoDecision` oppure `ContinueInvestigation`) che non è autoritativa e non produce alcuna transizione terminale. Dopo un enrichment statico riprende la stessa conversazione e conserva route alternative, actor e test precedenti.

Con `WORKER_SOURCE_ACCESS_ENABLED` attivo, il Worker dispone inoltre di `search_source`,
`list_dir` e `read_file` sull'intero `source_root`. L'accesso resta verticale e vincolato alla
lead assegnata: serve a risolvere grammatica, route, trasformazioni, schema e mitigazioni che
rendono discriminante il prossimo probe, non a svolgere discovery orizzontale di finding
indipendenti. Le letture producono normali source reference canoniche e rispettano gli stessi
limiti di path e output degli altri ruoli. Il flag è attivo di default ma resta disabilitabile
per confronti A/B e rollback senza cambiare modello o architettura restante.

Il working set iniziale costituisce l'eccezione alla proiezione metadata-only descritta sopra:
include gli snippet delle sole source reference scelte esplicitamente dal Confirmer nel
`CandidateHandoff`. Le reference aggiunte deterministicamente per preservare la provenance
statica restano nel ledger ma non ampliano automaticamente il working set del Worker. Se gli
snippet non entrano nel guardrail del prompt, l'assignment torna ai relativi metadata e mantiene
`read_source_ref` disponibile on demand. I candidate storici privi della selezione separata usano
per compatibilita' le reference del finding.

Ogni transazione HTTP conserva in memoria il body completo durante la run e associa un
`response_id` stabile alla response finale osservata. Il riepilogo nel prompt contiene
status, durata end-to-end in millisecondi, content type, dimensione, response id e preview;
durata e content length restano anche nella transazione e nello snapshot della response,
così il Worker può costruire oracle differenziali time-based. Il body completo resta fuori
dalla context. Il Worker dispone di `inspect_response`, `search_response`,
`read_response`, `find_json_records` (ricerca guidata case-insensitive per campi e valori)
e `query_html_response` (lxml/CSS selector sul DOM statico). `inspect_response` include
anche un riepilogo bounded delle collezioni JSON, dei campi, dei tipi e dei valori scalari
a bassa cardinalita'. JMESPath non e' esposto al Worker. Questi tool risolvono soltanto response della
propria `Deps`/run, non accettano path filesystem e non effettuano nuove richieste HTTP.
Gli output sono bounded e marcano il troncamento. Le response restano disponibili tra epoch
boundary della stessa esecuzione, ma non generano file post-run.

Dynamic Judge è stateless, tool-free e separato dal Worker. Viene invocato obbligatoriamente dopo ogni uscita Worker: proposte confirmed/rejected, output parziali, boundary, errori e assenza di una tranche finanziabile. Usa knob indipendenti `JUDGE_MODEL` e `JUDGE_REASONING_EFFORT` (default `google/gemini-3.7-flash`, `low`), così l'adjudication può usare un modello diverso dal Worker; applica le regole allo snapshot, mentre la strictness delle prove è nel gate deterministico. È l'unica autorità semantica che può emettere `approve_confirmed` o `approve_rejected`; l'orchestratore resta l'unico componente che applica materialmente il voto al ledger. Nessuna lead può quindi diventare dinamicamente `confirmed` o `rejected` sulla sola proposta Worker.

Il Judge può inoltre emettere `keep_suspected`, `retry_worker`, `request_static_enrichment` o `blocked`. `retry_worker` concede una tranche specifica per un esperimento nuovo e discriminante, entro `dynamic_judge_requeue_limit` e il budget residuo. `request_static_enrichment` porta la stessa lead al Confirmer tramite `needs_confirmer_evidence`; un CandidateHandoff revisionato torna poi a Worker e nuovamente al Judge. L'esaurimento del budget o l'impossibilità di finanziare un retry conserva la lead come suspected e non costituisce mai evidenza di rejection.

Prima di applicare un voto terminale, un gate deterministico verifica scope della lead, esistenza delle transazioni, assenza di placeholder, control richiesto dall'eventuale Recipe Card strutturata, differenze di response/actor e soglia del delta per gli oracle temporali. La natura differenziale o temporale dichiarata dal Judge richiede comunque una baseline distinta anche quando il candidate contiene soltanto il piano piatto. Il report persiste un'attestazione `adjudication.role=dynamic_judge`, la decisione, trace e versione del gate. Anche l'evaluator benchmark riconosce `dynamically_confirmed` soltanto quando questa attestazione è presente e valida.

Handoff Reader produce il contesto riutilizzabile dalla categoria successiva. Se non termina correttamente, l'orchestratore genera l'handoff dal report e dallo stato durevole.

## Ledger e resilienza

Il ledger è la fonte canonica di lead, source reference, transazioni e finding. Ledger,
report di categoria e report aggregato usano lo schema 8; i precedenti artefatti
`CategoryRecon` v2 non vengono migrati o riletti. Solo l'orchestratore applica al ledger i
verdetti attestati dal Dynamic Judge; gli output Worker restano proposte. `blocked` è
riservato a impedimenti ambientali, tecnici o informativi reali:
l'esaurimento locale di richieste o score non trasforma da solo una lead in blocked.

Le source reference create durante una tranche Confirmer sono scoped alla lead attiva e
vengono unite deterministicamente all'output terminale: `CandidateHandoff` e `LeadClosure`
non possono eliminare quelle iniziali o quelle nuove il cui file è citato nei campi
strutturati riscrivendo una lista incompleta. Anche le osservazioni sorgente
persistono il `lead_id`; l'evaluator benchmark può quindi ripristinare location scoped
omesse dalla serializzazione terminale senza usare `files_seen` come prova di finding. Per
i report schema 8 storici privi di `lead_id` sull'osservazione, il recupero è ammesso solo
quando l'intero report contiene un unico finding e quindi l'attribuzione non è ambigua.

Dopo ogni tranche e transizione vengono aggiornati checkpoint, report parziale e telemetria. I fallback entrano in funzione solo dopo il turno terminale e i retry, senza ulteriori chiamate al modello. Se manca il report finale, il benchmark usa i finding durevoli del report parziale, conserva i costi effettivi e marca le metriche come parziali. Lo schema 8 aggiunge osservazioni sorgente deduplicate (`role`, `lead_id`, `tool`, `file`, intervallo di righe) e milestone storiche `suspected`, `statically_validated` e `dynamically_confirmed`; non persiste una seconda copia degli snippet.

## Valutazione benchmark resiliente

L'evaluator benchmark v3 assegna a ogni caso il miglior livello raggiunto:
`not_reached`, `file_reached`, `anchor_reached`, `suspected`,
`statically_validated`, `dynamically_confirmed`, con valori assoluti
`0`, `0.2`, `1`, `2`, `3`, `5`. Le metriche principali di reach usano soltanto
osservazioni del Reader; le osservazioni degli altri ruoli restano nel report per
attribuzione e, quando portano lo stesso `lead_id` di un finding e il file è citato dal
finding strutturato, possono ripristinarne le location prima del matching. `file_reached`
richiede codice restituito da un file con anchor e
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

La contabilita' USD e' parallela al budget a punti e non ne modifica ammissione, quote o
overshoot. Il transport osserva il solo oggetto `usage` finale di OpenRouter e somma il
costo addebitato senza persistere prompt o output; se il provider non lo espone, un piccolo
listino versionato calcola il fallback separando input non cached, cache read e output.
`cost_source_counts` dichiara la provenienza; se mancano sia usage sia listino il costo resta
`null`, mai zero implicito. Il report registra anche catalog version/date e la stima da
listino, cosi' il dato fatturato e quello ricostruibile restano distinguibili.

La V0 di confronto attribuisce a ogni lead disposition finale, durata, punti, USD, model
request, tool call, richieste HTTP e bucket token, con breakdown per ruolo. La tranche Reader
che crea la lead viene trasferita alla lead stessa; Confirmer e Worker sono attribuiti tramite
lo scope durevole `active_lead_id`. Il benchmark conserva questi record in `cost.lead_usage`
e confronta le run soltanto a parita' di oracle/snapshot. I modelli richiesti ed effettivi e
il reasoning effort sono registrati in `audit_run_models` per Reader, Reviewer, Confirmer,
Worker e Dynamic Judge; il provider non e' parte dell'identita' sperimentale.

Per valutare l'accesso sorgente del Worker, la telemetria espone anche i tool call nominativi
per ruolo e per lead, il flag effettivo, la breadth sorgente per ruolo, il tempo, i model
request e i bucket token precedenti alla prima HTTP del Worker. Un evento bounded descrive
ogni round Worker→Judge con nuove transazioni HTTP, nuove source reference, causa di uscita e
decisione del Judge; contatori aggregati distinguono round senza nuova evidenza, richieste di
enrichment statico e cicli completi Worker→Judge→Confirmer→Worker. I boundary Reader,
Confirmer e Worker sono contati per ruolo e separano quelli privi di nuova evidenza. Le review
del Confirmer espongono la distribuzione delle decisioni e quante `force_verdict` coincidono
con un provvisorio `candidate` o `closure` già formulato.

I casi `evaluation_mode=disposition` verificano outcome semantici stabili, per esempio una
debolezza reale chiusa `not_exploitable`, ma sono esclusi da recall, confusion matrix e score
delle vulnerabilita'. Espongono una `disposition.accuracy` separata: in questo modo `tagrss`
non viene reinterpretata ne' come CVE confermata ne' come falso positivo.

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
Reader valido azzera il contatore. Recon, Reviewer, Confirmer, Worker, Dynamic Judge e Handoff
restano contenuti nei rispettivi fallback descritti nelle sezioni dei ruoli.

## Budget e richieste

Il budget economico è creato per ciascuna categoria e non è condiviso cumulativamente dall'intero audit. Non esiste alcun hard cap cumulativo sui raw token per nessun ruolo. Input non cached, input cached e output contribuiscono allo score con pesi distinti, configurabili e registrati nel run manifest; in assenza di metriche cache affidabili, tutto l'input è conteggiato conservativamente come non cached. `pentest:run` e `benchmark:run` accettano `--budget-category=small|regular|big|huge`: i preset applicano rispettivamente 0,5x, 1x, 1,5x e 2x al cap configurato in `settings/.env`; senza opzione resta valido il cap configurato. Il moltiplicatore scala proporzionalmente anche le quote soft dei ruoli. Il preset, il moltiplicatore e il cap effettivo in punti vengono persistiti nella telemetry della categoria e nel risultato benchmark per ricostruire il budget della run.

Le quote di Recon, Reader, Reviewer, Confirmer, Worker e Handoff sono soft. Il Dynamic Judge usa la quota Worker perché è parte obbligatoria di ogni episodio dinamico. La distribuzione predefinita del milione di punti è: Recon 40k, Reader 320k, Reviewer 80k, Confirmer 280k, Worker con Judge 240k e Handoff 40k. Una lead attiva può usare il residuo della categoria, con priorità alla produzione dell'output terminale e poi a Worker, Confirmer/enrichment, Reader discovery e Handoff. La fase terminale viene attivata prima dell'ammissione economica e della preparazione dello schema tool, così la richiesta riservata è già tool-free quando raggiunge il provider. Un turno terminale è sempre ammesso anche quando la richiesta investigativa precedente ha consumato o superato il residuo: lo sforamento è registrato separatamente come overshoot terminale e resta limitato dai request limit e dai retry di output.

I request limit sono guardrail anti-loop, non budget economici. Il Confirmer riceve una tranche iniziale da 16 richieste e ogni estensione approvata è una nuova tranche completa da 16 con grant di 280.000 punti; non esiste un tier follow-up corto. I retry Worker sono richiesti dal Dynamic Judge. Entrambi restano subordinati al cap economico globale. Il cap controlla esclusivamente il consumo e non esprime un verdetto tecnico: quando non consente un'altra tranche, l'orchestratore conserva la lead suspected (`reviewer_stop` nel percorso statico, `judge_stopped` nel percorso dinamico). La terminalizzazione tool-free con quattro retry di output riservati, cinque richieste totali, avviene invece quando il Reviewer emette `force_verdict`. Il limite della singola risposta è 5.500 token per i ruoli generici, 2.000 per Reviewer, 7.000 per Recon e 14.000 per il Confirmer. Il guardrail predefinito del singolo prompt è 48.000 token stimati, inclusi prompt persistente e schemi: resta distinto dalla finestra operativa da 128k e impedisce payload anomali senza rifiutare handoff legittimi già proiettati. Un rifiuto locale `PromptInputGuardExceeded` è deterministico e non viene reinviato identico nella retry ladder. La capacità utile della history viene calcolata per ruolo e fase sottraendo il limite reale della risposta, oltre al prompt persistente e al margine di sicurezza; non usa quindi una riserva generica inferiore al cap del ruolo. Prima della terminalizzazione Confirmer/Worker un preflight verifica che history, nuovo prompt e riserva di risposta entrino nella finestra effettiva: quando non entrano comprime la stessa conversation, senza sostituirla con il solo checkpoint. Tutti i ruoli dispongono inoltre di due retry completi per gli errori tecnici retryable del modello. Recon conserva quattro richieste investigative e dispone di tre retry Pydantic di output dedicati. Reviewer e Dynamic Judge sono terminali, tool-free e stateless. Se il budget a score termina durante un task attivo, la produzione dell'output ha priorità sul consumo raw.

Il Worker costituisce inoltre un'eccezione al cap generico di 5.500 token: usa un limite dedicato
di 14.000 token, riservato nel calcolo della capacita' di history, per evitare retry completi
causati dall'esaurimento dell'output. Il Dynamic Judge conserva il cap generico.

Nel retry successivo a una token exhaustion, il Reviewer usa sempre reasoning `low`; gli
altri ruoli continuano a usare l'effort di retry configurato.

Dopo la lavorazione completa di una lead, il Reader riparte soltanto se la sua allowance
residua finanzia almeno due turni medi della grant policy: uno per ottenere nuova evidenza
e uno per serializzare una lead. Una coda inferiore termina come
`insufficient_reader_tail_budget`, evitando epoch che possono consumare il residuo ma non
produrre un nuovo output durevole.

Restano validi soltanto limiti token non economici: capacità della context corrente, riserva di risposta e margine di sicurezza, dimensione della singola risposta e troncamento o paginazione degli output dei tool.

## History, cache e compression

Reader mantiene una conversazione append-only per categoria, Confirmer per lead e Worker per candidate; Recon e Handoff la mantengono per il rispettivo episodio. L'uscita eccezionale da uno stream, inclusi i boundary che invocano il Reviewer o il Dynamic Judge, persiste tutti i messaggi già osservati prima di trasferire il controllo. Il turno terminale usa la stessa history, conversation ID e session ID, così il prefisso resta riutilizzabile dalla cache. Reviewer e Dynamic Judge restano stateless perché ricevono checkpoint già persistiti. Il Reviewer viene invocato sui boundary Reader e sui checkpoint `ContinueInvestigation` del Confirmer, ma non appartiene al percorso Worker e non modifica direttamente ledger o finding. Il Judge riceve invece lo snapshot durevole dell'episodio Worker e la porzione scoped del log HTTP.

La compression dipende soltanto dalla pressione reale della context, non dallo score
economico, dalla concessione di budget extra o dal semplice completamento di un turno.
Una history lunga con prefisso cacheabile è economicamente preferibile a una history corta
ricostruita: la compression è un fallback di qualità e capienza, non un'ottimizzazione del
costo. Confirmer comprime all'85% della propria capacità utile e punta a una history
post-compression non superiore al 35%; Worker comprime al 75% e punta al 25%. La soglia
hard resta al 90%. La memoria risultante mantiene come componente primaria un summary
semantico LLM esteso (fino a 8.000 token per Confirmer e 6.000 per Worker), affiancato dal
checkpoint deterministico e da una coda raw rispettivamente di 5.000 e 4.000 token. Il
summary preserva catena source-propagation-sink, controllabilità, route e security gate,
mitigazioni, fatti dimostrati, unknown risolti/residui, ipotesi escluse, micro-probe,
source reference, feedback, cambi di strategia e piano dinamico; può eliminare tool output
duplicati, discovery ripetitiva e istruzioni superate. Il limite di input del summarizer
non è configurato separatamente: deriva dalla maggiore capacità utile di prompt già
assegnata ai ruoli operativi. Se la history serializzata la supera, viene divisa per confini
di messaggio, riassunta per chunk e ridotta in un unico checkpoint semantico.
Quando serve, apre una nuova epoch locale che conserva il `session_id` provider nelle
compression ordinarie, oltre a ledger, source reference, transazioni, response id, sessioni
actor e checkpoint investigativo. Una compression forzata dal recovery tecnico può invece
ruotare anche la sessione. Il checkpoint Worker conserva finding e comportamento da
discriminare, precondizioni, evidenze dimostrate/escluse, interpretazioni plausibili,
inspection/query già eseguite e il prossimo esperimento, senza duplicare il raw body.
Il suo costo viene attribuito al ruolo attivo e la telemetria espone per ogni evento ruolo,
token prima/dopo, token del summary, modalità `llm_summary` o
`deterministic_fallback` e l'eventuale carattere forzato dal retry/preflight.

Category Recon è l'eccezione: essendo una fase breve di quattro richieste investigative,
non usa la compression episodica. Se la history raggiunge la soglia hard del 90%,
l'orchestratore disabilita i tool e richiede immediatamente l'output terminale sulla
history integra. La telemetria registra `recon_status`, `recon_terminal_reason` e rende
quindi esplicita qualsiasi futura regressione che introducesse una compaction Recon.

## Perimetro e guardrail

Nei benchmark la vista sorgente materializzata per l'agente e il runtime di seeding sono
perimetri distinti. I path esclusi da `agent_view` vengono filtrati anche dagli overlay del
catalogo; dump SQL e altre fixture ground-truth restano nel runtime harness esterno a
`/workspace` e sono forniti a Compose soltanto tramite environment risolto dal descriptor.
Il seeder importa i dati e rimuove il dump dal proprio filesystem prima dell'avvio del loop:
l'agente può osservare lo stato risultante con query `SELECT` tramite `query_database`,
ma non leggere il file usato per costruirlo.

### Esecuzione nel target

All'avvio il command executor costruisce una sola volta un dossier runtime persistente per
la run. Lo snapshot elenca i servizi target e le connessioni database riconosciute tramite
le label dell'audit, l'environment runtime e i client presenti nei container. Le
credenziali restano in una struttura privata dell'executor; la vista inserita nei prompt
Confirmer e Worker contiene soltanto connection id, engine, servizio, nome database,
client risolto e capability read-only. Lo stesso snapshot sopravvive a nuove lead,
compression, epoch e sessioni actor perché appartiene alle dipendenze condivise della run.

`query_database` accetta un connection id appartenente all'enum del dossier, una sola
`SELECT` o `WITH ... SELECT`, `max_rows` e timeout. Non espone al modello servizio,
credenziali o argv. L'executor rifiuta statement multipli e costrutti mutanti, avvia la
sessione database in modalità read-only quando il motore lo consente, applica timeout e
wrapping `LIMIT max_rows+1`, quindi normalizza XML MySQL/MariaDB, CSV PostgreSQL o JSON
SQLite nel contratto `database`: colonne, righe, conteggio, durata, troncamento ed errore
tipizzato. Un timeout DB non arresta il target. Il guard SQL e la transazione read-only
sono difesa in profondità; l'eventuale principal SELECT-only resta responsabilità del
provisioning e non viene dichiarato dal dossier se non esiste realmente.

`run_target_command` è la primitive di osservazione read-only in un servizio esplicito
della sandbox. Prima di ogni model request il suo schema riceve dinamicamente l'enum dei
valori `sandbox.service` appartenenti a container running con lo stesso audit id; toolbox,
container estranei o arrestati sono esclusi. Il modello seleziona soltanto il nome logico,
mai container id, utente, environment o working directory. L'executor rivalida comunque
audit, stato e unicità del servizio a ogni invocation. Le modalità sono mutuamente
esclusive: `argv` esegue direttamente programma e argomenti senza shell; `script` usa Bash
o SH solo se presente nel servizio selezionato. `SERVICE_NOT_FOUND` e
`SERVICE_AMBIGUOUS` sono errori recuperabili e non fatali.

Il solo `run_target_probe` accetta esattamente una tra la mappa `files` path-relativo ->
contenuto testuale e la modalità `script` inline con `filename`; in quest'ultima modalità il
filename viene aggiunto deterministicamente ad `argv` quando manca. Chiamate vuote ricevono
un esempio completo e il secondo tentativo identico viene bloccato. Il command executor valida traversal, collisioni e limiti configurabili,
costruisce tramite l'API Docker un workspace casuale sotto `/tmp`, lo usa come cwd della
sola invocation e ne tenta sempre la rimozione in `finally`. L'archive accetta soltanto
directory e file regolari; non vengono creati symlink o bit executable. Il probe non
espone il servizio: risolve automaticamente `argv[0]` tra i servizi validi dell'audit.
Il Confirmer applica una policy probe-first: quando parser, lexer, regex, escaping, encoding
o sanitizzazione hanno comportamento deterministico, un micro-esperimento minimo precede
ulteriori letture laterali della stessa catena. Il Worker applica lo stesso principio sul
piano dinamico eseguendo appena possibile la minima richiesta HTTP discriminante, o la
coppia control/test richiesta dall'oracle, senza ottenere per questo `run_target_probe`.

Il boundary pubblico restituisce un unico contract `target_runtime`: `ok`, `exit_code`,
`stdout`, `stderr`, `timed_out`, `duration_ms`, flag di troncamento separati, `fatal` ed
errore strutturato con codice e messaggio. Un exit code applicativo non-zero non e' un
errore infrastrutturale. Il timeout di un comando nel target non arresta il container e
produce `timed_out=true` con `fatal=false`, cosi' il Worker puo' trattarlo come evidenza
di lentezza o di un esperimento inconcludente. `hard_stop` resta riservato agli errori
Docker API/executor, a un container target exited e alla perdita di readiness; ogni risultato
fatal rende terminali i successivi tool della run.

### Vincoli generali

## Policy operativa corrente (schema 8)

Questa sezione e' normativa e sostituisce le descrizioni legacy precedenti su quote,
terminalizzazione automatica, hard cap per-lead e compression deterministica.

Reader, Confirmer e Worker sono modelli operativi budget-unaware: non ricevono score,
costi, richieste residue o percentuali di pressione. Accounting appartiene
all'orchestratore; le decisioni di continuazione spettano al Reviewer per Reader/Confirmer
e al Dynamic Judge per Worker.

Il cap economico globale per categoria e' l'unica safety net hard. Il Reviewer concede
preset accoppiati richieste/punti a Reader e Confirmer; il Dynamic Judge può richiedere
una tranche Worker 8/160k. Gli episodi iniziali sono Reader 32/320k, Confirmer 16/280k e
Worker con Judge 12/240k. La tranche iniziale Confirmer e ogni estensione sono 16/280k;
non esistono micro-tranche follow-up. I grant
sono sempre subordinati al residuo globale: una tranche Confirmer parte soltanto se il
residuo può finanziarla per intero. Un'ultima richiesta gia' ammessa puo' produrre
overshoot, che viene registrato.

Ogni boundary Reader passa al Reviewer; il Confirmer lo invoca al massimo una volta dopo
una tranche completa e non vuota. Ogni output Worker, incluso un output
parziale, un errore o una proposta terminale, passa obbligatoriamente al Dynamic Judge.
Quando il supervisore competente ordina di continuare, i modelli operativi riprendono la stessa history append-only, la stessa
epoch, lo stesso conversation ID e lo stesso session ID: il grant modifica esclusivamente
le allowance di richieste e punti. Un reset della history è ammesso soltanto dalla
compression controllata per pressione della context, da un pivot che abbandona il focus
corrente o dal cambio di categoria. I retry e i recovery tecnici conservano la history
parziale osservata. Il pivot/`next_focus` del Reviewer Reader resta una guidance append-only;
se l'orchestratore termina l'agente o cambia scope, la conversazione successiva è invece
nuova per definizione. Per il Confirmer `force_verdict` avvia sulla stessa conversazione il verdetto terminale tool-free; `stop_lead` non è esposto al modello. Il fallback deterministico `reviewer_stop` conserva il finding come suspected con lifecycle `reviewer_stopped` quando il cap globale non consente né un'altra tranche né una decisione semantica del Reviewer, rimuove la lead dalla coda attiva e restituisce il focus al Reader. Un guasto tecnico del Reviewer con cap ancora disponibile forza invece la terminalizzazione del Confirmer; l'esaurimento del solo output terminale conserva raw output e lead suspected come `terminal_output_exhausted`, mentre gli errori infrastrutturali distinti continuano a poter produrre `blocked`.

Il profilo operativo predefinito e' 128k. Dopo prompt/schema, riserva output e safety
margin, il Confirmer comprime all'85% della capacità utile e punta al 35%; il Worker usa
75% e 25%. La soglia hard resta al 90%. Sotto la soglia del ruolo la history resta
invariata per favorire cache hit. Confirmer e Worker usano un summarizer LLM con prompt
dedicato, checkpoint strutturato deterministico e coda raw recente. I checkpoint preservano finding, source
reference, route/auth/middleware, evidenze, actor/session, request/response ID,
inspection/query e next experiment; i raw body restano soltanto in memoria durante la run.

L'isolamento del source root e del target URL, i health check, la separazione delle sessioni actor, i controlli sulle transazioni, la paginazione e i limiti di context restano invariati. Questi vincoli proteggono sicurezza e qualità, ma non sostituiscono il budget economico a score.
