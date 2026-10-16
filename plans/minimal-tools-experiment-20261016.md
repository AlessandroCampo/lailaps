# Piano: minimal come unica implementazione su un branch dedicato

## Obiettivo e vincolo

Verificare se un catalogo di primitive generali permette di mantenere la qualità
dell'audit riducendo complessità, errori operativi, token e costo di esecuzione.

Branch implementato: `minimal`. Sul branch vive soltanto minimal: nessun profilo
`classic`, feature flag, doppia registrazione o fallback agli strumenti rimossi.
La versione precedente rimane recuperabile tramite un riferimento Git e un'immagine
conservata per riferimento. P0-S/A/B/C/D sono implementati; le verifiche locali e i
limiti sono registrati in `plans/minimal-implementation-20261016.md`. La matrice con
modelli rimane da eseguire esplicitamente: questo documento non ne attesta gli esiti.

Riferimenti: `08a0f5a` fissa lo stato iniziale; `3435bb3` fissa il catalogo precedente
con SQL già esteso. L'immagine storica conservata non è stata ricostruita da questi
commit: per il confronto controllato occorre costruire l'immagine da `3435bb3` e
congelare gli input elencati sotto. Le rimozioni A-D sono consegnate insieme nel
commit finale, quindi la separazione per rischio sotto rimane una guida di analisi,
non una serie di benchmark o commit intermedi già disponibili.

Il criterio di eliminazione è la sostituibilità semplice, anche per tool molto usati.
L'inutilizzo è un indizio aggiuntivo, non il criterio principale.

## P0 — semplificazione progressiva con un solo percorso attivo

I passaggi P0-S e P0-A/D sono commit successivi dello stesso branch, non profili
selezionabili. Ogni passaggio mantiene il runtime funzionante. Il rischio viene
valutato dalla sostituzione concreta e dalle dipendenze, non soltanto dalle chiamate
storiche: un tool a zero che gestisce autenticazione non è automaticamente innocuo.

### 1. Fissare il punto di partenza

La working tree attuale, sul branch `worker-confirmer-merge`, contiene modifiche
non committate e sorgenti nuovi necessari al browser e all'investigatore unificato.
`HEAD` da solo non descrive quindi la baseline desiderata.

- Fissare in un commit revisionato lo stato applicativo necessario alla baseline,
  includendo i sorgenti nuovi pertinenti e rispettando tutte le modifiche presenti.
  Nessun `reset`, stash indiscriminato o commit automatico di tutto il workspace.
- Registrare il riferimento Git e costruire un'immagine agente immutabile; fissare
  anche il browser sidecar, mantenuto identico per entrambi i confronti.
- Congelare target/source commit e contenuto effettivo del checkout, fixture,
  configurazione dei ruoli, provider, budget, concorrenza e input di benchmark.
  Registrare i digest delle immagini, non soltanto tag che possono essere sovrascritti.
- Creare `minimal` dal punto fissato. Tutte le modifiche successive implementano
  direttamente la nuova versione, senza selettori di toolset.
- Implementare e verificare localmente P0-S, il singolo tool SQL descritto sotto,
  prima di ridurre il catalogo. Fissare questo commit e la relativa immagine come
  baseline principale, con catalogo attuale e SQL già esteso. Eseguire la baseline
  concordata e salvare outcome, transcript e input esatti. In questo modo i risultati
  delle semplificazioni successive non vengono confusi con la nuova facoltà di
  preparare dati nel DB. Il riferimento iniziale conserva anche lo stato SQL read-only.

Se esiste già una baseline read-only, conservarla come riferimento storico separato;
non attribuire alla riduzione dei tool i miglioramenti dovuti alle scritture SQL.
Gli esiti storici aiutano a scegliere il corpus ma non sostituiscono il confronto
controllato, perché cataloghi e orchestrazione sono cambiati.

I benchmark con modelli sono una fase da avviare esplicitamente; non rientrano nelle
verifiche autonome locali, secondo `AGENTS.md`.

