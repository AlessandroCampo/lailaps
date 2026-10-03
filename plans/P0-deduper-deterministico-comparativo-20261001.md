# P0 — deduper deterministico di default e confronto con il deduper LLM

Stato: implementazione P0 offline completata il 1 ottobre 2026. Il replay H0 sulle 144 lead è stato eseguito senza provider; il template H1 è pronto per la revisione manuale. La prova N con Reader e deduper LLM resta una procedura manuale a pagamento descritta sotto, non eseguita automaticamente. I comandi indicati come NUOVO sono ora disponibili.

## Obiettivo e confini

Rendere deterministica la deduplica ordinaria delle ReaderLead, conservando il deduper LLM come modalità esplicita e riferimento comparativo. Preferire un duplicato passato al Confirmer a una lead distinta soppressa. Conservare tutti gli originali e la provenienza; niente riscrittura semantica, merge di payload o residual nel deterministico.

Baseline Cacti: parent `cacti-reader-global-20260929-084906`, 144 lead; deduper LLM completato `deduper-01m3v0vthfg1je0jmg4jay4h2a`, contratto 3.0.2, 106 prodotti, 648.272,14 EP osservati, 16.747,6 EP riservati per usage ignota, 2.383,958 secondi cumulativi delle decisioni. Il milione di EP era l'envelope, non il consumo. Le 38 riduzioni nominali includono merge: non equivalgono a 38 conferme certamente risparmiate. Cinque merge sono già da rivedere.

P0 comprende contratto Reader, normalizzatore condiviso, default di pipeline, comando isolato, confronto offline e una procedura manuale limitata per verificare la produzione nativa del DTO. Non comprende nuove discovery globali ripetute, embeddings, AST multilinguaggio, fuzzy matching narrativo, paginazione LLM o tuning automatico.

## 1. Contratto Reader e identità interna

Oggi `ReaderLead` espone già `primary_file`, `primary_line`, `owasp_category` e `suspicious_operation`. Riutilizzarli. In accordo con AGENTS.md, il modello continua a emettere campi piatti; il DTO annidato è costruito dall'orchestratore, non richiesto al modello.

Nuovi campi piatti, tolleranti e retrocompatibili:

```json
{
  "weakness_kind": "sql_injection",
  "primary_file": "api.php",
  "primary_line": 10,
  "primary_end_line": 30,
  "primary_symbol": "update_order",
  "input_key": "order_id"
}
```

- `weakness_kind`: piccola tassonomia versionata: `sql_injection`, `command_injection`, `code_injection`, `xss`, `path_traversal`, `ssrf`, `xxe`, `unsafe_deserialization`, `csrf`, `broken_access_control`, `auth_bypass`, `other`, `unknown`. `other`/`unknown` non consentono soppressione per anchor. OWASP resta metadata distinto: A03/injection non identifica SQLi o XSS.
- L'anchor è l'operazione vulnerabile, il sink o il controllo mancante: il range minimo utile, non l'intera funzione né il percorso source-to-sink. Deve essere contenuto in un riferimento autorevole già letto, nello stesso snapshot.
- `primary_symbol`: funzione/metodo qualificato, se noto. `input_key`: parametro/campo coinvolto oppure, per problemi senza input puntuale, azione/risorsa specifica come `delete_tree:tree_id`. Conservare identificatori case-sensitive, senza deduzioni linguistiche.
- Campi mancanti o invalidi non rendono invalida una lead altrimenti valida e non causano retry LLM: identità parziale e pass prudente. Non inventare `end_line` dalla fine di uno snippet ampio.
- Categoria, simbolo e subject sono dichiarazioni Reader: validare sintassi e riscontri disponibili, senza presentarli come semantica verificata dal compilatore.

L'orchestratore produce `lead_identity` versione 1: project, snapshot, weakness_kind, main_anchor(file/start/end/symbol), input_key, origin (`native`, `legacy_explicit`, `manual_overlay`, `missing`), validità e motivazione. Snapshot, ID, hash e source references provengono da stato autorevole. I percorsi sono relativi al repository, separatori normalizzati e case preservato; rifiutare escape fuori sorgente.

