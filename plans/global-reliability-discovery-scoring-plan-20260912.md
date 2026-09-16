# Piano incrementale: affidabilità, discovery e scoring global

## Decisione proposta

Conservare il checkpoint Confirmer e la sua politica di estensione, che hanno evidenza favorevole nei benchmark condizionali. Non cambiare provider, reasoning Confirmer o criteri di handoff sulla base di questi soli incidenti. Intervenire prima sugli errori tecnici, poi sulla distribuzione della discovery e sullo scoring esclusivamente globale.

**Responsabilità semantica:** continuare, cambiare area, chiuderla o sospendere una categoria sono decisioni del Reviewer competente. Tempo, token e ripetizioni possono provocare una review o un boundary tecnico, ma non determinano automaticamente il verdetto semantico. L'orchestratore identifica gli oggetti, ricostruisce provenance, conserva checkpoint e applica la transizione; non ribalta una decisione valida per euristiche di produttività. In particolare, i difetti osservati non giustificano una nuova politica deterministica di chiusura.

Questo file è un piano, non una descrizione di modifiche implementate. Non sono state alterate o arrestate le run attive.

## Verifica dello stato attuale

- Il recovery `_run_role` intercetta ora `httpx.TimeoutException`, `httpx.TransportError` e `APIConnectionError`, con retry bounded e backoff. `ReadTimeout` ricade in questa gerarchia: il difetto originale del timeout non riconosciuto è corretto nel codice corrente.
- Il coordinatore interrompe la coda per `fatal_error` soltanto fuori dalla modalità globale.
- Verifica locale: **4 test passati**, 192 deselezionati, su `test_transport_errors_retry_with_backoff` e `test_global_mode_marks_fatal_category_incomplete_and_continues`. Test offline con browser disabilitato solo nell'ambiente del comando. Il primo avvio della raccolta test era fallito per configurazione browser priva di token; nessuna modifica alla configurazione persistente.
- Questi test verificano il recovery di ruolo e il fail di `run()`; non provano ancora il recupero di uno stream parziale reale, il contenimento di tutto il lifecycle o la sopravvivenza a un crash di processo.

### Ultima DeepSeek: cosa si può affermare

La run `yeswiki-global-20260912-190606` è effettivamente avviata con `deepseek/deepseek-v4.1-flash`, verificato negli argomenti del processo Docker. Al controllo il processo Python era vivo da circa 1h55, il target web era healthy, l'outcome era `running` e non conteneva fatal. L'ultimo artifact osservato risultava aggiornato alle 21:03:34. Non è quindi documentata una morte tecnica della run; processo vivo non equivale però a progresso utile né esclude uno stallo.

Il transcript registra quattro messaggi di retry del Reviewer distribuiti su più episodi e un `model-exhausted`, sempre per cap di 2.000 token; il Reader registra un retry per cap di 5.500 token. Non emerge un esaurimento ricorrente dei 14.000 token Confirmer. La run `190529` registra invece un retry Worker per `Network connection lost`.

Un output parziale locale, attualmente scritto nello stesso file della run globale, non conserva necessariamente la vista aggregata. Anche la distinzione fra run attiva, in recovery, bloccata e terminata deve quindi provenire dal lifecycle autorevole, non dalla sola presenza di un errore nel transcript.

## P0 — fix economici, senza cambiare il Confirmer

### 1. Completare il contenimento tecnico per categoria

Allargare il boundary per-category a creazione dipendenze/orchestratore, Recon di categoria, run, handoff, cleanup, contabilizzazione e aggregazione. Oggi inizializzazione, parte del cleanup e del bookkeeping sono fuori dal blocco che contiene `run()`.

