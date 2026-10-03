# Routing automatico GLM 5.3 Flash per nuove prove deduper

Aggiornamento 30 settembre: le nuove esecuzioni DB usano il contratto deduper
2.1, senza `reason` per pass/block, e `output_cap=16384` per consentire a
`partial` di emettere la ReaderLead completa. L'evaluator resta a 8192.
Immagine separata: `lailaps-pentest-agent:deduper-output-p0-20260930-v1`.
Gli execution ID e i comandi del 29 settembre sotto restano storici e
mantengono immagine, cap e contratto persistiti; non riusarli per provare
il nuovo cap.

Le nuove esecuzioni `benchmark:deduper` usano `provider=auto` quando è indicato
solo `--deduper-points`. Il runner omette `provider.only` e
`allow_fallbacks=false` dalla richiesta: OpenRouter sceglie tra provider dello
stesso `z-ai/glm-5.3-flash` e può usare fallback. `--deduper-provider=InferenceNet`
mantiene il comportamento precedente. Il modello Reader non cambia. Il file
`plans/deduper-role-config.example.json` applica auto solo al deduper;
l'evaluator nell'esempio resta InferenceNet.

Il nuovo default non modifica una run attiva o un resume storico. Provider,
modello, schema e configurazione sono nel fingerprint e nel ledger; cambiare
provider richiede **nuovo execution ID e nuovo envelope esplicito**. La run
YesWiki `deduper-01m3pwn3k2nbnhasqmnf55bnyj` resta su InferenceNet e il suo
circuito/contabilità restano invariati. Nessun verdetto viene riutilizzato come
cache tra i due esperimenti. Le risposte continuano a registrare provider
richiesto e provider osservato, quando OpenRouter lo restituisce.

La prompt cache presso un provider non si trasferisce automaticamente a un altro
endpoint. OpenRouter può applicare sticky routing dopo un cache hit, ma le
decisioni del deduper hanno input iniziali diversi; gli hit effettivi sono
misurati da `cached_input_tokens`. La response cache esplicita OpenRouter non è
abilitata. Un miglioramento dei 429 va misurato insieme a provider osservati,
cache hit, costo e qualità dei verdetti.

Per auto, la riserva USD a cache zero e la stima della run usano il prezzo
InferenceNet gia' nel catalogo ($0,045/M input, $0,01/M cache read,
$0,14/M output) come riferimento approssimativo. Non manteniamo un listino per
ogni provider. Alla risposta `usage.cost`, se presente, sostituisce la stima e
viene registrato come osservato; altrimenti i token osservati sono stimati con
il prezzo di riferimento e `usd_complete=false`. Se la usage manca o e' parziale,
la parte non osservata resta come riserva sconosciuta. Un altro provider puo'
costare diversamente; la stima non e' un cap USD. Gli EP mantengono i pesi del
modello precedente per confrontabilita' del budget. `usd_observed`,
`usd_estimated`, `unknown_reserved_usd` e `usd_complete` distinguono costo
osservato, stima e impegno incerto.

Nuova immagine: `lailaps-pentest-agent:glm-auto-p0-20260929-v2`
(`sha256:fce443527e3d4469187a17f26b8011f229c2d8fe14f20a3a05e9806cf717b318`).
Nessuna inferenza paid è eseguita per prepararla. Verificati 75 test Python
mirati e 9 test Pest / 75 asserzioni; lo smoke container con rete disabilitata
e il preflight DB hanno completato senza inferenza.

## Verifica offline e comandi paid separati

Il preflight DB del 29 settembre su YesWiki ha creato
`deduper-01m3q4ka1x3h0mgza2s26gb555`: 57 lead, picco input stimato 36.311
token (<64.000), 0 richieste e 0 USD sostenuti. La configurazione persistita
contiene `z-ai/glm-5.3-flash`, `provider=auto`, 250.000 EP. La simulazione
conservativa all-pass calcola 325.715,8 EP e circa $0,11687 al prezzo di
riferimento. Il budget esplicito puo' quindi sospendere il replay se i consumi
effettivi lo raggiungono; il preflight non e' un giudizio semantico. Il vecchio
execution ID con InferenceNet resta sospeso sul proprio circuito, invariato.

Dalla root del repository, ripetere il preflight offline dello stesso corpus:

```powershell
php artisan benchmark:deduper yeswiki yeswiki-reader-global-20260929-083528 --dedup-run=deduper-01m3q4ka1x3h0mgza2s26gb555 --preflight-only
```

Il seguente smoke paid e' **da lanciare dall'utente**. Usa una nuova directory
e un envelope di 50.000 EP per ruolo, deduper auto ed evaluator InferenceNet;
prova pass, block, partial, recupero originali e lettura del blob sorgente senza
nuova discovery. Lo smoke e' sintetico e non garantisce i verdetti attesi.

```powershell
pwsh -NoProfile -File plans/testing-reader-20260929/Recover-ReaderArtifacts.ps1 -Action Smoke -Target yeswiki -Image lailaps-pentest-agent:glm-auto-p0-20260929-v2 -SmokeDeduperProvider auto -RecoveryRoot storage/app/reader-evaluation/glm-auto-smoke-20260929
```

Solo dopo `technical_complete=true`, richieste strutturate valide e nessuna
sequenza di fallimenti nel suo `smoke-result.json`, avviare la deduplica DB
separata, sempre senza Reader:

```powershell
php artisan benchmark:deduper yeswiki yeswiki-reader-global-20260929-083528 --dedup-run=deduper-01m3q4ka1x3h0mgza2s26gb555
```

Se la fase si sospende per un errore tecnico, riprendere lo stesso ID e budget:

```powershell
php artisan benchmark:deduper yeswiki yeswiki-reader-global-20260929-083528 --dedup-run=deduper-01m3q4ka1x3h0mgza2s26gb555 --resume-failed
```

Confrontare `db-summary.json`, `phase-status.json` e gli attempt in `dedup.json`:
429 per richiesta, `provider_observed`, `cached_input_tokens`, retry, usage e
`usd_source`. Il cambio del provider richiesto invalida il riuso dei verdetti
del vecchio esperimento; non invalida necessariamente la prompt cache dentro
un provider, ma il cambio di endpoint puo' perdere gli hit caldi. La cache si
misura dai token cached osservati. Nessun paid test e' stato lanciato nella
preparazione.