### 2. Rendere le primitive sufficienti prima di eliminare i wrapper

**Shell workspace.** Riutilizzare `run_workspace_command`: Bash, `rg`, `find`, `sed`
e Python, sorgente read-only, nessuna rete o credenziale. Aggiungere soltanto eventuali
dipendenze effettivamente necessarie al corpus; niente installazioni durante la run.
Il toolbox deve consentire script e file temporanei nel proprio spazio scrivibile.

**Output accessibili agli script.** Riutilizzare `ToolOutputManager` e l'archivio
Investigator. Estendere il recupero esistente con la sola materializzazione necessaria:
un output autorizzato viene reso disponibile come file redatto e read-only al toolbox
della chiamata. Il modello riceve un percorso e pochi metadati piatti su completezza
e disponibilità. Il corpus esposto deve appartenere al ruolo/area/lead corrente;
non montare l'intera directory della run o i journal grezzi.

La materializzazione è on demand: non duplicare automaticamente tutti i body su disco,
non creare un secondo archivio durevole e non trasformare l'eviction in disponibilità
illimitata. Riutilizzare il recupero persistito quando già previsto. Output incompleto,
espulso o non acquisito devono restare distinguibili. Un semplice parser Python deve
poter leggere JSON/HTML/testo senza copiare body estesi nel prompt.

**Provenance sorgente.** Conservare `read_file` e `read_source_ref`. La shell trova e
correla; il blocco decisivo viene acquisito con reference verificabile. Non dedurre
file/righe consultate interpretando comandi Bash arbitrari. I percorsi e le reference
continuano a essere verificati dall'orchestratore.

**HTTP.** Esporre una sola primitiva, riutilizzando i trasporti attuali. Un contratto
piatto descrive metodo, URL, actor, header e payload nei formati realmente necessari:
testo/raw, JSON anche array, form URL-encoded e multipart con file. Conservare cookie,
sessioni, policy di rete, redazione e ledger delle transazioni. Non usare la shell
interna al target come sostituto delle richieste dell'attaccante.

**Runtime.** Conservare `run_target_command` e `run_target_probe`: hanno contesti di
esecuzione diversi. Non introdurre una mega-funzione con modalità e parametri
condizionali per unificarli soltanto nominalmente.

**P0-S — un solo tool SQL, lettura e modifica dei record.** Estendere direttamente
`query_database`, senza creare un tool di scrittura separato o un flag read/write.
Connessione e credenziali restano risolte dal runtime. La descrizione del tool e il
prompt dei ruoli autorizzati adottano questa policy:

> Puoi leggere e modificare i record del database della sandbox quando serve alla
> verifica della vulnerabilità corrente. Non puoi modificare schema, vincoli,
> trigger, procedure o privilegi del database.
>
> Crea o modifica soltanto i dati necessari al test, mantenendo uno stato coerente
> con il funzionamento dell'applicazione e plausibile in produzione. Preferisci
> aggiungere record dedicati al test e limita le modifiche ai dati esistenti.
>
> Non disabilitare protezioni e non attribuire all'attore attaccante il privilegio
> o l'accesso che il test deve dimostrare ottenibile.
>
> Distingui nella tua analisi le modifiche di preparazione dagli effetti prodotti
> attraverso l'applicazione. Le scritture dirette nel database non dimostrano,
> da sole, una vulnerabilità.

Esempio: creare un amministratore come vittima di un test può essere corretto;
rendere amministratore l'attaccante per dimostrare una privilege escalation non lo è.
Conta il ruolo del dato nell'esperimento, non la sola presenza di un account privilegiato.

Separare le responsabilità senza aggiungere campi obbligatori al modello:

- **Executor:** ammettere letture e singole istruzioni DML sui dati applicativi
  (`INSERT`, `UPDATE`, `DELETE`); vietare tecnicamente modifiche strutturali, privilegi,
  disabilitazione delle protezioni e istruzioni amministrative. `TRUNCATE` non è una
  sostituzione ammessa di `DELETE`. Metadati read-only restano consultabili.