- Un errore locale produce `technical_failure`/categoria incompleta con causa, ruolo, ultimo checkpoint valido e dati già acquisiti; la coda continua.
- Un handoff o cleanup fallito non sostituisce il report valido con un report vuoto e non maschera l'errore originario.
- Normalizzare coerentemente gli stati `model_error`, `model_unavailable`, `fatal_error`: errore operativo, stato finale e exit code non devono contraddirsi.
- Gestire errori annidati del trasporto soltanto quando effettivamente classificati come tali. Non convertire indiscriminatamente ogni eccezione in retry.
- Aggiungere una deadline di durata totale della singola richiesta LLM, distinta dal timeout d'inattività HTTPX, che includa i tentativi. Valore iniziale conservativo, per esempio 180–240 secondi configurabili, poi taratura con misure reali. Mantenere un numero ridotto di retry e rispettare Retry-After entro la deadline.
- Ripartire da messaggi/tool completati, senza ripetere automaticamente operazioni HTTP mutanti di esito già noto. Stream incompleto non significa rollback dei tool già eseguiti.
- Distinguere cancellazione esplicita dell'utente e indisponibilità del target condiviso dai failure di categoria. Nessun loop di retry infinito per preservare artificialmente lo stato running.

**Limite della garanzia:** un `try/except` non contiene SIGKILL, OOM o crash del processo unico. Per imporre anche a questi eventi un impatto massimo di una categoria serve isolamento di processo, incluso nel P1. Un guasto dell'host o del disco condiviso non permette di garantire prosecuzione live: la garanzia realistica è conservazione degli ultimi checkpoint persistiti e ripresa, non sopravvivenza fisica a qualsiasi guasto.

### 2. Preservare sempre report e benchmark parziali globali

Il coordinatore deve diventare l'unico proprietario della pubblicazione dell'outcome globale. Ogni checkpoint locale aggiorna la categoria corrispondente, poi l'aggregatore ricostruisce la vista globale. Non sostituisce l'intero `report` con quello dell'ultima categoria.

- Restare nel contratto dei due artifact della run, senza inventare directory/file di output per categoria.
- Conservare report, evidenze e stato delle categorie già completate anche mentre la successiva è attiva o fallisce.
- Serializzare aggiornamenti di report, evidenze, budget e benchmark; scrittura atomica da sola non risolve aggiornamenti concorrenti persi.
- Calcolare il benchmark globale dai dati persistiti a ogni checkpoint utile, non solo dopo il ritorno del processo agente. Rendere disponibile anche il ricalcolo offline dallo stesso outcome.
- `partial_failure` significa score parziale disponibile e cause tecniche visibili, non azzeramento dei risultati. La finalizzazione Laravel deve tentare pubblicazione/scoring anche con exit non-zero o timeout del processo.

### 3. Aumento selettivo dei limiti output

- Reviewer: **2.000 → 3.000 token**, lasciando reasoning low e contratto piatto. Se lo stesso errore persiste nel piccolo benchmark di validazione, provare 4.000 prima di cambiare modello o prompt radicalmente.
- Reader: **5.500 → 6.500 token solo per Reader**, senza aumentare il default di altri ruoli. Nell'ultima run c'è un episodio osservato; misurare se l'aumento elimina il retry.
- Confirmer: invariati checkpoint, reasoning e 14.000 token. Worker e Recon invariati in assenza di cap-hit documentati per quei ruoli.
- Distinguere cap output da budget economico dello stadio; verificare che l'aumento di output non consumi la riserva necessaria a checkpoint/serializzazione terminale.

### 4. Due correzioni piccole che migliorano la discovery

- Classificare successo/errore dai metadata del tool e non dalla presenza di `error` nel codice letto. Correggere così sia i contatori sia il segnale ricevuto dal Reviewer.
- Ridurre il contratto model-facing `read_file` a path, start e count. Mantenere la compatibilità interna, ma eliminare i due modi sovrapposti start/count e start/end nello schema visto dal modello. Non indovinare finestre mancanti: correzione breve e bounded per argomenti ambigui.

### 5. Uscita sensata dalle aree e dalla categoria — P0 prioritario

**Conclusione dai log:** esiste una condizione formale di completamento (tutte le aree chiuse, nessuna lead pending), ma alcuni percorsi rendono inefficace il raggiungerla. Correggere questi percorsi prima di introdurre limiti più stretti o parallelismo.