Separare ID stabile della lead, digest del payload originale e fingerprint di confronto: il fingerprint non sostituisce l'ID, e modificarlo non modifica un originale. Persistenza DB/file e handoff devono conservare sia i campi sia la provenienza del DTO.

## 2. Regole del motore deterministico

Un'unica implementazione Python riusata da runtime e benchmark, senza secondo algoritmo PHP. Ordine congelato, prima lead valida come rappresentante stabile.

1. Stesso ID già acquisito: riuso idempotente, nessun nuovo prodotto.
2. Duplicato esatto: stesso progetto/snapshot e digest uguale del contenuto semantico completo, con evidenze normalizzate. Escludere soltanto ID locali e metadata di trasporto/provenienza; non eliminare parametri, unknowns, precondizioni, letterali o evidenze distintive. Titolo o hypothesis uguali da soli non bastano. Deduplicare whitespace soltanto nella narrativa, mai dentro codice/letterali. Specificare il digest in fixture stabili.
3. Duplicato per anchor: stesso progetto/snapshot, file e weakness_kind specifica; entrambi i range validi, lunghi al massimo 40 righe; IoU degli intervalli inclusivi >= 0,80 e differenza di inizio e fine <= 5 righe. Richiedere anche lo stesso `input_key` non vuoto: è un vincolo positivo contro i sink condivisi. Simboli differenti, oppure simbolo disponibile soltanto da un lato, impediscono questo match. Due simboli assenti sono ammessi se il subject coincide.
4. Ogni altra situazione: pass con reason code. In particolare categoria generica, identità incompleta, subject sconosciuto, range troppo ampio o evidenze aggiuntive potenzialmente distintive espresse dai campi strutturati non autorizzano soppressione approssimata.

Questa versione restringe deliberatamente l'idea della sola vicinanza: `SQLi api.php:10–30` e `SQLi api.php:10–35` sono duplicati probabili se il subject coincide e non vi sono conflitti; in assenza di subject passano. Stessa posizione con `order_id` e `customer_id`, XSS invece di SQLi, oppure range 121–131: pass. Soglie iniziali, non calibrate sui verdetti LLM.

Confrontare sempre col rappresentante: niente union-find transitivo A≈B≈C se A non corrisponde a C. Ogni membro appartiene a un solo gruppo deterministico. Nessun trasferimento silenzioso di una lead già inviata al Confirmer né riapertura automatica di prodotti chiusi. Nuove lead riconosciute come duplicate possono essere collegate al rappresentante già dispatchato, mantenendo accessibili gli originali.

Output: `pass` oppure `block` con `duplicate_of`, reason (`exact_content`, `same_anchor_subject`, `missing_identity`, `distinct_subject`, ecc.), campi confrontati e regole/versioni. `block` qui significa duplicato per una regola, non finding invalido. Gruppi e originali restano consultabili; il payload del rappresentante non viene riscritto.

Errore di DTO su una lead: pass e warning locale. Errori globali di integrità del corpus/snapshot: errore esplicito, senza fingere completamento. Il motore non istanzia client provider, non ha budget LLM e non effettua fallback inferenziali.

## 3. Default e integrazione

