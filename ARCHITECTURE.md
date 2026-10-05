# Architettura del loop agentico di Lailaps

## Scopo e flusso generale

## Persistenza auditabile della run

CLI e UI condividono un unico contratto filesystem sotto
`storage/app/runs/{target}/{category}/{run_id}`. Il `run_id` è parlante e incorpora target,
categoria e timestamp filesystem-safe, per esempio `yeswiki-injection-20260822-173900`;
in caso di collisione riceve un suffisso numerico. Viene stampato all'inizio e alla fine.

La directory viene riservata prima della preparazione del target e contiene due file
autorevoli con lo stesso prefisso del `run_id`:

- `{run_id}-logs.php`: header PHP con `run_id` e transcript byte-per-byte della console,
  inclusi gli eventi `LAILAPS_EVENT` usati dal feed live;
- `{run_id}-outcome.json`: oggetto con sole chiavi top-level `run_id`, `started_at`, `report` e
  `benchmark`. `started_at` è l'istante di creazione della run in formato ISO 8601 con
  timezone `Europe/Rome`; report parziale/finale, telemetria ed environment state confluiscono nel
  campo `report`; l'evaluator aggiorna `benchmark` nello stesso file.

L'outcome viene sostituito atomicamente durante i checkpoint. Nel percorso ordinario non esistono directory child,
snapshot, JSONL, raw response, manifest o file di telemetria persistenti. Le fasi opzionali di
normalizzazione/eval conservano artifact derivati separati, descritti sotto. I body HTTP e gli
output completi dei tool restano bounded in memoria per la durata del processo. Il database
conserva lifecycle, metadati e proiezioni benchmark, ma non duplica il transcript in una
tabella eventi.

`ToolOutputManager` conserva ogni risultato prima della preview model-facing in una LRU da
32 MiB per categoria. Ogni entry porta tool, ruolo, categoria, lead e area assegnati dal
runtime; un ID non concede accesso fuori scope. `inspect_tool_output` permette ai ruoli
investigativi di listare, leggere a finestre e cercare letteralmente gli output autorizzati,
distinguendo disponibilita', espulsione e acquisizione incompleta. Il Worker recupera dallo
stesso archivio, tramite `inspect_persisted_evidence`, anche evidenze HTTP, browser e source
indicizzate. I tool di recupero non reinseriscono copie nella LRU. Le finestre redatte
consultate e citate nella decisione vengono promosse nel finding con la propria provenance.

La stessa LRU contiene gli eventi `investigation-event-*` di Confirmer e Worker:
messaggi investigativi e decisioni vengono normalizzati e acquisiti prima di ogni compaction,
restando disponibili attraverso le epoch della lead. `search_investigation_history` esegue
ricerca letterale case-insensitive con massimo 20 estratti; `read_investigation_event` legge
finestre dello stesso evento e dichiara l'espulsione. Scope e autorizzazione derivano dalla
lead corrente. System prompt, metadata provider, credenziali e body dei tool non entrano nel
corpus; per i tool restano solo nome e riferimenti agli output autorevoli. I risultati dei due
tool di recupero non vengono reindicizzati. Un evento storico rimane un'affermazione o una
decisione, non evidenza: il Worker deve consultare l'output o l'osservazione indicata.

`search_source` e `read_file` separano acquisizione e rendering: ripgrep, controllo path,
limiti di acquisizione, registrazione delle source reference e provenance producono prima un
risultato strutturato; il percorso nativo ne rende poi una preview soggetta al budget di
presentazione. I binding programmatici consumano il risultato strutturato entro gli stessi
limiti senza dover interpretare la stringa model-facing.

Il Confirmer, la Global Recon e il Reader dell'esperimento Recon/Reader dispongono anche di
`run_code(code, description)`: il codice e' gia' un corpo Python async e puo' invocare
sequenzialmente soltanto i binding strutturati `search_source` e `read_file`. Il processo
agente fidato riceve il socket Docker sia nelle run ordinarie sia nei runner benchmark e usa
l'immagine agente configurata come toolbox; il programma gira invece in un container fratello
effimero senza rete, credenziali, sorgente o socket Docker, con root read-only, tmpfs bounded,
256 MiB RAM, 32 processi, 30 secondi e massimo 32 subcall. Un broker JSON su stdin/stdout
esegue le subcall nel processo agente; programma e binding sono contabilizzati separatamente.
Sintassi invalida, daemon o immagine indisponibili, errore programma e timeout tornano come
risultati recuperabili al modello. Il cleanup rimuove sempre il container e conserva le
osservazioni gia' acquisite, senza replay o retry LLM automatico.

Per i progetti PHP il Confirmer dispone inoltre di `navigate_source`, un accesso semantico
Phpactor limitato a definition, references, implementation e hover. Il release PHAR e il
checksum sono fissati nell'immagine; il language server vive in un container lazy riusato
per il source root della run, con sorgente read-only, rete e Composer disabilitati, root
read-only, tmpfs e limiti di memoria/processi. Il client converte le coordinate model-facing
1-based in UTF-16 LSP, rivalida ogni URI sotto `/workspace` e registra una source reference
soltanto dopo aver letto localmente lo snippet restituito. Timeout, server assente e risultato
vuoto sono stati recuperabili e non costituiscono prova di assenza o sicurezza; il Confirmer
deve verificare il blocco decisivo con `read_file`.

Il benchmark condizionale usa un registry separato dagli artifact filesystem della run.
`benchmark_stage_artifacts` persiste esclusivamente boundary strutturati validi e bounded
(`CategoryRecon`, output Reader e verdetti terminali Confirmer), mai reasoning, raw provider
response, body HTTP o history. La chiave primaria di catalogazione e' sempre `project_key`;
categoria e commit sorgente impediscono di riusare una fixture su una versione incompatibile.
Ogni artifact puo' riferire il parent che lo ha alimentato, formando il lineage project-scoped
Recon -> Reader -> Confirmer. Payload, usage e provenance sono immutabili; label manuali,
metriche ed evaluator version restano metadati separati e ricalcolabili.

Le run condizionali distinguono soltanto `valid` e `technical_failure`. Budget esaurito,
target benchmark non raggiunto, assenza di lead o decisione tecnicamente errata restano run
valide e vengono diagnosticate dal transcript e dalle metriche. `technical_failure` e'
riservato a exit non-zero, indisponibilita' persistente del modello, fatal error o input di
fixture invalido.

Lailaps esegue audit difensivi e autorizzati su applicazioni in un ambiente di test. Laravel prepara e valida sorgente, target e ambiente; il loop agentico Python legge il progetto assegnato e può inviare richieste HTTP soltanto al target autorizzato.

Codebase Memory rimane un acceleratore fail-open, ma l'indicizzazione nativa usa per default
un solo worker con budget di 768 MiB. I container che avviano l'agente hanno un envelope di
2 GiB per lasciare headroom a launcher, parsing e processo Python: il budget del worker non
viene moltiplicato in modo implicito su repository grandi.

Il P0 data-flow aggiunge `trace_data_flow`, distinto dal call graph `trace_code_path`: il
primo esegue un backward slice interprocedurale bounded dall'argomento di una chiamata,
mentre il secondo naviga caller/callee. L'adattatore usa esclusivamente template CLI
controllati di `joern-parse` e `joern-slice data-flow`; il modello non fornisce Scala, shell o
query arbitrarie. Il backend riproducibile e' Joern `4.0.592` (Apache-2.0), con frontend P0
validati PHP e Python. L'immagine agente installa a build-time una JVM headless, `php-cli`
richiesto dal frontend PHP e il backend pinned, verifica entrambi i launcher e non effettua
download durante gli audit; l'indicizzazione resta source-only e non esegue il progetto.
L'abilitazione resta indipendente per Reader e Confirmer tramite kill switch; nell'immagine
P0 entrambi sono attivi per la validazione tecnica, mentre RSS e latenza vengono misurati prima
di promuovere la configurazione oltre un target rappresentativo
nel provisioning da 2 GiB. Worker non riceve il tool. Backend assente, versione diversa,
timeout, errore e linguaggio non supportato sono fail-open e non interrompono letture
sorgente, processo agente o target HTTP.

Indicizzazione e query sono separate. Un indice viene serializzato e riusato fra lead e
ruoli tramite la cache gia' montata, fuori dagli artifact della run; la chiave include hash
del contenuto sorgente, linguaggio, versione backend e configurazione strutturale. Il source
root e' canonico: input assoluti, traversal, symlink esterni e locator Joern non risolvibili
nel perimetro vengono rifiutati o proiettati come incertezza senza leggerli. Output e
profondita' sono bounded e il CPG/ID backend non entra nel prompt. I passaggi ricevono una
source reference soltanto dopo confronto dello snippet col sorgente reale; hash, ruolo e
scope seguono lo stesso ledger delle letture ordinarie. L'edge statico resta advisory e non
acquisisce provenance di esecuzione; assenza di path, dispatch dinamico e semantiche
conservative delle dipendenze esterne non determinano un verdetto di sicurezza.

L'outcome unico della run contiene anche `agent_notebooks`, una collezione piatta di note
narrative facoltative con identità e revisione assegnate dal runtime. I ruoli investigativi
Reader, Category Recon, Confirmer e Worker dispongono di `read_notes` e `write_note`: lo
scope `category` è condiviso soltanto nella categoria corrente, mentre `role` attraversa le
categorie ma resta isolato per ruolo operativo. Il briefing presenta deterministicamente
ID, titolo e hint, oppure il corpo quando è breve; corpi lunghi e pagine successive restano
on demand. Aggiornamenti concorrenti della stessa nota richiedono la revisione osservata e
un conflitto non sovrascrive lo stato nuovo; note distinte vengono unite per ID. Dimensioni
e quantità sono bounded e un rifiuto non tronca né cancella note. Le pubblicazioni di report
e telemetria preservano sempre la sezione esistente. Le note non sono evidenze, finding,
credenziali o stato autorevole e non provocano transizioni del ledger.

Il percorso ordinario puo' ancora eseguire una o due categorie filtrate in sequenza. Il
percorso esplicito `--global` non effettua invece uno sweep A01-A10: esegue una sola Recon
trasversale, inizializza un unico Area Ledger e avvia una sola pipeline investigativa
Reader -> Confirmer -> Worker. Le aree Recon sono domande concrete su
entrypoint, operazioni sensibili, confini di fiducia e controlli osservati; non sono nomi di
categoria. Non esistono riconciliazioni o handoff intermedi per categoria.

Il coordinatore `GlobalPipeline` possiede un deduper deterministico, il broker e tre
code con pool indipendenti: 4 Reader, 4 Confirmer e 2 Worker di default. Il pool Reader
riempie immediatamente gli slot liberi con un'altra area. Ogni Reader usa Deps, HTTP,
cookie, history, telemetria e toolbox propri; Confirmer e Worker della stessa lead
condividono una sessione isolata in sequenza. ID di lead, output e source reference sono
qualificati per scope e pass. Il coordinatore e' l'unico publisher del report aggregato.
Un figlio chiude soltanto le proprie sessioni browser e risorse operative.

La run globale impone `reader_checkpoint` e deduper deterministico: non chiama il
Reviewer, nemmeno per enrichment o recovery. Ogni Reader decide la continuazione e la
chiusura della sola area assegnata. Ogni ReaderLead valida le source reference e passa
atomicamente dal deduper unico prima dell'ammissione Confirmer. Una lead ammessa viene
accodata subito, senza sospendere il Reader in attesa del downstream. CandidateHandoff
sblocca un solo envelope Worker e lo accoda mentre gli altri agenti continuano. Gli
esiti downstream possono aggiornare il Reader ancora attivo senza riaprire un'area chiusa.
AreaEnrichmentLead viene validata su reference e locator e accodata dal coordinatore;
gli assignment esattamente duplicati sono scartati deterministicamente.

Il cap Reader aggregato copre tutti i Reader, checkpoint, retry e compressione: default
3.000.000 EP regular per pass, scalato una sola volta dal preset oppure sostituito da
`--reader-points`. Recon conserva il limite distinto di 640.000 EP. L'accounting include
il consumo live di tutti i task e lo salda una sola volta. Esaurire il cap Reader blocca
nuovi assignment, richieste Reader e nuove lead; non blocca lead gia' ammesse, anche in
coda, o nuovi Worker derivati da esse. Le risposte gia' in volo possono sforare il cap:
l'overshoot resta registrato. Il cap totale del pass resta una safety net distinta.
Confirmer sblocca 500.000 EP per lead canonica e 250.000 per continuazione; Worker usa
un envelope unico da 1.000.000 EP. Nessuno dei due erode il cap Reader.

Python, `pentest:run`, `benchmark:run --global` e avvio web propagano
`reader-concurrency` (1-4), `confirmer-concurrency`, `worker-concurrency`,
`reader-points` e `worker-points`. Il report `global_pipeline` registra configurazione,
stati/eventi per scope, conteggi, code, stop Reader e completamento downstream. Il pass
finisce dopo aver drenato tutte le lead ammesse; coverage resta incompleta se esistono
aree non chiuse. Failure locali non cancellano altri agenti. Un errore di persistenza
o una cancellazione globale raccoglie i task e conserva il checkpoint disponibile.

La categoria proposta dal Reader e' opzionale: l'orchestratore la normalizza sulla
registry OWASP oppure usa `unclassified`. Nelle run filtrate il filtro richiesto resta
autorevole; il percorso sequenziale legacy mantiene il Reviewer e la discovery condivisa.

`--depth N` ripete l'intero pass in sequenza. Ogni pass riceve nuove dipendenze operative,
ledger, history/provider session, notebook, evidence ledger, client HTTP, cookie e budget;
riusa soltanto sorgente, indice Codebase Memory e infrastruttura target. Lo stato applicativo
creato sul target non viene ripristinato fra pass. Le fixture isolate CVE/ReaderLead/
CandidateHandoff accettano soltanto depth 1. Un failure fatale interrompe i pass successivi.
`depth` non estende automaticamente TTL o timeout esterni della run.

L'outcome schema 10 contiene `depth_requested`, `passes_started`, `passes_finished`,
`active_pass` e `passes[]`, con stato `running`, `finished`, `failed` o `cancelled` e report
completo scoped; `passes_finished` conta soltanto gli stati `finished`. ArtifactStore isola
notebook ed evidence ledger per pass pur mantenendo un
solo file atomico. Finding confermati, sospetti e rifiutati sono concatenati nel root report
con `pass_id` e ID qualificato, senza deduplica cross-pass. Checkpoint e aggregati vengono
ricostruiti dai report autorevoli dei pass, non sommati incrementalmente. Gli outcome
storici privi di depth sono letti come depth 1. Nei benchmark ogni pass riceve una
valutazione separata, mentre l'aggregato cumulativo conta ogni ground-truth una sola volta.

Le run globali accettano un modello comune con `--model` oppure override indipendenti per
Recon, Reader, Confirmer e Worker. La risoluzione è deterministica:
override di ruolo (incluso `--operative-model` Laravel per Confirmer/Worker), poi `--model`,
poi `Models.json`. Il report continua a registrare il modello effettivo per ruolo, così run
con un unico modello e run eterogenee restano confrontabili senza cambiare il loop.
Il percorso comune delle richieste OpenRouter lascia il routing automatico attivo per default:
un modello logico può quindi essere servito da una route sana scelta da OpenRouter. I pin di
provider sono un override esplicito per confronti controllati e impostano
`provider.only` con `allow_fallbacks=false`. Telemetria e outcome distinguono policy richiesta
e provider osservato quando OpenRouter lo restituisce.

Il contratto Chat Completions usato dal runtime e' verificato offline contro la versione
Pydantic AI installata: la continuazione assistant tool-call → tool-result conserva un solo
tool call ID, il campo reasoning supportato dal profilo provider e l'ordine stabile del
prefisso. I pin provider sono applicati soltanto quando configurati e disabilitano i fallback.
L'accounting distingue input totale, cache read e output; usage assente vale zero e il tee
transport contabilizza una risposta una sola volta anche dopo lettura e chiusura dello stream.

Nel percorso filtrato sequenziale legacy il flusso è:

1. Recon costruisce la mappa iniziale di domande di sicurezza e inizializza l'Area Ledger
   autorevole; in modalita' globale la mappa e' trasversale alle categorie.
2. Reader svolge la discovery statica in epoch cognitive limitate e isolate per area.
3. Exploration Reviewer produce il checkpoint semantico dell'area e decide se continuare,
   cambiare focus o chiudere l'area; continua soltanto con un test discriminante bounded e
   non reitera una direttiva inevasa o una tranche dominata da duplicati senza nuova evidenza.
   L'orchestratore applica la transizione usando l'identita' area catturata nello snapshot:
   una chiusura duplicata o obsoleta conserva il checkpoint ma non chiude l'area successiva.
   Decisione proposta, decisione applicata e causa di normalizzazione restano registrate.
4. Confirmer ricostruisce ogni lead e prepara un piano di verifica ancorato all'istanza, eseguibile dal Worker fin dal primo passo.
5. Worker verifica dinamicamente il candidate.
6. Il Reader riprende la stessa area e history, aggiungendo una volta l'esito downstream; una lead non chiude l'area.
7. Il Reader puo' proporre una `AreaEnrichmentLead` come recovery per una superficie
   imprevedibile; il Reviewer decide semanticamente e l'orchestratore accoda l'area approvata.
8. La chiusura Reviewer-owned dell'ultima area completa deterministicamente la discovery
   quando non esistono lead pending; in alternativa `finish_pass` applica lo stop semantico
   dopo la prima visita di tutte le aree note.
