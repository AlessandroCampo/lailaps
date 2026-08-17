Voglio riprogettare la gestione del budget di Lailaps, in particolare per il **Reader**, in modo coerente con la nuova architettura append-only / provider-cache-aware che stiamo introducendo.

Lavora in **planning mode**: prima studia la codebase esistente (`dual_agent.py`, `BudgetBroker`, context budget, compression manager, telemetry, settings e usage provider), poi proponi un piano tecnico. Non implementare ancora.

## Problema attuale

Oggi il budget del Reader è principalmente espresso come quantità cumulativa di token.

Questo diventa poco rappresentativo quando utilizziamo provider prefix caching, perché:

- input cached e input non cached hanno costo molto diverso;
- output token hanno generalmente un costo molto superiore agli input;
- una run può accumulare milioni di raw input token pur avendo un costo economico relativamente basso grazie alla cache;
- la lunghezza della context window è un problema distinto dal costo economico cumulativo.

Voglio quindi separare completamente questi concetti.

# 1. Due sistemi indipendenti

L'architettura deve distinguere almeno:

## Economic budget

Serve esclusivamente a:

> impedire che una run consumi troppo budget API.

Non deve più essere espresso semplicemente come:

```text
input_tokens + output_tokens
```

Voglio invece un sistema astratto a **punti**.

## Context / quality budget

Serve esclusivamente a:

> evitare context window troppo grandi e perdita di qualità del modello.

Questo determina quando effettuare la compaction.

I due sistemi NON devono influenzarsi direttamente.

In particolare:

```text
economic pressure
→ eventualmente STOP della run
```

mentre:

```text
context pressure
→ COMPACTION
```

Non voglio più usare la compaction principalmente come meccanismo per ridurre il costo API.

---

# 2. Economic budget a punti

Il budget economico deve distinguere almeno tre categorie:

```text
uncached input tokens
cached input tokens
output tokens
```

Ogni categoria deve avere un peso configurabile.

Concettualmente:

```python
economic_points = (
    uncached_input_tokens * uncached_input_weight
    + cached_input_tokens * cached_input_weight
    + output_tokens * output_weight
)
```

Esempio puramente illustrativo:

```text
uncached input = 1 punto
cached input   = 0.1 punti
output         = 5 punti
```

NON hardcodare questi valori.

Devono essere settings/configurazione facilmente modificabili perché testo Lailaps con modelli diversi e i rapporti di pricing cambiano frequentemente.

Non voglio legare il core del budget a dollari/euro o al pricing corrente di uno specifico provider.

L'unità astratta "punti" deve permettermi di cambiare solamente i coefficienti.

---

# 3. Cached vs uncached input

Il codice già recupera metriche come `cache_read_tokens` quando disponibili.

Voglio che il sistema possa derivare:

```text
cached_input
uncached_input
```

per esempio:

```text
uncached_input = total_input - cached_input
```

quando semanticamente corretto per il provider/SDK corrente.

Studia però attentamente come i provider usati dal progetto espongono queste metriche.

Il sistema deve degradare correttamente quando un provider non espone cached token.

In quel caso, comportamento conservativo:

```text
cached = 0
uncached = input totale
```

o altra soluzione equivalente che non permetta accidentalmente di sottostimare il costo.

---

# 4. Output desiderato del budget

Voglio mantenere un output leggibile che mostri sia i raw token sia i punti economici.

Indicativamente:

```text
[budget:reader]

RAW
uncached input    180k
cached input      2.1M
output             95k

POINTS
uncached          180k
cached            210k
output            475k
----------------------
total             865k / 1.2M
```

Non è necessario usare esattamente questo formato.

L'importante è poter vedere distintamente:

- uncached input;
- cached input;
- output;
- punti generati da ciascuna categoria;
- punti totali;
- budget punti disponibile.

Se utile, mostra anche:

```text
cache hit ratio
```

come informazione diagnostica separata.

---

# 5. Raw token usage rimane utile

Non eliminare la telemetria raw.

Voglio continuare a conoscere:

```text
total input tokens
cached input tokens
uncached input tokens
output tokens
cumulative raw tokens
```

semplicemente questi valori non devono più essere il principale limite economico della run.

---

# 6. Reader budget

Attualmente il Reader ha un limite hard-fixed basato sui token cumulativi.

Voglio che questo concetto venga sostituito o reinterpretato.

Il Reader dovrebbe poter fare una run molto più lunga se:

- la maggioranza degli input token viene servita dalla cache;
- gli output rimangono sotto controllo;
- il budget economico a punti non è esaurito;
- la qualità della context window rimane buona.

Quindi non voglio più qualcosa semanticamente equivalente a:

```text
reader ha usato 600k raw tokens
→ STOP
```

se quei 600k sono economicamente molto economici.

Studia come modificare `BudgetBroker` senza creare un refactor eccessivamente invasivo.

---

# 7. Context budget completamente separato

La context window deve invece continuare ad essere misurata in **token reali della singola request/history corrente**.

Qui cache e pricing NON devono influire.

Un cached token continua comunque a occupare context.

Il context manager deve quindi ragionare su qualcosa come:

```text
current estimated context size
/
usable context capacity
```

