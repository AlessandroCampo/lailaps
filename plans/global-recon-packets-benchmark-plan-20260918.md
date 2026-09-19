# Lailaps — Global Recon, Reader concorrenti e Investigation Packet

Data: 18 settembre 2026. Stato: proposta implementativa da discutere; pipeline non modificata e nessun agent loop avviato.

## 1. Decisione e obiettivo

Mantenere e potenziare Global Recon. Misurare prima se individua superfici che permettono di raggiungere il codice vulnerabile; successivamente misurare se Reader indipendenti trasformano quelle superfici in domande investigative utili e se i Confirmer ne ricavano vulnerabilità staticamente sostenute.

Il risultato richiesto è più vulnerabilità distinte trovate entro tempo e costo prefissati. Numero di aree, packet, file letti e token sono misure diagnostiche, non obiettivi di qualità.

Il piano procede per fasi separatamente verificabili:

| Fase | Perimetro | Artifact finale | Domanda verificata |
|---|---|---|---|
| P0 | Global Recon con repetitions | Mappa architetturale, aree e locator | Dove cerchiamo comprende le vulnerabilità note? |
| P1 | Reader concorrenti su Recon congelata | Stream durevole di Investigation Packet | Raggiungiamo il codice e formuliamo le domande corrette? |
| P2 | Confirmer su packet congelati, senza Worker | CandidateHandoff, chiusure e casi irrisolti | Riconosciamo correttamente le vulnerabilità e cosa passeremmo al Worker? |
| P3+ | Deduplica semantica e integrazione dinamica | Finding confermati con prova HTTP | Quanta ricerca utile si converte in conferme? |

La frase «fermiamoci a Reader per vedere cosa viene confermato staticamente» comprende due esperimenti diversi: P1 termina al packet; P2 esegue Confirmer e termina prima di Worker. Nessuna conferma statica va attribuita al solo output Reader.

## 2. Evidenza che modifica la diagnosi precedente

Nel checkout analizzato:

- `_run_category_recon()` in `triple_agent.py` applica `min(2, settings.category_recon_request_limit)` alla global. Il limite configurato è 8, ma la global riceve due richieste investigative più la terminalizzazione.
- Con Codebase Memory, la prima richiesta Recon può usare soltanto `codebase_architecture`. Una richiesta può contenere più tool call: due richieste non significano necessariamente due tool call.
- `category_recon_tools()` espone architettura, grafo, directory, ricerca e note. Mancano `read_file`, `list_surface_signals`, recupero output e PTC. La Recon riceve un inventario statico iniziale, ma non può consultare liberamente i singoli segnali tramite quel tool.
- `CategoryReconBase` e `CategoryReconReady` limitano l'output a 12 aree. Le due run del 17 settembre registrano tre richieste Recon totali, coerenti con due investigative e una finale.
- YesWiki contiene 12 aree senza `paths`. I simboli e la narrativa contengono comunque indicazioni utili, fra cui CalcField. L'assenza del path strutturato non equivale all'assenza della superficie.
- `list_surface_signals(scope="active_area")` filtra esclusivamente sui path dell'area. Un'area con soli simboli e `paths=[]` non riceve segnali tramite questo filtro. `scope="all"` esiste, ma richiede un recupero intenzionale del Reader.
- L'evaluator Recon attuale assegna credito elevato a un file aggregatore o a una corrispondenza testuale del simbolo; può invece non riconoscere un componente nominato in `next_check`. I precedenti 2/12 e 3/6 sono punteggi di questa euristica, non una dimostrazione semantica delle omissioni.
- `run_events.emit_event()` e `model_trace.append_trace()` sono attualmente no-op. Outcome e transcript restano disponibili, ma non esiste già una timeline strutturata utilizzabile solo aggiungendo un filtro UI.