9. Se `depth` richiede un altro pass, l'orchestratore crea un nuovo scope operativo isolato
   e riparte da Recon senza trasferire memoria semantica dal pass precedente.

## Benchmark condizionale per stadio

Il P0 di evaluation isola Recon, Reader e Confirmer senza introdurre agenti o contratti
model-facing alternativi:

### Golden Recon e fan-out Reader globale

Il percorso `benchmark:reader-global` congela la Recon globale nel registry e mantiene il
payload completo soltanto nel coordinatore Laravel. Ogni processo Python riceve una fixture
proiettata con briefing e coverage notes comuni, una sola `ReconArea` e i metadati
`parent_artifact_id`/`assignment_area_id`; titoli e dettagli delle altre aree non entrano nel
contesto del Reader. Le 16 aree golden sono eseguite da una coda rolling con massimo quattro
processi: appena un assignment termina, il coordinatore avvia il successivo disponibile.
`finished_at` e durata sono fissati nel momento della terminazione del singolo processo.
Sorgente e target sono condivisi in sola lettura, mentre outcome, transcript, history, cache
CBM e stato agente restano per-assignment.

Reader ed eventuale Exploration Reviewer consumano per ogni assignment un unico envelope economico
con cap configurabile (500.000 punti di default); i limiti dei due ruoli non partizionano il
cap e Confirmer/Worker non vengono avviati. Le lead restano locali durante la ricerca e
sono qualificate con l'area soltanto nell'aggregato parent. La deduplica cross-area non blocca
output Reader.

Nel Reader globale con fixture congelata a una sola area, la prima proposta di `close_area` o
`finish_pass` non chiude subito: l'orchestratore conserva il checkpoint e concede una sola
tranche di recall con il budget residuo. Il Reader riceve solo briefing e assignment autorevoli,
checkpoint proposto, lead dell'area, indice delle source reference osservate, segnali statici
non riconciliati e domande residue; riesamina operazioni sensibili, trust boundary e rami
sorelli e serializza ogni ipotesi plausibile senza provarla. Il flag interno
`closure_recall_sweep_completed` rende lo sweep idempotente attraverso review ripetute e
compaction. La seconda chiusura è ordinaria. Dopo lo sweep, un `finish_pass` della sola area
visitata senza lead pending o rami aperti viene normalizzato a `close_area`; il checkpoint
conserva `proposed_decision=finish_pass`, `applied_decision=close_area` e
`normalization_reason=single_assignment_finish`. La semantica multi-area di `finish_pass`
resta invariata.

Una `AreaEnrichmentLead` approvata viene checkpointata dal processo proponente ma non modifica
il suo ledger: il coordinatore normalizza il payload, assegna un ID deterministico, elimina
solo proposte normalizzate identiche e le accoda FIFO dopo tutte le aree golden. Anche gli
enrichment possono produrre ulteriori enrichment, fino al limite complessivo di 48 aree.
Per uno screening mirato, `benchmark:reader-global --area` seleziona soltanto gli incarichi
iniziali indicati dalla Golden Recon, senza limitare i file leggibili dal Reader.
`--defer-enrichments` conserva nel parent proposte approvate, respinte e duplicate con
provenienza e stato, ma non avvia aree derivate. L'outcome distingue la fine degli
assignment selezionati dalla completezza globale; i task rinviati restano copertura
residua. Senza questi flag rimane il fan-out ordinario.
Tutte le aree chiuse producono `complete`; budget o limite aree producono un outcome valido
`incomplete`; gli errori infrastrutturali sono `technical_failure` per l'area e non fermano
le altre aree.

Nel confronto con `--reader-checkpoint-strategy=reader_checkpoint`, i boundary
ordinari sono self-checkpoint dello stesso Reader. Il benchmark non avvia Reviewer
online per novelty o enrichment: le lead ulteriori restano acquisite con novelty
`unresolved`, le proposte di enrichment restano `pending_review`, e la valutazione
di deduplica e qualita' e' offline. `--defer-enrichments` mantiene a zero il dispatch
di aree derivate. Le scorecard child e parent distinguono produzione ordinaria e
checkpoint, costo provider completo o mancante, stima, EP, token/cache, tool con
esito noto o pendente, retry, tempo e pressione di contesto; la qualita' offline
resta `pending` finche' non valutata. Il parent calcola rapporti da somme e conserva
separati il tempo totale degli assignment e il proprio wall-clock concorrente.
`benchmark:reader-global` propaga il pin `--reader-provider` e i cap
`--operational-context-window`/`--max-prompt-input-tokens` a ogni figlio; il
catalogo prezzi con fonte e data e la finestra richiesta/effettiva vengono
congelati negli outcome. Le tariffe statiche entrano nelle stime USD soltanto
quando il provider osservato o fissato coincide con quello verificato nel catalogo.
Gli EP restano un limite operativo distinto dal costo reale del provider.

L'aggregato globale aggiunge una diagnostica post-run per case, costruita esclusivamente dopo
la conclusione e mai inviata agli agenti: `matched_lead`, `anchor_read_without_lead`,
`file_only`, `unreached` oppure `wrong_hypothesis_same_anchor`. Un overlap di range è soltanto
un locator: conta come `matched_lead` solo il match `anchor_semantic` del manifest.

L'identita' Docker del processo e l'autorita' sul target sono separate: `audit_id` nomina e
delimita il toolbox univoco del Reader, mentre `target_audit_id` serve esclusivamente a
validare e inventariare i container della sandbox condivisa. Il cleanup di un processo puo'
quindi rimuovere soltanto il proprio container di stage e il proprio toolbox, mai quelli di
un altro Reader o il target riutilizzato.

1. `benchmark:recon` puo' eseguire una categoria oppure una Global Recon trasversale a tutti
   i manifest compatibili dello stesso snapshot. Ogni repetition persiste il `CategoryRecon`
   completo e un artifact figlio `ReconArea` per ciascuna area. La fixture predefinita e' la
   prima repetition tecnicamente valida, scelta senza usare lo score benchmark.
2. `benchmark:reader` carica una Golden Recon congelata, ricostruisce l'Area Ledger
   autorevole e avvia il Reader normale. L'Exploration Reviewer resta un controllo operativo
   fisso necessario a checkpoint e chiusura aree, ma non riceve una scorecard autonoma. Le
   lead non proseguono a Confirmer e vengono conservate come output Reader accettati. Per
   default il comando eredita il preset economico configurato della run normale; un preset
   esplicito resta un override di benchmark. Ogni output strutturato viene checkpointato
   atomicamente durante la run: il timeout esterno marca `ReaderRun` come technical failure,
   ma non invalida ne' perde le ReaderLead gia' validate.
3. I match univoci col manifest ricevono automaticamente la label `benchmark_positive`.
   Gli output fuori catalogo restano non classificati finche' un operatore non assegna una
   label versionata (`novel_valid`, `false_positive`, `duplicate`, `unresolved` o
   `out_of_scope`). La classificazione non riscrive mai il payload.
4. `benchmark:confirmer` riceve ReaderLead canoniche e classificate, ricostruisce le source
   reference dal commit assegnato ed esegue soltanto Confirmer. Un CandidateHandoff o una
   LeadClosure terminale viene persistita come child della lead; Worker non parte.
   I dataset comparativi sono congelati con una `label_set_version` dedicata (per esempio
   `confirmer-gold-v1`): le lead storiche vengono clonate con parent/provenienza immutabili e
   gli hard negative curati sono nuovi artifact versionati. L'oracle vive soltanto nei metadata
   post-run, mai nel subject inviato al modello, e dichiara decisione attesa, anchor statici e
   requisiti minimi del piano. L'evaluator separa classification score da quality score per
   source/propagation/sink/controllabilita'/reachability e piano Worker; una `technical_error`
   non puo' mai contare come LeadClosure corretta. Le metriche aggregate escludono i failure
   tecnici dal denominatore semantico e riportano costo, token, retry/output failure e stabilita'.
   Se `--dataset` e' valorizzato, la selezione resta limitata agli artifact canonici della
   versione congelata; se e' omesso, il comando usa direttamente le ReaderLead `valid` del DB,
   filtrate per progetto e per l'eventuale `--category`. `--artifact` resta l'override puntuale.
5. `benchmark:worker` riceve un `CandidateHandoff` congelato o tutti gli handoff di un
   parent selezionato tramite `--parent-run-id` e ricostruisce le source reference dal
   commit assegnato. Con un parent ogni ripetizione esegue un batch seriale in un'unica
   sandbox e runtime, condividendo sessioni e note tra le lead; esiti e budget restano
   per candidato.
   Un errore locale della candidate, inclusa validazione esaurita, conserva suspect,
   evidenze e costi, pubblica l'episodio e prosegue con la successiva nello stesso runtime.
   `worker_batch` distingue tentati, episodi conclusi, decisioni terminali valide,
   failure tecnici e non eseguiti; `worker_batch_complete_with_errors` indica che tutti
   gli episodi sono conclusi ma alcuni sono falliti. L'exit non-zero viene emesso dopo
   la persistenza del batch. Cancellazione, persistenza fallita, configurazione globale
   invalida, fatal error e hard stop globale interrompono anche le candidate successive.
   Il finalizer conserva questa proiezione Worker separata dal benchmark end-to-end.
   Il percorso per singolo candidato passa dal normale provisioning
   `pentest:run` con sandbox e volumi nuovi, setup, readiness, attori, fixture probe e
   post-readiness; il riuso stateful e `--keep` non fanno parte del percorso comparabile.
   Viene avviato soltanto il Worker reale con CandidateHandoff valido, toolset completo,
   sessioni, compression, retry, self-checkpoint e gate della run globale. Il cap e'
   1.000.000 EP per candidato di default, senza rinnovi; le continuazioni condividono
   envelope e conversazione. L'oracle post-run, assente dal subject e dai tool, valuta la
   decisione terminale Worker e la sufficienza delle prove (`sufficient`, `partial`, `absent`).
   Artifact e metriche riportano decisione, applicazione e cause di stop; le nuove signature
   distinguono il flusso autonomo e il report non aggrega configurazioni incompatibili.
   Failure tecnici, astensioni e casi non classificabili hanno denominatori distinti.
   I subject Worker sono congelati esclusivamente da artifact `CandidateHandoff` prodotti e
   valutati dal benchmark Confirmer: il comando di freeze rifiuta payload curati, cosi' ogni
   dataset Worker conserva lineage Confirmer immutabile e non attribuisce una fixture manuale
   a una run agente.
   Anche qui `--dataset` abilita la selezione frozen canonica, mentre la sua assenza seleziona
   dal DB i `CandidateHandoff` `valid` prodotti dal Confirmer per progetto e categoria;
   `--artifact` ha precedenza su entrambe le modalita'.
   `--parent-run-id=<ID artifact o run_id>` restringe ogni modalita' agli handoff derivati
   da una stessa `ReaderRun` o `DeduperRun`: run -> ReaderLead -> CandidateHandoff, tramite
   `parent_artifact_id`. La selezione DB ordinaria include tutte le lead e ripetizioni
   Confirmer di quella run. Un ID di lead o una run di un altro progetto viene rifiutato.
   Una singola invocazione esegue tutti gli handoff selezionati in episodi isolati.

La selezione Golden serve a misurare performance condizionale e non sostituisce il benchmark
end-to-end. Una Golden viene congelata per progetto, categoria e commit e non viene
riselezionata in funzione del modello confrontato. Il benchmark Worker misura l'episodio
dinamico sul CandidateHandoff congelato con lo stesso loop autonomo della run globale.
Il lineage project-scoped e' Recon -> Reader -> Confirmer -> Worker; la decisione finale
Worker viene applicata soltanto dopo validazione strutturale e di provenienza.

La filosofia operativa privilegia l'autonomia di Confirmer e Worker. Entrambi decidono ai
propri self-checkpoint, nella stessa conversazione e senza tool investigativi, se il prossimo
esperimento puo' produrre informazione utile o se le prove bastano per concludere. Il Worker
raccoglie autonomamente il contesto mancante e termina anche prima dei boundary operativi.

## Principi di design agentico

Le strutture dei tool e degli output devono restare semplici, piatte e tolleranti. I
contratti devono preservare completezza semantica e handoff utili tra ruoli, ma non devono
richiedere JSON annidato, wrapper multipli o shape troppo rigide quando una struttura
narrativa piu' libera puo' essere normalizzata deterministicamente dall'orchestratore. La
validazione deve distinguere tra errori materialmente pericolosi e campi recuperabili dal
ledger gia' persistito.

Regola operativa: il modello decide e racconta; l'orchestratore identifica, normalizza,
collega e persiste. I DTO model-facing sono distinti dai modelli interni persistiti. Esempi
positivi correnti:

- l'Exploration Reviewer emette una decisione piatta e `checkpoint_summary` narrativo;
  l'orchestratore costruisce l'`AreaCheckpoint` interno con area id, superfici osservate e
  source reference ricavate dallo snapshot autorevole;
- il Lead Novelty Reviewer usa un output piatto e l'assenza recuperabile di
  `related_lead_id` viene normalizzata dall'orchestratore a `unresolved`, senza chiedere al
  modello di riserializzare l'intera risposta. La novelty e' un'ottimizzazione fail-open:
  soltanto `same_hypothesis` con un ID autorevole e `not_a_security_lead` respingono la
  proposta; errori tecnici, sentinel testuali e relazioni non risolvibili ammettono la lead.

Il default e' dare autonomia ai modelli, soprattutto ai ruoli smart come `Confirmer` e
`Worker`. Limiti stretti, budget cap locali, supervisioni frequenti e prompt molto
restrittivi sono guardrail da introdurre solo quando un comportamento reiterato e
patologico e' osservato nei log, non come prevenzione generica. Prima di aggiungere un
guardrail che interrompe il ragionamento del modello bisogna chiedersi se il problema sia
meglio risolto aumentando tranche, semplificando il contratto o lasciando il ruolo
completare il proprio workflow.

Prima di patchare un comportamento fragile con regole orchestration, reviewer aggiuntivi o
prompt piu' stretti, valutare esplicitamente un upgrade del modello usato da quel ruolo. Se
un modello non riesce a seguire un contratto ragionevolmente semplice o a completare un
workflow operativo, patchare intorno al modello puo' introdurre complessita' e regressioni
peggiori del costo di usare un modello migliore.

Non patchare preventivamente problemi non dimostrati, soprattutto problemi di comportamento
dei modelli. Due regressioni da evitare come anti-pattern:

- Reviewer usato come timer generalizzato per ruoli operativi: ha consumato budget e
  interrotto flussi produttivi. La review del solo Reader e' invece event-driven e produce
  memoria/decisioni che il Reader non deve piu' serializzare.
- Category Recon troppo strict come checklist bloccante: puo' impedire al Reader di
  esplorare un'area vulnerabile osservata solo perche' non prevista nella checklist
  iniziale. Recon orienta e prioritizza, ma non deve diventare una gabbia che vieta piste
  concrete emerse durante la discovery.

Il reasoning del modello è sempre attivo. Modello e reasoning predefiniti di `recon`,
`reader`, `reviewer`, `confirmer` e `worker` risiedono nel singolo
`agent/pentest-agent/Models.json`, validato all'avvio; ciascun ruolo ha i campi piatti
`model` e `reasoning_effort` (`low`, `medium` o `high`, con `high` massimo supportato).
Category Recon e Reader sono quindi configurabili indipendentemente. Gli override CLI e
le rispettive variabili d'ambiente restano prioritari. Nel runtime Laravel containerizzato
il JSON viene montato read-only dall'host a ogni run, così il suo aggiornamento non richiede
un rebuild dell'immagine. L'opzione `pentest:run --test` controlla soltanto il rebuild
dell'immagine e il wire debug e non modifica il reasoning.

Il protocollo positivo di completamento non richiede una proposta Reader separata: quando
il Reviewer chiude l'ultima area, l'orchestratore verifica deterministicamente Area Ledger,
area attiva e lead pending e termina la categoria.
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

Category Recon possiede la copertura primaria senza cercare finding e senza usare HTTP. Non
Ã¨ una semplice ottimizzazione per evitare ricognizione generica al Reader: il suo output
inizializza la checklist che decide quali superfici saranno esplorate. Produce il contratto
breaking `CategoryRecon` v4: `schema_version`, `kind`, `status`, una lista ordinata di
una-dodici aree (`area_id`, `title`, `paths`, `qualified_names`, `next_check`) e `unknowns`.
Il modello può produrre soltanto `ready`; l'ordine delle aree è direttamente l'ordine di
esplorazione e deve mettere prima le superfici che Recon ritiene piu' probabili per sink
concreti e controllabili, lasciando in fondo quelle piu' deboli o indirette. Ogni area deve
avere almeno un path oppure un simbolo qualificato: i path devono
esistere nel source root ed essere stati osservati nell'output di un tool Recon, mentre i
`qualified_names` devono essere stati restituiti da Codebase Memory durante Recon. Il Reader
risolve ogni simbolo privo di path e legge il sorgente prima di produrre una lead; un riferimento
del grafo non è mai evidence per un finding. La validazione resta esclusivamente strutturale e
provenienziale: nessuna funzione deterministica valuta qualità semantica, vulnerabilità o
progresso.

