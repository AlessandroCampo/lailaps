# Benchmark real-world autorizzati: BookStack e Invoice Ninja

## Obiettivo e confini operativi

Integrare quattro benchmark ripetibili basati su versioni storiche vulnerabili di
BookStack e Invoice Ninja, per misurare la capacita di Lailaps di passare da un
sospetto statico a una vulnerabilita confermata nel proprio ambiente protetto:

- `bookstack-v0.30.3-xss`: 2 vulnerabilita note;
- `bookstack-v0.30.3-ssrf`: 1 vulnerabilita nota;
- `invoice-ninja-v5.11.23-ssrf`: 1 vulnerabilita nota;
- `invoice-ninja-v5.13.0-xss`: 2 vulnerabilita note.

Il benchmark e un caso speciale di audit interno autorizzato. Non introduce una
modalita per colpire installazioni pubbliche e non riusa il ramo `pentest:run
--url`: ogni target real-world viene materializzato da Laravel, avviato in
container effimeri dedicati e distrutto a fine run. Tutte le richieste dinamiche
sono limitate al target della run e agli eventuali servizi di osservazione creati
dallo stesso orchestratore.

I benchmark sintetici esistenti e il contratto `lailaps.benchmark/v2` restano
compatibili. L'obiettivo e aggiungere una suite real-world senza modificare il
comportamento degli audit ordinari sulle tre modalita di target gia supportate da
Lailaps.

## Architettura nel progetto

Laravel resta il confine canonico pre-agente e possiede:

- catalogo, materializzazione e verifica degli snapshot;
- creazione, preparazione, health check e teardown della sandbox;
- selezione del contesto pubblico visibile all'agente;
- lettura post-run della ground truth privata;
- matching, scoring, confronto e conservazione degli artefatti.

Python/Pydantic AI continua a possedere il loop di audit e viene esteso soltanto
con i mezzi di conferma necessari: browser controllato per XSS e callback OOB
locale per SSRF. L'agente non sceglie il target, non vede gli advisory e non
conosce i risultati attesi.

Per evitare di concentrare altra logica in `App\Console\Commands\PentestRun`,
estrarre l'esecuzione comune in un servizio applicativo, ad esempio
`App\Services\Pentest\AuditRunner`. `pentest:run` e il nuovo `benchmark:run`
costruiscono input differenti ma invocano lo stesso runner. Non usare una
chiamata Artisan annidata: renderebbe piu fragili gestione degli errori, teardown
e test.

## Catalogo, runtime e ground truth

Introdurre una directory Laravel-owned fuori dal build context dell'agente:

```text
benchmarks/real-world/
  catalog.json
  bookstack/v0.30.3/
    runtime/compose.yaml
    runtime/Dockerfile
    runtime/lailaps.audit.yaml
    public/xss.json
    public/ssrf.json
    private/xss.ground-truth.json
    private/ssrf.ground-truth.json
  invoice-ninja/v5.11.23/...
  invoice-ninja/v5.13.0/...
```

La directory contiene solo configurazione, lock e metadati; gli snapshot upstream
non vengono vendorizzati. I checkout verificati vivono in
`storage/app/private/benchmarks/snapshots/<target>/<commit>/`, mentre working copy,
volumi e credenziali generate per la singola run vivono sotto l'audit ID e sono
rimossi dal teardown.

Separare due contratti:

- `lailaps.real-world-benchmark/v1`: metadati pubblici indispensabili alla run,
  inclusi ID, target, commit, categoria/focus, attori di test, capability
  browser/OOB e limiti del target;
- `lailaps.real-world-ground-truth/v1`: unita attese con advisory, CWE, source
  anchor, route, input, sink, comportamento, hash e stato della riproduzione.

La ground truth puo riusare la forma delle finding unit di
`lailaps.benchmark/v2`, aggiungendo i campi real-world. Il parser attuale resta
quindi valido per DVWA, Mutillidae, Juice Shop e BenchmarkJava; il nuovo evaluator
usa un adapter esplicito invece di cambiare silenziosamente la semantica di v2.

Il manifest pubblico passato alla run deve essere generato con una allowlist di
campi, non rimuovendo campi da una copia della ground truth. Non deve contenere
advisory, CVE/GHSA, titoli attesi, file, linee, hash, route vulnerabili, alias,
payload, patch o denominatori. Le credenziali degli attori di test sono ammesse,
ma devono essere effimere o confinate al benchmark e oscurate nei log.

