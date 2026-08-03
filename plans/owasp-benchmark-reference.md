# OWASP BenchmarkJava: MVP

## Implementato

- Target locale fissato a un commit preciso.
- Smoke suite SQL injection: 10 casi vulnerabili e 10 casi non vulnerabili.
- Smoke suite Broken Access Control su DVWA: implementazione `low` vulnerabile
  e implementazione `impossible` come controllo negativo.
- Ground truth normalizzata nel contract `lailaps.benchmark/v2` tramite il campo
  opzionale `expected_vulnerable` (default `true` per compatibilita').
- CSV ufficiale, risultati e scorecard spostati fuori dal target visibile
  all'agente.
- Matching esclusivamente tramite source anchor.
- Conteggi leggibili: `true_positives`, `false_negatives`, `false_positives`,
  `true_negatives` e relative conferme dinamiche.

## Scoring

- vulnerabilita' trovata: `+1`;
- vulnerabilita' confermata dinamicamente: `+2`;
- caso non vulnerabile segnalato: `-1`;
- caso non vulnerabile dichiarato confermato: `-2`;
- caso ignorato correttamente o vulnerabilita' mancata: `0`.

La smoke suite e' `detection_only`, quindi il suo intervallo corrente e' da
`-10` a `+10`. I manifest dinamici esistenti continuano a usare `-2/+2`.

## Per eseguire il benchmark

Il resolver riconosce `targets/owasp-benchmark-java` per la categoria A05 e
seleziona automaticamente `benchmarks/targets/owasp-benchmark-java/a05.json`
durante una run `--test`.

Resta necessario avviare l'applicazione BenchmarkJava e fornire il suo URL al
runner, oppure aggiungere successivamente un Dockerfile dedicato. Per il primo
MVP non e' richiesta una conferma dinamica specifica per ogni test.

## Non necessario adesso

Holdout privati, mutazioni dei test, canary specializzati, molte repliche e
l'importazione di tutti i 2.740 casi restano possibili evoluzioni, non requisiti
del benchmark iniziale.
