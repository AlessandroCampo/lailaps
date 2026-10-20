# Collegare Laravel al Recon Python preparato

Il package e il Dockerfile sono in `agent/recon-python`. La provenienza e in
`UPSTREAM.json`: branch `minimal` di Lailaps 1, commit
`1d1cb1516c1036fa61b7cf693815a3622f8f2567`, with the local
`provider-tool-fixes-20261017` patch set recorded in `UPSTREAM.json`.
Rebuild the Recon image after applying these source changes; no paid run is required.
Il loop Recon, history, budget e retry sono quelli di questa revisione.
`ReconService` prepara gli input e persiste l'outcome. Il job `PrepareProjectAnalysis`
esegue prima Semgrep, poi CBM e infine Recon, dopo il commit della source.

Il confronto con il [retry executor DeepSeek Harness, revisione 687f282e](https://github.com/deepseek-ai/deepseek-harness/blob/687f282e8625c6b8d11ff58211a90524be2e4317/packages/llm/llm-retry/src/index.ts)
conferma la distinzione utile fra retry della richiesta nel loop e rilancio dell'intero
job. Qui manteniamo la policy Python esistente, senza introdurre un altro motore.

## 1. Costruire l'immagine

Dalla root di Lailaps 2, in WSL:

```bash
docker build -t lailaps-code-index:0.12.1 app/Features/CodeIndex/docker
docker build -t lailaps-recon:minimal-1d1cb151-cbm0121-osv-ulid agent/recon-python
```

La prima immagine e gia presente se hai completato CBM. L'immagine Recon contiene
Python, il package con le dipendenze del lock e il binario CBM 0.12.1. Non contiene
source di progetti, risultati Semgrep, indici o chiavi provider. Non va ricostruita
durante ogni onboarding. Per il deploy costruisci le immagini sul server o pubblica
quelle costruite nel registry che usi per il deploy.

## 2. Preparare gli input Laravel

Lavora sulla source ID fissata nel job, anche se nel frattempo e stata archiviata.
Esegui i tre `prep($source)` e usa gli artefatti restituiti, senza cercare di nuovo
la latest source o l'ultimo artefatto globale.

Prima del processo crea directory private attraverso `ProjectService::prepareDirectory`:

- Una directory di input per questa esecuzione.
- Una directory di output per questa esecuzione.
- Una copia di lavoro dell'intera directory `$codeIndexArtifact->path()`.

Copia l'indice dopo che il processo di indicizzazione e terminato, includendo tutti
i file. Non copiare soltanto il file SQLite: il runtime puo avere bisogno di cache
e file ausiliari. Recon interroga questa copia scrivibile e non richiama l'indicizzatore.
HTTP, CLI, queue e container Recon devono usare lo stesso UID/GID Linux.

Esporta `$staticAnalysisArtifact->payload` nel file `semgrep.json` senza wrapper.
Calcola `sha256` sui byte esatti del file esportato. Non usare automaticamente
`ProjectArtifact.content_hash`: quello riguarda lo stdout originale, mentre la
serializzazione Laravel puo produrre byte differenti per lo stesso JSON.

Esporta allo stesso modo il payload OSV in `dependencies.json`, conservando il report
completo. Il runtime espone una sintesi High/Critical e il catalogo consultabile
`dependency-advisories`; severita inferiori e sconosciute restano recuperabili.
Il nuovo campo richiede l'immagine aggiornata con suffisso `-osv`.

## 3. Scrivere `prepared.json`

Questa e la struttura completa accettata. Sostituisci ID, hash e percorsi con valori
ricavati dai record Laravel e dalle directory appena preparate:

```json
{
  "schema_version": 1,
  "run_id": "recon-123",
  "source_id": "01M57BE6CVRVVJ7V8SYSEP67RY",
  "source_hash": "0000000000000000000000000000000000000000000000000000000000000000",
  "source_path": "/workspace/source",
  "source_host_path": "/home/alessandro/projects/lailaps-2/storage/app/private/projects/1/sources/10/files",
  "static_analysis": {
    "id": 11,
    "project_source_id": "01M57BE6CVRVVJ7V8SYSEP67RY",
    "status": "ready",
    "reason": null,
    "path": "/inputs/semgrep.json",
    "sha256": "0000000000000000000000000000000000000000000000000000000000000000"
  },
  "code_index": {
    "id": 12,
    "project_source_id": "01M57BE6CVRVVJ7V8SYSEP67RY",
    "status": "partial",
    "reason": "Incomplete index: 44 partial parses, 26 unusable parses.",
    "path": "/index",
    "project": "workspace-source",
    "engine_version": "0.12.1"
  },
  "dependency_analysis": {
    "id": 13,
    "project_source_id": "01M57BE6CVRVVJ7V8SYSEP67RY",
    "status": "ready",
    "reason": null,
    "path": "/inputs/dependencies.json",
    "sha256": "0000000000000000000000000000000000000000000000000000000000000000"
  },
  "model": "xiaomi/mimo-v2.6-pro",
  "reasoning_effort": "medium",
  "point_limit": 160000,
  "request_limit": 48
}
```

`source_hash` e `ProjectSource.content_hash`. Il runtime verifica i file montati
con lo stesso algoritmo Laravel, includendo i file nascosti. Gli hash a zero
nell'esempio sono segnaposto. `model` e i limiti devono essere una scelta esplicita
del chiamante; i valori mostrati corrispondono ai default importati da `minimal`.
`point_limit` usa i punti economici del runtime legacy, non dollari o token.

`static_analysis`, `code_index` e `dependency_analysis` sono facoltativi: puoi ometterli o impostarli a
`null` quando il sensore non e disponibile. Non creare ID fittizi. Senza Semgrep
non vengono caricati segnali; senza CBM i tool del grafo non vengono registrati.
Lo stato del sensore resta esplicito nelle limitazioni. Una Recon accettata che
ha lavorato senza uno dei sensori viene classificata `partial`, non `failed`.
I nomi dei campi alla radice sono verificati: usa `static_analysis`, `code_index`, `dependency_analysis`,
`point_limit` e `request_limit`, senza wrapper alternativi come `budget`.

`code_index.project` viene dal payload CBM. Gli stati vengono dagli enum Laravel,
e `reason` viene da `ProjectArtifact.error`. Se un sensore e `failed`, puoi omettere
il suo path/checksum/project e passare il motivo: Recon procedera con quella
limitazione. Un input dichiarato disponibile ma mancante, incompatibile o corrotto
ferma l'esecuzione prima delle chiamate modello.

`source_path` e il percorso dentro Recon. `source_host_path` e invece il percorso
host visto dal daemon Docker, necessario per il toolbox fratello creato dal runtime.
Mantieni `/workspace/source` come root logica del sorgente e monta l'indice corretto
per gli ID dichiarati: il descrittore viene costruito da Laravel, non dal modello.

## 4. Provare gli input senza usare il modello

Nel comando seguente i quattro percorsi host devono indicare le directory preparate
nel punto 2. Avvia da WSL con lo stesso utente di PHP:

```bash
docker run --rm --init \
  --user "$(id -u):$(id -g)" \
  --network none --read-only \
  --tmpfs /tmp:rw,nosuid,nodev,size=128m \
  --mount "type=bind,src=$reconSourcePath,dst=/workspace/source,readonly" \
  --mount "type=bind,src=$reconInputsPath,dst=/inputs,readonly" \
  --mount "type=bind,src=$reconIndexPath,dst=/index" \
  --mount "type=bind,src=$reconOutputPath,dst=/output" \
  lailaps-recon:minimal-1d1cb151-cbm0121-osv-ulid \
  --prepared-inputs /inputs/prepared.json \
  --output /output/outcome.json \
  --check-inputs
```

Questa modalita verifica source, JSON Semgrep e query di architettura CBM. Non serve
una chiave provider o il socket Docker. Restituisce `mode: check_inputs` e
`preparation_status: validated`: non e un risultato Recon da registrare come ready.

## 5. Avviare Recon da Laravel

Aggiungi `DockerService::runRecon(...)` per comporre i mount e il comando. Il runner
attuale forza `--network none`: rendi selezionabile la rete mantenendo `none` come
default per Semgrep e CBM. Recon usa la rete per il provider, non un target applicativo.
Servono inoltre l'accesso al socket Docker e il suo gruppo per creare il toolbox.
Questo e il comando equivalente completo:

```bash
docker run --rm --init --name "lailaps-recon-$reconRunId" \
  --user "$(id -u):$(id -g)" \
  --group-add "$(stat -c '%g' /var/run/docker.sock)" \
  --network bridge --read-only \
  --tmpfs /tmp:rw,nosuid,nodev,size=128m \
  --env OPENROUTER_API_KEY \
  --mount type=bind,src=/var/run/docker.sock,dst=/var/run/docker.sock \
  --mount "type=bind,src=$reconSourcePath,dst=/workspace/source,readonly" \
  --mount "type=bind,src=$reconInputsPath,dst=/inputs,readonly" \
  --mount "type=bind,src=$reconIndexPath,dst=/index" \
  --mount "type=bind,src=$reconOutputPath,dst=/output" \
  lailaps-recon:minimal-1d1cb151-cbm0121-osv-ulid \
  --prepared-inputs /inputs/prepared.json \
  --output /output/outcome.json
```

In PHP usa l'array degli argomenti, come per gli altri producer. Passa la chiave al
processo tramite `Process::env`, e a Docker con `--env OPENROUTER_API_KEY` senza
inserire il valore negli argomenti. La chiave deve essere disponibile nella
configurazione Laravel; non viene letta automaticamente dall'immagine.

```php
$result = Process::timeout($timeout)
    ->env(['OPENROUTER_API_KEY' => config('services.openrouter.key')])
    ->run($arguments);

$outcome = json_decode($result->output(), true, 512, JSON_THROW_ON_ERROR);
$report = $outcome['report'] ?? null;
```

Il runtime non riceve credenziali database. Solo il runtime fidato riceve chiave
provider e socket: la shell del modello usa un container separato, senza rete,
chiavi o socket e con sorgente in sola lettura. Non passare un target container.
Se cambi tag dell'immagine imposta `TOOLBOX_IMAGE` con lo stesso tag, perche il
runtime usa la propria immagine anche per il toolbox.

Imposta un timeout adeguato alla Recon e un timeout del job superiore a quello del
processo. Se lo stesso job esegue Semgrep, CBM e Recon in sequenza, il timeout del
job deve coprire la somma dei timeout delle fasi e l'overhead. `PrepareProjectAnalysis`
usa 3600 secondi per Semgrep, CBM e Recon in sequenza; la queue database riserva il
job per 7260 secondi. Anche `retry_after` deve superare il timeout del job. Mantieni `tries=1`:
i retry delle richieste sono gia nel runtime. Nel finally ripulisci il container
runtime e i toolbox identificati dalle label `sandbox.audit_id={run_id}`,
`sandbox.service=toolbox` e `sandbox.managed_by=lailaps`, soprattutto dopo un timeout
che forza l'arresto. Alcuni toolbox hanno anche un suffisso casuale nel nome:
usa le label della run per includere quelli effimeri. Il runtime normalmente li
rimuove da solo.

## 6. Leggere e classificare l'output

Stdout contiene un solo JSON, identico a `/output/outcome.json`. I log legacy vanno
su stderr: la loro presenza, da sola, non e un fallimento. Il file viene scritto
atomicamente e permette di recuperare il risultato anche dopo problemi di trasporto.

Campi da leggere:

- `run_id`, `project_source_id`, `source_hash`, `input_artifacts`: devono corrispondere
  ai valori congelati da Laravel prima del lancio.
- `preparation_status`: `ready`, `partial` oppure `failed`.
- `preparation_reason`: motivo breve quando necessario.
- `report`: report legacy completo, con `category_recon`, `area_ledger`,
  `surface_context`, `structured_outputs`, `budget`, `telemetry` ed eventuali evidenze.

Exit 1 significa failed. Exit 0 puo essere ready o partial: controlla il campo, non
soltanto il codice del processo. Un fallback globale non viene promosso a ready
anche se il vecchio `category_recon.status` dice ready. Il runtime controlla output
accettato e telemetria. Un risultato accettato con input incompleti o limiti dichiarati
viene restituito partial, conservando il report.

Per il primo collegamento richiesto, `ReconService` puo semplicemente restituire
`$outcome` al chiamante: il report e gia strutturato e disponibile senza lettori
aggiuntivi nella classe base. La successiva persistenza deve validare l'identita
e conservare `preparation_status` e `preparation_reason`, senza dedurre ready
dal solo exit code. Non e necessario duplicare nei metadati ogni campo del report.

Collega infine la sequenza nel job: static analysis, code index, Recon, sempre
sulla stessa source e con gli artefatti appena restituiti. La preparazione del Lab
rimane indipendente. Non racchiudere l'esecuzione del modello in una transazione DB.

La verifica live con il provider e volutamente separata dai controlli offline:
il porting non autorizza automaticamente un'esecuzione agentica a pagamento.