Aggiornare `.dockerignore` dell'agente per escludere esplicitamente
`benchmarks/` come difesa aggiuntiva. Verificare inoltre che nessuna directory
privata venga montata in `/workspace`, `/artifacts` o nel toolbox. Laravel legge
la ground truth solo dopo l'arresto dell'agente.

## Materializzazione immutabile

Aggiungere un `RealWorldBenchmarkCatalog` e un
`BenchmarkSnapshotMaterializer` che:

1. risolvono esclusivamente un benchmark ID presente nel catalogo;
2. scaricano il solo repository e commit consentiti in una directory privata;
3. eseguono checkout detached;
4. verificano `git rev-parse HEAD`, URL origin normalizzato, commit completo e,
   se dichiarato, corrispondenza del tag annotato;
5. verificano gli SHA-256 degli anchor sullo snapshot vulnerabile;
6. rifiutano symlink o path che escano dalla root materializzata;
7. scrivono nell'artefatto pubblico commit e hash verificati, senza gli anchor
   privati.

Il resolver non accetta repository, ref o compose arbitrari dalla CLI. Tutti
questi valori arrivano dal catalogo versionato. Il fetch e l'installazione delle
dipendenze avvengono prima di attivare la rete `internal` del runtime; durante
l'audit target, agent tools e browser non hanno egress Internet.

## Estensioni alla sandbox esistente

Oggi `SandboxSpecDTO` assume che compose e `lailaps.audit.yaml` siano dentro
`projectPath`. Per gli snapshot upstream non modificati servono input distinti:

- `sourceRoot`: checkout upstream montato read-only per l'agente;
- `composeFile` e `composeWorkingDirectory`: runtime curato da Lailaps;
- `auditProfilePath`: setup/readiness curato da Lailaps;
- `runtimeSourcePath`: copia o volume temporaneo scrivibile usato dal target;
- policy di rete/capability dichiarate dal benchmark.

Estendere `AuditProfile` con `fromFile()` e mantenere `fromProject()` come wrapper
retrocompatibile. Estendere `ComposeDriver` per un compose esplicito consentito
dal catalogo e conservare l'auto-discovery corrente per `pentest:run`. Le label
`SandboxLabels`, i limiti, il binding delle porte su `127.0.0.1`, il TTL e il
teardown restano obbligatori anche per i benchmark.

Il runtime usa una copia temporanea del sorgente: lo snapshot verificato resta
immutabile e read-only, mentre Composer, cache, storage e file generati scrivono
solo nella working copy o in volumi per-run. Il teardown elimina container,
network, volumi e working copy; lo snapshot verificato puo restare in cache.

Runtime previsti, fissati per digest nel catalogo:

- BookStack: PHP 7.4 e MariaDB 10.5 con dipendenze Composer compatibili;
- Invoice Ninja: PHP 8.2, MySQL 8.0 e Redis 7 dove richiesto.

Ogni runtime ha seed idempotenti per amministratore, utente/editor e
vittima/cliente, oltre ai dati minimi del caso. `SandboxPreparationService`
continua a eseguire setup e readiness prima di consumare token, ma dovra poter
indirizzare `target_exec` al servizio dichiarato anziche soltanto al container web
risolto. La readiness funzionale include almeno login di ogni attore e presenza
della route necessaria, non solo una risposta generica del web server.

## Isolamento di rete e consenso

`benchmark:run` non espone `--url`, `--assume-authorized`, repository URL o ref.
L'autorizzazione deriva dal fatto che il comando puo selezionare soltanto fixture
versionate, create localmente da Lailaps. Negli audit ordinari rimane invariato il
consenso esplicito gia applicato da `pentest:run` per host non locali.

Per ogni benchmark creare una rete Docker dedicata con queste proprieta:

- target, browser e sink sono raggiungibili solo nella rete della run;
- le porte necessarie all'orchestratore sono pubblicate esclusivamente su
  `127.0.0.1` e su porte casuali;
- la rete di verifica e `internal` durante l'audit;
- il client HTTP conserva l'allowlist del base URL e il browser accetta soltanto
  URL relativi same-origin;
- il command executor puo operare solo sui container con lo stesso audit ID;
- nessun segreto host o ground truth entra nell'ambiente del target/agente.