Prima del turno Recon l'orchestratore costruisce un `SurfaceContext` v3 advisory e fail-open:
un inventory Semgrep offline composto dalla snapshot pinned del ruleset community `p/default`
e da un fallback locale ristretto alle primitive universali ad alta precisione, oltre alla
panoramica architetturale Codebase Memory. Lo scan non consulta il Registry a runtime e scarta
i risultati upstream di sola correctness privi di metadata security. I signal sono locator,
mai evidence o gate;
la loro assenza non dimostra l'assenza di una superficie. Non esiste un vocabolario globale
di wrapper applicativi (`getAll`, `count` e simili): i wrapper custom restano oggetto
dell'esplorazione agentica.
La snapshot e il manifest sono verificati per digest anche durante la build; un corpus
incoerente impedisce la distribuzione dell'immagine. A runtime un checksum errato lascia
attivo il fallback locale e rende il sensore incompleto, senza bloccare Recon o Reader.
La CLI usa `--no-rewrite-rule-ids` per preservare gli ID locali `lailaps.*` prima del
filtro security. Lo stato `ready` richiede corpus previsto, output valido, almeno un
file analizzato e nessun errore, regola esclusa o timeout dichiarato dall'engine;
altrimenti lo stato e la limitation restano visibili anche nel tool del Reader.

Prima dell'aggregazione, l'orchestratore conserva anche la proiezione interna completa dei
singoli match Semgrep con identita' deterministica derivata da sensore, regola, famiglia,
path e riga. Recon continua a ricevere il census aggregato; il Reader riceve nel prompt
soltanto stato del sensore e conteggi per famiglia e consulta i match con
`list_surface_signals(scope=active_area|all, path?, family?, cursor?)`. Lo scope area usa
esclusivamente i path locator dell'Area Ledger e non pretende di coprire dipendenze. Il tool
distingue scan indisponibile, risultato incompleto e assenza di match, pagina tutti i
segnali e consente sempre l'allargamento all'intera categoria. L'assenza o l'esaurimento
della lista non e' un gate di copertura: wrapper custom e superfici non segnalate restano
parte dell'esplorazione ordinaria. Con Codebase Memory disponibile Recon usa prima
`codebase_architecture`, poi `search_code_graph`, `list_dir` e
`search_source`; senza Codebase Memory conserva listing e ricerca testuale. Questi tool e
il Surface Context alimentano un registry dedicato dei path osservati, escludendo
path inesistenti, esterni al source root e identificatori `tool-output-*`. Recon non può
leggere implementazioni, eseguire command tool o usare HTTP. Se modello, provider o
validazione falliscono dopo tre retry di output riservati, l'orchestratore emette soltanto
`status=fallback`, `areas=[]` e `unknowns=["Category Recon non disponibile."]`: non recupera
output parziali e non costruisce hotspot deterministici. Prima del fallback applica anche
la policy comune di retry completo del turno modello. Recon dispone di otto richieste
investigative, 12.000 token massimi e una richiesta per la serializzazione. Consuma
l'allowance discovery locale da 3.000.000 EP regular, condivisa con Reader e Reviewer
senza quote economiche concorrenti. Il prompt impone breadth e riconciliazione delle
famiglie prima della serializzazione, non approfondimento di un singolo finding.
Riserva, consumo e pending admission della Recon usano gli stessi pesi economici del modello
servente; il gate history non puo' reinterpretare usage model-scaled con i pesi anchor
generici. La telemetria espone limite e consumo locali piatti e, quando avviene una chiusura
economica, registra nella boundary limite, usato, pending e residuo. Il conteggio separa i
turni terminali dal punto reale in cui la conversazione entra nella fase tool-free, anche se
questo avviene prima del request limit investigativo.
L'inventory Semgrep e' content-addressed e persistito per coppia categoria + fingerprint
del progetto, della versione engine, del manifest e dell'intero corpus di regole: riusa
soltanto scan complete riuscite, invalida automaticamente al cambio di uno di questi input e
non memorizza mai un fallimento fail-open o una scansione incompleta. La versione della
cache cambia quando cambiano la semantica degli ID e i diagnostici conservati. Il ranking resta bounded a quaranta file e tre
esempi per famiglia, ma privilegia confidence e severity e applica round-robin tra rule id
distinti prima della frequenza grezza, evitando che un pattern rumoroso nasconda primitive
precise. Provenienza, engine version e checksum del ruleset sono esposti nel sensor status.
Il comando
diagnostico `pentest surface-context` esegue solo questo sensore e stampa il JSON risultante,
senza inizializzare Codebase Memory o ruoli LLM.

Reader cerca piste statiche concrete e puo' produrre `ReaderLead`, `LeadEnrichment`,
`AreaEnrichmentLead` o il yield leggero `ReaderReviewRequested`. Nel contratto operativo
non produce checkpoint, proposte di chiusura o completion; l'opzione sperimentale
`reader_checkpoint` sostituisce temporaneamente quel contratto soltanto al boundary
tool-free descritto sotto. La soglia di `ReaderLead` e' deliberatamente la plausibilita', non la
conferma: richiede un sink o un'operazione sensibile, una source reference, un possibile
input controllabile o trust boundary e un collegamento plausibile. Appena la soglia è
visibile il Reader serializza la lead senza completare il lavoro del Confirmer. Come P0
sperimentale e facilmente rollbackabile, il prompt applica una soglia one-hop: dopo
operazione concreta, variabile nominata e origine plausibilmente meno trusted concede al
Reader al massimo un controllo locale su origine o barriera. Se sicurezza, ACL, route,
privilegi, caller distanti o runtime restano da stabilire, questi diventano unknown del
Confirmer. Il dubbio generico privo dei tre elementi non genera una lead; l'ignorare una
pista richiede invece una barriera locale esplicita, incondizionata e pertinente oppure
l'assenza di una variabile meno trusted dopo il controllo bounded. Il Reviewer sorveglia
soltanto il rispetto di questo confine operativo e non valida semanticamente il sink.
Quando abilitato, `trace_data_flow` puo' costituire quel singolo controllo bounded ma non e'
obbligatorio e non autorizza una ricostruzione esaustiva prima della lead.

Il tracking dei signal e' memoria interna dell'orchestratore e sopravvive alle epoch Reader:
separa `listed`, `read` e `evaluated`, collega le lead alle identita' pertinenti e proietta
nel dossier soltanto conteggi e pochi esempi ancora aperti. Il listing non equivale a una
lettura; `read_file` marca soltanto i match nel range realmente restituito; una lead o la
chiusura Reviewer dell'area marca la valutazione. Questo stato non schedula il Reader e non
sostituisce Area Ledger o Reviewer come autorita' di chiusura.
Il contratto model-facing di `read_file` espone soltanto `path`, `start` e `count`; il
contratto Python legacy resta adattato internamente. Successo, errore e vuoto dei tool sono
classificati dai metadata dell'envelope quando presenti, mai cercando la parola `error` nel
codice sorgente restituito.

I contratti Reader sono piatti: `ReaderLead` espone direttamente `coverage_delta` e `next_focus`, mentre
`LeadEnrichment` contiene soltanto identita', evidenza e condizioni di riapertura. Non
espongono `kind` ridondanti o `CategoryStateUpdate`; l'orchestratore aggiorna e sincronizza lo stato strategico
da ledger, coverage, focus e checkpoint autorevoli. La persistenza non affida al Reader la
copia di identificatori opachi: lo schema model-facing di `ReaderLead` non contiene
`source_ref_ids` o `owasp_category`; gli stessi campi restano soltanto nel modello interno
per leggere artifact legacy e vengono ricostruiti dall'harness. Il Reader puo' indicare il locator
descrittivo e tollerante `primary_file` + `primary_line`, senza retry se e' assente o errato.
L'orchestratore seleziona soltanto reference registrate nella tranche o nell'area: prima la
reference piu' stretta che contiene il locator primario, poi al massimo una reference per
ciascun file citato nella narrativa, fino a sei complessive; come fallback usa al massimo le
tre reference piu' recenti della tranche. Categoria
e rimozione di eventuali area id da `coverage_delta` sono normalizzate dallo stato
autorevole. Soltanto l'assenza completa di evidence sorgente reale respinge la proposta,
senza consumare retry di structured output per chiedere al modello di ricopiare un ID. Lo
stato durevole distingue `active_area_id`, `closed_area_ids` ed esplorazione granulare: una lead
non può chiudere un'area e, dopo il relativo handoff, il Reader riprende la stessa area per
cercare sink fratelli. `close_area` del Reviewer sposta l'orchestratore alla successiva area
non chiusa. Al boundary il Reviewer puo' continuare, cambiare focus entro l'area o chiuderla;
un pivot verso un'altra area non bypassa la chiusura esplicita di quella attiva. Il Reviewer
produce una decisione piatta con direttive e `checkpoint_summary` narrativo. L'orchestratore
costruisce l'`AreaCheckpoint` interno unendo quel testo ad area id, checked surfaces e source
reference ricavate deterministicamente dallo snapshot; il modello non serializza il DTO
persistito. L'orchestratore completa deterministicamente la categoria
dopo la chiusura dell'ultima area se non esistono lead pending.

Con la strategia di default `reviewer`, il Reviewer entra su `ReaderReviewRequested`, soglia
cognitiva soft/hard, intervallo massimo di 16 richieste o proposta `AreaEnrichmentLead`.
La novelty review delle lead resta separata. Non entra nei boundary ordinari di Confirmer o
Worker. La strategia alternativa del benchmark sostituisce soltanto le prime tre review
ordinarie; enrichment e novelty restano sempre al Reviewer.

`CategoryRecon` resta la fotografia iniziale immutabile; l'Area Ledger separato e' la fonte
autorevole per scheduling e completion e contiene record `queued`, `active` e `closed` con
origine `category_recon` o `reader_enrichment`. Una `AreaEnrichmentLead` puo' avere locator
concreti oppure soli `seed_checks` bounded. Non attraversa validazione deterministica
semantica o provenienziale: l'Exploration Reviewer restituisce `approve_area_enrichment` o
`reject_area_enrichment`; l'orchestratore assegna l'id e accoda FIFO l'area approvata senza
interrompere quella attiva. Non e' una finding, non usa il finding ledger e non consuma un
lead stage.

Una `LeadEnrichment` riapre la stessa ipotesi chiusa o fermata dal Worker con una source
reference nuova oppure, una sola volta senza nuovi ref, con `closure_contradiction`
sostanziale che dimostri come l'evidenza già registrata contraddica l'adjudication. Un sink
distinto è sempre una nuova `ReaderLead`, anche quando condivide file o intervallo sorgente.
Un errore di applicazione del ledger su output Reader viene respinto e corretto nella stessa
discovery, senza trasformarsi in shutdown fatale dell'orchestratore.

Quando esistono lead già adjudicated, la nuova `ReaderLead` resta fuori dal ledger finché
un Reviewer semantico stateless non confronta l'ipotesi proposta con lo stato globale
compatto: ciò che il Reader ha letto e tentato, le decisioni pregresse e, in particolare,
motivo, evidence gap, prossimo test di uno stop del Worker. Il Reviewer emette
`distinct_sink`, `same_hypothesis`, `not_a_security_lead` o `unresolved`. L'harness non
decide la duplicazione con
uguaglianze di path, righe, source reference o testo del sink: coordinate uguali non provano
una duplicata e coordinate diverse non provano una sink distinta. `same_hypothesis` instrada
il Reader verso l'enrichment della lead indicata; `not_a_security_lead` scarta evidenza
negativa o una proposta che descrive un controllo sicuro; `unresolved` restituisce strumenti
e una richiesta di chiarimento semantico, senza forzare la creazione; soltanto
`distinct_sink` inserisce una nuova lead. Il verdetto e la relativa istruzione restano persistiti nel finding
o nell'enrichment per rendere ricostruibile la decisione. Se `same_hypothesis` omette o cita
un `related_lead_id` non adjudicated, l'orchestratore lo normalizza a `unresolved`: e' un
errore semantico recuperabile e non consuma un retry di serializzazione.

Prima dell'inserimento nel ledger, ogni `ReaderLead` attraversa anche un quality gate
deterministico: titolo, ipotesi, operazione sospetta ed evidenza iniziale devono essere
informativi e non possono essere placeholder come `test`, `true`, `todo` o `unknown`; la
categoria deve coincidere con quella attiva. Un rifiuto produce un evento
`reader_output_rejected` con pressione di contesto e motivazioni e usa il retry di output
per richiedere la serializzazione concreta dalle evidenze già osservate, senza nuove letture.

Confirmer riceve anche lead acerbe e svolge discovery verticale nell'intera codebase, sempre scoped alla lead. Il suo compito è raccogliere contesto completo dal codice e confermare staticamente source, propagation, sink, reachability, mitigazioni e route rilevanti. Non deve completare una checklist esaustiva di dettagli operativi: il Worker dispone di `read_source_ref`, lettura del codice, accesso read-only a database e target e tool HTTP, quindi può adattare actor, autenticazione, formato e setup durante la verifica. Il Confirmer termina quando ha un sink verificato sul sorgente, una primitive plausibile e un primo test HTTP discriminante descrivibile, oppure quando una barriera statica positiva dimostra la chiusura. Un fatto ottenibile soltanto da una risposta HTTP appartiene normalmente al `verification_plan` del Worker.

L'handoff applica la regola anchor-o-barriera: quando la route e' derivabile staticamente,
`attackable_routes` contiene path concreti con parametri e valori noti, senza placeholder, e
il `verification_plan` descrive la prima probe discriminante. Quando il piano lascia gap,
il Worker ricostruisce autonomamente route, parametri, setup e catena statica consultando
il codice e il runtime. L'ingresso resta sempre un CandidateHandoff valido: nessun ritorno
al Confirmer o richiesta di autorizzazione per arricchire la stessa lead.

Il contratto minimo `CandidateHandoff` è narrativo e piatto: `verification_plan`, `success_signal` e `rejection_signal`. La `ConfirmationRecipeCard` strutturata è un acceleratore opzionale e normalmente viene omessa. Il Confirmer specifica l'osservazione HTTP discriminante senza doverla già osservare; payload definitivo, fixture completa, formato esatto dell'autenticazione, alternative e fallback non bloccano l'handoff. Una chiusura ordinaria richiede evidenza statica positiva di flusso interrotto, sanitizzazione efficace, costante sicura o irraggiungibilità. La basis `not_exploitable` richiede invece la prova che il controllo necessario appartenga a stato o privilegi non posseduti dall'attore; non può derivare da assenza di payload, conferma dinamica o budget. Non esiste un output o uno stato `NeedsReaderEvidence`.

Alla fine di ogni tranche il medesimo Confirmer esegue un self-checkpoint tool-free con
output piatto `ConfirmerCheckpoint`: `decision`, `reason`, `decisive_question` e `next_step`.
La decisione è `candidate_handoff`, `lead_closure` oppure `continue`; gli ultimi due campi
sono obbligatori soltanto per `continue`. Quest'ultimo è valido esclusivamente quando manca
un singolo fatto non-HTTP che può cambiare il verdetto o impedire il primo test HTTP e un
singolo tool call può risolverlo. Gli esiti terminali bloccano il tipo della successiva
serializzazione tool-free. Il Reviewer non partecipa al percorso P0 ordinario.

Il checkpoint usa la stessa `RoleConversationState`, lo stesso `session_id` e la stessa
pipeline `_role_history` della tranche: la history rimane append-only finché non raggiunge
la normale soglia di compression, e il cambio cognitivo è introdotto da un messaggio utente
deterministico. Prima di ogni continuazione cross-run l'orchestratore ripara un'eventuale
risposta finale con tool call non processate, rendendo valida la history per il nuovo prompt.

Ogni conversazione Confirmer ha uno `scope_id` immutabile uguale alla `lead_id`. Factory,
assignment, checkpoint di compression, self-checkpoint e validator ricevono
esplicitamente quello scope e respingono mismatch. L'assignment include anche briefing,
categoria, record dell'area d'origine e un Surface Context ristretto ai file della lead con
signal Semgrep e vicinato graph bounded. Questi elementi accelerano lead intenzionalmente
acerbe, incluse quelle nate da enrichment, ma restano locator da verificare sul sorgente.
I payload includono soltanto finding,
source reference, transazioni HTTP del Confirmer e blocker della
lead corrente; non includono lead concorrenti o blocker globali. Il Confirmer dispone

L'assignment completo e' un bootstrap per epoch: nelle tranche successive e nella
terminalizzazione l'orchestratore invia soltanto un delta di nuove ref, feedback ed evidenza
HTTP, riusando la history della conversazione. L'ammissione del bootstrap e' deterministica:
se il payload supera il budget del singolo prompt, gli snippet non essenziali diventano
metadata ma tutti gli identificatori restano recuperabili con `read_source_ref`. Le
transazioni HTTP sono sempre proiettate e limitate; un rifiuto locale del guardrail tenta una
sola volta il bootstrap minimale senza compaction, cambio di sessione o chiamata al provider.

sempre di `read_source_ref`, `search_source`, `list_dir`, `read_file` e, quando il command
executor è disponibile, `run_workspace_command` come strumentazione deterministica non
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

Quando il flag Confirmer e' attivo riceve anche `trace_data_flow` per ricostruire in modo
verticale source, propagation e sink della sola lead attiva; il Confirmer, non Joern, valuta
semanticamente controllabilita', trasformazioni, condizioni e barriere.

