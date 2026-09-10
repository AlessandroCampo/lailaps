# Integrazione Playwright nel Worker: piano P0 / P1

Stato: piano revisionato, non implementato. Review sul workspace del 10 settembre 2026.
`ARCHITECTURE.md` va aggiornato insieme alle modifiche strutturali effettivamente
implementate, non per anticipare questo piano.

## 1. Obiettivo ed esito della review

Il Worker deve verificare vulnerabilita' nella pagina reale servita dal target, con
JavaScript, autenticazione e interazioni utente, e consegnare prove verificabili al Dynamic
Judge. Il browser affianca i tool HTTP; il suo avvio resta lazy.

La versione precedente copriva molte funzionalita', ma lasciava problemi decisivi:

- mancava una matrice di accettazione per i tre sink YesWiki A05;
- dialog gestiti dopo l'azione potevano bloccare il flow;
- assertion generiche, page error e DOM modificato potevano essere scambiati per prova XSS;
- chiudere il context al verdetto Worker impediva il retry richiesto dal Judge;
- screenshot/trace durevoli erano incompatibili con la persistenza corrente a due file;
- primary evidence annidata e hint aggiuntivi duplicavano semantica gia' narrativa;
- P0, iframe/popup, bridge di sessione e vision non avevano gate separati;
- mancava l'adeguamento esplicito del benchmark Worker-only oltre a quello end-to-end.

## 2. Priorita' e perimetro

| Priorita' | Risultato richiesto | Esclusioni |
| --- | --- | --- |
| P0 | Chromium isolato, due tool Worker, login UI, actor separati, navigazione/interazioni essenziali, prova XSS reale, ledger/Judge/report e benchmark coerenti | Vision, trace, screenshot durevoli, iframe/popup interattivi, bridge automatico |
| P1 | Iframe e popup identificabili, autenticazione complessa, upload, bridge cookie esplicito se utile, screenshot opzionali | Bypass MFA/CAPTCHA, navigazione Internet libera |
| P2 | Vision on-demand, trace diagnostico, compatibilita' service worker e altri browser se giustificati dai risultati | Non sono prerequisiti P0/P1 |

DOM XSS nella pagina principale e token localStorage/IndexedDB prodotti normalmente da un
login UI rientrano gia' nel P0: non serve esporre storage al modello. Flussi che richiedono
popup, frame o identity provider aggiuntivi sono P1. Il P0 comprende l'intera verifica fino
all'adjudication, non il solo tool Playwright. Niente nuovo agente o budget economico separato.

## 3. Baseline verificata nel repository

Riferimenti da ricontrollare durante l'implementazione, poiche' il workspace evolve:

- `agent/pentest-agent/src/pentest_agent/triple_agent.py`: `make_worker`, `WORKER_PROMPT`,
  `validate_dynamic_judge_decision`, snapshot Judge e percorso Worker-only.
- `runtime_tools.py`: HTTP, `bind_actor_auth`, inspection e `query_html_response`.
  Quest'ultimo usa lxml su HTML statico, senza JavaScript. Il tool JSON registrato e'
  `find_json_records`, non `query_json_response`.
- `models.py`: `CandidateHandoff.verification_plan`, `success_signal`, `rejection_signal`,
  `ConfirmedDecision.exploit_request_id`, `RejectedDecision.test_request_id`,
  `baseline_request_id` e `observation_refs`. `confirmation_plan` e' una proiezione interna
  del finding: non aggiungere un nuovo wrapper omonimo al CandidateHandoff.
- `deps.py`: sessioni HTTP per actor, transazioni, observation ledger e telemetry.
- `artifacts.py` e `ARCHITECTURE.md`: due file per run, logs PHP e outcome JSON; raw body e
  tool output completi bounded in memoria. Nessuna directory child di artefatti nella run.
- `app/Services/Pentest/BenchmarkEvaluator.php`: `finding_evidence` richiede approvazione
  Judge, stato confermato, request id e success signal testuale; non verifica singolarmente
  le stringhe `confirmation_oracle.requires`.
- `app/Services/Pentest/WorkerBenchmarkOracle.php`: separa classificazione e sufficienza
  delle prove, oggi basate su request e response observations.

Un browser disponibile nell'ambiente di sviluppo non e' un tool del Worker. Il runtime
attuale non espone Playwright. `run_target_command` resta osservazione read-only e non e'
un fallback per generare traffico browser o fabbricare l'impatto.