- La classificazione deve considerare l'intera istruzione, incluse CTE e costrutti
  del dialetto supportato; non basta rimuovere parole dalla blacklist o controllare
  il primo token. Rifiutare multi-statement e vie di esecuzione indiretta non
  controllate, senza offrire la shell come percorso alternativo ai divieti SQL.
- Aggiornare l'attuale esecuzione read-only per MySQL/MariaDB, PostgreSQL e SQLite:
  le scritture autorizzate devono essere applicate, senza il wrapper `SELECT * FROM`
  usato per limitare le letture. Conservare timeout, redazione e limiti degli output;
  `max_rows` limita le righe restituite, non riscrive le modifiche richieste.
  Restituire l'esito e, dove disponibile, il numero di righe modificate nel risultato
  piatto esistente. Non dichiarare una capability read-only nel dossier aggiornato.
- Lasciare operare i vincoli e i trigger già presenti come durante il normale uso
  dell'applicazione. Nessuna creazione/modifica di schema o privilegi da parte del
  modello; eventuali account tecnici del provisioning restano responsabilità harness.
- **Modello:** valutare necessità, realismo e coerenza dei dati, evitare la costruzione
  artificiale della vulnerabilità e distinguere preparazione da prova nella narrativa.
  Nessuna euristica che pretenda di decidere questi aspetti dal testo della query.
- **Journal esistente:** acquisire query ed esito redatti, inclusi errori e timeout.
  Niente nuovo DTO, campo `justification` obbligatorio o protocollo di approvazione
  per ogni scrittura. Il percorso SQL non deve produrre una conferma HTTP o browser
  né un avanzamento automatico a finding confermato.
- Una scrittura interrotta può avere effetti già applicati: non ripeterla
  automaticamente dopo timeout/errore dall'esito incerto. La verifica dello stato
  usa le primitive esistenti. Le sandbox sono ripristinate tra le prove indipendenti.

La policy riguarda la verifica della lead corrente: non estendere per questo motivo
l'accesso SQL a Recon e Reader né aggiungere nuove responsabilità a tali ruoli.

### 3. Ridurre il catalogo in passaggi verificabili

**P0-A — sostituzioni più semplici, con rischio relativo minore.**

| Tool | Sostituzione | Condizione prima della rimozione |
|---|---|---|
| `list_dir` | `ls`/`find` nella shell workspace | Tutti i ruoli che lo usano dispongono della shell e dello stesso perimetro sorgente |
| `run_code` e relativi binding | Script Bash/Python nella shell workspace | Riprodurre ricerche composte e letture con dipendenze già installate; mantenere `read_file` per il blocco decisivo |

Queste rimozioni non dipendono dalla nuova primitiva HTTP o dalla materializzazione
dei body. Conservare in questa fase `search_source`, grafo, inventari e recupero
delle prove. Controllare anche eventuali percorsi di orchestrazione che invocano i
tool rimossi: il cambio non riguarda soltanto il catalogo visibile al modello.

**P0-B — elaborazione e recupero con primitive comuni.** Prima completare e verificare
l'accesso scoped ai file descritto nella sezione 2; poi sostituire:

| Capacità | Sostituzione |
|---|---|
| `crypto_artifact`, `find_json_records`, `query_html_response` | Librerie standard/parser e script sugli input acquisiti accessibili |
| Lettura/ricerca/ispezione delle risposte e prove persistite | Recupero esistente consolidato e script sui file autorizzati |
| `list_source_refs` | Reference consegnate nei briefing e recuperabili dal catalogo degli output; mantenere `read_source_ref` |
| `list_surface_signals` | Inventario precomputato consultabile come artifact, con stato/errori del sensore e senza fixture oracle |