#### Contesto e contratto del Reviewer: chiarire prima di complicare

L'Exploration Reviewer è stateless: non riceve la history completa del Reader. Riceve area attiva, ultimo checkpoint narrativo, category_notes, fino a 20 schede lead dell'area, ID delle aree chiuse/in coda, pending, contatori della tranche, ultime 8 tool call (argomenti ed excerpt troncati) e fino a 8 nuove source reference con estratti. La proposta ReaderReviewRequested viene aggiunta quando presente. Le schede lead espongono soprattutto titolo/stato/funding, non il motivo completo dell'esito Confirmer/Judge. Le alternative in coda sono soltanto ID, non dossier completi. Lo snapshot non offre una panoramica sufficiente a confrontare tutte le categorie globali.

Per la chiusura locale, i log dimostrano che questo contesto ha già permesso giudizi appropriati: la priorità è rispettarli. Non aggiungere tutta la history né un nuovo summarizer per default. Migliorare il checkpoint narrativo cumulativo dell'area attraverso i reset: domanda precedente, azione svolta, risultato, conclusioni già acquisite e residui. Aggiungere una breve motivazione autorevole degli esiti downstream alle schede lead, evitando che il Reviewer debba interpretare `reviewer_stopped` o `confirmer_funded` come prova tecnica. Per scegliere il prossimo focus, fornire almeno titolo e next_check delle aree candidate, oltre agli ID. Questi dati esistono già nel ledger/Recon.

Mantenere l'output piatto esistente (`decision`, `reason`, `next_area_id`, `next_focus`, `checkpoint_summary`):

- `continue_current`: il Reviewer spiega la domanda aperta e il prossimo controllo, senza dover soddisfare una soglia deterministica di novità;
- `pivot`: cambia davvero area/focus e conserva nella narrativa ciò che resta aperto; non è una dichiarazione di chiusura;
- `close_area`: la discovery dell'area è sufficiente secondo il Reviewer; le lead downstream e i loro esiti restano nel ledger;
- eventuale `defer_category`: aggiungerlo solo quando è implementata la possibilità effettiva di sospendere/riprendere una categoria e il Reviewer riceve un dossier di categoria (aree, residui e stato lead). Non esporre una scelta che il runner non sa applicare.

Chiarire nel prompt la distinzione fra cambiare area, chiudere discovery e chiudere una lead. L'Exploration Reviewer non sostituisce Confirmer/Judge nel decidere il merito della lead. Per la scelta comparativa fra categorie servirà contesto di categoria; non attribuirla implicitamente all'attuale snapshot locale.

Il prompting già invita a pivot/close in caso di stagnazione: da solo non corregge il problema, perché il normalizzatore oggi respinge proprio quei pivot e quelle chiusure. Il P0 minimo allinea prompt, enum e applicazione delle decisioni. I guard deterministici rimangono su scope/ID validi, integrità del ledger e budget ammesso, non sulla sufficienza semantica dell'esplorazione. Una decisione incompleta recuperabile viene normalizzata dai dati autorevoli; altrimenti una breve richiesta di correzione al Reviewer, senza ordinare automaticamente nuove letture al Reader.

Aggiornamento rispetto alla fotografia iniziale di questo documento: l'ultima DeepSeek `190606` ha ora lasciato A03 con `economic_budget_exhausted` e il transcript mostra la Recon di A01. L'outcome globale parziale riporta solo la categoria conclusa: non va interpretato come morte della run. In A03 ha effettuato 263 richieste LLM (161 Reader, 61 Confirmer, 30 Reviewer, più Recon/handoff), prodotto 7 lead, chiuso 4 aree su 10, senza avviare HTTP di verifica. Quindi il passaggio esiste, ma è stato innescato dal budget prima del completamento semantico.

