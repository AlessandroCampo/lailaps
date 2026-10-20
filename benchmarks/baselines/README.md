# Baseline per il rewrite

## Riferimento operativo corrente

Il riferimento C0 comprende due parti da leggere insieme:

1. [Snapshot Python del 7 ottobre 2026](python-c0-20261007/README.md): artifact originali e metriche storiche, verificabili offline.
2. [Ground truth manuale](python-c0-20261007-ground-truth.json): annotazioni autorizzate dall'utente, vincolate all'hash del manifest originale.

| Target | Ground truth operativa | Stato storico persistito |
|---|---|---|
| YesWiki | Disposizioni LLM conservate nell'outcome: 1 confermato, 3 sospetti, 1 respinto/inconclusivo. | Coincide con il riferimento. |
| BookStack | **4 conferme su 5 lead**, come indicato dall'utente. | 0 confermati, 4 sospetti; 4 lead ammesse, 5 lead grezze. |

Le annotazioni prevalgono **solo nel loro scope** per la valutazione di qualità. Non sostituiscono benchmark, costi, tempi o contatori storici. Per BookStack la disposizione della quinta lead e la mappatura individuale delle quattro confermate sono ancora non specificate: non assegnare verdict per ID deducendoli dal totale.

Il 4/5 è un riferimento manuale, non una misura ricostruita dalle ultime 30 righe del log ispezionato. Un eventuale log o mapping precisato dall'utente può completare l'annotazione senza riscrivere i byte degli artifact Python.

La verifica dello snapshot resta:

```powershell
node scripts/freeze-python-c0.mjs verify benchmarks/baselines/python-c0-20261007
```

Il comparator TS dovrà applicare anche le annotazioni e distinguere parità del ragionamento, stato tecnico e qualità delle prove. Il solo `metrics.json.totals.confirmed` storico non è la ground truth BookStack aggiornata.
