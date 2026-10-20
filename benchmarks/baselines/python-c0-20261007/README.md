# Baseline Python C0 — 7 ottobre 2026

## Perimetro congelato

Solo l'ultima run globale end-to-end disponibile di YesWiki e BookStack. Selezione per started_at dell'outcome, includendo run fallite; preflight e replay Recon/Reader/Confirmer esclusi. Inventario e motivazione in manifest.json. DB raggiungibile ma nessuna delle due audit_id presente; fonte autorevole: file storici. Nessun ricalcolo del benchmark, nessun nuovo agent loop, nessuna modifica al DB o cancellazione dello storico.

Gli output LLM Python sono la **ground truth operativa di parità**, come richiesto: i verdict non vengono ricorretti rispetto a CVE/oracle. Le due configurazioni agentiche possono differire. Il benchmark catalogo storico è conservato come misura aggiuntiva, senza sostituire le disposizioni LLM.

| Target | Run | Lead ammesse/riportate | Confermati/sospetti/respinti-inconclusivi | Intervallo pass, min | EP globali | Lead/M EP |
|---|---|---:|---:|---:|---:|---:|
| yeswiki | yeswiki-global-20260917-090105 | 5 | 1/3/1 | 243.49 | 1695092.843 | 2.950 |
| bookstack | bookstack-global-glm-limited-20261007-002549 | 4 | 0/4/0 | 22.75 | 776062.184 | 5.154 |

## File e portabilità

- manifest.json: selezione, checksum SHA-256, sorgenti, esito lookup DB, contesto di estrazione.
- <target>/metrics.json: totali globali, metriche derivate con denominatori, timing, errori, modelli, budget/configurazione, pricing, provider, telemetria originale, target commit registrati, elenco finding.
- <target>/outputs.json: finding completi, Recon, checkpoint e output strutturati disponibili; fallback terminale estratto dai log. I messaggi :output sono spesso narrativa intermedia, non verdict.
- <target>/trace-index.json: indice per ruolo/tag/tool con righe nel transcript decompresso. Non associa chiamate e risultati concorrenti senza call ID.
- <target>/source-outcome.json.gz e source-transcript.log.gz: byte originali completi, compressi senza modifiche; includono benchmark/casi/coverage/ledger, diagnostica, tool call, risultati, output narrativi. Aprirli come dati; il vecchio suffisso logs.php non implica che vada eseguito.

Il pacchetto è autonomo e fuori da storage/ e dal core Python. Va mantenuto nel repository o nel futuro archivio di baseline: i file sotto storage/ sono ignorati da Git. Non richiede Python, Laravel, DB, provider LLM o file originali per la verifica. Non è un input da passare al modello auditato: contiene le risposte di riferimento.

## Significato delle metriche

La normalizzazione legge solo report.telemetry globale; non somma pass, categorie, ruoli e lead, che sono proiezioni sovrapposte. Tutta la telemetria originale resta consultabile. Lead = lead_count riportato (incluse respinte), non numero di vulnerabilità utili. BookStack conserva anche raw_leads=5, una non ammessa e lead_count=4: sono denominatori diversi.

- Lead/minuto = lead_count / (somma degli intervalli started_at→finished_at dei pass / 60). Campo esplicito leads_per_reported_pass_minute: **proxy diagnostico**, non durata certificata del processo.
- Lead/EP = lead_count / economic_points_used; Lead/M EP moltiplica per 1.000.000. Anche EP/lead e finding utili/M EP sono presenti. Comparare per target; EP dipendono dal pricing/configurazione storica, non sono unità invarianti tra reference diverse.
- Tempi: inizio run, avvio console senza timezone, clock pass/scope, tempo prima lead riportato, durata e prima HTTP per lead, indice CBM. Durate concorrenti non si sommano come wall time. elapsed_seconds degli output sono locali al rispettivo scope.
- Error rate: exploration_error_results/exploration_tool_results per il solo Reader/exploration; output_retry_requests/model_requests per retry; upstream events/model_requests come densità di incidenti. Missing = null; zero solo se registrato. Tool error rate globale = null: manca un contatore esaustivo compatibile. Fatal/errori di processo separati dai verdict LLM.
- Rate normalizzati vengono ricalcolati dai contatori disponibili, senza correggere quelli originali. Esempio BookStack: first_pass_validation_rate riportato 4; conteggi 4/4 danno 1. Tokens/lead viene ricalcolato dai token globali.