- Introdurre `deduper_mode=deterministic|llm|off`, default `deterministic`, nei settings Python e negli entry point che gestiscono deduplica Reader, incluso `benchmark:reader-global --deduper-mode=...`.
- Il default vale per le ReaderLead, anche tra child della stessa run globale: indice condiviso del coordinatore, non un indice indipendente per ogni child. Recon/task/enrichment non sono finding e non passano nel nuovo matcher; mantenere il loro flusso ordinario.
- Bypassare il `LeadNoveltyReviewer` inferenziale quando la deduplica lead è deterministic/off. Il normale Exploration Reviewer conserva il suo ruolo. Non rimuovere il codice LLM né alterare le sue regole semantiche.
- `llm` resta selezionabile esplicitamente con modello/provider/envelope. Le esecuzioni già persistite riprendono sempre il motore/versione originali: mai reinterpretare una vecchia run LLM come deterministica. Configurazioni legacy che selezionano esplicitamente il deduper LLM restano supportate con modalità risolta e registrata; combinazioni contraddittorie sono errori prima dell'inferenza.
- Nel benchmark di generazione Reader usare `off`: salvare tutte le lead raw senza novelty LLM o deduplica preventiva. Confrontare sempre questi originali, non le canoniche di un altro motore.
- Riutilizzare `DeduperRun`, `DeduperDecision` e proiezioni ReaderLead esistenti; aggiungere discriminante engine/contract e gate Confirmer coerente. Niente migrazione se payload/metrics esistenti bastano. Tenere canoniche e decisioni scoped per execution.
- Aggiornare `ARCHITECTURE.md` solo durante l'implementazione, per descrivere il nuovo default e il ruolo opzionale LLM.

Punti principali: `models.py`, normalizzazione/prompt Reader e novelty boundary in `triple_agent.py`, `ledger.py`, settings/CLI; nuovo modulo deterministico piccolo; `BenchmarkReaderGlobal`, `BenchmarkDeduperArtifacts`, gate Confirmer e nuovo comando. Lasciare `deduper.py` LLM operativo e limitare gli adattamenti ai contratti condivisi.

## 4. Comandi e artefatti da implementare

**NUOVO comando isolato**:

```text
benchmark:deduper-deterministic {target-id} {reader-run-id}
  --reference-dedup-run=   # confronto read-only con un LLM esistente sullo stesso corpus
  --identity-overlay=     # annotazioni storiche separate, validate e legate agli hash
  --prepare-identities=   # scrive template overlay e termina senza deduplicare
  --output=              # directory nuova obbligatoria per gli artefatti di benchmark
  --image=               # runtime esplicito per il runner containerizzato
```

Senza reference, esegue soltanto il deterministico. Con reference, carica il corpus congelato di quella run, verifica tutti gli originali, riusa il medesimo ordine e non avvia il LLM. `--prepare-identities` può usare la reference per lo stesso congelamento; non è compatibile con `--identity-overlay`. Mai sovrascrivere un output già esistente.

Artefatti: manifest con hash/ordine/versioni/image digest; decisioni/gruppi; identità e provenienza; `summary.json`; `comparison.json` e `comparison.md` quando richiesta; `review-queue.json`. Registrare wall-clock end-to-end e tempo del solo algoritmo separatamente, conteggi raw/promossi/soppressi, copertura DTO, zero provider requests/token/EP. Non includere il costo Reader nei costi deduper.

Il comando attuale `benchmark:deduper` resta il comando isolato **LLM**, così non cambia significato agli script o ai resume esistenti. Aggiungere `--result-json=PATH` al suo riepilogo e a `benchmark:reader-global`; quest'ultimo restituisce `run_id`, il primo `execution_id`, insieme a stato e path, anche quando incompleti. Aggiungere `--image` a reader-global per scegliere l'immagine della singola prova senza cambiare il tag dev/config globale. Queste piccole opzioni rendono i comandi sotto riproducibili senza cercare "l'ultima run".

## 5. Lead storiche senza DTO: due prove distinte

### H0 — compatibilità reale, senza arricchimento

Usare le 144 lead originali congelate e il risultato LLM già completato. Nessuna nuova discovery né inferenza. Ricostruire solo dati espliciti: file/riga dichiarati e scope autorevole. Non convertire una categoria OWASP in weakness_kind specifica; non interpretare narrativa con regex per inventare subject/range. Dove mancano dati, pass salvo duplicato esatto.

Questo misura il comportamento reale sui dati legacy, non la capacità teorica del nuovo DTO. Riportare quante lead sono non confrontabili e per quale campo mancante.

### H1 — stesso corpus, identità annotate separatamente