Ogni ruolo, non soltanto il Confirmer, dispone di due retry orchestrati per
`UnexpectedModelBehavior`, `ModelAPIError` e l'eventuale `APIError` OpenAI non ancora
normalizzato. Il transport esegue un solo tentativo, evitando di moltiplicare implicitamente
i tre tentativi complessivi dell'orchestratore; i retry Pydantic di validazione dell'output
restano invece interni al medesimo episodio. Prima della validazione Pydantic, tutti gli output strutturati
attraversano una normalizzazione JSON schema-aware: una stringa che contiene un oggetto o
una lista JSON viene decodificata soltanto quando il campo dichiarato richiede quel container;
un container viene codificato a stringa soltanto per campi JSON testuali espliciti come
`payload_json`. Stringhe narrative, JSON scalari e forme incompatibili restano intatti e
continuano a fallire normalmente. Le rappresentazioni equivalenti recuperate localmente non
consumano quindi retry modello. Un errore riconducibile a context/output token exhaustion forza
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
del retry. Il client HTTP del provider applica un timeout di inattivita' configurabile di
120 secondi. Una deadline distinta, di default 240 secondi, avvolge ogni singola richiesta
modello nel nodo di stream e non il tempo complessivo della sessione o dei tool.
Timeout e guasti di trasporto esauriti entrano nei recovery tecnici gia' previsti per ogni ruolo:
Reader conserva il checkpoint e termina incompleto dopo due cicli, Confirmer blocca la lead
sospetta, Worker conserva la lead sospetta con causa tecnica esplicita;
Recon, Reviewer e Handoff usano i rispettivi fallback conservativi. Ogni guasto di trasporto
della richiesta registra nella telemetria persistita durata, indice della richiesta e retry
orchestratoriale, ruolo,
modello, policy provider richiesta, provider osservato se disponibile e stato dello stream
(`before_response`, `stream_without_content` o `stream_with_content`). Un provider non
osservato resta sconosciuto e non viene inferito dal modello o dal segnaposto diagnostico.
Per il Reader il primo retry conserva l'intera history;
prima del secondo l'orchestratore chiede un checkpoint all'Exploration Reviewer e apre una
nuova epoch. Se la review fallisce, usa un checkpoint deterministico: ledger, source
reference, file osservati, dossier area e stato della categoria restano autorevoli, mentre i
messaggi potenzialmente corrotti vengono eliminati. L'evento `reader_retry_compaction`
distingue checkpoint Reviewer e fallback deterministico.
`UsageLimitExceeded` rappresenta invece i guardrail locali di budget o richieste e non
viene ritentato dall'orchestratore. Il turno terminale del Confirmer restituisce un
`ConfirmerTerminalPayload` piatto (`decision`, `reason` e, per un candidate,
`verification_plan`, `success_signal`, `rejection_signal`; per una closure, `closure_basis`):
l'orchestratore collega lead ID, categoria, severity e source reference dallo stato
autorevole e tratta `recipe` come payload opzionale non tipizzato, convertito alla card
interna e scartato se malformato, senza retry. Gli adattatori legacy tollerano candidate e
closure JSON complete, envelope escapati e forme precedenti: un nucleo semanticamente
valido non viene mai rispedito al modello per ID, recipe o escaping. Durante la serializzazione terminale del Confirmer le
risposte raw vivono fuori dalla history comprimibile: l'orchestratore recupera soltanto un
`CandidateHandoff` o `LeadClosure` pienamente valido per schema, scope e source reference.
Se i cinque tentativi terminali terminano senza un output recuperabile, la lead resta
`suspected` con lifecycle `terminal_output_exhausted`; gli output emessi vengono persistiti
nel finding e il limite locale non produce un blocker tecnico.

Worker usa il candidate e sessioni actor separate per verificare dinamicamente il finding. Il contratto minimo Confirmer→Worker non richiede più una Recipe Card annidata: `verification_plan`, `success_signal` e `rejection_signal` sono tre stringhe piatte obbligatorie, complete ma adattabili al runtime. La Recipe Card strutturata è un acceleratore opzionale; se valida il Worker può seguirla dal primo step non completato, mentre se è assente l'harness proietta deterministicamente il piano piatto in una recipe compatibile a singolo step. Una recipe opzionale malformata non blocca la promozione di un candidate che soddisfa il nucleo statico e i tre campi piatti. Il Worker conserva l'evidenza, non ripete test riusciti e deve comunque raccogliere una baseline per prove differenziali o temporali. `ContinueInvestigation` persiste gli eventuali identificatori degli step strutturati completati, oltre al progresso narrativo e al prossimo esperimento già presenti nel checkpoint. L'assignment proietta anche prima azione concreta, actor, prerequisiti già soddisfatti, oracle, impedimento e progresso precedente usando piano e checkpoint autorevoli; non impone deterministicamente HTTP come primo tool. L'handoff iniziale non duplica gli snippet integrali raccolti dal Confirmer: consegna il piano di conferma e i metadata delle source reference autorizzate, mentre `read_source_ref` permette al Worker di recuperare on demand soltanto il codice necessario al prossimo test. L'access set del Worker include deterministicamente sia `finding.source_ref_ids` sia ogni source reference citata nella proiezione del piano, così un ref usato dal piano è sempre recuperabile. I candidate storici con Recipe Card o campi setup/baseline/exploit restano adattati in lettura. Se i metadata eccedono il budget del singolo prompt, l'orchestratore conserva tutti gli identificatori recuperabili e rimuove la proiezione ridondante di path e range. Quando il command executor è disponibile il Worker riceve sia `query_database`, per `SELECT` strutturate tramite il dossier runtime persistente, sia `run_target_command` per altre osservazioni read-only strettamente pertinenti nel servizio scelto dall'enum, incluse configurazione, route e verifica di effetti già prodotti tramite l'applicazione; non può usarli per fabbricare direttamente l'impatto. Il contratto di `run_target_command` rende obbligatori sia `argv` sia `script`: la modalità non scelta viene rappresentata rispettivamente da lista o stringa vuota, evitando parametri opzionali nello schema esposto al modello. Ogni ruolo che dispone di `run_target_command` dispone anche di `query_database`. Il Worker restituisce progresso investigativo o una decisione tipizzata
(`ConfirmedDecision`, `RejectedDecision`, `BlockedDecision`, `SuspectedDecision` o
`ContinueInvestigation`). Ogni uscita passa al proprio checkpoint prima della serializzazione
terminale. Riferimenti alle prove sono model-facing e autoritativi soltanto dopo validazione
di provenance; lo scope della lead viene collegato dall'harness. Non esistono richieste di
informazioni a Confirmer, routing inverso o nuove ammissioni economiche ai checkpoint.

Il dossier Confirmer→Worker aggiunge una sola `narrative` piatta che collega primitive,
controlli osservati, gap residuo, esperimento discriminante e criteri positivo/negativo.
Lead, area e stato operativo provengono dall'assegnazione; gli storici privi della relazione
vengono proiettati deterministicamente dai campi legacy, senza parser semantici. I campi
piatti necessari al controllo di flusso e al report restano tipizzati.

Con `WORKER_BROWSER_ENABLED` il provisioning Laravel crea un sidecar Playwright/Chromium isolato per audit su una rete dedicata, gli assegna l'origin target già autorizzato e un token casuale scoped alla run, poi lo rimuove insieme alla rete anche su errore o cancellazione. Il sidecar non riceve socket Docker, sorgenti o artifact; l'agente riceve soltanto l'URL interno e il token effimero. Chromium parte lazy con sandbox esplicita, profilo seccomp Playwright pinned e sole capability `SYS_CHROOT` necessaria al sandbox (tutte le altre restano droppate con `no-new-privileges`); i context sono separati per audit/categoria/lead/actor, service worker e download sono bloccati, popup e richieste fuori origin sono bloccati dal gateway. Il Worker, e nessun altro ruolo, riceve gli unici tool `browser_flow` e `inspect_browser`: step e assertion piatti, bounded, strict e senza CDP, shell o JavaScript arbitrario. Login UI conserva le credenziali nel lato privato del gateway; sessioni HTTP e browser dello stesso actor restano separate. Il context vive attraverso compression, checkpoint e retry Worker e si chiude solo alla lead terminale o alla fine dell'episodio Worker condizionale.

Ogni flow assegna un `action_ref` e checkpointa atomicamente azione, rete, dialog e osservazioni browser redatte nel medesimo outcome a due file, accanto alle transazioni HTTP senza inventare request id browser. `exploit_ref`/`test_ref` possono risolvere una transazione HTTP o una browser action; le proiezioni legacy restano leggibili. DOM, ARIA, page error, assertion UI e log generici sono diagnostici. Una conferma XSS browser richiede un evento con marker univoco, contesto pertinente, causalità del payload e `observation_ref` persistita della stessa action. Dialog esatto e cattura console contestualizzata sono oracle equivalenti; reflection, status 200 e impatto prodotto direttamente dalla shell restano insufficienti. L’orchestratore verifica ownership, episodio e provenance prima di promuovere il ledger. Il benchmark end-to-end usa il discriminante versionato `requires_browser_execution`; il benchmark Worker conserva e valuta separatamente action/observation browser, e un caso DOM-only non richiede traffico HTTP fittizio.

Il contratto browser comprende `submit_form`: una POST di navigazione reale, con query nel `path` e body nel campo piatto `form` come testo URL-encoded (anche con nomi ripetuti). Il gateway crea e invia una form tramite codice interno fisso, conservando documento, redirect e CSP della risposta; non renderizza risposte API tramite HTML sintetico. `set_cookie` e `clear_cookie` operano soltanto sull'host target e nel context dell'actor, usando `name`, `value`, `path` e attributi cookie espliciti. Queste azioni producono osservazioni di setup: non provano da sole controllo dei cookie della vittima o provenienza cross-site. Origin attaccante, frame interattivi, popup e upload restano fuori dal contratto corrente.

Playwright gestisce strictness e auto-wait senza pre-check di esistenza. Le assertion attendono il proprio timeout e un loro fallimento restituisce `assertion_failed`, distinto dagli errori degli step. Il modello riceve un riepilogo narrativo piatto con PASS/FAIL, osservazioni rilevanti e riferimenti citabili; i DTO delle assertion e dei collector restano interni. Ogni nuova chiamata del tool ha un identificatore di invocazione nuovo; il gateway serializza i flow dello stesso actor e deduplica soltanto ritrasmissioni con lo stesso identificatore, anche in-flight. Gli action ref derivano dall'identificatore di invocazione e non dalla lunghezza della finestra in memoria. Il timeout HTTP del gateway copre le attese ammesse dal flow. La rete usa una finestra scorrevole bounded che continua a raccogliere dopo la saturazione, con collector separato per azione e indicazione delle omissioni; i dialog hanno priorita' sul rumore console. Le WebSocket sono bloccate esplicitamente.

`inspect_browser` offre anche `forms`, con form, campi, opzioni e locator CSS senza valori degli input. `summary` descrive capacita', limiti e stato; `network` mostra metadati redatti inclusi metodo, status, nomi dei campi form, Content-Type, CSP, Origin e Referer. Tutte le modalita' leggono snapshot immutabili paginati tramite cursor scoped a context, mode e selector: avanzare non rilegge il DOM e non salta caratteri. Il gateway conserva fino a otto snapshot da 1 MB per context, segnala l'eventuale limite di acquisizione e richiede un selector piu' stretto; ogni pagina di massimo 12.000 caratteri viene conservata integralmente nell'osservazione browser persistita.

I prompt Confirmer e Worker richiedono esecuzione browser per dimostrare XSS, anche se il
candidate propone un success signal HTTP piu' debole. Il Worker valuta causalita', marker,
contesto e osservazione persistita: dialog e console contestualizzata sono oracle equivalenti,
mentre reflection e status HTTP non dimostrano esecuzione. La sufficienza semantica appartiene
al Worker; l'harness verifica gli ID e lo scope. Un trasporto necessario indisponibile produce
un blocco ambientale motivato; prove incomplete o un'indagine poco proficua restano suspected.

Il sidecar include un proxy privato per context vincolato a schema, host e porta dell'origin autorizzato. Il browser lo usa anche per loopback e redirect: il solo routing Playwright non intercetta gli hop successivi al primo. Le richieste HTTP e le POST vengono inoltrate senza modificare body o risposte; HTTPS usa CONNECT soltanto verso l'autorita' target, senza terminazione TLS o modifica della validazione dei certificati. Un redirect fuori origin viene bloccato prima di consegnare la richiesta e produce `policy_blocked`; i redirect same-origin conservano la normale semantica browser. Proxy, socket e connessioni vengono chiusi insieme al context; nessun endpoint aggiuntivo viene esposto al modello.

La finestra in memoria conserva 80 browser action; un cursore assoluto con offset mantiene coerenti assignment e gate della tranche anche quando le azioni piu' vecchie vengono rimosse dalla finestra.

Il Worker dispone sempre di `search_source`, `list_dir`, `read_file` e `read_source_ref`
sull'intero `source_root`. L'accesso resta verticale e vincolato alla lead assegnata: risolve
grammatica, route, trasformazioni, schema e mitigazioni utili al prossimo esperimento.
Le letture producono source reference canoniche e rispettano gli stessi limiti di path e
output degli altri ruoli; l'harness arricchisce la lead senza richiedere un Confirmer attivo.

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
a bassa cardinalita', un preview bounded degli header di sicurezza/CORS e una lookup
case-insensitive per nome. Il preview dichiara quando e' incompleto: un header non mostrato
non viene trattato come assente. Cookie e credenziali restano redatti, preservando gli
attributi di `Set-Cookie` necessari alla verifica. JMESPath non e' esposto al Worker. Questi tool risolvono soltanto response della
propria `Deps`/run, non accettano path filesystem e non effettuano nuove richieste HTTP.
Gli output sono bounded e marcano il troncamento. Le response restano disponibili tra epoch
boundary della stessa esecuzione, ma non generano file post-run.

Ogni ispezione riuscita di una response Worker genera inoltre un'osservazione derivata
bounded nel medesimo `evidence_ledger` atomico della transazione originaria. L'orchestratore
deriva `observation_id`, run/categoria/lead, request e response id dalla response store e dalla
transazione autorevole. Il Worker cita gli ID nella decisione finale. `observation_refs`
accetta direttamente request ID HTTP dell'episodio attivo, anche senza inspection:
il riferimento seleziona l'intera transazione registrata e rimane un request ID.
Alias response ID risolvono univocamente la richiesta; action e `tool-output-*`
risolvono le osservazioni quando disponibili nello scope. `exploit_ref`/`baseline_ref`
e `test_ref` selezionano le prove principali. Sono ammesse liste miste di richieste e
osservazioni, senza creare osservazioni sintetiche o espandere una richiesta nei suoi
estratti. Il body conserva la normale policy di redazione e troncamento; citare una
transazione non duplica il body nel payload model-facing. Oracle Worker v5 e report
accettano le medesime reference; i casi senza oracle classificabile sono esclusi
dall'accuracy semantica. Gli storici `*_request_id` restano alias in lettura. L'osservazione conserva locator, tipo di
estrazione, rappresentazione (`text_excerpt`, proiezione JSON o DOM), trasformazioni,
troncamento e redazione; una proiezione JSON/DOM non e' dichiarata copia byte-per-byte del
body. Gli estratti restano redatti e bounded, non sono un archivio di body; un risultato vuoto
o parziale non attesta l'assenza del comportamento fuori dalla porzione osservata.

Il Worker e' l'autorita' semantica dell'episodio dinamico. Parte sempre da un
`CandidateHandoff` valido (o input equivalente normalizzato al medesimo contratto), prodotto
dal Confirmer o importato da un artifact precedente: non occorre eseguire Confirmer nella
stessa run. Un ReaderLead grezzo non e' un ingresso Worker. Il contratto conserva
`verification_plan`, `success_signal` e `rejection_signal` obbligatori.

Ogni uscita investigativa, yield o boundary operativo porta al `WorkerCheckpoint` piatto:
`decision=continue|confirmed|rejected|suspected|blocked`, `reason`, `decisive_question` e
`next_step`. `continue` richiede una domanda decisiva e un prossimo esperimento concreto.
Il checkpoint valuta utilita' marginale, impedimenti e sufficienza delle prove senza tool.
La continuazione mantiene scope, history e sessione e usa il residuo dell'envelope gia'
ammesso; non concede nuove quote. Il verdict terminale viene bloccato nella stessa
conversazione e serializzato senza tool; i retry correggono il payload senza cambiare esito.
System prompt, definizioni dei tool e unione degli output restano stabili tra le fasi;
l'harness respinge invocazioni operative durante checkpoint, terminalizzazione e
riparazione di un output Worker respinto dal validatore. Gli errori attesi del contratto
diventano `ModelRetry` anche nel percorso investigativo: il modello riceve campo,
motivo e riferimenti validi. L'esaurimento dei retry di output non riavvia il turno
tramite i retry provider. `output_validation_by_role` registra retry, recuperi ed
esaurimenti separatamente da trasporto, costo ed esperimenti; il batch conserva i delta.

`SuspectedDecision` conserva motivo, evidence gap, prossimo test ed eventuali osservazioni.
Errori tecnici esauriti, limite economico e output non applicabile conservano la lead
`suspected` con lifecycle `worker_stopped` e `worker_stop_cause` esplicito. Il ledger
mantiene tutte le prove raccolte. `blocked` richiede un impedimento concreto osservato:
limiti locali, prove mancanti o scarsa utilita' di continuazione non sono rigetti o blocchi.

Il Worker seleziona gli ID decisivi: `exploit_ref`, `baseline_ref`, `test_ref` e
`observation_refs` sono model-facing, mentre `lead_id` appartiene all'harness. Prima di
applicare confirmed o rejected, l'orchestratore verifica campi non vuoti, identita' della
lead ed esistenza/provenienza delle prove nell'episodio Worker attivo. Gli alias sono
normalizzati soltanto se univoci; ID inventati, ambigui o fuori scope richiedono un retry.
Prove differenziali o temporali richiedono baseline distinta; senza baseline una prova
diretta richiede una motivazione. L'harness non interpreta status, body, timing o contenuti
per decidere la vulnerabilita'. Il report persiste `adjudication.role=worker`, decisione,
trace e `evidence_validation` v3 (`mode=structural_provenance_only`). Gli evaluator leggono
anche le attestazioni storiche `dynamic_judge` senza introdurre un supervisore nel runtime.

