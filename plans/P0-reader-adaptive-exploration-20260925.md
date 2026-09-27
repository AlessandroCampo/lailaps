# P0 proposto: tracing e nuovi task durante l'esplorazione Reader

25 settembre 2026. Sintesi corrente e handoff di implementazione per un agente Sol 6.
Decisione dell'utente: il primo test T1 comprende insieme correzione del bug
checkpoint/ledger e metodo di tracing, su pochi assignment della Recon congelata.
Le specifiche T1 e P1 in fondo a questo documento prevalgono sulle precedenti
sequenze A/B/C e sulla matrice cross-model dei piani collegati.
Rivede la priorità del [piano reach](P0-sensitive-code-reach-20260925.md): alleggerire
la pretesa di completezza iniziale della Recon e investire prima nel metodo Reader
e nell'acquisizione durevole delle nuove domande. Conserva il prerequisito
checkpoint/ledger del [P0 originario](P0-reader-methodology-cross-model-20260923.md).

## Tesi

Recon produce una mappa iniziale e buoni punti di ingresso. Il Reader deve poter
modificare la mappa attraverso ciò che scopre. Un assignment orienta e dà priorità;
non esaurisce le domande di sicurezza della componente e non delimita i file
leggibili, sempre entro il perimetro autorizzato del progetto.

La divisione dei ruoli dipende dal prodotto e dalla soglia di evidenza, non da un
numero di livelli del grafo. Recon localizza comportamenti. Reader esplora e inoltra
ipotesi plausibili. Confirmer ricostruisce e valuta la specifica ipotesi, incluse
mitigazioni, precondizioni e piano di verifica. Un Reader può attraversare più file
per trovare un'operazione senza diventare Confirmer; deve invece fermare quella
indagine quando il nesso plausibile è sufficiente e passare agli altri percorsi.

Un'assegnazione iniziale completa a priori non è un prerequisito realistico. Anche
il ciclo dinamico non garantisce completezza: rende esplicite nuove aperture e
lavoro rimasto. Al budget si conserva la coda pendente e si dichiara incompletezza.

## Evidenze aggiuntive verificate

Sui 16 outcome figli MiMo `20260923-193458`:

| Evidenza registrata | Totale |
|---|---:|
| ReaderLead acquisite | 79 |
| Output AreaEnrichmentLead | 0 |
| Exploration enrichment review | 0 |
| Chiamate Reader search_code_graph | 15 |
| Chiamate Reader trace_code_path | 0 |
| Chiamate Reader trace_data_flow | 0 |
| Chiamate Reader get_graph_code_snippet | 0 |
| Chiamate Reader list_surface_signals | 0 |

Gli ultimi cinque tool risultano enabled e available nella telemetria di tutti
i figli. Il backend data-flow risulta configured: non essendo stato interrogato,
questo non dimostra che una query Joern avrebbe avuto successo o prodotto un flusso
utile. Il modello ha usato ricerca e letture manuali, che possono ricostruire flussi;
zero chiamate ai tool di tracing non significa zero analisi di flusso.

Fonte riproducibile: fixture in
`storage/framework/lailaps-reader-global/yeswiki-reader-global-20260923-193458/fixtures/`,
outcome corrispondenti in `storage/app/runs/yeswiki/reader-area/<child>/<child>-outcome.json`,
campi `report.structured_outputs`, `report.telemetry.exploration_enrichment_reviews`,
`report.telemetry.tool_calls_details.reader` e `data_flow_backend_status`.

Questi dati indicano che la disponibilità dei meccanismi non li ha resi parte del
metodo osservato. Non dimostrano che imporne l'uso recuperi otto CVE. La diagnosi dei
miss e i relativi riferimenti restano nel piano reach e nei report che esso cita.

## Metodo di tracing generalizzabile

1. **Individuare un punto concreto.** Un ingresso, un controllo, un'operazione,
   un trasformatore o un accesso a dati. Non serve che appaia già vulnerabile: anche
   un verificatore può contenere effetti interessanti prima della sua decisione.
