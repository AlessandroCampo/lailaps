# Handoff — Tool di analisi dei flussi per Reader e Confirmer

## Obiettivo

Integrare un tool interrogabile dagli agenti per ricostruire percorsi di dati nel sorgente, usando un motore esistente. Esporlo nel P0 sia al Reader, per discovery, sia al Confirmer, per approfondimento verticale. Aggiungere `report.telemetry.tool_calls_details`, suddiviso per ruolo e nome del tool, per osservare quanto ogni agente usa gli strumenti disponibili e se ignora il nuovo tool.

Questo handoff autorizza l'implementazione descritta. È indipendente dagli handoff P0.1, P0.2 e P0.3 e non richiede che siano già implementati.

La proposta nasce da ARCHITECTURE.md e dalla documentazione ufficiale dei motori, senza ispezione dell'implementazione. Prima di modificare leggere le istruzioni applicabili, l'architettura corrente e il codice pertinente. Verificare cosa offrono già Codebase Memory, trace_code_path, source reference e contatori telemetrici; riutilizzarli invece di duplicarli.

## Scelta tecnica iniziale

Usare Joern come primo backend da integrare e validare. `joern-slice data-flow` documenta slicing all'indietro dagli argomenti delle chiamate, interprocedurale, con filtri e output JSON. La ricerca in avanti richiede query dedicate: non presentarla come una capacità già garantita dallo stesso comando.

Fonti ufficiali di riferimento:

- https://docs.joern.io/cpg-slicing/
- https://docs.joern.io/frontends/
- https://docs.joern.io/cpgql/data-flow-steps/
- https://docs.joern.io/dataflow-semantics/
- https://github.com/joernio/joern/releases

Verificare API, packaging, licenza e comportamento della versione scelta prima di fissarla. Fissare una versione riproducibile: non scaricare automaticamente `latest` durante una run.

Non sviluppare un nuovo motore multistack. Il codice Lailaps deve adattare il motore, governarne l'esecuzione e proiettare i risultati. La presenza di un frontend non garantisce precisione completa sui framework o collegamenti automatici tra linguaggi/servizi.

## Ruoli e semantica

### Reader

Il tool serve a individuare origini plausibilmente controllabili, passaggi attraverso wrapper e sink fratelli. Un esempio è partire dall'argomento di una query e ricostruirne le assegnazioni e i chiamanti fino a un possibile ingresso HTTP.

Preservare la soglia di lead plausibile già prevista: il Reader non deve completare controlli di ACL, mitigazioni, autenticazione o reachability prima di serializzare una pista concreta. Chiarire come una chiamata bounded al tool si inserisce nel controllo locale previsto dal prompt corrente, senza imporre ricerche esaustive o un uso obbligatorio.

### Confirmer

Il tool serve a ricostruire source, propagation e sink della lead attiva e individuare trasformazioni/condizioni che richiedono lettura. Il Confirmer valuta semanticamente controllabilità e barriere; il motore non chiude o conferma finding.

### Provenienza

I collegamenti di flusso restano risultati di analisi statica potenzialmente approssimata. Non trasformare un edge in evidenza di esecuzione o in prova di sfruttabilità. Risolvere i locator sul sorgente reale tramite i meccanismi canonici. Se gli snippet restituiti diventano source reference, validarli contro il sorgente e registrarli con ruolo, scope e hash come le letture ordinarie; soltanto il testo validato acquisisce tale provenienza, non l'edge.

L'assenza di un percorso non dimostra sicurezza. Dispatch dinamico, dipendenze mancanti, filtri e limiti possono rendere il risultato incompleto. Le semantiche conservative delle chiamate esterne possono invece produrre percorsi spurii.

## P0 — Implementazione incrementale

