# Memoria degli agenti — notebook P0 e continuità narrativa P1

Aggiornamento del 14 settembre 2026: estensione del piano alla continuità fra compaction, epoch, lead, aree e categorie, collegata al P1 del [piano provider e budgeting YesWiki](yeswiki-global-130326-provider-budget-fix.md). Il P0 originario dei notebook resta separato; il P1 di continuità non richiede che gli agenti scelgano prima di usare i notebook. Questo documento descrive interventi futuri, non capability già implementate.

## Obiettivo e perimetro

Offrire agli agenti una memoria narrativa facoltativa che sopravviva alle loro conversazioni e possa essere riutilizzata dalle istanze successive (notebook P0), e conservare di default il contesto operativo necessario a riprendere un'indagine (continuità P1). Nei notebook l'agente decide cosa annotare, quando leggerlo, quando aggiornarlo e se un fatto è applicabile alla nuova indagine. Nella continuità il modello racconta attraverso checkpoint e output già previsti; l'orchestratore conserva, identifica, collega e reinietta il testo nel perimetro corretto, senza selezionare semanticamente fatti o conclusioni.

**Principio dei notebook: scrittura facoltativa, disponibilità resa evidente automaticamente, approfondimento facoltativo, riutilizzo ragionato.** L'agente non deve cercare alla cieca per scoprire che esiste una nota utile: riceve un hint prima di iniziare il lavoro, poi decide se approfondire e applicare quella conoscenza. **Principio della continuità: persistenza e presentazione del checkpoint operativo di default**, anche con zero chiamate a `write_note` e `read_notes`.

Per P0, **persistente significa durevole nella stessa run**: attraverso lead diverse, cambi area/categoria, nuove epoch, ricreazione dell'agente e caricamento dello stato da un checkpoint della medesima run. Il salvataggio non dipende dal completamento del turno o della categoria. La funzione di caricamento della memoria non implica da sola un resume completo di tutto il runner.

Il riuso fra audit distinti dello stesso progetto è rinviato a P2: codice, ambiente e fixture possono cambiare, e nei benchmark indipendenti il riuso altererebbe il confronto. Non introdurre implicitamente una memoria condivisa tra clienti/progetti né apprendimento globale tra run. La ripresa dello stato della medesima run è un caso distinto.

Questa è una proposta di implementazione, non una modifica già applicata al runtime.

## Cosa esiste già

- `category_notes`: testo narrativo di categoria aggiornato da alcuni output del modello; una singola revisione, attualmente limitata a 6.000 caratteri.
- Checkpoint di area del Reviewer, memoria di compression e checkpoint di ruolo: ricostruiscono la continuità del loop e conservano stato/narrativa ai boundary.
- `global_memory` e handoff: trasferiscono contesto fra categorie.
- Codebase Memory: indicizzazione e navigazione del sorgente, non taccuino delle scoperte del modello.

Sono componenti utili, ma non espongono un'interfaccia generale di note taking persistente e intenzionale. Non sostituire questi checkpoint e non imporre una copia manuale delle informazioni già salvate nel ledger. Il taccuino completa il sistema con appunti liberamente scelti dall'agente.

### Evidenze che motivano l'estensione

Il task **Pianifica fix run e budgeting** analizza la run `yeswiki-global-20260913-130326`. La rilettura mirata del [log locale](../storage/app/runs/yeswiki/global/yeswiki-global-20260913-130326/yeswiki-global-20260913-130326-logs.php) conta 35 eventi `[reader:epoch]`, cioè reset registrati come compaction, non necessariamente 35 compression per saturazione della context. Le righe `[reader:tool] read_file(path="…")` contano 207 chiamate su 71 file distinti, di cui 41 letti più volte: `SearchManager.php` 15 volte, `AuthController.php` 12. I totali 299/83 dell'analisi iniziale non vanno attribuiti al solo Reader. Una rilettura può riguardare un altro range o una nuova ipotesi: questi conteggi non dimostrano da soli quante chiamate siano evitabili, né che ogni reset faccia ripartire da zero.

Il codice attuale mostra però punti concreti da correggere nel P1:

