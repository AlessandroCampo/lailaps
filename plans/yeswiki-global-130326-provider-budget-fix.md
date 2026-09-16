# Piano di fix: provider e finanziamento lead

Run di riferimento: `yeswiki-global-20260913-130326`.
Verifica: 13 settembre 2026. Piano soltanto: nessuna modifica al runtime o run API eseguita.

## Obiettivo e scope

P0: rendere affidabile la prosecuzione del lavoro e finanziare verifiche utili anche con
`budget-category=small`. Small deve ridurre la quantità di esplorazione, senza dimezzare
automaticamente la capacità operativa di ogni ruolo di conferma. Riutilizzare broker,
stage, conversazioni e ledger esistenti. Nessun nuovo agente.

P1: memoria dell'esplorazione, riduzione ulteriore del contesto e ottimizzazione della
copertura. Non subordinare il P0 a questi interventi.

## Diagnosi verificata

- Run: 2.047.547 punti consumati su 4.450.000; residuo 2.402.453; 6 fatal con messaggio
  `Deadline model turn ... esaurita dopo 240s`; 0 conferme dinamiche.
- Il finanziamento esiste: `unlock_confirmer_stage`, `unlock_worker_stage` e
  `fund_worker_retry` in `budget.py`. Judge e retry hanno già fondi distinti.
- `TripleAgentOrchestrator.__init__` moltiplica ANCHE gli stage per il preset.
  Nella run il peso input GLM è 0,2 e small è 0,5: Confirmer = 150k × 0,2 × 0,5 = 15k;
  Worker = 100k × 0,2 × 0,5 = 10k; Judge = 5k; retry Worker = 7,5k.
  Il peso per modello non è di per sé un bug: viene applicato anche al consumo.
  Il taglio small e la dimensione nominale delle tranche sono il problema operativo.
- Il Worker ha circa 32,7k token medi di input per richiesta. Una richiesta senza cache
  vale circa 6,55k punti di solo input: la tranche iniziale da 10k è troppo piccola per
  una sequenza setup, probe, osservazione e proposta. La cache aiuta, ma il minimo
  operativo deve funzionare anche al primo ingresso senza cache.
- Le quattro pipeline dinamiche hanno ricevuto tutte un grant di retry, arrivando a
  17,5k punti Worker e 10k Judge. A01 ha consumato 40.471 punti Worker: il grant viene
  assorbito dal consumo precedente e il retry non può partire. Non manca il routing:
  esiste un gate esplicito per residuo inutilizzabile dopo il grant.
- I fondi Judge non sono tutti esauriti: A01 ha circa 7,2k residui dopo il grant.
  Non attribuire ogni mancato retry al Judge affamato.
- Due pipeline hanno fatto HTTP: A01 tre richieste, A02 una; A07 e A09 zero.
  Non è corretto descrivere tutte e quattro come prive di transazioni HTTP.
- Gli slot globali sono 40, di cui 4 usati. Il limite degli slot non ha fermato questa run.
- `_run_role` applica 240s all'intera `_run_role_once`, che può includere molte richieste
  del modello e tool, oltre ai retry. Quando scade `asyncio.wait_for`, l'eccezione viene
  rilanciata immediatamente. Non sono sei misurazioni di singole richieste provider
  ferme per 240s. Gli errori Together sono reali, ma le due cause vanno separate.
- `http_call` salva gli header nello snapshot e nel ledger; il preview usa una allowlist
  che esclude CORS e `inspect_response` non mostra gli header.
- I prompt Confirmer e Worker contengono già prima richiesta concreta, barriera esplicita
  e invito a non rifare l'analisi statica. Non serve riproporre come nuova questa modifica.
- $0,8789 è `estimated_cost_usd`, non una fattura verificata. I campi costo provider
  hanno copertura/aggregazioni non uniformi: non dimensionare il budget assumendo che
  la spesa effettiva sia precisamente 88 centesimi.

## P0.1 — Routing esplicito e timeout al confine corretto