## Limiti storici espliciti

**YesWiki**: log exit 124 e timeout configurato 7.200 s; report pass terminato oltre quattro ore dopo l'avvio. Log modificato circa due ore dopo avvio console, outcome modificato successivamente: gli mtime sono osservazioni del filesystem, non timestamp autorevoli di esecuzione. Actual process wall = null. Non usare l'intervallo pass come soglia di performance vincolante. Benchmark cost: 248 request, 286 tool, 628.553,627 EP; telemetria globale: 716 request, 773 tool, 1.695.092,843 EP. Entrambi congelati; metriche globali usano la seconda. Budget limit/used/remaining non riconciliati. Output strutturati nativi non presenti: Recon/coverage e terminal log preservati.

**BookStack**: exit 1, partial_failure; tre fatal per output retry/request limit. Intervallo pass circa 22,75 min; è il miglior proxy disponibile, non un timer indipendente del processo. Benchmark e global EP concordano. Root category_recon_used_points e budget del pipeline non concordano: conservati entrambi. Ledger HTTP root vuoto non significa assenza di test HTTP: osservare lead_usage e transcript.

Per entrambe benchmark.cost.duration_seconds=0 è privo di misura utilizzabile. Mancano revisione storica esatta del runtime, prompt, lockfile storici, digest immagini e snapshot completo del laboratorio. Si conservano commit target registrati nel benchmark; il checkout corrente non viene presentato come codice che ha prodotto queste run. La baseline conserva la qualità raggiunta negli output; non ricostruisce automaticamente l'ambiente storico.

## Contratto per il confronto TS successivo

P0: associare semanticamente ogni ipotesi Python al risultato TS usando file/operazione/confine di sicurezza e narrativa, senza richiedere stessi ID, testo o ordine. Per ogni riferimento riportare matched/missing/changed, disposizione e milestone, prove e ragione. Una nuova lead non compensa automaticamente la perdita di una lead di riferimento; lead extra vanno rendicontate separatamente. Usare i verdict Python come riferimento, anche se discutibili. Distinguere suspected→confirmed (possibile miglioramento) da confirmed→suspected/missing (regressione da spiegare); anche rifiuti/inconclusivi restano visibili.

Confrontare separatamente Recon/Reader/investigatore quando gli output sono disponibili, poi end-to-end. Mostrare qualità, costo, EP, durata ed errori come dimensioni separate: un numero unico nasconderebbe regressioni. Modelli, pricing, budget o ambiente diversi identificano un confronto operativo; non provano causalità di una singola eliminazione. Per YesWiki il tempo storico è solo diagnostico: nessuna soglia temporale viene inventata. Non è richiesta validazione esterna dei verdict in questa prima fase.

Gate C0: esattamente due run selezionate; archivi byte-identici agli originali via SHA-256; normalizzazione riproducibile offline; finding/disposizioni identici all'outcome; denominatori e dati mancanti espliciti. Gate TS futuro: nessuna perdita non spiegata dei risultati di riferimento; comparator da costruire nel nuovo progetto. Questo snapshot non misura variabilità statistica né certifica la parità TS.

P1: benchmark appaiati/ripetizioni per determinare variabilità e tolleranze di tempo/costo; sorgenti/laboratorio realmente immutabili per le nuove run; ulteriori stack e adjudication esterna solo nella fase di qualità successiva.

## Verifica offline

Dal root del repository, con Node:

```powershell
node --test scripts/freeze-python-c0.test.mjs
node scripts/freeze-python-c0.mjs verify benchmarks/baselines/python-c0-20261007
```

La verifica controlla manifest, byte originali decompressi e proiezioni ricostruite, senza accedere al DB o lanciare agenti. Manifest.json non è autofirmato: checksum rilevano cambiamenti accidentali; versionare il pacchetto per fissare il riferimento. Il comando freeze rifiuta directory esistenti e run diventate obsolete; una nuova baseline richiede una nuova directory e una revisione esplicita della selezione.