L'accorpamento non è completato finché il modello non può accedere agli stessi dati
necessari al test, incluse pagine successive e contenuti dopo compaction. Non eliminare
un helper soltanto perché il suo algoritmo è facile da riscrivere in Python.

**P0-C — accorpamento HTTP e controllo delle sessioni.** Esporre la singola primitiva
HTTP descritta sopra e rimuovere i wrapper `http_*` sostituiti, dopo verifica locale
di tutti i formati. Rimuovere `bind_actor_auth` soltanto se i flussi di autenticazione
del corpus sono riproducibili tramite actor/sessioni e la nuova primitiva; se una
capacità necessaria manca, risolverla nel contratto unico prima di togliere il wrapper.
Il solo conteggio storico a zero non autorizza a perdere un flusso di autenticazione.

**P0-D — riduzione con maggiore rischio funzionale, da valutare per ultima.**
Sostituire `search_source` con `rg` e script. Rimuovere dal catalogo grafo, snippet,
call path, data flow e Phpactor senza introdurre una nuova CLI equivalente in P0.
Sono capacità usate nelle run lunghe: questa è una rinuncia esplicita, non una
rimozione di tool inutilizzati. Tenere separati i commit ricerca testuale e backend
semantici, così eventuali regressioni sono attribuibili al passaggio corretto.

Disattivare bootstrap e indicizzazione soltanto quando diventano inutili; rimuovere
dipendenze e layer Docker quando nessun percorso rimasto li richiede. Conservare
Semgrep per l'inventario precomputato, senza riscrivere il sensore.

**Da conservare durante P0:** browser a codice e relativa ispezione; `read_file` e
`read_source_ref`; runtime/probe; unico tool SQL; note, storia investigativa e reset di
sessione. Non accorpare questi ultimi per ridurre nominalmente il numero dei tool.

Aggiornare prompt, briefing e descrizioni: esempi brevi con comandi reali, indicazione
del filesystem disponibile e delle librerie installate. Eliminare istruzioni che
citano tool rimossi. Non modificare policy del loop, checkpoint, budget, modelli,
deduper o browser insieme al cambio del catalogo.

Aggiornare `ARCHITECTURE.md` dopo ogni modifica strutturale, inclusi accesso agli output,
provenance, tool assegnati ai ruoli e backend non più avviati.

### 4. Verifiche locali, senza modelli

Adattare i test esistenti e aggiungere soltanto verifiche sui contratti modificati:

- catalogo di ogni ruolo privo dei tool rimossi e degli switch classic/minimal;
- ricerca via `rg`, lettura citabile, esecuzione di un breve script e parsing HTML/JSON;
- materializzazione degli output con redazione, scope, output incompleto/espulso,
  accesso dopo compaction e recupero Investigator da archivio;
- impossibilità di leggere prove di un'altra lead, segreti o fixture di valutazione;
- primitiva HTTP su server locale/fixture: formati, sessioni actor, timeout ed evidenze;
- SQL sui motori supportati: letture e `INSERT`/`UPDATE`/`DELETE` realmente applicati,
  metadati consultabili, vincoli e trigger esistenti operativi, DDL/privilegi/modifiche
  alle protezioni e multi-statement rifiutati prima dell'esecuzione, anche con commenti,
  CTE e varianti sintattiche dei dialetti; nessun retry automatico delle scritture;
- query DML presenti nel journal con esiti redatti, senza generazione di prove HTTP;
  nessuna regressione nei limiti delle letture, nei timeout e nel dossier runtime;
- rimozione dei backend senza bootstrap residui e senza regressioni del browser;
- smoke dei runner e preflight runtime esistenti senza richieste LLM.

Eseguire solo i test pertinenti e gli eventuali lint dei file toccati; nessun lint
globale e nessun test che avvii loop agentici a pagamento.

### 5. Matrice iniziale di confronto, da avviare esplicitamente

Corpus fissato prima dell'implementazione: Cacti, YesWiki e Gitea, stessi snapshot
in entrambe le revisioni. Partire da Cacti per controllare rapidamente il percorso,
poi completare gli altri due target.

