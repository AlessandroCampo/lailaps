# Piano P0 — continuità investigativa, budget e verifica dinamica

Data: 15 settembre 2026.

Stato: piano di implementazione, nessuna modifica al runtime applicata da questo task.
Consolida le decisioni della conversazione e sostituisce, per questo P0, le alternative
di self-checkpoint Reader o agente intermedio. Le diagnosi di riferimento sono in
[global-coverage-review-20260915.md](global-coverage-review-20260915.md).

## Obiettivo e perimetro

Aumentare la copertura utile e il numero di ipotesi portate a una disposizione corretta,
evitando perdita di contesto, discovery impoverita dalle verifiche e interruzioni di
esperimenti ancora finanziabili. Velocità e cache sono misure secondarie.

Conservare i ruoli attuali: Recon, Reader, Exploration Reviewer, Confirmer, Worker e
Dynamic Judge. La novelty review resta distinta e non viene eliminata. Conservare
sequenzialità delle categorie e pausa del Reader mentre la sua lead viene verificata.

Il modello decide e racconta; l'orchestratore applica transizioni, attribuisce costi,
normalizza riferimenti e persiste. Riusare contratti narrativi, notebook, sessioni e
broker esistenti; niente nuovi agenti o registri di ipotesi paralleli al ledger.

## 1. Reviewer con history completa e Reader continuo

### Comportamento richiesto

Al boundary il Reviewer riceve la history disponibile dell'episodio Reader corrente,
con messaggi, tool call e risultati, oltre allo stato autorevole minimo necessario.
Lo snapshot di estratti non deve essere l'unica rappresentazione dell'indagine.

La copia è read-only rispetto alla conversazione Reader. Il Reviewer mantiene prompt,
output e ruolo propri; la history investigativa è contesto da valutare, non istruzioni
che sostituiscono il suo ruolo. Non eseguire nuovamente tool call storiche.

Usare una rappresentazione compatibile con i due modelli effettivi Reader/Reviewer:
conservare il contenuto investigativo disponibile, incluso quello restituito dal provider
quando utilizzabile, senza assumere che blocchi proprietari o reasoning firmato siano
trasferibili direttamente tra modelli. Nessuna promessa di trasferire stato interno
non restituito dall'API. Evitare duplicazioni fra history, estratti e dossier.

| Evento | Azione sulla history Reader |
|---|---|
| Review periodica, continue_current | Conservare e aggiungere soltanto decisione/direttiva |
| ReaderLead e ritorno da Confirmer/Worker nella stessa area | Conservare e aggiungere esito e nuove informazioni pertinenti |
| Approfondimento di funzione, middleware o ramo nella stessa area | Conservare |
| Pivot reale ad altra area | Salvare il checkpoint dell'area sospesa e aprire la conversazione dell'area di destinazione |
| Chiusura area o cambio categoria | Nuovo episodio con memoria pertinente al nuovo scope |
| Pressione reale di contesto | Compaction, conservando il significato dell'indagine |

Il verdetto del Reviewer viene persistito e aggiunto come nuovo aggiornamento alla
history originale del Reader: la conversazione del Reviewer non la sostituisce.
Mantenere stabili prompt e toolset Reader per favorire cache e continuità.

### Separare review e compaction

Rimuovere il reset automatico da continue_current e dal ritorno downstream nella stessa
area. Mantenere inizialmente frequenza delle review e criteri decisionali esistenti,
salvo chiarire che lavoro incompleto/stagnazione non è evidenza di assenza di vulnerabilità.

Dimensionare il contesto del Reviewer per ricevere la history Reader ammessa più il
proprio prompt/output: verificare anche il guardrail applicativo di input, non solo la
context dichiarata dal modello. I vecchi limiti dello snapshot non devono troncare
silenziosamente il nuovo percorso. Review periodica e soglia di context sono trigger distinti.

Alla pressione di contesto, usare il Reviewer con la history ancora disponibile per
produrre il checkpoint prima del reset. Nel testo devono rimanere ipotesi aperte,
evidenze, barriere ancora da verificare, tentativi esclusi e prossimo passo discriminante.
Riusare checkpoint_summary e riferimenti normalizzati; verificare se basta il contratto
esistente prima di aggiungere campi model-facing. Non introdurre un ulteriore summarizer
o un secondo agente solo per questo passaggio.

Un errore tecnico del Reviewer non deve distruggere la history ancora utilizzabile né
chiudere negativamente l'area. Se il limite tecnico impone uno stop, conservare lo stato
incompleto e la causa; nessun falso completamento di copertura.