## 4. Accettazione concreta: YesWiki A05

Manifest: `agent/pentest-agent/benchmarks/targets/yeswiki/manifests/a05-xss.json`.
ID e descrizioni sono quelli del manifest locale, non una rivalidazione delle CVE.

| Caso | Setup e percorso | Prova richiesta nel P0 |
| --- | --- | --- |
| CVE-2026-52772, label Bazar, `text.twig` / `textarea.twig` | Salvare la label tramite applicazione con l'attore consentito; aprire la vista del campo con un context vittima distinto | Collegare scrittura riuscita, oggetto/form, lettura successiva ed evento di esecuzione con marker univoco; verificare entrambi i template come sotto-scenari se raggiungibili |
| CVE-2026-52773, revisione `time`, `handlers/page/show.php` | Pagina leggibile e modificabile; navigazione con parametro correttamente codificato | Riflessione nel contesto previsto piu' evento di esecuzione; attivare l'eventuale evento richiesto dal payload |
| CVE-2026-52774, id widget, `__WidgetHandler.php` | Context anonimo, route widget e payload nell'attributo | Iniezione di attributo piu' evento di esecuzione, eventualmente tramite focus/hover/click |

Il manifest richiede esecuzione DOM solo per 52772 e `reflected HTML marker` per gli altri.
Versionare l'oracle: distinguere la vecchia misura di riflessione HTTP dalla nuova misura
di esecuzione XSS. Non confrontare punteggi di oracle diversi come la stessa metrica e non
indebolire l'oracle stored per far passare P0.

La review non ha eseguito gli exploit. Route esatta, form id, campi, ruolo minimo,
salvataggio ed evento necessario vanno verificati nell'harness nella baseline. I fixture
probe di login provano soltanto il login, non la raggiungibilita' del sink. Un caso non
raggiungibile deve avere diagnosi di setup/precondizione, non conferma inventata.

Per stored XSS distinguere autore del dato e vittima. Se la scrittura richiede admin,
riportare quel prerequisito e l'impatto effettivo: un cambio di context non prova escalation.
Ogni ripetizione benchmark usa provisioning e dati nuovi; creare un context non annulla
le mutazioni server-side dell'esperimento precedente.

## 5. Architettura e lifecycle P0

```text
Worker -- browser_flow / inspect_browser --> BrowserGateway nell'orchestratore
                                               |
                                  protocollo interno scoped all'audit
                                               |
                                  sidecar Playwright + Chromium
                                               |
                                 target autorizzato / collector
                                               |
                          transazioni + osservazioni -> Judge / outcome
```

Laravel/sandbox preparation stabilisce target, origin, audit, rete, limiti e abilitazione
prima del loop. Il modello non sceglie origin, container, proxy, lease o configurazione TLS.
Il gateway usa un adattatore Playwright piccolo, sostituibile con fake nei test: evitare
un framework multi-driver. Non esporre CDP, shell, Python/JavaScript arbitrario o `evaluate`.
Inviare codice come payload all'applicazione e' necessario per il test XSS ed e' distinto
dall'esecuzione privilegiata di script del driver.

- Sidecar dedicato per audit, predisposto solo se abilitato; Chromium parte alla prima
  azione. Nessuna chiave provider, Docker socket o mount sorgenti nel sidecar.
- Processo non root, sandbox Chromium esplicitamente abilitata e verificata, seccomp e
  impostazioni container compatibili, cap_drop, no-new-privileges, tmpfs e /dev/shm bounded.
  Validare la combinazione sull'host reale; nessun fallback silenzioso a `--no-sandbox`.
- Immagine, package Playwright e browser revision pinned e compatibili; readiness verifica
  launch e pagina locale. Limiti browser separati dall'envelope agente da 2 GiB e misurati;
  non sommare Chromium implicitamente alla memoria del code indexer.
- Credenziale del protocollo scoped all'audit e non leggibile dalla pagina; porta di
  controllo non raggiungibile dal target ne' pubblica. Riutilizzare provisioning/cleanup
  esistenti prima di introdurre un nuovo sistema di lease.
- Context per `(audit_id, category, lead_id, actor)`, pagina principale P0. Nessuna eredita'
  implicita tra actor/lead. Credenziali target usate nel login restano nello stato privato.
