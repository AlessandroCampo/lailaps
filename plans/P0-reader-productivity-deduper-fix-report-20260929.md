# Reader: produttività osservata e fix di deduper/evaluator

29 settembre 2026. Fotografia congelata alle **11:32:57 Europe/Rome**.
Analisi read-only delle run, del codice e simulazioni senza provider. Nessuna run
interrotta, nessun fix runtime applicato e nessuna chiamata inferenziale avviata.

## Conclusione

Reader produce un volume significativo di ipotesi a costo misurabile. Questo è un
risultato di produzione grezza, non ancora una dimostrazione di novelty o qualità.
La pipeline semantica è attualmente inaffidabile per problemi tecnici e di harness:
non ci sono elementi per attribuire questi fallimenti all'incapacità semantica di GLM.
Non basta cambiare provider o aumentare il timeout: il cap input è già un blocco locale
riproducibile, e il pipeline continua a spendere anche quando deduplica/giudizi falliscono.

Non è possibile garantire disponibilità di un provider o correttezza semantica assoluta.
Possiamo rendere verificabili completezza, costi, ripresa e gestione dei fallimenti,
e validare poi la qualità su decisioni revisionate.

## 1. Numeri esatti e loro significato

| Serie | Lead grezze accettate | EP Reader osservati | Lead / 100k EP | EP / lead |
|---|---:|---:|---:|---:|
| Cacti, aggiornamento precedente 11:17 | 93 | 2.904.229,3712 | 3,202226 | 31.228,273 |
| Cacti, snapshot congelato 11:32:57 | 103 | 3.231.940,0480 | 3,186940 | 31.378,059 |
| YesWiki, discovery finale | 57 | 1.514.943,4320 | 3,762517 | 26.577,955 |
| YesWiki, MiMo storico recuperato | 34 | 404.956,9408 | 8,395954 | 11.910,498 |

Formula: `100000 * ReaderLead accettate distinte per run_id:output_id / EP osservati`.
Esclude enrichment, Recon, deduper ed evaluator. I denominatori Reader comprendono
il lavoro e i checkpoint contabilizzati dai child; i campi float possono avere residui
di rappresentazione oltre le cifre riportate. EP non è sinonimo di token né di USD.

Per Cacti sono inclusi tutti i child mappati dal parent, anche quelli ancora attivi,
ciascuno letto da una sola versione del suo outcome. Non è un'istantanea atomica di
tutti i processi e non include consumo in-flight non ancora pubblicato. La fotografia
11:17 è ricostruita dai numeri acquisiti nel precedente aggiornamento; quella 11:32:57
ha copie degli input e hash conservati ed è il riferimento riproducibile del report.

Il rapporto Cacti resta vicino a 3,2/100k mentre aumentano lead e consumo. È un segnale
utile di produttività. Non sappiamo ancora quante ipotesi siano nuove, supportate,
sovrapposte o frammenti della stessa causa. La novelty richiede almeno deduplica e
controllo semantico, e non richiede di introdurre adesso Confirmer.

Il dato YesWiki storico impedisce di concludere un miglioramento di efficienza dal solo
numero di lead: il rapporto grezzo precedente era maggiore. Le nuove 57 lead possono
aggiungere copertura importante, ma servono curva di produzione a pari budget e hit
semantici per giudicarlo. Non confrontare direttamente Cacti e YesWiki come classifica
di difficoltà o qualità; sono superfici e scope diversi.

### Dove è persistito

- Ogni child: `report.structured_outputs` e `report.telemetry.economic_points_used`.
- Parent finale: `benchmark.accepted_leads`, `benchmark.accepted_leads_per_100k_points`,
  `report.telemetry.economic_points_used`. YesWiki contiene già **3.762516724782896**.
- Il parent Cacti ancora parziale aggrega prevalentemente child terminati: il suo
  subtotale non rappresenta il consumo di tutti i Reader attivi.
- Export di questa analisi: `storage/app/reader-evaluation/diagnostic-20260929-113257/summary.json`.
  La sottodirectory `inputs/` conserva le copie lette, con hash dei child nel summary.
- Script riproducibile, senza provider: `plans/testing-reader-20260929/diagnose-round.py`.

La metrica `unique_per_100k_discovery_ep` del postprocessor è differente: usa decisioni
semantiche del deduper e non va presentata come novelty verificata finché tali decisioni
non sono affidabili. Nei report distinguere raw / canoniche provvisorie / uniche adjudicate /
uniche revisionate / utili o hit revisionati, senza aggiungere sinonimi sovrapposti.

