# Analisi delle prime due run global effettive — 12 settembre 2026

## Perimetro e metodo

Analisi offline di transcript, outcome e codice corrente. Nessun audit rilanciato e nessuna modifica al runtime. Il working tree contiene modifiche preesistenti: i traceback persistiti confermano il percorso degli errori, ma non è disponibile un commit immutabile dell'intera configurazione eseguita.

Nota alla verifica finale: durante l'analisi il workspace è stato modificato esternamente. `_run_role` ora include `httpx.TimeoutException`/`httpx.TransportError` e il coordinatore interrompe su fatal soltanto fuori dalla modalità globale. Queste correzioni affrontano già parte del P0; non sono state eseguite né validate da questa analisi. I riferimenti di riga e la diagnosi sotto descrivono il codice letto inizialmente e il comportamento delle run storiche, non una garanzia che i difetti siano ancora tutti presenti nel working tree finale.

Run considerate, sotto `storage/app/runs/yeswiki/global/`:

- `yeswiki-global-20260912-130914`: GLM `z-ai/glm-5.3-flash` in tutti i ruoli.
- `yeswiki-global-20260912-130923`: DeepSeek `deepseek/deepseek-v4.1-flash` in tutti i ruoli.

Le tre directory precedenti non rappresentano audit agentici effettivi: una contiene solo lo stub iniziale, due sono terminate al preflight per fixture numerica non conforme.

## Risultati osservati

| Misura | GLM | DeepSeek |
|---|---:|---:|
| Creazione run → aggiornamento postflight | 13:09:14–15:19:20 | 13:09:23–15:19:45 |
| Intervallo documentato | 2h 10m 06s | 2h 10m 22s |
| Richieste modello contabilizzate | 75 | 66 |
| Richieste Reader / Confirmer | 33 / 27 | 31 / 14 |
| Token input cumulativi | 1.854.719 | 1.512.355 |
| Token output cumulativi | 55.522 | 44.677 |
| Input cached / input totale | 76,0% | 71,7% |
| Tool call contabilizzate | 97 | 106 |
| Categoria visitata | A01 | A03 |
| Aree pianificate nella categoria | 12 | 9 |
| Aree chiuse | 0 | 0 |
| Lead registrate | 2 | 2 |
| Confermate | 0 | 0 |
| HTTP nel ledger di audit | 0 | 0 |
| Budget globale usato | 3,52% | 3,50% |
| Termine | ReadTimeout, Confirmer lead 2 | ReadTimeout, Confirmer lead 2 |

Gli intervalli sono ricostruiti da `started_at` e `environment.updated_at`, non da un timer della sola inferenza. Il transcript indica avvio agente alle 13:10:38 e 13:10:41. Non conferma tre ore per singola run. Le run sono sovrapposte e utilizzano modelli diversi. Il rapporto tempo totale/richieste è circa 104 e 119 secondi, ma NON è la latenza media misurata del provider: include provisioning, tool, attese e finalizzazione; richieste fallite possono avere contabilizzazione incompleta.

L'indicizzazione Codebase Memory dura circa 64,5 secondi in ciascuna. Joern/data-flow non viene invocato. Worker e Judge non partono. Le HTTP di setup/preflight non sono verifiche dinamiche delle lead.

GLM chiude staticamente la prima lead CSRF per la barriera SameSite=Lax nel threat model adottato; la seconda, sul bypass ACL nella creazione di entry, resta sospetta. Questa analisi non rivalida il verdetto CSRF. DeepSeek produce una prima lead auto-update fermata dal budget dello stadio e una seconda esplicitamente collegata come `same_hypothesis_new_evidence`: due record non significano due vulnerabilità indipendenti.

## Cause certe e limiti dell'attribuzione

### 1. Il timeout del provider diventa erroneamente fatale per tutta la run

Entrambi i traceback mostrano `httpx.ReadTimeout` durante la lettura dello stream SSE OpenRouter. Nel caso GLM avviene nel self-checkpoint Confirmer; in DeepSeek nella normale esecuzione Confirmer. Il target è valido anche al postflight.

Il percorso è verificabile in:

- `agent/pentest-agent/src/pentest_agent/def_model.py:12`: retry del transport, due tentativi, timeout HTTPX a 120 secondi nel default.
- `agent/pentest-agent/src/pentest_agent/triple_agent.py:5166`: il recovery di ruolo intercetta errori Pydantic/OpenAI, ma non il `httpx.ReadTimeout` grezzo emerso nello stream.
- `triple_agent.py:7448`: anche il contenimento locale del Confirmer non comprende quell'eccezione.
- `triple_agent.py:6301`: il catch generico registra un fatal.
- `agent/pentest-agent/src/pentest_agent/multi_category.py:325`: `fatal_error` interrompe la coda delle categorie.

Il retry del transport non protegge automaticamente la lettura successiva del body in streaming. Inoltre `Timeout(120)` limita l'attesa di un chunk, non la durata complessiva di una generazione. [HTTPX, Timeouts](https://www.python-httpx.org/advanced/timeouts/).

