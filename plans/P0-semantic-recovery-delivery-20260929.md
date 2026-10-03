# Consegna P0: deduper e valutazione semantica recuperabili

Il contratto v2 è implementato nel checkout esistente. Le modifiche preesistenti sono conservate. Non sono state eseguite inferenze paid, nuove discovery, valutazioni semantiche reali o operazioni sui processi attivi. Reader resta `xiaomi/mimo-v2.6-pro`; deduper/evaluator sono configurati `z-ai/glm-5.3-flash`, provider `InferenceNet`, reasoning `low`, senza fallback.

## Esito offline sui corpus congelati

Gli input sono copie dei prodotti già persistiti, ordinate per acquisizione, con originali, transcript, evidenze e costi storici separati. Il congelamento Cacti rappresenta gli artifact pubblicati alle **14:02:16 Europe/Rome del 29 settembre 2026**; non attende né modifica il lavoro in corso.

| Corpus | Lead grezze | Enrichment | Picco input stimato completo | Pacchetti oltre 64k | Esito |
|---|---:|---:|---:|---:|---|
| YesWiki | 57 | 1 | 35.660 | 0 | Preflight completato, exit 0 |
| Cacti | 143 | 17 | 78.957 | 30 | `context_limit`, exit 2 |

La simulazione conserva ogni prodotto come pass e misura la crescita conservativa del registro; **non misura qualità semantica né garantisce che un successivo recupero integrale rientri nel cap**. Anche la seconda richiesta effettua il preflight completo. Cacti è bloccato nel P0: il launcher conserva gli artifact e impedisce il replay paid. Non sono introdotte paginazione, selezione per anchor o variazioni del cap.

Verificati 97 path YesWiki e 121 locator Cacti rispetto ai blob Git, con zero errori; una directory Cacti è esplicitamente esclusa dalle letture di file. Commit: YesWiki `7325759547611def210a731b103878bd696c419c`; Cacti `6482af547c204199e829b7a0df0b7a13db3e0a58`. Il checkout Windows CRLF non viene confrontato byte per byte con i blob LF.

Gli EP Reader osservati sono 1.514.943,432 per YesWiki e 5.755.363,3056 per Cacti. Le 57 lead YesWiki rimangono 57: l'enrichment è contabilizzato separatamente.

I budget di recupero espliciti sono quelli del round: 250.000 EP per ruolo YesWiki, 1.000.000 per ruolo Cacti. Non sostituiscono né azzerano la contabilità storica. La somma conservativa delle riserve all-pass, cache zero e output massimo, è rispettivamente 320.464,4 EP / USD stimati 0,11568593 e 1.486.166,8 EP / USD stimati 0,45734945. È una proiezione prudenziale, non un costo già sostenuto: ogni tentativo libera la parte coperta dalla usage osservata. Se il budget effettivo disponibile non basta, la fase si sospende; nessun funding aggiuntivo è concesso automaticamente.

Artifact di riferimento, relativi alla root del repository:

- Input congelati: `storage/app/reader-evaluation/p0-recovery-frozen-20260929-v2/{yeswiki,cacti}`.
- Preflight definitivo: `storage/app/reader-evaluation/p0-recovery-20260929-v2/preflight/{yeswiki,cacti}`: `phase-status.json`, `preflight.json`, `scorecard.json`, `report.md`, `collection.json`, `recovery-envelope.json`.
- Le diagnostiche `offline-verification.json` create al primo congelamento precedono gli ultimi adeguamenti; i preflight definitivi sopra sono il riferimento aggiornato.

## Cambiamenti verificati

`deduper.py` invia sempre proposta completa e schede deterministiche, con omissioni dichiarate, senza duplicazione dei transcript. Un unico recupero raggruppato fornisce originali e lineage integrali; block/partial senza quegli originali sono rifiutati. Pass da indice compatto conserva `comparison_basis=compact_index`. Il modello produce solo pass/block/partial; l'orchestratore distingue completed, inconclusive, failed_technical e pending.

Il runner condiviso possiede tutti i tentativi: due richieste logiche, un extra condiviso fra retry e riparazione, massimo tre HTTP; output massimo 8.192 token inclusivo del reasoning, connessione 15 s, lettura 90 s, tentativo 120 s, decisione 300 s incluse attese. Sono disabilitati i retry SDK/trasporto/Pydantic. Retry-After lungo sospende la fase. Tre decisioni tecnicamente fallite aprono il circuito persistente; soltanto la ripresa esplicita lo riapre.

Ogni tentativo viene salvato prima dell'invio con riserva input/output a cache zero, poi finalizzato anche su cancellazione. Usage osservata, impegno sconosciuto, EP e USD stimati rimangono distinti, senza sommare nuovamente reasoning token. Il resume conserva budget e tentativi; riusa gratis successi compatibili, riprova errori tecnici/pending, conserva l'incertezza semantica e ricalcola in ordine i dipendenti quando cambia la proiezione canonica. Le versioni precedenti dei verdetti non sono cache valide.

Entrambi i coordinatori impediscono il dispatch di enrichment non adjudicati anche nella ricostruzione della coda; gli assignment approvati proseguono e non sono dispatchati due volte. L'evaluator è gated sulla deduplica completa; il launcher controlla `phase-status.json` prima di evaluator e replay storico. Il wrapper online accetta exit 2 come normalizzazione incompleta, preservando pending e lavoro approvato.

