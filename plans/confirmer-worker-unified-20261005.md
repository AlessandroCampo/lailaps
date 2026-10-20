# Confirmer-Worker unificato: piano P0 e verifica BookStack

Data: 2026-10-05. Aggiornamento: 2026-10-06. Stato: implementazione P0 e preflight
completati; smoke/replay API e accettazione 9/9 ancora da eseguire con richiesta esplicita.
Branch verificato: `worker-confirmer-merge`, HEAD `36f468c691b1d631e299fb15e3f63a4704f0d7dd`.

## Obiettivo e perimetro

Unificare Confirmer e Worker della pipeline globale in un solo investigatore per lead:
stesso modello, sessione, history e contesto dalla ReaderLead alla conclusione.
La conferma statica resta un risultato autonomo, durevole e visibile anche quando la
verifica dinamica non viene completata. Browser fix escluso.

P0 comprende prompt, transizione statica/dinamica, gestione terminale e dei guasti,
budget per fase, pool globale, launcher e replay delle lead storiche. Riutilizzare
ledger, strumenti, checkpoint, artefatti e accounting esistenti. Non riscrivere il
loop intero e non introdurre un agente reviewer o judge aggiuntivo.

Il percorso legacy non globale e i benchmark separati Confirmer-only/Worker-only
restano disponibili come controlli. Il nuovo percorso globale e il replay usano lo
stesso investigatore. Aggiornare `ARCHITECTURE.md` durante l'implementazione, quando
il comportamento descritto esiste davvero.

## Evidenze verificate

- `global_pipeline.py::_product` crea un engine per lead; `_candidate` la accoda a
  un pool Worker separato. `_downstream` esegue `_refine` oppure `_confirm`.
- `_refine` e `_confirm` usano conversazioni e prompt distinti. Collegarli soltanto
  in sequenza non realizza l'unificazione richiesta.
- `ledger.promote` conserva `confirmation_plan`, source reference e milestone
  `statically_validated`; `worker_stop` conserva il finding in `suspected`.
- `_confirm` applica direttamente `worker_stop` se un checkpoint chiede continue
  senza budget. Gli errori investigativi/checkpoint possono saltare il terminale;
  altri fatal arrivano al catch esterno di `_downstream`.
- `BudgetBroker.can_start_request(terminal=True)` ammette gia' chiusure di stage
  finanziati con overshoot contabilizzato. `worker_completion_reserve` riserva
  `min(300000 EP, limite Worker / 5)`: con 1M EP sono 200000 EP.
- Esistono test offline con `FunctionModel`/mock, benchmark da ReaderLead e da
  CandidateHandoff, runtime batch e publisher centralizzato dei report.

## 1. Un investigatore, due fasi contabili

Introdurre nel modulo esistente il percorso di investigazione unificata. Riutilizzare
`RoleConversationState`, una factory con prompt stabile e gli output piatti gia'
disponibili. Una conversazione per lead, senza nuovo assignment Worker che ricopi
il dossier statico. La compaction mantiene sessione, milestone e riferimenti durevoli.

Il modello inizia con lettura statica e strumenti runtime read-only gia' consentiti
al Confirmer. Non svolge discovery di lead indipendenti. Il checkpoint statico decide
continue, closure con barriera positiva, oppure promozione statica.

La promozione viene serializzata usando il payload narrativo esistente e normalizzata
dall'orchestratore in `CandidateHandoff` interno. Riutilizzare `ledger.promote` e
persistenza di stage/checkpoint: la promozione deve essere durevole e pubblicata PRIMA
di aprire HTTP/browser. Una decisione di checkpoint o testo grezzo non basta a creare
una conferma statica valida.

Solo dopo questa persistenza, sbloccare una volta l'envelope dinamico e abilitare i
tool Worker esistenti. Proseguire nella stessa sessione con un breve delta che indica
la fase e l'eventuale limite, senza un nuovo handoff model-facing esaustivo o recipe
obbligatoria. Resettare i flag di serializzazione statica: il terminale di fase non
deve bloccare la successiva raccolta dinamica.