Nell'ultima GLM `190529`, alla lettura di questa revisione: 8 lead, 59 richieste Reader, 100 Confirmer, 32 Worker e 12 transazioni HTTP; una sola area attiva su 10, nessuna chiusa. Il transcript contiene 7 reset di epoch dopo lead e **nessun handoff all'Exploration Reviewer**; i reviewer presenti sono di altra funzione. Queste sono snapshot mobili, non risultati finali.

Le due prime run rimangono casi censurati dai timeout: non dimostrano da sole che la prima area non potesse terminare. Le ultime due permettono invece di distinguere produttività da stallo del controllo.

#### A. Chiusure corrette rifiutate per memoria strutturale insufficiente

Nell'area `area-migrations-upgrade` DeepSeek registra **5 `close_area respinto` e 3 pivot cross-area convertiti in `continue_current`**. Reader e Reviewer descrivono ripetutamente area esaurita e nessun ramo nuovo, ma il loop riparte.

La ricostruzione del checkpoint usa il precedente `checked_surfaces` più `evidence.new_files`, dove `new_files` è il delta dei file visti rispetto all'inizio della tranche. Letture già fatte in precedenti epoch, Recon o Confirmer non costituiscono file nuovi. Dopo i reset una superficie verificata può quindi non comparire in `checked_surfaces`, che è requisito obbligatorio di close_area. I log dei rifiuti riportano anche `in attesa=0`; il checkpoint finale delle migrazioni conserva soltanto `Collection.php` fra le checked surfaces, benché il transcript documenti molte letture di MigrationService e comandi.

L'assenza delle singole snapshot di normalizzazione non permette di attribuire retrospettivamente ciascun rifiuto allo stesso campo, ma l'accoppiamento fra delta di novità e requisito di chiusura è un difetto verificabile nel codice.

**Fix:** costruire le superfici controllate dalle osservazioni/source reference autorevoli accumulate per area, preservate attraverso epoch e ritorni dal Confirmer. Separare “già verificato” da “nuovo nell'ultima tranche”. Il Reviewer decide la sufficienza semantica: non dedurla dal solo fatto che un file sia stato aperto. Se un campo ricostruibile manca, normalizzarlo dal ledger; non mandare il Reader a rileggere per riempirlo. Registrare separatamente decisione proposta e applicata, con causa specifica del rifiuto.

#### B. Un pivot deve davvero cambiare focus operativo

Oggi un pivot verso un'altra area viene convertito in continuazione dell'area attiva, ma conserva la direttiva per quella nuova. DeepSeek finisce a cercare ThemeManager mentre lo stato autorevole resta migrations-upgrade.

**Fix:** consentire il pivot mantenendo l'area precedente sospesa/aperta, con checkpoint e domanda residua, oppure applicare close+activate quando il Reviewer ha deciso la chiusura. Mai mantenere area vecchia e istruzione nuova incompatibili. Un pivot non implica che l'area lasciata sia completa.

#### C. La produzione di lead può evitare indefinitamente la review dell'area

`_resume_reader_after_lead` apre una nuova epoch e ordina di rimanere nell'area cercando sink fratelli. Se ogni epoch produce una lead prima della soglia di review, il controllo di completamento area può non intervenire. È la firma osservata nell'ultima GLM: otto lead API, quasi tutte legate a varianti CSRF, nessuna Exploration Review. I sink distinti non sono automaticamente duplicati: tuttavia una nuova route non dimostra da sola che ripetere tutto il lavoro sia il miglior uso della prossima tranche.

**Fix:** mantenere il contatore di review a livello di area attraverso i reset, usando la soglia già configurata; il ritorno da una lead non deve azzerarlo. Se la soglia è maturata, eseguire la normale Exploration Review prima di assegnare un nuovo giro di sibling discovery. Non ridurre envelope e non aggiungere un supervisore per-lead sempre obbligatorio. Il Reviewer vede fatti condivisi già risolti, lead e veri rami residui.

#### D. Distinguere completamento discovery, verifica lead e sospensione

Una categoria può uscire in due modi semanticamente diversi:

1. **Discovery completata:** tutte le aree sono state valutate; ciascun ramo plausibile è stato escluso con motivazione, rappresentato da una lead oppure assegnato esplicitamente al downstream. La presenza di una lead già acquisita non impone di cercarla di nuovo. Il report resta incompleto finché gli accertamenti necessari non sono conclusi.
2. **Categoria sospesa/incompleta:** rimangono rami noti, ma il prossimo esperimento non è fattibile ora (provider, ambiente, prerequisito o funding). Persistono ipotesi, evidenze, ostacolo e condizione di ripresa; lo scheduler può avviare la categoria successiva. Non dichiarare clean né abbandonare silenziosamente il sink.

La coda pending deve distinguere lavoro realmente eseguibile da lead già sospese/fermate. Non usare l'assenza di tutte le pending globali come requisito per chiudere una singola area che ha completato la discovery.

**Criterio di continuazione:** deve esistere una domanda discriminante ancora aperta e un'azione fattibile capace di aggiungere evidenza. Se l'azione ha prodotto un nuovo dato utile, continuare è appropriato anche dopo molti token. Se la stessa domanda/azione è riproposta dopo un boundary senza nuova evidenza, cambiare approccio oppure sospendere il ramo; non finanziare automaticamente la ripetizione. Riutilizzare decisione e memoria narrativa del Reviewer; l'orchestratore mantiene identità, evidenze e stato interno. Nessun nuovo DTO annidato.

Un errore del Reviewer non deve diventare ripetutamente una normale estensione discovery: un recupero tecnico bounded conserva lo stato; se fallisce, sospendere la decisione/area e consentire il progresso altrove, senza dichiararla sicura.

#### E. Verificare il funding del checkpoint senza cambiarlo

DeepSeek mostra 5 `Self-checkpoint non finanziabile`; GLM ne mostra 2 nello snapshot. Questo non dimostra che il checkpoint del Confirmer sia inefficace: in quegli episodi non è stato finanziato. Verificare che il preset global riservi realmente il costo del checkpoint e dell'output terminale prima di concedere la tranche investigativa. Non aumentare indiscriminatamente gli envelope né sostituire il checkpoint prima di chiarire questo accounting.

#### Giudizio di rendimento

Una parte del lavoro è utile: DeepSeek individua sottosistemi differenti (autoupdate, build, migrazioni, font); GLM raggiunge anche il Worker. Non sono ore interamente sprecate e non c'è evidenza sufficiente per prescrivere un tetto temporale arbitrario alla prima categoria.

Non è però ragionevole considerare tutto il consumo profittevole: le chiusure respinte, i pivot che non spostano l'area, le riemissioni della stessa ipotesi e i checkpoint non finanziabili sono lavoro evitabile. Correggere questa liveness è P0. Dopo il fix, se restano nuove evidenze e sink realmente distinti, lasciare lavorare la categoria con gli envelope attuali e usare la concorrenza P1 per ampliare la copertura.

### Accettazione P0

Test di chiusura su file già visti in altre epoch, persistenza delle osservazioni per area, pivot effettivo, soglia review che sopravvive a più lead consecutive, sospensione con ripresa e checkpoint Confirmer finanziabile. Test mirati su stream con chunk seguito da ReadTimeout, retry esauriti, errore nel costruttore, run, handoff e cleanup: le categorie seguenti partono e i dati precedenti restano nel report. Test sulla deadline con stream che continua a inviare chunk. Test di checkpoint globale durante una categoria successiva e di scoring dopo exit non-zero. Nessun HTTP mutante completato viene ripetuto dal recovery. Nessun lint globale.

## P1 — top 2–3 categorie concorrenti, envelope attuali

Questa revisione sostituisce la proposta precedente di tranche iniziali da 8 richieste e precedenza generalizzata alle categorie non visitate. Conservare envelope e sistema di estensione esistenti. Avviare le prime 2–3 categorie nell'ordine d'interesse suggerito dalla Global Recon; non ridurre il budget discovery per ottenere artificialmente un cambio categoria.

