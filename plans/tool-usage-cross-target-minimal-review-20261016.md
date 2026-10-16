# Verifica del profilo minimal su Gitea, YesWiki e Cacti

Analisi offline del 16 ottobre 2026; nessun loop agente o test con API eseguito. Nessuna modifica all’applicazione.

## Campione e metodo

20 artifact di esecuzione, selezionati per avere sia le run lunghe recenti sia i benchmark recenti dei singoli ruoli:

- Gitea: le due global investigative più recenti (15 e 17 settembre) e la Recon del 27 settembre.
- YesWiki: le due global investigative più recenti (15 e 17 settembre), i tre Reader del 29 settembre, il Recon/Reader del 20 settembre e l’ultima Recon standalone completata del 18 settembre. Il Recon/Reader del 20 è uno snapshot parziale (`running`), non un audit completo.
- Cacti: ultima Recon (29 settembre), ultimi tre Reader (1 ottobre), ultimi tre Confirmer (2 ottobre), ultimi tre Worker batch/episode (4 ottobre).

Le tre esecuzioni Reader YesWiki del 29 settembre appartengono allo stesso avvio di benchmark. I batch Worker Cacti possono condividere lead/fixture: questo è un campione descrittivo dell’uso, non 20 prove indipendenti di qualità. Gli snapshot non dichiarano una nuova Recon dove il ruolo non è eseguito: nessuna moltiplicazione delle chiamate Recon delle run di origine.

Conteggio dagli outcome: 8,489 chiamate complessive; 8,217 nei quattro ruoli Reader/Confirmer/Worker/Recon, 272 nei ruoli storici aggiuntivi. Contatori di tentativi registrati, non successi. Chiamate a programmi e subcall possono essere contabilizzate separatamente: `run_code` non misura da solo tutte le operazioni sottostanti. Nessun confronto di efficienza col numero di tool call Playwright; browser conservato come capacità.

La fonte quantitativa è `report.telemetry.tool_calls_details`; controllati anche `source_observations`, disponibilità dei tool e campioni di transcript. Nei log storici non tutte le chiamate aggregate sono riproducibili come righe `[role:tool]`: non attribuire ai totali un tasso di successo ricostruito dalla console. I contatori aggregati storici `exploration_error_results` e i relativi rate non vengono usati per stimare errori dei singoli tool, anche perché alcuni rate superano 1.

## Esecuzioni considerate

| Run | Reader | Confirmer | Worker | Recon | Altri | Totale | Terminazione |
|---|---:|---:|---:|---:|---:|---:|---|
| `gitea-global-20260917-090348` | 634 | 34 | 12 | 2 | 17 | 699 | depth_complete |
| `gitea-global-20260915-091923` | 2292 | 246 | 229 | 62 | 105 | 2934 | partial_failure |
| `gitea-recon-global-20260927-203441` | 0 | 0 | 0 | 65 | 0 | 65 | category_recon_handoff_complete |
| `yeswiki-global-20260917-090105` | 539 | 78 | 107 | 2 | 47 | 773 | depth_complete |
| `yeswiki-global-20260915-091639` | 996 | 241 | 679 | 51 | 100 | 2067 | partial_failure |
| `yeswiki-reader-area-20260929-083534` | 115 | 0 | 0 | 0 | 0 | 115 | economic_budget_exhausted |
| `yeswiki-reader-area-20260929-083534-2` | 117 | 0 | 0 | 0 | 0 | 117 | economic_budget_exhausted |
| `yeswiki-reader-area-20260929-083534-3` | 130 | 0 | 0 | 0 | 0 | 130 | economic_budget_exhausted |
| `yeswiki-recon-reader-global-20260920-194109` | 263 | 0 | 0 | 22 | 3 | 288 | running |
| `yeswiki-recon-global-20260918-233349` | 0 | 0 | 0 | 4 | 0 | 4 | category_recon_handoff_complete |
| `cacti-recon-global-20260929-084019` | 0 | 0 | 0 | 13 | 0 | 13 | category_recon_handoff_complete |
| `cacti-reader-area-20261001-213423` | 92 | 0 | 0 | 0 | 0 | 92 | economic_budget_exhausted |
| `cacti-reader-area-20261001-212252` | 129 | 0 | 0 | 0 | 0 | 129 | economic_budget_exhausted |
| `cacti-reader-area-20261001-211929` | 105 | 0 | 0 | 0 | 0 | 105 | economic_budget_exhausted |
| `cacti-confirmer-xss-20261002-190243` | 0 | 17 | 0 | 0 | 0 | 17 | single_lead_confirmer_complete |
| `cacti-confirmer-injection-20261002-185511` | 0 | 19 | 0 | 0 | 0 | 19 | single_lead_confirmer_complete |
| `cacti-confirmer-broken-acc-20261002-184654` | 0 | 17 | 0 | 0 | 0 | 17 | single_lead_confirmer_complete |
| `cacti-worker-batch-20261004-170104` | 0 | 0 | 600 | 0 | 0 | 600 | worker_batch_complete_with_errors |
| `cacti-worker-batch-20261004-023302` | 0 | 0 | 172 | 0 | 0 | 172 | worker_episode_complete |
| `cacti-worker-batch-20261004-012252` | 0 | 0 | 133 | 0 | 0 | 133 | worker_episode_complete |