Questi elementi giustificano un esperimento Recon meglio finanziato. Non dimostrano ancora che aumentare le risorse migliori il recall, né che gli investigation packet siano la causa dei risultati migliori del progetto di riferimento. I confronti precedenti non hanno isolato modello, prompt, tool e orchestrazione.

## 3. P0 — Global Recon benchmark

### 3.1 Riutilizzo e correttezza del percorso

Estendere `benchmark:recon` e il comando Python `recon` con modalità globale e repetitions. Usare il medesimo prompt, toolset e budget effettivo del percorso Global Recon di produzione; passare `global_mode` esplicitamente. La stringa di categoria non deve simulare la modalità globale.

Unire nell'evaluator privato i casi dei manifest del target, verificando stesso snapshot e identità dei casi. Il modello riceve soltanto contesto pubblico, sorgente autorizzato e strumenti ordinari. Ground truth, CVE, fixture di valutazione e giudizi delle ripetizioni precedenti restano esclusi.

Correggere l'override: Laravel oggi presenta `--reader-model` come modello Recon, mentre Python seleziona `--recon-model` o il modello Recon configurato. Esporre e propagare il modello del ruolo corretto; persistere modello e reasoning effettivi.

Prima delle run misurate, rendere affidabile il timeout del runner usato dal benchmark: terminazione dell'agente/container, attesa della fine delle scritture e solo dopo cleanup del sorgente e valutazione dell'artifact. Conservare l'ultimo risultato durevole; nessun report finale riscritto dopo il cutoff. Registrare durata di bootstrap e inferenza separatamente. Questo protegge l'esperimento, non è una soluzione al basso recall.

### 3.2 Investimento iniziale proposto

Profilo sperimentale globale iniziale, da registrare integralmente nell'artifact:

| Risorsa | Oggi | Proposta iniziale |
|---|---:|---:|
| Richieste investigative | 2 effettive in global | 32 |
| Output massimo per risposta | 12.000 token | 24.000, compatibilmente con modello/context |
| Envelope Recon configurata | 160.000 EP | 640.000 EP |
| Aree massime | 12 | 48, senza minimo richiesto |
| Tempo | dipende dal timeout esterno | cutoff Recon proposto di 20 minuti, bootstrap contabilizzato a parte |

Sono valori di partenza per un esperimento, non stime del fabbisogno né promesse di completamento. Verificare il budget realmente ammesso, inclusi terminalizzazione, context fitting e limite totale: aumentare solo `max_tokens` non aumenta i turni e aumentare i turni non supera il limite economico. L'aumento Recon va contabilizzato anche nel futuro costo end-to-end; non moltiplicare automaticamente il cap totale per dieci.

Il modello può concludere prima. Se termina per limite, il report espone mappa parziale e lavoro residuo, senza dedurre completezza. Non chiedere 30 aree obbligatorie: 18 aree utili possono essere migliori di 40 sovrapposte. Quaranta aree reali non vanno compresse artificialmente a dodici.

### 3.3 Tool e contesto

Riutilizzare acquisizione, controlli scope e provenienza esistenti:

- consentire `read_file`, `list_surface_signals(scope="all", ...)` e `inspect_tool_output` alla Recon;
- mantenere architettura, ricerca testuale, directory e grafo, senza obbligare la prima richiesta a un unico tool;
- riusare `run_code` con i binding statici `search_source` e `read_file`, verificando che autorizzazione, accounting e output store non assumano il ruolo Confirmer;
- eseguire indice e analisi statica una volta per snapshot e renderli consultabili; non ricostruirli per ogni futura area;
- distinguere inventario completo, esempi mostrati, dati consultati e limiti del sensore. Un risultato acquisito dal programma ma non presentato al modello non conta come analizzato.

Non introdurre un nuovo motore di ricerca o un nuovo AST service in questa fase. LSP e data-flow aggiuntivi vanno valutati solo dopo aver verificato l'utilità dei tool già disponibili.

### 3.4 Compito e output Recon

