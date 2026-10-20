# P0 — BrowserCode Playwright e recovery Reader

Stato: implementato e verificato offline il 15 ottobre 2026. Nessuna nuova run LLM eseguita.

Verifiche completate: 111 test Python mirati (98 sul flusso aggiornato e 13 regressioni
trasporto/deadline), 10 test Node/Chromium nel sidecar isolato e 50 test PHP (390 assertion).
Il sidecar usa Bubblewrap e browser separati per actor; default adeguati a 2 GiB e 1024
PID/thread. Resta da eseguire il confronto live BookStack con stessa Recon e budget.

## Obiettivo e scope

Sostituire il DSL browser con codice Playwright scritto dal modello ed eseguito nella sandbox di audit. Correggere contemporaneamente timeout e recovery Reader osservati nella run `bookstack-global-20261015-091141`.

BrowserCode e' il primo esperimento verso macro flessibili come Shell, RunScript e Sql. Il P0 non introduce un framework di macro, un nuovo agente o un secondo LLM browser. I due blocchi si implementano e verificano separatamente.

## 1. Contratto BrowserCode

- Un solo tool operativo `browser_code(actor, code)`, disponibile all'Investigator dinamico. Actor richiesto e derivato dai profili configurati; run, lead, credenziali e identificatori restano responsabilita' dell'harness.
- `code` e' il corpo di una funzione JavaScript asincrona, senza schema di step, locator o assertion da compilare. Disponibili `page`, `context`, `target_url` e i valori del solo actor selezionato. Playwright e moduli Node standard sono utilizzabili nel runner.
- Browser, cookie e storage persistono fra invocazioni della stessa sessione. Le variabili locali dello snippet non costituiscono una REPL persistente.
- `return` facoltativo e libero: testo, dati serializzabili o nessun valore. Nessun report strutturato obbligatorio scritto dal modello.
- Conservare `inspect_browser` come lettura compatta di stato, form, DOM/ARIA ed eventi, riusando paginazione e redazione. Serve anche dopo un errore; non e' un secondo percorso di automazione.
- Rimuovere `browser_flow`, BrowserStep e BrowserAssertion dal catalogo model-facing e dai prompt. Nessun doppio percorso operativo o flag per mantenerli attivi. Gli artifact storici devono restare leggibili.

Esempio illustrativo:

```javascript
await page.goto(target_url + '/login');
await page.getByLabel('Email').fill(actor.username);
await page.getByLabel('Password').fill(actor.password);
await page.getByRole('button', { name: 'Log in' }).click();
return { url: page.url() };
```

## 2. Esecuzione, sessioni ed errori browser

- Riutilizzare provisioning Docker, gateway autenticato, proxy target, collector, code per sessione e identificatori di invocazione esistenti.
- Eseguire codice in un runner terminabile separato dal processo HTTP del gateway, con ambiente ripulito e senza token del gateway, chiavi LLM, sorgenti o accesso al filesystem host. Non usare `node:vm` o Promise.race come barriera di isolamento/terminazione.
- Il runner e il relativo browser appartengono alla sessione `(run, categoria, lead, actor)`, con avvio lazy. Browser separati evitano che codice Playwright libero acceda ai context degli altri actor tramite l'istanza Chromium condivisa attuale. Questo comporta piu' processi Chromium: riuso e cleanup sono parte del P0.
- Applicare isolamento e policy di rete al compartimento di esecuzione, anche per traffico Node e browser creati dal codice: il solo proxy configurato sul `context` non basta con codice arbitrario. Conservare gli origin autorizzati attuali; full code non amplia implicitamente il perimetro di rete.
- Deadline iniziale di 60 secondi per snippet, misurata esternamente al runner; timeout del client gateway con margine superiore. Limiti tecnici di dimensione codice/output interni al runtime, senza nuovi campi da compilare per il modello.
- Errori di sintassi, Playwright, assertion o serializzazione diventano risultati ordinari del tool, con messaggio, posizione dell'errore quando disponibile e stato osservabile. Non generano ModelRetry o InvestigatorOutputFailure.
- Su errore ordinario conservare la sessione. Su timeout/cancellazione arrestare il runner e i processi browser di quella sessione; dichiarare esplicitamente lo stato perso. Nuove chiamate possono ricrearla, senza rieseguire lo script precedente.
- Conservare la deduplicazione tecnica dello stesso invocation id. Una nuova chiamata e' un nuovo esperimento: nessun replay automatico di POST o script parzialmente eseguiti.

## 3. Evidenze, report e regression testing futuro

- Registrare automaticamente codice, actor/scope, tempi, risultato libero, stdout/stderr ed errore redatti, insieme a rete, dialog e osservazioni effettivamente raccolte. Persistenza anche su fallimento, compatibilmente con i limiti dichiarati del collector.
- Riutilizzare journal, action_ref e observation_ref. Nel contesto del modello restano esito compatto, output utile e riferimenti; i DTO di persistenza non diventano output obbligatori.
- Mantenere i collector sotto il controllo dell'harness, separati dal valore restituito dallo script. Un `return { confirmed: true }` non crea un evento browser.
- Il modello valuta la causalita': JavaScript eseguito direttamente con evaluate/addScriptTag o altri interventi del tester e' setup, non prova automatica di XSS. Conservare il codice accanto agli eventi per consentire questa valutazione; niente analizzatore statico del codice generato nel P0.
- Aggiornare gli oracle browser che dipendono dal vecchio formato delle assertion, usando le osservazioni reali senza ricostruire artificialmente PASS/FAIL. Conservare lettura degli artifact precedenti.
- Script e journal preparano il regression testing assistito; il P0 non costruisce un agente di replay o promette un test autonomo riproducibile senza setup e oracle.