## Risultati che modificano la proposta precedente

- Grafo e snippet non sono inutilizzati: 315 search_code_graph, 112 get_graph_code_snippet, 40 trace_code_path e 15 trace_data_flow. Nel ledger sorgente risultano inoltre 51 osservazioni get_graph_code_snippet su Gitea e 36 su YesWiki: non si tratta soltanto di nomi comparsi nelle intenzioni del modello. Questo non dimostra un contributo causale ai finding.
- Nei Reader delle global lunghe del 15 e 17 settembre run_workspace_command non compare nel catalogo dei tool degli outcome. Nei Reader condizionali successivi è disponibile. Non è dimostrato che tutti gli strumenti e le condizioni siano rimasti identici salvo Playwright. Le global lunghe hanno anche ruoli storici judge/reviewer/handoff, oggi non equivalenti alla pipeline BookStack.
- Nei tre Reader YesWiki del 29 settembre la shell ha 144 chiamate su 362 (39,8%); nei tre Reader Cacti del 1 ottobre 45 su 326 (13,8%). È confermata l’adozione spontanea delle primitive quando disponibili; non è ancora dimostrata la parità di qualità senza tool specializzati.
- list_source_refs ha 85 chiamate; read_source_ref 202; query_html_response 37; search_response 144; inspect_tool_output 14. Non vanno classificati come inutilizzati. Accorpare il recupero/elaborazione degli output può avere senso, ma deve mantenere la capacità di leggere le evidenze prima di passarle a script.
- Varianti http_json_call (6), http_multipart_call (6), http_raw_call (10) sono utilizzate. Accorpamento del trasporto con preservazione dei formati, non eliminazione della capacità.
- run_code ha 7 chiamate; è candidato a sostituzione con shell per sovrapposizione, non per inutilizzo. Nel Reader Cacti del 1 ottobre 21:22 la prima chiamata usa argomenti posizionali per un binding keyword-only; il modello corregge e riprova.
- Restano a zero navigate_source, crypto_artifact, find_json_records, http_json_array_call, bind_actor_auth; erano dichiarati disponibili in almeno alcuni ruoli attivi del campione. Zero uso non dimostra che rimuovere un eventuale tool di recovery sia sempre innocuo.

## Raccomandazione P0 / P1

**P0:** profilo sperimentale con i cinque tool a zero nascosti; run_code sostituito da shell; list_dir sostituibile con ls/find. Conservare read_file e provenance, SQL libero con dossier, runtime/probe, HTTP/browser e recupero delle evidenze. Se bind_actor_auth viene nascosto, verificare che le fixture d’autenticazione previste possano usare gli actor/sessioni esistenti. Conservare gli inventari list_surface_signals finché un equivalente è consultabile dalla shell.

**P1:** accorpare le varianti HTTP e l’accesso alle evidenze usando i backend esistenti. Confrontare separatamente la rimozione di grafo/tracing e quella di search_source. Sono rinunce di capacità/comodità utilizzate, non cleanup di codice morto. Non cambiare contemporaneamente il browser.