Recon costruisce una mappa del prodotto: architettura, attori, entrypoint, asset, confini di fiducia, operazioni sensibili e zone da esplorare. Deve includere anche controlli di autorizzazione, transizioni di stato e configurazione: non tutte le vulnerabilità possiedono un sink riconoscibile da Semgrep.

Le aree sono mini-incarichi orientati a funzionalità o confini: sufficientemente focalizzati da essere assegnabili a un Reader, con possibilità di seguire helper e caller nel resto del repository autorizzato. Non sono claim di vulnerabilità né ACL sui file leggibili.

Riutilizzare i campi attuali: `checkpoint_summary` per l'architettura comune; `title`, `paths`, `qualified_names`, `next_check` per le aree; `unknowns` per limiti reali. Conservare linee, simboli, direzione della ricerca e domande nella narrativa di `next_check`, evitando nuovi DTO annidati. L'orchestratore verifica e collega locator e riferimenti già osservati.

Un riferimento generico a `routers/api/v1/api.go` è una copertura ampia e debole. Un'area che individua le route organization/labels e propone il confronto dei permessi con le operazioni sorelle è più utile. Entrambe sono ammesse, con qualità diversa registrata nell'analisi.

Per ogni famiglia consultata, conservare esempi rappresentativi e locator recuperabili; non trasferire a mano tutti i risultati SAST. Le aree senza path devono mantenere simboli risolvibili o una domanda di localizzazione esplicita. Risolvere deterministicamente i simboli quando il backend fornisce un risultato univoco; non trasformare nomi ambigui in path inventati. Nei consumer, locator mancanti non devono presentare la vista filtrata vuota come assenza di segnali: mostrare il limite e offrire la ricerca globale.

Primo esperimento senza nuova checklist OWASP. In un secondo braccio, valutare un richiamo finale e facoltativo ai confini/famiglie trascurati. Nessun ritorno a dieci scansioni categoriali e nessuna quota di aree per categoria.

### 3.5 Valutazione e repetitions

Prima rivalutare semanticamente offline le Recon globali già salvate. Poi proporre tre ripetizioni indipendenti per target con il nuovo profilo. Sono esecuzioni a pagamento da avviare esplicitamente, non test da eseguire durante l'implementazione. Tenere stessi sorgenti, modelli, reasoning e sensori tra ripetizioni; distinguere bootstrap/cache calda. Tre ripetizioni sono una prima misura di variabilità, non una prova statistica forte.

Preparare una tabella privata per caso, compilata durante la nostra analisi:

1. componente/anchor esplicitamente localizzato;
2. area più ampia ma domanda che conduce ragionevolmente al caso;
3. sola famiglia generica, da cui non discende un percorso investigativo preciso;
4. superficie assente o fuorviante.

Citare area e testo che giustificano ogni assegnazione. Un componente citato nella narrativa vale come informazione; un intero albero del repository non produce recall perfetto. Le intersezioni automatiche path/range preparano il dossier, ma non decidono il valore semantico. Non introdurre un nuovo LLM evaluator online.

Misurare anche ampiezza dei locator, sovrapposizioni, correttezza dell'architettura, ranking, costo e tempo. Il recall delle aree non è il recall dei finding. Riportare tutti i risultati delle repetitions: media, minimo e casi intermittenti. Non selezionare una "golden Recon" in base al ground truth e presentarla come resa media.

Criterio decisionale: se restano soltanto famiglie generiche o mancano ripetutamente intere superfici, correggere questo stadio prima di aumentare il numero di Reader. Se la mappa fornisce punti di partenza plausibili e il Reader poi li ignora, spostare l'intervento su discovery/scheduling. Non imporre un recall del 100% come prerequisito alla fase successiva: i limiti residui vanno identificati e misurati.

## 4. P1 — Reader indipendenti e stream di Investigation Packet

### 4.1 Incarichi e concorrenza