- Context vivo tra tranche, compression, needs_info/clarification e retry Judge. Chiudere
  alla chiusura autorevole della lead o fine episodio Worker-only, non alla prima proposta
  confirmed/rejected. Una chiusura per budget fa cleanup.
- Browser chiuso a fine categoria/run; finalizer e cleanup esterno su cancellation/TTL
  coprono anche la morte dell'agente. Conservare prove raccolte prima di un crash.
- Crash segnala perdita dello stato browser; non rigiocare login, submit o scritture
  automaticamente. Un restart esplicito crea una nuova generazione di context. Timeout
  browser non diventa hard stop dell'intero target HTTP sano.

## 6. Tool P0: semplici, bounded, recuperabili

Registrare solo al Worker, con flag e capability check, gli hook di accounting, scope e
hard stop esistenti. Confirmer vede capability e limiti nel dossier, senza tool browser
e senza manifest/oracle.

### `browser_flow`

```python
browser_flow(actor: str, steps: list[BrowserStep], assertions: list[BrowserAssertion] = [])
```

Step/assertion sono record piatti con soli campi necessari all'operazione. Actor usa il
registry esistente; audit, lead e context li risolve l'orchestratore. P0 ha pagina implicita.
Massimo iniziale 10 step e 10 assertion, con limiti server-side configurabili.

Step: `navigate(path)`, `fill`, `clear`, `click`, `check`, `uncheck`, `select_option`,
`press` con tasti consentiti, `focus`, `hover`, `wait_for` su locator/path. Focus e hover
sono P0 per handler di attributo XSS. Niente generico `dispatch_event` o `evaluate`.
Il path ammette query e fragment: preservare il fragment, essenziale per DOM XSS.

Locator: role/name, label, testo, placeholder, test id o CSS; strict per default, errore
recuperabile per zero/piu' match. Niente XPath, coordinate o codice. Aspettare actionability
e condizioni bounded; non usare `networkidle` come criterio universale nelle SPA.

Assertion: path, stato elemento, testo/attributo, conteggio, dialog con marker esatto,
response con metodo/path/status. Assertion UI riuscita non e' prova XSS automatica.
Console/page error sono diagnostica, non oracle di esecuzione sufficiente.

**Dialog:** listener installati prima di navigare/cliccare; registrare subito testo, tipo,
pagina, frame se attribuibile e tempo. Dismiss automatico dopo registrazione per non bloccare
il flow. Eventuale accept e' una policy dichiarata prima dello step, mai una tool call
successiva che dovrebbe arrivare mentre la prima e' bloccata. Dismiss basta per test XSS.

Stop al primo step fallito; restituire progresso parziale e ultimo step sicuramente
completato. Niente rollback delle mutazioni applicative. Dopo timeout di submit l'esito
puo' essere ignoto: ispezionare prima di ripetere. Il gateway assegna un invocation id
stabile sul replay della stessa tool call e non riesegue mutazioni gia' ricevute; se non
puo' ricostruirne l'esito restituisce `outcome_unknown`.

Output model-facing piatto: `action_ref`, stato/codice errore, eventuale indice fallito,
summary narrativo bounded, `observation_refs`, `request_ids`, `response_ids`, troncamento.
Non esporre copie dei DTO interni o far rigenerare actor/lead al modello.

### `inspect_browser`

```python
inspect_browser(actor: str, mode: str = "summary", selector: str | None = None,
                cursor: str | None = None, max_chars: int = 6000)
```

Mode P0: summary, aria, visible_text, dom, events, network. DOM selezionato e ARIA bounded;
non assumere che testo renderizzato sia tutto nel viewport: documentare la semantica
dell'estrazione scelta. Niente intere pagine al modello per default.

Ogni risultato produce `observation_ref` risolvibile e summary. Eventi/network paginati con
cursore esplicito: leggere non consuma prove. Deduplicare output identici, conservando eventi
distinti con stesso testo. Inspection non avvia deliberatamente richieste, ma la pagina puo'
continuare polling: il collector applica cap anche fuori dai flow.

## 7. Prova XSS e adjudication P0

Marker univoco per esperimento, mai credenziali o dati esterni. Preferire un dialog col
valore esatto generato dal payload attraverso il sink reale. Collector installato prima
del trigger; valore atteso e finestra temporale associati all'esperimento. Il Judge vede
l'evento osservato e il percorso che lo ha causato.