## 4. Reader: deadline e unico proprietario dei retry

- Deadline Reader da 240 a **300 secondi**, applicata anche al recovery Reader. Gli altri ruoli restano a 240 secondi; il timeout HTTP di inattivita' resta a 120 secondi.
- Reasoning Reader **medium**, cap ordinario di output 32.000 e budget economici invariati. Non aumentare il numero massimo di retry orchestratoriali esistente.
- Disabilitare esplicitamente i retry SDK nella factory ordinaria; il transport e' gia' limitato a un tentativo. L'orchestratore diventa l'unico proprietario dei retry LLM.
- Separare errori di trasporto/deadline dagli errori di formato. Un timeout non deve aggiungere TECHNICAL RETRY CONTEXT che impone la serializzazione finale o afferma un errore di output.
- Ripetere solo la richiesta modello fallita dall'ultimo confine autorevole, conservando tool result gia' acquisiti. Non reinserire prompt utente duplicati, risposte parziali o tool call non eseguite nella history utile al retry. Nessuna ripetizione dei tool precedenti.
- Mantenere retry bounded e attesa cancellabile; la richiesta ritentata attraversa gli stessi controlli di budget. Nessun fallback provider o cambio modello automatico nel P0.

## 5. Reader: reference e recovery coerenti

- Per AreaEnrichmentLead collegare le source reference nell'orchestratore prima della validazione, usando osservazioni dello stesso Reader/area e locator dichiarati. Applicare la stessa normalizzazione nel percorso ordinario e nel recovery, prima dei gate del global pipeline.
- Il modello puo' omettere gli ID; nasconderli dal contratto quando sono ricostruibili. Non riempire il campo con tutte le reference recenti e non inventare evidenze.
- Se nessuna reference pertinente e' disponibile, conservare la proposta con diagnostica come non acquisita, senza mandare il Reader in un ciclo di riparazione degli ID.
- Normalizzazione locale prima di ogni riparazione LLM. Conservare il limite attuale di una sola richiesta di riparazione semantica; eliminare la contraddizione tra prompt e validatore.
- Un fallimento del recovery conserva prodotti e osservazioni gia' acquisiti, con esito tecnico esplicito.

## 6. Diagnostica necessaria al confronto

- Registrare ogni tentativo LLM nel punto condiviso, incluso il recovery, senza doppio conteggio nei wrapper.
- Distinguere deadline locale, timeout di inattivita', errore di trasporto e risposta HTTP upstream. Non classificare automaticamente una deadline come errore restituito dal provider.
- Conservare ruolo, scope Reader/lead, fase ordinaria o repair, durata, prima/ultima attivita' dello stream, presenza di contenuto e provider osservato quando disponibile.
- Propagare i dettagli attraverso GlobalPipeline e aggregazione multipass. Per usage/costi non restituiti dal provider dichiarare contabilita' incompleta, senza inventare valori.

## 7. Ordine, verifiche e criteri di completamento

1. Correzioni Reader con test locali simulati: SDK retry zero, deadline selezionata per ruolo, retry senza modifica semantica/history duplicata, tool non ripetuti, reference AreaEnrichmentLead e conteggio del repair.
2. Runner BrowserCode, gateway e persistenza; poi sostituzione dei tool e dei prompt. Verificare con fixture locali, senza LLM: login e cookie tra chiamate; errore correggibile; ciclo infinito terminabile; errore dopo POST senza replay; isolamento fra actor; cleanup; proxy/redirect e traffico Node; evidenze su errore; ritorni non serializzabili.
3. Aggiornare gli oracle e i test pertinenti al cambio di contratto. Usare test Node/Python/PHP mirati, senza lint globale e senza loop agentici a pagamento.
4. Aggiornare ARCHITECTURE.md, documentazione del tool e configurazione di esempio per rappresentare il codice arbitrario nel compartimento browser e la nuova gestione dei retry.
5. Controllare il diff ed eliminare adapter legacy operativi, opzioni e astrazioni non necessarie al P0.

Validazione live successiva, da eseguire solo su istruzione dell'utente: BookStack con stessa Recon, modelli, medium Reader, budget e concorrenza della run di riferimento. Confrontare esiti, richieste, token cached/uncached, deadline distinte, repair e fallimenti browser; ridurre i token senza completare gli stessi test non e' un successo.

## Riferimenti e P1+

DeepSeek Harness verificato al commit `86a402200d95fecef4529d969836f1fe13c6cb0d`: [retry executor](https://github.com/deepseek-ai/deepseek-harness/blob/86a402200d95fecef4529d969836f1fe13c6cb0d/packages/llm/llm-retry/src/index.ts), [contratto di recovery](https://github.com/deepseek-ai/deepseek-harness/blob/86a402200d95fecef4529d969836f1fe13c6cb0d/packages/llm/llm-retry/README.md), [integrazione browser](https://github.com/deepseek-ai/deepseek-harness/blob/86a402200d95fecef4529d969836f1fe13c6cb0d/packages/experimental/browser-use-playwright-mcp/src/index.ts). Si adottano retry tecnici separati dalla semantica e browser legato alla sessione; DS non e' la fonte del runner full code proposto.

Fuori P0: framework comune Shell/RunScript/Sql, nuovi tool macro, REPL JavaScript completa, agente browser separato, allargamento degli origin/capacita' ambientali, failover provider, deadline adattive, generazione automatica di regression test.
