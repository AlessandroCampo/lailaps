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

`ToolOutputManager` conserva ogni risultato prima della preview model-facing in una LRU da
32 MiB per categoria. Ogni entry porta tool, ruolo, categoria, lead e area assegnati dal
runtime; un ID non concede accesso fuori scope. `inspect_tool_output` permette ai ruoli
investigativi di listare, leggere a finestre e cercare letteralmente gli output autorizzati,
distinguendo disponibilita', espulsione e acquisizione incompleta. Il Dynamic Judge usa lo
stesso archivio attraverso `inspect_persisted_evidence`, insieme alle evidenze HTTP, browser
e source gia' indicizzate. I tool di recupero non reinseriscono una seconda copia nella LRU.
Quando il Judge consulta un output, l'orchestratore conserva temporaneamente la finestra
redatta realmente restituita; se il voto cita quell'output o una sua observation reference,
l'estratto e la provenance vengono promossi nel finding prima di una possibile espulsione.

La stessa LRU contiene gli eventi `investigation-event-*` di Confirmer, Worker e Judge:
messaggi investigativi e decisioni vengono normalizzati e acquisiti prima di ogni compaction,
restando disponibili attraverso le epoch della lead. `search_investigation_history` esegue
ricerca letterale case-insensitive con massimo 20 estratti; `read_investigation_event` legge
finestre dello stesso evento e dichiara l'espulsione. Scope e autorizzazione derivano dalla
lead corrente. System prompt, metadata provider, credenziali e body dei tool non entrano nel
corpus; per i tool restano solo nome e riferimenti agli output autorevoli. I risultati dei due
tool di recupero non vengono reindicizzati. Un evento storico rimane un'affermazione o una
decisione, non evidenza: Worker e Judge devono consultare l'output o l'osservazione indicata.

`search_source` e `read_file` separano acquisizione e rendering: ripgrep, controllo path,
limiti di acquisizione, registrazione delle source reference e provenance producono prima un
risultato strutturato; il percorso nativo ne rende poi una preview soggetta al budget di
presentazione. I binding programmatici consumano il risultato strutturato entro gli stessi
limiti senza dover interpretare la stringa model-facing.

Il Confirmer dispone anche di `run_code(code, description)`: il codice e' un corpo Python
async e puo' invocare sequenzialmente soltanto i binding strutturati `search_source` e
`read_file`. Ogni programma gira in un container effimero senza rete, credenziali, sorgente
o socket Docker, con root read-only, tmpfs bounded, 256 MiB RAM, 32 processi, 30 secondi e
massimo 32 subcall. Un broker JSON su stdin/stdout esegue le subcall nel processo agente;
programma e binding sono contabilizzati separatamente. Timeout ed errori rimuovono sempre il
container e restituiscono anche le osservazioni gia' acquisite, senza replay automatico.

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
Reader -> Confirmer -> Worker -> Dynamic Judge. Le aree Recon sono domande concrete su
entrypoint, operazioni sensibili, confini di fiducia e controlli osservati; non sono nomi di
categoria. Non esistono riconciliazioni o handoff intermedi per categoria.

Recon, Reader ed Exploration Reviewer consumano la stessa allowance discovery del broker
staged del pass. Il cap e il preset sono quindi applicati una volta alla scansione globale,
senza moltiplicazione per categoria; l'ammissione staged di Confirmer, Worker e Judge resta
invariata. La categoria proposta dal Reader e' un attributo model-facing opzionale:
l'orchestratore la normalizza sulla registry OWASP oppure usa `unclassified`. In una run
filtrata il filtro richiesto resta autorevole indipendentemente dal valore proposto.

L'Exploration Reviewer vede schede bounded di tutte le aree note e puo' restituire
`finish_pass` soltanto dopo almeno una visita a ciascuna area e in assenza di lead pending.
Una terminazione prematura viene normalizzata senza retry verso la prima area non visitata o
verso la continuazione necessaria. Un `finish_pass` valido persiste `semantic_stop`, lascia
aperte le aree sospese e mantiene `coverage.complete=false` quando restano residui; la
chiusura completa continua a dipendere dal gate deterministico `_can_complete_discovery`.

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
Recon, Reader, Reviewer, Confirmer, Worker e Dynamic Judge. La risoluzione è deterministica:
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

Per ogni pass il flusso è:

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
5. `benchmark:worker` riceve un `CandidateHandoff` canonico congelato e ricostruisce le
   source reference dal commit assegnato. Ogni ripetizione passa dal normale provisioning
   `pentest:run` con sandbox e volumi nuovi, setup, readiness, attori, fixture probe e
   post-readiness; il riuso stateful e `--keep` non fanno parte del percorso comparabile.
   Sono avviati il Worker reale e il Dynamic Judge ordinario, con assignment, prompt,
   toolset, sessioni, compression, retry tecnici, gate e persistenza della run globale.
   Ogni uscita Worker, incluso `ContinueInvestigation` o un boundary operativo, passa al
   Judge; soltanto `retry_worker` concede una nuova tranche. Un voto terminale chiude
   l'episodio e viene applicato al ledger dall'orchestratore. `request_static_enrichment`
   conserva la lead come richiesta di informazione ma non avvia il Confirmer, perche' il
   subject resta il candidate congelato dello stadio Worker. Proposta Worker e decisione
   Judge sono persistite separatamente. L'oracle post-run, mai esposto al subject o ai tool,
   valuta la decisione adjudicata e la sufficienza (`sufficient`, `partial`, `absent`) delle
   prove; la proposta Worker resta diagnostica. Failure tecnici, astensioni e casi non
   classificabili hanno contatori e denominatori distinti.
   I subject Worker sono congelati esclusivamente da artifact `CandidateHandoff` prodotti e
   valutati dal benchmark Confirmer: il comando di freeze rifiuta payload curati, cosi' ogni
   dataset Worker conserva lineage Confirmer immutabile e non attribuisce una fixture manuale
   a una run agente.
   Anche qui `--dataset` abilita la selezione frozen canonica, mentre la sua assenza seleziona
   dal DB i `CandidateHandoff` `valid` prodotti dal Confirmer per progetto e categoria;
   `--artifact` ha precedenza su entrambe le modalita'.

