# Piano P0 — ReaderCheckpoint opzionale e comparabile

Data: 20 settembre 2026.

Stato: piano implementativo, nessuna modifica al loop ancora applicata.

## Obiettivo

Introdurre un `ReaderCheckpoint` tool-free, eseguito sulla stessa conversazione del
Reader nei punti in cui oggi scatterebbe l'Exploration Reviewer. La nuova strategia
deve essere opzionale, disattivata per default e selezionabile per run, così da poter
confrontare i due percorsi sullo stesso benchmark e tornare immediatamente al
comportamento corrente senza revert del codice.

Il P0 non elimina il ruolo Reviewer in generale. Sostituisce soltanto le review
esplorative ordinarie di una tranche Reader. Restano invariati:

- Lead Novelty Reviewer;
- review semantica delle `AreaEnrichmentLead`;
- Reviewer del Confirmer;
- ledger, scheduling delle aree e gate deterministici di completion;
- budget complessivo e soglie che fanno scattare il boundary.

La domanda dell'esperimento e' circoscritta: a parita' di trigger, piano Recon,
modello Reader e budget, un checkpoint append-only del Reader conserva qualita' e
controllo del lifecycle consumando meno EP e sfruttando meglio la cache?

## Evidenza che motiva il P0

Nella run YesWiki Recon/Reader `20260920-194109`:

- Reader: 301.608,88 EP, cache input 84,7%;
- Reviewer: 206.829,60 EP, cache input 3,0%;
- 16 exploration review: 14 `continue_current`, 2 `close_area`, 0 pivot;
- una proposta `approve_area_enrichment` senza enrichment e' stata normalizzata
  dall'orchestratore;
- il processo e' arrivato al timeout con 2 aree chiuse, 1 attiva e 13 in coda.

Le run Reader dedicate recenti confermano un'incidenza Reviewer nell'ordine del
42-50% degli EP Reader+Reviewer. Esiste valore nei checkpoint e in alcune direttive,
ma non evidenza sufficiente che tale valore richieda una seconda conversazione
stateless con copia portabile della history.

Artifact di riferimento:

- `plans/REVIEW-recon-reader-yeswiki-20260920-194109.md`;
- `storage/app/runs/yeswiki/recon-reader-global/yeswiki-recon-reader-global-20260920-194109/`.

## Scope

### P0 — necessario ora

1. Strategia selezionabile `reviewer` oppure `reader_checkpoint`, con `reviewer`
   come default compatibile.
2. `ReaderCheckpoint` piatto, tool-free e prodotto dal modello Reader nella sua
   `ReaderConversationState` esistente.
3. Attivazione negli stessi identici boundary che oggi invocano
   `_review_tranche()`, senza nuove soglie o timer.
4. Normalizzazione del checkpoint nel contratto interno gia' applicato da
   `_apply_reader_review()`, evitando un secondo lifecycle.
5. Telemetria sufficiente a confrontare costo, cache, decisioni ed esiti delle due
   strategie.
6. Esposizione del flag nel benchmark Recon/Reader e persistenza della strategia
   scelta nell'outcome.
7. Test offline mirati, senza agent loop o chiamate provider.
8. Aggiornamento di `ARCHITECTURE.md`, perche' cambia l'autorita' model-facing del
   checkpoint Reader quando l'opzione e' attiva.

### P1+ — non incluso nel primo esperimento

- rimuovere definitivamente l'Exploration Reviewer;
- sostituire o fondere il Lead Novelty Reviewer;
- cambiare priorita', concorrenza o granularita' degli incarichi Recon;
- aggiungere nuove soglie deterministiche di stagnazione;
- cambiare budget globale o grant Reader;
- abilitare `reader_checkpoint` per default nelle run ordinarie;
- ottimizzazioni provider-specifiche della prompt cache prima di avere misure.

## Opzione e rollout

Usare un'enumerazione, non un booleano ambiguo:

```text
--reader-checkpoint-strategy=reviewer|reader_checkpoint
```

Semantica:

- `reviewer`: comportamento attuale, Exploration Reviewer stateless;
- `reader_checkpoint`: agli stessi boundary viene eseguito il self-checkpoint
  tool-free sulla conversazione Reader.

Default: `reviewer`.

P0 di esposizione:

1. aggiungere l'opzione al comando Python `pentest recon --global --with-reader`;
2. propagarla da Laravel `benchmark:recon-reader`;
3. validarla una sola volta all'ingresso e conservarla in `Deps` oppure
   nell'orchestratore come valore tipizzato;
4. registrarla in telemetria e in `report.recon_reader_benchmark`.