**Validazione:** baseline attuale e profilo minimal sugli stessi input e budget. Prima isolare Reader e Investigator su fixture uguali; poi confrontare una Recon nuova, senza riuso. Misurare finding validi ed evidenze, fallimenti, costo e durata. Valutare gli eventuali finding persi attraverso i transcript. Il campione storico orienta le ablazioni, non sostituisce il confronto controllato.

## Tool aggregati per target

| Tool | Gitea | YesWiki | Cacti | Totale |
|---|---:|---:|---:|---:|
| `browser_flow` | 2 | 21 | 0 | 23 |
| `codebase_architecture` | 12 | 19 | 1 | 32 |
| `get_graph_code_snippet` | 64 | 48 | 0 | 112 |
| `http_call` | 50 | 73 | 142 | 265 |
| `http_form_call` | 3 | 66 | 67 | 136 |
| `http_json_call` | 3 | 3 | 0 | 6 |
| `http_multipart_call` | 3 | 3 | 0 | 6 |
| `http_raw_call` | 3 | 7 | 0 | 10 |
| `inspect_browser` | 4 | 3 | 0 | 7 |
| `inspect_persisted_evidence` | 0 | 0 | 1 | 1 |
| `inspect_response` | 1 | 2 | 1 | 4 |
| `inspect_tool_output` | 2 | 6 | 6 | 14 |
| `list_dir` | 233 | 228 | 6 | 467 |
| `list_source_refs` | 35 | 41 | 9 | 85 |
| `list_surface_signals` | 122 | 68 | 8 | 198 |
| `query_database` | 2 | 118 | 194 | 314 |
| `query_html_response` | 5 | 5 | 27 | 37 |
| `read_file` | 1680 | 1198 | 282 | 3160 |
| `read_investigation_event` | 0 | 1 | 0 | 1 |
| `read_notes` | 48 | 41 | 5 | 94 |
| `read_response` | 6 | 3 | 4 | 13 |
| `read_source_ref` | 72 | 119 | 11 | 202 |
| `reset_actor_session` | 0 | 1 | 0 | 1 |
| `run_code` | 0 | 4 | 3 | 7 |
| `run_target_command` | 116 | 151 | 78 | 345 |
| `run_target_probe` | 8 | 0 | 2 | 10 |
| `run_workspace_command` | 16 | 175 | 52 | 243 |
| `search_code_graph` | 174 | 141 | 0 | 315 |
| `search_investigation_history` | 0 | 1 | 1 | 2 |
| `search_response` | 10 | 74 | 60 | 144 |
| `search_source` | 800 | 624 | 273 | 1697 |
| `trace_code_path` | 15 | 25 | 0 | 40 |
| `trace_data_flow` | 2 | 13 | 0 | 15 |
| `write_note` | 85 | 62 | 64 | 211 |

## Tool e disponibilità per ruolo

Formato `chiamate / snapshot attivi che dichiarano disponibile il tool`. Il denominatore conta artifact in cui il ruolo ha almeno una chiamata, non richieste modello; non indica disponibilità a ogni turno. `assente` significa nessuna entry in snapshot attivi: non equivale a un tool offerto e ignorato.

### reader

| Tool | Gitea | YesWiki | Cacti |
|---|---:|---:|---:|
| `codebase_architecture` | 0 / 1 | 0 / 1 | assente |
| `get_graph_code_snippet` | 63 / 2 | 48 / 6 | 0 / 3 |
| `inspect_tool_output` | 2 / 1 | 6 / 5 | 0 / 3 |
| `list_dir` | 170 / 2 | 178 / 6 | 1 / 3 |
| `list_source_refs` | 35 / 2 | 41 / 6 | 9 / 3 |
| `list_surface_signals` | 115 / 2 | 67 / 6 | 1 / 3 |
| `read_file` | 1565 / 2 | 927 / 6 | 136 / 3 |
| `read_notes` | 26 / 2 | 6 / 6 | 0 / 3 |
| `read_source_ref` | 46 / 2 | 102 / 6 | 0 / 3 |
| `run_code` | assente | 1 / 4 | 2 / 3 |
| `run_workspace_command` | assente | 144 / 3 | 45 / 3 |
| `search_code_graph` | 140 / 2 | 128 / 6 | 0 / 3 |
| `search_source` | 684 / 2 | 432 / 6 | 132 / 3 |
| `trace_code_path` | 15 / 2 | 25 / 6 | 0 / 3 |
| `trace_data_flow` | 2 / 2 | 13 / 6 | 0 / 3 |
| `write_note` | 63 / 2 | 42 / 6 | 0 / 3 |
### confirmer