L'evaluator usa solo blob del commit verificato e path consentiti precalcolati da tutto il corpus/catalogo. Valida file e intervalli di massimo 200 righe inclusive. Una richiesta invalida può consumare l'unico extra di riparazione. Gli ID originali Reader sono citabili; letture del judge non attribuiscono credito di discovery al Reader. Errori tecnici non diventano giudizi semantici definitivi.

## Validazione eseguita

- Suite mirata Python: **100 test passati**, un warning della dipendenza; schede, retrieval, cap, retry/429/408/503, SDK reale con HTTP MockTransport, output invalidi/troncati, cancellazione, riserve, circuito, resume/invalidazione, gate, CRLF/blob LF, limiti sorgente e metriche.
- Suite mirata PHP: **4 test, 102 asserzioni**, dispatch e wrapper/config/exit 2. Sintassi PHP valida; l'ambiente segnala il modulo `gd` caricato due volte.
- Ruff limitato ai nuovi file/core P0 e controlli diff mirati passati. Nessuna suite paid o lint globale.
- Smoke container `--network none`: preflight YesWiki/Cacti e help del comando smoke; zero inferenza.

Immagine separata: **`lailaps-pentest-agent:semantic-p0-20260929-v2`**. ID Docker osservato: `sha256:f174e9711291108e65db2f5fa2c766daac1c8ffc3f7eaa84cec9a7c9d133b888`; config digest `sha256:57bd0e405e5651fa5d6fdea721c1136df628e08bbc50fc886afc7805cce57675`. Deriva dalla base locale `dev` `sha256:71eb454345c47a7f3452346d0826f17223a1ff28f8362714ecad001370f7ce6c`; il tag `dev` non è sostituito. Ricostruzione offline, se necessaria:

```powershell
Set-Location agent/pentest-agent
docker build --network none --pull=false -f Dockerfile.recovery -t lailaps-pentest-agent:semantic-p0-20260929-v2 .
```

## Comandi di lancio

Eseguire dalla root `C:\Users\Alessandro\Desktop\latest-projects\lailaps`. Il launcher usa gli input congelati e la nuova directory `p0-recovery-20260929-v2`; non esegue discovery o replay storico. I sorgenti verificati sono quelli esistenti in `outputs/reader-round/sources/{yeswiki,cacti}` del workspace del 28 settembre. Le azioni paid usano l'env del runtime; nessuna credenziale è inclusa negli artifact.

1. Preflight offline, ripetibile e senza rete inferenziale:

```powershell
pwsh -NoProfile -File plans/testing-reader-20260929/Recover-ReaderArtifacts.ps1 -Action Preflight -Target yeswiki
pwsh -NoProfile -File plans/testing-reader-20260929/Recover-ReaderArtifacts.ps1 -Action Preflight -Target cacti
```

Il secondo comando restituisce intenzionalmente **2**, con `context_limit`. Non procedere con Cacti nel P0.

2. Smoke **paid**, limitato e da avviare soltanto dall'utente:

```powershell
pwsh -NoProfile -File plans/testing-reader-20260929/Recover-ReaderArtifacts.ps1 -Action Smoke -Target yeswiki
```

È un nuovo envelope esplicito di **50.000 EP per ruolo**, separato dal recupero. Tre comparazioni sintetiche esercitano pass, block, partial e recupero degli originali; un giudizio esercita la lettura reale di un blob Git. Richiede esiti strutturati attesi, usage completa, recuperi e lettura sorgente, senza errori dei tentativi. Non viene rilanciato sulla stessa directory né promosso a ground truth. Risultati e accounting: `p0-recovery-20260929-v2/smoke/run/smoke-result.json`; stato del gate: `smoke/phase-status.json`.

3. Deduplica di recupero YesWiki, dopo preflight e smoke completi:

```powershell
pwsh -NoProfile -File plans/testing-reader-20260929/Recover-ReaderArtifacts.ps1 -Action Dedup -Target yeswiki
```

4. Ripresa esplicita di errori tecnici/pending, con stesso ledger e budget residuo:

```powershell
pwsh -NoProfile -File plans/testing-reader-20260929/Recover-ReaderArtifacts.ps1 -Action ResumeDedup -Target yeswiki
```

5. Evaluator dopo il gate di deduplica, ed eventuale ripresa:

```powershell
pwsh -NoProfile -File plans/testing-reader-20260929/Recover-ReaderArtifacts.ps1 -Action Evaluate -Target yeswiki
pwsh -NoProfile -File plans/testing-reader-20260929/Recover-ReaderArtifacts.ps1 -Action ResumeEvaluate -Target yeswiki
```

Gli artifact paid saranno in `p0-recovery-20260929-v2/recovery/yeswiki`. Exit **0** significa fase richiesta completata; **2** significa artifact validi ma fase incompleta/sospesa; **1** significa input/integrità fatali. Una fase terminata non implica successo semantico. Le condizioni inconclusive rimangono da esaminare e non vengono automaticamente ritentate.

Le CLI Python espongono `--roles-config` (alias `--config`), `--resume-failed` e `--preflight-only`; i wrapper PHP espongono configurazione completa e resume, e il postprocessor PHP espone preflight. `--roles-config` è incompatibile con override individuali degli stessi ruoli; i flag preesistenti restano utilizzabili con i nuovi default. La configurazione reale congelata è in `{target}/roles.json`, l'esempio completo è `plans/deduper-role-config.example.json`.

La correttezza semantica resta da misurare dopo la validazione serving: revisione di **tutti** i block/partial e di un campione di pass, inclusi preservazione di condizioni/evidenze e motivi di astensione. Nessun verdetto viene automaticamente promosso a ground truth.