| Livello | Input per target | Esecuzioni per revisione | Scopo |
|---|---|---:|---|
| Recon | Sorgente e fixture, senza recon riusata | 1 | Aree utili e locator verificabili |
| Reader | Una stessa area da Recon congelata | 1 | Lead valide e qualità della catena sorgente |
| Investigator unificato | Due ReaderLead congelate, con controllo positivo e negativo scelti da revisione/manifest | 2 | Conferma/rigetto ed evidenze |

Totale della matrice completa: **12 episodi condizionali per revisione, 24 fra baseline
SQL P0-S e minimal finale**. Non ripetere l'intera matrice dopo ogni passaggio.
Per P0-A/B/C/D eseguire prima i controlli locali pertinenti; gli eventuali confronti
con modello sono limitati ai casi coinvolti e vengono avviati esplicitamente. I
risultati intermedi validi possono essere riusati per la medesima revisione e input.
Ogni risultato riporta il commit e il passaggio effettivamente eseguito, senza
presentare un risultato P0-A come misura della versione finale.
Ogni episodio parte con memoria e sandbox nuove; ordine, envelope e concorrenza sono
uguali. Gli input congelati contengono soltanto ciò che il ruolo deve ricevere: oracle,
verdetti storici e annotazioni evaluator rimangono esterni al contesto del modello.
Le lead devono coprire nel complesso autenticazione, uso DB, analisi di risposta e
almeno un flusso HTTP con payload non banale. I formati restanti sono coperti localmente.
Includere una preparazione SQL legittima e il controllo negativo che vieta di dare
all'attaccante il privilegio da dimostrare; distinguere nella revisione semantica
i dati predisposti dalle conseguenze osservate attraverso l'applicazione. La policy
SQL e le fixture di partenza sono identiche nella baseline P0-S e in ogni passo minimal.

Se i controlli passano, aggiungere **una coppia di audit globali su Cacti**, uno per
revisione, con Recon nuova: verifica dell'interazione fra tutti gli stadi e del costo
di bootstrap. Nessuna importazione della Recon baseline nell'audit minimal completo.

Riutilizzare i runner disponibili:

- `benchmark:recon --global`, fissando esplicitamente le ripetizioni;
- `benchmark:reader-global`, con Recon e area selezionate e concorrenza fissata;
- `benchmark:confirmer --unified-lead`, con input congelati e sandbox per lead;
- `benchmark:run --global`, per l'audit completo finale.

Usare il registry/dataset dei benchmark per congelare gli input. Gli script
`freeze_unified_replay.py` e `report_unified_replay.py` sono riferimenti riutilizzabili
dove compatibili, ma lo script freeze corrente contiene scope storici fissi: non
presumerlo un congelatore generico dei tre target e non costruire un nuovo harness.

I valori effettivi di modello/provider, envelope e timeout vengono fissati nel
manifest prima della baseline, utilizzando configurazioni già funzionanti. Le due
revisioni consumano lo stesso manifest; nessuna ricerca di iperparametri su minimal.
Le esecuzioni baseline e minimal avvengono dalle rispettive revisioni/immagini, non
tramite due implementazioni selezionabili nello stesso branch.

### 6. Criteri di valutazione e decisione

| Priorità | Misura |
|---|---|
| Qualità | Finding validi distinti, falsi positivi, positivi e negativi del corpus correttamente trattati |
| Prove | Source reference risolvibili, transazioni HTTP/browser sufficienti, classificazione Suspect/Confermato corretta |
| Affidabilità | Episodi completati, errori tecnici, retry, impossibilità di accedere agli output necessari |
| Efficienza | Costo, token, durata attiva e totale; tempo al primo test HTTP discriminante |
| Complessità | Catalogo e schema esposti, backend avviati, codice e dipendenze eliminati |
| Validità del setup SQL | Dati plausibili, protezioni attive, nessun accesso attribuito all'attaccante per costruire artificialmente il risultato |