I nomi contabili `confirmer` e `worker` possono restare come fasi del broker e della
telemetria; non rappresentano due agenti nel nuovo percorso. Verificare esplicitamente
che cambio fase e `_role_system_prompt` non cambino sessione, prompt stabile o storia.
Attribution e artefatti dichiarano lo stesso modello e la stessa sessione per entrambe
le fasi. Nessun consumo o stage grant duplicato.

## 2. Prompt: valore della conferma statica

Inserire nel prompt principale e ripetere nell'istruzione terminale:

> La conferma statica fondata sul codice e' un risultato valido e di valore. Una
> volta registrata, non viene annullata dalla mancanza di una prova dinamica. Se un
> limite di budget, un errore o un impedimento interrompe il lavoro, descrivi cosa
> hai confermato staticamente, quali prove dinamiche hai raccolto, il gap residuo e
> il prossimo test utile. Non dichiarare rejected per un test fallito o non eseguito.
> Rejected richiede evidenza positiva che smentisca l'ipotesi; confirmed richiede
> impatto dinamico dimostrato. Nel turno terminale non usare tool investigativi e
> non chiedere di continuare se l'orchestratore ha chiuso la raccolta.

Tenere la soglia statica corrente: primitive plausibile, flusso e sink verificati,
reachability e mitigazioni valutate. L'unificazione non deve trasformare ipotesi
Reader non verificate in finding staticamente validati. Una successiva confutazione
positiva puo' cambiare il verdetto; la milestone statica resta nella storia con la
motivazione della revisione.

Usare `SuspectedDecision` e narrativa esistenti per una conferma statica senza prova
dinamica; non aggiungere DTO annidati o una nuova tassonomia di verdict. Nel report
e nel consumo degli artefatti rendere leggibile "confermata staticamente; verifica
dinamica incompleta" dalla milestone e dal motivo di stop, distinta da una lead
Reader non ancora verificata. Un'incompletezza dinamica non conta come rejected.

## 3. Chiusura terminale e conservazione senza modello

Centralizzare la chiusura dell'episodio unificato, prima di cleanup e chiusura stage.
Un solo percorso di finalizzazione, idempotente; niente terminali ricorsivi.

- Uscita normale: self-checkpoint tool-free, seguito dalla serializzazione del
  verdetto scelto con i retry gia' previsti. Un verdetto valido e applicato chiude
  l'episodio; non produrre un secondo verdetto.
- Budget esaurito, limite richieste/context, errore di tool/provider recuperabile
  o output investigativo invalido: fermare la raccolta e attivare la chiusura
  tool-free nella stessa conversazione. Comunicare causa, stato statico durevole,
  prove osservate e assenza di ulteriori esperimenti finanziabili. Il checkpoint
  di chiusura puo' riconoscere una prova gia' sufficiente; non ammette continue.
- Riservare al massimo un checkpoint di chiusura e una serializzazione con i
  retry configurati. Riutilizzare la riserva Worker e l'ammissione terminale per
  stage gia' finanziati; contabilizzare ogni richiesta e overshoot. Non concedere
  altro budget operativo e non finanziare HTTP con la riserva terminale.
- Dinamico non finanziato dopo la promozione: concludere nella stessa sessione
  sullo stage statico ancora aperto, senza creare un grant Worker artificiale.
  Prima della promozione usare la chiusura statica, senza inventare la milestone.
- Se il checkpoint/terminale stesso fallisce o esaurisce i retry, usare lo stato
  gia' persistito. Riutilizzare il recupero di output strutturali validi dove
  applicabile; non dedurre un verdict dal testo incompleto.
- Errori provider permanenti gia' riconosciuti (credenziali/configurazione),
  cancellazione e errore di persistenza non giustificano tentativi infiniti.
  Registrare il motivo per cui il terminale non e' stato eseguibile. Nel caso di
  errore di persistenza propagare il fatal e conservare l'ultimo snapshot valido;
  non dichiarare riuscita una scrittura non confermata.

