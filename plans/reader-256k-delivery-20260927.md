# Consegna Reader 256k â€” 27 settembre 2026

## Patch

- Checkpoint periodico e pressione di contesto condividono la decisione: la memoria appena prodotta viene applicata alla stessa history, con compaction immediata quando anche il prossimo step raggiunge il trigger. La compaction azzera la scadenza periodica.
- Reader operativo e checkpoint hanno lo stesso system prompt congelato, toolset ordinato e output schema. Il checkpoint blocca l'esecuzione dei tool nell'harness. I tool Reader usano la barriera sequenziale nativa Pydantic AI, limitando il fan-out dei thread nei grandi batch.
- Catalogo unico aggiornato con prezzi/provider/fonti/date, voci simulate riconoscibili e cognitive window generale 128.000. MiMo e DS 4.1 richiedono 256.000; soft 192.000 e hard 230.400 quando non clamped. Soglie 40k/48k rimosse dal percorso Reader.
- La capacitÃ  fisica sottrae overhead, output e margine, rispetta cap espliciti e registra il clamp. Il benchmark passa cap adeguati; gli override ambientali restano visibili nella configurazione effettiva.
- Il benchmark globale self-checkpoint acquisisce lead ed enrichment senza Reviewer online. Gli enrichment restano pending per valutazione offline; con defer-enrichments non generano assignment aggiuntivi.
- Scorecard child/parent: produzione per fase, USD reali completi o null, stime e loro completezza separate, EP, token/cache, tool/errori/pendenti, retry, durata, checkpoint/compaction e distribuzione input. Rapporti parent calcolati dalle somme. QualitÃ  offline pending/null.
- Gli esiti tool usano stati espliciti, inclusi errori di lettura/ricerca/graph; testo sorgente contenente error e output vuoti validi non contano come fallimenti. Output terminali esclusi dal denominatore.
- Eventi di consumo e acquisizione, offset child, configurazione effettiva, catalogo e fingerprint runtime persistiti. ARCHITECTURE.md aggiornata.

## Verifiche offline

Python: 162 test mirati superati, piÃ¹ la regressione lettura mancante/output vuoto/testo error. Coperti contratti serializzati e prefisso dei messaggi, batch di 128 tool call con una sola esecuzione contemporanea, zero Reviewer/dispatch, acquisizione terminale a budget esaurito, checkpoint accorpati, finestre/default/override/clamp e catalogo/costi.

PHP: 6 test, 126 asserzioni superati su BenchmarkReaderGlobalCommandTest, BenchmarkReconImportCommandTest e BenchmarkReconCommandTest. Coperti fan-out, selezione/defer, propagazione strategia e aggregazioni con costi mancanti, zero lead, errori/retry e qualitÃ  pending.

Comando Python dalla directory agent/pentest-agent:

```powershell
.venv/Scripts/python.exe -m pytest tests/test_recon_reader_assignments.py tests/test_context_budgeting.py tests/test_economic_budget.py tests/test_benchmark_v0_metrics.py tests/test_triple_agent.py tests/test_runtime_tools.py tests/test_data_flow.py tests/test_codebase_memory.py -q -p no:cacheprovider -k '(reader or context or price or economic or benchmark or checkpoint or read_file or search_source or list_dir or source_ref or graph or data_flow) and not reader_gets_graph_tools_only_for_ready_runtime'
```

Comando PHP dalla root:

```powershell
php vendor/bin/pest tests/Feature/Pentest/BenchmarkReaderGlobalCommandTest.php tests/Feature/Pentest/BenchmarkReconImportCommandTest.php tests/Unit/Pentest/BenchmarkReconCommandTest.php --compact
```

Il test preesistente test_reader_gets_graph_tools_only_for_ready_runtime ha aspettative non aggiornate sui tool giÃ  presenti in HEAD, tra cui inspect_tool_output per Reader e tool di history/navigation per Confirmer. Ãˆ stato escluso dalla verifica mirata, senza modificare il suo file. Warning PHP preesistente: gd caricato due volte. Nessun lint globale o loop provider Ã¨ stato eseguito.

## Confronto preparato, non avviato

