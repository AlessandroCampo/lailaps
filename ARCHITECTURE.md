# Architettura del loop agentico di Lailaps

## Scopo e flusso generale

## Persistenza auditabile della run

CLI e UI condividono un unico contratto filesystem sotto
`storage/app/runs/{target}/{category}/{run_id}`. Il `run_id` è parlante e incorpora target,
categoria e timestamp filesystem-safe, per esempio `yeswiki-injection-20260822-173900`;
in caso di collisione riceve un suffisso numerico. Viene stampato all'inizio e alla fine.

La directory viene riservata prima della preparazione del target e contiene i file
autorevoli con lo stesso prefisso del `run_id`:

- `{run_id}-logs.php`: header PHP con `run_id` e transcript byte-per-byte della console,
  inclusi gli eventi `LAILAPS_EVENT` usati dal feed live;
- `{run_id}-outcome.json`: oggetto con sole chiavi top-level `run_id`, `started_at`, `report` e
  `benchmark`. `started_at` è l'istante di creazione della run in formato ISO 8601 con
  timezone `Europe/Rome`; report parziale/finale, telemetria ed environment state confluiscono nel
  campo `report`; l'evaluator aggiorna `benchmark` nello stesso file;
- `{run_id}-investigator-{scope_hash}.jsonl`: journal append-only redatto delle tool call
  della singola lead, isolato per run/pass/categoria/lead, con input e risultati acquisiti.

L'outcome viene sostituito atomicamente durante i checkpoint. Non esistono directory child
per gli output Investigator: il finding contiene il puntatore `tool_trace` al journal, con
scope e contatori. Il journal resta fuori dal contesto LLM e dalla LRU: registra attempt,
started, risultato, errore o cancellazione, non solo gli ID HTTP. I risultati sono quelli
acquisiti dai tool prima della preview per il modello, con redazione e limiti di acquisizione
espliciti; non sono una promessa di body illimitati o segreti riproducibili. Le subcall PTC
conservano `parent_call_id`; i flow browser conservano step e assertion originali. Append
con flush/fsync e fallimenti di persistenza propagati impediscono ulteriori effetti senza
traccia. Un crash puo' lasciare una chiamata started con esito ignoto; la coda incompleta
viene ignorata in lettura e riparata alla successiva append, senza replay degli effetti.
Il database conserva lifecycle e proiezioni benchmark senza duplicare il journal.

`ToolOutputManager` conserva ogni risultato prima della preview model-facing in una LRU da
32 MiB per categoria. Ogni entry porta tool, ruolo, categoria, lead e area assegnati dal
runtime; un ID non concede accesso fuori scope. `inspect_tool_output` permette ai ruoli
investigativi di listare, leggere a finestre e cercare letteralmente gli output autorizzati,
distinguendo disponibilita', espulsione e acquisizione incompleta. Per gli output Investigator
archiviati, il recupero scoped legge anche il journal dopo espulsione o riavvio. Il Worker recupera dallo
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
Reader -> investigatore unificato per lead (fasi contabili Confirmer e Worker). Le aree Recon sono domande concrete su
entrypoint, operazioni sensibili, confini di fiducia e controlli osservati; non sono nomi di
categoria. Non esistono riconciliazioni o handoff intermedi per categoria.

La Recon decide quando la mappa osservata e' utile ai Reader, accorpando domande che
richiedono lo stesso contesto. Prompt e schemi model-facing non espongono quote di aree
o richieste. L'orchestratore conserva i cap: se la risposta supera la capacita' ammessa,
mantiene le prime aree nell'ordine di priorita' del modello (48 globali per default,
12 filtrate), senza retry per ridurre la lista, e registra il taglio nell'evento
`recon_output_truncated`. La stessa normalizzazione vale per il recovery testuale e
gli incarichi ReconPlan. I limiti economici restano nell'accounting e nella telemetria
per l'operatore; al modello viene comunicata soltanto la disponibilita' operativa della
fase, senza importi EP o residui da consumare.

Il coordinatore `GlobalPipeline` possiede un deduper deterministico, il broker e due
code operative con pool indipendenti: 4 Reader e 2 investigatori di default. Il pool Reader
riempie immediatamente gli slot liberi con un'altra area. Ogni Reader usa Deps, HTTP,
cookie, history, telemetria e toolbox propri. Un task possiede la lead dalla verifica statica
alla conclusione dinamica con lo stesso modello, prompt stabile, history e sessione provider;
la compaction cambia epoch locale ma conserva la sessione e i riferimenti durevoli.
ID di lead, output e source reference sono
qualificati per scope e pass. Il coordinatore e' l'unico publisher del report aggregato.
Un figlio chiude soltanto le proprie sessioni browser e risorse operative.

`benchmark:run --global` puo' saltare la generazione Recon con `--recon-run=<run_id>`
oppure `--recon-artifact=<artifact_id>`, mutuamente esclusivi. La prima opzione legge
l'outcome dello stesso target e richiede una sola Recon `ready` non vuota nella run;
per sorgenti multipass ambigue si seleziona un artifact. Una run fallita nel downstream
puo' fornire una Recon valida. L'artifact deve essere una CategoryRecon globale accettata,
valida e dello stesso snapshot. Per gli storici senza snapshot registrato, l'identita'
del target deriva dalla directory e il commit sorgente resta esplicitamente sconosciuto.
Laravel estrae solo la mappa in una fixture privata montata read-only; non importa
finding, history, source reference o usage della vecchia run. Il coordinatore valida
la mappa, crea una coda nuova mantenendo ordine e contenuto delle aree e avvia normalmente
Reader e Investigator. Ogni pass riparte dalla stessa mappa congelata. Provenienza,
hash e numero di aree sono in `global_pipeline.recon_reuse`; le chiamate e gli EP
incrementali Recon sono zero. Setup del target e indicizzazione restano necessari.
Una sorgente assente, invalida o ambigua ferma il lancio senza generare una Recon sostitutiva.

