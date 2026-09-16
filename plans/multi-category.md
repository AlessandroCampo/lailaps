La implementerei come concorrenza a coppie “a ondate”:

```text
Global Recon
   ↓
A01 + A02 in parallelo
   ↓ attesa di entrambe
merge deterministico
   ↓
A03 + A04 in parallelo
   ↓
...
```

È preferibile a una coda “rolling”, dove A03 partirebbe appena termina una delle prime due: mantiene deterministici memoria globale, handoff, budget e ordine del report.

## P0

1. Estrarre l’esecuzione della singola categoria

Sposterei il corpo dell’attuale ciclo di [`multi_category.py`](C:/Users/Alessandro/Desktop/latest-projects/lailaps/agent/pentest-agent/src/pentest_agent/multi_category.py:187) in un metodo privato, indicativamente:

```python
async def _run_category(...) -> CategoryRunResult
```

Il risultato conterrebbe report, handoff, consumo economico ed eventuale errore. La coroutine non modificherebbe direttamente le collezioni aggregate.

2. Eseguire batch da due

Solo con `global_mode=True`:

```python
for batch in batched(self.categories, 2):
    results = await asyncio.gather(
        *(self._run_category(...) for category in batch)
    )
    merge_results_in_category_order(results)
```

La modalità ordinaria resterebbe sequenziale e invariata.

Userei `gather()` senza lasciare propagare errori category-local: il fallimento di A01 non deve cancellare A02 né impedire l’avvio della coppia successiva, coerentemente con il comportamento globale attuale.

3. Congelare il contesto all’inizio della coppia

Entrambe le categorie della stessa coppia riceverebbero:

- la stessa Global Recon;
- la stessa memoria globale;
- lo stesso registro delle lead proveniente dalle coppie precedenti;
- gli stessi handoff precedenti.

Solo quando entrambe terminano, i risultati vengono incorporati in [`GlobalRunState`](C:/Users/Alessandro/Desktop/latest-projects/lailaps/agent/pentest-agent/src/pentest_agent/global_run.py:135), rispettando l’ordine effettivo delle categorie, non quello di completamento.

Questo evita che una categoria lenta o veloce renda il risultato non deterministico.

4. Rendere il budget esplicitamente category-scoped

Non condividerei direttamente l’attuale `global_ceiling`: è un singolo valore mutabile nel [`BudgetBroker`](C:/Users/Alessandro/Desktop/latest-projects/lailaps/agent/pentest-agent/src/pentest_agent/budget.py:168). Due orchestratori concorrenti finirebbero per sovrascriverselo.

Aggiungerei quindi una piccola “budget lease” per categoria:

- la coppia viene ammessa atomicamente dal `GlobalBudgetCoordinator`;
- ogni categoria riceve un envelope immutabile;
- la somma dei due envelope non può consumare le riserve delle categorie future;
- il consumo continua a confluire nel broker globale;
- gli scope delle lead vengono prefissati con la categoria, ad esempio `A01:lead-1`, evitando collisioni nel broker condiviso;
- al termine della coppia, entrambi gli envelope vengono regolati e l’inutilizzato torna disponibile alla coppia successiva.

È necessaria anche un’operazione `enter_batch(categories)`: chiamare due volte l’attuale `enter()` assegna gli envelope in modo dipendente dall’ordine e può concedere complessivamente più headroom di quello realmente disponibile.

5. Conservare una sola pubblicazione globale

Durante l’esecuzione terrei una mappa dei report live delle due categorie attive. Ogni checkpoint ricostruirebbe l’aggregato includendo:

- categorie già concluse;
- entrambi i report live della coppia;
- ordine canonico A01…A10.

La scrittura atomica è già presente nell’[`ArtifactStore`](C:/Users/Alessandro/Desktop/latest-projects/lailaps/agent/pentest-agent/src/pentest_agent/artifacts.py:21); va però evitato che ciascuna coroutine costruisca l’aggregato da uno snapshot incompleto dell’altra.

6. Handoff al confine della coppia

Gli handoff prodotti dalla coppia diventerebbero disponibili alla coppia successiva. Le due categorie contemporanee non possono logicamente consumare l’handoff l’una dell’altra.

Quindi `needs_handoff` dovrebbe significare “esiste una coppia successiva”, non semplicemente “esiste una categoria successiva”.

7. Test mirati

Aggiungerei test in [`test_multi_category.py`](C:/Users/Alessandro/Desktop/latest-projects/lailaps/agent/pentest-agent/tests/test_multi_category.py:330) per verificare:

- massimo due categorie attive;
- A03 parte solo dopo la conclusione sia di A01 sia di A02;
- un fatal in A01 non cancella A02 o la coppia successiva;
- risultati e global lead ID restano nell’ordine richiesto anche se A02 termina prima;
- A03/A04 ricevono i risultati di entrambe le categorie precedenti;
- envelope e consumo globale non superano il cap;
- i checkpoint live non perdono lo stato dell’altra categoria.

Eseguirei soltanto i test interessati, senza lint globale.

8. Aggiornamento architetturale

La modifica cambia scheduling, consistenza della memoria e gestione del budget globale, quindi aggiornerei obbligatoriamente [`ARCHITECTURE.md`](C:/Users/Alessandro/Desktop/latest-projects/lailaps/ARCHITECTURE.md:76), descrivendo batch da due, snapshot al confine della coppia e budget lease.

## Trade-off esplicito

Due categorie della stessa coppia non vedranno in tempo reale le nuove lead dell’altra. Potrebbero quindi produrre due finding semanticamente sovrapposti. Rendere il registro live introdurrebbe invece risultati dipendenti dalla velocità delle coroutine.

Per il P0 sceglierei la consistenza per coppia, deterministica e più semplice.

## P1+ non incluso

- parametro configurabile `--global-concurrency`;
- scheduler rolling con massimo due categorie;
- novelty review centralizzata per deduplicare semanticamente le lead prodotte dalla stessa coppia.

Non modificherei agenti, output model-facing o contratti Pydantic: il cambiamento rimarrebbe confinato all’orchestrazione e al budget. Nessun file è stato modificato.