La selezione Golden serve a misurare performance condizionale e non sostituisce il benchmark
end-to-end. Una Golden viene congelata per progetto, categoria e commit: non viene riselezionata
in funzione del modello sottoposto a confronto. Il benchmark Worker P0 misura l'episodio
dinamico condizionato sul candidate congelato con la stessa supervisione Worker/Judge della
run globale. Il lineage project-scoped e' quindi Recon -> Reader -> Confirmer -> Worker ->
Judge; proposta Worker e conferma attestata restano distinte negli artifact.

La filosofia operativa privilegia l'autonomia dei ruoli ad alta capacita' (`Confirmer` e
`Worker`): i supervisori intervengono ai boundary, non dopo ogni tool call. Il Reader costituisce
un'eccezione evidence-backed: il suo Exploration Reviewer entra su yield esplicito, soglia
cognitiva o intervallo massimo di richieste, senza tool e con snapshot bounded. Dynamic Judge
supervisiona le uscite del Worker e autorizza esplicitamente ogni nuova tranche.

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
`reader`, `reviewer`, `confirmer`, `worker` e `judge` risiedono nel singolo
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
`AreaEnrichmentLead` o il yield leggero `ReaderReviewRequested`. Non produce checkpoint,
proposte di chiusura o completion. La soglia di `ReaderLead` e' deliberatamente la plausibilita', non la
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

Il Reviewer entra su `ReaderReviewRequested`, soglia cognitiva soft/hard, intervallo massimo
di 16 richieste o proposta `AreaEnrichmentLead`. La novelty review delle lead resta separata.
Non entra nei boundary ordinari di Confirmer o Worker.

`CategoryRecon` resta la fotografia iniziale immutabile; l'Area Ledger separato e' la fonte
autorevole per scheduling e completion e contiene record `queued`, `active` e `closed` con
origine `category_recon` o `reader_enrichment`. Una `AreaEnrichmentLead` puo' avere locator
concreti oppure soli `seed_checks` bounded. Non attraversa validazione deterministica
semantica o provenienziale: l'Exploration Reviewer restituisce `approve_area_enrichment` o
`reject_area_enrichment`; l'orchestratore assegna l'id e accoda FIFO l'area approvata senza
interrompere quella attiva. Non e' una finding, non usa il finding ledger e non consuma un
lead stage.

Una `LeadEnrichment` riapre la stessa ipotesi chiusa o fermata dal Judge con una source
reference nuova oppure, una sola volta senza nuovi ref, con `closure_contradiction`
sostanziale che dimostri come l'evidenza già registrata contraddica l'adjudication. Un sink
distinto è sempre una nuova `ReaderLead`, anche quando condivide file o intervallo sorgente.
Un errore di applicazione del ledger su output Reader viene respinto e corretto nella stessa
discovery, senza trasformarsi in shutdown fatale dell'orchestratore.

Quando esistono lead già adjudicated, la nuova `ReaderLead` resta fuori dal ledger finché
un Reviewer semantico stateless non confronta l'ipotesi proposta con lo stato globale
compatto: ciò che il Reader ha letto e tentato, le decisioni pregresse e, in particolare,
motivo, evidence gap, prossimo test e inventario di uno stop del Judge. Il Reviewer emette
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
il `verification_plan` inizia da path, parametro, payload e oracle della prima probe HTTP.
Quando non e' derivabile, il piano dichiara esplicitamente cosa manca e il percorso minimo
al primo probe. Se riceve un piano privo di entrambi, il Worker emette subito `needs_info`
mirato invece di ricostruire autonomamente route e catena statica prima della prima HTTP.

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
source reference, feedback Worker, transazioni HTTP successive al marker e blocker della
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
sospetta, Worker consegna il guasto al Judge e un Judge indisponibile lascia la lead sospetta;
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

