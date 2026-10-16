# Utilizzo tool: ultime tre run investigative

Run BookStack del 7, 15 e 16 ottobre 2026. Esclusa dal confronto la run `bookstack-global-20261015-090527`, fallita nel setup prima dell’avvio degli agenti. Le altre tre sono tutte terminate con `partial_failure`: i volumi descrivono quanto effettivamente tentato, non tre audit completi.

Fonte: `report.telemetry.tool_calls_details` negli outcome. Le chiamate comprendono i tentativi con errori o argomenti invalidi; non sono conteggi di successi. Per il 15 e 16 ottobre i conteggi Confirmer/Worker coincidono esattamente con gli eventi `attempt` dei journal Investigator (61 e 161). Non sommare attempt, started, result e output del medesimo journal. `run_code` è sempre a zero: nessuna subchiamata programmatica da separare. I tool di output strutturato/checkpoint non sono inclusi in questi contatori. Una chiamata browser può eseguire più azioni/richieste; una chiamata comando può eseguire più operazioni.

| Run | Reader | Confirmer | Worker | Investigator (C+W) | Recon nuova | Totale |
|---|---:|---:|---:|---:|---:|---:|
| `bookstack-global-glm-limited-20261007-002549` | 99 | 45 | 138 | 183 | 21 | 303 |
| `bookstack-global-20261015-091141` | 124 | 54 | 7 | 61 | 0 | 185 |
| `bookstack-global-20261016-075240` | 117 | 64 | 97 | 161 | 0 | 278 |

Le run del 15 e 16 riusano entrambe la Recon del 7 ottobre: 37 aree, zero nuove chiamate e zero richieste modello Recon.

## Reader

| Tool | 07/10 | 15/10 | 16/10 | Totale |
|---|---:|---:|---:|---:|
| `list_dir` | 2 | 5 | 4 | 11 |
| `list_surface_signals` | 2 | 3 | 3 | 8 |
| `read_file` | 52 | 58 | 58 | 168 |
| `read_notes` | 0 | 2 | 0 | 2 |
| `run_target_command` | 0 | 1 | 0 | 1 |
| `run_workspace_command` | 17 | 8 | 0 | 25 |
| `search_code_graph` | 0 | 0 | 2 | 2 |
| `search_source` | 26 | 47 | 49 | 122 |
| `write_note` | 0 | 0 | 1 | 1 |

Nessuna chiamata: `get_graph_code_snippet`, `inspect_tool_output`, `list_source_refs`, `read_source_ref`, `run_target_probe`, `trace_code_path`, `trace_data_flow`.

## Investigator: Confirmer

| Tool | 07/10 | 15/10 | 16/10 | Totale |
|---|---:|---:|---:|---:|
| `list_dir` | 2 | 0 | 0 | 2 |
| `read_file` | 22 | 25 | 20 | 67 |
| `read_notes` | 0 | 7 | 0 | 7 |
| `read_source_ref` | 1 | 3 | 2 | 6 |
| `run_target_command` | 5 | 0 | 15 | 20 |
| `run_target_probe` | 0 | 1 | 8 | 9 |
| `run_workspace_command` | 4 | 5 | 11 | 20 |
| `search_source` | 10 | 10 | 7 | 27 |
| `write_note` | 1 | 3 | 1 | 5 |

Nessuna chiamata: `get_graph_code_snippet`, `inspect_tool_output`, `navigate_source`, `query_database`, `read_investigation_event`, `run_code`, `search_code_graph`, `search_investigation_history`, `trace_code_path`, `trace_data_flow`.

## Investigator: Worker

| Tool | 07/10 | 15/10 | 16/10 | Totale |
|---|---:|---:|---:|---:|
| `browser_code` | 0 | 0 | 13 | 13 |
| `browser_flow` | 15 | 4 | 0 | 19 |
| `http_call` | 15 | 0 | 12 | 27 |
| `http_form_call` | 15 | 0 | 9 | 24 |
| `inspect_browser` | 1 | 0 | 3 | 4 |
| `inspect_persisted_evidence` | 1 | 0 | 0 | 1 |
| `inspect_response` | 1 | 0 | 0 | 1 |
| `list_dir` | 4 | 0 | 0 | 4 |
| `query_database` | 28 | 1 | 32 | 61 |
| `query_html_response` | 0 | 0 | 1 | 1 |
| `read_file` | 24 | 0 | 9 | 33 |
| `read_notes` | 2 | 0 | 0 | 2 |
| `read_response` | 1 | 0 | 0 | 1 |
| `read_source_ref` | 1 | 0 | 0 | 1 |
| `reset_actor_session` | 0 | 0 | 1 | 1 |
| `run_target_command` | 15 | 2 | 8 | 25 |
| `run_target_probe` | 0 | 0 | 2 | 2 |
| `search_investigation_history` | 1 | 0 | 0 | 1 |
| `search_response` | 4 | 0 | 1 | 5 |
| `search_source` | 9 | 0 | 6 | 15 |
| `write_note` | 1 | 0 | 0 | 1 |

