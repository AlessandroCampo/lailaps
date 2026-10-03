# P0 semplificato — Reader, deduper e benchmark su YesWiki e Cacti

29 settembre 2026. Sostituisce la precedente matrice di quattro run.
Nessuna run a pagamento avviata durante la preparazione.

## Scelta

Due nuove run di discovery, senza Confirmer, Worker o vecchio Reviewer.
Niente confronto tra modelli/provider, varianti di contesto, repliche o annotazione
preventiva dell'intero corpus. Il primo round serve anche a costruire il riferimento
semantico corretto per i benchmark successivi.

| Target | Discovery | Enrichment | Confronto |
|---|---|---|---|
| YesWiki | Golden precedente, aree 4 API, 5 Bazar, 6 rendering | Raccolti e deduplicati nel postprocessing; non eseguiti | Nuovo MiMo contro MiMo storico, stessi assignment |
| Cacti | Nuova Recon globale, poi Reader su tutte le aree canoniche | Pass/partial possono accodare nuove aree attraverso deduper | Prima baseline dell'intero flusso |

Reader: `xiaomi/mimo-v2.6-pro`, provider Xiaomi, self-checkpoint.
Deduper: `z-ai/glm-5.3-flash`, provider InferenceNet.
Valutatore semantico: stessa configurazione GLM, ruolo e budget separati.
Recon Cacti: GLM 5.3 Flash, una sola ripetizione, routing della configurazione Recon
esistente; registrarne il provider osservato. Non introdurre un confronto di routing.
Le disponibilità dei due provider sono state verificate sul catalogo OpenRouter;
non è una prova di qualità né una chiamata inferenziale.

## Esecuzione

### YesWiki: confronto diretto

Usare la Golden `01M309MYBKSARVPA2HWVTYT450`, aree `area-assignment-4/5/6`.
Non normalizzare di nuovo gli assignment iniziali: cambiarli romperebbe il confronto.
Eseguire Reader con `--defer-enrichments`, poi deduper sulle lead e sugli enrichment,
poi evaluator sulle canoniche. Le decisioni su enrichment non vengono confrontate
con una Recon seminata nel postprocessor: il test completo task/Recon è Cacti.

Processare con gli stessi ruoli anche gli artifact MiMo storici, senza rilanciare
Reader: parent `yeswiki-reader-global-20260927-225341`, child `20260927-225345*`.
Il parent storico è parziale: recuperare i tre child dalla sua directory fixture
conservata, mostrare lo stato provvisorio e non inventare tempi parent completi.
Sono presenti 34 ReaderLead accettate nella serie storica.

Confrontare output grezzi/canonici, ipotesi conservate, hit semantici, consumi e errori.
Stesse aree e stesso modello permettono continuità; una sola run e runtime diversi
non dimostrano un effetto causale del deduper sulla capacità del Reader.

### Cacti: flusso completo

1. Nuova Recon globale con assignment e `--repetitions=1`.
2. Recuperare l'artifact accettato di quella precisa run, senza usare la Golden o
   un generico “ultimo artifact”. Se Recon fallisce, arrestare la sequenza.
3. Reader su tutte le aree normalizzate dalla Recon, senza `--area` e senza
   `--defer-enrichments`. Il coordinatore deduplica ogni proposta di enrichment
   contro il lavoro già noto prima dell'eventuale accodamento.
4. Deduplica finale delle lead nel parent; evaluator riusa questo `dedup.json`.
   Nessuna seconda deduplica a pagamento. Nessun Confirmer viene avviato.

“Tutte le aree” significa tutte le domande canoniche: il deduper può eliminare
assignment interamente coperti e conservare o derivare le parti nuove.
Registrare separatamente proposte, pass/block/partial, inconclusive, nuove aree
dispatchate, integrazioni pending e arresti per limite. Un pass inconclusivo può
conservare/accodare lavoro: non è certificazione di unicità.

## Budget e limiti

Concorrenza 3; 500.000 EP per assignment; timeout 7.200 s per assignment.
Contesto Reader 1.048.576, guard input 900.000, come nella serie MiMo precedente.
Deduper: budget cumulativo indipendente, guard stimato 24k input, output massimo
4096, massimo due richieste per decisione incluso un recupero raggruppato.
Il caso senza precedenti passa senza richiesta. Nessuna nuova struttura di budget.

| Fase | Budget nominale |
|---|---:|
| Reader YesWiki | 1.500.000 EP, tre aree |
| Deduper YesWiki nuovo / storico | 250.000 EP ciascuno |
| Evaluator YesWiki nuovo / storico | 250.000 EP ciascuno |
| Reader Cacti | 500.000 EP per area, massimo 48 aree = 24.000.000 EP |
| Deduper Cacti, Recon/task/enrichment/lead complessivi | 1.000.000 EP |
| Evaluator Cacti | 1.000.000 EP |

