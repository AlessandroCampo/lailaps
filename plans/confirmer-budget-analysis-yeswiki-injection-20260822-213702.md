# Analisi budget — run yeswiki-injection-20260822-213702

Run: A05 Injection su YesWiki 4.6.5, esito `incomplete`, terminazione `economic_budget_exhausted`.
1 lead sospetta (SQLi `newtextsearch.php`), 0 confermate. Conversione static→dynamic: 0%.

## Fotografia economica della run (pool globale ~1.000.000 pt)

| Ruolo | Richieste | Token totali | Punti | % del pool |
|---|---|---|---|---|
| category_recon | 5 | 31.761 | 19.713 | 2% |
| reader | 8 | 167.699 | 68.139 | 7% |
| **confirmer** | **20** | **1.184.596** | **474.036** | **47%** |
| **worker** | **23** | **1.186.845** | **516.140** | **52%** |
| reviewer | 1 | 19.493 | 22.264 | 2% |

Totale input token della run: 2.484.899 su 57 richieste (mediana input per turno confirmer/worker ≈ 50-90k). Il costo è dominato dall'**input ripetuto del contesto ad ogni turno**, non dall'output.

## Problema 1 — Il confirmer consuma metà pool in analisi statica, senza produrre verifiche

Il confirmer entra con 8 unknown e spende 19 richieste (~474k pt) quasi interamente in:
ragionamento su tokenizzazione/regex di `searchWithLists`, ACL di default, config di install,
seeding SQL, disponibilità di PHP. **Zero chiamate HTTP (per design) e zero micro-esperimenti eseguiti.**

```
[budget] confirmer 1/19  | turno 12,678 in  / 4,810 out | context  8% | rimangono ruolo 257,702, run 889,850 pt
[budget] confirmer 5/19  | turno 48,731 in  /   601 out | context 37% | rimangono ruolo 170,341, run 802,488 pt
[budget] confirmer 10/19 | turno 70,393 in  / 5,333 out | context 55% | rimangono ruolo  81,843, run 713,991 pt
[budget] confirmer 15/19 | turno 88,084 in  /   392 out | context 71% | rimangono ruolo  30,020, run 662,167 pt
```

Tra il turno 5 e il 15 il confirmer spende **~140k pt di pool globale** quasi solo in thinking su
precondizioni verificabili con un probe PHP (comportamento di `preg_quote` + `prepareNeedleForRegexp`).
Il toolbox non ha PHP e il confirmer, dopo aver scoperto `php -v` assente, abbandona l'idea:

```
[confirmer:tool] run_workspace_command(command="php -v 2>&1 | head -3; echo ---; which php")
...
Hmm — running a local PHP snippet in the target container ... PHP is not in the toolbox. Hmm.
```

Non usa mai `run_target_command(files={"probe.php": ...}, argv=["php","probe.php"])`, che era la
via naturale (il target container ha PHP per costruzione). Risultato: il gate dati (nessuna opzione
EnumField con apice) viene stabilito per enumerazione HTTP solo dal **worker**, molto dopo, quando il
budget residuo era <300k pt.

## Problema 2 — La fase terminale del confirmer brucia ~108k pt in richieste che falliscono prima di generare output

A fine fase investigativa il context è all'85% e la compaction viene rifiutata:

```
[budget] confirmer 1/3 | turno 67,220 in / 14,000 out | context 85% | rimangono ruolo 0, run 546,723 pt
[confirmer:model-retry 1/2] UnexpectedModelBehavior: Model token limit (14000) exceeded before any response was generated.
[WARN] compaction model saltato: input oltre il limite del prompt.
```

I tre turni della fase terminale (con max_tokens=14.000 e poi il retry) costano **546.723 → 438.112 pt
di pool globale**, di cui due falliscono con `UnexpectedModelBehavior` *senza produrre alcun output*:

```
[budget] confirmer 1/3 | turno 11,707 in / 14,000 out | context  9% | rimangono ruolo 0, run 507,016 pt
[confirmer:model-retry 2/2] UnexpectedModelBehavior: Model token limit (14000) exceeded before any response was generated.
[budget] confirmer 1/3 | turno 19,785 in / 14,000 out | context 29% | rimangono ruolo 0, run 459,231 pt
[budget] confirmer 2/3 | turno 36,111 in /  2,163 out | context 44% | rimangono ruolo 0, run 438,112 pt
```