### Punti di intervento

- `triple_agent.py`: ReaderConversationState, make_exploration_reviewer,
  _exploration_snapshot, _review_tranche, _apply_reader_review,
  _transition_reader_epoch e ripresa dopo lead/retry tecnico.
- `deps.py`: dossier, checkpoint e aggiornamenti di area esistenti.
- `context_budget.py`, config e proiezione messaggi: solo gli adeguamenti necessari
  a ricevere la history completa e separare supervisione da compaction.

## 2. Budget: discovery per categoria, verifica per lead, unico cap di sicurezza

### Contratto economico

```text
Categoria → allowance discovery determinata dal preset
    ReaderLead → nuova envelope Confirmer indipendente
        CandidateHandoff → nuova envelope Worker + quota Judge indipendenti
            Continuazione autorizzata → ulteriore tranche del ruolo

Tutti i consumi e impegni → cap globale della run
```

- small/regular/big/huge scalano soltanto la discovery della categoria.
- Recon di categoria, Reader, Exploration Reviewer, novelty review della discovery e
  handoff di categoria vengono attribuiti alla discovery, una sola volta.
- Global Recon viene addebitata una sola volta al cap di run come lavoro comune;
  non erode in modo nascosto l'allowance della prima categoria.
- Ogni categoria ha la propria allowance: la prima non può consumare quella delle altre.
- Confirmer, Worker, Judge e le rispettive compaction/estensioni vengono attribuiti alla
  lead e non sottraggono discovery alla categoria.
- Alla chiusura della lead si rilasciano gli impegni inutilizzati, mantenendo il consumo.
- Un preset piccolo significa meno ricerca, non verifiche individuali meno finanziate.

Non mantenere una seconda competizione nascosta fra discovery globale, quote Reader,
riserve future e ceiling di categoria. Eliminare dal percorso ordinario i vincoli economici
ridondanti che contraddicono questo contratto. Riusare il broker e l'accounting dove possibile;
ridurre il coordinatore globale al controllo del cap e degli impegni, senza fair-share
di un pool discovery comune. Il residuo discovery di una categoria non viene redistribuito
automaticamente nel P0.

Tutti i modelli operativi restano budget-unaware. Il preset e gli importi appartengono
all'orchestratore. Conservare contabilizzazione per modello, input cached/uncached e
output; i punti non sono USD e non sono raw token.

### Envelope e prosecuzione

La creazione di una ReaderLead ammette una tranche Confirmer sufficiente a un episodio
statico utile, incluso self-checkpoint/output. La promozione ammette una tranche Worker
che includa preparazione, autenticazione, baseline, test e output, più il relativo Judge.

Le continuazioni Confirmer usano il self-checkpoint già esistente; quelle Worker il
Dynamic Judge. L'orchestratore finanzia la tranche autorizzata se il cap globale lo
consente. Non aggiungere un nuovo supervisore per il budget.

Rimuovere il tetto basso di pipeline dinamiche come normale criterio di esclusione:
ogni candidate deve poter essere finanziato finché il cap globale lo permette. Conservare
ammissione e accounting idempotenti per evitare doppie assegnazioni e doppio conteggio.

La discovery termina per completamento semantico, proprio budget o cap globale; non
perché una verifica ha consumato fondi della ricerca. Anche le verifiche possono essere
interrotte dal cap globale: in tal caso restano sospette/incomplete con causa esplicita.
Le riserve di output/finalizzazione devono consentire un risultato durevole; overshoot
di una richiesta già ammessa va registrato, non nascosto.

### Calibrazione numerica

Decisione iniziale P0: **regular = 3.000.000 economic point effettivi di discovery per
categoria**. Confermare questo valore con margine generoso sulla base degli artifact
sotto; non presentarlo come media osservata o soglia già dimostrata sufficiente.

#### Evidenze dagli artifact

Importi ricostruiti da `report.categories[].telemetry.role_usage`: somma di
`category_recon`, `reader`, `reviewer` e `handoff`, quando presenti. Confirmer, Worker
e Judge sono esclusi dalla discovery. Importi arrotondati al punto; usare i consumi
registrati, senza ricalcolare gli artifact storici con il rate card odierno.