La Recon ha il proprio budget configurato e timeout 1200 s. Il default applicativo
è 640.000 EP; registrare il valore effettivo della run e sommarne il consumo al totale
Cacti. Il report Reader corrente tratta Recon come riusata: non usare quel subtotale
come costo dell'intera sequenza Cacti. Con 21–23 aree, come nelle Recon Cacti storiche,
il solo funding Reader sarebbe 10,5–11,5M EP, prima di nuovi enrichment.
I limiti sono envelope, non garanzie di spesa esatta: riportare overshoot e usage
incompleta. Non aspettarsi costi simili a YesWiki.

## Ground truth dopo la run

Sì: prima esecuzione senza etichette attese, poi revisione semantica. Non occorre
classificare prima tutte le lead, né costruire tutte le coppie possibili.

Il launcher genera `review-queue.json` con giudizi proposti, riferimenti stabili e
campi di correzione vuoti. Rimane esplicitamente `provisional_not_ground_truth`.
Revisionare tutti i block, partial e inconclusive; almeno 10 pass per serie, o tutti
se meno di 10, cercando anche duplicati residui tra lead passate. La selezione per
hash è riproducibile; aggiungere i casi sospetti emersi leggendo il registro.
Per un block verificare che non perda evidenza o condizioni nuove; per un partial
verificare il contenuto derivato e la lineage, non solo l'etichetta.

Per il judge verificare hit, non-match, incerti e lead fuori catalogo, con evidenze
del Reader e manifest. Lettura di codice non equivale a ipotesi corretta; un hit
Reader non equivale a vulnerabilità confermata. Gli anchor restano diagnostici.

Solo le decisioni effettivamente revisionate diventano ground truth v1, con rationale,
originali, snapshot, ordine/stato del registro e hash del giudizio. Mantenere uncertain
dove il caso non è decidibile. Le etichette non revisionate restano proposte.
Non usare il giudizio del medesimo GLM come sua validazione automatica. Questo primo
round costruisce il riferimento; il punteggio sul corpus corretto è regressione,
non una misura indipendente di accuratezza. Tenere nuovi casi futuri fuori dal tuning.

## Cosa leggere nel report

- Deterministico: completamento/errore tecnico, richieste, tool error, token/cache,
  EP e USD separati per Reader, Recon, deduper, evaluator; latenza e stato copertura.
- Semantico: canoniche e duplicati residui, falsi block, contenuto perso dai partial,
  hit/miss/incerti per caso, qualità delle ipotesi fuori catalogo.
- Misto: lead uniche e hit per 100k EP o USD, con numeratore semantico revisionato
  e denominatore economico esplicito; evitare di trattarlo come dato puro deterministico.
- Breve commento sul lavoro dell'agente, fondato su esempi: cosa ha identificato,
  perché ha perso casi, dove ha speso senza aggiungere informazioni. Non un voto generico.

Qualunque falsa soppressione dimostrata o perdita nei partial blocca la promozione
al percorso Confirmer. Esaminare duplicati residui e quota inconclusive. Circa 5%
del costo discovery è un obiettivo iniziale di overhead, non una soglia dimostrata.
Se il registro intero supera il guard, il risultato resta inconclusivo: aumentare
silenziosamente il budget o certificare una comparazione parziale non è ammesso.
Nessuna replica automatica; proporla dopo solo per una differenza o anomalia concreta.

## Preparazione e lancio

Preparati: checkout puliti ai commit di manifest, verifica Golden e mapping storico,
launcher, esportazione coda di revisione; test contrattuali senza provider.
Il preflight ha richiesto due fix: stima input/lazy read degli originali del deduper,
e compatibilità tra proiezione runtime e collector per riusare dedup senza ripagarla.
Payload e source reference restano verificati integralmente.

Il launcher esegue anche il postprocessing semantico a pagamento: per YesWiki include
il replay degli artifact storici. Non avvia Confirmer. Usare le due righe separatamente:

```powershell
cd C:\Users\Alessandro\Desktop\latest-projects\lailaps
.\plans\testing-reader-20260929\Start-ReaderRound.ps1 -Target yeswiki
.\plans\testing-reader-20260929\Start-ReaderRound.ps1 -Target cacti
```

Aggiungere `-PreflightOnly` per i soli controlli locali, senza inferenza.
Output in `storage/app/reader-evaluation/p0-<target>-<timestamp>/`.
Ogni run conserva il comando, il parent selezionato, report, scorecard, collection,
dedup, semantic ledger e coda di revisione. I file denominati confirmer-handoff sono
soltanto export: non avviano uno stadio Confirmer.

Se un comando fallisce, il launcher si arresta e conserva gli artifact. Riprendere
solo lo stadio necessario tramite `benchmark:reader-evaluate` sul parent salvato;
non rilanciare discovery per correggere un problema di postprocessing.
Il dettaglio delle verifiche completate è in `testing-reader-20260929/PREFLIGHT.md`.