Worker usa il candidate e sessioni actor separate per verificare dinamicamente il finding. Il contratto minimo Confirmer→Worker non richiede più una Recipe Card annidata: `verification_plan`, `success_signal` e `rejection_signal` sono tre stringhe piatte obbligatorie, complete ma adattabili al runtime. La Recipe Card strutturata è un acceleratore opzionale; se valida il Worker può seguirla dal primo step non completato, mentre se è assente l'harness proietta deterministicamente il piano piatto in una recipe compatibile a singolo step. Una recipe opzionale malformata non blocca la promozione di un candidate che soddisfa il nucleo statico e i tre campi piatti. Il Worker conserva l'evidenza, non ripete test riusciti e deve comunque raccogliere una baseline per prove differenziali o temporali. `ContinueInvestigation` persiste gli eventuali identificatori degli step strutturati completati, oltre al progresso narrativo e al prossimo esperimento già presenti nel checkpoint. L'assignment proietta anche prima azione concreta, actor, prerequisiti già soddisfatti, oracle, impedimento e progresso precedente usando piano e checkpoint autorevoli; non impone deterministicamente HTTP come primo tool. L'handoff iniziale non duplica gli snippet integrali raccolti dal Confirmer: consegna il piano di conferma e i metadata delle source reference autorizzate, mentre `read_source_ref` permette al Worker di recuperare on demand soltanto il codice necessario al prossimo test. L'access set del Worker include deterministicamente sia `finding.source_ref_ids` sia ogni source reference citata nella proiezione del piano, così un ref usato dal piano è sempre recuperabile. I candidate storici con Recipe Card o campi setup/baseline/exploit restano adattati in lettura. Se i metadata eccedono il budget del singolo prompt, l'orchestratore conserva tutti gli identificatori recuperabili e rimuove la proiezione ridondante di path e range. Quando il command executor è disponibile il Worker riceve sia `query_database`, per `SELECT` strutturate tramite il dossier runtime persistente, sia `run_target_command` per altre osservazioni read-only strettamente pertinenti nel servizio scelto dall'enum, incluse configurazione, route e verifica di effetti già prodotti tramite l'applicazione; non può usarli per fabbricare direttamente l'impatto. Il contratto di `run_target_command` rende obbligatori sia `argv` sia `script`: la modalità non scelta viene rappresentata rispettivamente da lista o stringa vuota, evitando parametri opzionali nello schema esposto al modello. Ogni ruolo che dispone di `run_target_command` dispone anche di `query_database`. Il Worker restituisce una proposta narrativa tipizzata (`ConfirmedDecision`, `RejectedDecision`, `BlockedDecision`, `NeedsInfoDecision` oppure `ContinueInvestigation`) che non espone `lead_id`, evidence/observation ref, `exploit_ref`, `baseline_ref` o `test_ref` nello schema model-facing, non è autoritativa e non produce alcuna transizione terminale. L'harness collega scope e registri; alias legacy errati vengono ignorati senza retry operativo. Dopo un enrichment statico riprende la stessa conversazione e conserva route alternative, actor e test precedenti.

Il dossier Confirmer→Worker aggiunge una sola `narrative` piatta che collega primitive,
controlli osservati, gap residuo, esperimento discriminante e criteri positivo/negativo.
Lead, area e stato operativo provengono dall'assegnazione; gli storici privi della relazione
vengono proiettati deterministicamente dai campi legacy, senza parser semantici. I campi
piatti necessari al controllo di flusso e al report restano tipizzati.

Con `WORKER_BROWSER_ENABLED` il provisioning Laravel crea un sidecar Playwright/Chromium isolato per audit su una rete dedicata, gli assegna l'origin target già autorizzato e un token casuale scoped alla run, poi lo rimuove insieme alla rete anche su errore o cancellazione. Il sidecar non riceve socket Docker, sorgenti o artifact; l'agente riceve soltanto l'URL interno e il token effimero. Chromium parte lazy con sandbox esplicita, profilo seccomp Playwright pinned e sole capability `SYS_CHROOT` necessaria al sandbox (tutte le altre restano droppate con `no-new-privileges`); i context sono separati per audit/categoria/lead/actor, service worker e download sono bloccati, popup e richieste fuori origin sono bloccati dal gateway. Il Worker, e nessun altro ruolo, riceve gli unici tool `browser_flow` e `inspect_browser`: step e assertion piatti, bounded, strict e senza CDP, shell o JavaScript arbitrario. Login UI conserva le credenziali nel lato privato del gateway; sessioni HTTP e browser dello stesso actor restano separate. Il context vive attraverso compression e retry del Judge e si chiude solo alla lead terminale o alla fine dell'episodio Worker/Judge condizionale.

Ogni flow assegna un `action_ref` e checkpointa atomicamente azione, rete, dialog e osservazioni browser redatte nel medesimo outcome a due file, accanto alle transazioni HTTP senza inventare request id browser. `exploit_ref`/`test_ref` possono risolvere una transazione HTTP o una browser action; le proiezioni legacy restano leggibili. DOM, ARIA, page error, assertion UI e log generici sono diagnostici. Una conferma XSS browser richiede un evento con marker univoco, contesto pertinente, causalità del payload e `observation_ref` persistita della stessa action. Dialog esatto e cattura console contestualizzata sono oracle equivalenti; reflection, status 200 e impatto prodotto direttamente dalla shell restano insufficienti. Il Dynamic Judge verifica ownership, tranche e provenance prima di promuovere il ledger. Il benchmark end-to-end usa il discriminante versionato `requires_browser_execution`; il benchmark Worker/Judge conserva e valuta separatamente action/observation browser, e un caso DOM-only non richiede traffico HTTP fittizio.

Il contratto browser comprende `submit_form`: una POST di navigazione reale, con query nel `path` e body nel campo piatto `form` come testo URL-encoded (anche con nomi ripetuti). Il gateway crea e invia una form tramite codice interno fisso, conservando documento, redirect e CSP della risposta; non renderizza risposte API tramite HTML sintetico. `set_cookie` e `clear_cookie` operano soltanto sull'host target e nel context dell'actor, usando `name`, `value`, `path` e attributi cookie espliciti. Queste azioni producono osservazioni di setup: non provano da sole controllo dei cookie della vittima o provenienza cross-site. Origin attaccante, frame interattivi, popup e upload restano fuori dal contratto corrente.