- `_resume_reader_after_lead` può aprire una nuova epoch usando il checkpoint d'area precedente, senza nuova review quando il boundary non è maturato. Lead e source ref sopravvivono, ma la narrativa può non includere ciò che è stato appena appreso.
- `reader_area_dossier` mostra le ultime 12 source ref dell'area con excerpt; `_exploration_snapshot` mostra excerpt delle ultime 8 nuove ref; `_fallback_area_checkpoint` conserva una coda di 24 ref. La recenza può estromettere il controllo o il sink che spiega la prossima azione. Il registry conserva più informazioni della proiezione ricevuta dal modello.
- Reviewer e `category_notes` hanno già memoria narrativa, ma con limiti distinti e aggiornamenti non presenti su ogni percorso. `GlobalRunState.register_report` concatena le note categoria nella memoria globale e ne prende gli ultimi 20.000 caratteri: persistenza, trasferimento e sintesi non sono la stessa garanzia.
- Confirmer dispone già di assignment graduati con tutti gli ID autorizzati e lettura `read_source_ref`; il Worker riceve ref selezionate dal Confirmer. Questi sono precedenti da riutilizzare, non da sostituire con un secondo sistema di retrieval.

Sono riscontri sul checkout del 14 settembre, che può già differire dal runtime della run. I log motivano il problema; non provano che il notebook, da solo, lo risolva. Budget insufficiente, guasti provider e limiti dei tool restano cause separate nel piano di fix.

### Decisione: continuità di default e appunti facoltativi

| Componente | Cosa conserva | Presentazione e autorità |
|---|---|---|
| Stato autorevole esistente | Lifecycle, coverage, source ref, transazioni, riferimenti actor/session, budget | Proiezione scoped automatica; resta la fonte di verità operativa |
| Memoria operativa di default (P1) | Cosa è stato capito, perché, condizioni, tentativi discriminanti e risultati, esclusioni motivate, unknown e prossimo passo | Narrativa bounded inline prima di ripartire; prodotta dal modello nei boundary esistenti, collegata e persistita dal runtime |
| Notebook facoltativo (P0) | Osservazioni riutilizzabili e dettagli che l'agente vuole conservare oltre l'episodio | Hint automatici e corpi su richiesta; nessun obbligo di annotare o consultare |

Entrambe le memorie sono persistenti: la differenza è **la garanzia di disponibilità operativa**, non il salvataggio. Un catalogo di note opzionali non garantisce che qualcuno abbia scritto la conclusione essenziale o che il destinatario la legga. Estendere i due tool a tutti i ruoli non copre quindi tutti i casi; i supervisori tool-free devono ricevere continuità tramite i loro snapshot. Non creare una seconda collezione di “note obbligatorie”: rafforzare checkpoint e handoff esistenti.

## Esclusione esplicita: autenticazione

Stato autenticato, cookie/token, credenziali e relativo dossier sono già oggetto di un altro task. Questo piano non li reimplementa.

Nel prompt chiarire che la memoria non è il deposito di credenziali e non è la fonte autorevole dello stato di una sessione. L'agente può annotare una scoperta concettuale sul flusso, rimandando agli actor/session identifier esistenti; lo stato corrente viene dal dossier runtime. Non copiare segreti o body completi nel testo libero.

## P0: due ambiti, un solo meccanismo

| Ambito | Chi riutilizza le note | Esempi |
|---|---|---|
| `category` | I ruoli che lavorano nella categoria corrente, anche su altre aree/lead | Convenzioni di dispatch, controlli comuni verificati, differenze tra due percorsi, quesiti ancora aperti |
| `role` | Le successive istanze dello stesso ruolo nella run, anche in categorie diverse | Comportamento di un tool osservato sul progetto, procedura investigativa riuscita, interpretazione runtime utile ad altre lead |

L'identità effettiva è costruita dal contesto: run + categoria corrente oppure run + ruolo corrente. L'agente seleziona soltanto l'ambito, non percorsi filesystem, tenant o run ID. Il ruolo è il ruolo operativo reale, non il nome del modello: cambiare modello non perde le note.

P0 non aggiunge un terzo ambito categoria×ruolo, un taccuino per ogni lead o un archivio globale. Se una nota vale soltanto in certe condizioni, l'autore lo dice nel testo. Le note role restano isolate dagli altri ruoli per default; per condividere con altri ruoli l'agente sceglie category.

## Interfaccia model-facing minima

Proposta: **due tool**, con output semplice.

`read_notes(scope, note_id?)`

- Senza `note_id`: restituisce il catalogo del taccuino, con ID, titoli e hint; per le note brevi mostra direttamente il corpo. Paginazione soltanto se necessaria al limite di output.
- Con `note_id`: restituisce il testo della nota appartenente al taccuino autorizzato.
- Il taccuino vuoto è una risposta normale, non un errore.

`write_note(scope, title, hint, body, note_id?)`