La run globale impone `reader_checkpoint` e deduper deterministico. Ogni Reader decide la continuazione e la
chiusura della sola area assegnata. Ogni ReaderLead valida le source reference e passa
atomicamente dal deduper unico prima dell'ammissione Confirmer. Una lead ammessa viene
accodata subito, senza sospendere il Reader in attesa del downstream. CandidateHandoff
e' un boundary interno normalizzato dal payload narrativo piatto: `ledger.promote` persiste
e pubblica la milestone statica prima di sbloccare l'unico envelope dinamico.
Il task prosegue con un breve delta di fase, senza nuovo dossier o seconda coda Worker.
Le lead acquisite proseguono esclusivamente downstream; il Reader continua l'esplorazione.
Il suo inventario conserva ID e titolo dei prodotti emessi, senza esiti o direttive downstream.
AreaEnrichmentLead viene validata su reference e locator e accodata dal coordinatore;
gli assignment esattamente duplicati sono scartati deterministicamente.

Il cap Reader aggregato copre tutti i Reader, checkpoint, retry e compressione: default
3.000.000 EP regular per pass, scalato una sola volta dal preset oppure sostituito da
`--reader-points`. Recon conserva il limite distinto di 160.000 EP. L'accounting include
il consumo live di tutti i task e lo salda una sola volta. Esaurire il cap Reader blocca
nuovi assignment, richieste Reader e nuove lead; non blocca lead gia' ammesse, anche in
coda, o nuovi Worker derivati da esse. Le risposte gia' in volo possono sforare il cap:
l'overshoot resta registrato. Il cap totale del pass resta una safety net distinta.
Confirmer sblocca 500.000 EP per lead canonica e 250.000 per continuazione; Worker usa
un envelope unico da 1.000.000 EP. Nessuno dei due erode il cap Reader.

Python, `pentest:run`, `benchmark:run --global` e avvio web propagano
`reader-concurrency` (1-4), `lead-concurrency` (default 2), `lead-model` (default modello Worker),
`reader-points` e `worker-points`. Il report `global_pipeline` registra configurazione,
stati/eventi per scope, conteggi, code, stop Reader e completamento downstream. Il pass
finisce dopo aver drenato tutte le lead ammesse; coverage resta incompleta se esistono
aree non chiuse. Failure locali non cancellano altri agenti. Un errore di persistenza
o una cancellazione globale raccoglie i task e conserva il checkpoint disponibile.

Le fasi contabili non sono due agenti. Il prompt stabile dichiara il valore autonomo della
conferma statica: assenza di prova dinamica, budget negato, test fallito o errore non la
annullano. `rejected` richiede una barriera positiva; `confirmed` richiede evidenza dinamica
scoped e valida. Gli override Confirmer/Worker incompatibili producono un errore prima del
runtime; il nuovo pool usa soltanto `lead-concurrency`. I percorsi sequenziali e i benchmark
Confirmer-only/Worker-only mantengono le proprie opzioni e conversazioni.

Ogni episodio unificato passa dalla finalizzazione prima di chiudere gli stage. Dopo uno
stop operativo tenta al massimo un checkpoint tool-free con il contratto minimo; un esito
conclusivo viene applicato direttamente senza seconda serializzazione. Durante la raccolta,
Confirmer e Worker accettano anche testo libero: resta nella stessa conversazione e passa
al self-checkpoint esistente, senza inferire decisioni o applicare milestone dalla narrativa.
Checkpoint, terminale e riparazione richiedono invece l'output strutturato `decision` e
`narrative` facoltativa, senza tool investigativi. Il recovery di output
e' limitato a un tentativo; non riprende la raccolta e non usa la riserva Worker per HTTP. Senza envelope
dinamico chiude sullo stage statico ancora aperto, senza grant Worker artificiale. Errori
permanenti, cancellazione e checkpoint/terminale falliti portano a conservazione da stato
autorevole con motivo esplicito, senza inventare output modello o trace. Una promozione
persistita conserva milestone, piano, severity, ref e prove acquisite in `suspected`;
`lead_investigation` distingue terminale tentato/applicato e salute tecnica. Gli errori
restano contabilizzati anche quando il finding statico sopravvive. Una persistenza
fallita propaga l'errore e impedisce nuove pubblicazioni dal pool, conservando lo snapshot
atomico precedente; un kill del processo non puo' garantire un'altra chiamata modello.

La categoria proposta dal Reader e' opzionale: l'orchestratore la normalizza sulla
registry OWASP oppure usa `unclassified`. Nelle run filtrate il filtro richiesto resta
autorevole; il percorso filtrato standalone usa il self-checkpoint e la discovery condivisa.

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
3. self-checkpoint Reader produce il checkpoint semantico dell'area e decide se continuare,
   cambiare focus o chiudere l'area; continua soltanto con un test discriminante bounded e
   non reitera una direttiva inevasa o una tranche dominata da duplicati senza nuova evidenza.
   L'orchestratore applica la transizione usando l'identita' area catturata nello snapshot:
   una chiusura duplicata o obsoleta conserva il checkpoint ma non chiude l'area successiva.
   Decisione proposta, decisione applicata e causa di normalizzazione restano registrate.
4. Confirmer ricostruisce ogni lead e prepara un piano di verifica ancorato all'istanza, eseguibile dal Worker fin dal primo passo.
5. Worker verifica dinamicamente il candidate.
6. Il Reader prosegue nella stessa area e history dopo l'acquisizione; una lead non chiude l'area e il suo verdetto non ritorna in discovery.
7. Il Reader puo' proporre una `AreaEnrichmentLead` come recovery per una superficie
   imprevedibile; il Reader decide semanticamente e l'orchestratore accoda l'area approvata.
8. La chiusura dell'ultima area completa la discovery; le lead pending restano nella coda downstream.
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