Non c'è evidenza di una sequenza di 429 che dimostri saturazione per troppe richieste. Mancano provider upstream, tempi per richiesta, tempo al primo token e intervalli fra chunk: non è possibile assegnare una percentuale delle due ore a OpenRouter, upstream, rete o reasoning. Due fallimenti analoghi su modelli diversi suggeriscono di verificare anche il percorso condiviso, senza dimostrare un outage del gateway.

### 2. Il budget protegge il costo, ma non assicura copertura entro un tempo utile

Il coordinatore somma le allowance delle categorie e trattiene le riserve delle categorie non visitate. La prima categoria può comunque utilizzare gran parte del pool discovery disponibile. Le riserve garantiscono fondi futuri, non l'arrivo alle categorie future entro una deadline.

Reader, Confirmer e Worker procedono in sequenza; la discovery aspetta il Confirmer della lead corrente. La chiusura di tutte le aree resta il normale prerequisito al cambio categoria. Nessuna delle due run chiude nemmeno l'area iniziale. Il modello economico lascia ancora circa il 96,5% del budget globale disponibile: ridurre solamente il costo dei token non risolve il problema.

### 3. Tool calling e ripetizioni consumano turni senza avanzamento equivalente

Nel transcript GLM, 13 delle 37 chiamate Reader a `read_file` specificano `end_line` senza `start_line`. Il tool parte correttamente dalla riga 1 per default, ma il modello pensa a una finestra successiva e rilegge l'inizio. Sono presenti anche due path contenenti frammenti `<arg_key>`, errori osservabili di argomentazione. Il Confirmer ripete `PageManager.php` con `end_line=400`, poi `410`, prima di specificare `start_line=320`.

Nel transcript DeepSeek, fra 50 tool call Reader ci sono 21 occorrenze oltre la prima di chiamate testualmente identiche. Non sono tutte necessariamente inutili, ma la ripetizione dello stesso sottosistema e la lead-delta indicano una ridotta diversità della discovery.

`read_file` espone cinque parametri; `start_line`, `max_lines` ed `end_line` offrono due modi parzialmente sovrapposti di descrivere la stessa finestra. La semantica può essere mantenuta con un contratto model-facing più semplice, senza indovinare silenziosamente righe omesse.

### 4. Il Reviewer consuma il proprio output prima della decisione

Entrambe le run riportano `Model token limit (2000) exceeded before any response was generated`. GLM registra un retry; DeepSeek due retry e un esaurimento. Il limite corrente è `reviewer_max_tokens=2000`, con reasoning low. Il retry del Reviewer torna ancora a low: non basta ridurre il prompt quando reasoning e serializzazione esauriscono lo stesso limite.

La prima lead DeepSeek termina `reviewer_stopped`, ma questo nome di lifecycle non prova che sia stata fermata dall'Exploration Reviewer: il Confirmer usa lo stesso stato di compatibilità quando non ottiene altro funding.

### 5. Una metrica distorce la valutazione del progresso

`ReaderEvidenceWindow.record_result`, in `triple_agent.py:1130`, cerca `error`, `errore`, `timeout` ecc. nell'intero output. Una lettura valida di codice contenente gestione errori può diventare `is_error=true` e non essere contata come nuova evidenza.

I contatori 25/42 e 12/37 di exploration error non sono tassi affidabili di fallimento dei tool. La classificazione alimenta anche lo snapshot del Reviewer, quindi ha conseguenze sulle decisioni. Lo stato operativo deve provenire da metadata autorevoli del tool, non da keyword nel codice letto.

### 6. Qualità e verificabilità delle ipotesi

La seconda lead DeepSeek aggiunge propagazione a una stessa ipotesi auto-update già costosa. Il nuovo Confirmer rilegge diversi componenti dell'intera catena. Una delta dovrebbe conservare fatti e incertezze già verificati e focalizzarsi sul nuovo elemento discriminante.

Una superficie admin/supply-chain può essere rilevante, ma deve chiarire presto cosa controlla l'attaccante nel threat model. La sola chiamata `ZipArchive::extractTo` non dimostra un traversal sfruttabile: la semantica del runtime è da verificare con il probe già disponibile. Nessuna chiamata `run_target_probe` compare in queste run.

Il census Semgrep indica `local-fallback` per `community ruleset checksum mismatch`, con una sola famiglia `dynamic-code` e otto segnali. Non è un blocco fatale, ma riduce la ricchezza del supporto statico; va corretto senza usare il manifest CVE come input dell'agente.

## OpenRouter: interventi e confronto corretto