- Senza ID: crea una nota e restituisce l'ID assegnato dall'orchestratore.
- Con ID: sostituisce quella nota con una revisione esplicita, mantenendo l'identità.
- Non serve appendere una seconda nota per correggere la prima. Per una conclusione superata l'agente può riscrivere la nota spiegando la correzione.
- Titolo breve, hint di una frase e corpo in testo libero. L'autore scrive nell'hint cosa contiene la nota e quando può essere utile: è il segnale che permette alla prossima istanza di scegliere consapevolmente se leggerla. L'orchestratore non genera o interpreta semanticamente l'hint.
- Aggiornando una nota, l'autore mantiene coerenti hint e corpo. Per una nota già breve, hint e corpo possono coincidere: non richiedere due riassunti diversi della stessa frase.
- Nessun campo obbligatorio per confidence, categoria CWE, sink, evidence tree, applicabilità tipizzata o “lezione imparata”. Queste informazioni, se utili, stanno nella narrativa. L'hint è l'unico campo aggiuntivo rispetto alla proposta iniziale: il titolo da solo non rende esplicito quando consultare la nota e il corpo completo può essere troppo lungo da mostrare sempre.

Metadata interni (autore, categoria di provenienza, modello, timestamp, revisione) vengono aggiunti dal runtime, senza chiederli al modello. Un eventuale conflitto sulla stessa nota va comunicato come mancato aggiornamento con invito a rileggere; non fondere semanticamente testi diversi con regole o un nuovo agente. Le creazioni concorrenti di note diverse devono entrambe essere conservate.

La motivazione dei due tool è consentire scrittura prima dell'output terminale e lettura selettiva. Aggiungere campi a ogni risultato terminale non darebbe la stessa durabilità e renderebbe i contratti più pesanti.

## Come rendere la possibilità chiara senza imporla

I ruoli con tool investigativi, in P0 Reader, Recon, Confirmer e Worker, ricevono i due tool quando compatibili con la fase corrente. Nelle fasi terminali tool-free non riaprire l'investigazione soltanto per scrivere note. I supervisori tool-free conservano i loro contratti attuali: nessun nuovo giro di tool viene imposto per introdurre questa funzione. Le loro memorie narrative esistenti continuano a essere disponibili; un editor personale per i supervisori resta P1 se emerge un'utilità concreta.

### Esposizione a due livelli

Nel briefing iniziale di ogni nuova istanza o assegnazione di lead dei ruoli abilitati, prima dei tool investigativi, rendere visibili:

1. disponibilità dei taccuini categoria/ruolo e significato dei due ambiti;
2. un catalogo compatto con ambito, ID, titolo e hint delle note disponibili nei due taccuini autorizzati;
3. istruzioni d'uso ed esempi pertinenti al ruolo.

Il corpo delle note lunghe si legge su richiesta con read_notes. Se la nota è già breve, mostrarne direttamente il corpo al posto dell'hint, senza duplicarli e senza imporre una tool call per poche righe. Questa scelta dipende soltanto dalla dimensione del testo, non da una stima automatica di rilevanza. I limiti del catalogo e del taccuino devono essere coerenti: con il volume previsto dal P0, gli hint devono essere visibili entro un budget di prompt contenuto.

La presentazione del catalogo è un meccanismo di accessibilità, non una selezione semantica delle note. Un ordinamento stabile/per aggiornamento basta in P0; nessun embedding, ranking automatico di importanza, filtro per keyword o vector database. Se il catalogo supera il limite, indicare chiaramente quante note non sono mostrate e permettere di consultare il resto: non nasconderle come se non esistessero.

### Aggiornamento del contesto

- **Nuova istanza, nuova lead o cambio categoria:** mostrare il catalogo aggiornato degli ambiti autorizzati prima di iniziare l'indagine.
- **Compression o reset:** ricostruire anche il catalogo, perché la disponibilità delle note non dipenda da messaggi rimossi dalla history.
- **Note create o riviste durante attività concorrenti:** al successivo boundary utile, prima della richiesta al modello, aggiungere soltanto gli hint nuovi/aggiornati (o i corpi brevi), identificati come aggiornamenti. Non reinviare il catalogo intero a ogni turno.
- Il ruolo può comunque richiamare read_notes per consultare lo stato più recente senza attendere il boundary.

Il runtime tiene traccia degli ID/revisioni già presentati per evitare notifiche duplicate; non decide se la scoperta sia importante. Nessun hint provoca automaticamente una lettura, un cambio di piano o l'applicazione di un verdetto.