Playwright gestisce strictness e auto-wait senza pre-check di esistenza. Le assertion attendono il proprio timeout e un loro fallimento restituisce `assertion_failed`, distinto dagli errori degli step. Il modello riceve un riepilogo narrativo piatto con PASS/FAIL, osservazioni rilevanti e riferimenti citabili; i DTO delle assertion e dei collector restano interni. Ogni nuova chiamata del tool ha un identificatore di invocazione nuovo; il gateway serializza i flow dello stesso actor e deduplica soltanto ritrasmissioni con lo stesso identificatore, anche in-flight. Gli action ref derivano dall'identificatore di invocazione e non dalla lunghezza della finestra in memoria. Il timeout HTTP del gateway copre le attese ammesse dal flow. La rete usa una finestra scorrevole bounded che continua a raccogliere dopo la saturazione, con collector separato per azione e indicazione delle omissioni; i dialog hanno priorita' sul rumore console. Le WebSocket sono bloccate esplicitamente.

`inspect_browser` offre anche `forms`, con form, campi, opzioni e locator CSS senza valori degli input. `summary` descrive capacita', limiti e stato; `network` mostra metadati redatti inclusi metodo, status, nomi dei campi form, Content-Type, CSP, Origin e Referer. Tutte le modalita' leggono snapshot immutabili paginati tramite cursor scoped a context, mode e selector: avanzare non rilegge il DOM e non salta caratteri. Il gateway conserva fino a otto snapshot da 1 MB per context, segnala l'eventuale limite di acquisizione e richiede un selector piu' stretto; ogni pagina di massimo 12.000 caratteri viene conservata integralmente nell'osservazione browser persistita.

Confirmer, Worker e Judge richiedono esecuzione browser per confermare XSS, anche quando il candidate propone un success signal HTTP piu' debole. Il gate usa i CWE gia' presenti (CWE-79 e varianti), con fallback sulle denominazioni esplicite legacy di titolo/weakness/impact, senza aggiungere flag o DTO model-facing. Una proposta `browser_execution` deve sempre citare una vera browser action e un'osservazione causale della stessa azione; dialog e console contestualizzata sono equivalenti, mentre una reflection differenziale HTTP non aggira il controllo. Il Judge resta responsabile della semantica dei candidate non classificati. Se manca un trasporto necessario, Worker/Judge conservano la lead sospetta con un blocco motivato o `keep_suspected`; un retry richiede un esperimento nuovo concretamente esprimibile.

Il sidecar include un proxy privato per context vincolato a schema, host e porta dell'origin autorizzato. Il browser lo usa anche per loopback e redirect: il solo routing Playwright non intercetta gli hop successivi al primo. Le richieste HTTP e le POST vengono inoltrate senza modificare body o risposte; HTTPS usa CONNECT soltanto verso l'autorita' target, senza terminazione TLS o modifica della validazione dei certificati. Un redirect fuori origin viene bloccato prima di consegnare la richiesta e produce `policy_blocked`; i redirect same-origin conservano la normale semantica browser. Proxy, socket e connessioni vengono chiusi insieme al context; nessun endpoint aggiuntivo viene esposto al modello.

La finestra in memoria conserva 80 browser action; un cursore assoluto con offset mantiene coerenti assignment e gate della tranche anche quando le azioni piu' vecchie vengono rimosse dalla finestra.

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
transazione autorevole. Gli ID restano interni ai checkpoint: gli output operativi non devono
ricopiarli. Per leggere artifact legacy, request id, response id, action ref e ID envelope
`tool-output-*` sono soltanto hint; vengono mantenuti quando risolvono univocamente nello scope
e ignorati senza retry operativo negli altri casi. Solo le decisioni interne del Judge espongono
`exploit_ref`/`baseline_ref` o `test_ref`; gli storici `*_request_id` restano alias accettati
esclusivamente in lettura. L'osservazione conserva locator, tipo di
estrazione, rappresentazione (`text_excerpt`, proiezione JSON o DOM), trasformazioni,
troncamento e redazione; una proiezione JSON/DOM non e' dichiarata copia byte-per-byte del
body. Gli estratti restano redatti e bounded, non sono un archivio di body; un risultato vuoto
o parziale non attesta l'assenza del comportamento fuori dalla porzione osservata.

Ogni invocazione del Dynamic Judge e' stateless e separata dal Worker, ma l'orchestratore
le passa il checkpoint narrativo precedente, l'ultima direttiva, il risultato Worker e
l'indice delle osservazioni. Il Judge aggiorna `checkpoint_summary` nella stessa risposta
con fatti acquisiti, tentativi esclusi, gap e prossimo esperimento. Viene invocato dopo ogni uscita
del Worker, inclusi verdetti tipizzati, `ContinueInvestigation`, boundary ed errori. Soltanto
`retry_worker` autorizza una nuova tranche entro il cap globale; gli altri esiti
chiudono o deviano semanticamente l'episodio. Usa knob indipendenti
`JUDGE_MODEL` e `JUDGE_REASONING_EFFORT`, derivati per default da `Models.json`,
cosi' l'adjudication puo' usare un modello diverso dal Worker; applica allo snapshot le
regole di sufficienza delle prove ed e' l'unica autorita'
semantica che puo' emettere `approve_confirmed` o `approve_rejected`; l'orchestratore resta
l'unico componente che applica materialmente il voto al ledger. Nessuna lead puo' quindi
diventare dinamicamente `confirmed` o `rejected` sulla sola proposta Worker.
Lo snapshot del Judge include un inventario harness-owned indipendente dalle citazioni del
Worker. Un unico tool read-only, `inspect_persisted_evidence`, pagina e recupera per ID le
source ref, transazioni e osservazioni già persistite della lead; non effettua richieste al
target. In questo modo anche baseline più vecchie della finestra recente restano consultabili.