| Tool | Gitea | YesWiki | Cacti |
|---|---:|---:|---:|
| `get_graph_code_snippet` | 1 / 2 | 0 / 2 | 0 / 3 |
| `inspect_tool_output` | 0 / 1 | 0 / 1 | 0 / 3 |
| `list_dir` | 2 / 2 | 14 / 2 | 1 / 3 |
| `navigate_source` | 0 / 1 | 0 / 1 | 0 / 3 |
| `query_database` | 0 / 2 | 29 / 2 | 9 / 3 |
| `read_file` | 102 / 2 | 121 / 2 | 18 / 3 |
| `read_investigation_event` | 0 / 1 | 0 / 1 | 0 / 3 |
| `read_notes` | 11 / 2 | 2 / 2 | 1 / 3 |
| `read_source_ref` | 15 / 2 | 6 / 2 | 6 / 3 |
| `run_code` | 0 / 1 | 0 / 1 | 0 / 3 |
| `run_target_command` | 25 / 2 | 15 / 2 | 0 / 3 |
| `run_target_probe` | 8 / 2 | 0 / 2 | 2 / 3 |
| `run_workspace_command` | 16 / 2 | 31 / 2 | 3 / 3 |
| `search_code_graph` | 0 / 2 | 3 / 2 | 0 / 3 |
| `search_investigation_history` | 0 / 1 | 0 / 1 | 0 / 3 |
| `search_source` | 87 / 2 | 91 / 2 | 11 / 3 |
| `trace_code_path` | 0 / 2 | 0 / 2 | 0 / 3 |
| `trace_data_flow` | 0 / 2 | 0 / 2 | 0 / 3 |
| `write_note` | 13 / 2 | 7 / 2 | 2 / 3 |
### worker

| Tool | Gitea | YesWiki | Cacti |
|---|---:|---:|---:|
| `bind_actor_auth` | 0 / 2 | 0 / 2 | 0 / 3 |
| `browser_flow` | 2 / 2 | 21 / 2 | 0 / 3 |
| `crypto_artifact` | 0 / 2 | 0 / 2 | 0 / 3 |
| `find_json_records` | 0 / 2 | 0 / 2 | 0 / 3 |
| `http_call` | 50 / 2 | 73 / 2 | 142 / 3 |
| `http_form_call` | 3 / 2 | 66 / 2 | 67 / 3 |
| `http_json_array_call` | 0 / 2 | 0 / 2 | 0 / 3 |
| `http_json_call` | 3 / 2 | 3 / 2 | 0 / 3 |
| `http_multipart_call` | 3 / 2 | 3 / 2 | 0 / 3 |
| `http_raw_call` | 3 / 2 | 7 / 2 | 0 / 3 |
| `inspect_browser` | 4 / 2 | 3 / 2 | 0 / 3 |
| `inspect_persisted_evidence` | assente | assente | 1 / 3 |
| `inspect_response` | 1 / 2 | 2 / 2 | 1 / 3 |
| `inspect_tool_output` | 0 / 1 | 0 / 1 | 6 / 3 |
| `list_dir` | 2 / 2 | 13 / 2 | 4 / 3 |
| `query_database` | 2 / 2 | 89 / 2 | 185 / 3 |
| `query_html_response` | 5 / 2 | 5 / 2 | 27 / 3 |
| `read_file` | 9 / 2 | 149 / 2 | 128 / 3 |
| `read_investigation_event` | 0 / 1 | 1 / 1 | 0 / 3 |
| `read_notes` | 11 / 2 | 33 / 2 | 4 / 3 |
| `read_response` | 6 / 2 | 3 / 2 | 4 / 3 |
| `read_source_ref` | 11 / 2 | 11 / 2 | 5 / 3 |
| `reset_actor_session` | 0 / 2 | 1 / 2 | 0 / 3 |
| `run_target_command` | 91 / 2 | 136 / 2 | 78 / 3 |
| `search_investigation_history` | 0 / 1 | 1 / 1 | 1 / 3 |
| `search_response` | 10 / 2 | 74 / 2 | 60 / 3 |
| `search_source` | 16 / 2 | 79 / 2 | 130 / 3 |
| `trace_data_flow` | 0 / 0 | 0 / 0 | 0 / 0 |
| `write_note` | 9 / 2 | 13 / 2 | 62 / 3 |
### category_recon