`--prepare-identities` esporta un template per tutte le 144 lead: ID originale, hash immutabile, campi espliciti precompilati, campi mancanti null, riferimenti alle evidenze, `review_status=unreviewed`. Una persona o una revisione offline dei payload/sorgenti completa i campi supportati dalle evidenze; annotazione cieca rispetto a verdetti/gruppi LLM. Ambiguità rimangono null. Non richiamare il Reader e non riscrivere il DB storico.

Ogni riga porta origine `manual_overlay`, annotatore, motivazione/riferimento e stato reviewed. L'overlay deve coprire tutti gli ID con hash corretti; reviewed può dichiarare esplicitamente dati insufficienti. Rifiutare righe estranee/duplicate, hash cambiati, anchor fuori evidenza e righe non revisionate. Conservare il tempo di annotazione come costo sperimentale separato, non spacciarlo per costo nullo end-to-end.

H1 confronta il deterministico con DTO ricostruito contro il vecchio LLM senza DTO: dichiarare questa asimmetria. H0 e H1 condividono esattamente ID, narrativa, evidenze e ordine; cambia soltanto l'overlay. Non basta assegnare DTO in funzione dei duplicati desiderati. Salvare l'hash dell'overlay prima di osservare il confronto e non calibrare soglie retroattivamente sullo stesso corpus.

## 6. Lead nuove: verifica nativa e confronto controllato

Una sola generazione Reader limitata all'area Cacti `area-assignment-13`, Golden Recon `01M3NYRPZBBXE8AQM1J9XZR3TZ`, snapshot `6482af547c204199e829b7a0df0b7a13db3e0a58`. Sono valori verificati nella run storica. Concorrenza 1, enrichment differiti, deduplica off, budget Reader+Reviewer 100.000 EP, timeout 900 s. Non garantisce un numero minimo di lead: se il campione è vuoto o troppo piccolo, registrare la limitazione senza ripetere automaticamente.

La prova verifica che il Reader produca naturalmente i nuovi campi e che persistano fino al benchmark. Non promette di replicare le vecchie sette lead di quell'area: una nuova discovery cambia il corpus. Rifiutare il confronto diretto tra conteggi della nuova discovery e le vecchie 144 come misura di accuratezza del deduper.

Sul nuovo corpus congelato eseguire una sola run LLM isolata (envelope 100.000 EP), poi il deterministico usando quel manifest come reference. Entrambi ricevono le stesse lead raw native, nello stesso ordine e con gli stessi nuovi campi disponibili, senza CVE/label o verdetti dell'altro motore. Niente regenerazione Reader per ogni motore.

Come controllo di compatibilità del DTO, nei test offline proiettare lo stesso corpus rimuovendo soltanto i nuovi campi e verificare il fallback legacy; non serve una seconda run Reader o LLM. Il test 100+ resta H0/H1 sul corpus storico. Repetitions native aggiuntive sono P1, non necessarie per consegnare P0.

Se il LLM termina incompleto, conservarlo come riferimento parziale: denominatori espliciti e nessun verdetto tecnico trattato come pass. Il completamento LLM non deve bloccare la run deterministica né il suo default. Niente resume/rilancio automatico in questo piano di test.

## 7. Comparatore: cosa misura e cosa non può concludere

