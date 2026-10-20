# Selezione golden Reader — 20 ottobre 2026

Scelta per il pilot Cloudflare Recon+Reader. Analisi offline degli outcome locali, senza nuove inferenze o modifica delle run. I dati e i checksum sono in [GOLDEN_RUNS.json](GOLDEN_RUNS.json).

## Cacti scelta dall'utente

`gl-230800` corrisponde a [cacti-global-20261016-230800](../../storage/app/runs/cacti/global/cacti-global-20261016-230800/cacti-global-20261016-230800-outcome.json).

- Snapshot target: `6482af547c204199e829b7a0df0b7a13db3e0a58`.
- Reader: `xiaomi/mimo-v2.6-pro`, reasoning `medium`, concorrenza 4.
- Cap Reader aggregato: 500.000 EP; consumo effettivo: **509.640,4176 EP**.
- 15 emissioni `ReaderLead`, **14 accettate**, una respinta; enrichment esclusi.
- **2,747 lead accettate/100k EP**, equivalente normalizzato **13,735/500k**.
- Recon riusata da artifact `01M3NYRPZBBXE8AQM1J9XZR3TZ`, 15 aree iniziali: cinque Reader avviati, dieci non avviati per budget. Due aree chiuse, tre fermate dal budget.

Fonte del numeratore: `report.passes[0].report.structured_outputs`, filtro `output_type=ReaderLead && accepted=true`. Denominatore: `report.telemetry.role_usage.reader.economic_points`; coincide con `report.global_pipeline.reader_used`.

L'intera run è incompleta per una failure Investigator; questo non cancella l'output Reader già persistito. Non usare gli EP totali della run, pari a circa 1,095 milioni, come costo Reader.

Il benchmark accredita automaticamente due casi allo stadio suspected o superiore. Almeno l'abbinamento `CVE-2026-39955` è incompatibile: il manifest descrive SQL injection, ma i finding collegati descrivono access control su alberi/report. La golden è un riferimento storico di prodotti Reader, **non un insieme di TP certificati**.

## YesWiki selezionata

[yeswiki-reader-global-20260929-083528](../../storage/app/runs/yeswiki/reader-global/yeswiki-reader-global-20260929-083528/yeswiki-reader-global-20260929-083528-outcome.json).

| Candidata | Lead accettate | EP Reader | Lead/100k EP | Valutazione |
|---|---:|---:|---:|---|
| 16 ottobre, `201708` | 0 | non disponibili | — | Nessun report utile |
| 16 ottobre, `193041` | 0 | nessun usage Reader | — | Solo Recon: 291.934,656 EP, da non attribuire al Reader |
| **29 settembre, `083528`** | **57** | **1.514.943,4320** | **3,763** | Selezionata: recente, output sostanziale, nessun child tecnicamente fallito |
| 27 settembre, `222851-2` | 1 | 17.100,3143 | 5,848 | Snapshot `running`, troppo piccolo; il rapporto da solo sarebbe fuorviante |
| 25 settembre, `214245` | 13 | 1.193.249,2600 | 1,089 | Stesse tre aree della selezionata, con failure tecniche |
| 23 settembre, `193458` | 75 | 6.240.081,0400 | 1,202 | Snapshot `running`, più vecchio e meno efficiente |
| 21 settembre, `075801` | 45 | 2.320.524,4400 | 1,939 | Più ampio, ma precedente e meno efficiente sul volume grezzo |
| 20 settembre, `225733` | 80 | 4.751.193,7893 | 1,684 | Più ampio, ma precedente e meno efficiente sul volume grezzo |

Sono stati esaminati gli aggregati globali, Reader-global e Recon+Reader recenti; esclusi dai candidati i test sintetici, le run di solo downstream e le run senza produzione Reader. Le altre partenze del 27 settembre hanno output vuoti. Le metriche sopra misurano produttività grezza, non validità semantica.

### Perimetro e contabilità della selezionata

Snapshot target `7325759547611def210a731b103878bd696c419c`; Golden Recon `01M309MYBKSARVPA2HWVTYT450`.
Reader MiMo; concorrenza 3; checkpoint senza Reviewer separato. Il reasoning non è stato ricavato con certezza dal parent esaminato.

| Area | Child | Lead | EP |
|---|---|---:|---:|
| `area-assignment-4`, Controller/API REST | `yeswiki-reader-area-20260929-083534` | 21 | 504.024,9152 |
| `area-assignment-5`, Bazar | `yeswiki-reader-area-20260929-083534-2` | 17 | 502.450,7920 |
| `area-assignment-6`, Rendering/template | `yeswiki-reader-area-20260929-083534-3` | 19 | 508.467,7248 |
| Totale | | **57** | **1.514.943,4320** |

Il cap era **500k per area**, non complessivo. Tutte terminano per budget, senza failure tecniche secondo la scorecard parent. Un enrichment viene conservato ma non eseguito. Il parent contiene 16 aree iniziali, ma solo queste tre sono state selezionate.

Quattro casi sono classificati automaticamente `matched_lead`: `CVE-2026-52762`, `52770`, `52771`, `52775`. Non equivalgono a quattro conferme né dimostrano che tutte le altre lead siano false. La scorecard riporta ancora pending per valutazione semantica e root cause utili. La lettura dei titoli evidenzia anche piste sovrapposte; prima di confrontare qualità occorre riesaminare duplicati e plausibilità.

I pesi EP dei tre child coincidono con quelli della Cacti selezionata: uncached `1.16`, cached `0.0096`, output `0.464`. Somma dei child e parent coincidono, entro la rappresentazione floating point.

## Come usare queste golden

Confrontare le due nuove run con il rispettivo target, senza sommare i risultati fra target. Usare il consumo osservato, non il cap nominale.

Le golden restano fuori dal prompt: non passare al Reader i finding, gli anchor dei manifest o le etichette. Le nuove Recon sono fresche. La baseline Git fissa il codice da cui partiamo oggi, **non ricrea automaticamente il motore che produsse le run storiche**.

Il pilot serve a decidere se approfondire. Differenze di scope, concorrenza, tool e periodo restano dichiarate; il rapporto lead/EP non le corregge. Il risultato va accompagnato da un piccolo riesame semantico degli output, senza pretendere una dimostrazione statistica.