Nota: `ruolo 0` (budget ruolo esaurito) ma le richieste partono comunque e **addebitano il pool run**.
Una richiesta rifiutata dal provider prima della generazione non dovrebbe costare ~40k pt.

## Problema 3 — Il reviewer fa 1 richiesta in tutta la run e determina comunque gli esiti (fail-open a catena)

```
[handoff] confirmer reviewer -> confirmer | decisione=terminalize | motivo=Reviewer non disponibile; fallback conservativo ... UsageLimitExceeded: Budget reviewer insufficiente
[handoff] worker reviewer -> worker      | decisione=continue_current | motivo=Continuation fail-open: UsageLimitExceeded: Budget reviewer insufficiente
[handoff] worker reviewer -> worker      | decisione=stop_lead | motivo=Cap economico globale raggiunto; la lead resta suspected.
```

Il reviewer ha un pool talmente piccolo che completa solo 1 valutazione (redirect, corretta).
Tutte le decisioni successive sono fallback fail-open presi *senza* valutazione. In particolare
`terminalize` (fallback) ha chiuso il confirmer mentre il worker avrebbe ancora potuto eseguire
il differenziale con ~300k pt residui.

## Problema 4 — Il worker eredita 4 unknown e ri-deriva l'analisi statica del confirmer

L'handoff confirmer→worker passa la lead con `unknown=4` e il worker ri-legge forms, liste,
SearchManager e ACL già analizzati dal confirmer (duplicazione di ~516k pt di spend worker,
in parte inevitabile ma amplificata dal non aver risolto gli unknown via probe).

```
[handoff] confirmer -> worker | lead=lead-1 | stato=suspected | unknown=4
```

## Problema 5 — Ultime richieste del worker: output cap troppo basso + context alto = richieste rifiutate

```
[budget] worker 1/9 | turno 79,322 in / 5,500 out | context 62% | rimangono ruolo 92,554, run 92,554 pt
[worker:model-retry 1/2] UnexpectedModelBehavior: Model token limit (5500) exceeded before any response was generated.
[budget] worker 1/9 | turno 88,320 in / 50 out | context 75% | rimangono ruolo 4,134, run 4,134 pt
[worker:tool] http_call(... phrase=bonjour ...)   # baseline eseguita, ma nessun turno sopravvive per leggerla
[worker:model-retry 2/2] UnexpectedModelBehavior: Model token limit (5500) exceeded before any response was generated.
[handoff] worker reviewer -> worker | decisione=stop_lead
```

Il differenziale exploit/baseline **non viene mai eseguito**: la chiamata HTTP finale non è mai
elaborata da un modello perché entrambi i turni successivi vengono rifiutati.

## Proposte di piano

1. **Non addebitare (o rimborsare) i turni falliti con `UnexpectedModelBehavior` "before any response was generated"**. Nella sola run questi turni hanno bruciato ~90-110k pt a vuoto.
2. **Retry automatico con max_tokens ridotto e/o compaction forzata** quando il provider rifiuta per limite: oggi il retry ri-sottomette lo stesso prompt e fallisce identico.
3. **Trigger di compaction anticipato** (es. al 60-70% di context, non all'85%): la compaction rifiutata ha reso il confirmer irrecuperabile. Verificare anche il limite del modello di compaction.
4. **Pool riservato al reviewer** indipendente dal pool run: le decisioni `terminalize`/`continue`/`stop` prese in fail-open hanno chiuso la run con ~300k pt ancora spendibili dal worker.
5. **Cap di share per ruolo** (es. confirmer max 30% del pool) con handoff obbligatorio al worker quando gli unknown residui richiedono HTTP o esecuzione: il confirmer ha speso il 47% senza mai produrre evidenza dinamica.
6. **Handoff più ricco**: ridurre gli `unknown=4` passando al worker anche le conclusioni statiche già stabilite (es. "nessuna opzione enum con apice trovata nel seeding") per evitare ri-derivazione.
7. **Budget per output cap coerente col context**: con context >60% e cap 5.500 il provider rifiuta; calcolare max_tokens dinamico in funzione del context residuo.