2. **Indietro:** chi lo chiama, quali valori e attori lo alimentano, quali percorsi
   alternativi esistono? Approfondire il collegamento che può cambiare la comprensione
   del comportamento, evitando di enumerare ricorsivamente tutti i caller.
3. **Avanti:** quali effetti produce, quali servizi/template/parser usa, dove arriva
   il dato e come viene usato il risultato? Per un controllo conta anche se il caller
   ne verifica il risultato e quali effetti precedono il controllo.
4. **Di lato:** rami alternativi o operazioni sorelle trattano gli stessi dati con
   controlli, attori o contesti differenti? Una conclusione resta riferita al ramo
   e alla proprietà osservati, non all'intera classe.
5. **Decidere il prossimo prodotto:** lettura locale, nuova domanda acquisita, lead
   plausibile oppure chiusura motivata del preciso percorso.

Non eseguire meccanicamente tre query per ogni funzione. La domanda aperta determina
la direzione e lo strumento. Un breve percorso sorgente basta quando è esplicito;
grafo e data-flow aiutano a scegliere collegamenti nelle dipendenze più complesse.

| Stato dell'indagine | Azione |
|---|---|
| Manca un collegamento concreto per capire il percorso attivo | Seguirlo subito con una lettura mirata |
| Emerge una domanda indipendente e non è ancora una lead | Acquisire un task con osservazione, locator e prossima verifica; continuare il lavoro attivo |
| Operazione sensibile, possibile influenza e nesso plausibile sono osservati | Emettere ReaderLead con unknowns; Confirmer approfondisce |
| Una barriera osservata risolve la domanda specifica | Registrare la conclusione circoscritta; preservare eventuali rami indipendenti |

Questo applica il principio 'curioso nella ricerca, tempestivo nell'inoltro'. Non
richiede al Reader una ricostruzione completa di exploitability prima di consegnare
una pista. 'Tutti i livelli' significa non fermarsi artificialmente a un wrapper o
al bordo dell'assignment, non espandere tutte le dipendenze transitivamente.

## Come impiegare i tool già presenti

- Semgrep / `list_surface_signals`: locator di operazioni e viste alternative della
  superficie. Non stabiliscono il flusso o la vulnerabilità. `active_area` filtra i
  path iniziali: usare `all` con path/family quando si segue una dipendenza esterna.
- CBM / `search_code_graph` e `trace_code_path`: localizzazione di simboli e tracing
  caller/callee in entrambe le direzioni. Gli archi sono strutturali e possono essere
  incompleti; non sono una dimostrazione di data-flow o raggiungibilità.
- Joern / `trace_data_flow`: l'interfaccia attuale espone uno slice backward
  dall'argomento di una chiamata, non un generico tracing bidirezionale completo.
  Usarlo per una domanda sull'origine del dato; verificare i passaggi decisivi nel
  sorgente. Per il forward usare gli altri strumenti e letture mirate.
- `search_source` / `read_file`: verifica dei blocchi effettivi, template, dispatch,
  configurazioni e collegamenti che il grafo non risolve. Un risultato incompleto
  resta un collegamento da investigare, non evidenza di sicurezza.

Riferimenti nel runtime corrente: `tools/data_flow.py:14`, `tools/graph.py`
(`trace_code_path`), `surface_context.py:676`, `triple_agent.py:3366`.
Prima dell'esperimento verificare offline disponibilità effettiva, query e ritorno
di evidenze di Joern/CBM sullo snapshot. Il metodo deve restare eseguibile anche con
fallback sorgente; i failure del sensore vanno misurati separatamente.

## Una coda che cresce con le osservazioni del Reader

Il ciclo minimo è: Recon -> coda -> Reader -> nuova domanda -> coda. Le lead seguono
Reader -> Confirmer -> Worker. La selezione semantica può riutilizzare il Reviewer;
non richiede un nuovo Recon persistente o un nuovo agente.

Il runtime contiene già `AreaEnrichmentLead`, approvazione del Reviewer, ledger di
aree con origine `reader_enrichment` e accodamento dei figli. Il limite semantico è
che il contratto presenta l'enrichment soprattutto come recupero di una superficie
non coperta dall'Area Ledger. Va ammessa anche una domanda indipendente e non ancora
risolta dentro una componente nominalmente assegnata.