La garanzia realistica e': ogni uscita passa dal finalizzatore; il turno modello
viene eseguito quando il provider e il processo sono disponibili; il risultato
statico gia' persistito resta recuperabile anche senza un'altra risposta. Un kill
del processo non puo' garantire una nuova chiamata, quindi la persistenza anticipata
della promozione e' parte essenziale del requisito.

Se esiste promozione durevole e manca un verdict dinamico applicabile, mantenere il
finding `suspected`, confirmation plan, source reference, severity e milestone;
salvare causa tecnica/economica, gap, prossimo passo e prove acquisite. Coprire anche
fatal intercettati dal pool e lead promosse ma non ancora `confirming`. Il fallback
e' una chiusura dell'orchestratore, non una nuova valutazione semantica del modello:
non fabbricare trace, adjudication o conferme. Tenere separati esito investigativo e
salute dell'esecuzione: un errore tecnico resta conteggiato anche con finding statico
preservato. Verificare report parziale, finale, artifact registry e scorecard.

## 4. Pool, budget e configurazione

Pipeline nuova: Recon -> fino a 4 Reader -> deduper/ammissione -> coda lead -> pool
investigatori unificati. Un task possiede la lead per tutta l'indagine; eliminare la
seconda coda di dispatch Worker dal percorso nuovo. Lead diverse e Reader possono
continuare in parallelo. Il cap Reader non ferma downstream gia' ammesso.

Default proposto: 2 investigatori concorrenti, per mantenere al massimo due indagini
dinamiche come oggi; unico parametro `lead-concurrency`. La fase statica occupa lo
stesso slot: misurare la durata complessiva senza promettere un guadagno automatico.
Non introdurre scheduler a priorita' o un ulteriore semaforo in P0.

Un parametro `lead-model` seleziona il modello per entrambe le fasi; default del
nuovo percorso pari al Worker configurato. Override espliciti Confirmer/Worker in
conflitto con la modalita' unificata devono produrre un errore chiaro, senza scegliere
silenziosamente. Preservare le opzioni legacy nei percorsi legacy. Propagare policy,
modello e concorrenza attraverso Python, `PentestRun`, `BenchmarkRun` e avvio web;
riusare gli stessi campi/controlli quando possibile.

Conservare 500000 EP iniziali statici, policy di continuazione statica esistente e
1000000 EP dinamici condizionati alla promozione. Non sommarli in un fondo anticipato:
una ReaderLead sblocca lo statico, una promozione valida sblocca il dinamico. Il cap
totale rimane distinto. Lead senza budget dinamico mantengono la conferma statica.
La riserva terminale deve restare associata alla lead ammessa fino alla finalizzazione.

## 5. Test offline: obbligatori prima del replay

Estendere le suite esistenti usando mock/FunctionModel, senza provider o target reale.

| Caso | Asserzioni richieste |
|---|---|
| ReaderLead -> statico -> dinamico | Un modello/sessione; history conservata; niente secondo handoff; tools dinamici disponibili solo dopo promozione persistita |
| Statico chiuso | Barriera positiva; nessun grant o tool dinamico |
| Transizione/compaction | Stessa lead/sessione; nessun flag terminale residuo; milestone/ref sopravvivono |
| Budget statico/dinamico | Grant unico, conti per fase, cap Reader indipendente, nessuna doppia reservation o contabilizzazione |
| Budget dinamico negato/esaurito | Terminale ammesso sullo stage corretto; nessun nuovo tool; finding statico presente in tutti gli output |
| continue dopo stop forzato | Non riparte la raccolta; chiusura terminale motivata |
| Errori in raccolta, checkpoint, serializzazione | Fault injection di eccezioni API/tool/validation e fatal locale; finalizzazione unica e risultato statico preservato |
| Errore prima della promozione | Nessuna falsa milestone statica; lead unresolved conservata |
| Terminale/retry falliti | Fallback durevole, causa distinta, nessun verdict/trace del modello inventato |
| Prova dinamica gia' sufficiente al boundary | Possibile confirmed soltanto con output e riferimenti validi; prove conservate anche se serializzazione fallisce |
| Rigetto positivo | Rejected motivato, milestone storica conservata; payload fallito/timeout non bastano |
| Pool concorrente | Investigatori avviati prima della fine Reader, cap rispettato, refill e drain dopo stop Reader |
| Fatal, cancellazione, persistenza | Una lead locale non perde le altre; cleanup e task raccolti; snapshot durevole recuperabile; persistenza fallita non nascosta |
| Launcher/report | Opzioni coerenti, provenance corretta, fallback in suspected, scorecard non perde static_validation; errori tecnici restano errori |