## 2. Errori osservati

### YesWiki deduper: 58 prodotti = 57 lead + 1 enrichment

| Esito/causa | Numero |
|---|---:|
| Adjudicated | 4 |
| Inconclusive: cap input locale | **29** |
| Inconclusive: timeout | **9** |
| Inconclusive: Connection error | **8** |
| Inconclusive: output validation/retries esauriti | **5** |
| Inconclusive: cap output 4096 prima della risposta | **2** |
| Inconclusive: risposta provider `finish_reason=error` non valida per SDK | **1** |

I 54 inconclusivi sono errori di esecuzione, contratto o capacità del pacchetto:
non sono 54 valutazioni semantiche dubbie. Dei quattro adjudicated, due sono pass
automatici senza precedenti (primo prodotto di ciascun gruppo lead/task); solo due
sono decisioni pass ottenute dal modello. Non è stato emesso alcun block o partial.
28 richieste, circa 25,3 minuti nel runner, **usage incompleta**.

### YesWiki evaluator, fase current terminata

57 giudizi, tutti inconclusivi: 38 timeout, 15 Connection error, 3 cap output,
1 richiesta finestra sorgente fuori limite. 58 richieste, circa 63,4 minuti nel runner.
Il report viene comunque scritto e il launcher può passare alla baseline storica:
la fine del processo non equivale a successo della valutazione.

Nei log del container evaluator sono stati osservati ripetuti **HTTP 429** da OpenRouter.
Non possiamo attribuire ogni Connection error a un 429 né identificare dai soli dati
persistiti se il rate limit sia account, modello o upstream. Il ledger attuale perde
questo dettaglio. Non ci sono prove sufficienti per concludere un guasto di rete locale.

### Cacti deduper, checkpoint fotografato

23 decisioni su task/enrichment: 10 adjudicated, 13 inconclusive
(9 timeout, 3 Connection error, 1 errore di output). Tutte conservate come pass.
Non è ancora la deduplica finale delle lead. Le proposte inconclusive possono essere
accodate: il costo di questo guasto include lavoro Reader potenzialmente ridondante.

## 3. Fix P0, nell'ordine di implementazione

### A. Fermare le fasi degradate e separare gli stati

**Problema verificato:** `reader_evaluation.evaluate()` valuta tutte le canoniche anche
quando la deduplica è quasi interamente inconclusiva. Il CLI termina con successo se
riesce a scrivere i file; il launcher interpreta exit 0 come permesso di avviare anche
il replay storico. La semantica di dipendenza richiesta dal piano non è rispettata.

**Fix:** distinguere completed, pending_evidence, failed_technical e budget_exhausted.
Preservare sempre prodotti originali e metriche; non avviare una valutazione aggregata
come valida se la deduplica necessaria non è completa. Eventuali giudizi parziali siano
esplicitamente diagnostici. Restituire un esito di fase machine-readable e farlo leggere
al launcher prima della fase successiva. Un report prodotto non è un gate di successo.

Dopo una breve sequenza di fallimenti tecnici consecutivi dello stesso ruolo/provider
(inizialmente tre), interrompere nuove chiamate della fase e mantenere il resto pending.
Non perdere lead e non trasformare l'outage in una serie di pass semantici.
Per il percorso online Cacti, lasciare terminare gli assignment già attivi; le nuove
espansioni non adjudicate vanno mantenute pending, con limite esplicito alla crescita
della coda. Non eliminare automaticamente task già dispatchati.

**Accettazione:** test simulato con tre timeout/429: stop della fase, nessuna chiamata
per i restanti prodotti né al judge/storico; artifact e ripresa integri. Nessun Confirmer.

### B. Rendere sostenibile il pacchetto del deduper

**Problema verificato:** indice completo contiene titolo, ipotesi estesa, operazione e
path per ogni precedente; cresce linearmente per richiesta, quindi il costo cumulativo
può crescere quadraticamente. La rimozione degli originali quando si supera il cap non
basta: l'indice stesso supera 24k. Le proposte salvate come pass inconclusivi allargano
ulteriormente il registro. Il fix preparatorio reggeva i 34 output MiMo storici, non i 57
nuovi: la validazione iniziale non era sufficiente per questo volume.

Simulazione offline sul corpus congelato, risposta artificiale sempre pass:

| Corpus | Prodotti | Inconclusive a 24k | a 48k | a 64k |
|---|---:|---:|---:|---:|
| YesWiki nuovo | 58 | 29 | 0 | 0 |
| Cacti parziale | 112 | 71 | 32 | 6 |

Questa verifica misura solo cap e packaging. Non misura qualità o costi reali del modello;
il corpus Cacti simulato comprende lead/enrichment disponibili, non tutti gli assignment
Recon del runtime. Non usare 48k o 64k come soluzione generale dimostrata.

**Fix minimo corretto:** preparare un indice privo delle duplicazioni narrative evitabili,
caricare gli originali pertinenti su richiesta, e fare preflight sull'intero corpus prima
di avviare una fase paid. Non troncare arbitrariamente ipotesi/condizioni certificando
comunque una comparazione completa. Un semplice top-K lessicale non basta per un pass
globale, proprio perché gli anchor non determinano l'equivalenza.

Quando un indice semanticamente sufficiente non entra, il confronto deve supportare
partizioni complete del registro con budget totale e limite di chiamate espliciti.
Tutte le partizioni necessarie vanno coperte prima di certificare un pass globale;
block/partial richiedono gli originali pertinenti, senza deduplica transitiva automatica.
Budget esaurito prima del completamento => pending, non unicità certificata.
Questa estensione modifica il limite attuale di due richieste: va versionata e misurata,
mai introdotta come retry nascosto. Non fissare un numero alto di chiamate per ogni lead:
il percorso normale resta indice piccolo + eventuale recupero; paginare solo all'occorrenza.

Prima di adottarla misurare l'overhead a cache zero. Se il confronto globale bounded non
sta nell'envelope economico, segnalarlo: non possiamo promettere simultaneamente confronto
esaustivo, numero costante di chiamate e costo costante al crescere illimitato del corpus.
Retrieval semantico più sofisticato resta P1; non introdurre ora un nuovo servizio vector DB.

**Accettazione:** i corpus congelati completi attraversano il preflight; a fine replay ogni
confronto è concluso oppure pending esplicito. Nessun `input_cap` silenzioso trasformato
in risultato valido. Verificare evidenze nuove, stesso anchor con ipotesi diversa e overlap
parziale tra partizioni. Misurare costo/lead e p95 latenza, non solo percentuale di block.

### C. Transport, timeout e rate limit

**Problema verificato:** il runner applica timeout esterno 90s, mentre il client HTTP ha
default 120s. SDK retry e output retry sono zero. La cancellazione esterna può avvenire
prima del timeout HTTP e perdere diagnostica/usage. Non c'è cooldown di fase sui 429.

**Fix:** una sola policy di retry posseduta dal runner. Classificare HTTP 429/5xx, timeout,
errore di rete, autenticazione/config e contratto di output; usare `Retry-After` dove
disponibile e backoff limitato con jitter, senza moltiplicare tentativi SDK/trasporto/ruolo.
Retry consuma lo stesso envelope e un contatore esplicito; inizialmente un solo tentativo
aggiuntivo per errore transiente. Non ritentare autenticazione o input incompatibile.
Allineare timeout HTTP e deadline del ruolo; misurare prima latenza delle risposte valide,
non alzare indiscriminatamente 90s a diversi minuti per ogni prodotto.

Coordinare gli slot delle chiamate di deduper/evaluator che usano lo stesso provider;
partire con concorrenza uno per questa fase di recupero. Non cambiare automaticamente
modello/provider: conservare GLM e pin attuale per la diagnosi; se quel serving resta
indisponibile, renderlo un blocco operativo esplicito.

**Accettazione:** mock 429 con Retry-After, 503 transiente, connect/read timeout e 401;
numero massimo di tentativi e tempo totale verificati, nessun retry infinito o doppio.

### D. Output strutturato e reasoning

**Problema verificato:** risposte esauriscono 4096 token prima di produrre output;
altri fallimenti sono genericamente riportati come output retries esauriti, e uno
contiene `finish_reason=error`. Il runner non imposta una policy di reasoning specifica
del ruolo. Non abbiamo ancora il dettaglio di validazione per tutti i cinque errori.