Il socket Docker oggi usato dai command tool non deve acquisire nuovi privilegi
per questa feature. A breve termine i test devono dimostrare l'enforcement di
container ID e audit label; la sostituzione con un broker Docker ristretto resta
un hardening separato e non blocca il benchmark se il perimetro corrente viene
rispettato.

## Evidenza dinamica tipizzata

Generalizzare `ExploitAttempt` senza rompere i report correnti introducendo un
discriminatore `evidence_type: http | browser | oob`. Per `http`, i campi attuali
(`request_id`, metodo, path, status e baseline) rimangono validi. Il report
continua a esporre una lista unica di finding; `confirmed` e `suspected` restano
viste derivate dallo stato dell'evidenza.

### XSS

Aggiungere un tool Playwright/Chromium eseguito in un container separato e
abilitato solo dai manifest che dichiarano `browser`. Il tool accetta:

- actor noto e sessione actor-scoped;
- path relativo same-origin;
- sequenza limitata e validata di navigate/click/fill/submit;
- marker univoco generato dal tool, non scelto liberamente dal modello.

Registra `browser-transactions.jsonl` con navigazione, actor, marker, dialog,
console o beacon osservati e riferimenti agli step, applicando redazione a cookie,
token e password. Una XSS e `confirmed` soltanto quando esistono:

- una baseline negativa senza marker eseguito;
- un tentativo exploit distinto;
- esecuzione effettiva dello stesso marker nella pagina prevista;
- riferimenti validi a entrambe le transazioni nel finding.

Riflessione testuale del payload, presenza nel DOM o risposta HTTP 2xx non sono
sufficienti.

### SSRF

Aggiungere un servizio `ssrf-sink` comune, senza accesso a Internet, e un tool OOB
che espone all'agente solo tre operazioni ad alto livello: genera/resetta nonce,
fornisce la destinazione interna autorizzata e verifica la callback associata.
L'API di osservazione non deve essere raggiungibile dal target come endpoint di
lettura.

Registrare trigger e callback in `oob-transactions.jsonl`. Una SSRF e `confirmed`
soltanto se, dopo il trigger HTTP sul target, il sink osserva una nuova callback
con nonce, audit ID e finestra temporale corretti. Status 2xx, timeout o errore
applicativo senza callback restano evidenza insufficiente.

## Ground truth dei quattro benchmark

Le unita attese restano sei:

- `BS-XSS-001`: link attachment XSS;
- `BS-XSS-002`: page-content XSS tramite URI `javascript:`/form action; il
  meta-refresh e evidenza alternativa dello stesso advisory, non una terza unita;
- `BS-SSRF-001`: flusso export verso `ImageService::imageUriToBase64`, route di
  export e fetch remoto corretto in v0.30.5;
- `IN-SSRF-001`: flussi vulnerabili nei servizi PDF/template, verificati rispetto
  al fix completo `2a9bf353b432d7060e85487b617151ecbc36247d`;
- `IN-XSS-001`: product notes verso Markdown/HTML non sanificato;
- `IN-XSS-002`: line-item descriptions con bypass della denylist.

Per ogni unita conservare separatamente:

- fatti dell'advisory;
- fatti osservati nel diff di correzione;
- anchor e hash ricavati dalla versione vulnerabile;
- ricetta e risultato della riproduzione locale;
- requisiti minimi per classificare il finding come sospetto o confermato.

Le categorie usano il registry gia adottato dal progetto: XSS in
`A05:2025 Injection` con focus `Cross-Site Scripting`, SSRF in
`A01:2025 Broken Access Control` con focus `Server-Side Request Forgery`. Il
focus restringe il lavoro dell'agente ma non crea una tassonomia parallela.

## Evaluator real-world

Estendere `BenchmarkEvaluator` tramite una strategia dedicata, lasciando
invariato il matching source-anchor di `lailaps.benchmark/v2`. Per i benchmark
real-world calcolare uno score di coppia configurabile:

- CWE: 15;
- source location: 25;
- route: 20;
- input/sink: 15;
- comportamento osservato: 15;
- similarita di titolo/descrizione: 10.

Normalizzare path, line range, route e nomi CWE prima dello scoring. Applicare
matching massimo uno-a-uno globale, non il primo match trovato. Soglie iniziali:

- `>= 70`: match automatico;
- `45-69`: ambiguo;
- `< 45`: non associato.

Un finding puo coprire piu unita soltanto se contiene location e prove dinamiche
distinte per ciascun flusso. In particolare, un finding generico non puo produrre
`2/2` per BookStack XSS o Invoice Ninja XSS. Finding ulteriori associati alla
stessa unita sono duplicati.

Il reviewer Pydantic AI e opzionale, disabilitato di default e invocato soltanto
per coppie nella fascia ambigua. Riceve finding e ground truth minima necessaria
dopo la run e restituisce un output strutturato: `match`, `no_match`, `duplicate`
o `insufficient_evidence`, con confidence e motivazione. Il reviewer non puo
promuovere a `confirmed` una prova che non supera le regole deterministiche
browser/OOB.

Produrre:

- matched, confirmed, suspected, missed e duplicate;
- additional valid, false positive adjudicated e unmatched/unadjudicated;
- recall di detection, recall di conferma e precisione solo sui casi adjudicati;
- tempo totale, token, richieste modello e tool call per nome;
- costo audit e costo reviewer separati.

Un finding unmatched ma provato dinamicamente puo diventare `additional_valid`
solo tramite regola deterministica o adjudication esplicita. Un finding statico
unmatched resta `unadjudicated` e non viene contato automaticamente come falso
positivo.

## CLI e artefatti

Aggiungere:

```text
php artisan benchmark:run <benchmark-id> [--keep] [--review-ambiguous]
php artisan benchmark:compare --runs=<audit-id-or-path> --runs=<audit-id-or-path>
```

`benchmark:run`:

1. risolve il benchmark dal catalogo;
2. genera un audit ID non ambiguo (preferibilmente ULID/UUID);
3. materializza e verifica lo snapshot;
4. crea runtime, seed e readiness;
5. genera il solo contesto pubblico;
6. invoca `AuditRunner` con source root, target e capability fissati;
7. arresta l'agente, carica la ground truth privata e valuta;
8. esegue sempre teardown salvo `--keep`, stampando in tal caso durata e comando
   esplicito di pulizia.

`benchmark:compare` legge risultati gia prodotti e non rilancia target, browser,
agente o reviewer.

Conservare sotto `storage/app/audits/<audit-id>/artifacts`:

- `benchmark-public.json` sanitizzato;
- `snapshot-verification.json` con repository, commit e hash verificati;
- `report.json`, `telemetry.json` e log di completamento;
- `transactions.jsonl`, `browser-transactions.jsonl` e
  `oob-transactions.jsonl` quando applicabili;
- `benchmark.json` con score e adjudication;
- un indice degli artefatti con schema version e checksum.

Non copiare la ground truth completa negli artefatti della run. Lo score puo
contenere ID delle unita e motivi sintetici, ma non payload o segreti. Le
transazioni devono avere dimensione limitata e redazione deterministica.

## Piano di implementazione

### Fase 1 - Contratti e orchestrazione

- Definire parser/validator dei due nuovi contratti e il catalogo dei quattro ID.
- Estrarre `AuditRunner` da `PentestRun` mantenendo identiche le invocazioni
  correnti.
- Aggiungere input espliciti per compose e audit profile esterni.
- Implementare materializer, verifica immutabile e artefatto pubblico sanitizzato.

### Fase 2 - Runtime ripetibili

- Portare online BookStack v0.30.3 e i due snapshot Invoice Ninja.
- Rendere setup, migration e seed idempotenti.
- Applicare digest, rete interna, label, limiti, health e teardown.
- Aggiungere `ssrf-sink` senza egress e con API nonce-scoped.

### Fase 3 - Evidenza dinamica

- Generalizzare `ExploitAttempt` con evidenza tipizzata.
- Implementare browser tool, sessioni attore, baseline e marker.
- Implementare tool OOB e correlazione callback.
- Aggiornare ledger, report, telemetria e reader degli artefatti Laravel.

### Fase 4 - Scoring e confronto

- Implementare strategia real-world con matching pesato uno-a-uno.
- Aggiungere classificazione duplicati/additional/unadjudicated.
- Integrare il reviewer opzionale per la sola fascia ambigua.
- Implementare `benchmark:compare` e riepilogo CLI.

## Test e criteri di accettazione

### Test PHP