| Run / categoria | Discovery consumata | Risultato utile | Stato della discovery |
|---|---:|---|---|
| [YesWiki Injection, 25/08 18:55][budget-yw-0825] | 131.876 | 1 CVE del benchmark confermata dinamicamente su 6 | `discovery_complete`, ledger aree assente |
| [YesWiki Injection, 27/08 13:53][budget-yw-1353] | 274.383 | 2 CVE validate staticamente su 6; nessuna conferma dinamica | `discovery_complete`, 9 aree chiuse |
| [YesWiki Injection, 27/08 18:47][budget-yw-1847] | 351.301 | 3 CVE validate staticamente su 6; nessuna conferma dinamica | Budget esaurito; 4 aree chiuse, 1 attiva, 7 in coda |
| [YesWiki Injection, glm53flash-num][budget-yw-glm] | 348.653 | 1 CVE del benchmark confermata dinamicamente su 6 | Budget esaurito; 5 aree chiuse, 1 attiva, 5 in coda |
| [Kanboard Access Control, 30/08, run -3][budget-kb] | 350.065 | 2 finding confermati nel report; nessun TP nel benchmark | `insufficient_reader_tail_budget`, incompleta |
| [YesWiki global, 12/09, sola A01][budget-yw-global] | 1.327.950 | 1 finding confermato nel report della categoria; riferimento di consumo, non di recall | Budget esaurito; 3 aree chiuse, 1 attiva, 6 in coda |

[budget-yw-0825]: ../storage/app/runs/yeswiki/injection/yeswiki-injection-20260825-185505/yeswiki-injection-20260825-185505-outcome.json
[budget-yw-1353]: ../storage/app/runs/yeswiki/injection/yeswiki-injection-20260827-135352/yeswiki-injection-20260827-135352-outcome.json
[budget-yw-1847]: ../storage/app/runs/yeswiki/injection/yeswiki-injection-20260827-184729/yeswiki-injection-20260827-184729-outcome.json
[budget-yw-glm]: ../storage/app/runs/yeswiki/injection/yeswiki-injection-20260827-glm53flash-num/yeswiki-injection-20260827-glm53flash-num-outcome.json
[budget-kb]: ../storage/app/runs/kanboard/broken-access-contro/kanboard-broken-access-contro-20260830-103120-3/kanboard-broken-access-contro-20260830-103120-3-outcome.json
[budget-yw-global]: ../storage/app/runs/yeswiki/global/yeswiki-global-20260912-190529/yeswiki-global-20260912-190529-outcome.json

Il completamento dichiarato non prova una copertura esaustiva: le due Injection chiuse
lasciano rispettivamente 5 e 4 CVE del catalogo senza validazione statica. Anche due run
Kanboard dichiarano discovery completa a circa 143K e 291K, ma senza TP di benchmark:
non usarle come target di efficienza. Le run produttive fermate intorno a 350K danno
un riferimento di lavoro utile, non il costo necessario a completare la categoria.

I 3M scelti sono circa **8,5 volte** i 351K della Injection con tre validazioni statiche
e **2,26 volte** gli 1,328M della A01 lunga ancora incompleta. Quest'ultimo confronto
motiva il margine, senza assumere che più spesa risolva da sola i problemi di coverage.
Modelli, cache e flusso differiscono tra run; il Reviewer con history completa modifica
ulteriormente il consumo. Questi sono riferimenti empirici per il primo esperimento,
non una media statistica né una conversione in USD.

#### Importi da implementare

Conservare i moltiplicatori dei preset, applicandoli soltanto alla discovery:

| Preset | Discovery per categoria |
|---|---:|
| small | 1.500.000 EP |
| regular | 3.000.000 EP |
| big | 4.500.000 EP |
| huge | 6.000.000 EP |

Per completare la configurazione iniziale delle verifiche, usare tranche indipendenti
dal preset, con estensioni autorizzate semanticamente:

| Destinazione | Tranche iniziale | Tranche aggiunta alla continuazione |
|---|---:|---:|
| Confirmer, per lead | 500.000 EP | 250.000 EP |
| Worker, per candidate | 1.000.000 EP | 500.000 EP |
| Judge, quota per valutazione | 100.000 EP | Nuova quota da 100.000 EP per il voto dopo la continuazione Worker |

Riferimenti per queste envelope: nella conferma dinamica YesWiki del 25/08 la singola
lead consuma circa 253K Confirmer, 517K Worker e 14K Judge; nella run glm53flash-num,
circa 135K, 75K e 34K. La tranche iniziale dà quindi circa il doppio del maggiore
consumo statico/dinamico di questi esempi positivi. È un punto di partenza generoso,
non un massimo sufficiente per qualsiasi lead; i pesi storici differiscono e le
estensioni servono proprio agli esperimenti più lunghi. Il Worker finanzia anche
preparazione e routing; una continuazione deve finanziare lavoro utile e il successivo
Judge. Le quote non sono obiettivi di spesa: un episodio concluso restituisce il residuo.