Nel solo percorso filtrato multi-categoria, Handoff Reader produce il contesto riutilizzabile dalla categoria successiva con soli fatti
`topic`/`fact` e `unknowns` model-facing. Le source ref dell'episodio restano un indice
separato dell'handoff e non vengono assegnate indiscriminatamente a ogni fatto. Se non
termina correttamente, l'orchestratore genera l'handoff dal report e dallo stato durevole.

## Ledger e resilienza

Il ledger è la fonte canonica di lead, source reference, transazioni e finding. Ledger e
report del singolo pass usano lo schema 9; il report root multi-pass usa lo schema 10. I precedenti artefatti
`CategoryRecon` v2 non vengono migrati o riletti. Solo l'orchestratore applica al ledger i
verdetti terminali del Worker dopo il gate strutturale e di provenance. `blocked` è
riservato a impedimenti ambientali o tecnici reali:
l'esaurimento locale di richieste o score non trasforma da solo una lead in blocked.

Le source reference create durante una tranche Confirmer sono scoped alla lead attiva e
vengono unite deterministicamente all'output terminale: `CandidateHandoff` e `LeadClosure`
non possono eliminare quelle iniziali o quelle nuove il cui file è citato nei campi
strutturati riscrivendo una lista incompleta. Anche le osservazioni sorgente
persistono il `lead_id`; l'evaluator benchmark può quindi ripristinare location scoped
omesse dalla serializzazione terminale senza usare `files_seen` come prova di finding. Per
i report schema 8 storici privi di `lead_id` sull'osservazione, il recupero è ammesso solo
quando l'intero report contiene un unico finding e quindi l'attribuzione non è ambigua.

Dopo ogni tranche e transizione vengono aggiornati checkpoint, report parziale e telemetria. I fallback entrano in funzione solo dopo il turno terminale e i retry, senza ulteriori chiamate al modello. Se manca il report finale, il benchmark usa i finding durevoli del report parziale, conserva i costi effettivi e marca le metriche come parziali. Lo schema 9 include osservazioni sorgente deduplicate (`role`, `lead_id`, `tool`, `file`, intervallo di righe), milestone storiche `suspected`, `statically_validated` e `dynamically_confirmed`, adjudication semantica delle lead, gap dinamico e stato di funding; non persiste una seconda copia degli snippet. `coverage.reader_area_checkpoints` conserva l'ultimo checkpoint semantico bounded di ogni area.

Il report schema 9 aggiunge `evidence_ledger`, il registro durevole delle transazioni HTTP:
ogni tool HTTP registra risposta o timeout, actor handle, request/response ID, metodo, path,
status, timing, redirect ed excerpt bounded, con payload e header sensibili redatti. La
scrittura è atomica e sincrona prima che il tool restituisca il controllo al modello: un
errore di persistenza è un hard stop infrastrutturale, perché nessuna prova può restare
solo in memoria. Le pubblicazioni parziali/finali successive fondono il ledger esistente e
non possono cancellarlo, inclusi cambi categoria, errori di serializzazione o report
incompleti. Il Worker puo' recuperare transazioni e osservazioni durevoli della lead
con `inspect_persisted_evidence`, incluse quelle fuori dalla finestra recente. Nessun fallback
dell'orchestratore conferma o respinge una vulnerabilita' dai contenuti delle prove. Se il
Worker non produce un verdetto applicabile, la lead resta suspected e conserva transazioni,
osservazioni e gap. Le pubblicazioni e il recovery fondono gli elementi per identita' e scope.
Ogni transazione include anche una proiezione piatta e redatta della request: actor, metodo,
path, Origin, Referer, content type, nomi degli header, campi del body ed excerpt bounded.
Cookie, authorization, password e token non espongono mai i rispettivi valori.

Il report include anche `category_recon_summary`, una proiezione compatta della Recon
iniziale (`status`, aree ordinate, locator principali, `next_check` e unknowns). Lo stesso
risultato compatto viene stampato in console come `[category_recon:result]` nelle run
normali e nei benchmark, mentre il `category_recon` completo resta in `coverage`.

## Attori applicativi autorizzati

Il profilo `lailaps.audit.yaml` accetta una root opzionale `actors`: ogni record dichiara
`password`, `role`, una `description` opzionale e almeno uno tra `username` ed `email`;
l'assenza della root mantiene la
condotta precedente, con solo l'attore implicito `anonymous`. Laravel valida il profilo e
genera un file JSON effimero con permessi restrittivi, montato read-only nell'agente e
cancellato in `finally`: contenuto e segreti non entrano in argv, outcome, ledger, eventi o
log. Python assegna handle opachi derivati da ruolo e posizione (es. `administrator-1`) e
Confirmer e Worker ricevono nel prompt privato credenziali e descrizioni;
Reader, Recon e Reviewer restano ignari delle utenze. Nessuna sessione viene preautenticata.
Le sessioni HTTP sono isolate per handle e persistono tra round e lead della stessa run;
prima di ogni round Worker una vista senza valori segreti espone stato vuoto o materiale
riusabile, nomi di cookie/header e ultima request dell'actor. Questa vista offre contesto e
opzioni senza imporre login, riuso o cambio identità. Il Worker può selezionare liberamente
un altro handle, usare `anonymous` oppure azzerare il solo stato HTTP locale con
`reset_actor_session`; il logout applicativo resta una normale richiesta al target.

## Benchmark dedicato Recon

`benchmark:recon` e' un percorso Recon-only distinto da `benchmark:run`: materializza la
stessa vista sanitizzata, inizializza Codebase Memory e Surface Context, esegue il reale
handoff Recon e termina prima di creare il Reader. Con `--global` unisce i casi distinti di
tutti i manifest dello stesso target e snapshot; duplicati incompatibili rendono invalida la
fixture. Di default usa soltanto il sorgente; con `--reuse-sandbox` ricollega una sandbox
Lailaps gia' pronta dello stesso target e snapshot, verifica la readiness e rende disponibili
i comandi nel target, come `benchmark:recon-reader`. Non prepara una nuova sandbox ne' esegue
fixture probe. La ground truth resta fuori
dal processo agente e viene letta dall'evaluator Laravel soltanto dopo l'handoff. L'outcome
persiste Recon, Area Ledger iniziale, Surface Context, tool telemetry, costo e punteggi per
caso. L'evaluator separa exact locator, guidance semantica e sola famiglia pertinente e
riporta strict/guided/weak recall, score normalizzato, recall@1/3/5, aree, locator e unknown.
Con `--global --assignments` la sola Recon usa il contratto funzionale `ReconPlan` gia'
usato da `benchmark:recon-reader`: l'orchestratore assegna gli ID, normalizza il briefing
e gli incarichi in `CategoryRecon` e termina senza avviare Reader. In questa modalita'
solo un piano acquisito puo' essere promosso a Golden Recon. `benchmark:reader-global` accetta una Golden Recon
ready dello stesso target e snapshot con almeno un incarico, senza imporre il numero di
aree della fixture YesWiki; ogni figlio riceve un solo incarico e il briefing comune.
Le ripetizioni del comando producono run e outcome distinti. Il profilo globale iniziale
concede 32 richieste investigative, 640.000 punti, 24.000 token di output e fino a 48 aree;
la Recon categoriale conserva i limiti precedenti. La Global Recon dispone di lettura file,
segnali statici, recupero degli output e `run_code` con i soli binding statici
`search_source`/`read_file`, oltre agli strumenti architetturali. Il benchmark impone per
default un timeout di 20 minuti: il runner rimuove il container prima che Laravel legga
l'ultimo outcome atomico o elimini il sorgente temporaneo.

Nel registry il lineage e' `CategoryRecon -> ReconArea`; i figli contengono soltanto area,
indice e riferimento al parent, mentre contesto comune, usage e configurazione rimangono sul
parent. Le query per la fixture completa filtrano sempre `output_type=CategoryRecon`.

## AgentBench per modello e ruolo

Il benchmark end-to-end precedente resta la misura della harness e del flusso complessivo.
In parallelo il finalizer materializza un asse separato `AgentBench` in
`benchmark_role_evaluations` e `benchmark_role_case_results`: una scorecard per ruolo,
modello effettivo, benchmark e ripetizione. Le scorecard non modificano lo score globale e
riusano i casi, gli anchor, gli oracle e lo snapshot target gia' versionati; la loro identita'
include versione dell'obiettivo, evaluator, harness, target commit, budget e modelli di
controllo. Due righe sono quindi confrontabili soltanto quando la
`comparison_signature` coincide.

Gli obiettivi P0 seguono le responsabilita' osservabili del ruolo: Recon misura coverage
strict/guided/weak e recall@K sugli anchor; Reader misura `suspected`, Confirmer
`static_validation` e Worker `dynamically_confirmed`, di cui possiede la decisione.
Reviewer mantiene la proxy discovery nei report che lo esercitano. Gli obiettivi a casi
usano F1 contro positivi e negative control e conservano TP/FP/FN e risultati per caso.
Un ruolo senza richieste e' `not_exercised` e non riceve uno zero artificiale. Le scorecard
storiche Judge sono leggibili soltanto quando la run contiene la relativa telemetria.

`benchmark:experiment:run` accetta sia la matrice completa `models`, sia
`subject_role`, `subject_models` e `control_models`: nel secondo caso varia un solo ruolo e
registra `benchmark_subject_role` nella run. Per Recon il modello soggetto occupa il normale
slot Reader, usato anche da Category Recon. `benchmark:role:compare` aggrega media,
deviazione standard, target trovati, token, cache hit, USD e TP per 1.000 token; per default
considera soltanto run dove il ruolo era la variabile controllata.

L'esperimento supporta due execution mode con identica persistenza. Il default crea prima
l'intera matrice e accoda ogni `AuditRun`; `--foreground` crea la stessa matrice ma invoca lo
stesso executor del queue job in sequenza, una run alla volta. Il callback del processo
continua a scrivere il transcript durevole nel file canonico e contemporaneamente lo
rispecchia sul terminale. Finalizer, scorecard, lifecycle e firme di comparabilita' restano
gli stessi; cambia soltanto chi guida temporalmente l'esecuzione.

La telemetria economica delle scorecard e' role-scoped e conserva richieste, input cached e
uncached, output, total token, costo provider, stima, completezza del costo, pricing basis e
pesi. `report.telemetry.pricing` congela l'intero rate card di `model_prices.json` insieme a
checksum, schema, data e fonte; la scorecard ne estrae la tariffa del modello effettivo. Le
modifiche future al listino non possono quindi reinterpretare retroattivamente il costo di
una run storica. Un costo incompleto resta `null`.

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
Se la lead contiene un `primary_location` validato dall'orchestratore contro una source
reference osservata, il matching del finding usa esclusivamente quel punto; le location di
supporto non possono attribuire credito a un altro sink nello stesso file.

Lo score normalizzato è il netto dei punti dei casi positivi meno il miglior livello
dichiarativo raggiunto sui soli casi esplicitamente negativi, limitato tra zero e il
massimo `5 × positivi`. I finding senza match restano open-world e rendono
l'adjudication provisional senza penalità automatica. Detection e confirmation restano
alias compatibili rispettivamente di suspected e dynamic confirmation.
Nelle run `--global` questo score resta la misura chiusa di catalog coverage e non assorbe
la discovery open-world. L'aggregato espone in parallelo `discovery_yield`, una misura
assoluta senza denominatore: ogni finding durevole unico vale il solo miglior livello
raggiunto, 3 punti se `statically_validated` e 5 se `dynamically_confirmed`; suspected e
rejected restano visibili ma non ricevono credito. La scorecard separa finding accreditati
matched al catalogo e fuori catalogo, senza reinterpretare questi ultimi come true positive
del manifest. Prima dell'aggregazione globale, match e pending vengono riconciliati a livello
di intera suite: un finding associato a qualunque manifest non resta unmatched per effetto
dell'ordine con cui sono stati valutati gli oracle, e costo e finding condivisi sono contati
una sola volta. Non viene prodotto uno score open-world normalizzato, poiché il numero totale
dei sink reali non è noto.
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
il reasoning effort sono registrati in `audit_run_models` per Category Recon, Reader,
Reviewer, Confirmer e Worker; il provider non e' parte dell'identita'
sperimentale.

La telemetria Worker espone tool call per ruolo e lead, breadth sorgente, tempo e richieste
prima della prima HTTP. `worker_checkpoint_events` registra decisione, domanda decisiva,
prossimo passo, nuove transazioni/source ref, causa di uscita e delta dei tool per nome,
raggruppati in HTTP/source/runtime. I contatori distinguono self-checkpoint, continuazioni
e round senza nuova evidenza; le cause economiche e tecniche rimangono esplicite.
I boundary Reader, Confirmer e Worker sono contati per ruolo. Gli eventi e le metriche dei
supervisori storici restano leggibili, ma non sono emessi dal nuovo flusso Worker.

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

Per iterazioni locali, `pentest:run --reuse-sandbox=<audit-id>` e
`benchmark:run --reuse-sandbox=<audit-id>` ricollegano una sandbox Lailaps ancora running,
precedentemente lasciata con `--keep`, invece di buildare o avviare un nuovo target. Il
service ricostruisce il `SandboxDTO` dalle label autorevoli, ne verifica scadenza, servizio
HTTP e readiness e passa ancora `target-container-id` all'agente: i tool console restano
quindi disponibili. La run riusata non esegue setup mutante e non può smontare la sandbox
di origine; verifica readiness e fixture probe prima dell'agente e ripete i soli probe
post-run non mutanti. Questa è una modalità esplicitamente stateful, adatta a debug e
sviluppo ma non alla misurazione benchmark ripetibile, che richiede un runtime/DB pristine.

La resilienza modello ha tre livelli separati: il transport OpenRouter gestisce gli errori
HTTP transitori, Pydantic AI corregge parsing e validazione nello stesso episodio, quindi
l'orchestratore può rilanciare l'intero turno due volte. Ogni tentativo viene contabilizzato
separatamente. Quando un tentativo Worker ha già prodotto transazioni HTTP, il retry riceve
il loro riepilogo e il divieto di ripeterle. La telemetria espone per ruolo retry tentati,
recuperati ed esauriti.

Un ciclo Reader esaurito conserva l'episodio se la stessa area e il contesto sono ancora utilizzabili.
Due cicli consecutivi esauriti, pari a sei tentativi complessivi, attivano il circuit breaker:
il pass viene pubblicato incompleto con `termination_reason=model_unavailable`. Il percorso
filtrato puo' produrre l'handoff deterministico previsto; nel percorso globale il failure
interrompe gli eventuali pass successivi. Un output
Reader valido azzera il contatore. Recon, Reviewer, Confirmer, Worker e Handoff
restano contenuti nei rispettivi fallback descritti nelle sezioni dei ruoli.

## Budget e richieste

### Conferma benchmark per singola CVE

`benchmark:cve:run {target-id} {case-id}` seleziona una case dal manifest e monta un DTO CVE
privato nell'agente con categoria, CWE, anchor, root cause, oracle e fixture. L'orchestratore
crea una lead dalle anchor e avvia Confirmer, quindi Worker dal CandidateHandoff valido.
`--envelope-points` limita il consumo condiviso, senza discovery o overdraft. Il Worker
usa anche il proprio cap configurato per handoff, limitato dall'envelope totale, e i
checkpoint operativi non rinnovano punti. L'outcome e l'evaluator restano quelli end-to-end
applicati alla case selezionata; l'esaurimento conserva il lavoro come suspected.

L'accounting usa `pricing_schema_version: 2`: per ogni richiesta i pesi sono derivati dal prezzo del modello effettivamente servente in `model_prices.json`, con anchor fisso `google/gemini-3.7-flash` ($0.375 input e $1.875 output per milione) e floor `0.2`. Il peso cache è il rapporto reale, limitato a `0.1x`, per sussidiare intenzionalmente il riuso della cache. Le voci `pricing_basis: simulated:*` producono punti ma non entrano in `provider_cost_usd`; telemetry e benchmark le distinguono, e lo schema segmenta i confronti economici incompatibili.

Il budget economico usa una allowance discovery e un cap di 100.000.000 EP per pass. Non
esiste hard cap sui raw token cumulativi: input uncached, cached e output contribuiscono al
consumo con pesi per modello distinti. `--budget-category=small|regular|big|huge` scala
soltanto discovery (1.500.000, 3.000.000, 4.500.000, 6.000.000 EP per pass). Envelope
indipendenti dal preset e dal peso del modello: Confirmer 500.000 EP iniziali e 250.000 per
continuazione; Worker 1.000.000 per handoff senza rinnovi. Il peso del modello si applica al consumo, mai al grant. Cap, impegni, uso e overshoot
restano visibili negli snapshot; gli artifact storici non vengono ricalcolati.

Nella run globale il cap discovery misura soltanto Reader; Recon usa una quota distinta.
Nel percorso filtrato Recon, Reader, Reviewer e Handoff condividono discovery. Ogni lead ammette Confirmer indipendentemente;
la promozione a CandidateHandoff rilascia il residuo statico e ammette un solo envelope
Worker. Questi stage non erodono discovery. Alla chiusura gli impegni inutilizzati vengono
rilasciati senza cancellare i consumi. Nel Worker standalone l'ammissione del candidate non
richiede uno stage Confirmer precedente e non puo' riaprire un envelope gia' chiuso.