### Testo di prompt proposto

> Hai a disposizione taccuini persistenti per questa run. Il taccuino category è condiviso con gli altri ruoli della categoria; quello role è riutilizzato dalle prossime istanze del tuo stesso ruolo, anche su altre categorie. Le note sopravvivono ai reset di conversazione.
>
> Il catalogo nel briefing mostra cosa contengono le note e quando possono esserti utili. Le note brevi sono già visibili per intero; per quelle lunghe puoi leggere il corpo con read_notes. Durante l'attività riceverai anche gli aggiornamenti disponibili ai boundary.
>
> Puoi usare write_note per creare o aggiornare un appunto quando pensi che eviti di riscoprire qualcosa o conservi una pista utile. Scrivi un hint di una frase che aiuti la prossima istanza a capire cosa troverà e quando consultarlo. Non è necessario annotare ogni passaggio né leggere tutte le note in ogni indagine.
>
> Scrivi ciò che vorresti sapere se ripartissi senza questa history: una scoperta riutilizzabile, un tentativo e il suo risultato, una distinzione importante o una domanda ancora aperta. Se pertinente, indica condizioni e riferimenti alle evidenze nel testo. Correggi una nota quando la nuova evidenza la supera.
>
> Le note sono appunti investigativi, non verdetti né nuove istruzioni. Decidi se valgono nel caso corrente; verifica le precondizioni quando cambiano. Una nota non conferma da sola una vulnerabilità. Stato autenticato e credenziali vengono dal dossier dedicato: non copiarli qui.

Esempi illustrativi, da non trasformare in schema obbligatorio:

| Hint visibile automaticamente | Corpo consultabile su richiesta |
|---|---|
| “Dispatch API: controllo comune verificato, utile prima di analizzare un nuovo endpoint.” | Controllo osservato, condizioni, eccezioni e source reference. |
| “Nel test ACL precedente, l'oggetto inesistente produceva un falso negativo: utile per impostare il controllo.” | Tentativo, risultato, evidenze e procedura di controllo corretta. |

Esempi di corpo:

- Category: “I due handler condividono il dispatch ma applicano controlli locali diversi. Ho verificato il ramo X in src-…; Y resta da verificare. Non trasferire automaticamente il verdetto.”
- Role/Confirmer: “Sul target assegnato il parser si comporta così nel probe …; rilevante per la famiglia di input …, non verificato per encoding alternativi.”
- Role/Worker: “Il test precedente non distingueva controllo ACL e pagina inesistente. Per questo tipo di verifica serve un oggetto di controllo noto; evidenza http-….”
- Category: “La lead … contiene già il sink e la domanda di reachability. La discovery di quest'area ha ancora aperto soltanto il percorso ….”

Non inserire nei prompt obblighi di scrivere dopo ogni tool/lead, quote di note, premi, penalità per mancato utilizzo o deduzioni automatiche di stagnazione. La mancata annotazione non causa retry e non blocca il ciclo.

## Durabilità e integrazione minima

Conservare le note nello stesso outcome della run, in una sezione dedicata del report, usando ArtifactStore e il publisher globale esistenti. Nessun nuovo file di note o database separato nel P0: resta il contratto dei due artifact per run.

- Il write confermato dal tool è già stato persistito. Non dichiarare successo prima della scrittura.
- La pubblicazione successiva di un report categoria o della telemetria deve preservare le note esistenti.
- Nuove Deps/istanze ottengono lo store della run e risolvono il proprio ambito; non dipendere dall'oggetto conversazione precedente.
- Per una lettura da checkpoint caricare gli appunti persistiti della stessa run. L'esistenza di una nota non ripristina automaticamente sessioni o stato del target.
- Riutilizzare serializzazione/coordinamento delle scritture già presenti; verificare che sia compatibile col modo di concorrenza effettivo. Il lock in-process non è una garanzia per processi distinti: con processi figli le mutazioni passano al publisher padre.
- Limiti semplici a dimensione nota/taccuino e output per evitare crescita illimitata. Se una scrittura supera il limite, messaggio breve e possibilità di rivedere la nota. Non troncare silenziosamente, riassumere con un altro LLM o cancellare vecchie note per liberare spazio.
- Errore di salvataggio delle sole note: comunicarlo esplicitamente e preservare l'ultimo stato valido; non trasformarlo in un verdetto sulla lead. Riutilizzare la policy infrastrutturale esistente quando il problema riguarda anche la persistenza delle evidenze.