Esempio illustrativo di task: 'Ho osservato un secondo renderer che riceve gli stessi
metadati del form. Il contesto è diverso da quello appena analizzato. Partire dalla
chiamata osservata e verificare come quei metadati entrano negli attributi prodotti'.
Serve un locator reale, non questo esempio generico nel prompt di un audit.

Il contenuto minimo è osservazione concreta, domanda irrisolta e punto di ripartenza.
Sta nei campi narrativi e nei locator dell'enrichment esistente; identità, stato,
provenienza e collegamento all'incarico originario appartengono all'orchestratore.
Una nuova domanda non chiude l'incarico attivo e non diventa un finding nel report.

Un todo scritto soltanto nel `checkpoint_summary` non è un task acquisito. Il
problema sarebbe identico alle lead narrative scambiate per emesse. Il checkpoint
deve poter ordinare l'emissione dell'enrichment al ritorno operativo; il ledger
conferma l'acquisizione. Domande temporanee nello stesso percorso possono restare
memoria locale, ma una delega o un rinvio oltre l'epoch richiedono stato durevole.

Per un nuovo processo trasferire domanda, locator e osservazione utile, non la
history completa o supposizioni elevate a fatti. Gli ID di evidenza locali al figlio
non sono automaticamente risolvibili da un altro figlio: il task deve includere
riferimenti sorgente portabili e prevedere la rilettura del passaggio decisivo.

## Deduplica dei task e delle lead

Sono due giudizi diversi, anche se riutilizzano modello e infrastruttura:

- Task: la stessa domanda sullo stesso comportamento è già in coda, in lavorazione
  o effettivamente risolta? Unire il contesto nuovo o mantenere la domanda distinta.
- Lead: stessa root cause, operazione e condizioni? Collegare varianti e nuove
  evidenze senza perdere sink o confini indipendenti.

Una directory assegnata, un file letto o una lead sulla CSRF di un endpoint non
risolvono automaticamente una domanda sulla query di quel percorso. Una conclusione
negativa non va estesa a caller diversi. Una proposta duplicata non deve cancellare
nuovi locator, condizioni o controevidenze.

Esiste già un Lead Novelty Reviewer. Il fan-out Reader mantiene output locali e
l'aggregazione non equivale a deduplica semantica globale prima del downstream.
Per gli enrichment il coordinatore Laravel deduplica payload normalizzati identici;
questo garantisce idempotenza, non equivalenza semantica fra proposte diverse.

P0: riutilizzare la review esistente per evitare la falsa equivalenza
'componente assegnata = domanda coperta'. Il revisore deve disporre dei task
pertinenti e del loro stato effettivo. Se il fan-out non gli espone questa vista,
non promettere deduplica globale: rimandare il confronto globale al coordinamento
prima del dispatch, mantenendo intatti gli output acquisiti. Evitare subito un
ulteriore agente dedicato o una chiamata di coordinamento dopo ogni lettura.

## Cosa prendere dai concetti di reward

Usare un criterio di utilità per scegliere il prossimo passo: quanto può chiarire
un'operazione sensibile, un confine o una condizione non ancora esplorati, rispetto
al costo della lettura? Dare valore anche a una confutazione precisa, perché evita
ricerca duplicata. Il modello deve motivare la scelta in modo breve e concreto.

Non assegnare punti per file, profondità, tool call, numero di todo o lead. Queste
quantità sono facili da gonfiare e non misurano sicurezza. Scrivere una ricompensa
nel prompt non implementa apprendimento per rinforzo: in P0 si sperimenta una
politica di ricerca e il suo feedback, senza training o funzione reward numerica.

Il segnale esterno per giudicare il metodo resta il guadagno marginale di operazioni
sensibili comprese e piste utili, con duplicati, falsi positivi e costo controllati.
Il benchmark resta fuori dai prompt e la valutazione comprende codice sicuro e
operazioni fuori catalogo, non solo nuovi successi scelti a posteriori.

## Primo test T1: bugfix e tracing insieme, su tre assignment