Il Judge può inoltre emettere `keep_suspected`, `retry_worker`, `request_static_enrichment` o `blocked`. `retry_worker` concede una tranche per un esperimento discriminante se il cap globale finanzia Worker e voto successivo; una quarta o ulteriore continuazione resta ammissibile. `request_static_enrichment` porta la stessa lead al Confirmer tramite `needs_confirmer_evidence`; un CandidateHandoff revisionato torna poi a Worker e nuovamente al Judge. L'esaurimento del cap conserva la lead come suspected e non costituisce evidenza di rejection.

Quando `keep_suspected` chiude un episodio che ha comunque dimostrato una porzione positiva
del comportamento dinamico, il Judge può allegare un `evidence_inventory` strutturato:
claim dimostrate e relative transazioni HTTP, boundary raggiunto, gap residuo e prossimo test
discriminante. Il gate accetta nell'inventario soltanto request/response della slice Worker
della lead attiva; una source reference o una transazione di un altro episodio non può
validarlo. Il ledger marca quindi `dynamic_evidence_status=evidenced_partial` e conserva
l'inventario sotto `judge_stopped`, senza promuovere il finding oltre `suspected`. Tentativi
falliti o assenza di prova positiva restano `not_demonstrated` e non vengono rivalutati come
evidenza.

Prima di applicare un voto terminale, l'orchestratore verifica soltanto struttura, identita'
e provenienza: lead attiva, campi non vuoti, riferimenti esistenti e appartenenti alla slice
Worker della lead. Non confronta status, body, timing, actor o classificazioni e non
reinterpreta la sufficienza semantica decisa dal Judge. Alias request/response vengono
normalizzati soltanto quando il mapping nel ledger e' univoco; riferimenti inventati,
ambigui o fuori scope producono un retry del Judge. Se non si
ottiene un voto strutturalmente applicabile, la lead resta `suspected`/`judge_stopped`, non
`blocked`. Il report persiste `adjudication.role=dynamic_judge`, decisione, trace ed
`evidence_validation` v3 con `mode=structural_provenance_only`. L'evaluator benchmark
continua a riconoscere `dynamically_confirmed` tramite questa attestazione.

Nel solo percorso filtrato multi-categoria, Handoff Reader produce il contesto riutilizzabile dalla categoria successiva con soli fatti
`topic`/`fact` e `unknowns` model-facing. Le source ref dell'episodio restano un indice
separato dell'handoff e non vengono assegnate indiscriminatamente a ogni fatto. Se non
termina correttamente, l'orchestratore genera l'handoff dal report e dallo stato durevole.

## Ledger e resilienza

Il ledger è la fonte canonica di lead, source reference, transazioni e finding. Ledger e
report del singolo pass usano lo schema 9; il report root multi-pass usa lo schema 10. I precedenti artefatti
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

Dopo ogni tranche e transizione vengono aggiornati checkpoint, report parziale e telemetria. I fallback entrano in funzione solo dopo il turno terminale e i retry, senza ulteriori chiamate al modello. Se manca il report finale, il benchmark usa i finding durevoli del report parziale, conserva i costi effettivi e marca le metriche come parziali. Lo schema 9 include osservazioni sorgente deduplicate (`role`, `lead_id`, `tool`, `file`, intervallo di righe), milestone storiche `suspected`, `statically_validated` e `dynamically_confirmed`, adjudication semantica delle lead, inventario dinamico parziale e stato di funding; non persiste una seconda copia degli snippet. `coverage.reader_area_checkpoints` conserva l'ultimo checkpoint semantico bounded di ogni area.

Il report schema 9 aggiunge `evidence_ledger`, il registro durevole delle transazioni HTTP:
ogni tool HTTP registra risposta o timeout, actor handle, request/response ID, metodo, path,
status, timing, redirect ed excerpt bounded, con payload e header sensibili redatti. La
scrittura è atomica e sincrona prima che il tool restituisca il controllo al modello: un
errore di persistenza è un hard stop infrastrutturale, perché nessuna prova può restare
solo in memoria. Le pubblicazioni parziali/finali successive fondono il ledger esistente e
non possono cancellarlo, inclusi cambi categoria, errori di serializzazione o report
incompleti. Il Dynamic Judge riceve tutte le transazioni durevoli della lead oltre alla
proposta Worker opzionale: se la proposta manca o è malformata decide comunque dalle prove,
e nessun fallback dell'orchestratore conferma o respinge deterministicamente una
vulnerabilità. Gli evidence ID vengono normalizzati e verificati soltanto per esistenza e
scope, senza derivare un verdetto dai loro contenuti. Se
Worker e Judge non producono un verdetto valido la lead resta `suspected` con inventario
dinamico costruito dal ledger, senza perdere le transazioni. Lo stesso ledger include le
osservazioni derivate delle inspection response, deduplicate per identita' e scope: la
pubblicazione parziale/finale e il recovery le fondono con le transazioni senza cancellarle.
Il Dynamic Judge riceve le osservazioni rilevanti della lead attiva da un inventario
harness-owned, indipendente da eventuali citazioni del Worker; il fitting conserva la
provenienza e dichiara conteggio/motivo delle osservazioni omesse o ulteriormente troncate,
mentre il tool read-only consente di recuperare quelle più vecchie.
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
Confirmer, Worker e Dynamic Judge ricevono nel prompt privato credenziali e descrizioni;
Reader, Recon e Reviewer restano ignari delle utenze. Nessuna sessione viene preautenticata.
Le sessioni HTTP sono isolate per handle e persistono tra round e lead della stessa run;
prima di ogni round Worker/Judge una vista senza valori segreti espone stato vuoto o materiale
riusabile, nomi di cookie/header e ultima request dell'actor. Questa vista offre contesto e
opzioni senza imporre login, riuso o cambio identità. Il Worker può selezionare liberamente
un altro handle, usare `anonymous` oppure azzerare il solo stato HTTP locale con
`reset_actor_session`; il logout applicativo resta una normale richiesta al target.