Congelare una Recon e riusarla identica per confrontare i Reader. Selezionarla con regola stabilita prima dello scoring, per esempio la prima ripetizione tecnicamente valida; usare poi un'altra Recon per verificare robustezza. Un test condizionato su un'unica mappa non dimostra la resa dell'intera pipeline.

Creare un incarico logico per ogni area. Partire con quattro Reader attivi contemporaneamente; gli altri attendono in coda. Verificare due incarichi nello smoke tecnico offline e valutare successivamente 8, poi eventualmente 30 concorrenti. Il limite di concorrenza si misura contro throughput, latenza, errori provider, memoria e serializzazione dei backend condivisi.

Ogni Reader ha history, ruolo attivo, area, output store, note investigative, source-ref e contabilità isolati. Sorgente, indice e inventario statico sono condivisi in lettura. L'orchestratore è l'unico proprietario di queue, budget globale, ID e pubblicazione dell'outcome. Non avviare trenta istanze complete di `TripleAgentOrchestrator` con budget e ArtifactStore condivisi accidentalmente.

Ogni incarico riceve architettura comune concisa, area completa, locator/segnali pertinenti e indice breve delle altre aree, senza replicare l'intero dossier globale in ogni richiesta. Può seguire dipendenze, wrapper, caller e operazioni sorelle nell'intero sorgente autorizzato. Può produrre packet su scoperte laterali e segnalare lavoro residuo; non deve chiedere permesso a un Reviewer per leggere fuori dai path indicativi.

### 4.2 Budget e termine dell'incarico

Un solo budget totale Reader, esplicito e registrato per il confronto. La prima quota di ogni incarico è ricavata da quel totale e dal numero di incarichi; riservare quota anche agli incarichi ancora in coda e alla loro finalizzazione. Non concedere a ciascuno il budget globale attuale. Riportare incarichi non partiti e investigazioni interrotte dal limite separatamente dalle conclusioni semantiche.

Il Reader conclude autonomamente l'incarico con un riepilogo di controlli osservati, piste risolte e domande aperte. Nel nuovo percorso non passa periodicamente dall'Exploration Reviewer e non necessita dell'approvazione di chiusura dell'area. Budget, deadline e capacità del contesto restano limiti di risorse, non euristiche di sicurezza basate su conteggi di righe o chiamate ripetute.

Conservare la history dopo l'emissione di ogni packet. Riutilizzare la compaction esistente solo quando necessaria; il checkpoint conserva fatti, controevidenze e domande, non trasforma una verifica parziale in area sicura. Packet già emessi restano persistiti e recuperabili.

### 4.3 Cambiamento sostanziale da ReaderLead a InvestigationPacket

Il prompt Reader attuale ammette già lead incomplete e pattern variabili con flusso parziale. Il solo cambio di nome non abbassa una soglia che oggi sia necessariamente alta e non garantisce maggiore proficiency.

Il nuovo contratto rende esplicito che il packet è una domanda investigativa source-backed, non una vulnerabilità dichiarata. Il minimo è codice reale consultato, operazione/controllo rilevante, possibile influenza dell'attore o boundary ancora da chiarire, e una domanda discriminante. Non richiedere prova dell'assenza di mitigazioni, intero attack path, payload o piano HTTP.

Non limitare i packet ai sink: una discrepanza fra permessi di operazioni sorelle o una transizione di stato è una base altrettanto valida. Non emettere un packet per ciascun match SAST se manca una domanda di sicurezza specifica. Includere le protezioni già osservate, anche quando potrebbero confutare la pista.

Interfaccia model-facing proposta: `submit_investigation_packet(title, investigation)`. `investigation` è un testo narrativo contenente osservazioni sorgente con locator, input/attore noto o incerto, operazione, controllo atteso/osservato e prossimo dubbio da risolvere. L'orchestratore collega source-ref, area, run e identità. Verifica la corrispondenza ai sorgenti osservati senza attribuire automaticamente al packet tutte le letture della sessione. Riferimenti ambigui restano espliciti; eventuale correzione mirata non deve perdere il resto dell'output.