I request limit sono guardrail anti-loop, non budget economici e non pacing ordinario. Il
Confirmer riceve una tranche iniziale da 12 richieste e ogni estensione concede fino
a 32 richieste, con massimo operativo 64. Prima di concedere ogni estensione esegue il
self-checkpoint tool-free sulla stessa conversazione; soltanto `continue` apre una nuova
tranche. `candidate_handoff` e `lead_closure` avviano la terminalizzazione tool-free e i retry
di output correggono la serializzazione senza riaprire la decisione. La telemetria distingue
`full_grant`, `partial_grant` e `terminal_only`. Il cap controlla esclusivamente il consumo
e non esprime un verdetto tecnico: quando non consente un'altra tranche, l'orchestratore
conserva la lead suspected (`reviewer_stop` nel percorso statico, `worker_stopped` nel
percorso dinamico). La terminalizzazione tool-free mantiene una riserva di retry di output
piu' generosa per assorbire problemi di serializzazione strutturata. Il limite della
singola risposta e' 6.500 token per Reader e ruoli generici, 5.000 per Reviewer, 12.000 per Recon e
14.000 per il Confirmer. Il guardrail predefinito dell'input e' 230.000 token stimati,
includendo history, nuovo prompt, prompt persistente e schemi: resta distinto dalla
finestra operativa da 256k e permette la review della history Reader disponibile. Un
rifiuto locale `PromptInputGuardExceeded` e' deterministico e non viene reinviato identico
nella retry ladder. La capacita' utile della history viene calcolata per ruolo e fase
sottraendo il limite reale della risposta, oltre al prompt persistente e al margine di
sicurezza; non usa quindi una riserva generica inferiore al cap del ruolo. Prima della
terminalizzazione Confirmer/Worker un preflight verifica che history, nuovo prompt e
riserva di risposta entrino nella finestra effettiva: quando non entrano comprime la stessa
conversation, senza sostituirla con il solo checkpoint. Tutti i ruoli dispongono inoltre
di due retry completi per gli errori tecnici retryable del modello. Recon conserva richieste
investigative dedicate e retry Pydantic di output. Il Reviewer ha prompt/output propri
e riceve una copia read-only dell'episodio Reader. Se il budget a score termina durante un task attivo, la produzione
dell'output ha priorita' sul consumo raw.

Ogni singola richiesta LLM ha una deadline wall-clock configurabile, inizialmente 240
secondi, applicata allo stream del relativo model request e distinta sia dal timeout HTTP
di inattivita' sia dalla durata dell'intera sessione, dei tool e dei backoff. Il recovery conserva
messaggi e tool result completati; per il Worker reinietta le transazioni HTTP gia'
persistite e vieta di ripetere automaticamente operazioni mutanti note.

Il Worker usa un envelope di 1.000.000 EP per handoff, indipendente dal preset discovery e
dal prezzo del modello. Finestre operative da 32 richieste scandiscono i self-checkpoint;
non sono quote economiche. Prima di nuove richieste investigative viene conservata una
riserva per checkpoint, terminalizzazione e retry di output pari al minimo fra 300.000 EP
e un quinto dell'envelope. L'overdraft non eleva il cap. Anche un quinto candidate puo'
essere ammesso se il cap globale finanzia l'intero envelope, senza slot numerici.
Telemetria e snapshot riportano `lead_stage_unlocked`/`lead_stage_denied`, uso, funding,
residuo, overshoot e admission failure separati per `confirmer_stage` e `worker_stage`;
non esiste uno stage Judge. Un'ultima richiesta gia' ammessa puo' sforare la stima: il consumo
resta visibile, mentre checkpoint e serializzazione hanno retry finiti e nessuna nuova
indagine dopo l'esaurimento.

I delta dei tool, le operazioni precedenti alla prima HTTP e i round senza nuova evidenza
servono a valutare autonomia e qualita' degli handoff; non applicano soglie semantiche o
transizioni automatiche al ledger.

La proiezione canonica `report.telemetry.tool_calls_details` aggrega tutte le categorie,
epoch e lead per identita' di ruolo e nome stabile del tool. Ogni voce espone `enabled`,
`available` e `calls`: `available=true` significa che lo schema e' stato esposto almeno una
volta in una richiesta investigativa costruita per quel ruolo, mentre `calls` incrementa al
boundary del dispatcher appena una tool call del modello viene ricevuta, inclusi parametri
invalidi, errori e timeout. Il medesimo `tool_call_id` non viene ricontato su replay o
compression e i retry interni del backend non producono call aggiuntive. I tool disponibili
ma mai usati conservano zero esplicito; `enabled=false` distingue il rollback/configurazione
spenta. I checkpoint e il finalizer proiettano sempre la stessa istanza autorevole della
telemetria, quindi finalizzazioni ripetute non incrementano i contatori. Outcome storici non
vengono migrati con zeri inventati e possono non contenere la sezione.

Il Worker costituisce inoltre un'eccezione al cap generico di 5.500 token: usa un limite dedicato
di 14.000 token, riservato nel calcolo della capacita' di history, per evitare retry completi
causati dall'esaurimento dell'output.

Nel retry successivo a una token exhaustion, il Reviewer usa sempre reasoning `low`; gli
altri ruoli continuano a usare l'effort di retry configurato.

Dopo la lavorazione completa di una lead, il Reader riparte soltanto se la sua allowance
residua finanzia almeno due turni medi della grant policy: uno per ottenere nuova evidenza
e uno per serializzare una lead. Una coda inferiore termina come
`insufficient_reader_tail_budget`, evitando epoch che possono consumare il residuo ma non
produrre un nuovo output durevole.

Restano validi soltanto limiti token non economici: capacità della context corrente, riserva di risposta e margine di sicurezza, dimensione della singola risposta e troncamento o paginazione degli output dei tool.

## History, cache e compression

### Vincolo obbligatorio: le fasi dello stesso agente preservano il prefisso

Discovery, self-checkpoint, produzione del summary di compaction e serializzazione
terminale sono fasi dello **stesso agente**, salvo un cambio di agente esplicito.
Una transizione di fase non autorizza a sostituire il contratto inviato al provider.
Devono restare identici modello, system prompt, provider session ID, definizioni e
ordine dell'intero toolset, inclusi gli schemi degli output strutturati. La history
gia' inviata deve mantenere contenuto, ordine e rappresentazione: le istruzioni della
nuova fase si aggiungono in coda, senza riscrivere il prefisso o ricostruire la
conversazione come un nuovo prompt. Riutilizzare soltanto l'oggetto conversation o
il `session_id` non soddisfa questo requisito.

Cambia **quale azione/output e' abilitato**, non quali definizioni sono presenti
nella richiesta. Il catalogo stabile comprende fin dall'inizio i contratti delle
diverse fasi. Nel checkpoint le chiamate operative (letture, ricerche, probe) sono
bloccate: il modello deve produrre un output terminale ammesso, anche il solo
`ReaderCheckpoint` che autorizza a continuare la discovery. I tool di output
strutturato non sono tool investigativi. "Tool-free" indica questa restrizione
operativa, **non la rimozione dei tool o la sostituzione dello schema**.

La restrizione va applicata con un meccanismo di selezione degli output compatibile
con il provider e con un controllo nell'harness prima di eseguire qualsiasi tool
operativo. Il solo prompt non basta. Un filtro SDK che elimina o riordina le
definizioni prima dell'invio viola il vincolo, anche se chiamato `tool_choice` o
`prepare_tools`. La verifica riguarda la richiesta realmente serializzata, non
soltanto la configurazione dell'Agent.

Per la compaction distinguere la **richiesta che produce la memoria**, che deve
ancora leggere la history integra con il prefisso stabile, dall'**applicazione
della memoria**: sostituire i vecchi messaggi con il summary cambia necessariamente
la history della nuova epoch e puo' perdere la cache di quella parte. Questo e'
un costo esplicito della riduzione del contesto; non giustifica invalidare prima
anche la richiesta di compaction. Non comprimere, filtrare o ripacchettare la
history solo per entrare in checkpoint. I recovery che devono scartare messaggi
corrotti sono eccezioni tecniche esplicite, da registrare, non transizioni ordinarie.

La stabilita' del prefisso rende possibile il cache hit, non lo garantisce: routing,
scadenza e politiche del backend restano esterni. Le verifiche devono confrontare
toolset/schema e prefisso dei messaggi prima/dopo il cambio di fase e misurare
input cached/uncached per fase. Un contatore di sessione invariato non prova il riuso.

**Stato di conformita' (27 settembre 2026):** il Reader operativo e il suo
self-checkpoint costruiscono lo stesso catalogo ordinato di tool e output, con lo
stesso system prompt e session ID. La fase checkpoint blocca nell'harness le
chiamate investigative pur mantenendo le definizioni nel payload. I tool Reader
sono eseguiti sequenzialmente tramite la barriera nativa Pydantic AI: anche batch
grandi non moltiplicano le esecuzioni sincrone e i relativi thread. La compaction
inizia una nuova epoch solo dopo la decisione semantica. I percorsi di summary
Confirmer/Worker con prompt dedicato restano distinti e vanno verificati a parte.

Reader mantiene una conversazione append-only dentro l'area attiva attraverso review
periodiche, approfondimenti e ritorni downstream; Confirmer per lead e Worker per candidate.
Recon e Handoff la mantengono per il rispettivo episodio. L'uscita
eccezionale da uno stream persiste tutti i messaggi gia' osservati prima di trasferire il
controllo. Ogni nuova epoch Reader svuota la history e cambia conversation ID mantenendo lo
stesso provider session ID; system prompt, toolset e output schema restano invarianti. La
memoria reiniettata e' un dossier bounded della sola area attiva, non l'intera CategoryRecon.
L'Exploration Reviewer riceve la history disponibile dell'episodio Reader come copia
read-only portabile fra modelli: messaggi, tool call, risultati e reasoning visibile sono
proiettati senza metadata provider firmati; nessuna tool call storica viene rieseguita.
Il Reviewer conserva prompt e output propri, persiste la decisione e l'orchestratore
l'aggiunge alla history Reader originale.
Il dossier Reader conserva inoltre i delta accettati dopo l'ultimo checkpoint: narrativa
della lead, source reference e stato downstream vengono aggiunti alla history originale
e restano separati dal lifecycle autorevole. Il successivo checkpoint narrativo del
Reviewer li integra; se la review fallisce resta valida la sintesi precedente insieme al
delta esplicito. Le source reference citate nella memoria attiva e quelle selezionate dalla
lead hanno priorità sugli excerpt recenti. Il Reader può recuperare una ref autorizzata con
`read_source_ref` e paginare l'indice metadata con `list_source_refs`; ref sconosciute o
fuori area non ampliano lo scope. Ogni nuova epoch ricostruisce anche il catalogo notebook.
Il contatore del boundary di review appartiene all'area e non viene azzerato quando una lead
torna dal downstream: se la soglia e' maturata, il Reviewer interviene prima di un altro giro
di sibling discovery. Le superfici controllate vengono ricostruite dalle source reference
autorevoli accumulate nell'area, incluse quelle delle lead e dei downstream, separatamente
dal delta di novita' della tranche. La chiusura discovery di un'area non dipende dalla coda
downstream delle lead. Un pivot verso un'altra area autorevole cambia davvero l'area attiva,
conserva checkpoint e rami residui dell'area sospesa e permette di riprenderla; decisione
proposta e applicata, con eventuale causa di normalizzazione, sono persistite.
L'Exploration Reviewer scandisce soltanto i boundary cognitivi/event-driven del Reader e
`AreaEnrichmentLead`; la novelty review interviene prima di inserire una `ReaderLead` quando
esiste gia' uno stato adjudicated. Il Reviewer non appartiene al percorso
Worker e modifica il ledger soltanto indirettamente tramite il verdetto di novita' applicato
dall'orchestratore. Il Worker esegue i propri self-checkpoint sulla stessa history. La prima lead di una categoria non richiede confronto di novita' perche'
non esiste ancora un antecedente adjudicated.

Per il Reader la finestra tecnica e quella cognitiva sono distinte. `model_prices.json`
contiene prezzi, provider di riferimento, capacita' fisica e `cognitive_window_tokens`:
default 128.000, override 256.000 per MiMo-V2.6-Pro e DeepSeek V4.1 Flash. La finestra
effettiva della history e' il minimo tra finestra cognitiva, capacita' fisica al netto di
prompt/schema, output e margine, e cap esplicito dell'input completo. Il clamp e il motivo
sono persistiti. Soft 75% e hard 90% si applicano una sola volta alla finestra effettiva:
96.000/115.200 per 128k, 192.000/230.400 per 256k non clamped. Il controllo precede
la richiesta successiva; una review periodica che gia' fornisce memoria sufficiente puo'
applicare subito la compaction includendo il prossimo step, senza un checkpoint equivalente.
Per Confirmer e Worker la compression dipende
dalla pressione reale della context, non dallo score economico o dal semplice completamento di un turno.
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

Recon non usa la compression episodica. Il percorso categoriale conserva la tranche breve;
quello globale puo' usare fino a 32 richieste investigative. Se la history raggiunge la
soglia hard del 90%,
l'orchestratore disabilita i tool e richiede immediatamente l'output terminale sulla
history integra. La telemetria registra `recon_status`, `recon_terminal_reason` e rende
quindi esplicita qualsiasi futura regressione che introducesse una compaction Recon.

## Esperimento Recon -> Reader per incarichi funzionali (P0)

Il branch sperimentale espone `benchmark:recon-reader {target-id}` in Laravel e
`pentest recon --global --with-reader` in Python. Esegue una Recon nuova e poi un solo
Reader attivo alla volta, riusando il lifecycle Reader/Exploration Reviewer esistente.
Il flag interno `recon_reader_test` richiede modalita' globale e `stop_after_reader`:
Confirmer, Worker e Handoff non vengono eseguiti. I comandi ordinari conservano
il proprio contratto Recon. Questo esperimento valuta il percorso congiunto, non Recon
in isolamento.

Ogni ingresso che inizializza il Reader espone per run
`--reader-checkpoint-strategy=reviewer|reader_checkpoint`, con `reviewer` come default:
`pentest scan`, `pentest reader`, `pentest recon --with-reader` e i relativi comandi
Laravel `pentest:run`, `benchmark:run`, `benchmark:reader`, `benchmark:reader-global` e
`benchmark:recon-reader`. Anche gli shortcut e le matrici benchmark propagano lo stesso
valore; il fan-out globale lo applica indipendentemente a ogni assignment. La scelta vive
in `Deps`, non in settings globali, ed e' persistita nei report e negli outcome disponibili.
Nel secondo percorso gli stessi identici boundary ordinari chiedono al modello Reader un
turno tool-free sulla sua `ReaderConversationState`, conservando modello, sessione, epoch e
history append-only. Puo' restituire direttamente `ReaderLead` o `AreaEnrichmentLead` gia'
sostenuti dalle evidenze, oppure `ReaderCheckpoint`. Il contratto di quest'ultimo e' piatto: decisione
`continue|area_closed`, motivo, singolo `next_step` obbligatorio solo per `continue`,
`checkpoint_summary` e `category_notes` opzionali. Area, source reference, superfici lette,
scheduling e completion restano autorevoli nell'orchestratore, che converte il risultato
nell'`ExplorationReview` interno e riusa grant, stale guard, chiusura area e transizioni epoch
esistenti. La factory checkpoint mantiene toolset e output schema operativi;
il flag di fase nel runtime blocca l'esecuzione dei tool investigativi senza
rimuoverne le definizioni. Errori tecnici o
structured-output exhaustion producono la continuazione
conservativa corrente senza cascata verso il Reviewer.

I prodotti restituiti al checkpoint attraversano la stessa acquisizione degli output Reader
operativi prima di qualsiasi nuova richiesta. Non chiudono l'area; in caso di budget esaurito
la lead valida resta acquisita con novelty `unresolved`, mentre una proposta enrichment
valida resta `pending_review` e non viene accodata finche' manca l'approvazione del Reviewer.
Nel benchmark globale self-checkpoint tutte le proposte enrichment restano pending
per la valutazione offline e le lead non richiedono novelty review online.
Il parent del benchmark distingue questa proposta da quelle respinte e da quelle approvate
ma differite. Un prodotto non inventa una decisione `continue` o `area_closed`.

I self-checkpoint sono contabilizzati sotto `reader`, non `reviewer`. Telemetria ed artifact
registrano strategia, boundary originario, epoch/sessione, tipo di output e, quando presente,
decisione proposta e applicata,
normalizzazione/fallback e delta di richieste, input cached/uncached, output, USD e relativa completezza, ed EP. Restano
inoltre i contatori concettuali degli exploration boundary; le review enrichment sono
contate separatamente. L'outcome Laravel conserva la strategia per ogni repetition, senza
stato globale mutabile fra run.

Recon parte dal briefing deterministico del progetto, da Codebase Memory quando
disponibile, dalle directory e da letture mirate del sorgente. Riceve lo stato dei
sensori, senza il censimento iniziale degli hotspot. Dopo una prima mappa funzionale,
il prompt richiede di consultare i segnali statici e riconciliare componenti mancanti,
esclusioni e incertezze. `list_surface_signals`, lettura/ricerca sorgente, directory,
recupero degli output, `run_code` con i soli binding statici e i tool CBM restano disponibili durante tutta la fase
investigativa: la sequenza e' una direttiva semantica, non tre nuovi agenti o un gate
deterministico sui tool. Indice e sensori sono acquisiti una volta per la run; un
sensore assente o incompleto non esclude componenti dalla ricerca.