**Unità del contratto:** tutti gli importi sopra sono EP effettivamente spendibili,
nella stessa unità di `economic_points` consumati. Non applicare un secondo
moltiplicatore di allowance basato sul modello: ad esempio, 1M Worker deve restare
1M anche con peso input 0,2, non diventare 200K. Conservare invece i pesi di pricing
nel calcolo del consumo dei token cached/uncached/output. Adeguare il grant esistente
a questa semantica senza introdurre un secondo sistema di punti.

**Cap globale iniziale: 100.000.000 EP per run**, unico tetto economico complessivo.
È una scelta prudenziale di configurazione, non un costo medio dedotto dagli artifact:
10 categorie regular impegnerebbero al massimo 30M di discovery, lasciando 70M per
Global Recon, verifiche ed estensioni; 10 huge lascerebbero 40M. Questo calcolo illustra
il margine, non introduce pool o riserve supplementari. Il vecchio cap 4,45M sarebbe
incompatibile anche con la sola discovery regular di dieci categorie.

Usare il parametro globale esistente; non moltiplicarlo per ogni lead e non introdurre
nel P0 un secondo budget USD o limite raw token cumulativo. Validare questi importi
nel confronto B/C: verificare qualità della copertura, consumo per fase e cause di stop
prima di ridurli. Il solo aumento dei finding o dei punti spesi non dimostra un guadagno.

### Punti di intervento

- `budget.py`, `global_run.py`, `multi_category.py`: allowance locali, cap comune,
  impegni e rimozione delle quote concorrenti obsolete.
- `config.py`, `triple_agent.py`: preset, admission e grant di ruolo.
- CLI, Laravel e testi UI esistenti: allineare soltanto semantica/parametri realmente
  esposti. Nessun redesign. Preservare artifact storici senza reinterpretarne i costi.

## 3. Worker prepara ed esegue; Judge autorizza la continuità

### Responsabilità

- Confirmer: validare staticamente catena, input controllabile, barriere e precondizioni;
  trasferire al Worker obiettivo del test, fatti verificati, riferimenti e unknown residui.
  Non dichiarare una conferma dinamica sulla sola analisi statica.
- Worker: completare il piano sull'istanza, recuperare routing/autenticazione necessari,
  preparare attori e oggetti, acquisire baseline, eseguire il test e presentare le prove.
- Judge: valutare ciò che le prove dimostrano, ciò che manca e se un nuovo esperimento
  può cambiare la disposizione. Non confondere setup incompleto con rejection.

Il piano del Confirmer resta un punto di partenza utile; non richiedere che scopra ogni
dettaglio operativo prima dell'handoff. Il Worker può leggere sorgenti e usare i tool
runtime già disponibili. Nessun agente intermedio e nessun nuovo framework di routing.

### Estensioni e history

Conservare l'append-only Worker per lead già implementato, comprese le riprese dopo
Judge e le evidenze di operazioni mutanti già eseguite. Il Judge aggiunge direttive;
non provoca un riavvio della verifica. La compaction resta legata al contesto.

Rimuovere il limite ordinario di tre requeue e il rifiuto basato sull'uguaglianza testuale
del next_step. La valutazione della ripetizione/progresso è semantica e spetta al Judge.
Un passo simile può diventare utile dopo aver risolto una precondizione.

Quando il Judge autorizza una continuazione, finanziare una nuova tranche Worker e
il voto successivo se il cap globale lo permette. I limiti per tranche sono occasioni
di checkpoint, non tetti cumulativi mascherati. Conservare i limiti dei retry tecnici
e dei singoli tool; non ripetere automaticamente richieste mutanti dopo errori di rete.

Distinguere nei motivi di stop: decisione semantica, cap globale, errore tecnico e
deadline esterna. Non usare un messaggio unico di retry non ammissibile per cause diverse.
Verificare che il timeout di processo realmente applicato dal launcher sia coerente con
la run configurata; non confonderlo con la deadline della singola richiesta LLM.

### Memoria operativa riutilizzabile

Dare al Worker la responsabilità esplicita di annotare nei notebook esistenti procedure
verificate e riusabili: percorso di login, campi necessari, sequenza osservata, route e
metodi funzionanti, precondizioni, limiti e riferimenti alle prove. Usare scope category
per condivisione fra ruoli nella categoria e role per i Worker delle categorie successive.