| Tool | Gitea | YesWiki | Cacti |
|---|---:|---:|---:|
| `codebase_architecture` | 12 / 3 | 19 / 4 | 1 / 1 |
| `inspect_tool_output` | 0 / 1 | 0 / 2 | 0 / 1 |
| `list_dir` | 59 / 3 | 23 / 4 | 0 / 1 |
| `list_surface_signals` | 7 / 1 | 1 / 2 | 7 / 1 |
| `read_file` | 4 / 1 | 1 / 2 | 0 / 1 |
| `read_notes` | 0 / 3 | 0 / 4 | 0 / 1 |
| `run_code` | 0 / 1 | 3 / 2 | 1 / 1 |
| `run_workspace_command` | 0 / 1 | assente | 4 / 1 |
| `search_code_graph` | 34 / 3 | 10 / 4 | 0 / 1 |
| `search_source` | 13 / 3 | 22 / 4 | 0 / 1 |
| `write_note` | 0 / 3 | 0 / 4 | 0 / 1 |

## Riscontri puntuali nei log

- YesWiki global 17 settembre, righe 3188–3192: trace_code_path su WebfingerService.getRemoteActor/getInteractionUrl con risultati Call path inbound.
- YesWiki global 15 settembre, righe 52345–52347: get_graph_code_snippet con simbolo non trovato; non ogni tentativo graph riesce.
- Cacti Reader 1 ottobre 21:22, righe 1481–1489: run_code, correzione degli argomenti del binding e nuovo tentativo.
- Cacti Recon 29 settembre, righe 787–790: run_code placeholder e list_surface_signals che restituisce scan_unavailable per errori Semgrep. Il conteggio delle chiamate non misura il valore del risultato.
- Cacti Worker 4 ottobre 17:01, righe 1289–1290 e 1478–1479: query_html_response restituisce rispettivamente 7 e 3 match per form/input; righe 1411–1415: search_response con risultati positivi e vuoti.
- YesWiki Reader 29 settembre -3, righe 41 e 57: shell usata per find/wc/ls; Cacti Reader 1 ottobre 21:19, riga 244: pipeline head/sed/grep.

## Fonti

