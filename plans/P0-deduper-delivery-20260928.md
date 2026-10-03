# Consegna P0 deduper e valutazione semantica — 2026-09-29

Implementazione nel checkout condiviso; baseline precedente conservata in `.p0-baseline.patch` e `.p0-baseline-status.txt`. Nessun commit, push, audit o richiesta provider a pagamento. Review finale dei due gap snapshot/cache chiusa dal coordinatore.

## Requisiti e collegamenti

- Raccolta parent incompleto/child esplicitamente mappati, raw immutabili, metriche deterministiche e contabilità separata: `reader_evaluation.py` (`collect`, `deterministic_metrics`, `scorecard`). Esportazione report, timeline e handoff Confirmer sul set canonico.
- Deduper generico, pass/block minimali e partial sugli schemi esistenti, originali/provenienza, registro persistente, idempotenza/refresh, massimo due richieste e letture artifact senza discovery: `deduper.py`. Runner con budget distinto, limite input comprensivo di schema/history/retrieval, output cap e retry SDK disabilitati.
- Integrazione runtime Recon prima planning, enrichment prima dispatch (differiti esclusi), leads prima Confirmer e self-checkpoint senza vecchio Reviewer: `triple_agent.py`, `deps.py`, `ledger.py`. Pipeline parent: `BenchmarkReaderGlobal.php`; consumo canonici: `BenchmarkConfirmer.php`, `ConfirmerBenchmarkDataset.php`.
- Entrypoint/ruoli espliciti: `cli.py`, `config.py`, `def_model.py`, `BenchmarkReaderEvaluate.php`, `BenchmarkStageProcessRunner.php`.
- Judge del set deduplicato e diagnosi miss, credito soltanto dalle ipotesi Reader originali, qualità distinta dai match e root cause non sempre separabile: `reader_evaluation.py`. Reach osservato distinto dal hit: `BenchmarkEvaluator.php`, `BenchmarkAdjudicator.php`, `BenchmarkResultAggregator.php` (semantica observed-only-4.0; legacy segnalato).
- Snapshot: catalog compatibile col parent prima delle richieste; letture source solo root Git verificata, HEAD uguale commit congelato, working copy pulita. Blob verificato e SHA-256 del file realmente letto. Source non verificabile produce richiesta inconclusive. Cache semantic include versione/prompt/schema/package/config/evidenze effettive/source identity.
- Campione calibrazione storico 13 confronti annotati: `deduper-calibration-20260928.json`; ruoli esempio `deduper-role-config.example.json`. Prompt di produzione generico. Documentazione: `ARCHITECTURE.md`.

## Verifiche offline eseguite

- Nuova suite `test_reader_evaluation.py`: **24 pass**. Ultime verifiche cache/source/CLI: **4 pass**, 20 deselezionati. Cache invariata riusata; prompt/schema/version/snippet cambiati invalidano. Source dirty non riusata, accesso disabilitato; source verificata non crea credito Reader.
- 36 test Recon/Reader; 52 test combinati deduper (versione precedente della suite)/metriche/economic budget; 28 test triple_agent selezionati (203 deselezionati). I conteggi si sovrappongono e non vanno sommati.
- Pest ReaderGlobal: 2 test,96 assert; Contract/ArtifactRegistry/FinalizationRecovery:37 test,397 assert.
- Adapter provider simulato prova schema piatto, provider pin, output cap, SDK retries zero; budget insufficiente/input troppo grande impediscono chiamate. Prove partial source refs/merge/residual e innesti Recon/enrichment/lead incluse nelle suite.
- Sintassi PHP del comando e help Python/Artisan passati. Warning locale gd già caricato preesistente.
- Post-processing reale metrics-only MiMo:3 child recuperati con parent incompleto,34 leads/1 enrichment; output `storage/app/reader-evaluation/p0-mimo-metrics`. Nessuna chiamata provider.

## Comandi (PowerShell, dalla root repository)

```powershell
$env:WORKER_BROWSER_ENABLED='false'
agent/pentest-agent/.venv/Scripts/python.exe -m pytest agent/pentest-agent/tests/test_reader_evaluation.py -q --disable-warnings --maxfail=1
php vendor/bin/pest tests/Feature/Pentest/BenchmarkReaderGlobalCommandTest.php --compact
php vendor/bin/pest tests/Unit/Pentest/BenchmarkContractTest.php tests/Feature/Pentest/BenchmarkStageArtifactRegistryTest.php tests/Feature/Audit/BenchmarkFinalizationRecoveryTest.php --compact
agent/pentest-agent/.venv/Scripts/python.exe -m pentest_agent.cli reader-evaluate --parent storage/app/runs/yeswiki/reader-global/yeswiki-reader-global-20260927-225341/yeswiki-reader-global-20260927-225341-outcome.json --target yeswiki --output storage/app/reader-evaluation/p0-mimo-metrics --mode metrics --fixtures storage/framework/lailaps-reader-global/yeswiki-reader-global-20260927-225341/fixtures --children storage/app/runs/yeswiki/reader-area
php artisan benchmark:reader-evaluate --help
agent/pentest-agent/.venv/Scripts/python.exe -m pentest_agent.cli deduper-calibrate --help
php artisan benchmark:confirmer --help
```

Calibrazione futura (richiede provider configurato; **non eseguita**, consuma budget esplicito dell'esempio; scegliere/verificare modello e provider prima):

```powershell
agent/pentest-agent/.venv/Scripts/python.exe -m pentest_agent.cli deduper-calibrate --sample plans/deduper-calibration-20260928.json --config plans/deduper-role-config.example.json --output storage/app/reader-evaluation/calibration-minimal --variant minimal
agent/pentest-agent/.venv/Scripts/python.exe -m pentest_agent.cli deduper-calibrate --sample plans/deduper-calibration-20260928.json --config plans/deduper-role-config.example.json --output storage/app/reader-evaluation/calibration-targeted --variant targeted
```

Per full/evaluate usare `benchmark:reader-evaluate` con parent/output e modello/provider/budget di entrambi i ruoli espliciti, secondo help; `evaluate --dedup-artifact` riusa il registro. Il catalog viene congelato dal comando PHP. Confirmer consuma `--dedup-artifact` senza modificare gli outcome storici.

## Limiti e stato

P0 collegato e verificato offline. Non sono state eseguite calibrazione LLM, validazione empirica delle soglie né comparazioni semantiche pagate; campione e comandi sono pronti. Le soglie sono iniziali, non validate. Incertezza/timeout/budget conservano output originali e stato pending/inconclusive; fusione con attività già avviata resta pending_integration. USD assente/incompleto resta null: nessun ranking globale o credito trasferito fra modelli. Repository sorgente senza Git/commit verificabile o con modifiche (anche file ignorati) non è leggibile dal judge. Non restano requisiti implementativi P0 dichiarati incompleti; la calibrazione empirica resta deliberatamente non eseguita entro il vincolo offline.