Prerequisito: P0 sulle transizioni di area e sulle condizioni di uscita, descritto sotto. Il parallelismo rende più veloce una pipeline produttiva, ma moltiplica una pipeline bloccata.

- Stato investigativo, history e checkpoint separati per categoria. Registro lead e pubblicazione globale gestiti dal coordinatore, con ammissione serializzata delle proposte concorrenti anche contro lead pending.
- Unico proprietario della scrittura dell'outcome; prenotazioni budget atomiche, senza moltiplicare il cap comune o sottrarre il funding di una lead già ammessa.
- Conservare il checkpoint Confirmer e le sue estensioni. Prima abilitazione con massimo un Worker dinamico sul target condiviso: le altre categorie continuano discovery/triage; non eseguire test mutanti concorrenti senza isolamento dello stato.
- Global Recon condivisa e indici riusati. Verificare consumo di memoria e mutabilità di settings/backend prima di aumentare gli slot.

### Checkpoint dopo la top 3

Introdurre inizialmente un checkpoint aggregato deterministico, senza nuovo Judge globale: per ogni categoria mostrare aree valutate, aree aperte, lead distinte, stadi raggiunti, ripetizioni, blocchi, tempo/costo e prossimo esperimento indicato dai Reviewer.

Il checkpoint di gruppo diventa decisionale quando tutte le prime tre categorie hanno raggiunto almeno un boundary di area utile oppure una sospensione esplicita. Non attendere necessariamente la chiusura completa di tutte e tre. La sua pubblicazione non blocca gli slot liberi e non cancella lead attive. Come policy iniziale, una categoria con esperimento nuovo e fattibile mantiene il proprio envelope; una chiusa/sospesa libera lo slot per la successiva nell'ordine Recon. Una rivalutazione semantica globale delle priorità resta P2, da giustificare coi dati.

### Crash e limiti dell'isolamento

Per contenere eccezioni applicative bastano boundary isolati e persistenza corretta, già P0. Se resta requisito tassativo contenere anche OOM/SIGKILL della singola categoria, il runner deve avere processi figli isolati e un coordinatore padre: questo è un requisito di fault isolation separato dalla politica dei budget, non un motivo per ridurne gli envelope. Un guasto dell'host richiede ripresa dai checkpoint.

### Accettazione P1

Avviare effettivamente le prime tre categorie nell'ordine Recon, dimostrare che una categoria ricca ottiene le normali estensioni, che una chiusa/sospesa libera lo slot e che un fallimento non interrompe le altre. Verificare cap globale, deduplicazione concorrente, persistenza e serializzazione dei Worker sul target condiviso. Il confronto con baseline deve misurare risultati distinti e copertura semantica oltre al semplice tempo.

## P0 indipendente — benchmark globale senza oracle CVE

### Ambito

Nuovo evaluator deterministico solo per `benchmark:run --global`, con versione `global_productivity_v1`. Nessun matching con manifest CVE, anchor o TP/FP/FN nel punteggio globale. I manifest possono ancora servire al provisioning/fixture del target: non entrano nello scoring globale. I comandi benchmark specifici per stadio/categoria conservano oracle, fixture e valutazione attuali.

Il risultato misura **copertura osservata e avanzamento delle ipotesi**, non recall delle vulnerabilità reali. Senza oracle non è possibile ricostruire quanti bug siano rimasti ignoti. Non presentare il punteggio come percentuale di sicurezza o accuratezza.

### Formula iniziale proposta

`score = coverage_points + somma(stage_points per ipotesi distinta)`

Coverage, massimo 20 punti:

- fino a 10 punti per quota di categorie richieste con almeno una osservazione sorgente Reader valida e attribuita alla categoria;
- fino a 10 punti per quota di file sorgente eleggibili effettivamente letti dal Reader sul totale dell'inventario deterministico fissato all'avvio.

