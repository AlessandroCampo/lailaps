# Consegna P0 — test isolato del deduper sulle ReaderLead DB

Implementato `benchmark:deduper <progetto> <reader-run-id>`. Recupera tutte le
ReaderLead strutturate valide della run singola o dei child dichiarati dal parent
globale. Non filtra per accepted/score/label e non rilancia Reader, discovery,
evaluator, Confirmer o dispatch. AreaEnrichmentLead e accordo/promozione delle
aree restano P1. Originali e modifiche preesistenti del checkout sono conservati.

Aggiornamento successivo: le **nuove** esecuzioni del benchmark DB usano per
GLM 5.3 Flash il routing OpenRouter `auto`; le esecuzioni e l'immagine di questa
consegna restano pinnate a InferenceNet. Vedere
`plans/GLM-auto-routing-20260929.md` per il comando del nuovo esperimento.

## Persistenza e selezione downstream

Nella tabella esistente `benchmark_stage_artifacts`, senza migrazioni:

| Tipo / ruolo | Contenuto |
|---|---|
| DeduperRun / deduper | Parent Reader, scope, ordine, manifest/hash, configurazione completa, budget, stato e usage cumulativa |
| DeduperDecision / deduper | Una decisione corrente per originale, stati separati, pass/block/partial quando validi, fingerprint e storico dei tentativi |
| ReaderLead / deduper | Payload canonico, ID/lineage originali, relazione pass/merge/residual e flag di selezione downstream |

Pass conserva il payload Reader; block conserva originale e verdetto senza una
nuova promozione; partial crea il payload restituito dal modello. Un merge rende
non selezionabili le canoniche assorbite; residual conserva le precedenti e
aggiunge la parte nuova. Non si deve filtrare semplicemente lo storico dei pass:
Confirmer seleziona solo le **canoniche finali** di una DeduperRun completata.
Errori, inconclusivi e pending non diventano pass.

Gli originali completi restano nelle righe Reader e in una copia verificata
`frozen-input.json`; il parent DB conserva riferimenti/hash, senza duplicare
l'intero corpus in una singola query MySQL. `dedup.json` è il ledger Python
autorevole per checkpoint, resume e accounting; gli import DB sono idempotenti.
Un lock impedisce due invocazioni della stessa esecuzione. Se l'import viene
interrotto, il comando riconcilia il ledger salvato prima di ripartire.

EP osservati, riserve sconosciute e USD stimati sono distinti e persistiti sul
DeduperRun. I costi Reader rimangono separati e invariati. La nuova esecuzione
richiede un envelope esplicito; la ripresa non lo ricarica. `--roles-config`
passa la configurazione condivisa completa (solo deduper viene usato) ed è
incompatibile con override individuali del ruolo. I default P0 e GLM 5.3 Flash /
InferenceNet sono mantenuti. Non vengono riusati verdetti legacy.

## Preflight reale eseguito, zero inferenza

Riferimento: i due parent DB del mattino, selezionati per ID esatto. I preflight
sono stati eseguiti con la nuova immagine e Docker `--network none`, senza env
inferenziale. Richieste, EP osservati, riserve sconosciute e USD sostenuti: **0**.

| Progetto | Reader run | Lead valide | Picco input stimato | Pacchetti >64k | Exit |
|---|---|---:|---:|---:|---:|
| YesWiki | yeswiki-reader-global-20260929-083528 | 57 | 36.311 | 0 | 0 |
| Cacti | cacti-reader-global-20260929-084906 | 144 | 81.068 | 33 | 2 |

Le 57 lead YesWiki restano 57: enrichment esclusi. Le 144 Cacti includono tutte
le righe ReaderLead valide, anche una con accepted=false che il precedente
corpus congelato non comprendeva (143 lead). Non è una lead inventata né un
enrichment contato come lead. Gli ID sono qualificati dal child run e dall'ID DB;
l'ordine è `created_at,id`, dichiarato come ordine di persistenza DB.

I parent Reader riportano rispettivamente `incomplete` e `technical_failure`:
il test deduper può lavorare sui loro originali validi, ma non dichiara completa
la discovery storica. Tutte le decisioni dei preflight sono ancora pending e
nessuna lead è promossa. `preflight_complete` significa solo dimensionamento.
La simulazione all-pass non misura qualità semantica né garantisce che il
recupero integrale rientri; anche la seconda richiesta ha il suo preflight.