Nessuna chiamata: `bind_actor_auth`, `crypto_artifact`, `find_json_records`, `http_json_array_call`, `http_json_call`, `http_multipart_call`, `http_raw_call`, `inspect_tool_output`, `read_investigation_event`, `trace_data_flow`.

`trace_data_flow` è disabilitato/non disponibile al Worker negli snapshot delle tre run; non va interpretato come un tool disponibile ignorato. `browser_flow` è usato il 7 e il 15, `browser_code` il 16. `run_target_probe` compare nella disponibilità Worker del 16.

## Recon

| Tool | 07/10 | 15/10 | 16/10 | Totale |
|---|---:|---:|---:|---:|
| `codebase_architecture` | 1 | 0 | 0 | 1 |
| `list_dir` | 2 | 0 | 0 | 2 |
| `run_workspace_command` | 5 | 0 | 0 | 5 |
| `search_code_graph` | 8 | 0 | 0 | 8 |
| `search_source` | 5 | 0 | 0 | 5 |

Nessuna chiamata: `inspect_tool_output`, `list_surface_signals`, `read_file`, `read_notes`, `run_code`, `run_target_command`, `run_target_probe`, `write_note`.

## Lettura dei dati

- Reader: `read_file` + `search_source` = 290/340 chiamate (85,3%). Sono stati avviati 4, 7 e 5 Reader rispettivamente; le altre aree non sono partite per budget.
- Investigator: Confirmer 163 chiamate, Worker 242; totale 405. `read_file` 100, `query_database` 61, `run_target_command` 45, `search_source` 42.
- Il 15 ottobre il Worker ha solo 7 tentativi: la run registra un errore fatale sui retry di `browser_flow`. Il 16 arriva a 97 e registra un errore di output strutturato (`Nessun payload completo da riparare`). Il 7 registra errori di output/retry e limite richieste del Worker.
- La Recon nuova usa soprattutto `search_code_graph` (8/21), `search_source` (5/21) e `run_workspace_command` (5/21). Le chiamate Recon del 7 non sono duplicate nei totali delle run che la riusano.
- Nei tre campioni nessun utilizzo di `trace_data_flow`, `trace_code_path`, `get_graph_code_snippet`, `navigate_source`, `run_code` o `inspect_tool_output`. Questo misura l’uso dei tool esposti: non esclude operazioni analoghe svolte dentro comandi generici.
- Campione limitato allo stesso target e alla stessa Recon: non sufficiente per concludere che un tool sia inutile in generale.

## Fonti

- [bookstack-global-glm-limited-20261007-002549 outcome](C:/Users/Alessandro/Desktop/latest-projects/lailaps/storage/app/runs/bookstack/global/bookstack-global-glm-limited-20261007-002549/bookstack-global-glm-limited-20261007-002549-outcome.json)
- [bookstack-global-glm-limited-20261007-002549 log](C:/Users/Alessandro/Desktop/latest-projects/lailaps/storage/app/runs/bookstack/global/bookstack-global-glm-limited-20261007-002549/bookstack-global-glm-limited-20261007-002549-logs.php)
- [bookstack-global-20261015-091141 outcome](C:/Users/Alessandro/Desktop/latest-projects/lailaps/storage/app/runs/bookstack/global/bookstack-global-20261015-091141/bookstack-global-20261015-091141-outcome.json)
- [bookstack-global-20261015-091141 log](C:/Users/Alessandro/Desktop/latest-projects/lailaps/storage/app/runs/bookstack/global/bookstack-global-20261015-091141/bookstack-global-20261015-091141-logs.php)
- [bookstack-global-20261016-075240 outcome](C:/Users/Alessandro/Desktop/latest-projects/lailaps/storage/app/runs/bookstack/global/bookstack-global-20261016-075240/bookstack-global-20261016-075240-outcome.json)
- [bookstack-global-20261016-075240 log](C:/Users/Alessandro/Desktop/latest-projects/lailaps/storage/app/runs/bookstack/global/bookstack-global-20261016-075240/bookstack-global-20261016-075240-logs.php)