e decidere quando iniziare la compaction.

---

# 8. Compaction molto meno frequente

Con il nuovo Reader append-only e provider-cache-aware, voglio cambiare la filosofia della compression.

Prima la compaction serviva contemporaneamente a:

- contenere la context window;
- contenere i token cumulativi/costo.

Adesso deve servire principalmente a:

- evitare context overflow;
- evitare degradazione qualitativa su context troppo lunghe;
- eventualmente mantenere margine per output/tool interactions.

Quindi voglio poter impostare una soglia molto più alta.

Esempio concettuale per un modello da 1M context:

```text
physical context limit: 1M
quality threshold:      ~500-600k
```

I numeri reali verranno determinati successivamente tramite eval.

Il punto architetturale è che la compaction threshold deve essere **indipendente dal budget economico**.

---

# 9. Soft/hard context limits

Valuta se ha senso distinguere:

```text
context soft limit
context hard limit
```

Per esempio:

```text
soft
→ pianifica/esegui compaction

hard
→ non permettere un'altra normale request che rischia overflow
```

Non è obbligatorio se la codebase ha già una soluzione equivalente.

Riutilizza per quanto possibile `context_budget_from_settings` e le strutture esistenti.

---

# 10. Cache epochs

Tieni conto della nuova strategia Reader:

```text
epoch append-only
→ context cresce
→ raggiunge quality/context threshold
→ compaction cache-aware
→ nuova epoch compatta
```

La compaction rompe intenzionalmente il prefix precedente dopo aver generato il checkpoint.

Questo è accettato.

Non effettuare compaction anticipata soltanto perché il cumulative raw token count è alto: una lunga epoch con cache hit elevato può essere economicamente migliore di numerose epoch piccole.

---

# 11. Request limit rimane separato

Voglio mantenere anche un limite sul numero di request/turni.

Anche se:

- il costo economico è basso;
- c'è ancora context disponibile;

il Reader potrebbe entrare in un loop poco produttivo.

Quindi concettualmente avremo almeno tre guardrail indipendenti:

```text
ECONOMIC POINT BUDGET
→ quanto possiamo spendere

CONTEXT / QUALITY BUDGET
→ quanto può crescere la working context prima della compaction

REQUEST LIMIT
→ quanto può continuare il loop
```

---

# 12. Worker

Non assumere automaticamente che il nuovo economic budget debba essere limitato al Reader.

La rappresentazione a punti può probabilmente diventare una primitive generale del `BudgetBroker`.

Tuttavia la modifica comportamentale principale richiesta riguarda il Reader.

Non cambiare in questa fase la strategia di compression del Worker.

Se una generalizzazione pulita del budget a punti permette Reader e Worker con pesi condivisi, proponila nel piano.

---

# 13. Configurazione

Vorrei poter configurare qualcosa concettualmente simile a:

```text
UNCACHED_INPUT_WEIGHT
CACHED_INPUT_WEIGHT
OUTPUT_WEIGHT

READER_POINT_BUDGET

READER_CONTEXT_SOFT_LIMIT
READER_CONTEXT_HARD_LIMIT
```

Non usare necessariamente questi nomi.

Studia il sistema `settings` attuale e proponi una configurazione coerente con il progetto.

I coefficienti devono poter cambiare facilmente tra benchmark/modelli.

---

# 14. Non trasformarlo in pricing engine

Non voglio costruire ora:

- listini per provider;
- pricing automatico per modello;
- conversione USD/EUR;
- fetch dinamico dei prezzi;
- billing system.

I "punti" devono essere intenzionalmente astratti.

Posso configurare manualmente, per esempio:

```text
1 / 0.1 / 5
```

oppure:

```text
1 / 0.25 / 8
```

a seconda del modello che sto testando.

---

# 15. Obiettivo finale

La nuova filosofia deve essere:

> consentire al Reader di fare il massimo lavoro utile possibile finché costo economico e qualità restano entro i limiti.

Non:

> fermare il Reader perché ha elaborato arbitrariamente molti token.

Una run potrebbe quindi avere milioni di raw input token cumulativi e rimanere perfettamente accettabile se:

```text
cache hit alto
+
pochi uncached token
+
output controllati
+
context corrente sotto la soglia qualitativa
+
economic points sotto budget
```

---

# Output richiesto

Non implementare.

Dopo aver studiato il repository, produci un piano che spieghi:

1. come funziona oggi `BudgetBroker`;
2. dove oggi vengono mescolati costo, raw token e context pressure;
3. come introdurresti il sistema economico a punti;
4. come distingueresti cached input, uncached input e output;
5. come renderesti visibile il breakdown nei log/telemetry;
6. come separeresti economic budget e context budget;
7. come cambierebbe il trigger della compression Reader;
8. quali settings modificheresti;
9. quali componenti/file toccheresti;
10. eventuali edge case dovuti a provider che non riportano cache usage;
11. come integreresti questa modifica con la nuova architettura append-only/cache-aware del Reader;
12. la tua raccomandazione finale per una soluzione minimale e pulita.

Evita per ora dettagli eccessivi di implementazione e test. Voglio prima decidere l'architettura sulla base del codice reale.