Riutilizzare l'archivio di stage output per conservare il packet prima dell'ack. Il tool restituisce l'ID assegnato e il Reader prosegue nella stessa conversazione. Questo è uno stream di artifact acquisiti, distinto dallo streaming di token non ancora validi. La redelivery della stessa invocazione non crea una seconda occorrenza; proposte semantiche simili di Reader diversi rimangono distinte fino a P3.

Nel percorso sperimentale escludere Novelty Reviewer e routing inline a Confirmer/Worker. I packet non aumentano automaticamente `suspected` del report di audit. Registrare un output type esplicito `InvestigationPacket` e adeguare solo i consumer necessari; leggere i vecchi `ReaderLead` attraverso i contratti esistenti, senza migrare outcome storici o fabbricare campi di certezza mancanti.

Dare anche al Reader il PTC statico riusato da P0. Prospettive investigative forward, backward, confronto fra operazioni sorelle e invarianti sono istruzioni selezionabili secondo la domanda, non nuovi ruoli obbligatori.

### 4.4 Rumore e valutazione

Accettare packet che si riveleranno non vulnerabili. Non fissare come target 200 packet o 90% di rigetti. Un packet ben fondato può concludersi correttamente con un controllo efficace; un testo generico privo di evidenza costa invece un Confirmer senza aggiungere copertura.

Per ogni caso benchmark, distinguere privatamente:

- file/anchor letto dal Reader;
- anchor citato in un packet;
- packet che formula la domanda pertinente alla vulnerabilità;
- stesso caso presente in più packet.

Conteggiare una CVE una sola volta. Leggere anche packet non abbinati e un campione dei controlli esclusi dai Reader: assenza di match non significa falso positivo e una pista può essere scartata prima dell'emissione. Conservare i riepiloghi finali per ricostruire queste esclusioni.

La revisione semantica iniziale è nostra, offline e sostenuta dalle prove; automatizzare soltanto raccolta di locator, statistiche e preparazione degli artifact. Per centinaia di packet, esaminare tutti i possibili match benchmark, poi un campione distribuito su ogni Reader, includendo anomalie e famiglie diverse. Dichiarare la parte non revisionata: non dedurre precisione globale da un campione scelto fra i migliori.

Misurare casi distinti con domanda pertinente entro 15/30/60 minuti di discovery, token/EP per caso, packet per area, duplicati stimati e costo previsto della validazione. Riportare sia tempo Reader-only sia tempo totale con bootstrap e Recon. Il totale token include input ripetuto e cache: non equivale a codice nuovo analizzato.

Prima verifica del nuovo percorso con stessa mappa e modello; poi confronto con Reader attuale su quella stessa mappa, se tecnicamente supportabile. Il cambio di contratto è parte della variante: valutare entrambi sul medesimo criterio semantico, senza confrontare il numero grezzo di lead con il numero di packet. La variante senza Recon o un Reader baseline indipendente sono ablation successive, non requisiti P0.

## 5. Osservabilità — prima dei Reader concorrenti

### Vista operativa proposta

Usare la console esistente con due livelli:

- riepilogo run: fase, tempo, budget totale, aree queued/running/finished/interrupted, packet acquisiti ed errori;
- elenco Reader: area, stato, tempo attivo e in coda, ultima attività, richieste/costo e numero di packet; selezionando un Reader si apre soltanto il suo transcript con link ai packet.

Selezionare un Reader modifica soltanto la vista. Non sospende gli altri. Filtri per ruolo, area, incarico e packet; collegamento successivo Reader → packet → Confirmer. Non usare "30/30 aree finite" come percentuale di sicurezza del repository.

### Persistenza e implementazione minima