- [gitea-global-20260917-090348 outcome](C:/Users/Alessandro/Desktop/latest-projects/lailaps/storage/app/runs/gitea/global/gitea-global-20260917-090348/gitea-global-20260917-090348-outcome.json) · [log](C:/Users/Alessandro/Desktop/latest-projects/lailaps/storage/app/runs/gitea/global/gitea-global-20260917-090348/gitea-global-20260917-090348-logs.php)
- [gitea-global-20260915-091923 outcome](C:/Users/Alessandro/Desktop/latest-projects/lailaps/storage/app/runs/gitea/global/gitea-global-20260915-091923/gitea-global-20260915-091923-outcome.json) · [log](C:/Users/Alessandro/Desktop/latest-projects/lailaps/storage/app/runs/gitea/global/gitea-global-20260915-091923/gitea-global-20260915-091923-logs.php)
- [gitea-recon-global-20260927-203441 outcome](C:/Users/Alessandro/Desktop/latest-projects/lailaps/storage/app/runs/gitea/recon-global/gitea-recon-global-20260927-203441/gitea-recon-global-20260927-203441-outcome.json) · [log](C:/Users/Alessandro/Desktop/latest-projects/lailaps/storage/app/runs/gitea/recon-global/gitea-recon-global-20260927-203441/gitea-recon-global-20260927-203441-logs.php)
- [yeswiki-global-20260917-090105 outcome](C:/Users/Alessandro/Desktop/latest-projects/lailaps/storage/app/runs/yeswiki/global/yeswiki-global-20260917-090105/yeswiki-global-20260917-090105-outcome.json) · [log](C:/Users/Alessandro/Desktop/latest-projects/lailaps/storage/app/runs/yeswiki/global/yeswiki-global-20260917-090105/yeswiki-global-20260917-090105-logs.php)
- [yeswiki-global-20260915-091639 outcome](C:/Users/Alessandro/Desktop/latest-projects/lailaps/storage/app/runs/yeswiki/global/yeswiki-global-20260915-091639/yeswiki-global-20260915-091639-outcome.json) · [log](C:/Users/Alessandro/Desktop/latest-projects/lailaps/storage/app/runs/yeswiki/global/yeswiki-global-20260915-091639/yeswiki-global-20260915-091639-logs.php)
- [yeswiki-reader-area-20260929-083534 outcome](C:/Users/Alessandro/Desktop/latest-projects/lailaps/storage/app/runs/yeswiki/reader-area/yeswiki-reader-area-20260929-083534/yeswiki-reader-area-20260929-083534-outcome.json) · [log](C:/Users/Alessandro/Desktop/latest-projects/lailaps/storage/app/runs/yeswiki/reader-area/yeswiki-reader-area-20260929-083534/yeswiki-reader-area-20260929-083534-logs.php)
- [yeswiki-reader-area-20260929-083534-2 outcome](C:/Users/Alessandro/Desktop/latest-projects/lailaps/storage/app/runs/yeswiki/reader-area/yeswiki-reader-area-20260929-083534-2/yeswiki-reader-area-20260929-083534-2-outcome.json) · [log](C:/Users/Alessandro/Desktop/latest-projects/lailaps/storage/app/runs/yeswiki/reader-area/yeswiki-reader-area-20260929-083534-2/yeswiki-reader-area-20260929-083534-2-logs.php)
- [yeswiki-reader-area-20260929-083534-3 outcome](C:/Users/Alessandro/Desktop/latest-projects/lailaps/storage/app/runs/yeswiki/reader-area/yeswiki-reader-area-20260929-083534-3/yeswiki-reader-area-20260929-083534-3-outcome.json) · [log](C:/Users/Alessandro/Desktop/latest-projects/lailaps/storage/app/runs/yeswiki/reader-area/yeswiki-reader-area-20260929-083534-3/yeswiki-reader-area-20260929-083534-3-logs.php)
- [yeswiki-recon-reader-global-20260920-194109 outcome](C:/Users/Alessandro/Desktop/latest-projects/lailaps/storage/app/runs/yeswiki/recon-reader-global/yeswiki-recon-reader-global-20260920-194109/yeswiki-recon-reader-global-20260920-194109-outcome.json) · [log](C:/Users/Alessandro/Desktop/latest-projects/lailaps/storage/app/runs/yeswiki/recon-reader-global/yeswiki-recon-reader-global-20260920-194109/yeswiki-recon-reader-global-20260920-194109-logs.php)
- [yeswiki-recon-global-20260918-233349 outcome](C:/Users/Alessandro/Desktop/latest-projects/lailaps/storage/app/runs/yeswiki/recon-global/yeswiki-recon-global-20260918-233349/yeswiki-recon-global-20260918-233349-outcome.json) · [log](C:/Users/Alessandro/Desktop/latest-projects/lailaps/storage/app/runs/yeswiki/recon-global/yeswiki-recon-global-20260918-233349/yeswiki-recon-global-20260918-233349-logs.php)
- [cacti-recon-global-20260929-084019 outcome](C:/Users/Alessandro/Desktop/latest-projects/lailaps/storage/app/runs/cacti/recon-global/cacti-recon-global-20260929-084019/cacti-recon-global-20260929-084019-outcome.json) · [log](C:/Users/Alessandro/Desktop/latest-projects/lailaps/storage/app/runs/cacti/recon-global/cacti-recon-global-20260929-084019/cacti-recon-global-20260929-084019-logs.php)
- [cacti-reader-area-20261001-213423 outcome](C:/Users/Alessandro/Desktop/latest-projects/lailaps/storage/app/runs/cacti/reader-area/cacti-reader-area-20261001-213423/cacti-reader-area-20261001-213423-outcome.json) · [log](C:/Users/Alessandro/Desktop/latest-projects/lailaps/storage/app/runs/cacti/reader-area/cacti-reader-area-20261001-213423/cacti-reader-area-20261001-213423-logs.php)
- [cacti-reader-area-20261001-212252 outcome](C:/Users/Alessandro/Desktop/latest-projects/lailaps/storage/app/runs/cacti/reader-area/cacti-reader-area-20261001-212252/cacti-reader-area-20261001-212252-outcome.json) · [log](C:/Users/Alessandro/Desktop/latest-projects/lailaps/storage/app/runs/cacti/reader-area/cacti-reader-area-20261001-212252/cacti-reader-area-20261001-212252-logs.php)
- [cacti-reader-area-20261001-211929 outcome](C:/Users/Alessandro/Desktop/latest-projects/lailaps/storage/app/runs/cacti/reader-area/cacti-reader-area-20261001-211929/cacti-reader-area-20261001-211929-outcome.json) · [log](C:/Users/Alessandro/Desktop/latest-projects/lailaps/storage/app/runs/cacti/reader-area/cacti-reader-area-20261001-211929/cacti-reader-area-20261001-211929-logs.php)
- [cacti-confirmer-xss-20261002-190243 outcome](C:/Users/Alessandro/Desktop/latest-projects/lailaps/storage/app/runs/cacti/confirmer-xss/cacti-confirmer-xss-20261002-190243/cacti-confirmer-xss-20261002-190243-outcome.json) · [log](C:/Users/Alessandro/Desktop/latest-projects/lailaps/storage/app/runs/cacti/confirmer-xss/cacti-confirmer-xss-20261002-190243/cacti-confirmer-xss-20261002-190243-logs.php)
- [cacti-confirmer-injection-20261002-185511 outcome](C:/Users/Alessandro/Desktop/latest-projects/lailaps/storage/app/runs/cacti/confirmer-injection/cacti-confirmer-injection-20261002-185511/cacti-confirmer-injection-20261002-185511-outcome.json) · [log](C:/Users/Alessandro/Desktop/latest-projects/lailaps/storage/app/runs/cacti/confirmer-injection/cacti-confirmer-injection-20261002-185511/cacti-confirmer-injection-20261002-185511-logs.php)
- [cacti-confirmer-broken-acc-20261002-184654 outcome](C:/Users/Alessandro/Desktop/latest-projects/lailaps/storage/app/runs/cacti/confirmer-broken-acc/cacti-confirmer-broken-acc-20261002-184654/cacti-confirmer-broken-acc-20261002-184654-outcome.json) · [log](C:/Users/Alessandro/Desktop/latest-projects/lailaps/storage/app/runs/cacti/confirmer-broken-acc/cacti-confirmer-broken-acc-20261002-184654/cacti-confirmer-broken-acc-20261002-184654-logs.php)
- [cacti-worker-batch-20261004-170104 outcome](C:/Users/Alessandro/Desktop/latest-projects/lailaps/storage/app/runs/cacti/worker-batch/cacti-worker-batch-20261004-170104/cacti-worker-batch-20261004-170104-outcome.json) · [log](C:/Users/Alessandro/Desktop/latest-projects/lailaps/storage/app/runs/cacti/worker-batch/cacti-worker-batch-20261004-170104/cacti-worker-batch-20261004-170104-logs.php)
- [cacti-worker-batch-20261004-023302 outcome](C:/Users/Alessandro/Desktop/latest-projects/lailaps/storage/app/runs/cacti/worker-batch/cacti-worker-batch-20261004-023302/cacti-worker-batch-20261004-023302-outcome.json) · [log](C:/Users/Alessandro/Desktop/latest-projects/lailaps/storage/app/runs/cacti/worker-batch/cacti-worker-batch-20261004-023302/cacti-worker-batch-20261004-023302-logs.php)
- [cacti-worker-batch-20261004-012252 outcome](C:/Users/Alessandro/Desktop/latest-projects/lailaps/storage/app/runs/cacti/worker-batch/cacti-worker-batch-20261004-012252/cacti-worker-batch-20261004-012252-outcome.json) · [log](C:/Users/Alessandro/Desktop/latest-projects/lailaps/storage/app/runs/cacti/worker-batch/cacti-worker-batch-20261004-012252/cacti-worker-batch-20261004-012252-logs.php)

- [Estratto strutturato della telemetria](C:/Users/Alessandro/Desktop/latest-projects/lailaps/storage/app/tool-usage-cross-target-summary-20261016.json)