### Routing

Il catalogo pubblico OpenRouter consultato espone per `deepseek/deepseek-v4.1-flash`
l'endpoint `deepseek`, con tools, tool_choice e reasoning. Primo esperimento:

```json
{"provider": {"only": ["deepseek"], "allow_fallbacks": false}}
```

Applicarlo soltanto alle richieste del modello DeepSeek interessato, compresi Recon,
Reader, Handoff e chiamate accessorie che lo usano. Non applicarlo ai ruoli GLM.
Integrare la preferenza nel percorso comune di costruzione del modello/request, senza
disseminare condizioni nei singoli agenti. Mantenere modello e reasoning attuali per
isolare l'effetto. Salvare policy richiesta e provider realmente osservato, se disponibile.

`order: ["deepseek"]` da solo è una preferenza, non un pin: può ricadere su altri provider.
Il pin elimina Together dal percorso, ma non dimostra che DeepSeek sia più affidabile.
Un secondo provider ammesso si aggiunge dopo un confronto, senza fallback indiscriminato.

### Timeout e ripresa

Spostare la deadline assoluta da sessione del ruolo a singola richiesta del modello,
nel confine già percorso da `_stream_role`. Conservare un timeout di inattività del
trasporto separato. Come punto di partenza: 120s di inattività e 240s per richiesta;
i limiti economici e di richieste continuano a contenere la sessione completa.
Il tempo dei tool usa i rispettivi timeout e non consuma la deadline della risposta LLM.

Un errore transitorio riprova la richiesta interrotta con tentativi limitati e backoff,
senza rieseguire l'intera investigazione. Riutilizzare la policy di retry esistente e
verificare il numero totale di tentativi fra SDK, transport e orchestratore, evitando
moltiplicazioni implicite. Distinguere errori transitori, errori di configurazione e
fallimenti di validazione: non riprovare indiscriminatamente credenziali o input invalidi.

Preservare messaggi completati, risultati tool, lead e riferimenti autorevoli. Non
rieseguire automaticamente HTTP/tool con effetti già avvenuti. Un output parziale del
modello non diventa una lead accettata; riprendere dagli ultimi messaggi validi.
Esauriti i tentativi, conservare l'incompletezza tecnica e le evidenze, senza inventare
un verdetto o dichiarare copertura completa. Il salto categoria resta un ultimo esito
di indisponibilità persistente, non la conseguenza ordinaria di quattro minuti di lavoro.

Verifica offline: una sessione con più richieste sane può superare 240s complessivi;
una singola richiesta appesa scade; un'interruzione dopo un tool non duplica il tool;
i retry finiscono dopo il limite previsto. Usare clock e transport simulati.

File principali: `def_model.py`, `triple_agent.py`, `config.py`, `provider_usage.py`.

## P0.2 — Small restringe l'esplorazione; ogni stage ammesso è operativo

### Separare due decisioni già presenti

1. Preset: scala discovery/ampiezza e tetto complessivo della run.
2. Finanziamento per lead: tranche nominali indipendenti dal preset, ancora pesate
   coerentemente per il modello. Nessun moltiplicatore small sui fondi di verifica.

Il residuo globale non è automaticamente spendibile da uno stage locale: l'admission
deve intersecare tranche, ceiling di categoria e cap globale. Rendere espliciti questi
vincoli, invece di mostrare genericamente "budget esaurito".

Proposta iniziale da calibrare, in punti anchor-equivalent:

| Stage | Oggi nominale | Proposto | Effettivo GLM nella run, oggi → proposto |
|---|---:|---:|---:|
| Confirmer per lead | 150.000 | 250.000 | 15.000 → 50.000 |
| Worker iniziale | 100.000 | 300.000 | 10.000 → 60.000 |
| Judge per round | 50.000 | 75.000 | 5.000 → 15.000 |
| Worker per retry | 75.000 | 200.000 | 7.500 → 40.000 |