Manifest: reader-256k-comparison-20260927.json. Contiene catalogo congelato, hash dei file runtime/lockfile e parametri concreti rilevati. La Golden Recon Ã¨ stata verificata nel database: YesWiki, ID 01M309MYBKSARVPA2HWVTYT450, commit 7325759547611def210a731b103878bd696c419c. Le aree 3/4/5/6 corrispondono ad autenticazione, API, Bazar e rendering.

Due parent da quattro assignment ciascuno, eseguiti **in sequenza**. Parametri comuni: concorrenza 4, 500.000 EP per assignment, timeout 7.200 secondi, self-checkpoint, enrichment differiti, cap fisico operativo 1.040.000 e guard input 900.000. Per entrambi C effettivo 256.000, senza clamp. La rotta Ã¨ fissata e senza fallback: Xiaomi per MiMo, InferenceNet per DS.

Dalla root, per il confronto successivo:

```powershell
$readerComparisonArgs = @('yeswiki', '--recon-artifact=01M309MYBKSARVPA2HWVTYT450', '--area=area-assignment-3', '--area=area-assignment-4', '--area=area-assignment-5', '--area=area-assignment-6', '--concurrency=4', '--assignment-points=500000', '--timeout=7200', '--reader-checkpoint-strategy=reader_checkpoint', '--defer-enrichments', '--operational-context-window=1040000', '--max-prompt-input-tokens=900000', '--follow-slot=1')
php artisan benchmark:reader-global @readerComparisonArgs --reader-model=xiaomi/mimo-v2.6-pro --reader-provider=Xiaomi
# Attendere la fine del primo parent prima del secondo comando.
php artisan benchmark:reader-global @readerComparisonArgs --reader-model=deepseek/deepseek-v4.1-flash --reader-provider=InferenceNet
```

Prima di eseguire, mantenere identici runtime e impostazioni del manifest, confrontare gli hash e verificare l'assenza di altri benchmark che contendano risorse:

```powershell
Get-CimInstance Win32_Process | Where-Object { $_.Name -in @('php.exe','python.exe','uv.exe') -and $_.CommandLine -match 'benchmark:|pentest_agent|reader-only' } | Select-Object Name,ProcessId,CommandLine

docker ps --format '{{.Names}}\t{{.Status}}'
```

Alla verifica di consegna non risultavano processi benchmark PHP/Python attivi; restavano quattro container Cacti in esecuzione. Non sono stati fermati: verificare nuovamente le risorse prima del confronto. Le condizioni possono cambiare dopo questa consegna.

Controllare nei nuovi artifact gli stessi runtime_sha256, parametri effettivi, source snapshot e rotta osservata, C=256.000 e reviewer_requests/enrichment_dispatches=0. Se uno di questi valori differisce, conservare l'artifact e non presentarlo come confronto omogeneo a 256k.

## Lettura delle curve e limiti

Gli EP sono il limite operativo; non equivalgono a USD. Le scorecard contengono economy.usage_events e economy.lead_events per assignment, piÃ¹ gli offset di avvio nel parent. Sommare gli eventi per tempo, collegare le lead ai rispettivi ID e applicare offline deduplica/root cause e match CVE semantici. La produzione utile resta non definita fino a questa valutazione.

Confrontare soltanto costi provider completi, nel tratto comune 0..min(USD effettivamente raggiunti dai due parent), usando gli stessi punti USD. Le lead acquisite appartengono alla curva soltanto dopo l'evento di acquisizione; i costi di un turno vengono contabilizzati alla sua conclusione. Le stime incomplete e i costi mancanti non ricostruiscono una curva reale. Non estrapolare assignment interrotti o attribuire qualitÃ  zero a valutazioni pending.

192k Ã¨ un trigger di history, non un tetto garantito dell'input completo: p95/max richiedono una run reale. Le verifiche offline dimostrano stabilitÃ  del payload e serializzazione del batch, non cache hit del provider, qualitÃ  investigativa o comportamento sotto carico reale. Queste misure restano al confronto successivo. Il documento originale P0-reader-efficienza-e-confronto-modelli-20260927.md non Ã¨ stato modificato.