## Benchmark dedicato Recon

`benchmark:recon` e' un percorso sorgente-only distinto da `benchmark:run`: materializza la
stessa vista sanitizzata, inizializza Codebase Memory e Surface Context, esegue il reale
handoff Recon e termina prima di creare il Reader. Con `--global` unisce i casi distinti di
tutti i manifest dello stesso target e snapshot; duplicati incompatibili rendono invalida la
fixture. Non prepara sandbox, non esegue
health check, readiness o fixture probe e non richiede un URL. La ground truth resta fuori
dal processo agente e viene letta dall'evaluator Laravel soltanto dopo l'handoff. L'outcome
persiste Recon, Area Ledger iniziale, Surface Context, tool telemetry, costo e punteggi per
caso. L'evaluator separa exact locator, guidance semantica e sola famiglia pertinente e
riporta strict/guided/weak recall, score normalizzato, recall@1/3/5, aree, locator e unknown.
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
strict/guided/weak e recall@K sugli anchor; Reader misura la classificazione `suspected`;
Confirmer la `static_validation`; Worker e Dynamic Judge la
`dynamically_confirmed`. Reviewer usa `suspected` come proxy controllato della supervisione
discovery, mentre Worker/Judge dichiarano esplicitamente la responsabilita' condivisa. Gli
obiettivi a casi usano F1 contro positivi e negative control del manifest; ogni scorecard
conserva anche TP/FP/FN e risultati per caso. Un ruolo con zero richieste e' `not_exercised`
e non riceve uno zero di qualita' artificiale. Le proxy condivise diventano attribuibili al
modello soltanto negli esperimenti one-variable-at-a-time; le altre proiezioni sono marcate
`observational`.

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
Reviewer, Confirmer, Worker e Dynamic Judge; il provider non e' parte dell'identita'
sperimentale.

Per valutare l'accesso sorgente del Worker, la telemetria espone anche i tool call nominativi
per ruolo e per lead, il flag effettivo, la breadth sorgente per ruolo, il tempo, i model
request e i bucket token precedenti alla prima HTTP del Worker. Un evento bounded descrive
ogni round Worker→Judge con nuove transazioni HTTP, nuove source reference, causa di uscita e
decisione del Judge; contatori aggregati distinguono round senza nuova evidenza, richieste di
enrichment statico e cicli completi Worker→Judge→Confirmer→Worker. I boundary Reader,
Confirmer e Worker sono contati per ruolo e separano quelli privi di nuova evidenza. Le review
legacy del Confirmer restano leggibili per compatibilità; il percorso corrente espone numero
di self-checkpoint, distribuzione `candidate_handoff|lead_closure|continue` e ripetizioni
della stessa domanda decisiva.

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
Reader valido azzera il contatore. Recon, Reviewer, Confirmer, Worker, Dynamic Judge e Handoff
restano contenuti nei rispettivi fallback descritti nelle sezioni dei ruoli.

## Budget e richieste

### Conferma benchmark per singola CVE

`benchmark:cve:run {target-id} {case-id}` e' un ramo benchmark distinto: Laravel
seleziona una sola case dal manifest, costruisce un DTO CVE privato e lo monta
read-only nell'agente. Il DTO contiene identità della case, categoria, CWE, anchor,
root cause, provenienza, oracle e fixture necessarie; non viene mai passato a Recon
o Reader. L'orchestratore crea deterministicamente una sola lead dalle anchor e
avvia soltanto Confirmer, Worker e Dynamic Judge. `--envelope-points` e' l'unico
hard cap condiviso fra questi ruoli: non esistono pool discovery, tranche o
overdraft. Il Judge può richiedere ulteriore Confirmer o Worker soltanto finché
l'envelope ammette una nuova richiesta; al suo esaurimento la lead viene fermata
nel ledger senza prosecuzioni. L'outcome e l'evaluator benchmark restano gli stessi
del percorso end-to-end, ma valutano la proiezione contenente la sola case selezionata.

L'accounting usa `pricing_schema_version: 2`: per ogni richiesta i pesi sono derivati dal prezzo del modello effettivamente servente in `model_prices.json`, con anchor fisso `google/gemini-3.7-flash` ($0.375 input e $1.875 output per milione) e floor `0.2`. Il peso cache è il rapporto reale, limitato a `0.1x`, per sussidiare intenzionalmente il riuso della cache. Le voci `pricing_basis: simulated:*` producono punti ma non entrano in `provider_cost_usd`; telemetry e benchmark le distinguono, e lo schema segmenta i confronti economici incompatibili.

