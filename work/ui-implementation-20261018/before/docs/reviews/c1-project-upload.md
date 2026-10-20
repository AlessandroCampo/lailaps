# Review C1: progetti e upload ZIP

**Aggiornamento dopo la correzione del 9 ottobre 2026:** i difetti del flusso ZIP
descritti sotto sono stati risolti, dopo autorizzazione dell'utente. La review
seguente conserva la fotografia precedente alla correzione.

Il flusso attuale usa `App\Services\ZipService` per estrazione a stream, limiti,
hash canonico, salvataggio dello ZIP originale e dello snapshot e pulizia.
`CreateProjectAction` coordina progetto e prima source; `CreateProjectSourceAction`
gestisce deduplicazione e sostituzione transazionale. Estrazione e hash avvengono
fuori dalla transazione. `ready` è assegnato esplicitamente dopo la persistenza;
gli errori di import sono restituiti al form senza creare righe fallite. Sono
corretti anche `latestSource`, filtro ZIP, toast e metadati del caricamento.
Successivamente i path sono stati derivati dagli ID di progetto/source, eliminando
`source_path` e `metadata.archive_path`. La migrazione conserva gli snapshot
esistenti nella nuova struttura privata.

Verifica successiva: 32 controlli isolati su DB in memoria e storage temporaneo,
più smoke nel browser autenticato per creazione, deduplica, sostituzione e rifiuto
di traversal. Coperti file nascosti, compressione/ordine diversi, riuso di source
archiviate, symlink, percorsi non validi, duplicati/conflitti, archivi vuoti o
danneggiati, limiti di upload/estrazione/entry e rollback DB con pulizia.
Nessun unit/feature test aggiunto al repository o loop LLM eseguito.

L'import sincrono sostituisce per questo incremento il job di import ipotizzato
inizialmente. Vite Plus e i componenti demo preesistenti restano fuori dalla
correzione del flusso ZIP. Un cleanup dopo arresti improvvisi rimane un possibile
incremento successivo.

---

9 ottobre 2026. Repository: `/home/alessandro/projects/lailaps-2`.

La review riguarda la logica di business preesistente. Le action, la request di
creazione e i model sono stati lasciati invariati. Sono state aggiunte le rotte
e le pagine richieste, con controlli di proprietà sui nuovi percorsi di lettura
e caricamento. C1 non è ancora chiuso: il caricamento è bloccato e manca la
materializzazione della source.

## Blocchi da risolvere per C1

1. **La creazione restituisce sempre 403.**
   `app/Http/Requests/StoreProjectRequest.php:17` restituisce `false` da
   `authorize()`. Anche un utente autenticato viene fermato prima di entrare
   nel controller. Confermato dal form nel browser con un ZIP innocuo.

2. **Il controller chiama un metodo su un array.**
   `app/Http/Controllers/ProjectController.php:50`: `validated()` restituisce
   un array, quindi `$validated->only('name')` genera un errore quando la
   request sarà autorizzata. È sufficiente usare i dati della request con il
   relativo metodo `only`, oppure costruire il piccolo array passato all'action.

3. **L'action usa l'UploadedFile di Guzzle.**
   `app/Actions/CreateProjectSourceAction.php:10,20,25`: Laravel consegna un
   `Illuminate\Http\UploadedFile`, incompatibile con il parametro Guzzle.
   Inoltre `getRealPath()` non appartiene al tipo Guzzle importato.
   Questo blocca anche la nuova rotta per caricare una source su un progetto
   esistente: confermato nel browser, con errore conservato nel form.

4. **Il percorso ZIP non identifica il file salvato.**
   `app/Actions/CreateProjectSourceAction.php:41-44`: una volta corretto il
   tipo del parametro, `Storage::put($path, $uploadedFile)` usa internamente
   `putFile`. `$path` diventa una directory e il file riceve un nome generato:
   non viene scritto il file atteso `<project>/<source>.zip`. Il controllo
   `exists($path)` non verifica quindi l'archivio identificato dall'action.
   La semantica è verificabile in
   `vendor/laravel/framework/src/Illuminate/Filesystem/FilesystemAdapter.php`,
   metodo `put`. Per mantenere il nome scelto serve una scrittura con nome
   esplicito, per esempio `putFileAs`.

5. **Una scrittura fallita può comunque sostituire la source attiva.**
   `app/Actions/CreateProjectSourceAction.php:43-47`: il risultato di scrittura
   non viene controllato; il disco `project_sources` esiste ma ha `throw=false`.
   Un fallimento dello storage può quindi lasciare una nuova riga senza ZIP e
   archiviare le source precedenti. La transazione DB non annulla scritture
   filesystem. Il risultato dello storage va verificato prima della sostituzione;
   i file temporanei/orfani vanno gestiti esplicitamente nei casi di errore.

6. **Lo stato `importing` non raggiunge mai un esito.**
   `app/Actions/CreateProjectSourceAction.php:29-49`: non viene avviato un job
   di import, estratto il codice o impostato `ready`/`failed`. `uploaded_at`
   resta vuoto. La roadmap C1 richiede una revisione pronta e leggibile, con
   errori e pulizia del temporaneo. Il solo archivio caricato non completa
   questo requisito. La materializzazione dovrà anche applicare limiti e
   controlli su traversal/symlink prima di estrarre codice non fidato; oggi
   non c'è estrazione, quindi non è stata dimostrata una vulnerabilità di
   traversal nel codice corrente.