### Obiettivo del task affidato a Sol 6

Consegnare runtime, test offline, osservabilità e comando necessari a lanciare uno
screening Reader-only che risponda a questa domanda:

> Con checkpoint corretto e metodo backward/forward/rami alternativi, MiMo usa il
> tracing per raggiungere e interpretare operazioni prima trascurate, inoltra le
> piste pronte e propone aperture concrete oltre la domanda iniziale?

T1 è una sola configurazione **A+B**: A indica la correzione del bug di acquisizione;
B il metodo di ricerca rivisto. Non richiede una run preliminare con il solo bugfix,
una nuova Recon o la matrice di tre modelli. Misura il pacchetto combinato; non separa
causalmente il contributo di A da quello di B. Le run storiche restano riferimenti
diagnostici, non controlli equivalenti.

Sol 6 è l'agente implementatore, non il modello Reader del benchmark. Il task termina
con test offline superati e T1 pronto al lancio. L'audit a pagamento viene lanciato
successivamente dall'utente; l'implementatore non deve avviarlo automaticamente.

### Perimetro e configurazione

Riutilizzare Golden Recon `01M309MYBKSARVPA2HWVTYT450` e sorgente
`7325759547611def210a731b103878bd696c419c`. Selezionare i tre incarichi originali sotto,
conservando ID, briefing, locator e next_check. Il filtro seleziona gli incarichi:
il Reader può seguire collegamenti in tutto il sorgente autorizzato.

| Area | Titolo nella fixture | Motivazione offline |
|---|---|---|
| `area-assignment-4` | Controller e API REST | Follow-up controller/servizi, reazioni e regressione sulla SQLi già emessa |
| `area-assignment-5` | Bazar: moduli, voci e campi | Concentrazione di percorsi trascurati: filtri, input, widget e federazione |
| `area-assignment-6` | Rendering e template (XSS surface) | Risoluzione dei template effettivi, rami vicini e soglia di inoltro |

Le motivazioni e i CVE attesi restano nell'evaluation: non aggiungerli ai prompt o
alle fixture. In particolare non nominare ActivityPub o locator CVE per guidare il
Reader. Il perimetro funzionale di queste aree comprende naturalmente sei degli
otto miss di mancata lettura. RecentChanges e cancellazione spam restano verifiche
secondarie, se raggiunte spontaneamente, oppure per un successivo allargamento.
T1 non misura il recall globale dell'intero progetto.

- Reader: `xiaomi/mimo-v2.6-pro`, esplicito per tutti i figli.
- Reviewer/novelty: `z-ai/glm-5.3-flash`, come negli outcome MiMo di riferimento.
- Strategia: `reader_checkpoint`; bugfix A e metodo B nello stesso build.
- Una repetition, tre figli iniziali, concorrenza 3, timeout 7.200 secondi per figlio.
- Envelope nominale 500.000 EP per assignment, Reader più Reviewer: 1.500.000 EP
  nominali. Registrare spesa effettiva e overshoot delle tranche finali; questa
  somma non introduce una garanzia di hard cap aggregato.
- ID, ledger, checkpoint e memoria nuovi; niente stato semantico dei Reader storici.
  Confirmer, Worker e Judge non vengono avviati.
- Registrare provider effettivo, modelli, reasoning configurato, runtime/prompt,
  tokenizer/usage, pesi EP e stato dei tool. Non sostituire silenziosamente un
  modello indisponibile: conservare il failure.

Riferimento storico mirato:

| Child MiMo del 23 settembre | Area | Lead grezze | EP registrati |
|---|---:|---:|---:|
| `193537-4` | 4 | 3 | 531.398 |
| `193924` | 5 | 4 | 547.490,68 |
| `200351` | 6 | 7 | 493.854,06 |
| Totale | | 14 | 1.572.742,74 |

Nei tre figli: zero trace_data_flow/trace_code_path, zero enrichment, un match CVE
semantico (52771), tutte terminazioni a budget. Nell'area 6 il costo monetario
registrato è incompleto: non ricavarne confronti in dollari. Concorrenza e runtime
storici non sono un controllo equivalente per confronti di velocità.