- Validare progetto/snapshot, insieme e hash degli originali e ordine identico. Rifiutare reference estranee. Registrare separatamente hash base e hash identità/overlay.
- LLM è una baseline comparativa, non ground truth. Pass significa soltanto non soppresso, non vulnerabilità confermata. I cinque merge storici dubbi entrano esplicitamente nella coda di revisione.
- Confrontare coppie/gruppi di originali, oltre ai conteggi dei prodotti: block e merge LLM possono riferirsi a canoniche derivate. Risolvere la lineage conservando il tipo di relazione.
- Un residual LLM non significa equivalenza piena con tutte le sue source lead. Non trasformare automaticamente `original_ids` di residual o lineage mista in un cluster di duplicati: etichettare queste relazioni come partial/non direttamente confrontabili. Evidenziare i 24 residual separatamente.
- Report: duplicati deterministici sostenuti dal LLM; duplicati deterministici contestati/non giudicabili; relazioni LLM lasciate passare; casi incerti; costi e tempi. Non chiamare precision/recall i soli numeri di accordo LLM.
- Revisionare tutti i block deterministici, in particolare i disaccordi e gli anchor con più input. Etichette manuali `same_issue`, `distinct_issue`, `uncertain`, con motivazione. Per stimare i duplicati mancati, includere un campione fisso di pass e le relazioni LLM non riprese; non generalizzare dal solo campione.
- Stima ROI: costo deduper + costo downstream effettivamente misurato. Nel P0 che non esegue Confirmer, dichiarare soltanto chiamate potenzialmente evitate e soglie di pareggio; non dichiarare minuti Confirmer risparmiati senza misura. Wall-clock seriale, tempo cumulativo provider e annotazione manuale sono colonne diverse.

## 8. Test offline e criteri di accettazione

Test mirati, provider stub o vietato; nessun agent loop nei test automatici:

- IoU inclusiva e soglie: 10–30/10–35; range disgiunti/vicini; categorie diverse; input distinti/ignoti; simboli incompatibili; anchor ampio, mancante o fuori evidenza; file case-sensitive; snapshot diverso.
- Idempotenza, duplicati esatti con ID diversi, evidenze/parametri distinti non eliminati dal digest, catena A≈B≈C senza accorpamento transitivo, rappresentanti già dispatchati.
- DTO nativo, legacy e overlay; originali invariati, conflitti/hash invalidi, annotazioni incomplete; errore su una lead non arresta il corpus.
- Integrazione default runtime/coordinatore globale: zero chiamate deduper/novelty LLM; originale conservato anche se soppresso; Confirmer seleziona solo le canoniche della execution scelta. Modalità off conserva tutto. Modalità LLM e resume storico restano compatibili.
- Comparatore con block/merge/residual e riferimenti derivati, reference incompleta, corpus diverso respinto. Nessuna equiparazione automatica tra residual e duplicato pieno.

File test nuovi previsti: `tests/test_deterministic_deduper.py`, `tests/test_deduper_comparison.py` nel progetto Python; `tests/Feature/Pentest/BenchmarkDeterministicDeduperCommandTest.php`. Estendere i test esistenti del comando globale, DTO Reader e gate Confirmer dove necessario.

Accettazione P0: 144/144 lead trattate, nessun errore bloccante per DTO incompleto, zero provider requests/EP del deterministico, output stabile per manifest/regole uguali. Target misurato: algoritmo < 1 secondo e comando completo < 30 secondi sul corpus locale, riportando eventuale startup container separatamente. Nessun block confermato errato nella revisione completa dei block di questo corpus; se emerge, restringere la regola o disabilitare la soppressione per anchor lasciando l'exact-match. Non interpretare zero errori osservati come garanzia universale. Nessuna percentuale minima di deduplica imposta a costo di falsi accorpamenti.

## 9. Comandi PowerShell di collaudo, dopo l'implementazione

Tutti dalla root del repository. Le nuove opzioni/comandi/file di test devono essere consegnati con i nomi qui indicati e verificati con `--help` durante l'implementazione. Non eseguire queste sequenze oggi: il piano non le ha implementate. I blocchi con provider sono separati per evitare esecuzioni accidentali durante il replay offline.

### Preparazione e test senza inferenza