Cacti resta **bloccato dal cap P0**, prima di ogni chiamata al provider.
Non sono introdotti paginazione, aumenti del cap o sottoinsiemi impliciti.

Esecuzioni persistite da riusare:

- YesWiki: `deduper-01m3pwn3k2nbnhasqmnf55bnyj`, envelope **250.000 EP**.
- Cacti: `deduper-01m3pwn3n8bcjekks6sb1xkrfh`, envelope **1.000.000 EP**.

Directory: `storage/app/runs/<progetto>/deduper/<execution-id>`, con
`frozen-input.json`, `dedup-input.json`, `dedup-roles.json`, `preflight/preflight.json`,
`preflight/phase-status.json`, `db-summary.json` e `db-report.md`. Dopo inferenza
contiene anche `dedup.json` e `phase-status.json` della deduplica.

La proiezione prudenziale all-pass con output massimo e cache zero è
325.715,8 EP / USD 0,116867495 per YesWiki e 1.479.823,8 EP / USD 0,444248675 per
Cacti. Non è spesa sostenuta: la usage osservata sostituisce le riserve.
Questa previsione supera gli envelope scelti, quindi **non garantisce il
completamento entro budget**; la fase si sospende se il residuo non basta.
Il cap input/output non concede funding aggiuntivo.

## Comandi dalla root del repository

Root: `C:\Users\Alessandro\Desktop\latest-projects\lailaps`.

### 1. Preflight offline ripetibile degli stessi artifact DB

```powershell
php artisan benchmark:deduper yeswiki yeswiki-reader-global-20260929-083528 --dedup-run=deduper-01m3pwn3k2nbnhasqmnf55bnyj --preflight-only
php artisan benchmark:deduper cacti cacti-reader-global-20260929-084906 --dedup-run=deduper-01m3pwn3n8bcjekks6sb1xkrfh --preflight-only
```

Il secondo comando restituisce intenzionalmente **2**, stop `context_limit`.
Non avviare il replay completo Cacti nel P0.

Per creare una nuova esecuzione e un **nuovo envelope esplicito**, anziché
riusare quelli sopra:

```powershell
php artisan benchmark:deduper yeswiki yeswiki-reader-global-20260929-083528 --deduper-points=250000 --preflight-only
# Alternativa: configurazione completa, senza override individuali
php artisan benchmark:deduper yeswiki yeswiki-reader-global-20260929-083528 --roles-config=plans/deduper-role-config.example.json --preflight-only
```

L'esempio JSON ha un proprio budget: verificare `deduper.total_points` prima di
creare l'esecuzione. Per riprendere usare l'ID stampato, senza rifinanziamento.

### 2. Smoke paid limitato, da lanciare soltanto dall'utente

```powershell
pwsh -NoProfile -File plans/testing-reader-20260929/Recover-ReaderArtifacts.ps1 -Action Smoke -Target yeswiki -Image lailaps-pentest-agent:deduper-db-p0-20260929-v2
```

Riusa lo smoke P0 esistente: pass/block/partial, recupero raggruppato di originali
e lettura di blob sorgente per il judge, con nuovo envelope esplicito 50.000 EP
per ruolo. Non è una nuova discovery. Non riavviarlo per azzerarne il budget se
esiste già: ispezionare il report. Output validi, usage completa e nessuna
sequenza di fallimenti sono prerequisiti tecnici al replay, da verificare nel
suo `phase-status.json` e report. **Non eseguito durante questa implementazione.**

### 3. Deduplica paid di recupero da DB, senza nuova discovery

Dopo lo smoke valido, per YesWiki:

```powershell
php artisan benchmark:deduper yeswiki yeswiki-reader-global-20260929-083528 --dedup-run=deduper-01m3pwn3k2nbnhasqmnf55bnyj
```

Il comando usa lo stesso corpus, immagine, contratto e budget persistiti dal
preflight; non effettua nuove chiamate Reader. Exit **0** deduplica completata,
**2** incompleta/sospesa (contesto, budget, circuito), **1** errore fatale.
Consultare `db-summary.json` e `phase-status.json`, non solo la fine del processo.