Mantenere outcome come snapshot autorevole e un solo log canonico per run. Introdurre nel log record strutturati compatti per attività e correlazione, separati visivamente dal transcript umano. Ogni record acquisisce dal runtime timestamp UTC, sequence, run/pass, incarico/area, ruolo, eventuale packet, tipo ed eventuale request/tool-call ID. Una sola funzione di emissione serializza i record; la UI non deve inferire l'incarico dall'ultimo prefisso stampato.

Registrare start/end incarico, start/end chiamata, tool result o errore, packet persistito, compaction, fine per budget/cancellazione e finalizzazione run. Conservare la normale redazione di segreti; niente wire dump indiscriminati. Per ogni packet: testo originale, riferimenti sorgente, cronologia delle decisioni e locator nel transcript. Il confronto semantico deve poter tornare alle prove, non solo al riepilogo.

`emit_event` attuale non implementa questo trasporto: ripristinare o sostituire il piccolo punto di emissione necessario, senza costruire un event bus, un nuovo database o trenta archivi raw duplicati. Correlazione legata al contesto dell'incarico e request ID, mai a campi globali mutabili fra task.

Un solo writer/coordinatore aggiorna outcome ed evidenze; le scritture dirette di ArtifactStore devono rispettare la stessa ownership. Incrementi, packet ed evidenze già persistiti non possono essere persi da uno snapshot concorrente. Pubblicare gli aggiornamenti significativi, evitando di riserializzare tutti i sorgenti a ogni token.

Fin dal primo smoke, il log deve essere filtrabile per incarico anche senza UI. Con la concorrenza attiva, la console mostra di default il riepilogo; i dettagli rimangono leggibili e recuperabili. Per P0 basta il transcript Recon corrente con limiti effettivi e tempi visibili; il pannello multi-Reader appartiene a P1.

## 6. P2 — Confirmer sui packet congelati

Riutilizzare il benchmark Confirmer isolato, adattando input e selezione ai nuovi packet. Oggi il selector cerca `ReaderLead` e dataset con label note: la nuova campagna deve poter selezionare esplicitamente i packet della run, inclusi quelli senza etichetta, senza filtrare i positivi tramite ground truth.

Ogni Confirmer riceve packet e prove del suo autore, può leggere il sorgente di supporto e cerca controevidenze. Produce:

- `CandidateHandoff`: vulnerabilità staticamente sostenuta e verifica dinamica proponibile;
- chiusura con barriera o controevidenza concreta;
- irrisolto per mancanza di prova, prerequisiti o risorse;
- failure tecnico separato.

L'assenza di conferma non diventa automaticamente rejection. Un sink ordinario protetto non va promosso per aumentare il conteggio. La persistenza lega ogni decisione all'esatto packet congelato, senza aggiornare retroattivamente il testo del Reader.

Confirmer può lavorare in un pool separato, inizialmente fino a quattro incarichi attivi, con cap totale di campagna oltre all'envelope per packet. Duecento packet con envelope piena ciascuno possono moltiplicare il costo di decine di volte. Il default attuale isolato è 350.000 EP per decisione: 200 envelope ammesse implicherebbero fino a 70 milioni EP, prima di Worker.

Per la prima campagna ammettere i packet in ordine di emissione, registrando gli esclusi per budget. Se si valuta soltanto un sottoinsieme, riportare la resa condizionata e il recall perso nella coda, senza attribuire il risultato all'intero insieme. Un campione scelto manualmente perché contiene CVE misura una capacità diagnostica, non il rendimento end-to-end.

Il test termina prima di qualsiasi Worker. Misure: vulnerabilità distinte sostenute, chiusure corrette, casi noti rigettati erroneamente, irrisolti, costo per decisione, tempo al primo candidato valido e recall a cutoff. Più di quattro output non è successo se sono duplicati o non supportati.

Nella successiva integrazione streaming, i Confirmer consumano i packet già acquisiti mentre i Reader continuano. Il primo benchmark resta su artifact congelati per poter attribuire errori e costi allo stadio corretto.