Suite da estendere/riusare: `test_worker_checkpoint.py`, `test_global_pipeline.py`,
`test_economic_budget.py`, `test_stage_artifacts.py` e test pertinenti del ledger;
PHP `GlobalPipelineOptionsTest`, `BenchmarkWorkerReportTest`, `WorkerBatchFinalizationTest`
e test del nuovo collegamento benchmark/launcher. Eseguire solo test pertinenti e lint
dei file modificati, come richiesto da `AGENTS.md`.

## 6. Replay delle ReaderLead BookStack

Baseline: `bookstack-global-20261004-215149`, outcome in
`storage/app/runs/bookstack/global/bookstack-global-20261004-215149/`.
Il report contiene 11 CandidateHandoff: 9 confirmed, 1 errore tecnico e 1 rejected.
Tutte le 11 lead hanno una sola ReaderLead originaria identificata da
`origin_reader_output_id`, con source reference incluse nello stage output.

| Scope storico (lead `:lead-1`) | Caso sintetico | Esito storico |
|---|---|---|
| investigation-1 | Visibilita' immagini/path | confirmed |
| investigation-2 | Redirect allegato esterno | confirmed |
| investigation-3 | Contenuto drawio | confirmed |
| investigation-4 | API image-gallery URL/data | confirmed |
| investigation-6 | Allegato inline | confirmed |
| investigation-10 | Update relazione allegati | confirmed |
| investigation-11 | Immagini orfane | confirmed |
| investigation-12 | Redirect `_return` | confirmed |
| investigation-18 | Link pagina/tabnabbing | confirmed |
| investigation-14 | Cover nell'import ZIP | staticamente validata, Worker technical_error |
| investigation-5 | Drawio di pagina nel cestino | rejected; controllo negativo aggiuntivo |

I primi dieci sono il gruppo richiesto; il caso 5 e' un controllo addizionale e non
altera il denominatore delle dieci lead. Usare un elenco esplicito, mai prendere i
primi dieci handoff. Ordinare per scope numerico per riproducibilita'.

Preparazione:

1. Congelare manifest evaluator con run/scope/output ID, esito storico e checksum
   dell'outcome. Separare i subject model-facing: soltanto ReaderLead, source refs,
   categoria e source commit. Non includere verdict storico, CandidateHandoff finale,
   prove Worker, CVE/label/oracle o indicazioni della risposta attesa.
2. Riutilizzare `ConfirmerBenchmarkDataset` e loader di stage; verificare mapping
   univoco, source hash, parent/provenance e commit rispetto al target pinned del
   catalogo. Il checkout BookStack osservato e' `e1cd3229966d939a75a74a2224ff0643d8af337b`;
   prima del replay confrontarlo con descriptor/manifest e metadata della baseline.
3. Estendere minimamente il runner ReaderLead con modalita' unificata e selezione
   batch, riutilizzando materializzazione, fixture probes, runtime e finalizzazione
   esistenti. Il solo `benchmark:worker` da CandidateHandoff non verifica la fusione.
   Registrare versione della modalita' e usare il percorso unificato di produzione.
4. Eseguire prima un preflight senza modelli per subject e runtime. Validare state
   reset e isolamento: nessun residuo del vecchio run, prove scoped per lead, setup
   deterministico prima di ciascuna lead se lo stato precedente puo' contaminarla.
   Non aggiungere automaticamente infrastruttura di snapshot.