Nessun algoritmo promuove note a finding, chiude aree o deduplica lead sulla sola memoria. Provenance e perimetro restano quelli dei ledger/tool esistenti; una reference non più disponibile viene trattata come tale. Il testo di una nota non autorizza letture fuori dal source scope del ruolo o HTTP fuori target.

## Verifiche P0

1. Reader scrive una nota category; un nuovo Confirmer della stessa categoria la trova. Una nuova categoria non la riceve automaticamente.
2. Confirmer scrive una nota role; il Confirmer della lead successiva e di un'altra categoria la trova. Worker non la riceve come propria nota role.
3. Creazione, revisione e rilettura funzionano dopo reset dell'epoch e ricreazione delle dipendenze dal checkpoint; le pubblicazioni locali/globali successive non cancellano le note.
4. Due scritture di note distinte non si perdono; un aggiornamento in conflitto non sovrascrive silenziosamente la revisione non letta. Nessun leakage fra run.
5. Nessuna chiamata ai tool di memoria: run e benchmark continuano normalmente. Nessun campo memoria diventa obbligatorio negli output terminali.
6. Limite/errore di persistenza: il tool non dichiara successo e conserva il testo precedentemente salvato.
7. Una nuova istanza o lead riceve gli hint prima della prima azione investigativa, senza dover chiamare read_notes per scoprirne l'esistenza. Una nota breve appare direttamente, una lunga resta leggibile su richiesta.
8. Compression/reset ricostruiscono il catalogo; una nota creata o aggiornata da un'altra istanza compare al boundary successivo una sola volta per revisione. Il corpo lungo non viene iniettato automaticamente.
9. Catalogo oltre limite: omissione esplicita e accesso al resto, senza selezione automatica per parole chiave o presunta importanza.

Validazione semantica breve su due o tre lead correlate dello stesso target: verificare se l'agente sceglie di annotare e se la nuova istanza nota l'hint prima di ripetere tentativi, approfondisce quando utile e riusa il fatto correttamente senza perdere la differenza specifica della nuova lead. Confrontare richieste, riletture e correttezza dell'esito con/senza taccuini, mantenendo gli envelope. Il semplice numero di note non è una metrica di successo. Se gli agenti non usano le note, valutare prima chiarezza degli hint e utilità percepita; non renderle obbligatorie.

## P1 — continuità narrativa e selezione delle source ref

Questo è il P1 del piano di fix della run. È indipendente dall'adozione dei notebook: per validare il problema osservato, iniziare da **P1.1 Reader**, poi estendere la stessa garanzia con **P1.2 agli altri boundary e alle categorie**. Non subordinare la continuità essenziale al successo del note taking facoltativo. Non implementare automaticamente le estensioni P2.

### Cosa deve sopravvivere e a chi

| Boundary / destinatario | Memoria disponibile di default | Cosa resta consultabile senza riempire il prompt |
|---|---|---|
| Reader, nuova epoch nella stessa area o ritorno dopo lead | Ultimo checkpoint d'area, delta accettato dell'ultima lead, esito downstream, rami residui e prossimo focus; controlli già compresi con condizioni e ref | Altre ref dell'area, dettagli delle lead e notebook autorizzati |
| Reader, pivot e ritorno a un'area sospesa | Checkpoint distinto dell'area ripresa, stato corrente delle sue lead, note categoria trasversali | Storia delle altre aree; non trasferire come valido il verdetto dell'area appena lasciata |
| Confirmer, compaction o ripresa della stessa lead | Catena source→sink, controllabilità e gate, mitigazioni, probe e risultati, esclusioni, unknown, decisione del self-checkpoint e passo successivo | Snippet completi tramite ref, output runtime ed evidenze conservati dai meccanismi esistenti |
| Worker, compaction o retry della stessa lead | Candidate/verification plan, setup già verificato, actor/session ID, transazioni e loro interpretazione, control/test già svolti, feedback Judge e prossimo esperimento | Body/ispezioni nei limiti dello store esistente; la narrativa non ricrea una risposta non più disponibile |
| Nuova lead per Confirmer/Worker | Assignment della nuova lead e contesto condiviso applicabile; notebook role e category visibili | Memoria episodica di altre lead solo attraverso riferimenti e handoff autorizzati; nessun riuso automatico di precondizioni o conclusioni locali |
| Exploration Reviewer / Novelty Reviewer / Dynamic Judge | Snapshot scoped, sintesi precedente e delta corrente; ragioni delle decisioni pertinenti, esclusioni e questioni da discriminare | Ledger e ref restano autorevoli; nessuna history personale o nuovo tool loop per questi supervisori |
| Global/Category Recon e Handoff | Mappa e unknown già osservati, sintesi condivisa della run, conclusioni categoria con condizioni; nell'Handoff anche checkpoint e progressi disponibili | Dettagli delle aree/indagini tramite riferimenti; non rieseguire la discovery per ricostruire una sintesi già disponibile |
| Nuova categoria | Memoria trasversale della run, provenienza delle conclusioni e limiti di applicabilità; mappa e registry aggiornati | Checkpoint delle altre categorie e note role restano durevoli; non iniettare tutti i notebook category |
| Recovery tecnico / ricreazione Deps dalla stessa run | Ultimo checkpoint valido, output accettati e tool result persistiti, perimetro e stato aggiornati | Eventuale lacuna dall'ultimo salvataggio esplicita; nessuna promessa di recuperare thinking o output incompleti mai accettati |