Non inserire payload in `set_content`, `evaluate`, init script o console del driver per
dichiararlo eseguito dal target. Non modificare response, CSP o security flags per ottenere
l'exploit. DOM marker e' prova solo se deriva da esecuzione, non da semplice riflessione
HTML. P0 non richiede endpoint OAST o binding JavaScript privilegiati.

Prova minima: input e sink raggiunti, attore, pagina/origin, trigger, marker osservato in
evento di esecuzione correlato. Stored richiede scrittura e lettura successiva; oracle
differenziali richiedono control distinto, condizioni comparabili e finestra esplicita.
Assenza di dialog non prova sicurezza: timeout, evento non attivato, CSP, frame bloccato
e precondizioni mancanti vanno interpretati. Distinguere blocked da rejected con rejection
signal osservato.

Il modello decide e racconta; l'orchestratore valida ownership, esistenza, generazione,
tempi e integrita' dei riferimenti. Niente classificatore XSS generico a keyword. Judge
riceve osservazioni, provenance e control/test, non solo `assertion_passed`. Distinguere
contenuto controllato dal target dai metadata del collector; pagina, ARIA e console sono
input non fidati, non istruzioni da seguire.

## 8. Network e sessioni P0

Il browser e' un secondo trasporto autorizzato: aggiornare il prompt che riserva ogni HTTP
a `http_*`. `run_target_command` resta escluso.

- P0 autorizza solo l'origin target configurato. Path relativi canonicalizzati senza doppio
  context path; rifiutare URL protocol-relative, credenziali in URL e schemi attivi come
  `javascript:`. Preservare payload salvo encoding necessario. Documentare URL interno e
  URL effettivo usato dal browser.
- Enforcement rete esterno a Chromium: egress solo alle destinazioni target approvate,
  DNS controllato, niente host/metadata/control plane/altri audit. La sola rete Docker
  privata non basta. Guard applicativo verifica scheme/host/port per ogni richiesta e
  redirect: firewall IP non distingue origin sullo stesso indirizzo.
- Testare redirect, DNS/rebinding, subresource, fetch, beacon, WebSocket e popup; niente
  wildcard Internet. Limiti connessioni/byte per context/lead oltre che per flow. Bloccare
  download e protocolli non necessari. P0 blocca popup/frame fuori scope e non li espone
  come superfici interattive; una dipendenza necessaria non supportata viene segnalata.
- P0 imposta `service_workers="block"`; WebSocket bloccati finche' non implementati con
  policy esplicita. Una dipendenza bloccata e' limite ambientale, non prova di sicurezza.
  P2 puo' abilitare service worker con collector e test dedicati.
- Preservare CSP, sandbox iframe, TLS e cookie browser. Eccezioni TLS per fixture soltanto
  nel provisioning, dichiarate nel report e mai impostabili dal modello.

Ogni actor browser parte pulito e fa login UI. HTTP/browser sono sessioni separate dello
stesso actor, senza promessa di cookie/token uguali. Dossier e risultati indicano trasporto
e autenticazione effettivamente verificata. Provare login con identita'/risorsa protetta,
non solo status/redirect. Niente `BrowserContext.request` nel P0: aggiungerebbe un terzo
percorso API non necessario ad A05. Raccogliere invece le API avviate dall'applicazione.

## 9. Collector e persistenza P0

Raccogliere document/fetch/XHR e metadata delle altre richieste con `transport=browser`,
actor, generazione context, page/frame se disponibili, timestamp e legame alle azioni.
Listener sul context prima delle pagine; non attribuire automaticamente ogni richiesta
background all'ultima azione.

Normalizzare nello store HTTP esistente con id assegnati dall'orchestratore e response id
solo se una risposta esiste. Metodo, URL, redirect chain, status, body leggibile bounded;
esplicitare body assente/troncato, cache, failure e correlazione incerta. Non inventare
cookie diff, timing o campi mancanti: adeguare adapter e inspector alle differenze reali.
Le richieste di un flow non sono model tool call multiple.

Record interni tipizzati: azione, osservazione, legami di provenance. Il modello riceve
riferimenti piatti e sintesi. DOM/body completi bounded in memoria; nel report un evidence
bundle compatto delle prove decisive citate con estratto redatto, tipo, scope, timestamp,
digest, risultato e legami. Gli id devono restare interpretabili dopo teardown: digest
senza estratto non consente review.