- parsing e rifiuto di schema/versioni/campi non validi;
- catalog resolver che rifiuta ID, repository e ref non dichiarati;
- checkout detached e fallimento su origin, commit, tag o hash errati;
- path traversal e symlink fuori snapshot rifiutati;
- compose/profile esterni senza regressioni sull'auto-discovery attuale;
- setup/readiness idempotenti e teardown anche dopo errore parziale;
- fixture evaluator per `2/2`, `1/2`, duplicato, ambiguous, additional valid,
  falso positivo adjudicato e unmatched statico;
- matching per CWE, file/line, route, input/sink, soglie e assegnazione globale.

### Test Python

- compatibilita dei report HTTP esistenti;
- validazione di `http | browser | oob` e rifiuto di evidenze incoerenti;
- browser baseline negativa ed esecuzione marker positiva;
- rifiuto di navigazione assoluta, cross-origin o azioni non consentite;
- SSRF con nonce corretto e callback assente, errata, scaduta e presente;
- redazione di cookie, token, password e header sensibili;
- telemetria distinta per HTTP, browser e OOB.

### Test di non-leakage e isolamento

- ground truth assente dall'immagine agente e toolbox;
- nessun advisory, anchor, denominatore o path privato in prompt, argv, env e
  manifest pubblico;
- agente avviabile senza montare `benchmarks/real-world/private`;
- target, browser e sink senza egress durante l'audit;
- host binding solo su loopback;
- command tool incapace di operare su container con audit ID diverso;
- callback SSRF osservabile dall'orchestratore ma API di lettura non accessibile
  al target.

### Smoke test Docker e acceptance finale

Per ogni benchmark verificare da ambiente pulito:

- materializzazione e commit/hash corretti;
- health, readiness e login di tutti gli attori;
- route necessaria raggiungibile;
- assenza di accesso Internet dal runtime;
- completamento del comando e teardown senza residui.

L'acceptance richiede inoltre:

- denominatori `2, 1, 1, 2`;
- BookStack page-content distinto dall'attachment XSS;
- Invoice Ninja product notes distinto da line-item descriptions;
- stato `confirmed` per XSS solo con baseline ed esecuzione browser;
- stato `confirmed` per SSRF solo con callback OOB correlata;
- nessuna ground truth visibile all'agente;
- benchmark sintetici, `pentest:run` locale/compose/remoto e test esistenti ancora
  verdi.

## Fonti primarie da fissare nella ground truth

- BookStack attachment XSS:
  [GHSA-7p2j-4h6p-cq3h](https://github.com/BookStackApp/BookStack/security/advisories/GHSA-7p2j-4h6p-cq3h)
- BookStack page-content XSS:
  [GHSA-r2cf-8778-3jgp](https://github.com/BookStackApp/BookStack/security/advisories/GHSA-r2cf-8778-3jgp)
- BookStack export SSRF:
  [GHSA-8wfc-w2r5-x7cr](https://github.com/BookStackApp/BookStack/security/advisories/GHSA-8wfc-w2r5-x7cr)
- Invoice Ninja SSRF:
  [GHSA-j2m4-4rvg-26j8](https://github.com/advisories/GHSA-j2m4-4rvg-26j8)
- Invoice Ninja product notes XSS:
  [GHSA-xph7-9749-56mh](https://github.com/invoiceninja/invoiceninja/security/advisories/GHSA-xph7-9749-56mh)
- Invoice Ninja line-item XSS:
  [GHSA-98wm-cxpw-847p](https://github.com/invoiceninja/invoiceninja/security/advisories/GHSA-98wm-cxpw-847p)

Commit, tag, anchor, hash e ricette di riproduzione devono essere congelati nei
file privati dopo una verifica manuale iniziale. Gli anchor vengono sempre dalla
versione vulnerabile; le patch servono come fonte di corroborazione, non come
codice visibile all'agente.

## Fuori scope

- scansione dei servizi BookStack o Invoice Ninja esposti da terzi;
- URL o repository arbitrari nel comando benchmark;
- uso del sink SSRF per raggiungere Internet o servizi host;
- crawling browser generico fuori dalle azioni dichiarate;
- modifica o redistribuzione degli snapshot upstream nel repository;
- sostituzione completa dell'infrastruttura benchmark sintetica esistente;
- broker Docker di produzione, che resta un hardening separato.