### 4. Resume dei fallimenti, stesso envelope e stesso storico

```powershell
php artisan benchmark:deduper yeswiki yeswiki-reader-global-20260929-083528 --dedup-run=deduper-01m3pwn3k2nbnhasqmnf55bnyj --resume-failed
```

Riusa gratis i successi compatibili; riprova solo fallimenti tecnici e pending.
L'incertezza semantica non viene ritentata automaticamente. Quando una riparazione
cambia la proiezione canonica, il runtime invalida/ricalcola in ordine i dipendenti.
Riaprire una run completata non crea decisioni o chiamate duplicate.

### 5. Confirmer isolato, dopo il gate di deduplica

```powershell
php artisan benchmark:confirmer yeswiki --dedup-run=deduper-01m3pwn3k2nbnhasqmnf55bnyj --path="C:/Users/Alessandro/Documents/Codex/2026-09-28/ok-vorrei-fare-la-seguente-cosa/outputs/reader-round/sources/yeswiki"
```

È un lancio paid separato dell'utente. Se una categoria OWASP non identifica una
sola categoria del catalogo, aggiungere il flag esistente `--category=<slug>`
compatibile; il comando rifiuta ambiguità senza avviare il Confirmer. La selezione
DB non richiede il file ledger: controlla originali/hash, commit, contratto,
esecuzione completata e tutte le decisioni correnti. Non combinare `--dedup-run`
con `--artifact`, `--dataset` o `--dedup-artifact`.

Il modello riceve solo canoniche finali promosse, mai block, pending, inconclusivi,
fallimenti o pass assorbiti da merge. Un pass non è una vulnerabilità confermata:
senza oracle revisionato le metriche di accuratezza sono non valutabili, non
automaticamente false né promosse a ground truth. Nessun Confirmer è stato avviato.

L'evaluator semantico resta un comando separato del precedente P0:
`plans/P0-semantic-recovery-delivery-20260929.md`. Non è eseguito dal nuovo test.

## Verifiche e immagine

- **101 test Python passati**, un warning di dipendenza: runner, retry/timeout,
  circuiti, uso/riserve, resume, schede/retrieval, gate e blob LF/checkout CRLF.
- **22 test PHP passati, 211 asserzioni**: nuove 9 prove integrate DB/runtime con
  provider simulato più registry/selettori/comandi/gate/oracle esistenti.
- Test integrati coprono pass/block/partial merge e residual, originali immutati,
  lead-only, import idempotente, interruzione prima dell'import, timeout→successo,
  successi→zero chiamate, incertezza senza retry, input/config incompatibili,
  blocco contesto e Confirmer, e lock senza mutazioni di esecuzioni concorrenti.
- Una reale contesa Windows fra lettura PHP e rinomina Python dei checkpoint è
  risolta con retry locale limitato della sola rinomina atomica, senza nuovi
  tentativi inferenziali; test dedicato verifica anche il limite del retry.
- Sintassi PHP, Ruff limitato ai file P0 e controlli diff mirati. Nessun lint
  globale, test paid, nuova discovery o modifica/interruzione di run attive.

Immagine distinta: **`lailaps-pentest-agent:deduper-db-p0-20260929-v2`**.
ID Docker: `sha256:628c0b4041b5e9034ea018bace42257ee794ae01a6d18eaa04b37accc9c52d07`;
config digest `sha256:e8ebfc2d8e30a3fadba471ce626a77e157cfdb6590fa5fbf3851c7f2d2bd7342`.
Il tag dev rimane `sha256:71eb454345c47a7f3452346d0826f17223a1ff28f8362714ecad001370f7ce6c`.

```powershell
Set-Location agent/pentest-agent
docker build --network none --pull=false -f Dockerfile.recovery --label lailaps.feature=isolated-deduper-db -t lailaps-pentest-agent:deduper-db-p0-20260929-v2 .
```

La correttezza semantica resta da misurare revisionando tutti i block/partial e
un campione di pass. La pipeline ora completa la fase oppure conserva il lavoro
e ne espone il motivo tecnico; i test offline non attestano la qualità del modello.