### Scope implementativo minimo per T1

1. Correggere checkpoint/turno operativo: il summary non acquisisce output;
   `next_step` può richiedere l'emissione di ReaderLead o AreaEnrichmentLead.
   Il ledger rimane autorevole. Preservare percorso e residui dopo l'emissione,
   senza trasformare supposizioni in controlli verificati.
2. Applicare il metodo di tracing sopra: seguire collegamenti oltre anchor/locator
   quando una domanda concreta lo richiede; emettere alla plausibilità; conservare
   le aperture indipendenti. Non introdurre quote di uso dei tool.
3. Conservare la capacità esistente di proporre enrichment e renderne chiaro l'uso.
   Misurarne proposte ed esiti. L'estensione C della coda e della deduplica dei task
   resta un passo successivo; non serve un nuovo orchestratore per T1.
4. Rendere selettivo il runner esistente e predisporre raccolta delle metriche.
   Riutilizzare telemetria, structured_outputs e transcript; aggiungere solo dati
   non ricostruibili. Nessun nuovo evaluator LLM a pagamento o DTO complesso.

Per T1 **acquisire e valutare gli enrichment, rinviandone l'esecuzione**. Questo
mantiene lo screening a tre assignment e rende leggibile il costo A+B. Conservare
le proposte approvate come pendenti/rinviate nel parent, con provenienza e locator;
non scartarle o dichiararne esplorata la superficie. Conservare anche le proposte
respinte e i motivi. L'esecuzione dei nuovi task e il loro contributo saranno C.

Il runner `benchmark:reader-global` non ha oggi filtro aree e rinvio enrichment.
Implementare due opzioni locali, senza creare un altro runner:

- `--area=<area_id>`, ripetibile: seleziona gli incarichi iniziali dalla Golden Recon,
  preservando ID e ordine originale. ID inesistenti o selezione vuota falliscono
  prima delle chiamate provider; mai ricadere automaticamente sulle 16 aree.
- `--defer-enrichments`: persiste proposte ed esiti senza avviare figli derivati.
  Il parent distingue fine dello screening, stato delle aree e copertura residua:
  non certificare completezza globale o dei task rinviati. Senza questa opzione
  rimane l'attuale accodamento degli enrichment.

### Metriche obbligatorie nella valutazione dell'output

Produrre una tabella per area e una aggregata, con log reference per nuovi percorsi
e miss persistenti. I conteggi sono diagnostici, non reward del Reader.

**Copertura e lead.** Mantenere lead acquisite, match CVE semantici, anchor/file
raggiunti, ipotesi diversa sullo stesso anchor, blocco operativo nel trace,
valutazione osservabile e mancato inoltro. Distinguere output grezzi, duplicati,
sink plausibili e tesi contraddette, con esempi fuori benchmark. Conservare i
diagnostici globali come contesto, dichiarando il perimetro limitato di T1.

**Tracing.** Contare le chiamate effettive Reader separatamente:

- `trace_data_flow`: Joern backward.
- `trace_code_path`: distinguere `inbound`, `outbound` e `both` dagli argomenti
  registrati; una chiamata `both` conta una volta nel totale.
- `search_code_graph`, `get_graph_code_snippet`, `list_surface_signals` e letture
  sorgente di supporto: esporle senza chiamarle tutte 'traceback'.

Accanto alle chiamate riportare enabled/available, risultati utili, vuoti,
unavailable/error e truncated; cache hit e query ripetute quando disponibili.
Un dato non ricostruibile è non disponibile, non zero. Telemetria per i contatori,
transcript/eventi per direzione e risultato; evitare di ricontare la stessa
chiamata stampata in più log. Collegare i tracing decisivi ai blocchi raggiunti
o alle decisioni. Letture manuali che ricostruiscono un flusso restano evidenza utile.

**Enrichment.** Contare proposte strutturate persistite, approvate/acquisite,
respinte con motivo e senza decisione; proposte accorpate/deduplicate e task distinti
conservati. Separare conteggi per output da conteggi per task, con area d'origine.
Le sole menzioni nel checkpoint non sono proposte acquisite. Mostrare eseguiti e
pendenti/rinviati: gli eseguiti sono zero in T1 per configurazione. Distinguere
deduplica semantica dalla sola identità del payload. Zero proposte resta un
risultato da spiegare, senza imporre una quota artificiale.