Reader e i suoi self-checkpoint consumano per ogni assignment un unico envelope economico
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

I boundary ordinari sono self-checkpoint dello stesso Reader. Le lead restano
acquisite senza una seconda review online; le aree aggiuntive sono validate e trasferite
al parent come `pending_integration`. La valutazione di deduplica e qualita' e' offline.
`--defer-enrichments` mantiene a zero il dispatch
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
   autorevole e avvia il Reader normale. Il self-checkpoint Reader resta un controllo operativo
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
   Con `--unified-lead` lo stesso runner usa `_investigate`, il percorso di produzione globale,
   con stage condizionati anziche' un fondo operativo anticipato. `--replay-manifest` e `--scope`
   selezionano ReaderLead storiche validate sul commit/checksum e frozen in subject separati
   dagli esiti evaluator; nessun CandidateHandoff o oracle storico entra nell'input.
   Il replay `--unified-lead --replay-manifest` esegue una sola `GlobalPipeline` e sandbox
   per ripetizione: ammette le ReaderLead frozen attraverso il deduper e il broker globali,
   le drena con `lead-concurrency` (default 2) e un cap totale condiviso. Recon viene
   recuperata dal primo output storico congelato e ogni lead eredita la propria area
   Reader originaria; il surface inventory e il bootstrap runtime avvengono una volta.
   Non esegue nuove chiamate Recon/Reader e non importa note o prove downstream storiche.
   I notebook della nuova run sono condivisi; history, cookie/client HTTP e sessione
   provider restano separati per lead, come in produzione. Lo stato applicativo resta
   comune durante il batch; il reset avviene solo fra ripetizioni. `--isolated-leads`
   conserva il controllo con sandbox nuova per caso. E' un benchmark downstream
   condizionato alle lead frozen: non dichiara completa la coverage di discovery.
   Le fixture probes esistenti verificano il setup; `--preflight-only` valida gli input
   e `--runtime-preflight` verifica l'ambiente senza modelli. Il registry conserva i boundary validi e un
   `UnifiedLeadRun` con snapshot del finding, anche in caso di errore tecnico downstream.
   Il report aggregato collega ogni finding al proprio artifact/replay scope, mantiene
   l'accounting per lead/fase e misura il batch separatamente dal lavoro attivo. La matrice
   accetta sia gli outcome globali sia i controlli isolati; i contatori retry/errori
   aggregati restano esplicitamente marcati come appartenenti al batch.
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

- il self-checkpoint Reader emette una decisione piatta e `checkpoint_summary` narrativo;
  l'orchestratore costruisce l'`AreaCheckpoint` interno con area id, superfici osservate e
  source reference ricavate dallo snapshot autorevole;
- la deduplica resta nel componente dedicato alla frontiera di acquisizione;
  non introduce un output di ritorno della lead al Reader.

Il default e' dare autonomia ai modelli, soprattutto ai ruoli smart come `Confirmer` e
`Worker`. Limiti stretti, budget cap locali, supervisioni frequenti e prompt molto
restrittivi sono guardrail da introdurre solo quando un comportamento reiterato e
patologico e' osservato nei log, non come prevenzione generica. Prima di aggiungere un
guardrail che interrompe il ragionamento del modello bisogna chiedersi se il problema sia
meglio risolto aumentando tranche, semplificando il contratto o lasciando il ruolo
completare il proprio workflow.

Prima di patchare un comportamento fragile con regole orchestration, self-checkpoint aggiuntivi o
prompt piu' stretti, valutare esplicitamente un upgrade del modello usato da quel ruolo. Se
un modello non riesce a seguire un contratto ragionevolmente semplice o a completare un
workflow operativo, patchare intorno al modello puo' introdurre complessita' e regressioni
peggiori del costo di usare un modello migliore.

Non patchare preventivamente problemi non dimostrati, soprattutto problemi di comportamento
dei modelli. Due regressioni da evitare come anti-pattern:

- Reader usato come timer generalizzato per ruoli operativi: ha consumato budget e
  interrotto flussi produttivi. La review del solo Reader e' invece event-driven e produce
  memoria/decisioni che il Reader non deve piu' serializzare.
- Category Recon troppo strict come checklist bloccante: puo' impedire al Reader di
  esplorare un'area vulnerabile osservata solo perche' non prevista nella checklist
  iniziale. Recon orienta e prioritizza, ma non deve diventare una gabbia che vieta piste
  concrete emerse durante la discovery.

Il reasoning del modello è sempre attivo. Modello e reasoning predefiniti di `recon`,
`reader`, `confirmer` e `worker` risiedono nel singolo
`agent/pentest-agent/Models.json`, validato all'avvio; ciascun ruolo ha i campi piatti
`model` e `reasoning_effort` (`low`, `medium` o `high`, con `high` massimo supportato).
Category Recon e Reader sono quindi configurabili indipendentemente.
I default Confirmer e Worker sono entrambi `z-ai/glm-5.3-flash`, con reasoning `high`;
nel globale questo e' anche il default dell'investigatore unificato. Gli override CLI e
le rispettive variabili d'ambiente restano prioritari. Nel runtime Laravel containerizzato
il JSON viene montato read-only dall'host a ogni run, così il suo aggiornamento non richiede
un rebuild dell'immagine. L'opzione `pentest:run --test` controlla soltanto il rebuild
dell'immagine e il wire debug e non modifica il reasoning.

Il protocollo positivo di completamento non richiede una proposta Reader separata: quando
il Reader chiude l'ultima area, l'orchestratore verifica deterministicamente Area Ledger,
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
la policy comune di retry completo del turno modello. Category Recon dispone di sedici richieste
investigative, 24.000 token massimi e una richiesta per la serializzazione. Ha un
plafond cumulativo distinto di 80.000 EP, intersecato con il cap economico della run. Il prompt impone breadth e riconciliazione delle
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

