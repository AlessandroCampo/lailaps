# Report offline Reader YesWiki post-fix pricing

Data analisi: 2026-09-11

## Perimetro e affidabilita

Il report e ricostruito esclusivamente dagli `outcome.json` e dai `logs.php` persistiti per le run avviate dopo il fix al pricing di `deepseek/deepseek-v4.1-flash`. Non sono stati rilanciati benchmark.

Sono sopravvissute due run valutate per categoria. Tutte e quattro hanno `benchmark.artifact_state=final`, `benchmark.run_state=completed` e score `provisional`. La terza ripetizione richiesta non e mai partita in entrambe le categorie.

Esiste anche lo stub `yeswiki-reader-security-misc-20260911-074955`, privo di report e benchmark: non e conteggiato come run.

`report.outcome=incomplete` non indica un processo ancora attivo: il Reader ha terminato per `economic_budget_exhausted`. La valutazione benchmark e comunque presente e completa (`metrics_partial=false`).

## Risultati per run

| Run | Score | File recall | Anchor recall | TP / FP / FN | Lead (unmatched) | Token | Request | Tool call | Economic points |
|---|---:|---:|---:|---:|---:|---:|---:|---:|---:|
| Security 07:49 | 13.33 (2/15) | 33.3% | 33.3% | 1 / 0 / 2 | 7 (6) | 2,502,786 | 123 | 144 | 351,416 |
| Security 08:46 | 6.67 (1/15) | 33.3% | 33.3% | 0 / 0 / 3 | 9 (9) | 1,977,294 | 112 | 101 | 348,447 |
| Injection 07:50 | 10.67 (3.2/30) | 50.0% | 33.3% | 1 / 0 / 5 | 8 (7) | 2,783,345 | 136 | 164 | 351,016 |
| Injection 09:03 | 14.00 (4.2/30) | 66.7% | 50.0% | 1 / 0 / 5 | 7 (6) | 2,593,432 | 146 | 149 | 349,804 |

Medie delle due run sopravvissute:

| Categoria | Score | File recall | Anchor recall | Recall finding | Lead (unmatched) | Token | Request | Tool call |
|---|---:|---:|---:|---:|---:|---:|---:|---:|
| Security misconfiguration | 10.00 | 33.3% | 33.3% | 16.7% | 8.0 (7.5) | 2,240,040 | 117.5 | 122.5 |
| Injection | 12.33 | 58.3% | 41.7% | 16.7% | 7.5 (6.5) | 2,688,389 | 141.0 | 156.5 |

## Copertura dei casi

Security:

- `CVE-2026-52773`: una detection `suspected` su due; nella seconda run viene raggiunto l'anchor ma non viene emessa una lead corrispondente.
- `CVE-2026-52772` e `CVE-2026-52774`: mai raggiunti.

Injection:

- `CVE-2026-52763`: trovato come `suspected` in entrambe le run; e l'unico vero positivo stabile.
- `CVE-2026-52771` e `CVE-2026-52775`: copertura del codice migliorata nella seconda run, senza trasformazione in finding.
- `CVE-2026-52770`: raggiunto solo a livello file nella seconda run.
- `CVE-2026-52762` e `CVE-2026-52778`: mai raggiunti.
- Caso negativo `yeswiki-tagrss-non-exploitable`: anchor raggiunto nella seconda run, ma nessuna disposizione emessa; il controllo negativo non e stato risolto.

## Uso dei nuovi tool

| Categoria/run | Semgrep `list_surface_signals` | Joern `trace_data_flow` | Grafo codebase |
|---|---:|---:|---:|
| Security 07:49 | 9 | 0 | 2 chiamate (search + snippet) |
| Security 08:46 | 6 | 0 | 0 |
| Injection 07:50 | 8 | 0 | 3 search |
| Injection 09:03 | 12 | 0 | 0 |