Non usare una variabile globale mutabile di `settings` per scegliere la strategia:
la scelta appartiene alla singola run e deve essere visibile nell'artifact. Il default
esplicito garantisce rollback immediato tramite CLI e preserva ogni caller esistente.

L'estensione della stessa opzione al comando audit globale ordinario puo' essere
aggiunta nello stesso diff soltanto se e' un semplice pass-through del medesimo valore.
Non e' necessaria per validare il P0 e non deve moltiplicare i percorsi applicativi.

## Contratto model-facing

Introdurre un output piatto dedicato, senza DTO interni, ID o copie del ledger:

```python
class ReaderCheckpoint(BaseModel):
    decision: Literal["continue", "area_closed"]
    reason: str
    next_step: str | None = None
    checkpoint_summary: str
    category_notes: str | None = None
```

Regole:

- `continue`: `next_step` obbligatorio, singolo e discriminante;
- `area_closed`: `next_step` assente; il modello non sceglie la prossima area;
- `checkpoint_summary`: narrativa bounded di evidenza positiva/negativa, rami
  esclusi, rami aperti e conclusioni realmente osservate;
- `category_notes`: opzionale e mantenuto soltanto per preservare la memoria
  condivisa gia' offerta dal Reviewer corrente;
- nessun `area_id`, `next_area_id`, lead ID, source ref ID o stato di emissione
  model-facing: l'orchestratore li ricostruisce dallo snapshot autorevole;
- nessun `pivot`, `finish_pass` o decisione enrichment nel contratto P0.

Il validator deve rifiutare `continue` senza `next_step` e normalizzare stringhe
vuote. Non deve chiedere al modello di ricopiare informazioni ricostruibili dal
ledger.

## Prompt del checkpoint

Il prompt deve essere breve e descrivere una sola decisione:

1. valuta esclusivamente l'evidenza gia' nella conversazione;
2. non usare tool e non iniziare nuove letture;
3. scegli `continue` soltanto se resta una singola prossima azione statica concreta
   che puo' cambiare la decisione di chiusura o produrre una lead;
4. scegli `area_closed` quando non restano letture discriminanti nell'incarico;
5. non dichiarare emessa una lead in base alla narrativa: ledger e output acquisiti
   sono autorevoli;
6. non trasformare nomi, convenzioni o supposizioni in controlli verificati;
7. conserva nel checkpoint incertezza e handoff verso altri incarichi senza
   scegliere direttamente la prossima area.

Il prompt puo' ricordare l'ultima direttiva e il suo esito autorevole. La valutazione
di ripetizione resta semantica: non introdurre conteggi di tool call o euristiche
deterministiche di stuck nel P0.

## Integrazione minima nel loop

### 1. Conservare i trigger attuali

Non cambiare i punti che generano il boundary:

- `ReaderReviewRequested`;
- soft/hard cognitive limit;
- intervallo massimo `reader_review_request_interval`;
- boundary maturato dopo il ritorno di una lead;
- recovery tecnico che oggi richiede un checkpoint.

L'esperimento deve confrontare chi decide al boundary, non quando il boundary
avviene. La telemetria deve continuare a registrare il motivo originale.

### 2. Separare il dispatch dalla decisione

Estrarre o introdurre un dispatch ristretto, concettualmente:

```text
review_reader_boundary(proposal, snapshot):
    if proposal is AreaEnrichmentLead:
        return Exploration Reviewer attuale
    if strategy == reviewer:
        return Exploration Reviewer attuale
    return ReaderCheckpoint sulla conversazione Reader
```

La novelty review non passa da questo dispatch.

### 3. Riutilizzare la conversazione Reader

Seguire il pattern di `_checkpoint_confirmer()`:

- stesso modello Reader;
- stessa `ReaderConversationState`;
- stesso `session_id` ed epoch;
- checkpoint appendibile alla history gia' osservata;
- `completion_budget_guaranteed=True` soltanto durante la richiesta di checkpoint;
- un tentativo piu' la riserva di retry structured-output gia' configurata;
- nessun tool operativo disponibile nella richiesta;
- accounting sotto il ruolo `reader`, non `reviewer`.

Il normale agente Reader deve essere ricostruito/ripreso dopo il checkpoint con il
contratto operativo originale. Il checkpoint acquisito resta nella conversation
history; non aggiungere anche una copia narrativa `ESITO EXPLORATION REVIEWER`, salvo
una correzione autorevole prodotta dalla normalizzazione.

### 4. Adattare al lifecycle esistente

Convertire deterministicamente `ReaderCheckpoint` nell'`ExplorationReview` interno:

- `continue` -> `continue_current`;
- `area_closed` -> `close_area`;
- area, checked surfaces e source reference dallo snapshot/ledger;
- `next_action` da `next_step`;
- summary da `checkpoint_summary`.

Poi riusare `_apply_reader_review()` e `_close_active_area()`. Non duplicare grant,
transizioni epoch, persistenza o completion gate.

Su `area_closed` l'orchestratore seleziona la successiva area queued. Su `continue`
applica lo stesso grant oggi assegnato dopo `continue_current`. Se il boundary era
cognitivo/tecnico, usa il checkpoint per la stessa `_transition_reader_epoch()` gia'
prevista; negli altri casi prosegue append-only nella stessa epoch.

### 5. Mantenere Reviewer per enrichment

`AreaEnrichmentLead` resta una decisione eccezionale dell'Exploration Reviewer,
anche quando la strategia e' `reader_checkpoint`. Evita di ampliare il contratto
binario e mantiene rollbackabile il test. La relativa spesa Reviewer deve apparire
separata dalla spesa dei checkpoint ordinari.

## Cache: ipotesi e guardrail

La stessa history e lo stesso session ID non garantiscono da soli un cache hit.
Tool list e output schema fanno parte della richiesta provider e possono modificare
il prefisso cacheabile. Il P0 deve quindi:

- usare lo stesso system prompt Reader; il prompt di checkpoint e' un nuovo messaggio
  user, non un system prompt alternativo;
- evitare dossier o transcript duplicati nella richiesta;
- seguire prima il pattern tool-free gia' usato dal Confirmer;
- misurare input totale, cached e uncached per ogni `ReaderCheckpoint`;
- non dichiarare riuscita l'ottimizzazione soltanto perche' la conversazione Python
  e' la stessa.

Se il provider non conserva il prefisso a causa del cambio tool/output schema, il
checkpoint resta valido funzionalmente ma l'ipotesi economica non e' confermata.
Qualsiasi tentativo successivo di stabilizzare esattamente tool schema e output union
e' P1: non tenere tool realmente utilizzabili durante il checkpoint soltanto per
forzare la cache.

## Telemetria e artifact

Persistire almeno:

- `reader_checkpoint_strategy`;
- `reader_self_checkpoints`;
- decisioni `continue|area_closed`;
- motivo del boundary;
- richieste, input/cached/uncached/output token ed EP dei checkpoint;
- epoch e session ID non sensibile/identificatore interno gia' usato;
- decisione proposta, decisione applicata ed eventuale normalizzazione;
- fallback tecnico;
- numero di review enrichment rimaste al Reviewer;
- costo Reviewer separato fra exploration, novelty ed enrichment, se ottenibile
  senza rifare l'accounting.

Conservare anche i contatori concettuali di exploration boundary, cosi' i grafici
esistenti possono confrontare le strategie. Non far apparire un self-checkpoint come
richiesta Reviewer e non sommare due volte il costo nel report benchmark.

Nel report Recon/Reader aggiungere la strategia accanto a
`functional_assignments_v1`; non cambiare schema o significato delle lead acquisite.

## Fallback e failure mode

- Errore tecnico o structured-output exhaustion del self-checkpoint: usare il
  checkpoint deterministico corrente e proseguire conservativamente, come gia'
  avviene per il Reviewer; non chiamare automaticamente il Reviewer, altrimenti il
  confronto economico diventa opaco.
- Budget insufficiente per il checkpoint: conservare stato e terminare/applicare il
  boundary economico senza inferire `area_closed`.
- `area_closed` normalizzato ma area gia' cambiata: mantenere la protezione stale
  review esistente.
- `continue` senza next step valido dopo i retry: fallback conservativo, senza
  inventare una direttiva tecnica.
- `AreaEnrichmentLead`: percorso Reviewer attuale.

Non introdurre una cascata automatica `ReaderCheckpoint -> Reviewer` nel P0. Un flag
deve selezionare un percorso misurabile, non eseguirli entrambi nei casi difficili.

## Verifiche offline

Usare modelli finti e test mirati; non avviare agent loop reali.

### Contratto

- schema piatto e sole decisioni `continue|area_closed`;
- `continue` richiede `next_step` non vuoto;
- `area_closed` non accetta una scelta model-facing della prossima area;
- nessun ID autorevole richiesto al modello.

### Compatibilita' e rollback

- opzione assente -> percorso Reviewer identico all'attuale;
- `reviewer` esplicito -> stesso risultato del default;
- valore sconosciuto -> errore CLI chiaro prima della run;
- `reader_checkpoint` -> nessuna Exploration Review ordinaria;
- cambio del flag fra due run non lascia stato globale condiviso.