```powershell
Set-Location 'C:\Users\Alessandro\Desktop\latest-projects\lailaps'
$ErrorActionPreference = 'Stop'
function Invoke-Checked { param([string]$Exe, [string[]]$Arguments)
    & $Exe @Arguments
    if ($LASTEXITCODE -ne 0) { throw "Exit code ${LASTEXITCODE}: $Exe" }
}
$image = 'lailaps-pentest-agent:deduper-deterministic-p0-20261001-v1'
$readerOld = 'cacti-reader-global-20260929-084906'
$llmOld = 'deduper-01m3v0vthfg1je0jmg4jay4h2a'
$comparisonRoot = Join-Path (Get-Location) ('storage/app/deduper-comparison/p0-' + (Get-Date -Format 'yyyyMMdd-HHmmss-fff'))
New-Item -ItemType Directory -Path $comparisonRoot | Out-Null

Invoke-Checked docker @('build', '-f', 'agent/pentest-agent/Dockerfile.recovery', '-t', $image, 'agent/pentest-agent')
Invoke-Checked uv @('run', '--project', 'agent/pentest-agent', 'python', '-m', 'pytest', 'agent/pentest-agent/tests/test_deterministic_deduper.py', 'agent/pentest-agent/tests/test_deduper_comparison.py', 'agent/pentest-agent/tests/test_deduper_details_p0.py', '-q')
Invoke-Checked php @('vendor/bin/pest', 'tests/Feature/Pentest/BenchmarkDeterministicDeduperCommandTest.php', 'tests/Feature/Pentest/BenchmarkDeduperCommandTest.php', 'tests/Feature/Pentest/BenchmarkReaderGlobalCommandTest.php', 'tests/Unit/Pentest/SemanticNormalizationGateTest.php')
Invoke-Checked php @('artisan', 'benchmark:deduper-deterministic', '--help')
```

Il Dockerfile recovery esistente copia il codice sul runtime dev: verificare base e dipendenze compatibili e registrare il digest; non sostituisce automaticamente il tag dev. Il comando pytest deve usare l'ambiente dev già predisposto. Usare Pest direttamente evita di confondere il precedente exit code anomalo del wrapper `artisan test` con un esito positivo; ogni fallimento va comunque esaminato.

### H0 e preparazione H1 — NUOVO, zero inferenza

```powershell
Invoke-Checked php @('artisan', 'benchmark:deduper-deterministic', 'cacti', $readerOld, "--reference-dedup-run=$llmOld", "--image=$image", "--output=$comparisonRoot/H0-legacy")
Invoke-Checked php @('artisan', 'benchmark:deduper-deterministic', 'cacti', $readerOld, "--reference-dedup-run=$llmOld", "--prepare-identities=$comparisonRoot/identities.reviewed.json", "--image=$image", "--output=$comparisonRoot/H1-prepare")
```

Ora compilare e revisionare `identities.reviewed.json` secondo la sezione 5, senza consultare i verdetti LLM. Non eseguire H1 con il template incompleto. Annotazioni irrisolvibili restano esplicitamente missing, non vengono indovinate.

```powershell
Invoke-Checked php @('artisan', 'benchmark:deduper-deterministic', 'cacti', $readerOld, "--reference-dedup-run=$llmOld", "--identity-overlay=$comparisonRoot/identities.reviewed.json", "--image=$image", "--output=$comparisonRoot/H1-annotated")
```

### N — una generazione Reader nativa limitata, con costo provider

Questo blocco è un collaudo manuale distinto: una sola area, nessuna repetition automatica. Nuove opzioni: `--deduper-mode`, `--image`, `--result-json`. Tutte le altre sono già presenti nel comando globale verificato.

```powershell
$source = 'C:/Users/Alessandro/Documents/Codex/2026-09-28/ok-vorrei-fare-la-seguente-cosa/outputs/reader-round/sources/cacti'
$snapshot = '6482af547c204199e829b7a0df0b7a13db3e0a58'
$sourceHead = & git -C $source rev-parse HEAD
if ($LASTEXITCODE -ne 0 -or $sourceHead.Trim() -ne $snapshot) { throw 'Snapshot sorgente incompatibile' }
$sourceChanges = & git -C $source status --porcelain --untracked-files=all
if ($LASTEXITCODE -ne 0 -or $sourceChanges) { throw 'La sorgente deve corrispondere allo snapshot pulito' }
& php artisan benchmark:reader-global cacti "--path=$source" --recon-artifact=01M3NYRPZBBXE8AQM1J9XZR3TZ --area=area-assignment-13 --defer-enrichments --concurrency=1 --assignment-points=100000 --timeout=900 --reader-model=xiaomi/mimo-v2.6-pro --reader-provider=Xiaomi --reader-checkpoint-strategy=reader_checkpoint --deduper-mode=off --follow-slot=0 "--image=$image" "--result-json=$comparisonRoot/native-reader.json"
$readerExit = $LASTEXITCODE
if ($readerExit -notin @(0, 1)) { throw "Reader exit code inatteso: $readerExit" }
if (-not (Test-Path "$comparisonRoot/native-reader.json")) { throw 'Reader result-json mancante' }
$nativeReader = (Get-Content -Raw "$comparisonRoot/native-reader.json" | ConvertFrom-Json).run_id
if (-not $nativeReader) { throw 'Reader run_id mancante' }
```