Riusare attori/sessioni e il catalogo automatico già disponibili. Credenziali e cookie
restano nei canali dedicati. Distinguere routing dichiarato dal codice e routing verificato
sull'istanza. Nessuna estrazione automatica di conoscenza tramite keyword, nessuna memoria
tra audit diversi, nessun nuovo archivio globale nel P0.

## 4. Sequenza di implementazione e verifiche

### Incremento A — continuità Reader/Reviewer

Implementare e verificare il blocco 1 mantenendo inizialmente invariati modelli,
frequenza di review e importi. Questo consente di isolare il cambiamento di contesto.

Verifiche offline mirate:

- Reviewer riceve una pista con il suo contesto anche se precede gli ultimi otto tool;
- continue_current non cancella né duplica la history Reader;
- ritorno downstream nella stessa area conserva history e aggiunge l'esito una volta;
- pivot/chiusura e pressione di context conservano checkpoint e transazioni corrette;
- fallback tecnico non chiude un'area come negativa;
- compatibilità della proiezione dei messaggi con modelli Reader/Reviewer diversi.

Usare il caso EraseSpamedComments come fixture di passaggio informativo, senza
hardcodificare nome del progetto, CVE o decisione nei prompt produttivi. Un test simulato
dimostra che la pista arriva al Reviewer, non che il modello la giudicherà correttamente.

### Incremento B — budget separati

Implementare blocco 2 e ammissione delle estensioni. Verifiche offline con uso sintetico:

- Confirmer/Worker/Judge non riducono discovery;
- consumo della prima categoria non riduce allowance delle successive;
- small e huge cambiano discovery ma non envelope della stessa lead;
- gli importi sono EP effettivi: un grant da 1M resta 1M anche con peso modello 0,2;
- una quinta pipeline e una quarta estensione possono partire quando finanziabili;
- cap globale, impegni, rilascio del residuo e overshoot non producono doppio conteggio;
- Global Recon è addebitata una volta; esaurimento globale conserva il report incompleto.

Separare nei risultati il cambio di meccanismo dal cambio di importi.

### Incremento C — preparazione Worker e memoria

Implementare blocco 3 sul percorso esistente. Verificare offline una sequenza in cui
il primo login non completa l'autenticazione, emerge il percorso corretto e il Judge
autorizza la prosecuzione dopo tre estensioni, conservando prove/sessione senza duplicare
operazioni. Verificare isolamento degli scope notebook e disponibilità della procedura
al Worker successivo.

### Validazione con modelli reali — successiva e separata

Non eseguire autonomamente agent loop, benchmark lunghi o chiamate API a pagamento.
Dopo autorizzazione, confronto controllato di poche aree/candidate sugli stessi sorgenti,
modelli e configurazioni, poi una global come verifica complessiva. Conservare una
baseline con incremento A soltanto prima del confronto con B/C e budget più generosi.

Misure principali: ipotesi conservate ai boundary, casi raggiunti trasformati in lead,
disposizioni corrette, aree risolte con motivazione ed esperimenti discriminanti completati.
Misure secondarie: riletture, turni, cache, costo completo quando disponibile e durata.
Non dichiarare successo dal solo aumento di lead, file letti o aree closed.

## 5. Documentazione e completamento

Durante l'implementazione aggiornare ARCHITECTURE.md dopo ciascun incremento strutturale:
history Reader/Reviewer, nuovi confini economici, grant semantici e ruolo operativo Worker.
Non descrivere oggi il piano come architettura già implementata.

Aggiornare solo documentazione/configurazione e test esistenti interessati. Mantenere la
history completa in memoria secondo il contratto corrente: nessuna nuova persistenza di
raw transcript provider, prompt o body HTTP. Proiettare nei report contatori e cause
necessari a verificare P0; nessun progetto collaterale di telemetria.

Eseguire controlli focalizzati senza lint dell'intero progetto. Prima della consegna,
deletion pass su quote obsolete, reset duplicati e helper introdotti: mantenere soltanto
ciò che serve ai comportamenti sopra. Segnalare separatamente ciò che resta da validare
con inferenza reale.

## P1+ esclusi

- Rimozione del Reviewer e self-checkpoint Reader come decisore unico.
- Ricerca organizzata per superficie al posto delle categorie.
- Categorie/superfici concorrenti o Reader indipendente dal downstream.
- Agente di preparazione intermedio o inventario universale delle route.
- Cambio modello/provider, ottimizzazione della capability Gitea, tuning aggressivo cache.
- Correzioni indipendenti di Semgrep/Joern, memoria fra run e redesign UI.

Questi interventi possono essere valutati dopo aver misurato il P0. Non sono prerequisiti.