### Trigger equivalenti

Per ciascun trigger attuale verificare che il numero e la posizione dei boundary
siano uguali nelle due strategie:

- intervallo richieste;
- `ReaderReviewRequested`;
- soft/hard context pressure;
- boundary dopo lead;
- recovery tecnico.

### Lifecycle

- `continue` assegna lo stesso grant e riprende il Reader;
- `area_closed` chiude l'area snapshot e apre la prossima queued;
- chiusura dell'ultima area passa ancora da `_can_complete_discovery()`;
- checkpoint stale non chiude l'area successiva;
- summary e category notes persistono nel report;
- lead e source ref provengono dal ledger, non dal testo del checkpoint;
- history contiene una sola copia del checkpoint;
- compaction avviene soltanto negli stessi boundary cognitivi/tecnici.

### Eccezioni mantenute

- `AreaEnrichmentLead` usa ancora Exploration Reviewer;
- Lead Novelty Reviewer resta invariato;
- una prima lead priva di antecedenti continua a non richiedere novelty review;
- accounting enrichment/novelty resta sotto `reviewer`.

### CLI e Laravel

- test Typer per default, valore valido e valore invalido;
- test del comando `benchmark:recon-reader` per il pass-through dell'argomento;
- outcome e payload benchmark registrano la strategia scelta;
- repetitions applicano la stessa strategia a ogni run indipendente.

Non eseguire lint globale. Eseguire soltanto i test Python e Laravel direttamente
coinvolti.

## Esperimento comparativo successivo all'implementazione

Non eseguirlo automaticamente durante il task implementativo. Comando e run a
pagamento richiedono avvio esplicito dell'utente.

Disegno minimo:

1. stessa Golden Recon o stesso piano Recon congelato;
2. stesso snapshot target;
3. stesso Reader model/provider/reasoning;
4. stesso budget e timeout;
5. strategia A `reviewer`, strategia B `reader_checkpoint`;
6. almeno tre repetition indipendenti se il costo e' accettabile.

Metriche primarie:

- lead semanticamente valide per 100k EP discovery;
- aree chiuse per 100k EP;
- EP Reader + checkpoint prima del cutoff;
- cached/uncached input dei soli checkpoint;
- tempo e richieste Reader per area chiusa;
- duplicati/root cause distinti;
- fedelta' checkpoint-ledger e affermazioni non supportate;
- coverage degli anchor/file del benchmark, senza equipararla automaticamente a
  vulnerabilita' trovate.

Metriche secondarie:

- distribuzione `continue|area_closed`;
- ripetizione semantica del medesimo next step;
- output retry e fallback tecnici;
- compaction ed epoch create;
- aree ancora queued al cutoff.

## Criteri di successo P0

Il P0 e' implementato quando:

1. il default esegue ancora il Reviewer corrente senza regressioni;
2. il flag `reader_checkpoint` sostituisce ogni review esplorativa ordinaria negli
   stessi boundary;
3. checkpoint e decisione sono prodotti dal Reader tool-free nella stessa
   conversazione e applicati dal lifecycle esistente;
4. novelty ed enrichment mantengono il Reviewer;
5. outcome e telemetria permettono di attribuire token, cache ed EP alla strategia;
6. test offline coprono continuazione, chiusura, stale decision, fallback e
   pass-through CLI;
7. `ARCHITECTURE.md` descrive entrambe le strategie e il default;
8. nessuna run provider e' stata avviata automaticamente.

Il successo economico non fa parte della sola implementazione: richiede che il
confronto reale mostri riduzione di EP senza perdita materiale di discovery o
fedelta' della memoria.

## File attesi nel diff implementativo

Lista indicativa, da restringere dopo la lettura puntuale del codice:

- `agent/pentest-agent/src/pentest_agent/models.py`;
- `agent/pentest-agent/src/pentest_agent/deps.py` oppure stato orchestratore equivalente;
- `agent/pentest-agent/src/pentest_agent/triple_agent.py`;
- `agent/pentest-agent/src/pentest_agent/cli.py`;
- `agent/pentest-agent/tests/test_triple_agent.py`;
- `agent/pentest-agent/tests/test_recon_reader_assignments.py`;
- `app/Console/Commands/BenchmarkReconReader.php`;
- `tests/Unit/Pentest/BenchmarkReconReaderCommandTest.php`;
- `ARCHITECTURE.md`.

Evitare nuovi moduli, servizi o astrazioni se il dispatch, il model factory e
l'adattamento all'`ExplorationReview` interno possono restare locali al lifecycle
Reader esistente.