**Fix:** persistere finish_reason, modello/provider effettivo, dettaglio Pydantic bounded,
usage e dimensioni della risposta, senza scaricare indiscriminatamente prompt o segreti.
Separare conceptualmente cap reasoning e risposta finale quando il provider lo supporta,
e configurare esplicitamente uno sforzo adatto a una decisione breve. Determinare un cap
totale con headroom dai dati di smoke, senza assumere che JSON corto significhi costo corto.
Pass/block devono restare minimali; partial resta l'unico prodotto riscritto ex novo.
Consentire al più una riparazione mirata del contratto entro il budget e la deadline,
senza “riparare” deterministicamente il significato di un block/partial.

`finish_reason=error` è una risposta provider/protocollo da diagnosticare, non un verdetto
da recuperare a forza. Non aggiornare tutte le dipendenze o cambiare schema per congettura.

**Accettazione:** fixture di output troncato, validazione errata, risposta nulla, finish_reason
non standard; nessuna promozione a decisione valida. Smoke reale pass/block/partial solo
dopo i fix offline e su pochi casi revisionabili.

### E. Ripresa selettiva degli inconclusivi

**Problema verificato:** deduper restituisce la decisione già acquisita anche se fallita;
`refresh=True` incontra comunque lo stesso input/context key se nulla cambia. Evaluator
restituisce qualsiasi giudizio già presente per key, inclusi timeout. Un rilancio identico
può quindi riusare il fallimento invece di riprovare. Directory nuove aggirano il problema
ma possono ripagare tutto e perdere la continuità dell'envelope.

**Fix:** cache dei successi separata dai tentativi falliti; comando esplicito resume-failed
con storico tentativi append-only, stesso snapshot e budget residuo persistito. Non mutare
originali, non redispatchare assignment già eseguiti. Se una decisione riparata cambia il
registro canonico, invalidare soltanto decisioni successive il cui contesto cambia e i
giudizi dipendenti dai prodotti sostituiti; riprenderle in ordine, non riusare hash obsoleti.

**Accettazione:** timeout -> resume -> successo; successo -> resume -> zero chiamate;
merge riparato -> ricalcolo dei dipendenti, lineage e budget coerenti, nessun doppio task.

### F. Contabilità e diagnostica dei fallimenti

**Problema verificato:** usage_complete è false nei ledger YesWiki. `wait_for` cancella
la coroutine e `_provider_request` intercetta Exception, non conserva necessariamente
usage al momento della cancellazione. Dunque i costi osservati non sono costi totali.
Reasoning token aggregati eccedono output token riportati: serve riconciliazione con
la usage normalizzata, senza sommarli nuovamente alla cieca.

**Fix:** conservare usage/diagnostica in un oggetto di tentativo posseduto dal runner,
finalizzare anche su cancellazione e chiudere il client HTTP in modo deterministico.
Riutilizzare `provider_diagnostics.model_error_diagnostic`, aggiungendo attempt id,
decision id, provider richiesto/osservato, HTTP status, timeout phase e request id quando
disponibili. `TimeoutError` con reason vuota non è un report utilizzabile.
Separare costo noto, stima e quota potenzialmente fatturata ma sconosciuta. Se usage manca,
mantenere una riserva prudenziale nel budget fino alla riconciliazione: non trattare
ogni timeout come costo zero. Mostrare tempi chiamata, attesa/retry e percentuale errori.

**Accettazione:** errori con usage parziale, timeout senza usage e successi cached; somme
riconciliate una volta, cap non aggirabile da sequenze di timeout, completeness accurata.

### G. Accesso al sorgente nell'evaluator

**Problemi verificati:** un giudizio fallisce per finestra oltre il cap. Inoltre i checkout
preparati sono Git-clean, ma hanno CRLF. L'evaluator compara bytes del worktree con
`git show`: `includes/controllers/ApiController.php` YesWiki e `auth_login.php` Cacti
differiscono solo per CRLF. Il successivo accesso valido al sorgente verrebbe rifiutato
come “Source changed after snapshot verification”. Non è la causa dei 38 timeout, ma
è un difetto indipendente riprodotto offline che il preflight iniziale non aveva coperto.

**Fix:** leggere il blob del commit verificato tramite Git, mantenendo root, commit e path
vincolati, e hash del blob effettivamente fornito al judge. Non indebolire la verifica con
una normalizzazione generica che potrebbe mascherare vere modifiche. In alternativa,
materializzare snapshot byte-identici in nuove directory, senza toccare quelli in uso.
Esporre esplicitamente la finestra massima (200 righe inclusive) e restituire un errore
correggibile entro il budget, senza estendere silenziosamente il contesto. Precalcolare
l'insieme dei path autorizzati da manifest e prodotti/evidenze, indipendente dall'ordine
dei prodotti; non concedere lettura libera del repository.