Il budget economico usa una allowance discovery e un cap di 100.000.000 EP per pass. Non
esiste hard cap sui raw token cumulativi: input uncached, cached e output contribuiscono al
consumo con pesi per modello distinti. `--budget-category=small|regular|big|huge` scala
soltanto discovery (1.500.000, 3.000.000, 4.500.000, 6.000.000 EP per pass). Envelope
indipendenti dal preset e dal peso del modello: Confirmer 500.000 EP iniziali e 250.000 per
continuazione; Worker 1.000.000 iniziali e 500.000 per continuazione; Judge 100.000 per
voto. Il peso del modello si applica al consumo, mai al grant. Cap, impegni, uso e overshoot
restano visibili negli snapshot; gli artifact storici non vengono ricalcolati.

Recon, Reader e Reviewer di discovery/novelty consumano la discovery condivisa del pass;
nel percorso filtrato vi rientra anche l'eventuale Handoff. Ogni lead ammette Confirmer
indipendentemente; `statically_validated` rilascia il residuo Confirmer e ammette Worker più
Judge atomicamente. Confirmer, Worker, Judge e relative estensioni non erodono discovery.
Alla chiusura della lead gli impegni inutilizzati vengono rilasciati senza cancellare il
consumo. Una continuazione approvata è finanziata soltanto se il cap ammette una nuova
tranche e il voto seguente, con accounting idempotente e overshoot visibile.

I request limit sono guardrail anti-loop, non budget economici e non pacing ordinario. Il
Confirmer riceve una tranche iniziale da 12 richieste e ogni estensione concede fino
a 32 richieste, con massimo operativo 64. Prima di concedere ogni estensione esegue il
self-checkpoint tool-free sulla stessa conversazione; soltanto `continue` apre una nuova
tranche. `candidate_handoff` e `lead_closure` avviano la terminalizzazione tool-free e i retry
di output correggono la serializzazione senza riaprire la decisione. La telemetria distingue
`full_grant`, `partial_grant` e `terminal_only`. Il cap controlla esclusivamente il consumo
e non esprime un verdetto tecnico: quando non consente un'altra tranche, l'orchestratore
conserva la lead suspected (`reviewer_stop` nel percorso statico, `judge_stopped` nel
percorso dinamico). La terminalizzazione tool-free mantiene una riserva di retry di output
piu' generosa per assorbire problemi di serializzazione strutturata. Il limite della
singola risposta e' 6.500 token per Reader e ruoli generici, 5.000 per Reviewer, 12.000 per Recon e
14.000 per il Confirmer. Il guardrail predefinito dell'input e' 96.000 token stimati,
includendo history, nuovo prompt, prompt persistente e schemi: resta distinto dalla
finestra operativa da 128k e permette la review della history Reader disponibile. Un
rifiuto locale `PromptInputGuardExceeded` e' deterministico e non viene reinviato identico
nella retry ladder. La capacita' utile della history viene calcolata per ruolo e fase
sottraendo il limite reale della risposta, oltre al prompt persistente e al margine di
sicurezza; non usa quindi una riserva generica inferiore al cap del ruolo. Prima della
terminalizzazione Confirmer/Worker un preflight verifica che history, nuovo prompt e
riserva di risposta entrino nella finestra effettiva: quando non entrano comprime la stessa
conversation, senza sostituirla con il solo checkpoint. Tutti i ruoli dispongono inoltre
di due retry completi per gli errori tecnici retryable del modello. Recon conserva richieste
investigative dedicate e retry Pydantic di output. Il Reviewer ha prompt/output propri
e riceve una copia read-only dell'episodio Reader; il Dynamic Judge è stateless e dispone soltanto del tool read-only sull'evidenza
persistita. Se il budget a score termina durante un task attivo, la produzione
dell'output ha priorita' sul consumo raw.

Ogni singola richiesta LLM ha una deadline wall-clock configurabile, inizialmente 240
secondi, applicata allo stream del relativo model request e distinta sia dal timeout HTTP
di inattivita' sia dalla durata dell'intera sessione, dei tool e dei backoff. Il recovery conserva
messaggi e tool result completati; per il Worker reinietta le transazioni HTTP gia'
persistite e vieta di ripetere automaticamente operazioni mutanti note.

Il Worker riceve fino a 32 richieste nella tranche iniziale da 1.000.000 EP; ogni voto
Dynamic Judge ha quota distinta da 100.000 EP. Un `retry_worker` autorizzato finanzia una
nuova tranche Worker da 500.000 EP e il voto successivo da 100.000 EP, fino a 24 richieste
Worker. Il Judge valuta semanticamente progresso e prossimo esperimento; non esistono
limiti ordinari di requeue o rifiuti per uguaglianza testuale del passo. Quando il Worker
raggiunge un boundary operativo senza verdetto, il Judge valuta
lo snapshot durevole e decide se un nuovo esperimento giustifica una tranche. `ACTIVE_LEAD_COMPLETION_OVERDRAFT_LIMIT`
resta leggibile per compatibilita' storica, ma l'orchestratore non lo usa per elevare
il cap. Anche un quinto candidate staticamente valido avvia Worker se il cap globale
finanzia Worker e Judge; altrimenti resta suspected/unfunded con causa economica.
Telemetria e budget snapshot
espongono gli eventi `lead_stage_unlocked`/`lead_stage_denied`, il numero di pipeline
dinamiche e, per lead, uso, funding e residuo separati di `confirmer_stage`, `worker_stage`
e `judge_stage`. Il costo del Judge è scoped alla pipeline della lead e non può consumare
silenziosamente la tranche Worker successiva.
Lo snapshot autorevole espone inoltre `stage_grants_not_started`, overshoot di stage e
admission failure; la proiezione per categoria include soltanto lead del ledger locale, così
una global non duplica stage ereditati da categorie precedenti.