Se il Reader termina per budget/timeout, lo script si ferma e conserva il riepilogo. Esaminare il parent terminale e le lead raw disponibili: possono essere utilizzate come campione parziale esplicito, senza rilanciare discovery. Non promuovere questa condizione a successo globale. Se il corpus è vuoto, non lanciare il deduper LLM.

### N — confronto LLM/deterministico sul medesimo nuovo corpus

Una sola inferenza LLM nuova sul piccolo corpus nativo; non ripetere il replay LLM delle 144 lead storiche. `--result-json` sul comando LLM è NUOVO, il resto della sua sintassi esiste già.

```powershell
Invoke-Checked php @('artisan', 'benchmark:deduper', 'cacti', $nativeReader, '--deduper-model=z-ai/glm-5.3-flash', '--deduper-provider=auto', '--deduper-points=100000', '--preflight-only', "--image=$image", "--result-json=$comparisonRoot/native-llm-preflight.json")
$nativeLlm = (Get-Content -Raw "$comparisonRoot/native-llm-preflight.json" | ConvertFrom-Json).execution_id
if (-not $nativeLlm) { throw 'Deduper execution_id mancante' }
& php artisan benchmark:deduper cacti $nativeReader "--dedup-run=$nativeLlm" "--image=$image" "--result-json=$comparisonRoot/native-llm-result.json"
$llmExit = $LASTEXITCODE
if ($llmExit -notin @(0, 2)) { throw "Deduper LLM exit code inatteso: $llmExit" }
Invoke-Checked php @('artisan', 'benchmark:deduper-deterministic', 'cacti', $nativeReader, "--reference-dedup-run=$nativeLlm", "--image=$image", "--output=$comparisonRoot/N-native")
```

Se il LLM si ferma incompleto, dopo l'ispezione si può eseguire soltanto l'ultima riga: il comparatore deve supportare reference parziale senza inferenza aggiuntiva. Non usare `--resume-failed` come parte automatica del confronto. Il preflight non costa inferenza e il suo execution ID viene riutilizzato: non creare una seconda run paid con un nuovo envelope.

Per usare il nuovo default in una normale run futura, omettere `--deduper-mode=off` oppure specificare `--deduper-mode=deterministic`. La prova nativa sopra usa off intenzionalmente per preservare il corpus raw. Il default stesso si verifica nei test offline del runtime e del coordinatore, senza una seconda discovery paid.

## 10. Ordine di consegna e P1

1. Campi piatti, DTO interno e normalizzazione tollerante, fixture legacy/native.
2. Motore deterministico e integrazione default/novelty boundary; preservare esplicitamente LLM e scope lead-only.
3. Comando isolato, overlay e comparatore; output JSON dei comandi necessari.
4. Test mirati e H0 offline; preparare H1 e revisionare le identità prima del replay annotato. Aggiornare architettura e documentare risultati/limiti.
5. Consegnare i comandi manuali N; eseguirli soltanto quando richiesto, senza trasformare il collaudo in tre run complete dello stesso set.

P1: repetitions reali su un'area con corpus aggregato congelato; confronto downstream Confirmer per ROI misurato; eventuale matching su hash del singolo sink o AST solo se gli errori osservati lo giustificano. Non implementare questi ampliamenti nel P0.