Il numero di tool call resta diagnostico: una pipeline può sostituire molte chiamate.
Riportare separatamente timeout e problemi provider; non eliminare selettivamente
esecuzioni fallite dal confronto. Stesso trattamento delle cache: registrare se il
bootstrap è cold/warm e applicare la stessa preparazione alle due revisioni.

Gate iniziale: nessuna perdita di provenance o isolamento, controlli negativi senza
false conferme e nessuna perdita inspiegata dei positivi riprodotti dalla baseline.
Una baseline che non riproduce un positivo rende quel confronto inconcludente, non
un successo di minimal. Distinguere il numero di aree/lead dalla loro qualità semantica.

Per ogni regressione, leggere il transcript e classificare la causa: capacità assente,
istruzioni insufficienti, errore del comando o problema esterno. Correggere minimal
direttamente e ripetere i casi coinvolti, senza ripristinare un selettore classic.
Una reintroduzione giustificata diventa l'unica nuova versione del branch.
Avanzare al passaggio successivo soltanto dopo aver risolto le regressioni del
precedente: P0-A può essere valutato da solo senza anticipare P0-B/C/D. Un esito buono
delle sostituzioni semplici non vale come validazione della rimozione del grafo.

Una ripetizione per cella è un filtro iniziale, non prova di equivalenza statistica.
Un miglioramento di costo o durata intorno al 20% è un obiettivo utile da verificare,
non una soglia che autorizza a sacrificare qualità. Se il risultato resta incerto,
non promuovere minimal sulla base di un singolo episodio favorevole.

## P1+ — dopo il primo risultato

- Ripetere le celle informative almeno altre due volte e ampliare aree/lead prima
  di una promozione definitiva.
- Se emerge una regressione attribuibile al grafo, provare l'accesso CLI al backend
  necessario, senza ricreare il catalogo di tool specializzati.
- Eliminare `read_file`/`read_source_ref` solo se una soluzione altrettanto semplice
  preserva provenienza e citazioni; non introdurre parsing dei comandi shell.
- Accorpare note e storia soltanto se il loro contratto emerge come costo concreto.
- Ulteriore riduzione dell'immagine e ottimizzazioni non necessarie al primo confronto.

## Sequenza di consegna

1. Stato iniziale riproducibile e input congelati; creazione del branch dedicato.
2. P0-S: unico SQL esteso, policy e verifiche locali; baseline comparabile fissata.
3. P0-A: eliminare `list_dir` e `run_code` con sostituzioni shell verificate.
4. P0-B: output accessibili e accorpamento degli helper di elaborazione/recupero.
5. P0-C: unica primitiva HTTP e verifica completa di actor/sessioni e formati.
6. P0-D: ricerca via shell, poi rimozione separata dei backend semantici.
7. A ogni passaggio: prompt/architettura aggiornati, controlli pertinenti e deletion pass.
8. Benchmark esplicitamente avviati, matrice finale e report con regressioni analizzate.

## Riferimenti verificati

- Analisi locale: `plans/tool-usage-cross-target-minimal-review-20261016.md`.
- DeepSeek Harness, commit `86a402200d95fecef4529d969836f1fe13c6cb0d`, verificato
  durante la pianificazione: [tool Bash](https://github.com/deepseek-ai/deepseek-harness/blob/86a402200d95fecef4529d969836f1fe13c6cb0d/packages/shell/tool-bash/src/index.ts)
  e [executor sandbox](https://github.com/deepseek-ai/deepseek-harness/blob/86a402200d95fecef4529d969836f1fe13c6cb0d/packages/shell/bash-sandbox/src/index.ts).
  Il concetto trasferito è una primitiva di comando semplice sopra un executor che
  conserva isolamento e gestione dei risultati. Nessuna dipendenza, plugin o nuova
  architettura Harness è necessaria a questo esperimento.