Queste sono basi sperimentali, non una promessa di sufficienza per qualsiasi finding.
60k punti consentono circa nove input medi Worker senza cache prima di conteggiare
l'output, contro circa uno e mezzo oggi. Mantenere i request limit esistenti nel primo
intervento; rivederli solo se diventano il vincolo effettivo.

### Contratto delle transizioni

- ReaderLead nuova e ammessa: sblocca il Confirmer completo. Il Reader continua a essere
  pagato dalla discovery; non finanziare di nuovo duplicati o riaperture idempotenti.
- CandidateHandoff accettato: sblocca Worker completo e Judge separato. Il consumo del
  Confirmer non riduce il finanziamento Worker. Una chiusura statica non consuma slot
  dinamici e non riserva Worker inutilmente.
- Judge `retry_worker`: se ammesso, rende disponibile una nuova tranche utilizzabile
  e il successivo Judge. Contare il grant come eseguito solo quando finanziamento e
  admission hanno avuto successo; evitare grant positivi con zero richieste possibili.
- Il budget non determina verità tecnica: esaurimento lascia suspected/incomplete,
  con motivo economico e prossimo test, mai un falso verdetto di non vulnerabilità.

Prima di finanziare uno stage, controllare che il residuo effettivo possa sostenerlo.
Se manca cap globale, fermare nuova esplorazione/ammissione e completare il lavoro già
finanziato. Conservare comunque le lead emerse senza fondi. Riutilizzare broker e
coordinatore esistenti per proteggere gli impegni, senza introdurre un secondo allocatore.
Le categorie future conservano la riserva già prevista: se la garanzia non è possibile,
non avviare uno stage con una frazione simbolica del finanziamento promesso.

Per il primo confronto mantenere esplicito il cap globale della baseline, 4,45M punti:
gli stage più grandi cambiano l'allocazione e quante pipeline possono essere ammesse,
non autorizzano da soli una moltiplicazione del tetto. Disaccoppiare quindi il cap di
sicurezza dalla formula che oggi somma i massimi stage × slot. Fuori dal confronto,
il cap continua a essere una decisione della configurazione/preset. Nessun grant illimitato.

### Correggere lo sforamento che mangia i retry

Separare in contabilità l'investigazione dal costo della chiusura tool-free e dei relativi
retry di output. Oggi `record_usage` carica tutto sullo stage; `terminal_overshoot_points`
misura soltanto il superamento del cap complessivo, quindi può valere zero con uno stage
largamente sforato. Rendere visibile lo sforamento dello stage e la sua causa.

Il nuovo finanziamento deve partire dal consumo già regolato: coprire l'eventuale
sforamento precedente e aggiungere la tranche utile, oppure negare esplicitamente
l'ammissione se il cap non lo permette. Non azzerare consumo, non cancellare costi,
non imputare gli stessi token due volte. Una piccola riserva di chiusura, calcolata con
i limiti terminali già esistenti, va inclusa nell'ammissione dello stage.

Verifiche offline mirate: small e regular hanno gli stessi stage; unlock idempotenti;
stage isolati; consumo cold-cache; retry dopo lo sforamento A01; Judge preservato;
cap globale e riserve categorie rispettati; fallimento di admission senza falso grant.

File: `budget.py`, costruzione broker e `_grant_*`/`_confirm` in `triple_agent.py`,
`multi_category.py`, `global_run.py`, `config.py` e test economici esistenti.

## P0.3 — Evidenze accessibili e continuità Worker

Estendere `inspect_response` con lettura bounded degli header, anche per nome, usando
lo snapshot esistente. Nel preview HTTP mostrare gli header di sicurezza/CORS utili;
un header non mostrato non deve essere interpretato come assente. Preservare le
redazioni di segreti e gli attributi dei cookie necessari alle verifiche. Non servono
nuovi client HTTP o nuovi artifact di risposta.