Sequenza delle run, dopo implementazione e test offline:

- Una smoke run su caso 1, caso 14 e controllo negativo 5 per validare transizione,
  report terminale e semantica del rigetto.
- Un replay completo delle dieci lead piu' controllo negativo, una ripetizione;
  stesso source/fixture, limiti e tool disponibili della baseline. Browser invariato:
  eventuali limiti browser vanno registrati e non mascherati da una patch collaterale.
- Modello unico iniziale `xiaomi/mimo-v2.6-pro`, reasoning `high`, pari al Worker
  storico. La baseline statica usava `z-ai/glm-5.3-flash` con reasoning `high`:
  il confronto storico cambia anche modello statico, quindi non attribuire tutto
  alla fusione. Se emerge un delta, rieseguire SOLO il caso discrepante nel percorso
  separato con lo stesso modello su entrambe le fasi, per distinguere le cause.
- Rerun mirate soltanto per differenze concrete; nessuna matrice automatica di
  modelli/reasoning o tripla ripetizione di default. Ricostruire l'immagine dal branch
  dedicato e registrarne digest; nessuna run o modifica su main.

## 7. Criteri di accettazione e consegna

Vincoli invarianti: nessuna conferma statica durevole persa per problemi dinamici;
100% dei guasti offline coperti conserva il risultato disponibile; ogni episodio ha
una chiusura durevole e un terminale modello o un motivo esplicito della sua assenza;
zero rejected causati dalla sola assenza di prova, zero conferme prive di evidenza.

Nel replay, obiettivo 9/9 conferme dinamiche storiche riprodotte. Ogni downgrade va
esaminato con prove; non accettare come successo generale un semplice 8/9. Una
correzione motivata di un falso positivo storico puo' essere legittima. La lead 14
deve almeno restare staticamente validata se il dinamico e' ancora incompleto; non e'
obbligatorio convertirla in confirmed. Il controllo 5 deve restare respinto sulla
barriera positiva, anche se ora viene chiuso prima della fase dinamica.

Consegnare matrice per lead: stato statico/dinamico, motivo stop, terminale tentato
e applicato, gap, provenance e prove, errori tecnici, tempo attivo/coda, EP e token
totali/per fase, tempo al primo HTTP e retry. Riportare durata del batch separatamente
dal lavoro totale; non usare velocita' o costo per compensare lead perse.

Ordine di implementazione: (1) sessione/factory e promozione durevole; (2) finalizzatore
e fault tests; (3) pool/broker/launcher e test integrazione offline; (4) runner e subject
BookStack, preflight; (5) smoke/replay e confronto; (6) review del diff minimo e
`ARCHITECTURE.md`. Nessun merge prima della verifica dei criteri.

Le run agentiche sono previste, non eseguite da questo task di pianificazione.
`AGENTS.md` vieta di avviare autonomamente test lunghi con costi/API: l'esecuzione
del replay richiedera' una richiesta esplicita; i test offline non hanno questo vincolo.

P1 fuori scope: fix browser, sweep reasoning/modelli, scheduler con priorita' dinamica,
aumento automatico slot e migrazione del percorso legacy alla nuova architettura.

## 8. Consuntivo iniziale del 2026-10-06 (replay rivisto nella sezione 10)

Implementato sul branch `worker-confirmer-merge`, senza merge:

- `_investigate` usa una sola conversazione, modello, prompt e sessione per lead;
  `ledger.promote` pubblica la milestone prima dei tool dinamici. I nomi contabili
  Confirmer/Worker restano distinti e i flag terminali vengono azzerati al cambio fase.
- Finalizzazione tool-free, chiusura sullo statico se il dinamico non e' finanziato,
  recupero soltanto di payload terminali completi e validi, fallback da stato autorevole.
  Gli errori tecnici restano separati dalla milestone; persistenza fallita propagata
  senza sovrascrivere l'ultimo snapshot valido. Il pool conserva anche lead promosse
  che falliscono fuori dal finalizzatore e drena le lead ammesse dopo lo stop Reader.