Un risultato di ricerca con nome/riga soltanto, un elenco di directory o l'inventario Recon non valgono come file letto. Conteggiare letture/snippet effettivi con provenance verificata, una sola volta per file. Escludere vendor/generated/asset secondo lo scope sorgente già applicato. Mostrare numeratori e denominatori: visita di un file non equivale a copertura di tutte le sue righe. Se manca un denominatore, quella componente è non disponibile, non si inventa uno zero o una percentuale.

Per ipotesi distinta, usare **il solo stadio più alto valido**, senza sommare 1+3+6:

| Stadio | Punti |
|---|---:|
| ReaderLead accettata, non duplicata, non successivamente smentita | 1 |
| CandidateHandoff accettato dal Confirmer, validazione statica | 3 |
| Conferma dinamica attestata dal Judge con evidenze richieste | 6 |

Una lead avanzata al dinamico vale 6 in totale. Delta e riclassificazioni non producono nuovi punti sulla stessa ipotesi. L'identità deriva dal registro e dalle relazioni semantiche autorevoli, non dall'uguaglianza della route o del titolo. Una relazione unresolved rimane esplicitamente provvisoria e non viene dichiarata certamente unica.

Una chiusura tecnica per budget/provider non cancella uno stadio valido già raggiunto. Una smentita tecnica della vulnerabilità revoca i punti dell'ipotesi; conservarne il conteggio nella metrica separata delle lead prodotte e chiuse. Separare chiusura semantica da failure infrastrutturale. Una dichiarazione testuale del modello non basta a promuovere lo stadio.

### Output

- Score assoluto, breakdown coverage/Reader-only/static-only/dynamic, pesi e versione.
- Contatori cumulativi di lead distinte prodotte, validate staticamente, confermate, smentite, duplicate/delta e unresolved.
- Categorie non visitate/incomplete/terminate/fallite; file osservati/inventariati.
- Durata, richieste, costi disponibili, lead/ora e conferme/ora, senza nascondere costi mancanti o errori tecnici.
- Stato score parziale/finale distinto dal risultato tecnico della run. Nessun massimo complessivo inventato e nessuna normalizzazione percentuale: non conosciamo il numero totale di vulnerabilità.

Scoring ricalcolabile senza provider dagli outcome esistenti, distinguendo dati storici assenti da zero. Se l'inventario deterministico non è persistito nelle vecchie run, coverage file non disponibile; i punti lead possono comunque essere calcolati. Non modificare silenziosamente gli score storici con una nuova versione.

### Accettazione scoring

Stessa ipotesi emessa da due categorie, delta, upgrade Reader→static→dynamic, smentita, interruzione tecnica dopo static, evidenze dinamiche insufficienti e categoria successiva fallita: conteggi corretti senza duplicazione o perdita. Nessuna dipendenza del punteggio globale dal manifest CVE. Test di regressione dei benchmark specifici e della presentazione CLI/UI, che non deve continuare a mostrare zeri TP/FP/FN per questo evaluator.

## P2 — soltanto dopo misure comparabili

Valutare più slot, endpoint diretti/fallback multi-provider e riuso più sofisticato fra delta. Confrontare stessi target, configurazione, cap e durata. Non adottare cambi strutturali del Confirmer o un nuovo agente sulla base del punteggio di produttività globale.

## Ordine delle consegne

1. Contenimento, persistenza globale e piccoli aumenti output; test offline mirati.
2. Scoring globale deterministico e ricalcolo offline dei dati disponibili.
3. Top 2–3 categorie concorrenti nell’ordine Recon, envelope attuali, checkpoint aggregato e ammissione serializzata delle lead; isolamento di processo se richiesta la garanzia contro crash/OOM.
4. Una run di validazione bounded e confronto con baseline su copertura, lead distinte, stadi, errori e tempo. Nessuna promessa numerica di speedup prima della misura.

Aggiornare `ARCHITECTURE.md` insieme alle implementazioni che cambiano recovery, ownership della persistenza, budget, scheduling e scoring. Questo piano non modifica la fotografia dell'architettura corrente.