**Costo e stato.** EP effettivi Reader/Reviewer e aggregato, richieste, token,
durata, overshoot, budget e failure tecnici. Lo scoring di produttività economica
e l'esito Confirmer sono P1; T1 conserva i dati necessari.

### Esito atteso dello screening

Il report deve permettere di rispondere a quattro domande:

1. Le piste pronte diventano output acquisiti senza perdersi nei checkpoint?
2. Il tracing pertinente consegna contesto decisivo prima assente?
3. Il Reader segue percorsi oltre la domanda iniziale e inoltra correttamente le
   piste, preservando i risultati già sostenuti?
4. Le aperture diventano proposte conservate con domanda e locator utilizzabili?

Per ogni nuovo recupero o miss riportare percorso osservato e punto di arresto.
Più chiamate o enrichment non bastano se non portano copertura utile. Nessun numero
minimo di CVE o tool call è promesso o imposto. Una sola prova identifica meccanismi
e regressioni; repliche e confronti separati restano necessari per attribuire
causalmente il guadagno. Un failure tecnico non è un rifiuto semantico.

### Definition of done per l'agente implementatore

- A+B, filtro aree, persistenza degli enrichment rinviati e raccolta metriche pronti.
  Nessuna modifica a manifest/anchor per gonfiare lo score.
- Test offline mirati su checkpoint -> emissione -> acquisizione, continuazione
  dell'incarico, filtro e rinvio enrichment, riutilizzando test e processi/modelli
  simulati. Nessun agent loop con chiamate provider.
- Contatori verificati su artifact/fixture locali: proposto diverso da approvato,
  `both` non duplicato, failure diverso da zero, aggregati coerenti coi figli.
- Preflight locale Joern/CBM sullo snapshot: query e sorgente restituita verificabili,
  errori/incompletezza espliciti. Dichiarare impedimenti residui senza fingere T1 pronto.
- Raccolta automatica dei conteggi dopo T1 e modello di report semantico con log
  reference predisposti. Usare gli artifact storici per verificare l'estrazione;
  i risultati del nuovo audit saranno compilati soltanto dopo il lancio.
- Versione runtime/prompt e configurazione registrate nel parent. Aggiornare
  ARCHITECTURE.md se cambia struttura/lifecycle, rispettare AGENTS.md e modifiche
  preesistenti. Niente lint globale o nuovo framework.
- Consegnare comando verificato, test offline, directory prevista degli output e
  procedura di valutazione. L'obiettivo empirico resta da verificare dopo T1.

### Comando da consegnare al termine del task

`--area` e `--defer-enrichments` sono **da implementare**: il comando seguente è il
contratto di lancio atteso, non è ancora eseguibile nel checkout ispezionato.
Verificarlo con help e test offline e consegnarne la versione finale, dalla root Lailaps:

```powershell
php artisan benchmark:reader-global yeswiki --recon-artifact=01M309MYBKSARVPA2HWVTYT450 --area=area-assignment-4 --area=area-assignment-5 --area=area-assignment-6 --reader-model=xiaomi/mimo-v2.6-pro --reviewer-model=z-ai/glm-5.3-flash --reader-checkpoint-strategy=reader_checkpoint --assignment-points=500000 --concurrency=3 --timeout=7200 --defer-enrichments --follow-slot=1 --tool-output
```

A+B è determinato dal build/prompt consegnato: non serve una nuova matrice di preset.
L'abilitazione effettiva Joern/CBM deve essere verificata e resa riproducibile nella
configurazione di lancio; la disponibilità storica non prova quella del nuovo processo.

## Dopo T1: passo C e P1

### C — eseguire le aperture e valutare il ciclo dinamico

Resta valida la direzione del piano: ampliare l'enrichment a domande indipendenti
nella componente corrente, acquisire e consumare realmente i task, confrontare i
duplicati e misurarne il contributo. T1 prepara questa fase senza pretendere di
dimostrare efficacia dei task che rinvia.