- Pool globale Reader/Lead (default 4/2), `lead-model` e `lead-concurrency` propagati
  da CLI Python, Laravel e web. Override incompatibili rifiutati; legacy conservato.
- Runner `benchmark:confirmer --unified-lead --replay-manifest`, sandbox nuova per
  subject/ripetizione, fixture probes esistenti, registry con boundary validi e snapshot
  `UnifiedLeadRun`. `ARCHITECTURE.md` aggiornata sul comportamento implementato.
- `freeze_unified_replay.py` congela subject Reader separati dall'evaluator;
  `report_unified_replay.py` produce la matrice e distingue tempo batch da lavoro attivo.

Manifest e subject congelati in `storage/app/benchmarks/unified-bookstack-20261006/`:
11 subject validati, scope numerici espliciti, denominatori 10 primari e 9 dinamici.
Checksum outcome baseline:
`41afa197b142e6caa722691cdc43fbb8604bd48ec4dd5a3d504382dec7589baf`.
Checkout, descriptor e label `sandbox.source_snapshot` della sandbox baseline:
`e1cd3229966d939a75a74a2224ff0643d8af337b`.
La matrice iniziale indica 11 `not_run` e nessuna conferma riprodotta: non e' un risultato
di replay e non dimostra il criterio 9/9.

Preflight senza modelli completati:

- Tutti gli 11 subject: schema, lineage Reader, checksum, source range e hash pinned.
- `bookstack-confirmer-global-20261006-082159` (scope 1) e
  `bookstack-confirmer-global-20261006-082347` (scope 14): due sandbox nuove,
  readiness/database e tutte le fixture probes riuscite, teardown completato.
- Corretto il solo setup indispensabile: il seed BookStack attende `/login` pronta
  prima di scrivere, evitando il deadlock osservato durante le migrazioni. Browser invariato.

Le suite offline coprono sessione/tool schema, compaction, grant e accounting, stop
economici, fault injection, recupero terminale, cancellazione/persistenza, pool e launcher,
artifact registry e matrice. Nessuna chiamata provider nei test. Dettagli delle ultime
verifiche:

- Python: 84 passed / 1 failed nella selezione finale. Tutti gli 80 test delle cinque
  suite P0 (`test_unified_lead`, `test_global_pipeline`, `test_worker_checkpoint`,
  `test_economic_budget`, `test_stage_artifacts`) passano. I 5 controlli aggiuntivi
  `test_budget_unaware_policy` hanno 4 passed e 1 failure preesistente:
  `test_terminal_admission_remains_available_at_the_global_cap`. Verifica separata
  con `budget.py` caricato da `git show HEAD` riproduce la stessa ammissione Reviewer
  `False`. Correggere questo comportamento legacy e' P1, non incluso.
- PHP: 72 passed, 319 assertions nella selezione finale di launcher, web, replay,
  scorecard, report Worker e finalizzazione batch.
- Controlli di sintassi PHP, Ruff F821/F822/F823 sui file Python modificati,
  `git diff --check`, Prettier sulla pagina e Pint sul nuovo test replay riusciti.
  ESLint mirato non verificato: la configurazione effettua uno scan delle directory
  e incontra EPERM su `.pytest-tmp/pytest-of-Alessandro`. Pint sui file PHP esistenti
  segnala differenze di stile; non applicato un reformat collaterale.

Immagine costruita dal branch con tutte le modifiche Python:
`lailaps-pentest-agent:unified-lead-20261006`, digest
`sha256:a4bcdf7d286026526b430a94c2107cf1811d31c03913674de375fe3591f1f3df`.
CLI `confirm-lead --help` verificata con rete disabilitata. Laravel usa questo nuovo
tag come default; `PENTEST_AGENT_IMAGE` resta disponibile e il tag baseline non e'
stato sovrascritto.