Reader produce soltanto `ReaderLead`, `AreaEnrichmentLead` o `ReaderCheckpoint`.
Il modello decide e racconta; l'orchestratore normalizza categoria, locator e source
reference dalle letture osservate, identifica il prodotto, lo collega e lo persiste.
Una lead emessa non torna al Reader e non viene riaperta mediante un output di enrichment.
Confirmer e Worker leggono autonomamente il sorgente della lead assegnata.
Il Reader continua nell'area corrente dopo l'acquisizione, senza attendere un verdetto.
Il coordinatore globale esegue gia' il downstream contemporaneamente; l'ingresso filtrato
standalone riserva l'envelope Confirmer all'acquisizione, accoda le lead e drena il
downstream dopo discovery, senza restituirne l'esito.

`ReaderLead` descrive titolo, ipotesi, operazione sospetta, evidenza iniziale e unknowns.
Locator e weakness_kind sono descrittivi e tolleranti. Il quality gate esistente verifica
che la narrativa descriva una pista concreta; la deduplica resta alla frontiera di
acquisizione, senza un secondo agente di review.
`AreaEnrichmentLead` descrive domanda, motivazione, locator e prossima verifica. I controlli
esistenti su reference, path e identita' precedono l'accodamento. Nei benchmark con coda
esterna il parent riceve il prodotto `pending_integration` e lo valuta offline.

Il self-checkpoint e' l'unico percorso. `ReaderCheckpoint` e' ammesso anche durante il
turno operativo e viene applicato senza un'altra richiesta al modello. Ai boundary
periodici e cognitivi l'orchestratore chiede un turno tool-free allo stesso Reader,
conservando modello, sessione, history e contratto. Decisione `continue` richiede un
singolo `next_step`; `area_closed` non lo accetta. `reason` e `checkpoint_summary` sono
narrativi; `category_notes` e' opzionale. Area, superfici e fonti vengono collegate
all'`AreaCheckpoint` interno dallo snapshot autorevole. Il checkpoint puo' anche emettere
una lead o un'area sostenuta dalle osservazioni: il prodotto viene acquisito prima di nuove
letture e non implica la chiusura dell'area. La chiusura dell'ultima area completa la
discovery indipendentemente dal downstream. Errori tecnici conservano memoria, evidenze e
prodotti acquisiti e marcano la discovery incompleta. La strategia e' `reader_checkpoint`.

Confirmer riceve anche lead acerbe e svolge discovery verticale nell'intera codebase, sempre scoped alla lead. Il suo compito è raccogliere contesto completo dal codice e confermare staticamente source, propagation, sink, reachability, mitigazioni e route rilevanti. Non deve completare una checklist esaustiva di dettagli operativi: il Worker dispone di `read_source_ref`, lettura del codice, accesso read-only a database e target e tool HTTP, quindi può adattare actor, autenticazione, formato e setup durante la verifica. Il Confirmer termina quando ha un sink verificato sul sorgente, una primitive plausibile e un primo test HTTP discriminante descrivibile, oppure quando una barriera statica positiva dimostra la chiusura. Un fatto ottenibile soltanto da una risposta HTTP appartiene normalmente al `verification_plan` del Worker.

L'handoff applica la regola anchor-o-barriera: quando la route e' derivabile staticamente,
`attackable_routes` contiene path concreti con parametri e valori noti, senza placeholder, e
il `verification_plan` descrive la prima probe discriminante. Quando il piano lascia gap,
il Worker ricostruisce autonomamente route, parametri, setup e catena statica consultando
il codice e il runtime. L'ingresso resta sempre un CandidateHandoff valido: nessun ritorno
al Confirmer o richiesta di autorizzazione per arricchire la stessa lead.

L'output model-facing statico e dinamico e' un unico `InvestigatorOutput`: soltanto
`decision` obbligatorio e `narrative` opzionale (default vuoto). La fase statica ammette
`continue|candidate_handoff|lead_closure`, la dinamica
`continue|confirmed|rejected|suspected|blocked`. Il modello racconta flusso, barriera, test e
risultato nella narrativa; non deve ripetere ID lead, metadata Reader, checklist o segnali in
campi obbligatori. L'orchestratore costruisce `CandidateHandoff`/`LeadClosure` interni con
identita' e source reference autorevoli. I vecchi piani e recipe restano leggibili; non
sono requisiti del nuovo output. Un esito conclusivo, anche durante la tranche operativa,
viene applicato direttamente. Solo un boundary privo di esito richiede un self-checkpoint
senza tool investigativi. `continue` non ha un validatore di concretezza: la valutazione
dell'utilita' e del prossimo passo e' semantica e appartiene al modello. Il Reader non
riceve di ritorno la lead e non partecipa a questa finalizzazione.

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

La policy distingue errori di output, di contesto e di trasporto. Il transport esegue un
solo tentativo; guasti provider transitori possono usare al massimo due retry orchestrati.
Errori di configurazione/input e guardrail locali non vengono ritentati. Gli output passano
prima dalla normalizzazione JSON schema-aware, senza inventare narrativa o semantica.

Il Reader ha un cap iniziale di risposta di 32.000 token e reasoning almeno medium.
Il cap non e' un grant economico. Pydantic non ritenta internamente gli output Reader.
Un payload completo ma invalido viene prima normalizzato localmente; se serve, una sola
richiesta tool-free con payload, schema, errore e contesto autorevole minimo ripara la
serializzazione senza history investigativa. La riparazione conserva tipo e decisione.
Una risposta troncata dal limite di output ha un solo recupero con cap fino a 64.000 token
nello stesso episodio, se l'input entra nella capacita' con la nuova riserva. Il guardrail
considera questa riserva prima della richiesta. Output exhaustion non forza compattazione
e non abbassa il reasoning. Una seconda failure ferma l'area incompleta senza un nuovo
ciclo esterno o replay investigativo. Evidenze e prodotti acquisiti restano persistiti.