Prima di C fissare un budget complessivo comprensivo dei figli derivati: non
aggiungere implicitamente 500k EP per ogni nuovo task. Il limite di 48 aree non
sostituisce il limite economico. La coda attuale raccoglie enrichment a fine figlio
e li accoda dopo i task iniziali; misurarne l'effetto prima di un nuovo scheduler.

### P1 — benchmark di produttività economica (proficiency)

Introdurre lead **uniche** per EP spesi, separatamente da conteggi grezzi e recall CVE.
Deduplicare semanticamente su root cause, operazione e condizioni; titoli e anchor
uguali non bastano. Conservare il mapping fra cluster unico e lead d'origine;
le varianti indipendenti restano distinguibili.

`U_R` = ipotesi Reader uniche acquisite.
`E_D` = EP effettivi discovery, inclusi Reader, checkpoint, Reviewer/novelty e
coordinamento della fase. Esplicitare il trattamento della Recon: zero costo
incrementale quando si riusa la Golden Recon, costo effettivo per la pipeline
completa. Non confrontare questi regimi senza dichiararlo.

`proficiency_reader = 100000 * U_R / E_D`

L'unità è lead uniche per 100k EP. Separare benchmark e fuori benchmark e affiancare
qualità statica, duplicati e tesi contraddette: unicità non significa validità.
Usare punti spesi, non cap assegnato; registrare schema/pesi EP, cap, snapshot e
modelli. Se spesa manca o denominatore è zero, metrica non disponibile.
Questo score non diventa un reward operativo del prompt.

Successivamente in P1, dopo deduplica e passaggio a Confirmer, aggiungere:

- `U_valutate`: lead uniche con esito statico semanticamente adjudicato.
- `U_conservate`: fra queste, lead mantenute come candidate o plausibili attraverso
  un giudizio statico esplicito, anche senza conferma dinamica.
- `retention_confirmer = U_conservate / U_valutate`, mostrando conteggi e campione.
- Eventuale produttività cumulativa: `100000 * U_conservate / (E_D + E_C)`,
  dove `E_C` include Confirmer e review/deduplica della fase senza doppio conteggio.

Una lead non ancora scartata non è automaticamente conservata: queued, unfunded,
timeout, budget ed errori tecnici restano non valutati. Separare confutazione
statica e impossibilità di conferma HTTP nel fixture. Mappare gli stati effettivi
del runtime con evidenza, senza inventare un verdetto Confirmer per il benchmark.
Per campioni parziali dichiarare copertura e selezione; non estrapolare al totale.

Queste metriche sono interamente P1: T1 conserva dati grezzi e non avvia Confirmer
o un nuovo sistema di scoring/deduplica globale.

### Altri P1 — coordinamento adattivo dove serve

- Migliorare selezione/priorità solo se buoni task restano in coda, usando valore,
  costo e lavoro pendente senza soglie arbitrarie di stagnazione.
- Se si duplicano aperture fra processi, introdurre confronto semantico globale
  prima del dispatch, riutilizzando review e stato autorevole.
- Se persistono componenti mai raggiunte, richiamare Recon per riconciliare mappa
  e copertura: l'esplorazione per collegamenti non copre ogni componente disconnessa.
- Valutare il ritorno di aperture indipendenti dal Confirmer nella coda: il prompt
  corrente non lo permette, quindi è un'estensione da provare.
- Ripetere le configurazioni promettenti e riprendere i confronti fra modelli,
  reasoning e budget dopo il primo screening.

## Idee da evitare nel primo esperimento

Una Recon che deve completare a priori il grafo; un Reader premiato per continuare
sempre; un tracing esaustivo di ogni dipendenza; un Recon richiamato a ogni tool
call; un agente nuovo per ciascun tipo di deduplica; todo lasciati solo in prosa;
budget aggiunto implicitamente a ogni task. Queste varianti aumentano costo o
ambiguità prima di verificare la parte più promettente dell'idea.

Nessuna modifica al runtime, nuovo audit o test a pagamento avviato per questa sintesi.