Residuo del primo preflight interrotto: la sandbox
`bookstack-confirmer-global-20261006-081652` e' stata rimossa, ma la directory
`storage/framework/lailaps-confirmer/bookstack-confirmer-global-20261006-081652`
resta sul disco. La rimozione della sola directory temporanea verificata e' stata
rifiutata dal controllo automatico con motivo `blocked by policy`; non aggirato.

## 9. Comandi preparati, non eseguiti: smoke e replay API

`AGENTS.md` e la sezione 7 richiedono una richiesta esplicita per avviare questi loop
con costi/API. Il modello unico e' `xiaomi/mimo-v2.6-pro`; `Models.json` mantiene
reasoning Worker `high`, applicato a entrambe le fasi. Envelope totale 100M EP,
statico iniziale 500k e dinamico condizionato 1M, come nella baseline. Il modello
statico storico era diverso: eventuali delta richiedono il controllo mirato gia' descritto.

Da PowerShell nella root, dopo autorizzazione esplicita:

```powershell
$env:PENTEST_AGENT_IMAGE = 'lailaps-pentest-agent:unified-lead-20261006'
php artisan benchmark:confirmer bookstack --unified-lead --replay-manifest=storage/app/benchmarks/unified-bookstack-20261006/manifest.json --scope=investigation-1 --scope=investigation-14 --scope=investigation-5 --lead-model=xiaomi/mimo-v2.6-pro --envelope-points=100000000 --repetitions=1
```

Esaminare le tre smoke prima di avviare il batch completo:

```powershell
php artisan benchmark:confirmer bookstack --unified-lead --replay-manifest=storage/app/benchmarks/unified-bookstack-20261006/manifest.json --lead-model=xiaomi/mimo-v2.6-pro --envelope-points=100000000 --repetitions=1
$replayOutcomes = Get-ChildItem -LiteralPath 'storage/app/runs/bookstack/confirmer-global' -Recurse -Filter '*-outcome.json' | Select-Object -ExpandProperty FullName
agent/pentest-agent/.venv/Scripts/python.exe agent/pentest-agent/benchmarks/report_unified_replay.py storage/app/benchmarks/unified-bookstack-20261006/manifest.json @replayOutcomes --output storage/app/benchmarks/unified-bookstack-20261006/matrix.json
```

Lo script filtra gli outcome per `unified-v1` e scope; mantiene le ripetizioni esplicite
anziche' selezionare silenziosamente il risultato migliore. Dopo il replay restano la
revisione dei downgrade, il criterio 9/9 e l'eventuale controllo separato soltanto dei
casi discrepanti. Nessun merge prima di questa verifica.

## 10. Revisione: replay fedele al downstream globale

Su richiesta dell'utente, il default `--unified-lead --replay-manifest` usa ora una
sola sandbox e `GlobalPipeline` per ripetizione. I comandi della sezione 9 restano
validi e ora avviano il batch globale; `--lead-concurrency=2` e' il default.
`--isolated-leads` abilita il precedente controllo con una sandbox per caso.

Il batch usa deduper, admission, broker, pool e finalizzazione di produzione,
con cap totale condiviso e grant statici/dinamici condizionati per lead. CBM,
surface inventory, materializzazione e setup target avvengono una volta per batch.
Lo stato dell'applicazione e i notebook della nuova run sono condivisi; history,
sessione provider e client/cookie HTTP sono isolati per lead, come nel globale.
Nessun reset fra casi; sandbox nuova soltanto alla ripetizione successiva.

Il freezer conserva `context.json` con il primo output Recon storico, separato
dall'evaluator e verificato tramite checksum. Le undici aree Reader originarie
sono state verificate contro questa Recon. Ogni investigatore riceve la propria
area, senza espandere il contesto all'intera Recon. Nessuna nota storica finale,
CandidateHandoff o prova Worker viene importata. Non si eseguono nuovi loop
Recon/Reader: il confronto misura il downstream delle ReaderLead congelate e
non la discovery o il costo dell'audit globale completo. Coverage discovery
rimane incompleta esplicitamente.