Ogni round Worker→Judge registra inoltre il delta dei tool per nome e lo raggruppa in HTTP, source e runtime. Queste metriche, insieme alle operazioni precedenti alla prima HTTP e alla frequenza di `request_static_enrichment`, servono a distinguere rescue occasionali da handoff sistematicamente incompleti; non applicano soglie, validator o transizioni del ledger.

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
l'aggiunge alla history Reader originale. Dynamic Judge resta stateless e legge prove
persistite.
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
dall'orchestratore. Il Judge riceve invece lo snapshot durevole dell'episodio Worker e la
porzione scoped del log HTTP quando c'e' una decisione Worker da adjudicare, non per ogni
boundary operativo. La prima lead di una categoria non richiede confronto di novita' perche'
non esiste ancora un antecedente adjudicated.

Per il Reader la finestra tecnica e quella cognitiva sono distinte: soft limit assoluta
40.000 token e hard limit 48.000 token, calibrabili per ruolo e non espresse come percentuale
della context provider. Alla soglia l'Exploration Reviewer produce il checkpoint; il Reader
non riassume mai la propria history degradata. Per Confirmer e Worker la compression dipende
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

Reader, Confirmer e Worker sono modelli operativi budget-unaware: non ricevono score,
costi, richieste residue o percentuali di pressione. Accounting appartiene
all'orchestratore. Il Confirmer decide semanticamente a ogni self-checkpoint se continuare.
L'Exploration Reviewer controlla le epoch del solo Reader; il Dynamic Judge valuta ogni
uscita Worker e soltanto `retry_worker` autorizza la continuazione.

Il cap globale della run resta l'unica safety net economica comune. Il Reader ha un massimo
di 16 richieste per epoch prima della review; Confirmer parte da
12 richieste e Worker da 32 richieste. Le estensioni del Confirmer, fino a 32 richieste, richiedono
`continue` dal self-checkpoint; quelle del Worker, fino a 24 richieste, sono concesse
soltanto da `retry_worker` del Dynamic Judge dopo una uscita Worker. Le richieste dei
supervisori restano rare; soltanto il Judge possiede il tool read-only di evidenza. Gli stage
di lead vengono ammessi interamente o negati, mai con grant simbolici. Dentro uno stage già
ammesso, il grant di richieste investigative conserva prima la quota calcolata per
self-checkpoint, terminalizzazione e retry di output. L'overdraft non viene attivato
dall'orchestratore. Un'ultima richiesta già ammessa può sforare lo stage: consumo e causa restano
visibili separatamente e il retry successivo deve coprire lo sforamento prima di aggiungere
una nuova tranche utilizzabile.

L'ammissione economica scoped segue due transizioni del lifecycle: la creazione della lead
sblocca soltanto `confirmer_stage`; la promozione del `CandidateHandoff` sblocca
`worker_stage` insieme alla quota Judge, se il cap globale lo finanzia. Non esistono
slot dinamici numerici: la pipeline esegue HTTP quando la sua envelope e' ammessa.
`category_remaining_points` e l'admission dei model request sono
stage-sensitive: Confirmer e i suoi checkpoint non vedono il residuo Worker, mentre Worker
e Judge usano riserve distinte e non vedono il residuo Confirmer. L'overdraft condizionale resta disabilitato in
questa modalita'. Un esaurimento economico produce soltanto `reviewer_stopped`,
`judge_stopped` o `unfunded_dynamic`; non costituisce mai un verdetto tecnico.

I boundary Reader passano all'Exploration Reviewer; ogni boundary Confirmer passa al
self-checkpoint dello stesso modello e non al Reviewer. Al boundary di richieste o budget il
Worker entra nella fase tool-free della stessa conversazione e può produrre una proposta o
un `ContinueInvestigation`; l'uscita e lo snapshot durevole passano quindi al Dynamic Judge.
Quando il self-checkpoint sceglie `continue`, o un
supervisore competente ordina continuità, Confirmer e Worker riprendono la stessa history
append-only. Il Reader conserva invece la stessa history nella stessa area, aggiungendo
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

Il profilo operativo predefinito e' 128k. Dopo prompt/schema, riserva output e safety
margin, il Confirmer comprime all'85% della capacità utile e punta al 35%; il Worker usa
75% e 25%. La soglia hard resta al 90%. Sotto la soglia del ruolo la history resta
invariata per favorire cache hit. Confirmer e Worker usano un summarizer LLM con prompt
dedicato, checkpoint strutturato deterministico e coda raw recente. I checkpoint preservano finding, source
reference, route/auth/middleware, evidenze, actor/session, request/response ID,
inspection/query e next experiment; i raw body restano soltanto in memoria durante la run.

L'isolamento del source root e del target URL, i health check, la separazione delle sessioni actor, i controlli sulle transazioni, la paginazione e i limiti di context restano invariati. Questi vincoli proteggono sicurezza e qualità, ma non sostituiscono il budget economico a score.