**Accettazione:** test vero accesso sorgente Windows CRLF / blob LF, path autorizzato,
file modificato, traversal, intervallo invertito o troppo lungo; provenance Reader e
provenance judge rimangono distinte. Il giudizio non può inventare una scoperta del Reader.

### H. Metriche e credito semantico

**Problema:** chiamare tutte le canoniche “uniche” quando il deduper è degradato è fuorviante.
Inoltre il giudizio suggerisce source evidence IDs nel pacchetto, ma attribuisce hit solo
se viene citato anche un originale della lead: una citazione di solo snippet potrebbe
perdere credito senza spiegazione. Nessun caso osservato oggi dimostra questo secondo
errore, perché tutti i giudizi hanno fallito prima; il contratto va però testato.

**Fix:** riportare raw productivity indipendente dalla fase semantica; contare uniche/hit
solo con stato e denominatore di completezza espliciti. Collegare le citazioni alla lead
Reader con ID/provenance dell'orchestratore, e distinguere motivo del mancato credito.
Nel parent live sommare child attivi da snapshot, evitando il solo subtotale dei chiusi.
Separare costo Reader da costo dell'intero round (Recon + deduper + evaluator + recuperi).
Le percentuali economiche con usage incompleta restano sconosciute o lower bound dichiarati.

**Accettazione:** somme child/parent/export coerenti, 57 lead != 58 prodotti, pending non
conta come unique; hit basato su ipotesi Reader tracciata, lettura solo judge non dà credito.

## 4. Piano di recupero delle run esistenti

1. Conservare gli output Reader e la fotografia diagnostica; non rilanciare discovery
   per riparare deduplica o valutazione. Le nuove etichette non correggono retroattivamente
   il lavoro duplicato già eseguito da Cacti: riportarlo come costo realmente sostenuto.
2. Implementare A–G con test offline; H per rendere leggibile il risultato. Non sostituire
   l'immagine sotto una run attiva aspettandosi che i container già avviati cambino.
3. Eseguire un unico smoke paid minimo dello stesso GLM/provider: casi pass/block/partial,
   un recupero artifact e un accesso sorgente; costi e latenza registrati. Se non passa,
   fermarsi, senza una nuova matrice di modelli/provider.
4. Replay deduper sul corpus congelato, con tentativi falliti recuperabili e budget unico.
   Revisionare block/partial, inconclusivi e campione pass; mantenere la ground truth
   provvisoria finché non corretta. Nessuna etichettatura preventiva di tutto il corpus.
5. Solo dopo il gate di deduplica, evaluator su prodotti canonici. Usare la stessa
   configurazione per storico YesWiki e nuovo; riportare pending invece di precision/recall
   inventate quando la valutazione non è completa.
6. Confrontare produzione a pari EP, lead uniche utili/100k EP, hit/100k EP e costo aggiuntivo
   dei ruoli. L'obiettivo di overhead circa 5% deve essere misurato includendo fallimenti,
   latenza ed espansioni ridondanti; non è garantito dall'envelope.

## 5. Cosa rimane P1

- Confronti modello/provider, repliche per varianza, Gitea e Confirmer.
- Retrieval semantico indicizzato se il confronto bounded si rivela troppo costoso.
- UI di revisione e report comparativo interattivo.
- Policy automatica di tuning dei cap; nel P0 restano espliciti e versionati.

## Riferimenti implementativi

- `agent/pentest-agent/src/pentest_agent/deduper.py`: RoleRunner, caps, acquire/cache.
- `agent/pentest-agent/src/pentest_agent/reader_evaluation.py`: gating, source, resume, scorecard.
- `agent/pentest-agent/src/pentest_agent/def_model.py` e `provider_diagnostics.py`: transport.
- `app/Console/Commands/BenchmarkReaderGlobal.php`: dispatch dinamico e aggregati.
- `app/Console/Commands/BenchmarkReaderEvaluate.php`: configurazione e stato fase.
- `plans/testing-reader-20260929/Start-ReaderRound.ps1`: sequenza, stop/resume e gate storico.

I fix sono proposti, non già implementati. La diagnosi conserva distinta l'evidenza
osservata dalle verifiche ancora necessarie sul serving reale.