Confirmer/Worker usano lo stesso recovery limitato descritto sotto; gli altri ruoli
conservano i rispettivi retry e recuperi esistenti. Il client ha timeout
di inattivita' di 120 secondi, deadline Reader di 300 secondi (anche nel recovery) e
deadline di 240 secondi per gli altri ruoli. Consumi falliti
restano contabilizzati. La cache dipende dal prefisso di input e dal provider: cambiare il
solo cap non implica necessariamente invalidarla; nessun recupero presume costo nullo.

`UsageLimitExceeded` rappresenta i guardrail locali di budget o richieste e non viene
ritentato. Confirmer/Worker hanno zero retry SDK di output: un payload completo viene prima
normalizzato localmente; se resta invalido, una sola richiesta tool-free riceve payload,
schema, errore e decisioni ammesse senza ripetere la history investigativa. Un output
troncato puo' avere un solo recupero sulla history originale con cap raddoppiato fino a
64.000 token, soltanto se entra nella capacita' con la nuova riserva. Non si riduce il
reasoning e non si forza compaction per recuperare output. Il cap ordinario resta invariato;
checkpoint e un eventuale recupero hanno riserva di due richieste, non pile di retry
terminali. Un secondo fallimento conserva finding e journal con stato incompleto senza
riavviare l'investigazione o chiedere un altro checkpoint. Costo dei tentativi falliti e
recuperi resta contabilizzato.

Il Worker usa il candidate, letture del codice e sessioni actor separate per verificare il
finding. `verification_plan`, `success_signal`, `rejection_signal` e recipe appartengono ai
piani legacy importati, non sono campi richiesti al nuovo Investigator. Il dossier narrativo
e gli indici scoped consentono di ricostruire route, setup e primo esperimento senza
routing inverso. `read_source_ref` recupera on demand il codice autorizzato; l'access set
include le reference del finding e del piano. Quando disponibili, `query_database`,
`run_target_command` e `run_target_probe` forniscono osservazioni runtime. Una shell non
puo' fabbricare direttamente l'impatto usato come prova. Il modello decide il verdetto e lo
racconta in `narrative`; l'orchestratore collega identita', citazioni note e trace senza
richiedere un secondo DTO del modello.

Il dossier Confirmer→Worker aggiunge una sola `narrative` piatta che collega primitive,
controlli osservati, gap residuo, esperimento discriminante e criteri positivo/negativo.
Lead, area e stato operativo provengono dall'assegnazione; gli storici privi della relazione
vengono proiettati deterministicamente dai campi legacy, senza parser semantici. I campi
piatti necessari al controllo di flusso e al report restano tipizzati.

Con `WORKER_BROWSER_ENABLED` Laravel prepara un sidecar Playwright/Chromium effimero per
audit, con rete e token privati. Soltanto l'Investigator dinamico riceve
`browser_code(actor, code)` e `inspect_browser`. Il codice e' il corpo di una funzione
JavaScript async con `page`, `context`, `target_url` e valori privati dell'actor;
`return` e' libero e facoltativo. Non esistono piu' step/locator/assertion DTO esposti al
modello. Gli artifact del vecchio contratto restano leggibili.

Ogni sessione audit/categoria/lead/actor avvia lazy un runner Bubblewrap e una propria
istanza Chromium. Browser, cookie e storage persistono; le variabili locali dello
snippet no. Il runner ha namespace Linux separati per user, mount, rete e PID, ambiente
ripulito, filesystem di runtime read-only e /tmp privato; non riceve token gateway,
chiavi LLM, sorgenti, mount host o socket Docker. Node non ha rete diretta. Due socket
Unix del solo compartimento inoltrano proxy target e controllo CDP; un terzo socket
trasporta comandi/risultati. Gateway e collector restano fuori dal compartimento.
Il codice puo' usare Playwright e moduli Node standard, senza VM JavaScript come barriera
di sicurezza. Anche un nuovo browser/context creato dal codice resta nella rete isolata.

Il proxy autorizza schema/host/porta target anche attraverso redirect e CONNECT HTTPS,
senza modificare body, risposte o CSP. Popup, service worker e download sono disabilitati
nel context fornito; il modello non riceve un origin attaccante aggiuntivo. Cookie/DOM,
JavaScript evaluate/addScriptTag o altri interventi diretti del tester sono setup,
non prova automatica di XSS. Il modello valuta causalita', marker e contesto; il
benchmark richiede eventi reali raccolti e osservazioni persistite, senza inventare
assertion PASS per gli script. Un valore restituito dallo script non crea un evento.

Deadline di 60 secondi per script, misurata dal gateway esterno; il client concede 105
secondi includendo avvio e margine. Errori di sintassi, Playwright e serializzazione sono
risultati ordinari: non provocano retry SDK o fatal dell'Investigator. Un errore conserva
lo stato; timeout, cancellazione o morte del runner terminano la sola sessione e dichiarano
`state_lost`. Una nuova chiamata puo' ricrearla, senza replay. Le invocazioni della stessa
sessione sono serializzate; solo ritrasmissioni dello stesso invocation id sono deduplicate.
Il cleanup chiude runner, Chromium, socket, proxy e profili, anche dopo errori. Il profilo
sidecar predefinito concede 2 GiB e 1024 PID/thread per le istanze Chromium separate;
non aggiunge CAP_SYS_ADMIN. Il seccomp pinned consente mount/umount/pivot_root necessari
ai namespace non privilegiati. Readiness verifica namespace e sandbox Chromium prima
dell'avvio dell'agente; se il kernel non li supporta il provisioning fallisce.