Il registry filtra i boundary per lead prima di collegarli agli artifact parent;
la matrice legge il singolo outcome aggregato, mantenendo consumo per lead/fase
e tempo batch separato. Retry/errori aggregati sono marcati con scope `batch`.
I test offline verificano la condivisione del broker e dei notebook, isolamento
HTTP, concorrenza, admission con cap insufficiente, assenza di chiamate Recon/Reader,
un solo avvio per ripetizione e corretto collegamento finding/artifact/consumo.

Smoke/replay con API non eseguiti; criterio 9/9 ancora da verificare. Per i soli
dieci casi primari usare gli scope 1, 2, 3, 4, 6, 10, 11, 12, 14 e 18; senza filtro
viene incluso anche il controllo negativo 5.

Verifiche della revisione completate:

- Python: 65 passed nelle suite `test_global_pipeline`, `test_unified_lead`,
  `test_cli_resilience`, `test_economic_budget` e `test_stage_artifacts`;
  due controlli mirati successivi su CLI batch e matrice aggregata passano.
- PHP: 29 passed, 99 assertions nelle suite `UnifiedLeadReplayTest`,
  `PentestRunCommandTest` e `GlobalPipelineOptionsTest`.
- Preflight runtime dei dieci casi primari in una sola sandbox:
  `bookstack-confirmer-global-20261006-111506`. Readiness, database e tutte le
  fixture probes riuscite; teardown verificato. Nessuna chiamata modello.
- Immagine aggiornata `lailaps-pentest-agent:unified-lead-20261006`, digest
  `sha256:fa41a59c186f7ffe70f4c877832e2b19402b13a51c6b0d2036b623c2c1335fd9`.
  CLI `confirm-lead --help` verificata con rete disabilitata; espone
  `--lead-concurrency`. Questo digest sostituisce quello storico della sezione 8.
- Controlli mirati di sintassi e `git diff --check` riusciti.

Comando per il replay API dei dieci casi, preparato ma non eseguito:

```powershell
$env:PENTEST_AGENT_IMAGE = 'lailaps-pentest-agent:unified-lead-20261006'
php artisan benchmark:confirmer bookstack --unified-lead --replay-manifest=storage/app/benchmarks/unified-bookstack-20261006/manifest.json --scope=investigation-1 --scope=investigation-2 --scope=investigation-3 --scope=investigation-4 --scope=investigation-6 --scope=investigation-10 --scope=investigation-11 --scope=investigation-12 --scope=investigation-14 --scope=investigation-18 --lead-model=xiaomi/mimo-v2.6-pro --lead-concurrency=2 --envelope-points=100000000 --repetitions=1 --tool-output
```

## 11. Avvio API autorizzato dall'utente

Il 6 ottobre l'utente ha chiesto esplicitamente di avviare i dieci casi e verificarne
il funzionamento. Il primo tentativo `bookstack-confirmer-global-20261006-131442`
ha superato il preflight ma e' fallito prima delle chiamate LLM: il launcher batch
non passava il parametro obbligatorio `reader_model` a `GlobalPipeline`.
Corretto il passaggio nel launcher; il test CLI ora usa il vero costruttore della
pipeline e simula soltanto `run`. Tutti gli 8 test CLI passano offline.

Immagine ricostruita con la correzione: `lailaps-pentest-agent:unified-lead-20261006`,
digest `sha256:16f7067cf07ccde2c31079526c0729be2dda98bd2268f260adb620ceb1852155`.
Riavviato lo stesso comando dei dieci casi come
`bookstack-confirmer-global-20261006-132401`, processo PHP in background.
Log launcher e metadati sono in
`storage/app/benchmarks/unified-bookstack-20261006/replay-20261006-132359.*`.
Il preflight della nuova run e' riuscito. Il criterio finale 9/9 rimane da verificare
alla conclusione del replay; il tentativo fallito non costituisce un risultato funzionale.