1. Verificare il valore aggiunto rispetto a trace_code_path: il nuovo strumento deve seguire valori/argomenti e trasformazioni, non limitarsi a duplicare un call graph.
2. Integrare un backend Joern con indicizzazione e query nel perimetro sorgente autorizzato. Prima capacità completa da consegnare: slicing backward mirato a un argomento di chiamata, con profondità e dimensioni bounded. Validare inizialmente PHP e un secondo stack rilevante presente nei benchmark, scelto dopo la verifica del catalogo.
3. Esporre il tool a Reader e Confirmer con un nome stabile e descrittivo, per esempio `trace_data_flow`. Il nome definitivo deve seguire le convenzioni locali e rimanere identico nella telemetria. Non occorre aggiungere nuovi agenti.
4. Preferire input piatti: file relativo al source root, riga e selettore dell'argomento quando necessario. Aggiungere colonna o nome del simbolo solo se servono a risolvere ambiguità reali. Quando più espressioni coincidono con il locator, restituire poche alternative chiare senza scegliere silenziosamente un nodo arbitrario.
5. Restituire una proiezione compatta dei passaggi con locator sorgente, frammenti pertinenti, trasformazioni osservabili e collegamenti incerti. Non esporre il CPG integrale, ID interni del backend o JSON annidato del motore. Tipizzare solo stato operativo e campi necessari al controllo; mantenere narrativa la spiegazione. Valutare esplicitamente se il contratto può essere ancora semplificato prima di aggiungere campi.
6. Distinguere risultato riuscito senza percorsi, analisi parziale/troncata, locator ambiguo, linguaggio non supportato, backend non disponibile ed errore/timeout. Nessuno di questi stati produce deterministicamente un verdetto di sicurezza.
7. Integrare il tool nei prompt come capacità facoltativa orientata a domande concrete. Non imporre una chiamata per lead o riavviare automaticamente l'agente se non lo usa. Il Reader conserva l'esplorazione libera e il Confirmer il proprio scope verticale.
8. Prevedere abilitazione indipendente per Reader e Confirmer con flag coerenti con la configurazione esistente. La prima integrazione è sperimentale e rollbackabile; documentare chiaramente il default scelto. Nessun fallback deve interrompere le normali letture sorgente.

### Esecuzione e risorse

- Il modello non invia script Scala, query arbitrarie di shell o comandi Joern: l'adattatore usa query/template controllati e parametri validati.
- Non eseguire il progetto sottoposto ad audit per indicizzarlo e non ampliare il source root. Risolvere e validare anche i path restituiti dal motore, inclusi symlink e percorsi esterni.
- Separare l'indicizzazione dall'interrogazione. Riutilizzare il grafo per le lead successive e invalidarlo con fingerprint del sorgente, versione del backend e configurazione/semantiche rilevanti. Riutilizzare un'eventuale infrastruttura cache esistente e non creare nuovi artifact nella directory della run che violino il contratto dei due file.
- Il documento descrive un envelope di 2 GiB per l'agente. Misurare il consumo reale di Joern prima di collocarlo nello stesso processo/container; se necessario usare un servizio isolato con risorse esplicite. Non aumentare implicitamente la concorrenza di indicizzazione o sottrarre memoria al processo agente senza aggiornare il provisioning.
- Usare timeout e limiti di output; non uccidere il target HTTP in caso di errore dell'analizzatore. Lo strumento è un acceleratore fail-open.
- Non duplicare l'installazione o l'indice tra ruoli. Versione, capability e indisponibilità devono essere osservabili senza introdurre payload operativi grandi nei prompt.

## P0 — Telemetria `tool_calls_details`

Creare la sezione nell'outcome canonico sotto `report.telemetry.tool_calls_details`. Estenderla a tutti i tool dei ruoli, non soltanto a Joern, così il conteggio del nuovo strumento è confrontabile con search_source, read_file e trace_code_path. Riutilizzare gli hook/contatori nominativi già documentati, verificandone la semantica.

Forma interna persistita proposta, da adattare soltanto se esiste già un contratto equivalente:

```json
{
  "tool_calls_details": {
    "reader": {
      "trace_data_flow": {"available": true, "calls": 0},
      "read_file": {"available": true, "calls": 12}
    },
    "confirmer": {
      "trace_data_flow": {"available": true, "calls": 3}
    },
    "worker": {
      "trace_data_flow": {"available": false, "calls": 0}
    }
  }
}
```

Questa struttura è interna, non un DTO da far compilare al modello. `available=true` significa che il tool è stato esposto almeno una volta al ruolo in una richiesta investigativa effettivamente inviata al modello; non significa che il backend abbia risposto con successo. Se il progetto possiede già metadati equivalenti di disponibilità, riusarli mantenendo leggibile questa distinzione.

Requisiti dei conteggi:

- `calls` conta una volta ciascuna invocazione del modello ricevuta dal dispatcher, incluse quelle con errore recuperabile, timeout o parametri non validi quando il dispatcher le osserva. Definire esplicitamente il boundary; tool call mai ricevute non possono essere contate.
- Separare i retry interni del backend, che non incrementano `calls`, da una nuova chiamata emessa dal modello, che incrementa il conteggio. Compression, ripubblicazione del report e replay della history non devono ricontare invocazioni già registrate.
- Aggregare per identità canonica del ruolo/agente; se esistono subruoli distinti, usare le identità già presenti senza introdurre etichette ambigue. Non aggregare soltanto per nome del modello provider: lo stesso modello può coprire ruoli diversi.
- Esporre esplicitamente zero per i tool disponibili ma mai invocati. Per il nuovo tool rendere distinguibile anche la disabilitazione/indisponibilità al ruolo. Incrociare con il numero di richieste del ruolo già presente per distinguere ruolo non esercitato e tool ignorato.
- Aggregare tutte le epoch, lead e categorie della run senza sovrascrivere i contatori al cambio scope. Usare una sola fonte autorevole; eventuali proiezioni di categoria devono riconciliarsi con il totale.
- Conservare i conteggi nei checkpoint e nell'outcome parziale/finale, inclusi recovery e cancellazioni per cui esiste un checkpoint. Mantenere idempotente il finalizer. Non promettere persistenza di eventi successivi all'ultimo checkpoint in caso di crash se il meccanismo corrente non la garantisce.
- Per outcome precedenti con dati insufficienti, segnalare dettaglio non disponibile oppure omettere la nuova sezione. Non inventare zeri storici.
- Non persistere argomenti sensibili, query complete o output raw per ottenere questi conteggi.

Non è richiesta una nuova pagina UI. La sezione JSON deve essere direttamente ispezionabile e disponibile alle proiezioni telemetriche esistenti. Il conteggio zero è un segnale diagnostico, non una prova che il modello abbia ignorato un tool utile: potrebbe non averne avuto bisogno.

## Verifica e criteri di accettazione

### Correttezza del tool

- Verificare flusso locale, passaggio attraverso wrapper tra file, trasformazione/sanitizzazione e riassegnazione che interrompe il percorso originario.
- Verificare almeno un caso con chiamata dinamica o dipendenza esterna non risolta: incertezza visibile, nessuna pretesa di completezza.
- Confrontare locator e snippet con il sorgente autorevole e verificare che non sia possibile leggere fuori perimetro.
- Verificare cache hit e invalidazione al cambio sorgente/versione, timeout, troncamento e fallback quando Joern manca.
- Misurare memoria e durata su un progetto rappresentativo prima di dichiarare compatibilità con l'envelope esistente.

### Correttezza della telemetria

- Reader con tool disponibile e mai chiamato → `calls=0`, disponibilità esplicita.
- Confirmer con chiamate riuscite e fallite → totale coerente con gli eventi reali.
- Retry interno del backend → una sola invocazione; nuova chiamata del modello → incremento distinto.
- Cambio lead/categoria, compression e finalizzazione ripetuta → nessuna perdita o duplicazione.
- Tool disabilitato e ruolo non esercitato → distinguibili dall'assenza d'uso di un tool disponibile.
- Outcome storico privo dei dati → nessun conteggio zero inventato.

### Utilità agentica

Usare i benchmark condizionali Reader e Confirmer esistenti per piccoli confronti A/B separati, mantenendo fissi candidate/Recon, modelli, budget e snapshot. Non richiedere P0.3 Worker.

Per Reader misurare lead valide, coverage/recall, duplicati e costo; per Confirmer correttezza di catena/closure, passaggi irrisolti e costo. In entrambi controllare `tool_calls_details`, letture sorgente e latenza dell'indicizzazione/query. Il numero di invocazioni non è una metrica di qualità da massimizzare.

Se il tool non viene usato, verificare prima effettiva esposizione nello schema, descrizione, risoluzione dei locator ed errori. Cambiare i prompt o il modello soltanto con una diagnosi, evitando obblighi artificiali di utilizzo.

## Documentazione e consegna

- Aggiornare ARCHITECTURE.md con capability del tool, ruoli abilitati, provenance, backend/versioning, risorse e fallback; descrivere anche la semantica di `tool_calls_details`.
- Consegnare configurazione riproducibile, implementazione, test mirati, esempio di risultato del tool e di telemetria con zero/nonzero, istruzioni A/B e limiti osservati.
- Non eseguire lint sull'intero progetto. Non dichiarare guadagni di precisione sulla sola base dei test di integrazione; documentare eventuali impedimenti reali a benchmark o smoke test.

## P1 e P2 — Estensioni successive

P1: query forward da input verso utilizzi/sink, validata con casi noti e capability esplicita; esposizione opzionale al Worker per risolvere dubbi sul prossimo probe; estensione dei frontend verificati; semantiche di wrapper/framework dove i benchmark mostrano lacune. Progettare l'adattatore P0 per accoglierle senza implementare anticipatamente un sistema di plugin generico.

P2: backend alternativi quali CodeQL per stack supportati, collegamenti fra servizi/linguaggi e UI di confronto telemetrico. Queste capacità non sono prerequisiti per consegnare il P0.