Ogni script persiste automaticamente codice redatto, actor/scope, output libero,
stdout/stderr bounded, errore, durata, stato perso, rete, dialog e osservazioni. action_ref
ed observation_ref sono assegnati dall'harness nel ledger atomico e nel journal esterno
al contesto. Nel prompt tornano soltanto riepilogo, risultato utile e riferimenti; il
modello non serializza DTO di persistenza. Le action in memoria restano bounded a 80,
con cursor assoluti per le tranche. Questo materiale prepara il regression testing
assistito, senza introdurre un agente di replay o un framework generale di macro.

`inspect_browser` conserva summary, forms, ARIA/DOM, testo, eventi e rete con redazione,
snapshot immutabili paginati (fino a otto da 1 MB per sessione) e pagine di massimo
12.000 caratteri. Leggere uno snapshot non riesegue il codice. Le sessioni HTTP e browser
dello stesso actor restano separate; dopo login il CSRF va acquisito nella sessione
corrente. Script o POST con effetti parziali non vengono mai ripetuti automaticamente.

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
transazione autorevole. Le citazioni nella narrativa sono facoltative: l'orchestratore collega soltanto ID noti
nello scope senza spendere retry per assenza o ref sconosciuti. Non seleziona automaticamente
un test decisivo e non inventa baseline, assertion o oracle. I campi `exploit_ref`,
`baseline_ref`, `test_ref` e `observation_refs` restano leggibili negli output storici.

Il Worker e' l'autorita' semantica dell'episodio dinamico. Parte da un `CandidateHandoff`
interno oppure importato, anche senza Confirmer eseguito nella stessa run. Il contratto
model-facing e' sempre `InvestigatorOutput`, inclusi i self-checkpoint. Un confirmed
richiede soltanto provenienza tecnica: almeno una tool call eseguita con risultato nella
stessa lead, anche probe, script o browser, senza whitelist di trasporti. L'harness non
interpreta status, body, timing, concretezza della narrativa o sufficienza della prova.
Il report dichiara `evidence_validation.mode=tool_execution_provenance` e
`semantic_validation=model`: non attesta i controlli rimossi. Senza trace conserva il
confirmed dichiarato con `applied=false`, finding suspected e incompletezza tecnica,
senza retry semantici. Il milestone statico resta acquisito. Gli evaluator leggono anche
le attestazioni storiche Worker e Dynamic Judge.

Il report conserva narrativa dell'exploit, citazioni riconosciute e puntatore al journal
completo. Questo materiale permette report semidettagliati e una futura regressione
assistita; il P0 non implementa un agente di regression testing o replay automatico.
Baseline e barrier positive restano criteri semantici nel prompt, non campi bloccanti.
Errori tecnici o economici conservano le prove e la lead suspected con motivo esplicito.

I tool HTTP richiedono `actor` nello schema inviato al modello, incluso `anonymous`.
Gli actor browser sono limitati ai profili applicativi realmente configurati piu'
`anonymous`; gli errori riportano le alternative. I child Investigator ereditano i profili
autorevoli. Sessioni HTTP/browser restano separate, il modello deve acquisire CSRF fresco
dopo login e nessun POST viene ripetuto automaticamente. Il profilo BookStack imposta
APP_URL sull'origin runtime effettivo prima della readiness: non allarga gli origin del
browser e non aggira la sua policy di rete.