Verificare che il Judge riceva i riferimenti canonici della stessa transazione.
Normalizzare request_id/response_id soltanto quando il mapping nel ledger è univoco;
un riferimento inventato non diventa evidenza valida. Coprire A02 con risposta simulata,
senza vincolare il comportamento alla vulnerabilità CORS specifica del benchmark.

Per l'handoff, riutilizzare `verification_plan`, risultati setup e progressi esistenti:
prima azione concreta, actor, prerequisiti soddisfatti, oracle e impedimento eventuale.
Verificare la proiezione realmente ricevuta dal Worker/retry prima di aggiungere prompt.
Non aggiungere DTO annidati né un obbligo deterministico di fare HTTP come primo tool:
alcuni test richiedono davvero autenticazione, dati o preparazione.

File: `runtime_tools.py`, proiezioni Worker/Judge in `triple_agent.py`, test HTTP esistenti.

## Consegna e verifica

Ordine: routing/deadline; finanziamento e overshoot; header e continuità.
Aggiornare `ARCHITECTURE.md` insieme alle modifiche strutturali, riconciliando descrizioni
e formule legacy con il broker globale e gli stage Judge effettivamente presenti.

Eseguire soltanto test offline mirati con fake model/transport e fixture sintetiche:
nessun lint globale, nessun loop agentico a pagamento autonomo.

Successivamente, validazione manuale con run brevi controllate: prima confrontare il
routing a budget invariato, poi introdurre il nuovo funding mantenendo il routing scelto.
Usare i benchmark condizionali esistenti su handoff rappresentativi per Worker/Judge,
prima di una nuova global. Non aggiungere informazioni CVE al contesto degli agenti.

Metriche: errori upstream per richiesta e provider; deadline locali separatamente;
durata; prima probe; percentuale Worker senza HTTP; grant concessi ma non avviati;
stage sforati; conversione statica→dinamica; costo provider osservato e copertura del
costo, separati dalla stima. Deduplicare per lead autorevole: l'outcome contiene anche
copie di stage ereditate da categorie precedenti, con zero richieste locali.

Criterio P0: un guasto transitorio recuperabile non perde lavoro completato; small
riduce discovery mantenendo stage utilizzabili; ogni retry ammesso può iniziare;
gli header necessari sono consultabili; cap e accounting restano verificabili.
La percentuale di conferme è una misura empirica, non una garanzia implementativa.

## P1 non incluso

- Memoria narrativa tra epoch e selezione di source ref per ridurre riletture/contesto:
  sviluppata nel [piano notebook e continuità degli agenti](agent-notebooks-memory-plan-20260913.md#p1--continuità-narrativa-e-selezione-delle-source-ref), aggiornato il 14 settembre.
  Separare appunti facoltativi da memoria operativa persistita e reiniettata di default,
  funzionante anche con zero note. Prima Reader: checkpoint d'area + delta dopo lead,
  ref selezionate dalla narrativa invece della sola recenza, recupero delle altre ref
  su richiesta. Poi verificare continuità su compaction, pivot, retry e cambio categoria
  per tutti i ruoli tramite checkpoint/handoff esistenti, mantenendo tool-free i supervisori.
  Nessun nuovo giro LLM per ogni reset; nessun riuso implicito fra audit distinti.
  La continuità Worker già prevista in P0.3 resta P0 e non viene rinviata a questa estensione.
- Coverage guidata dalle aree Recon e review semantica delle aree trascurate, generalista:
  nessuna regola hardcoded su `tools/bazar`.
- Riduzione del costo di Recon/Handoff e confronto con un secondo provider ammesso.
- Taratura ulteriore delle tranche sui benchmark dopo il P0, senza introdurre subito
  un allocatore LLM o un sistema automatico di budgeting adattivo.

## Fonti esterne

- Routing ufficiale: https://openrouter.ai/docs/guides/routing/provider-selection
- Catalogo endpoint consultato: https://openrouter.ai/api/v1/models/deepseek/deepseek-v4.1-flash/endpoints

Disponibilità e prestazioni degli endpoint vanno ricontrollate al momento del confronto.
