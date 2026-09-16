# Piano P0 — Opengrep e analisi statica utile agli agenti

Data: 15 settembre 2026. Stato: piano di implementazione, runtime non modificato
da questo task.

## Decisione e risultato atteso

**Adottare Opengrep come motore predefinito**, dopo verifiche offline di compatibilità
e regressione. La scelta di direzione è motivata anche dall'apertura delle capacità
di analisi e dall'indipendenza del progetto: non richiede di dimostrare prima un
incremento del recall mediante una global LLM a pagamento.

Precisazione: anche il motore Semgrep CE è open source. La differenza rilevante
per questa decisione riguarda disponibilità ed evoluzione delle funzionalità,
oltre al modello di governance; motore e licenze delle regole restano separati.
[Chiarimento ufficiale Semgrep](https://semgrep.dev/blog/2024/important-updates-to-semgrep-oss/).

Il risultato P0 deve permettere al Reader di trovare e approfondire piste migliori
e al Confirmer di consultare una catena statica già individuata, mantenendo visibili
le incertezze. Non basta sostituire il comando dello scanner.

```text
Source snapshot → scan Opengrep → inventario esistente con dettagli/trace
                                ├─ Recon: census aggregato
                                ├─ Reader: segnali e dettaglio su richiesta
                                └─ Confirmer: dettaglio della pista e sorgente
                                                ↓
                                              Worker
```

Conservare ruoli, budget, history, ledger, notebook e strumenti Joern/Codebase Memory.
Lo scanner non crea ReaderLead automaticamente e non decide vulnerabilità o chiusure.

## Perimetro e rapporto con i documenti precedenti

Questo piano governa l'evoluzione SAST P0 e incorpora i requisiti ancora necessari di
`HANDOFF-static-analysis-stack-coverage-20260915.md`. Le cause storiche restano
documentate lì; l'analisi delle run è in `global-final-analysis-20260915.md`.
Il piano budget/history rimane indipendente.

Il checkout letto contiene già `--no-rewrite-rule-ids`, nuovi conteggi/error details,
cache schema v3 e un manifest aggiornato con la patch della regola Slack. Verificare
lo stato reale prima di implementare: non duplicare correzioni già presenti né
presumere che i problemi delle run storiche siano ancora identici nel codice corrente.

### P0

- Motore Opengrep fissato e verificabile; Semgrep disponibile solo per confronto
  e rollback esplicito durante la migrazione.
- Corpus security utilizzabile nel contesto previsto, integrità verificata e
  copertura dichiarata per stack.
- Analisi taint fra funzioni dello stesso file, con trace consultabili.
- Riutilizzo di inventario/paginazione e un solo nuovo tool di dettaglio.
- Indicazioni concrete su come Recon, Reader e Confirmer usano i segnali.
- Test offline del percorso completo e misure essenziali.

### P1+ esclusi

- Framework di adapter, nuovi agenti, nuovo database o indice persistente SAST.
- Tool di structural search e probe taint con pattern proposti dal modello.
- Guarded taint sperimentale, anche in shadow mode, nel primo incremento.
- Cross-file aggiuntivo, sostituzione Joern, inventario universale delle route.
- Scoring composito configurabile, profili per ogni audit, download regole runtime.
- Generazione LLM di regole, ruleset specifici per CVE/progetto, redesign UI.

## 1. Migrazione del motore

### Installazione e selezione

Usare inizialmente **Opengrep v1.30.0**, release verificata durante il planning;
fissare asset e checksum per l'architettura Docker effettiva. Scaricare il binario
durante la build, verificarlo e provare `--version`/le opzioni richieste. Niente
installer remoto eseguito alla cieca e niente aggiornamento durante l'audit.
[Release ufficiale](https://github.com/opengrep/opengrep/releases/tag/v1.30.0).

Riusare il punto unico di costruzione del comando in `surface_context.py` e un
solo selettore interno/configurazione `opengrep|semgrep`, default Opengrep al termine
dei controlli. Una funzione per il comando e la normalizzazione esistente bastano:
non introdurre `StaticAnalysisEngine`, registry di provider e factory.

Preservare la disponibilità del vecchio scanner per i controlli e il rollback.
Nessuna esecuzione doppia ordinaria e nessun fallback silenzioso a Semgrep: registrare
sempre il motore effettivo. Se lo scanner fallisce, proseguire con lo stato degradato
già previsto dal sensore; i segnali parziali restano esplicitamente incompleti.

### Opzioni

Verificare sulla release fissata supporto e output di:

- `scan`, JSON, regole locali, metriche/version check disabilitati;
- ID senza riscrittura, o equivalente nativo verificato;
- `--dataflow-traces` e `--taint-intrafile`;
- timeout e limiti per file già applicati da Lailaps.

`--inline-metavariables` e enclosing context vanno usati solo se necessari per
recuperare informazioni che il JSON standard della release non contiene già.
Non moltiplicare le opzioni per raccogliere dati che gli agenti non utilizzeranno.

Il percorso ordinario esegue **una scansione con pattern e taint** sul corpus
selezionato. L'attivazione intrafile si verifica separatamente nel confronto
offline; non servono due profili permanenti baseline/deep che duplicano ogni scan.

Le trace intrafile attraversano funzioni nello stesso file; non attestano una
catena completa fra router, servizio e repository in file diversi. Un flusso non
trovato non esclude la vulnerabilità. [Capacità documentata](https://github.com/opengrep/opengrep/wiki/Intrafile-tainting-tutorial).

## 2. Corpus security e copertura per stack

### Integrità e provenienza

Riusare `rules/`, il manifest e lo script di aggiornamento. Il manifest deve
descrivere i byte finali dopo eventuali patch locali, fonte/revisione, engine
compatibile e provenienza/licenza delle componenti distribuite. Verificare checksum
e regole nell'aggiornamento e impedire la distribuzione di un'immagine con corpus
richiesto incoerente.

Il passaggio a Opengrep non modifica i diritti d'uso del corpus Semgrep già copiato.
La Semgrep Rules License limita distribuzione e disponibilità come servizio.
Selezionare per l'immagine destinata al prodotto regole originali o fonti con
condizioni compatibili, mantenendo i notices. Non assumere che un fork di regole
cambi la loro licenza. Questo è un controllo sulla dipendenza che si distribuisce,
non un motivo per introdurre un'infrastruttura di compliance.
[Licenza delle regole](https://semgrep.dev/legal/rules-license/).

Per isolare il motore, Semgrep e Opengrep devono usare lo stesso corpus verificato
e utilizzabile nel contesto del test. Se cambia anche il corpus, misurare quel
cambio separatamente. Non presentare l'eventuale riduzione del corpus come effetto
del nuovo engine e non sostituire l'attuale base ampia con poche regex senza
documentarne la perdita di copertura.

### Regole di superficie e regole taint

Conservare due significati semplici: **hotspot da leggere** e **flusso statico
segnalato**. Entrambi sono advisory. Non usare il nome `vuln` come promessa che il
secondo sia già una vulnerabilità.

Riutilizzare le regole disponibili; aggiungere soltanto regole generaliste che
coprano vuoti concreti. Nessun albero di directory per framework non ancora usati,
nessun vocabolario universale di wrapper custom e nessuna etichetta obbligatoria
per ogni possibile concetto. La provenienza resta interna; agli agenti basta la
semantica utile del segnale.

Produrre una matrice breve nel README/manifest:

`lingua/framework | famiglie presenti | engine verificato | casi testati | lacune`

Go e PHP sono i primi casi end-to-end. Per JS/TS, Python, Java, Ruby, C# e Kotlin
già contemplati dal corpus, dichiarare soltanto la copertura effettivamente verificata;
conservare le regole compatibili e segnalare i limiti, senza estendere P0 a nuovi scanner.

Controllare almeno SQL, comandi, filesystem/path, HTTP/SSRF/TLS, template/XSS,
deserializzazione e primitive crypto/auth pertinenti allo stack. Non confondere
“parser disponibile” con “framework e famiglia coperti”. Un inventario di chiamate
sicure a DB può essere corretto per una regola hotspot; i test negativi delle regole
di vulnerabilità devono verificare un'altra proprietà.

## 3. Conservare le informazioni utili e riusare lo scan

### Estendere lo stato esistente

Oggi la normalizzazione conserva famiglia, file, riga e messaggio; aggiungere,
quando realmente restituiti dall'engine, range del match, tipo di regola, metavariabili,
source/sink e trace. Conservare il dettaglio fuori dal prompt ordinario, associato
al `signal_id` nello stato già esistente. Non inventare un source/sink assente
né dedurlo dal solo messaggio della regola.

Riutilizzare la cache dei risultati e il suo ciclo di vita. Il fingerprint deve
includere motore, versione effettiva, corpus e opzioni che cambiano la semantica
dello scan. Nessuna collisione Semgrep/Opengrep o intrafile on/off. Invalidare le
proiezioni vecchie quando necessario, senza riscrivere gli artifact storici.

La global già condivide un Surface Context: assicurarsi che fra categorie venga
riusato anche il dettaglio completo, non soltanto `example_signals` o il census.
La scansione del source snapshot deve avvenire una volta nella normale global;
query e cambio categoria non devono rilanciarla. Non cambiare automaticamente
lo snapshot statico perché il Worker crea fixture sull'istanza runtime.

Per confrontare gli engine usare regola + path + range della source/sink, conservando
origine e versione. Deduplicare match equivalenti, senza accorpare ipotesi diverse
perché condividono riga o funzione; riusare l'identità esistente dove basta.

### Un solo nuovo tool

Estendere `list_surface_signals` mantenendo scope/path/family/paginazione; nel
risultato breve aggiungere soltanto distinzione hotspot/flow e presenza della trace.
I metadata di categoria aiutano l'ordinamento ma non escludono rigidamente un segnale.

Aggiungere **`get_surface_signal(signal_id, cursor?)`** per Reader e Confirmer.
Restituisce testo compatto con regola, file/range, frammenti pertinenti, percorso
dei dati e limiti; paginazione della trace solo quando serve. È un recupero del
risultato già calcolato, non un nuovo scan.

Prima dell'uso come evidenza registrare riferimenti ai frammenti verificati sul
sorgente originale, secondo il ledger già esistente. Conservare confini di source
root e scope dei ruoli: il Confirmer usa segnali pertinenti alla lead e ai file
coinvolti, senza ottenere una discovery globale implicita.

**Controllo model-facing:** stato, ID e cursore bastano per il controllo di flusso;
spiegazione e trace possono essere narrative. Non esporre JSON raw, nuovi DTO
annidati, campi duplicati o una nuova struttura completa di CandidateHandoff.

## 4. Rendere i segnali utilizzabili nel flusso investigativo

### Recon

Continuare a fornire il census aggregato, con famiglie e file interessanti, senza
dump di codice/trace. Riutilizzare l'ordinamento e la diversità fra regole già
implementati. Un'area con segnali concreti merita considerazione; l'assenza di
segnali non elimina altre aree. Niente nuovo interest score composto da molti pesi.

### Reader

Al cambio di area il briefing segnala in modo compatto la disponibilità di hotspot
e flussi nei suoi file. Il prompt spiega quando usare lista e dettaglio: scegliere
una pista promettente, leggere la catena disponibile, identificare il confine che
resta da verificare e approfondire sul sorgente.

Non imporre una chiamata tool per ogni area o una lead per ogni match. Il Reader
continua a cercare wrapper custom, permessi e superfici non coperte dalle regole.
Non considerare un'intera funzione già risolta perché una sua pista è confermata.

### Confirmer

L'handoff già esistente include, quando disponibili, i riferimenti ai segnali
pertinenti senza duplicare trace e codice nel prompt. Il Confirmer recupera il
dettaglio per capire la propagazione e verifica input controllabile, route, attore,
permessi, sanitizzazione e precondizioni reali. Joern/Codebase Memory rimangono
strumenti complementari per i passaggi che la trace non risolve.

L'orchestratore collega i segnali osservati agli eventi/lead quando il contesto
lo consente, senza chiedere al modello di compilare un nuovo modulo di attribuzione.
Una lettura precedente del segnale documenta esposizione, non prova causalità del finding.

Il Worker riceve la candidate migliorata; nessun nuovo tool SAST nel suo ruolo.

## 5. Verifiche e sequenza di consegna

### Incremento A — baseline sana e migrazione

- Verificare le correzioni già presenti, integrità e corpus selezionato.
- Fissare binario Opengrep e adattare comando/cache/provenienza.
- Confronto offline: Semgrep corretto vs Opengrep, stesse regole e sorgenti,
  inizialmente senza estendere la semantica taint.
- Controllare parsing, compatibilità, risultati grezzi/conservati, durata e memoria.
  Valutare le differenze sui casi noti: nessuna perdita ingiustificata di piste utili.

Opengrep diventa default dopo questi controlli. Non attendere una prova di superiorità
statistica o una global agentica; regressioni concrete sui target supportati vanno
risolte o circoscritte prima della promozione.

### Incremento B — intrafile e dettaglio agli agenti

- Confrontare Opengrep con intrafile disattivato/attivato sugli stessi casi.
- Attivare il percorso intrafile ordinario dopo i controlli di correttezza e costo.
- Preservare trace, aggiungere il solo tool di dettaglio e aggiornare briefing e
  handoff dei ruoli; verificare che non si perda tutto passando di categoria.

### Test P0, tutti senza LLM

1. Fixture Go e PHP: match reale → normalizzazione → census → lista → dettaglio
   → riferimenti validi al sorgente. Includere regole locali e del corpus selezionato.
2. Taint: flusso diretto, helper nello stesso file e variante sanitizzata; un caso
   fuori capacità/cross-file deve mantenere esplicito il limite. Almeno una trace
   nuova del percorso intrafile deve essere effettivamente consultabile.
3. Confronto semantico: verificare source/sink attese, non soltanto numero dei match
   o JSON identico. Un esito taint negativo non diventa chiusura della lead.
4. Riutilizzo: due categorie/query condividono lo scan completo; cache distingue
   motori/versioni/opzioni; risultati fuori dal top-N restano recuperabili.
5. Errori: corpus corrotto, parsing parziale, timeout/troncamento, ID prefissati e
   path non validi non diventano stato sano con zero risultati.
6. Scope e output: tool disponibile ai ruoli previsti, dettaglio bounded, sorgente
   verificata e nessuna fuga involontaria dall'indagine della lead.

Estendere `test_surface_context.py` e i test dei tool/prompt già presenti. Usare
poche fixture leggibili e il binario reale nel container; niente nuovo harness.
La validazione completa del vecchio corpus era andata in timeout a 45s durante
l'analisi precedente: non considerarla già superata e non attribuirne la causa
senza diagnosticarla.

### Misurazione agentica successiva, separata

Solo dopo autorizzazione a chiamate API, confrontare pochi episodi Reader/Confirmer
con gli stessi modelli, budget e source snapshot. Misurare lead corrette, recall sui
casi valutabili, verifiche statiche concluse e token/tempo per episodio; poi una
global può verificare la generalizzazione. I manifest restano oracle esterni ai prompt.

Misure minime negli artifact esistenti: motore/versione/corpus, durata scan,
risultati raw e conservati, errori/troncamenti, numero di query/dettagli consultati.
Usare la telemetria tool esistente; evitare un nuovo sistema di scoring o attribuzione.

## 6. File e criterio di completamento

Punti principali: `surface_context.py`, `deps.py`, `triple_agent.py`, `config.py`,
`rules/`, updater, Dockerfile, test esistenti e README dell'agente. Adeguare il
passaggio Global Recon/categorie solo per condividere i dettagli mancanti. Riusare
il comando diagnostico `pentest surface-context` per scansioni senza ruoli LLM.

Durante l'implementazione aggiornare `ARCHITECTURE.md` su motore, corpus,
riuso dei risultati, trace e nuovo tool; non modificare adesso la fotografia del runtime.
Preservare gli altri cambiamenti del working tree e non eseguire lint globale.

**P0 completo:** Opengrep è il default verificato, il corpus è coerente e di
provenienza adeguata all'uso, il taint intrafile e il dettaglio arrivano realmente
agli agenti, le limitazioni restano visibili e i test offline del percorso passano.
Documentare separatamente che l'aumento del recall agentico resta da misurare.

Prima della consegna eliminare wrapper, opzioni e strutture che non servono a questi
comportamenti. L'obiettivo è migliorare l'indagine con l'infrastruttura esistente.