L'output model-facing e' `ReconPlan`: `briefing` narrativo, `assignments` ordinati e
`coverage_notes` narrative. Un incarico contiene solo `title`, `paths` e `next_check`;
quest'ultimo descrive comportamento, proprieta' da verificare e prima lettura utile.
Sono ammessi incarichi senza path, con una ricerca di localizzazione nel testo, e
senza alcun sospetto preliminare. La priorita' dipende da esposizione, autorita', dati
e dipendenze osservate; i path orientano la ricerca e non delimitano l'accesso al codice.
Non vengono aggiunti al modello ID, status, score, matrici CWE o DTO di coordinamento:
l'orchestratore assegna gli ID e normalizza il piano nel `CategoryRecon`/area ledger
esistente. Briefing e note restano testo, senza duplicarli in nuovi campi semantici.

Ogni epoch Reader riceve briefing e note comuni, l'incarico attivo completo e un indice
con ID e titoli degli altri incarichi. Il Reviewer conserva la vista di coordinamento
completa. Reader sceglie le letture successive fra percorsi inesplorati, controlli da
confrontare e dipendenze condivise; puo' seguire codice fuori dai locator e proporre
`AreaEnrichmentLead` per comportamenti non rappresentati anche senza un sink sospetto.
Emette zero, una o piu' `ReaderLead`, con osservazioni e domande discriminanti negli
unknowns: il nuovo protocollo autonomo `InvestigationPacket` non e' implementato nel P0.
Una lead acquisita resta non validata e non finanziata dal downstream; Reader prosegue
nella stessa epoch. Chiusura, pivot e checkpoint sono coordinati dal Reviewer; al cambio
di incarico viene aperta una nuova epoch. L'ordine iniziale segue gli incarichi Recon,
con i pivot semantici gia' previsti dal lifecycle.

Recon, Reader e Reviewer consumano il budget discovery condiviso esistente, entro il
cap della run e i limiti di ruolo. Non esistono riserve per incarico o garanzia di
completare tutti gli incarichi: un cutoff conserva lavoro residuo e risultati parziali.
Se Recon non produce un piano valido, la run termina con `recon_unavailable`, senza
avviare silenziosamente un Reader privo del piano sperimentale.

Il benchmark materializza solo il sorgente sanitizzato; manifest, oracle e diagnostici
restano in Laravel. In modalita' workspace-only il runner passa al command executor il path
host della vista sanitizzata, distinto dal `/workspace` interno all'agente. Con
`--reuse-sandbox` Laravel ricollega una sandbox Lailaps pronta, conserva separati il nuovo
run ID del report e l'audit ID del target e passa al Python soltanto container ID e identita'
autorevole della sandbox. Target benchmark e snapshot sorgente sono scritti nelle label alla
creazione e devono coincidere su tutti i container al riuso. L'outcome registra
`workspace_only` oppure `reused_sandbox`; non
esiste provisioning implicito nel comando congiunto. Il report conserva piano grezzo,
Recon normalizzata, lead accettate,
osservazioni, checkpoint, telemetria e metadati `functional_assignments_v1`, anche negli
snapshot parziali. L'outcome unico contiene costo totale e diagnostici per manifest
dello stesso snapshot. Il vecchio evaluator e' solo un ausilio al riesame: le letture
Recon non valgono come copertura Reader e i costi ripetuti nei diagnostici non vanno
sommati. Una run eseguita senza errori resta `pending_semantic_review`, senza attribuire
conferme statiche alle lead; errore di processo, report mancante o Recon indisponibile
sono fallimenti tecnici. Timeout arresta il processo con il runner esistente e conserva
l'ultimo output durevole. Le repetitions, quando richieste, sono seriali e indipendenti.

Recon e Reader ricevono `run_workspace_command` quando esiste l'executor e ricevono
`run_target_command` e `run_target_probe` soltanto quando il target e' collegato. Il contesto
discovery espone una vista redatta con capability, working directory e servizi logici, senza
credenziali o dossier database. I comandi possono essere usati prima di un'area o di una lead
per inventari mirati e non sono obbligatori. Output, exit code, timeout, scope, comando o argv,
servizio e nomi dei file temporanei sono conservati nel broker bounded con ruolo e area quando
presente. stdout non diventa source reference e un'osservazione CLI non equivale a reachability
HTTP o conferma. Timeout diagnostici del target sono recuperabili e non arrestano
l'applicazione; i probe conservano il cleanup temporaneo esistente.

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
client risolto, capability read-only e un sommario redatto di tabelle/colonne; i nomi
delle tabelle restano disponibili e le colonne oltre il budget del dossier sono marcate
come omesse. Lo stesso snapshot sopravvive a nuove lead,
compression, epoch e sessioni actor perché appartiene alle dipendenze condivise della run.

`query_database` accetta un connection id appartenente all'enum del dossier, una sola
`SELECT` o `WITH ... SELECT`, `max_rows` e timeout. Per MySQL/MariaDB accetta inoltre
solo forme ancorate e read-only di `SHOW`, `DESCRIBE` ed `EXPLAIN` (mai `ANALYZE`),
eseguite senza wrapper ma con lo stesso timeout e limite righe; PostgreSQL e SQLite
rimandano a `information_schema`. Non espone al modello servizio,
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

## Policy operativa corrente (ledger schema 9, aggregato schema 10)

Questa sezione e' normativa e sostituisce le descrizioni legacy precedenti su quote,
terminalizzazione automatica, hard cap per-lead e compression deterministica.

Reader, Confirmer e Worker sono budget-unaware: non ricevono score, costi, richieste residue
o percentuali di pressione. L'accounting appartiene all'orchestratore. Confirmer e Worker
decidono semanticamente ai self-checkpoint se un ulteriore esperimento e' utile. Il Worker
termina quando le prove bastano, un impedimento e' concreto o la probabilita' di progresso
non giustifica altre azioni.

Il cap globale e' la safety net economica comune. Confirmer usa finestre iniziali da 12
richieste e continuazioni fino a 32; Worker usa finestre da 32 richieste entro un solo cap
per handoff di 1.000.000 EP. Gli stage sono ammessi interamente o negati. I checkpoint
Worker non rinnovano l'envelope e il modello non deve ottimizzare costi. La riserva finale
protegge checkpoint e serializzazione con retry finiti. Consumi e overshoot vengono
registrati; l'overdraft rimane disabilitato.

L'ammissione scoped segue la creazione della lead (`confirmer_stage`) e la promozione del
CandidateHandoff (`worker_stage`). Il Worker standalone puo' ammettere direttamente un
handoff importato valido. `category_remaining_points` e admission sono stage-sensitive:
Confirmer non usa residui Worker e Worker non usa residui Confirmer. Un limite economico
conserva la lead suspected, con `worker_stopped` o `unfunded_dynamic` nel percorso dinamico;
non produce un verdetto tecnico.

I boundary Reader passano all'Exploration Reviewer; ogni boundary Confirmer passa al
self-checkpoint dello stesso modello e non al Reviewer. Il Worker passa al proprio
checkpoint dopo ogni uscita o boundary: il verdict terminale viene serializzato senza tool,
mentre `continue` riprende la stessa history append-only entro l'envelope iniziale.
Il Reader conserva invece la stessa history nella stessa area, aggiungendo
una direttiva Reviewer o un esito downstream una sola volta. Apre una nuova epoch al
pivot reale, alla chiusura area o alla pressione di contesto. I retry Reader conservano la history
soltanto al primo tentativo e usano Reviewer/fallback deterministico prima del secondo.
se l'orchestratore termina l'agente o cambia scope, la conversazione successiva è invece
nuova per definizione. Per il Confirmer `candidate_handoff` o `lead_closure` blocca sulla
stessa conversazione il tipo del verdetto terminale tool-free; `stop_lead` non è esposto al
modello. Se dopo `continue` il cap non finanzia un'altra tranche, il ledger conserva il
finding come suspected con lifecycle `reviewer_stopped` per compatibilità dello stato
persistito, senza attribuire al budget un verdetto tecnico. L'esaurimento del solo output
terminale conserva raw output e lead
suspected come `terminal_output_exhausted`, mentre gli errori infrastrutturali distinti
continuano a poter produrre `blocked`.

Il profilo operativo predefinito e' 256k. Dopo prompt/schema, riserva output e safety
margin, il Confirmer comprime all'85% della capacità utile e punta al 35%; il Worker usa
75% e 25%. La soglia hard resta al 90%. Sotto la soglia del ruolo la history resta
invariata per favorire cache hit. Confirmer e Worker usano un summarizer LLM con prompt
dedicato, checkpoint strutturato deterministico e coda raw recente. I checkpoint preservano finding, source
reference, route/auth/middleware, evidenze, actor/session, request/response ID,
inspection/query e next experiment; i raw body restano soltanto in memoria durante la run.

L'isolamento del source root e del target URL, i health check, la separazione delle sessioni actor, i controlli sulle transazioni, la paginazione e i limiti di context restano invariati. Questi vincoli proteggono sicurezza e qualità, ma non sostituiscono il budget economico a score.

Il manifest del confronto Reader conserva gli eventi di consumo USD/EP e i tempi di
acquisizione delle lead per assignment; il parent conserva offset di partenza,
configurazione Reader effettiva e fingerprint dei sorgenti runtime/lockfile.
Le curve a pari USD richiedono costi provider completi e deduplica offline;
non vengono estrapolate oltre la spesa raggiunta. Gli esiti tool usano status
di esecuzione/validazione: errori in codice letto e risultati vuoti non sono fallimenti.

## Deduper e valutazione semantica degli artifact Reader (P0, contratto v2)

`pentest_agent.deduper` confronta lead fra loro e task/enrichment fra loro, senza
discovery o conferma. Reader resta `xiaomi/mimo-v2.6-pro`; recupero deduper/evaluator
usa `z-ai/glm-5.3-flash`, InferenceNet, senza fallback. Il modello restituisce pass,
block o partial; solo partial produce un nuovo payload piatto dello schema Reader
esistente, merge oppure residual. ID, provenance, lineage e proiezione sono
dell'orchestratore. Originali immutabili; nessuna fusione transitiva automatica o
riscrittura di assignment attivi/conclusi. Integrazioni verso lavoro già assegnato
restano pending, senza redispatch.

Il pacchetto contiene SEMPRE il payload completo della proposta, schede compatte di
ogni canonica precedente del gruppo e indice degli artifact recuperabili. Nessun
filtro per anchor, adattamento delle schede alla dimensione del registro o
paginazione. Le schede deterministiche riportano ID integrale, tipo e stato;
titolo 240 caratteri, domanda/ipotesi 320, operazione lead 160, primi due unknowns
96 ciascuno, primary file lead/primi due path task. Si normalizzano soltanto gli
spazi; oltre il limite si conservano testa 70% e coda 30%, separate da ellissi.
Nel contratto 2.2 (schede 1.1) i limiti e le omissioni sono dichiarati una sola
volta nel prompt, senza metadati `omitted` ripetuti per scheda. Path oltre 512
caratteri sono omessi, mai troncati. Gli ID delle schede sono direttamente
recuperabili: `artifact_index` contiene solo gli ID aggiuntivi. La serializzazione
JSON inviata al modello e quella stimata usano separatori compatti.
Le schede sono proiezioni, non nuovi prodotti Reader. Le evidenze sono raggruppate
sotto gli ID degli originali: un recupero fornisce integralmente originali, source
reference e lineage; transcript esterni restano artifact con ID registrato.

`read_artifacts` permette un unico recupero raggruppato, nessuna lettura libera.
Block e ogni partial richiedono tutti gli originali citati, inclusa la lineage
dei derivati. Pass senza overlap plausibile può usare il registro compatto:
`comparison_basis=compact_index` è una decisione provvisoria, non prova esaustiva
di unicità. Overlap irrisolvibile resta `inconclusive`. La prima proposta di un
gruppo vuoto passa senza inferenza, dopo il preflight.
Il contratto 2.1 del deduper rende `pass` un output con sola decisione e
`uncertain` facoltativo; `block` contiene decisione e ID correlati qualificati.
Nessuno dei due espone `reason`. Solo `partial` restituisce il prodotto Reader
strutturato completo, insieme alla relazione e agli ID correlati.

Il runner condiviso è l'unico proprietario dei tentativi. Default persistiti:
85.333 token input stimati completi per nuove configurazioni deduper senza cap
esplicito (prompt, schema, proposta, indice, recupero); evaluator resta a 64.000.
I cap espliciti congelati restano invariati; massimo configurabile 85.333.
La versione 2.2 richiede una nuova esecuzione rispetto ai ledger precedenti.
16.384 max output per risposta deduper (8.192 per evaluator), incluso il
reasoning, effort `low` tramite il
mapping runtime `extra_body.reasoning.effort`; due richieste logiche e un solo
tentativo aggiuntivo condiviso fra retry tecnico e riparazione del contratto,
massimo tre tentativi HTTP per decisione. Connessione 15s, lettura HTTP 90s,
deadline tentativo 120s e decisione 300s incluse attese. Nessun retry SDK,
trasporto o Pydantic. Il preflight si ripete sulla seconda richiesta e sulle
correzioni, con lo stimatore runtime, margine 50% e overhead 1.500 token.
Superamento => `context_limit`, nessuna chiamata né ulteriore troncamento.
Dal contratto 2.3 il deduper limita lo stop alla proposta corrente: overflow
iniziale diventa failed_technical, overflow nel recupero diventa inconclusive;
le proposte successive vengono comunque elaborate.

429/408/5xx e trasporto transitorio possono usare l'unico retry. 429 rispetta
Retry-After (secondi o data); attese oltre 30s o deadline residua sospendono la
fase, header assente => 5s + jitter massimo 1s. Auth/config/richieste incompatibili
non vengono ritentate. Output invalido può ricevere errore bounded e richiesta
di risposta concisa; nessuna correzione deterministica del significato.
Troncamento/finish_reason error non recuperati restano errori tecnici. Nel
deduper 2.3 anche fatal della richiesta, artifact non leggibile e fallimenti
consecutivi restano locali: nessun circuito arresta le altre lead. Il runner
evaluator mantiene il circuito dopo tre fallimenti consecutivi e la ripresa
esplicita. Budget esaurito, cancellazione/interruzione e configurazione o
integrita del corpus congelato non valide restano condizioni globali.

Prima dell'invio si salva un record di tentativo e si riserva input/output a
cache zero nell'envelope totale del ruolo. Usage completa sostituisce la riserva;
usage parziale conta una volta e conserva il residuo prudenziale sconosciuto.
Timeout/cancellazione senza usage conservano l'intera riserva. EP osservati e
`unknown_reserved_points`, USD stimati osservati e riserve sconosciute restano
distinti e impegnano insieme il budget; reasoning già incluso nell'output non
si somma nuovamente. ID decisione/tentativo, durata, classe, HTTP status,
request ID, provider richiesto/osservato, finish reason e usage disponibili
sono finalizzati anche su cancellazione, senza prompt dump o credenziali.
Budget dei ruoli restano quelli configurati del round; nuovi cap non rifinanziano.

Il verdetto è separato da `completed`, `inconclusive`, `failed_technical`, `pending`.
Un errore non crea un pass: `decision=null`, proposta conservata nella proiezione
provvisoria. PHP e Python verificano adjudication prima del dispatch, anche
quando ricostruiscono la coda dalle canoniche. Enrichment non completati restano
in attesa; gli assignment approvati continuano. Il vecchio novelty Reviewer non
viene invocato sulle stesse proposte. Reader-only conserva la produzione grezza
prima del postprocessing; nessun handoff dichiarato deduplicato con fase incompleta.

`reader-normalize` e `reader-evaluate`/wrapper PHP supportano `--resume-failed`:
si ricostruisce in ordine la proiezione, si riusano gratuitamente successi con
fingerprint compatibile, si riprovano fallimenti tecnici e pending. Incertezza
semantica richiede revisione, non retry automatico. Contesto cambiato da un merge
riparato invalida i risultati successivi dipendenti; eventi e tentativi precedenti,
costi, impegni e limite originale restano. Prodotti già dispatched non ripartono.
Prompt, schede, schema e ledger sono versionati. Verdetti v1 non sono cache v2:
recupero in nuova directory da originali e ordine congelati, con costi storici
separati ed envelope di recupero esplicito. `--roles-config` passa la configurazione
completa ed è incompatibile con override individuali dei medesimi ruoli.

`benchmark:reader-evaluate`/`reader-evaluate` lavorano soltanto su outcome/fixture
mappati o `--collection` congelata. Metrics/report parziali e `phase-status.json`
sono sempre prodotti per input validi; exit 0 fase completata, 2 artifact validi
ma fase incompleta/sospesa, 1 input/integrità fatali. `--preflight-only` raccoglie/
verifica artifact, simula crescita all-pass dell'intero registro e controlla i blob
sorgente con ZERO inferenza. È una misura di packaging/cap, non qualità semantica.
Il launcher legge lo stato, non interpreta la scrittura del report come successo
e non avvia evaluator/replay storico dopo deduplica incompleta. Online exit 2
mantiene pending e lavoro approvato, distinto dal crash.

Evaluator parte soltanto quando TUTTE le decisioni necessarie della deduplica
sono `completed`. Valuta ogni ipotesi canonica contro tutti i casi del manifest;
match molti-a-molti, qualità, incertezza e diagnosi miss restano distinti. Timeout
persistiti sono tentativi falliti recuperabili, mai giudizi semantici definitivi.
Gli ID originali Reader citabili sono espliciti; discovery credit richiede
l'ipotesi Reader tracciata, mai una lettura svolta dall’evaluator offline.