Il riferimento DeepSeek Harness e' la separazione fra risultato validato, conclusione del
turno e log persistente separato dal contesto: commit
[`5719020`](https://github.com/deepseek-ai/deepseek-harness/blob/571902028a75c64c1d767f4ae5fd099485f0ddf0/packages/subagent/subagent/src/structured.ts)
e [session persistence](https://github.com/deepseek-ai/deepseek-harness/blob/571902028a75c64c1d767f4ae5fd099485f0ddf0/packages/session/session-persistence/README.md).
Non sono introdotti runtime o dipendenze DS; la policy di singolo repair e' di Lailaps.

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
Reader e Recon restano ignari delle utenze. Nessuna sessione viene preautenticata.
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
concede 48 richieste investigative, 160.000 punti, 32.000 token di output e fino a 48 aree;
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
Reader mantiene la proxy discovery nei report che lo esercitano. Gli obiettivi a casi
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
Reader, Confirmer e Worker; il provider non e' parte dell'identita'
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

Il Reader termina incompleto dopo l'esaurimento del recupero previsto, con
`termination_reason=model_unavailable`. Non esiste un secondo ciclo di retry completo.
Le lead gia' acquisite restano disponibili al downstream e nei report parziali.
Gli altri ruoli conservano i rispettivi fallback descritti nelle sezioni dedicate.

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
Nel percorso filtrato Recon, Reader e Handoff condividono discovery. Ogni lead ammette Confirmer indipendentemente;
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
conserva la lead suspected (`stop statico` nel percorso statico, `worker_stopped` nel
percorso dinamico). La terminalizzazione tool-free mantiene una riserva di retry di output
piu' generosa per assorbire problemi di serializzazione strutturata. Il limite della
singola risposta e' 32.000 token per Reader e handoff, 24.000 per Recon filtrata, 32.000 per Recon globale e
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
investigative dedicate e retry Pydantic di output. Il Reader ha prompt/output propri
e riceve una copia read-only dell'episodio Reader. Se il budget a score termina durante un task attivo, la produzione
dell'output ha priorita' sul consumo raw.

Ogni singola richiesta LLM ha una deadline wall-clock: 300 secondi per Reader e suo
recovery, 240 per gli altri ruoli, distinta dal timeout HTTP di inattivita' (120 secondi)
e dalla durata della sessione. I retry SDK e transport sono disabilitati; l'orchestratore
possiede i retry bounded e il backoff cancellabile, senza aumentare il limite esistente.
Un errore di trasporto ripete la sola richiesta pendente dall'ultimo confine autorevole,
senza duplicare prompt, includere output parziali, imporre serializzazione terminale o
rieseguire tool acquisiti. Restano invariati medium Reader, cap ordinario 32.000 e budget.
AreaEnrichmentLead puo' omettere gli ID: l'orchestratore collega solo source reference
osservate nello scope e pertinenti ai locator, prima dei gate ordinari e del recovery.
Una proposta senza riferimento acquisibile e' conservata non acquisita, senza repair LLM
degli ID. Il recovery conserva una sola riparazione semantica dopo normalizzazione locale.
La telemetria registra anche i tentativi repair una sola volta e conserva ruolo/scope,
fase, durata, prima/ultima attivita' stream e provider osservato attraverso aggregazione
globale e multipass. Deadline locali, timeout di inattivita', errori di trasporto e risposte
HTTP upstream sono distinti; una deadline locale non incrementa gli errori HTTP provider.
Usage mancante resta incompleto, senza inventare costi. Il riferimento e' il
[retry executor DeepSeek](https://github.com/deepseek-ai/deepseek-harness/blob/86a402200d95fecef4529d969836f1fe13c6cb0d/packages/llm/llm-retry/src/index.ts),
con recovery tecnico separato dal contenuto semantico.

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

Il Reader usa un cap di 32.000 token. Il Worker usa un limite dedicato
di 14.000 token, riservato nel calcolo della capacita' di history, per evitare retry completi
causati dall'esaurimento dell'output.

Reader e Investigator mantengono il reasoning ordinario anche nei recuperi di output.
Il Reader resta almeno medium; i retry di trasporto degli altri ruoli usano l'effort configurato.

Dopo ogni acquisizione il Reader continua entro la propria allowance; la lavorazione
downstream della lead non governa il suo avanzamento nella discovery.

Restano validi soltanto limiti token non economici: capacità della context corrente, riserva di risposta e margine di sicurezza, dimensione della singola risposta e troncamento o paginazione degli output dei tool.

## History, cache e compression

### Vincolo obbligatorio: le fasi dello stesso agente preservano il prefisso

Discovery, self-checkpoint, produzione del summary di compaction e serializzazione
terminale sono fasi dello **stesso agente**, salvo un cambio di agente esplicito.
Una transizione di fase non autorizza a sostituire il contratto inviato al provider.
La policy di cache privilegia modello, system prompt, provider session ID e schemi
stabili. Investigator conserva prompt e schema di output, ma il toolset cambia fra fase
statica e dinamica ed e' rimosso nei checkpoint; il repair compatto usa una richiesta
dedicata senza history. Queste transizioni non garantiscono il riuso del prefisso. La history
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
operativa. Per Investigator il blocco avviene anche rimuovendo i tool dalla richiesta,
con il limite di cache dichiarato sopra; lo schema di output resta lo stesso.

La restrizione va applicata con un meccanismo di selezione degli output compatibile
con il provider e con un controllo nell'harness prima di eseguire qualsiasi tool
operativo. Il solo prompt non basta. Un filtro SDK che elimina o riordina le
definizioni prima dell'invio modifica il prefisso anche se chiamato `tool_choice` o
`prepare_tools`; il percorso Investigator descritto sopra accetta questo limite. La verifica riguarda la richiesta realmente serializzata, non
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
periodiche e approfondimenti; Confirmer per lead e Worker per candidate.
Recon e Handoff la mantengono per il rispettivo episodio. L'uscita
eccezionale da uno stream persiste tutti i messaggi gia' osservati prima di trasferire il
controllo. Ogni nuova epoch Reader svuota la history e cambia conversation ID mantenendo lo
stesso provider session ID; system prompt, toolset e output schema restano invarianti. La
memoria reiniettata e' un dossier bounded della sola area attiva, non l'intera CategoryRecon.
Il self-checkpoint legge la conversazione originale dello stesso Reader; tool call
storiche non vengono rieseguite. Il dossier conserva fonti e prodotti acquisiti senza
restituire una lead dal downstream alla discovery. Le source reference restano recuperabili
tramite `read_source_ref` e indice bounded. Il contatore periodico appartiene all'area e
attraversa gli handoff. La chiusura dell'area e la coda downstream sono indipendenti.
Confirmer e Worker usano i propri self-checkpoint.

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
quello globale puo' usare fino a 48 richieste investigative. Se la history raggiunge la
soglia hard del 90%,
l'orchestratore disabilita i tool e richiede immediatamente l'output terminale sulla
history che conserva esiti e decisioni, riducendo se necessario i body dei tool
a stub recuperabili prima del terminale. La telemetria registra `recon_status`, `recon_terminal_reason` e rende
quindi esplicita qualsiasi futura regressione che introducesse una compaction Recon.

## Esperimento Recon -> Reader per incarichi funzionali (P0)

Il branch sperimentale espone `benchmark:recon-reader {target-id}` in Laravel e
`pentest recon --global --with-reader` in Python. Esegue una Recon nuova e poi un solo
Reader attivo alla volta, riusando il lifecycle Reader con self-checkpoint esistente.
Il flag interno `recon_reader_test` richiede modalita' globale e `stop_after_reader`:
Confirmer, Worker e Handoff non vengono eseguiti. I comandi ordinari conservano
il proprio contratto Recon. Questo esperimento valuta il percorso congiunto, non Recon
in isolamento.

Ogni ingresso Reader usa esclusivamente `reader_checkpoint`, inclusi scan, Reader isolato,
Recon con Reader e wrapper Laravel. Il parametro di strategia accetta soltanto questo
valore. Ai boundary il runtime blocca i tool investigativi conservandone le definizioni;
la riparazione breve espone soltanto il contratto di output e nessun tool.

I prodotti del checkpoint attraversano la stessa acquisizione degli output operativi.
Le aree con coda esterna restano `pending_integration` per il parent. I self-checkpoint sono
contabilizzati sotto `reader`; artifact e telemetria conservano boundary, epoch, sessione,
output, decisione proposta/applicata, normalizzazione e consumi. Un checkpoint anticipato
applica direttamente il risultato senza una seconda richiesta.

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
con ID e titoli degli altri incarichi. Il Reader conserva la vista di coordinamento
completa. Reader sceglie le letture successive fra percorsi inesplorati, controlli da
confrontare e dipendenze condivise; puo' seguire codice fuori dai locator e proporre
`AreaEnrichmentLead` per comportamenti non rappresentati anche senza un sink sospetto.
Emette zero, una o piu' `ReaderLead`, con osservazioni e domande discriminanti negli
unknowns: il nuovo protocollo autonomo `InvestigationPacket` non e' implementato nel P0.
Una lead acquisita resta non validata e non finanziata dal downstream; Reader prosegue
nella stessa epoch. Chiusura, pivot e checkpoint sono coordinati dal Reader; al cambio
di incarico viene aperta una nuova epoch. L'ordine iniziale segue gli incarichi Recon,
con i pivot semantici gia' previsti dal lifecycle.

Recon e Reader consumano il budget discovery condiviso esistente, entro il
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

I boundary Reader passano al self-checkpoint dello stesso Reader; ogni boundary Confirmer passa al
self-checkpoint dello stesso modello e non al Reader. Il Worker passa al proprio
checkpoint quando manca un esito conclusivo al boundary; gli esiti conclusivi si applicano direttamente,
mentre `continue` riprende la stessa history append-only entro l'envelope iniziale.
Il Reader conserva invece la stessa history nella stessa area, aggiungendo
il checkpoint e la conferma di acquisizione di ogni prodotto una sola volta. Apre una nuova epoch al
pivot reale, alla chiusura area o alla pressione di contesto. Il recupero da output troncato conserva la history se entra nel contesto; la riparazione
di un payload completo usa soltanto il payload e il contesto minimo.
se l'orchestratore termina l'agente o cambia scope, la conversazione successiva è invece
nuova per definizione. Per il Confirmer `candidate_handoff` o `lead_closure` blocca sulla
stessa conversazione il tipo del verdetto terminale tool-free; `stop_lead` non è esposto al
modello. Se dopo `continue` il cap non finanzia un'altra tranche, il ledger conserva il
finding come suspected con stop statico conservativo per compatibilità dello stato
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
in attesa; gli assignment approvati continuano. Reader-only conserva la produzione grezza
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
deduper semantico esistente con budget autonomo. Nel Reader globale i child
generano lead raw; il coordinatore applica un solo indice deterministico al
corpus aggregato prima dell'handoff. Il normalizzatore deterministico opera
offline, senza provider, token o EP di deduplica.
Il runtime predefinito punta all'immagine separata
`lailaps-pentest-agent:unified-lead-20261006`; l'override
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


### Consegna dei tool e budget Recon

Gli output acquisiti rimangono nella LRU RAM da 32 MiB, senza persistenza dei body
su disco. Ogni preview contiene un identificatore, l'esito di esecuzione e lo stato
di consegna (`complete`, `partial`, `omitted`); debug e reference estese rimangono
nella cache. Un corpo semanticamente vuoto e un corpo omesso per budget sono distinti.
I comandi mantengono stdout e stderr separati e i metadati di esecuzione al livello
del risultato. Acquisizione incompleta ed eviction vengono dichiarate separatamente.

Le preview individuali hanno un massimo indipendente dal profilo di contesto di
12.000 token, envelope incluso. Il ProcessHistory applica il cap aggregato di
48.000 token al batch completo: riserva prima tutti gli esiti minimi, ripartisce
equamente il resto in ordine di tool call e redistribuisce le quote non utilizzate.
L'ordine di completamento delle chiamate concorrenti non decide l'ammissione.
Lo spazio realmente residuo nella history riduce ulteriormente il cap; se necessario,
i body vecchi vengono ridotti a stub recuperabili, preservando le coppie call/result.
Le proiezioni ripetute della stessa history non duplicano la contabilizzazione.

`inspect_tool_output` e' disponibile anche nella Recon categoriale. Gli output Recon
sono autorizzati soltanto nell'episodio/categoria che li ha prodotti; lo scope viene
generato dall'orchestratore, senza dipendere dall'esistenza di area o lead. I risultati
di recupero sono paginati e partecipano al cap di presentazione senza essere ricacheati.
Le source reference canoniche vengono registrate all'acquisizione; le osservazioni
di codice e i locator Recon vengono aggiornati sulla preview finale e sul recupero.
Un dato ricevuto da un binding Python non equivale a una lettura del modello.

Recon usa il profilo operativo da 256.000 token (override espliciti rispettati),
clampato alla capacita' della route modello/provider e al cap dell'input completo.
Il resolver condiviso sottrae overhead, riserva di risposta e safety margin; la
capacita' history e' quindi inferiore alla finestra configurata. Global Recon dispone
di 32.000 token di completamento, 48 richieste investigative e 160.000 EP cumulativi;
Category Recon di 24.000 token, 16 richieste e 80.000 EP. Il cap economico della run
resta separato. A plafond esaurito si conclude l'episodio: una richiesta terminale
senza tool e fino a tre retry di validazione, ammessi anche con consumo solo pending.
I limiti investigativi e terminali sono cumulativi nell'episodio, anche attraverso
retry tecnici che aprono un nuovo run Pydantic AI. L'eccezione non autorizza un
nuovo episodio e l'usage pending viene saldato una volta.
Recon mantiene la chiusura alla pressione di contesto, con riduzione deterministica
dei body e preflight terminale, senza compaction LLM ordinaria aggiuntiva.

Il log budget separa richieste investigative/terminali, input/output del modello,
history/capacita', output tool presentati, plafond Recon, residuo Reader e residuo
globale assoluto. La telemetria degli output registra soltanto dimensioni, stato,
motivo di riduzione, disponibilita' e scope, senza nuovi dump dei body.