La compression di Confirmer/Worker e i retry che mantengono la history non richiedono una riscrittura obbligatoria delle note. Recon resta breve e non acquisisce una compression dedicata per questa funzione. Nessun default rende automaticamente applicabile una vecchia ipotesi: il destinatario rivaluta le condizioni quando cambia lead, categoria o stato del target.

### P1.1 — Reader: continuità anche con zero notebook

1. **Conservare una narrativa d'area progressiva.** Usare `checkpoint_summary` dell'Exploration Reviewer come sintesi di ciò che resta utile per ripartire: percorso compreso, controlli e relativi limiti, tentativi/rami esclusi con motivazione, unknown e prossimo controllo. Fornire al Reviewer precedente sintesi e delta dell'epoch, con le ref che li sostengono. Non limitare la memoria a elenco di file letti o direttiva futura; non chiedere al Reader di riassumere una history già degradata.
2. **Colmare il ritorno dopo lead senza review.** Prima del reset persistere e reiniettare il checkpoint precedente insieme alla narrativa già accettata della lead e all'esito downstream scoped, marcati come aggiornamenti successivi al checkpoint. L'orchestratore assembla questi testi senza riscriverne il significato. La prossima review ordinaria li integra nella sintesi e sostituisce il delta già incorporato. Non aggiungere una review a ogni lead e non conservare tutta la traiettoria raw. Se manca una conclusione non espressa negli output, indicare il limite: nessuna ricostruzione dal thinking. Un nuovo campo è da valutare solo se la verifica dei payload dimostra una lacuna non coperta dalla narrativa esistente.
3. **Separare revisione e presentazione.** Ogni nuova epoch riceve la vista aggiornata prima della prima azione; un turno ordinario mantiene il prefisso stabile. L'area sospesa conserva il suo checkpoint e lo ritrova al ritorno. Lead e risultati recenti si collegano per ID autorevoli; un riepilogo vecchio non può sovrascrivere il lifecycle nuovo.
4. **Salvare prima di scartare.** Riutilizzare ArtifactStore/publisher e checkpoint per rendere durevoli sintesi e collegamenti prima del reset controllato. Se una nuova sintesi non è disponibile, conservare quella valida precedente e il delta accettato, rendendo esplicita la lacuna. Non azzerare la memoria né spendere retry semantici soltanto per ottenere una nota. Gli errori di persistenza seguono la policy infrastrutturale esistente.

### P1.1 — Ref pertinenti, senza aumentare la history

La selezione semantica spetta al modello; il runtime risolve riferimenti, deduplica per ID e applica i limiti di presentazione. Prima implementazione:

- Il Reviewer cita nel `checkpoint_summary` gli ID osservati necessari a sostenere le conclusioni e il prossimo focus. Riutilizzare questo testo: non aggiungere subito `important_refs`, punteggi o un DTO di evidenze. L'orchestratore riconosce solo corrispondenze esatte con il registry autorizzato; non risolve un nome di file ambiguo come se fosse un ID.
- Il briefing privilegia gli snippet delle ref citate nella memoria attiva e nelle direttive, poi le ref esplicitamente selezionate nell'ultimo output accettato della lead pertinente. La sola coda recente resta un fallback dichiarato quando manca una selezione semantica, non il criterio unico. Nessuna regola “file già letto = inutile” o ranking per numero di letture.
- Anche il Reviewer deve vedere i riferimenti selezionati nel checkpoint precedente e negli output accettati, oltre al delta recente: migliorare soltanto il prompt del Reader lascerebbe il problema a monte. Esporre un indice compatto delle ref nel suo snapshot scoped; se il contesto non basta, dichiarare l'omissione, senza attribuire al Reviewer accesso a snippet non presentati.
- Riutilizzare `read_source_ref` e le proiezioni metadata/snippet già presenti. Le ref non inline restano nel registry autorizzato con ID, file e range; rendere disponibile l'indice bounded, con accesso al resto quando necessario, affinché il Reader possa ritrovarle. Verificare la disponibilità effettiva di questo percorso per il Reader, senza assumere che basti quello Confirmer. Nessun nuovo motore di ricerca se il catalogo esistente copre il caso.
- Un budget complessivo per briefing contiene narrativa, snippet e catalogo dei notebook. Evitare copie dello stesso checkpoint in ledger/dossier e dello stesso snippet in più sezioni; mantenere metadata e accesso su richiesta per gli excerpt che non entrano. Non tagliare una condizione sorgente a metà presentandola come completa. Aumentare i limiti non è la soluzione iniziale.
- Ref sconosciuta, non autorizzata o non più disponibile: segnalazione esplicita e ultimo stato valido; niente evidenza inventata e niente retry dell'intero output per una selezione recuperabile. La memoria non amplia source scope o target HTTP e non converte automaticamente suspected in confirmed.

Se citare ID nella narrativa risulta insufficiente nella verifica, l'unica estensione da valutare è una lista piatta facoltativa di ID selezionati. Non introdurla preventivamente: i dati già presenti potrebbero ottenere la stessa semantica con meno campi.

### P1.2 — Altri ruoli e passaggio fra categorie

**Confirmer/Worker:** verificare innanzitutto le proiezioni effettive dei checkpoint e delle assignment. Riutilizzare summary di compression, self-checkpoint, verification plan e feedback Judge. La persistenza utile comprende anche tentativi negativi e perché non sono conclusivi, non soltanto la ricetta futura. Mostrare lo stato corrente di setup e transazioni al retry; non ripetere automaticamente azioni mutanti già registrate. Questa parte si coordina con la continuità Worker P0 del piano budgeting: non duplicarne l'implementazione. Stato autenticato e segreti rimangono nel dossier dedicato.

**Supervisori:** estendere la qualità del contesto in ingresso a tutti i ruoli, mantenendo i contratti tool-free. Le loro decisioni e motivazioni già accettate diventano parte della continuità dell'indagine, non notebook privati da riscrivere. L'editing volontario di appunti da parte dei supervisori è una capability diversa e resta P2.

**Recon/Handoff/categorie:** riutilizzare `category_notes`, `checkpoint_summary`, narrativa Handoff e `global_memory`. Passare all'Handoff i checkpoint e le conclusioni già disponibili, così che non debba recuperarli da un mero elenco di file e titoli finding. Quando esiste già un turno di sintesi, fornire la precedente memoria trasversale e il delta categoria e ottenere una revisione narrativa bounded: fatti riutilizzabili, condizioni, riferimenti canonici, incertezze e correzioni. Verificare prima se la narrativa Handoff esistente basta; soltanto se non basta aggiungere un unico testo piatto, senza copiare registry/coverage o chiedere metadata ricostruibili.

La memoria trasversale va pubblicata e mostrata alla categoria successiva; non affidarsi alla sola concatenazione seguita da taglio degli ultimi caratteri. Se il turno Handoff manca o fallisce, conservare la precedente sintesi valida e rendere visibile il delta accettato della categoria, entro il budget e con omissioni esplicite. Nessuna nuova chiamata dedicata a un “bibliotecario”; nessuna garanzia di sintesi aggiornata quando nessun modello ha potuto produrla. Usare mapping globali per lead/ref provenienti da Deps diverse, senza collisioni o accesso implicito a evidenze fuori scope. Restano consultabili le versioni categoria già persistite: la sintesi globale non è l'unica copia del fatto.

Un notebook `role` condiviso fra istanze non raggiunge ruoli diversi; un notebook `category` non raggiunge la categoria successiva. La memoria trasversale di default colma questo trasferimento attraverso gli handoff esistenti. Non serve subito un terzo notebook globale né copiare automaticamente tutte le note di categoria nel contesto comune. L'eventuale promozione di un appunto in una sintesi è una scelta semantica del modello nel turno già previsto.

### Verifica incrementale P1

Verifiche offline con snapshot sintetici, senza loop agentici o API:

1. Reader ritorna da una lead senza nuova review: il primo prompt contiene sintesi precedente, risultato accettato nuovo, esito downstream e focus; funziona senza note. Una review successiva incorpora il delta senza duplicarlo. Review fallita conserva l'ultima narrativa valida.
2. Ref essenziale più vecchia delle ultime 12 rimane selezionabile e inline quando citata; ref secondaria resta recuperabile. ID inventato/di altro scope non diventa evidenza; omissioni e limiti sono visibili. Verificare anche il contesto in ingresso al Reviewer.
3. Pivot A→B→A conserva conclusioni e unknown distinti; nuova lead non eredita un verdetto locale o precondizioni non verificate. Un errore tecnico non diventa conclusione di sicurezza.
4. Compression Confirmer e retry Worker preservano ragioni, esperimenti, ref e feedback senza replay HTTP; nuova istanza ricostruisce la memoria dalla stessa run. Non promettere recupero dei raw body oltre le garanzie reali dello store.
5. Passaggio categoria e fallback Handoff mantengono sintesi precedente, delta e provenienza; ref/lead globali sono risolte correttamente. Supervisor tool-free riceve la memoria pertinente senza acquisire un tool loop.
6. Salvataggio, reload e pubblicazioni successive preservano checkpoint e note; nessun leakage fra run. Il briefing rimane bounded e non ripete gli stessi contenuti in sezioni diverse.

Confronto semantico successivo, **da eseguire solo su richiesta esplicita perché richiede chiamate LLM**: poche lead correlate con reset e pivot, prima baseline vs continuità di default senza notebook, poi eventuale aggiunta dei notebook. Misurare riletture dello stesso range/revisione per la stessa domanda (distinguendole semanticamente dai controlli legittimi), input token, cache/costo, capacità di riprendere il prossimo controllo e correttezza dei finding. I conteggi di file e note sono diagnostici, non criteri automatici di stagnazione o qualità. La nuova selezione può aumentare `read_source_ref` riducendo letture ampie: confrontare costo e lavoro utile complessivi, non premiare semplicemente meno tool call.

**Prima consegna P1:** memoria Reader precedente + delta durevole, selezione ref dalla narrativa, briefing senza duplicazioni e verifiche dei reset. Nessun cambio a budget, numero di review o policy di chiusura delle aree. Solo dopo validazione estendere le proiezioni P1.2; il guadagno semantico non è dimostrabile con soli test offline.

## P2 — estensioni eventuali

- Riuso fra audit distinti dello stesso progetto: scelta esplicita, provenienza/source revision/ambiente visibili e note precedenti trattate come ipotesi da rivalutare; benchmark indipendenti isolati per default.
- Notebook condiviso fra ruoli e categorie, solo se memoria globale e ambiti category/role non coprono un bisogno osservato; nessuna copia automatica di tutte le note in ogni prompt.
- Editing volontario delle note da supervisori tool-free, se utile: valutare un unico testo opzionale senza tool loop né duplicazione di `category_notes`.
- Ricerca nel testo soltanto se dimensioni reali rendono insufficiente l'indice; retrieval semantico non è un prerequisito.

## File coinvolti e consegna

Intervenire nei punti esistenti: Deps per contesto/ambiti, ArtifactStore e publisher globale per persistenza, registrazione tool e briefing dei ruoli in triple_agent. Definire il minimo storage interno necessario; niente nuova gerarchia di memory manager, agente bibliotecario o sistema automatico di estrazione delle scoperte.

Prima consegna: due tool, due ambiti, hint scritti dall'autore e visibili automaticamente, corpi lunghi su richiesta e note brevi inline, aggiornamenti ai boundary, persistenza e test mirati. Aggiornare ARCHITECTURE.md insieme all'implementazione perché si aggiungono capability e memoria condivisa al loop. Nessuna modifica all'autenticazione, al checkpoint Confirmer, alle envelope o ai criteri di conferma in questo task.

Per P1 intervenire nei punti già individuati: `reader_area_dossier` e persistenza in `deps.py`; `_resume_reader_after_lead`, `_transition_reader_epoch`, `_exploration_snapshot`, `_checkpoint_from_review` e assignment/handoff in `triple_agent.py`; compression in `tools/compression_manager.py`; trasferimento categoria in `multi_category.py` e `global_run.py`. Il P0 notebook lascia invariato il checkpoint Confirmer; P1.2 può correggerne la proiezione solo per colmare una perdita verificata. L'aggiornamento di questo piano non modifica `ARCHITECTURE.md`: quel file va aggiornato quando i cambiamenti strutturali vengono implementati, per continuare a descrivere il runtime reale.
