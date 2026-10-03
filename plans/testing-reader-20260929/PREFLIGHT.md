# Preflight completato — 29 settembre 2026

- Piano ridotto a due nuove discovery: YesWiki aree 4/5/6 e Cacti da nuova Recon.
- Launcher PowerShell analizzato senza errori; helper PHP valido e lookup read-only verificato sulla Recon Cacti storica.
- `Start-ReaderRound.ps1 -Target yeswiki -PreflightOnly`: OK.
- `Start-ReaderRound.ps1 -Target cacti -PreflightOnly`: OK.
- Docker Desktop avviato; build `lailaps-pentest-agent:dev` completata. Deduper nel container: 1.1.0.
- Checkout Git indipendenti, senza alternates, ai commit dei manifest: entrambi verificati puliti dall'host e dal verificatore del semantic evaluator nel container. Impostazione autocrlf locale coerente tra Windows e Linux.
- Suite `test_reader_evaluation.py`: 27 passed. Solo warning di scrittura cache pytest; nessun test fallito.
- Postprocessing PHP/container in modalità metrics sulla baseline MiMo: 3 child recuperati, nessuno mancante, 34 lead e 1 enrichment. Discovery osservata 404956.9408 EP. Parent storico parziale, quindi serie sempre segnalata provvisoria.
- Diagnostica cap con risposte simulate pass: sui 34 output MiMo, zero input_cap dopo il fix (prima 30); sul vecchio corpus DS di 54 output, 20 inconclusive perché anche l'indice completo supera il cap, prima 52. Questo secondo corpus è solo stress test offline: nessun nuovo confronto di modelli né inferenza DS.

Fix preparatori: stima input coerente col runtime e recupero su richiesta degli originali quando il pacchetto completo eccede il guard; block/partial senza originali letti sono conservati come inconclusivi. Riuso della dedup runtime nell'evaluator validando payload e source reference, senza richiedere che timestamp/envelope del collector siano già presenti nell'artifact runtime.

Limite noto da osservare nel round Cacti: con registri molto grandi anche l'indice completo può superare 24k stimati. Il deduper conserva le proposte come inconclusive, non sopprime contenuto e non certifica unicità. Non è stato nascosto aumentando il cap. I costi e la qualità semantica richiedono la run reale.

Nessuna chiamata inferenziale o run a pagamento eseguita. GET read-only del catalogo endpoint OpenRouter per verificare disponibilità e supporto tools/structured output; nessuna misura di qualità da tale controllo.

Le chiamate paid iniziano soltanto lanciando lo script senza `-PreflightOnly`. Il launcher include evaluator e, per YesWiki, postprocessing degli artifact MiMo storici. Ground truth ancora da costruire dopo revisione.