## 7. P3+ — miglioramenti successivi

- **Deduplica semantica prima dei Confirmer**, se il costo misurato dei duplicati la giustifica. Conservare occorrenze e prove originali; raggruppare per stessa domanda/controllo e possibile causa, senza fondere operazioni indipendenti soltanto per file o CWE. Ambiguità mantiene i packet separati. Misurare le false fusioni, che riducono recall. L'idempotenza della stessa invocazione esiste già in P1 e non richiede LLM.
- **Baseline Reader indipendente o esplorazione delle lacune Recon**, con budget tratto dal totale; confronto separato per misurarne il contributo.
- **Hint OWASP finale**, una variante di prompt controllata, senza cambiare simultaneamente modello e budget.
- **Worker dinamici**: inizialmente seriali sullo stesso target. La concorrenza dei Reader/Confirmer read-only non dimostra che siano sicuri test HTTP simultanei: utenti, DB, fixture e stato applicativo possono interferire. Parallelizzare Worker soltanto con isolamento reale dei target o mutazioni dimostrate indipendenti; mantenere Judge e prove per packet.
- **Concorrenza Reader superiore a 4–8** se il provider e i backend aumentano il throughput utile a costo accettabile.

## 8. Ordine implementativo e verifiche

1. P0: runner/finalizzazione, global Recon benchmark e override modello corretto; budget/tool/contratto Recon; dossier offline e repetitions.
2. Valutazione delle mappe; scelta esplicita dell'artifact congelato per P1.
3. P1: contratto InvestigationPacket e persistenza; isolamento/budget/writer; pool Reader e termine autonomo; osservabilità minima. Integrare i pezzi con due Reader finti prima di quattro reali.
4. Revisione semantica dei packet e delle esclusioni Reader.
5. P2: selezione packet senza label privilegiate, adapter Confirmer, cap della campagna e risultati collegati. Nessun Worker.
6. Decisione su P3 basata sul costo e sulle perdite osservate.

Test tecnici offline mirati: global flag e modello effettivo; limite Recon effettivo; tool disponibili e provenienza; round-trip di una Recon con 30+ aree; locator senza path; due Reader concorrenti con ID locali uguali; nessuna contaminazione di history/note/source-ref; reservation budget concorrenti; packet conservato dopo cancellazione; redelivery idempotente; writer senza lost update; filtro transcript per incarico; nessun Confirmer/Worker avviato in Reader-only; nessun Worker in Confirmer-only; niente container orfani né scritture tardive.

Per integrazioni che richiedono un binario reale, usare fixture locali piccole senza rete o inferenza. Nessun lint globale. Le run LLM e le repetitions descritte nel piano non sono autorizzate automaticamente dall'implementazione.

Aggiornare `ARCHITECTURE.md` insieme a ciascun cambiamento effettivo a ruoli, tool, budget, concorrenza, output e lifecycle. Questo documento è una proposta; non sostituisce la fotografia dell'architettura corrente.

## 9. Interpretazione degli esiti

Per ogni vulnerabilità nota ricostruire la prima perdita osservabile:

`superficie disponibile → area Recon → lettura Reader → packet pertinente → candidato statico → prova HTTP`

Letture, menzioni e ipotesi non sono automaticamente finding. Una vulnerabilità assente dalle aree ma trovata dal Reader è recupero esplorativo; presente nelle aree ma mai letta è un problema di instradamento/discovery; letta e scartata è una decisione investigativa da riesaminare; packet valido rigettato dal Confirmer è una perdita downstream.

Usare questo percorso e la curva dei casi distinti nel tempo per decidere dove investire. Se aumenta solo il numero di packet e non migliorano le domande pertinenti o i candidati sostenuti a costo prefissato, il piano non ha risolto il problema: aumentare ulteriormente repetitions, aree o concorrenza non costituisce una risposta.