Il sorgente è il blob Git del commit verificato in root esplicita. Checkout
CRLF, modifiche o HEAD successivi non alterano quei byte; si verificano root,
commit, path relativo e tipo blob regolare (non symlink/submodule/directory).
Path autorizzati sono precalcolati da manifest, tutti i prodotti ed evidenze,
indipendentemente dall'ordine. URL/directory dichiarate non espandono scope.
Finestra massima 200 righe inclusive, esposta nello schema; richieste invalide
possono usare l'unica riparazione, senza ampliamenti automatici. Blob ID, SHA-256
e commit sono persistiti con provenance evaluator. Cache include config,
versione, prompt/schema, pacchetto, scope, hash artifact e identità Git.

Report separano lead grezze e lead/100k EP Reader, canoniche provvisorie,
decisioni per stato/causa, risultati semantici e completezza, EP osservati,
impegni sconosciuti, USD stimati, richieste/recuperi/retry/riparazioni e latenza.
Una riga per decisione espone prodotto, esito, tentativi, durata e causa tecnica,
senza ragionamento interno. Modelli non producono ground truth: revisione di tutti
block/partial e campione di pass resta necessaria. Nessuna nuova discovery o
Confirmer per recuperare il benchmark. L'immagine di recupero ha tag separato;
le run attive e il tag dev non vengono sostituiti.

### Benchmark isolato del deduper da DB (P0, 29 settembre 2026)

`benchmark:deduper <progetto> <reader-run-id>` congela tutte le `ReaderLead`
strutturate valide della run selezionata, senza filtrare `accepted`, score o label.
Per `ReaderGlobalRun` risolve soltanto i child dichiarati nei suoi assignment;
le lead appartengono ai rispettivi `ReaderRun`, non al run_id del parent globale.
Progetto e commit devono coincidere. Sono esclusi AreaEnrichmentLead, task e
accordo/promozione delle aree; sono incluse vere ReaderLead prodotte da assignment
originariamente di enrichment. Nessun Reader, discovery, evaluator, Confirmer o
dispatch viene avviato dal comando.

Gli originali Reader rimangono immutati. La tabella esistente
`benchmark_stage_artifacts` contiene `deduper/DeduperRun`,
`deduper/DeduperDecision` e copie/prodotti `deduper/ReaderLead`. Il parent
dell'esecuzione è la run Reader; decisioni e prodotti sono figli del DeduperRun.
Il manifest DB conserva ID qualificati, hash, lineage, ordine di persistenza
`created_at,id`, configurazione e riferimento al ledger. Gli originali completi
sono già nel DB: il runner usa una copia `frozen-input.json` verificata con hash,
senza reinserire l'intero corpus nel parent e superare `max_allowed_packet` MySQL.
Label, oracle e score non entrano nel pacchetto del modello. Un ordinamento DB
stabile non pretende di ricostruire tempi di acquisizione storici non disponibili.

Ogni originale riceve subito una decisione pending. Il runtime Python v2 è
l'unico proprietario di inferenza, tentativi, circuito e accounting; PHP importa
checkpoint con transazioni brevi e identità idempotenti. Conserva decisioni
precedenti e indica quelle correnti. Pass copia la lead; block conserva originale
e verdetto senza una nuova promozione; partial crea il payload del modello con
lineage orchestrata. Merge sostituisce le canoniche citate, residual aggiunge la
parte nuova. Le canoniche superate restano tracciabili, escluse dal downstream.
Inconclusive, failed_technical e pending rimangono distinti da pass. Nessuna
promozione è selezionabile fino alla completezza dell'intero corpus congelato.

La nuova esecuzione richiede funding esplicito (`--roles-config` oppure
`--deduper-points`). Gli override individuali sono incompatibili con
`--roles-config`; il modello è GLM 5.3 Flash, con routing auto per le nuove
esecuzioni DB e pin InferenceNet esplicito ancora disponibile.
`--dedup-run` riusa input, directory ed envelope, senza ricaricarli;
`--resume-failed` riprova soltanto tecnici/pending con la ripresa P0 e conserva
tentativi, costi osservati e impegni sconosciuti. Successi compatibili non costano
altre chiamate. Un lock impedisce invocazioni concorrenti della stessa esecuzione.
Un import interrotto si riconcilia dal ledger già persistito. Su Windows la sola
rinomina atomica del checkpoint tollera brevemente un handle di lettura occupato,
con limite di 250 ms; questo non aggiunge tentativi HTTP.

Prima dell'inferenza viene sempre eseguito il preflight all-pass dell'intero
corpus. `--preflight-only` termina qui, con rete container disabilitata e zero
inferenza. Il superamento del cap 64k lascia tutte le decisioni pending e produce
exit 2; non introduce paginazione o un sottoinsieme implicito. Report DB/file e
`phase-status.json` distinguono lead grezze, canoniche provvisorie, stati/verdetti,
completezza, EP osservati, riserve sconosciute, USD e richieste. Exit 0 del solo
preflight significa dimensionamento completato, non deduplica semantica eseguita.
Run Reader incomplete possono fornire originali validi: la completezza successiva
si riferisce soltanto al corpus congelato e conserva tale provenienza.

`benchmark:confirmer --dedup-run=<id>` controlla esecuzione, progetto, commit,
contratto, integrità degli originali e tutte le decisioni correnti, poi seleziona
dal DB soltanto canoniche finali valide e promosse della specifica esecuzione.
Non richiede il file ledger per selezionarle. È incompatibile con selettori
artifact/dataset/file alternativi. Anche `--dedup-artifact` impone il gate v2.
La categoria viene risolta dal catalogo e dalla lead; se ambigua richiede il flag
categoria esistente, senza scegliere il primo manifest. Le nuove lead non hanno
oracle costruiti dai verdetti: le metriche di correttezza restano non valutabili
senza ground truth revisionata. Confirmer viene lanciato separatamente dall'utente.

Comandi e preflight reali sono in
`plans/P0-benchmark-deduper-db-delivery-20260929.md`. L'immagine separata è
`lailaps-pentest-agent:deduper-db-p0-20260929-v2`; nessuna run attiva è sostituita.

Per nuove esecuzioni del benchmark DB, il provider di `z-ai/glm-5.3-flash` è
`auto`: il runner omette la preferenza `provider` e lascia a OpenRouter il routing
e i fallback tra endpoint dello stesso modello. `--deduper-provider=InferenceNet`
mantiene il pin precedente. Configurazione e provider sono nel fingerprint; il
resume di una vecchia esecuzione usa immagine/provider/budget persistiti e non
trasforma i verdetti esistenti in cache di un esperimento nuovo. Reader non cambia.
Gli EP restano l'unità di budget del modello; per l'auto-route la riserva USD
e la stima della run usano la tariffa InferenceNet del catalogo come riferimento
approssimativo, senza listino per ogni provider. `usage.cost`, quando presente,
alimenta il costo osservato; se manca, la stima resta esplicitamente non completa.
Per le richieste senza usage la riserva resta un impegno sconosciuto. Il routing
automatico può scegliere una tariffa diversa: la stima non è un cap in USD.
Le nuove esecuzioni DB con `--deduper-points` congelano un cap deduper di
16.384 token; un `--roles-config` esplicito conserva il proprio valore. Il
contratto deduper 2.1 rimuove `reason` da pass/block e lascia `partial` come
ReaderLead completo. L'immagine
`lailaps-pentest-agent:deduper-output-p0-20260930-v1` e' separata dalle
esecuzioni precedenti: il resume riusa immagine e contratto persistiti.

### Esperimento deduper compatto P0 (30 settembre 2026)

Il benchmark DB usa di default `lailaps-pentest-agent:deduper-isolation-p0-20260930-v1`,
contratto 2.3.0 / schede 1.1.0, input 85.333 e output 16.384. Il percorso online
e quello offline condividono schede, recupero e default deduper; la configurazione
evaluator non cambia. Restano due richieste logiche e un tentativo extra, senza
paginazione. Il corpus Cacti da 144 lead viene misurato in una nuova esecuzione
con envelope esplicito di 1.000.000 EP: token effettivi medi/p95/picco, esiti
pass/block/partial, canoniche finali e incompletezza sono distinti dalla stima
prudenziale del preflight. Una singola run non stabilisce la capacita media
generale ne la correttezza semantica dei verdetti.

La prima misura Cacti usa il tag immutato `deduper-compact-p0-20260930-v1`: arresto
alla lead 81 durante recupero originali, 70 decisioni completed, 9 failed_technical,
2 inconclusive, 63 pending; 136 richieste e 494.551,8 EP. La v2 corregge soltanto
il messaggio di riparazione di un artifact ID invalido, ricordando che sono
recuperabili anche gli ID delle schede e i source_ref_id della proposta. Nessun
replay provider della v2 e nessun cambio di schema, cap o budget.

### Isolamento degli errori deduper (contratto 2.3, 30 settembre 2026)

`Deduper` abilita l'isolamento nel runner condiviso, senza cambiare la policy
dell'evaluator. Il preflight all-pass resta diagnostico: `--preflight-only`
restituisce ancora incomplete per overflow, ma una normale esecuzione 2.3 non
blocca l'intero corpus su quella previsione. CLI di normalizzazione, pipeline
di valutazione e wrapper DB proseguono per lead. La run puo terminare l'iterazione
con `lead_errors` e complete=false: errori/inconclusive non diventano pass,
e il gate Confirmer non promuove un corpus incompleto. Budget e costi restano
immutati, comprese riserve e resume. Una nuova esecuzione e necessaria per il
contratto 2.3; non riutilizzare cache 2.2.

Ogni decisione conserva `context_diagnostics`: ultimo input stimato (anche
se rifiutato prima dell'invio), cap e caratteri dei singoli artifact recuperati.
Questo evita di perdere il dettaglio del pacchetto che ha causato l'overflow.
La nuova immagine e verificata offline; nessuna run provider parte automaticamente.

L'ispezione dei 144 originali Cacti misura payload medi di 4.633 caratteri e
source_refs medi di 10.684 caratteri. Il prodotto derivato piu grande della run
`deduper-01m3rh219pj15ewkj8tv3q08wa` contiene 62.139 caratteri, oltre ai tre
originali recuperati automaticamente (87.750 caratteri). Il pacchetto di lettura
ha 107.078 caratteri di snippet, contro 47.446 di snippet distinti. Queste misure
spiegano l'amplificazione causata da evidenze aggregate e lineage, senza
ricostruire gli ID richiesti nello specifico overflow storico (non registrati).
La ristrutturazione del recupero e la deduplicazione del testo delle evidenze
non sono incluse nella modifica di isolamento.

### Recupero semantico mirato del deduper (contratto 3.0, 1 ottobre 2026)

Il deduper online e il benchmark DB condividono ora il contratto 3.0.2 / schede
2.0.0. Il pacchetto iniziale contiene il payload semantico della proposta e
schede compatte dei prodotti canonici, con alias brevi stabili nella decisione.
Gli ID qualificati e le source reference restano autorevoli nel ledger, ma non
vengono ripetuti nelle schede o nei risultati dei tool. `read_lead_details`
accetta fino a quattro alias canonici e restituisce i loro payload semantici e
metadati di evidenza, senza espandere automaticamente lineage o snippet.
`read_evidence` accetta soltanto alias di evidenze registrate gia visibili e un
offset; fornisce una finestra di testo solo quando richiesta. Gli snippet con
stesso contenuto e posizione condividono un alias nella decisione. L'orchestratore
normalizza gli alias degli output in ID qualificati prima di persistere block e
partial e rifiuta riferimenti inventati.

I default deduper sono 85.333 token input, 16.384 output, quattro richieste
logiche, un tentativo extra e cinque tentativi HTTP massimi. I dettagli
semantici hanno un limite cumulativo stimato di 8.192 token; le evidenze hanno
8.192 token cumulativi e 4.096 per risposta. Prima di aggiungere dati a un
prompt si usa lo stesso stimatore prudenziale del preflight; al limite il tool
restituisce un avviso breve e resta un turno finale. I cap dell'evaluator non
cambiano. Dubbi semantici e limiti di lettura ammettono un `pass` prudenziale
(`uncertain: true`) completato e dispatchable, conteggiato separatamente.
Timeout, output invalido e fatal restano errori tecnici locali alla lead; un
budget globale esaurito ferma l'esecuzione senza inventare verdetti. I block e
partial richiedono i dettagli semantici di ogni canonica citata, non gli snippet.
Il benchmark DB usa una nuova immagine immutabile
`lailaps-pentest-agent:deduper-details-p0-20261001-v3` e una nuova esecuzione:
le decisioni dei contratti precedenti non sono riusate sotto il nuovo prompt.
Il contratto 3.0.1 accetta nei due output di lettura l'omissione di `decision`
quando il nome del tool identifica gia l'azione; l'orchestratore la ripristina.
Questo evita un errore di schema osservato nella prima prova 3.0.0 Cacti.
Nel 3.0.2 un block o partial che cita una canonica non letta diventa un
`pass uncertain` valido: il deduper non puo scartare lavoro sulla base di una
scheda incompleta, nemmeno quando il limite di recupero e stato raggiunto.
# Deduplica Reader deterministica (P0, 1 ottobre 2026)

Le `ReaderLead` espongono campi piatti opzionali `weakness_kind`, `primary_end_line`,
`primary_symbol` e `input_key`, oltre a `primary_file`/`primary_line` già esistenti.
L'orchestratore normalizza la posizione contro le source references autorevoli;
valori assenti o invalidi lasciano passare la lead. Il DTO interno e la sua origine
sono costruiti in Python, senza esporre wrapper annidati al modello Reader.

`pentest_agent.deterministic_deduper` è il motore predefinito per le lead:
stesso progetto e snapshot, categoria specifica canonica, file e riga principale
uguale, oppure range brevi (massimo 40 righe) fortemente sovrapposti (IoU >= 0,80,
estremi distanti al massimo 5 righe). La riga finale è facoltativa per il confronto
puntuale. `input_key` e `primary_symbol` restano metadati, non condizioni di match.
La tassonomia Reader è condivisa con il matcher; varianti note sono normalizzate
tramite alias espliciti, valori ignoti passano. L'identità derivata conserva il
valore dichiarato senza modificare le lead originali congelate. Si
confronta ogni proposta con il rappresentante del gruppo. Gli originali restano
persistiti; un block deterministico collega il duplicato al rappresentante e
non avvia un nuovo Confirmer. Recon, task ed enrichment non usano questa regola.
Le modalità `deterministic`, `llm` e `off` sono esplicite; `llm` conserva il
deduper semantico esistente con budget autonomo. La novelty review inferenziale
delle lead è bypassata in modalità deterministic/off. Nel Reader globale i child
generano lead raw; il coordinatore applica un solo indice deterministico al
corpus aggregato prima dell'handoff. Il normalizzatore deterministico opera
offline, senza provider, token o EP di deduplica.
Il runtime predefinito punta all'immagine separata
`lailaps-pentest-agent:deduper-deterministic-p0-20261002-v2`; l'override
`PENTEST_AGENT_IMAGE` resta disponibile. Il vecchio tag `dev` non viene
sovrascritto da questa consegna.

`benchmark:deduper-deterministic` congela ReaderLead da DB e produce decisioni,
gruppi e canoniche con la stessa implementazione Python. Può confrontarsi in
sola lettura con una run `benchmark:deduper` LLM sul medesimo corpus e generare
un overlay di identità per le lead storiche. I partial residual del LLM non
diventano automaticamente relazioni di duplicato pieno. I prodotti promossi
restano scoped alla execution `DeduperRun`; Confirmer seleziona le canoniche
attraverso il gate di completezza. Gli output LLM sono baseline comparativa,
non ground truth.

## Worker benchmark con parent run (2026-10-03)

`benchmark:worker --parent-run-id=<artifact-id oppure run_id>` seleziona i
CandidateHandoff validi discendenti della run Reader/Deduper ed esegue un unico
batch seriale per ripetizione, anche quando le lead appartengono a categorie
diverse. Non esiste una modalita' isolata per il parent: una ripetizione possiede
una sola sandbox, un processo agente, il ledger e le dipendenze condivise.
Sessioni HTTP per actor, header persistenti, evidenze e taccuini della run restano
disponibili alla lead successiva. Le conversazioni Worker restano per-lead;
le note role del Worker attraversano le categorie, quelle category rimangono
scoped alla categoria. Le modifiche al target sono condivise nel batch.

L'envelope Worker viene rinnovato per candidato. L'outcome mantiene
`worker_batch.episodes` con esiti, evidenze e consumi attribuiti alla singola
lead, insieme al report e alla telemetria aggregati. I checkpoint parziali
conservano gli episodi conclusi e la lista ordinata degli artifact selezionati;
un errore tecnico ferma il batch lasciando visibili i candidati non eseguiti.
Laravel registra un artifact Worker per ogni episodio prodotto, con lo stesso
run_id del batch e il rispettivo CandidateHandoff come parent. Le firme di
confronto includono ordine e hash dei candidati e il reset per batch, evitando
di mescolare i risultati con il benchmark a sandbox isolata. Timeout e TTL
coprono l'intero batch. Le ripetizioni iniziano con sandbox e memoria nuove.

L'inventario privato delle sessioni include anche gli handle creati a runtime;
i nomi di cookie/header indicano materiale riutilizzabile, senza certificare
che la sessione sia ancora autenticata. Note e log sono persistiti nell'outcome
della run; cookie jar e conversazioni non vengono importati da run distinte.