L'inventario Semgrep e stato realmente consultato tramite `list_surface_signals` in tutte le run (media 7.5 chiamate in Security e 10 in Injection). Il sensore persistito risulta `ready`, Semgrep `1.175.0`, senza errori e servito dalla cache. Questo dimostra utilizzo dell'inventario, non causalita: i log non consentono di attribuire automaticamente il finding corretto a un segnale Semgrep specifico.

Joern era `enabled=true` e `available=true`, con backend configurato alla versione `4.0.592`, ma `trace_data_flow` ha ricevuto zero chiamate. Non risultano errori Joern: il modello non lo ha scelto. Le chiamate `search_code_graph` appartengono al grafo Codebase Memory e non vanno conteggiate come utilizzo Joern.

## Efficienza del nuovo modello

Il pricing e ora riconosciuto come reale: pesi DeepSeek `uncached=0.4`, `cached=0.008`, `output=0.32`. Le quattro run consumano circa 350k economic points ciascuna. Il modello converte quindi lo stesso budget economico in molti piu token e piu esplorazione.

La cache del Reader e significativa (circa 58-72% a seconda della run), ma l'efficienza qualitativa per token e debole:

- Security: 2.24M token medi per 0.5 TP; una run non produce alcun TP.
- Injection: 2.69M token medi per 1 TP; 6.5 lead su 7.5 restano unmatched.
- L'aumento di lead non produce un aumento proporzionale dei veri positivi.

Il costo USD stimato e circa $0.15-$0.16 per run, ma `cost_accounting_complete=false` e `provider_cost_usd=null`: alcune richieste non hanno usage provider completo. Gli economic points e i token sono piu affidabili del totale USD.

## Confronto con i riferimenti precedenti

Rispetto alle tre run DeepSeek pre-fix pricing:

- Security: score medio 10.00 contro 6.67 (+50%); recall finding 16.7% contro 11.1%. I token aumentano da circa 0.69M a 2.24M (+226%).
- Injection: score medio 12.33 contro 9.78 (+26%); anchor recall 41.7% contro 27.8%, ma il recall finding resta identico al 16.7%. I token aumentano da circa 0.68M a 2.69M (+295%).

Questo conferma che il pricing corretto permette molta piu ricerca e migliora la copertura, ma il miglioramento di detection e modesto: in Injection il numero di TP non cambia.

Rispetto al riferimento GLM Flash:

- Injection, con signature compatibile: DeepSeek resta sotto (12.33 contro 20.00; recall 16.7% contro 38.9%), pur usando circa il 22% di token in meno.
- Security, confronto solo direzionale per signature/oracle differente: DeepSeek ottiene 10.00 contro 20.00 del singolo riferimento GLM valido.

I vecchi risultati GLM Injection erano artefatti `partial/failed`; il confronto e utile come indicazione, non come verdetto statistico definitivo.

## Problemi osservati nei log

- Tutte le run terminano per esaurimento del budget economico, non per completamento naturale della copertura.
- Tre run riportano anche un errore API OpenRouter del Reader esaurito dopo i retry.
- Tutte le run riportano almeno un errore del Reviewer per limite output di 2,000 token.
- L'error rate esplorativo e particolarmente alto in Injection: 29.9% e 21.1%.
- Le compaction sono numerose: 13-18 per run.

## Conclusione

Il P0 tecnico e funzionante per disponibilita dei tool e pricing. Semgrep viene usato spontaneamente; Joern no. Il nuovo modello, con pricing corretto, esplora di piu e migliora soprattutto la reach, ma non ha ancora dimostrato un miglioramento convincente della qualita finale: produce molte ipotesi unmatched e resta nettamente sotto il riferimento GLM nella categoria Injection.

La prossima prova ad alto ROI dovrebbe isolare le variabili con almeno tre run complete per configurazione: stesso modello con Semgrep/Joern disabilitati, solo Semgrep, e Semgrep+Joern. Senza un'ablation, dai log attuali non e possibile attribuire il piccolo incremento di score a Semgrep, al maggiore budget effettivo o alla varianza del modello.