7. **L'hash identifica l'archivio, non il contenuto del progetto.**
   `app/Actions/CreateProjectSourceAction.php:25`: due ZIP con gli stessi file
   ma compressione, ordine o timestamp diversi producono hash diversi. La
   roadmap richiede che il medesimo contenuto produca un hash coerente. Se
   `content_hash` deve avere questa semantica, va calcolato sul contenuto
   materializzato in ordine stabile; l'hash dello ZIP può identificare invece
   l'artefatto ricevuto. Non occorre anticipare l'integrazione GitHub.

## Altri difetti rilevanti

- `app/Models/Project.php:18`: `latestSource()` usa `Project::class` anziché
  `ProjectSource::class`. La relazione cerca progetti usando `project_id`,
  che non è una colonna della tabella `projects`. I nuovi percorsi di lettura
  usano `sources()` e non richiamano questa relazione difettosa.
- `app/Http/Requests/StoreProjectRequest.php:29-36`: viene accettato anche
  `github`, senza richiedere il file, mentre il controller legge sempre
  `$validated['source']` e l'action crea sempre una source ZIP. Per il C1
  attuale il backend dovrebbe accettare soltanto ZIP, come la nuova rotta
  dedicata alle source. Nascondere GitHub nel form non restringe le richieste
  inviate direttamente al server.
- `app/Http/Controllers/ProjectController.php:56-59`: `withMessage` salva
  `message` in sessione con campo `text`. Il gestore toast esistente
  legge l'evento flash Inertia e la chiave `toast` con `type`/`message`.
  Anche dopo un caricamento riuscito questa conferma non apparirebbe nel toast.
- `StoreProjectRequest` non fissa una dimensione massima applicativa del ZIP.
  Esistono comunque i limiti PHP/server: non è un upload senza limiti.
  Il limite di archivio e di contenuto estratto deve essere definito insieme
  alla materializzazione richiesta da C1.

## Action e stile

Le due action chiamate dal controller sono una scelta ragionevole: la creazione
del progetto e l'aggiunta di una source sono operazioni diverse, e la seconda
serve anche per i caricamenti successivi. Non introdurrei un ulteriore layer
soltanto per nascondere queste due chiamate.

Il controller può coordinare la transazione della creazione iniziale. La
transazione dentro `CreateProjectAction`, che contiene una sola `create`, è
superflua in questo percorso. Quella dentro `CreateProjectSourceAction` va
valutata anche per l'uso autonomo della seconda action: ci sono più scritture
DB. Le transazioni annidate Laravel non sono di per sé un bug, ma non rendono
atomico il filesystem. La priorità è definire quando la nuova source diventa
attiva e cosa succede se salvataggio/import falliscono.

`Auth::id()` dentro la prima action funziona nel percorso HTTP attuale, ma
nasconde la dipendenza dall'utente autenticato. Passare l'utente avrebbe senso
quando servirà chiamarla fuori da quel contesto; non serve aggiungere adesso
un'interfaccia o una factory. Restano import inutilizzati, spaziatura irregolare
e `$projectSource` assegnato senza essere usato: pulizia locale, non refactoring
strutturale.

La soft-delete delle altre source è stata conservata: rispetta la sostituzione
richiesta. Quando saranno collegati audit/preparazioni, bisognerà garantire che
la revisione già analizzata rimanga recuperabile tramite la sua identità.
Questa estensione non è stata implementata nel task UI.

## Lavoro eseguito e verifiche

- Index paginato e limitato al proprietario, create, show progetto, show source
  con verifica del progetto padre e del proprietario; endpoint di upload source.
- Nessuna rotta di aggiornamento/cancellazione progetto; nessuna UI GitHub.
- Theme Geist tramite CSS semantico, font serviti localmente dalla pipeline
  Vite, componenti condivisi, guide e skill locale richiamate da `AGENTS.md`.
- Pagine elenco/creazione/dettaglio progetto e dettaglio source, picker e
  drag-and-drop singolo ZIP, errori, progresso upload e stato autorevole.
- Build di produzione riuscita tramite `node node_modules/vite/bin/vite.js build`
  con Node Linux di NVM. Lo script `npm run build` preesistente usa Vite Plus,
  che richiede un alias Vite diverso da quello installato: non modificato.
- Verifica browser autenticato: stato vuoto/popolato, creazione rifiutata,
  selezione e rimozione ZIP, upload source rifiutato, stati importing/failed,
  navigazione dettagli, desktop/mobile e temi chiaro/scuro. Fixture DB temporanea
  separata dai dati dell'utente, eliminata al termine; nessun import riuscito
  dichiarato. Build e sintassi PHP verificate, skill validata.
- `vue-tsc --noEmit` segnala errori nei componenti demo preesistenti (dipendenze
  mancanti e import Vue) e nel RedirectController generato per la rotta settings
  preesistente, senza diagnostiche nei nuovi file UI. Non sono stati
  corretti i demo non usati. Nessun lint globale, test unit/feature o loop LLM.

La logica originale è stata confrontata con la copia precedente alle modifiche:
request, action e model non sono stati corretti durante questo task.