P0 preserva i due file: niente PNG, trace, dump raw o nuove sottodirectory. ARIA decisiva
come estratto testuale bounded nell'outcome. Checkpoint atomico del bundle prima di pubblicare
conferma; un failure di persistenza non puo' produrre conferma durevole senza prova.
Cap totali e retention delle prove citate attraverso eviction/compression. Redigere segreti
da body, query, DOM/ARIA, eventi e log, preservando marker di test.

## 10. Contratti Worker/Judge e compatibilita'

Prima di ogni schema model-facing confrontare esplicitamente alternative con meno campi.
Proposta P0: riferimento primario stringa piatto `exploit_ref` / `test_ref`, eventuale
`baseline_ref` ed esistente `observation_refs`. Id con namespace risolti nel ledger a
request o azioni browser. `PrimaryDynamicEvidence` puo' essere union interna, non DTO
annidato generato dal LLM. L'orchestratore ricostruisce tipo/actor/lead; narrativa invariata.

Accettare alias legacy `exploit_request_id`, `test_request_id`, `baseline_request_id` nella
migrazione; rifiutare conflitti e normalizzare valori univoci senza retry inutili. HTTP
mantiene proiezioni legacy nel report; browser usa proiezione versionata senza request id
fittizi. Aggiornare tutti i consumer. Con browser off mantenere schema model-facing storico
e adapter interno comune.

Aggiornare insieme Worker/Judge models, validator, baseline checks, ledger, snapshot Judge,
finalizer, compression, outcome parziale/finale, UI e benchmark. Preservare scope temporale
senza perdere prove delle tranche precedenti nello stesso episodio autorizzato; observation
di altra lead/generazione non valida una nuova azione per coincidenza di marker.

Non aggiungere `preferred_execution` / `browser_reason` al CandidateHandoff P0:
`verification_plan` puo' gia' dire browser, attore, route, interazione e segnale. Confirmer
riceve capability e prepara un primo passo browser eseguibile. Prompt/guardrail probe-first
devono accettarlo senza HTTP preliminare obbligatoria o route discovery ridondante.

## 11. Benchmark e oracle: due percorsi distinti

**End-to-end:** aggiornare BenchmarkEvaluator, manifest e validator Judge. Un requisito XSS
browser deve corrispondere a evento/prova risolvibile, non alle parole "DOM execution" nel
testo LLM. `requires` puo' restare descrittivo; introdurre solo il minimo discriminante
machine-readable per selezionare la verifica. Semantica del finding al Judge; controlli
su provenance/evento e requisiti della fixture all'oracle post-run.

**Worker-only:** aggiornare WorkerBenchmarkOracle, serializer stage artifact, snapshot
evidence, freeze/compatibilita' dataset e report. Conservare lineage Confirmer -> Worker;
mai oracle o payload attesi nei prompt del subject. Worker-only valuta proposta e
sufficienza, non crea adjudication e non imposta `dynamically_confirmed`. DOM-only non deve
fallire per `minimum_http_requests=1` se l'oracle richiede invece un'azione browser; stored
puo' richiedere una richiesta per provare la scrittura.

Versionare oracle/report/dataset e conservare leggibilita' storica. Controlli negativi:
riflessione escaped, marker solo testuale, CSP bloccante con trigger verificato, marker
vecchio/altro actor, errore JS generico, assertion UI riuscita, screenshot senza evento.
Nessuno deve ottenere credito come esecuzione XSS.

## 12. P1: interazioni e autenticazione avanzate

Ogni capability si attiva dopo P0 con test e flag indipendenti quando necessario.

- **Popup/pagine:** registry con `page_ref` opaco restituito dal gateway, selezionabile da
  flow/inspection. Opener, actor, origin e creazione registrati; listener prima del click,
  selezione esplicita e ritorno alla pagina principale. Cap e chiusura bounded; nessun
  cambio di actor durante lo switch.
- **Iframe:** `frame_ref` scoped a pagina/generazione da inspection oppure frame locator
  bounded. Same-origin e cross-origin autorizzati, attribution corretta e CSP/sandbox reali.
  Frame disconnesso rende ref stale, non seleziona un altro frame.
- **Origin aggiuntivi:** allowlist immutabile dal provisioning per IdP/asset necessari,
  distinguendo target auditabile da dipendenza login. Autorizzare IdP al login non autorizza
  attacchi all'IdP. Navigazioni model-facing tramite alias configurato + path relativo.