OpenRouter distingue limiti propri e del provider upstream; nuove API key non aumentano automaticamente la capacità. Il fallback può tentare altri provider e altri modelli. [Documentazione limiti](https://openrouter.ai/docs/api_reference/limits).

Il routing predefinito privilegia il prezzo; il codice invia reasoning e session_id, senza preferenze provider esplicite. È possibile provare `provider.sort="throughput"` per risposte lunghe o `"latency"` per risposte brevi, mantenendo fallback e verificando la compatibilità dei parametri. Le preferenze prestazionali non sono deadline garantite. [Provider routing](https://openrouter.ai/docs/guides/routing/provider-selection).

Un endpoint diretto è una variante da misurare, non una cura dimostrata: elimina il gateway, ma conserva i limiti e le possibili lentezze dell'upstream. Confrontare richieste equivalenti su routing attuale, routing prestazionale e diretto dello stesso modello quando disponibile; misurare prima concorrenza 1 e poi 2. Non scegliere un vincitore dalla sola durata di queste due run, che seguono categorie e ipotesi differenti.

## Piano incrementale

### P0 — affidabilità e copertura prevedibile

1. **Recovery dello stream e deadline assoluta per richiesta.** Intercettare gli errori di trasporto al livello che consuma lo stream; massimo un recupero applicativo iniziale, entro deadline e accounting unificati. Preservare messaggi e tool completati, non rieseguire automaticamente HTTP mutanti. Su esaurimento, conservare la lead come incompleta tecnica e consentire prosecuzione/fallback; distinguere questo caso dai veri fatal di sandbox e invarianti. Su indisponibilità generale persistente, chiusura tecnica rapida anziché dieci categorie di retry.
2. **Telemetria essenziale nello stesso outcome.** Durata richiesta, primo token, ultimo progresso, provider/model effettivi, retry e causa, durata tool, categoria e area. Nessun nuovo agente o file parallelo. Correggere subito la classificazione errori testuale.
3. **Prima passata globale bounded.** Configurare un limite temporale totale e una quota iniziale per categoria. Come esperimento: 60 minuti discovery per dieci categorie, circa 6 minuti ciascuna, da ricalibrare con misure reali. Ruotare area dopo una epoch senza progresso discriminante; differire gli approfondimenti in coda. Ogni categoria deve risultare visitata, differita o tecnicamente bloccata, senza trasformare un limite in una dichiarazione di sicurezza. Riservare separatamente il tempo di conferma dinamica. Questa passata misura breadth, non completezza dell'audit.
4. **Semplificare `read_file` e sbloccare il Reviewer.** Esporre path/start/count e mantenere metadata interni; trattare omissioni ambigue con una correzione breve. Provare cap Reviewer 3–4k o un modello che raggiunga affidabilmente l'output sotto il cap, misurando token e retry: valori iniziali di tuning, non garanzie.
5. **Triage orientato al prossimo esperimento.** Confirmer deve passare al Worker quando ha precondizioni, attore, route e test discriminante sufficienti; un handoff non equivale a una conferma. Usare il probe esistente per semantiche locali dubbie, preservare la verifica HTTP per i finding confermati. Limitare gli approfondimenti a basso rendimento della prima passata e le riletture integrali delle delta.

### P1 — ottimizzazione misurata

- Ripristinare il census previsto risolvendo il checksum Semgrep e verificandone copertura e costo.
- Benchmark per ruolo con fixture congelate già supportate: validità degli argomenti Reader, precisione/recall delle lead, qualità del piano Confirmer, evidenze Worker, latenza p50/p95. Confrontare routing e provider diretti prima di cambiare tutti i modelli.
- Trasferire alle lead-delta un checkpoint narrativo dei fatti acquisiti e della sola domanda nuova, normalizzato dall'orchestratore. Nessun DTO model-facing aggiuntivo se la stessa semantica entra nel testo esistente.
- Verificare accounting: il `provider_cost_usd` globale GLM non coincide con la somma dei costi per ruolo; i costi delle richieste fallite sono parziali. Non usare quel singolo campo per confrontare efficienza economica prima di riconciliare l'aggregazione.

### P2 — parallelismo limitato

Solo dopo il P0, separare discovery e coda di conferma con uno o due slot controllati. Le verifiche dinamiche che modificano lo stesso target devono restare serializzate o avere sandbox isolate. Moltiplicare subito agenti e categorie aumenterebbe pressione sul provider, duplicati e interferenze senza correggere i difetti osservati.

## Validazione proposta

- Test mirato con stream che emette un chunk e poi `ReadTimeout`: recovery bounded, nessun tool completato ripetuto, nessun arresto globale spurio.
- Test con lettura valida contenente la parola `error`: non classificata come fallimento.
- Test di scheduler: categoria iniziale prolissa, deadline raggiunta, passaggio successivo con checkpoint e incompletezza preservati.
- Piccolo confronto Reader/Reviewer su fixture congelata prima di un'altra run completa; poi run globale con SLA dichiarato e medesimo target.
- KPI: categorie/aree visitate entro 30/60 minuti, lead distinte valide/ora, tempo al primo HTTP discriminante, conferme con prove/ora, precisione e recall rispetto a benchmark offline. Il semplice numero di HTTP o di record lead non misura la qualità.

Le modifiche strutturali proposte a recovery, budget temporale e scheduling richiederanno aggiornamento di `ARCHITECTURE.md` quando implementate. Questo documento è un'analisi e non descrive modifiche già applicate.