- **Auth:** multi-step, redirect di ritorno, scadenza/logout, token gestiti dall'app;
  credenziali da riferimenti privati del registry se disponibili. MFA/CAPTCHA richiedono
  fixture/accesso predisposto o blocked esplicito; niente promessa di automazione universale.
- **Upload:** `set_input_files` con contenuto inline bounded, filename/MIME validati, nessun
  path host. Verificare submit JS e multipart effettivo.
- **Bridge opzionale:** solo con ROI misurato, copia esplicita cookie HTTP -> browser o
  viceversa per lo stesso actor, conflitti documentati e verifica identita' successiva.
  Test Domain/Path/Secure/SameSite, host alias, partitioned cookie e perdita di attributi
  httpx. Rifiutare conversioni non fedeli; niente estrazione automatica token localStorage.
  SessionStorage non e' coperto da storage_state.
- **WebSocket:** abilitazione scoped se necessaria a P1, con policy e cap, metadata minimi;
  non promettere inspection completa dei messaggi nella prima estensione.

### Screenshot opzionali P1

Servono alla review umana, non alla conferma A05. Selector/viewport, viewport fisso,
pixel/byte cap e masking. Non promettere redazione universale di canvas/segreti arbitrari:
se non si puo' rispettare la policy, omettere cattura e segnalarlo.

Store binario separato dal contratto a due file: namespace run/lead, id opachi, digest,
accesso owner, retention/cleanup. Outcome con soli metadata e ref autorizzate; path assegnati
server-side e nessun base64 nei log. Aggiornare ARCHITECTURE per questa estensione.
Gestire expired/missing senza link falsamente disponibili. Le prove testuali decisive
restano leggibili dopo scadenza dell'immagine. Nessun vision model nel P1.

## 13. Budget, errori e telemetry

Stesso score Worker, nessuna riserva browser. Contare separatamente model requests, tool
calls, step e network. Un flow non permette lavoro illimitato dentro una tool call.
Limiti configurabili step/flow, pagine/context, richieste/byte, wall time lead e output;
cap background anche durante inspection.

Telemetry: enabled/available/calls come dispatcher esistente, startup/crash, flow/step,
network per trasporto, policy blocks, timeout, context aperti/chiusi, troncamenti, prove
HTTP/browser. P1 aggiunge artifact bytes e bridge events senza segreti. Budget nascosto
ai modelli; compression conserva summary, refs, actor/pagine e prossimo test, non raw body.

Errori: capability_disabled/unavailable, policy_blocked, locator/assertion failure,
timeout, state_lost, stale_reference, outcome_unknown, capture failure. Browser failure
isolato non equivale a target down; errore applicativo non e' failure infrastrutturale.
Non promuovere automaticamente policy_blocked a finding o rejected.

## 14. Implementazione incrementale e gate

### P0-A: baseline e contratti

Congelare revisioni target/harness/oracle; inventariare consumer evidence; verificare
precondizioni A05 e controlli negativi. Definire record, ref piatti, adapter legacy,
retention bounded e policy egress concreta. Misurare envelope browser.
Deliverable: migrazione e fixture deterministiche; nessuna nuova model call.

### P0-B: sidecar e percorso completo senza modello

Provisioning, launch lazy, context, policy, collector, flow/inspection, dialog e cleanup.
Fixture login -> payload -> evento -> record persistito. Fake per unit test; Chromium
reale per rete, isolamento e semantica browser.
Deliverable: prova browser riproducibile con confine di sicurezza testato.

### P0-C: Worker -> Judge -> report -> benchmark

Esporre due tool solo al Worker, aggiornare prompt/validator/ledger/compression/finalizer
e i due evaluator. Migrazione schema e checkpoint atomico. Aggiornare ARCHITECTURE nello
stesso change set. Eseguire i tre A05 su target fresco.
Deliverable: conferma adjudicated leggibile dopo teardown; scoring Worker-only distinto.
P0 resta off di default fino ai gate.

### P1-A / P1-B

P1-A: pagine/frame, popup, auth multi-step e origin alias; upload/WebSocket nei flussi che
li richiedono. P1-B: bridge se giustificato, screenshot con storage/access control.
Ogni incremento aggiorna test, capabilities e architettura.

### Test e gate obbligatori

- Unit mirati: schema/limiti, path query/fragment, scope/stale refs, alias legacy, cursor,
  replay/dedup, collector, redazione, persistence/eviction e baseline validation.
- Browser: login CSRF/SPA, due actor, stored/reflected/DOM XSS e negativi; focus/hover,
  dialog senza deadlock, timeout submit senza doppia scrittura, polling, crash,
  compression/needs_info/retry Judge senza perdita di sessione/prova.
- Sandbox: non-root e sandbox Chromium attiva; niente provider secrets/socket/mount;
  isolamento audit, redirect/DNS/subresource/beacon/WebSocket/control plane negati;
  cancellation/startup failure senza container orfani.
- P1: iframe/popup e ritorno IdP, frame stale, cookie conversion loss, upload, session
  expiry, authorization/retention/masking screenshot e loro indisponibilita' successiva.
- Compatibilita': test HTTP/Worker pertinenti con browser off, schema storico leggibile,
  zero Chromium e model call aggiuntive. Solo test/lint mirati, mai lint intero progetto.

Per ogni fixture deterministica positiva almeno 20 ripetizioni: zero flake per rispettare
strettamente <5%; tutti i negativi senza false conferme. Non e' garanzia statistica generale.
Zero bypass scope nei test, zero prove basate solo su riflessione/errore/screenshot, zero
ref perse dopo teardown. I due template Bazar sono sotto-scenari, non due CVE indipendenti.

Benchmark modello A/B HTTP-only vs HTTP+browser: medesimi subject canonici, modelli,
reasoning, budget e provisioning fresco. Separare affidabilita' driver da qualita'
stocastica Worker/Judge. Prima della misura finale fissare sulla baseline numero di run e
soglie gain/costo/latency; riportare classificazione, sufficienza, false conferme/rifiuti,
blocked, wall time, token/score, startup/memoria. P1 aggiunge frame/popup/auth; vision A/B P2.

## 15. Rollout, rollback e Definition of Done

P0 `WORKER_BROWSER_ENABLED`; capabilities frozen all'avvio run. P1 espone solo feature
implementate/testate. Unavailable diagnosticato una volta, senza loop. Rollout: fixture ->
Worker-only -> end-to-end -> abilitazione selettiva.

Rollback nuove run disabilita tool/provisioning. Run attive finiscono o sono cancellate
con cleanup esplicito. Report storici/nuovi e ref persistite restano leggibili; spegnere
il flag non elimina prove.

Componenti: nuovo package browser/browser_tools, config/Deps/telemetry, triple_agent,
models/ledger, compression, artifacts/serializer; Laravel/Compose preparation/readiness/
finalizer; BenchmarkEvaluator/WorkerBenchmarkOracle, registry/dataset stage; UI evidence,
test e `.env.example`. Playwright dipendenza sidecar; aggiungerla all'agente solo se serve
al protocollo scelto. P1 aggiunge store screenshot e controller autorizzato.

**DoD P0:** tre A05 raggiungibili con prove di esecuzione reale, negativi corretti, sessioni
isolate, rete confinata, Judge/report e due benchmark coerenti, regressione HTTP superata,
ARCHITECTURE fedele al runtime.

**DoD P1:** flussi frame/popup/auth previsti superano gli stessi gate di isolamento,
provenance/recovery; ogni capability opzionale dichiara limiti e diagnostica verificati.

## 16. Riferimenti Playwright verificati nella review

- [Dialog](https://playwright.dev/python/docs/dialogs): listener deve gestire il dialog
  per evitare che l'azione resti bloccata.
- [Network](https://playwright.dev/python/docs/network) e
  [Service workers](https://playwright.dev/python/docs/service-workers): routing non e'
  controllo rete completo; esistono limiti sulle richieste gestite dai service worker.
- [Authentication](https://playwright.dev/python/docs/auth): persistenza autenticazione,
  incluso sessionStorage non salvato automaticamente da storage_state.
- [Docker](https://playwright.dev/python/docs/docker) e
  [BrowserType](https://playwright.dev/python/docs/api/class-browsertype): compatibilita'
  immagine/package, utente non root e configurazione esplicita sandbox Chromium.
- [BrowserContext](https://playwright.dev/python/docs/api/class-browsercontext): isolamento,
  eventi context e pagine multiple.

Riconfermare le API sulla versione pinned scelta nell'implementazione: installare l'ultima
release non risolve automaticamente isolamento o qualita' delle prove.
