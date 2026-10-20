# Cloudflare Reader pilot — piano semplificato

Aggiornato il 20 ottobre 2026 dopo la scelta dell'utente: **P0 Recon + Reader; P1 Investigator completo solo se la discovery è promettente.**

Questo piano sostituisce il precedente disegno A/B/C end-to-end. Restano valide l'[analisi del codice](REVIEW.md) e le skill adattate; l'integrazione nella pipeline completa è rinviata.

**Prossimo incremento Reader:** [READER_PROGRESSIVE_PLAN.md](READER_PROGRESSIVE_PLAN.md)
definisce metodo/catalogo nel prompt e knowledge tecnica accessibile tramite
`read_skill`. Per questa evoluzione sostituisce il caricamento fisso del Passo 2
qui sotto; conserva Recon, riuso degli artifact, concorrenza e budget attuali.
L'incremento è implementato come `progressive_v1` e lascia l'Investigator fuori
dallo scope; il benchmark agentico resta manuale. Le specifiche di seguito sul
caricamento fisso descrivono il primo pilot e sono superate per il Reader.

**P0 Investigator separato:** [INVESTIGATOR_P0_PLAN.md](INVESTIGATOR_P0_PLAN.md)
prepara l'estensione al ruolo unificato e il replay delle 11 lead Cacti della run
`cacti-recon-reader-global-20261020-173047`, fissate in
[INVESTIGATOR_INPUT.json](INVESTIGATOR_INPUT.json). Implementato nel perimetro
ristretto: briefing e topic incrementali, nessun nuovo requisito di validazione.
Comandi in [INVESTIGATOR_RUN.md](INVESTIGATOR_RUN.md); non modifica il confronto
Reader e non avvia run agentiche. Le indicazioni Investigator originarie sotto
sono superate da questo P0.

**Aggiornamento dopo il primo pilot:** per la prossima comparazione usare quattro
Reader concorrenti sullo stesso cap aggregato 500k e riusare Recon/briefing ready
quando disponibili. La Cacti `cacti-recon-reader-global-20261020-144734` è stata
verificata ed è riutilizzabile nonostante il timeout successivo del Reader.
I comandi aggiornati sono in [RUN.md](RUN.md); le indicazioni seriali sotto
descrivono la prima implementazione, superata dalla modalità Reader-only del pool.

## 1. Baseline già fissata

Codice attuale committato e pushato su `origin/codex/cloudflare-skills-test`:
[`46160f362053c612d9e6a1b8d456b6e98fd4ca2c`](https://github.com/AlessandroCampo/lailaps/commit/46160f362053c612d9e6a1b8d456b6e98fd4ca2c).

Il commit include le modifiche applicative preesistenti e i file necessari al runtime, compreso l'archivio training. Il test Cloudflare non è ancora implementato. Restano locali i backup sotto `work/`, export di benchmark, prototipi e altri file non pertinenti: non sono necessari a eseguire questa baseline.

Destinazione: **Lailaps-1**, `C:\Users\Alessandro\Desktop\latest-projects\lailaps`. Non usare `lailaps-2`.

## 2. Due riferimenti storici, due nuove run

Le golden sono **riferimenti di confronto**, non ground truth di vulnerabilità e non Recon da riutilizzare come input. Le nuove run devono esercitare anche il planner Cloudflare.

| Target | Golden selezionata | Lead Reader accettate | EP Reader effettivi | Lead / 100k EP | Equivalente / 500k EP |
|---|---|---:|---:|---:|---:|
| Cacti | `cacti-global-20261016-230800` | 14 | 509.640,4176 | 2,747 | 13,735 |
| YesWiki | `yeswiki-reader-global-20260929-083528` | 57 | 1.514.943,4320 | 3,763 | 18,813 |

Selezione, checksum, snapshot target, child e candidati alternativi sono in [GOLDEN_RUNS.json](GOLDEN_RUNS.json); le motivazioni sono in [GOLDEN_SELECTION.md](GOLDEN_SELECTION.md). Gli outcome originali rimangono locali sotto `storage/app/runs`, esclusi da Git.

**Perché YesWiki del 29 settembre:** è la più recente aggregazione Reader sostanziale disponibile, senza child falliti tecnicamente. Migliora la produttività grezza rispetto al 25 settembre sullo stesso sottoinsieme (57 contro 13 lead; 3,76 contro 1,09 per 100k EP). Le run del 16 ottobre non producono risultati Reader utili. Il singolo risultato parziale del 27 settembre non è una baseline affidabile solo perché mostra un rapporto elevato.

Il limite è concreto: YesWiki esaminava soltanto API, Bazar e rendering, con **500k per area**, tre aree, tre Reader concorrenti. Cacti aveva cap aggregato 500k, quattro Reader concorrenti e downstream attivo. Le nuove run useranno Recon fresca e un Reader seriale su tutto il target. Il confronto è un **pilot storico di produttività**, non un A/B controllato; normalizzare gli EP non elimina queste differenze.

## 3. P0: integrazione minima sul percorso già esistente

Riutilizzare `benchmark:recon-reader` → `pentest recon --global --with-reader`.
Questo percorso ha già ReconPlan, briefing, incarichi, self-checkpoint e arresto prima del downstream. Non modificare `GlobalPipeline`, `scan`, `multi_category.py` o il lifecycle Investigator per questo P0.

### Passo 1 — Un flag e un cap esplicito

Proporre due opzioni sul solo comando Recon+Reader:

- `--cloudflare-hunting`, booleano opt-in: cambia la metodologia Recon e aggiunge la knowledge Reader;
- `--reader-points=500000`: cap **aggregato del solo Reader**, comprendente i self-checkpoint; non per incarico.

Le opzioni sono implementate nel branch `codex/cloudflare-skills-test`. Senza flag/punti espliciti il comando attuale conserva il comportamento.

File:

- [`BenchmarkReconReader.php`](../../app/Console/Commands/BenchmarkReconReader.php): accettare e inoltrare le opzioni, riportarle nei metadati.
- [`cli.py`](../../agent/pentest-agent/src/pentest_agent/cli.py), funzione `recon_only`: accettare le opzioni, validare che il profilo richieda `--global --with-reader`, passare il booleano nelle dipendenze.
- [`deps.py`](../../agent/pentest-agent/src/pentest_agent/deps.py): un solo flag interno, senza campi nuovi nei contratti del modello.

Per il cap riusare `BudgetBroker.reader_only_discovery` e `discovery_limit`, già presenti in [`budget.py`](../../agent/pentest-agent/src/pentest_agent/budget.py), come fa la pipeline globale. Configurarli nell'orchestratore del comando prima dell'esecuzione: nessun nuovo conto o riserva per incarico. La Recon mantiene il cap di ruolo e consuma il cap complessivo esistente, ma **non erode i 500k Reader**. Il cap totale configurato deve poter finanziare entrambi; validare la combinazione prima di partire.

Conservare admission, pending usage, chiusura e gestione dell'overshoot esistenti. Riportare EP effettivi e overshoot, senza troncarli artificialmente a 500.000.

### Passo 2 — Caricare le skill locali

Asset già pronti:

| Asset | Uso |
|---|---|
| [Recon planner](skills/recon-planner/SKILL.md) | Sostituisce la metodologia del prompt Recon sperimentale |
| [Reader](skills/reader/SKILL.md) | Integra la discovery soft |
| [Prospettive comuni](skills/references/attack-perspectives.md) | Conoscenza Reader |
| [Web/browser](skills/references/web-and-browser.md) | Conoscenza Reader per i due target |
| [Investigator](skills/investigator/SKILL.md) | **P1, non caricare nel P0** |

Copiare l'albero `skills/` in `agent/pentest-agent/src/pentest_agent/knowledge/cloudflare/`, con [licenza](LICENSE.cloudflare) e [manifest upstream](UPSTREAM.json) nello stesso root. Gli asset sono del runtime Pydantic AI, non skill da installare nella configurazione personale Codex.

In [`triple_agent.py`](../../agent/pentest-agent/src/pentest_agent/triple_agent.py), `_role_system_prompt`:

1. con il flag, usare il corpo di `recon-planner/SKILL.md` nel ramo Recon;
2. nel ramo Reader mantenere il contratto assignments attuale e aggiungere corpo Reader + due riferimenti, in ordine fisso;
3. conservare scope, tool, output, note e budget Lailaps.

Un caricamento fisso dei file inclusi nel package è sufficiente. Non aggiungere tool di retrieval, selector, registry o dipendenze; non leggere path controllati dal target. Verificare packaging e file presenti prima dell'inferenza. Il set Reader misura circa 11.500 caratteri; nessun caricamento di tutta la repo Cloudflare.

I documenti upstream selezionati contengono anche istruzioni incompatibili, quindi usare gli adattamenti forniti. Nessun download da `main` o durante le run.

### Passo 3 — Riutilizzare il briefing e gli output

Mantenere `ReconPlan(briefing, assignments, coverage_notes)`, `ReconAssignment(title, paths, next_check)` e `normalize_recon_plan` senza nuovi campi. Sottosistema, ipotesi/focus e prima verifica entrano nella narrativa di `next_check`.

Il briefing rimane dentro la Recon già persistita e raggiunge il Reader attraverso il dossier esistente. **Nessun nuovo file architecture del target, nessuna cache di briefing, nessun servizio di preparazione.** I sorgenti e Semgrep sono sufficienti; CBM resta disabilitato e OSV fuori scope.

Conservare lead soft, esplorazione fuori focus e `AreaEnrichmentLead`; il parent/ledger corrente continua a gestire gli incarichi. Non aggiungere Reviewer, Judge, subagenti o validator semantici.

Nel report corrente aggiungere soltanto provenienza interna dell'esperimento: flag, hash dei testi caricati, commit motore, cap Reader e snapshot target. Conservare questi dati anche nel report parziale. Non duplicare il briefing in un nuovo DTO.

### Passo 4 — Verifica offline e architettura

Estendere [`test_recon_reader_assignments.py`](../../agent/pentest-agent/tests/test_recon_reader_assignments.py) per verificare:

- flag spento invariato; flag acceso con gli stessi output e permessi;
- ReconPlan e briefing ancora consegnati al Reader;
- budget Recon separato dal cap aggregato Reader e self-checkpoint conteggiati;
- nessuna chiamata Confirmer/Worker; chiusure, lead e enrichment conservati;
- asset disponibili dal package e metadati negli output parziali.

Verificare opzioni/argv in [`BenchmarkReconReaderCommandTest.php`](../../tests/Unit/Pentest/BenchmarkReconReaderCommandTest.php), riusando i pattern esistenti. Solo modelli fake e mock, senza provider. Nessun lint globale.

Aggiornare brevemente [`ARCHITECTURE.md`](../../ARCHITECTURE.md) quando il runtime viene modificato: flag, knowledge e uso del cap Reader separato. Non anticipare l'Investigator P1.

## 4. Esecuzione del pilot dopo l'implementazione

**Una nuova run Cacti e una nuova run YesWiki**, entrambe Recon fresca + Reader seriale, cap Reader 500.000 EP complessivi, `--repetitions=1`. Nessuna matrice A/B/C, nessuna nuova baseline live o batch da 18 run.

- Usare gli stessi snapshot target delle golden riportati nel manifest.
- Usare Reader `xiaomi/mimo-v2.6-pro` in entrambe, coerente con le golden; Recon sullo stesso modello per avere una configurazione unica. Fissare e registrare il reasoning prima della partenza; proposta `medium`, come la golden Cacti. Il reasoning della golden YesWiki non è stato stabilito dal suo aggregato.
- Registrare provider effettivo, pesi EP, contesto e commit. I pesi Reader storici coincidono: uncached `1.16`, cached `0.0096`, output `0.464`. Se i pesi correnti differiscono, ricalcolare anche una metrica comparabile dagli usage usando questi pesi, senza alterare il budget runtime.
- Usare la vista sorgente sanitizzata del benchmark. Le golden e i manifest oracle rimangono fuori dal contesto degli agenti.
- Usare workspace-only per questo test statico, salvo capacità già necessarie al target; segnalare differenze dai tool/runtime storici. Non introdurre provisioning nuovo per il pilot.
- Il comando congiunto ha già un timeout: conservarlo dichiarato e classificare eventuale interruzione; non prolungare automaticamente o rilanciare fino a ottenere un risultato migliore.

Il runtime del pilot è implementato; le due run si lanciano manualmente seguendo [RUN.md](RUN.md). Nessuna run agentica è stata eseguita durante l'implementazione.

## 5. Confronto proporzionale semplice

Per ciascun target, separatamente:

```text
yield = lead Reader accettate / EP Reader effettivi × 100.000
variazione = (yield nuova / yield golden − 1) × 100
equivalente a 500k = yield × 5
```

Contare ogni prodotto originale una sola volta; escludere enrichment, checkpoint, emissioni respinte, output downstream e duplicazioni parent/child. Non usare il bucket finale `suspected` della pipeline completa per contare le lead Reader. Le golden selezionate sono già estratte con questa distinzione.

L'equivalente a 500k è una **densità normalizzata**, non una previsione: non significa che la golden YesWiki avrebbe trovato 18,8 lead nei primi 500k. Non scalare linearmente recall, CVE o root cause validati.

Consegnare una sola tabella golden/nuova per target con:

1. EP Reader, lead accettate e lead/100k;
2. lead uniche plausibili dopo riesame offline, duplicati/non supportati e ancora incerti;
3. match semantici al catalogo verificati, tenuti separati dai match automatici;
4. EP Recon, EP totali, durata, aree coperte e fallimenti.

Il riesame può usare gli artifact già prodotti e il sorgente, senza una nuova pipeline LLM a pagamento. Le 57 lead YesWiki non sono 57 vulnerabilità validate. Anche il benchmark Cacti ha almeno un abbinamento CVE incompatibile con la descrizione del finding: non usarne lo score grezzo come ground truth.

Segnale sufficiente per valutare P1: miglioramento visibile della densità di **ipotesi uniche plausibili**, con esempi concreti di nuove domande utili, senza aumento evidente di rumore o regressioni tecniche. Un aumento delle sole emissioni non basta. Se esistono ancora troppi casi incerti, esito inconclusivo; non aggiungere automaticamente altre run.

## 6. P1, solo se il Reader è promettente

Integrare [Investigator](skills/investigator/SKILL.md) e briefing nel percorso globale unificato, quindi confrontare conferme effettive e costo/TP. A quel punto affrontare la separazione di `recon_reader_test` dal formato assignments, la propagazione ai figli e la storia statica→dinamica descritte nella review.

Ablation, ripetizioni statistiche, briefing preparatorio persistente e caricamento on-demand rimangono estensioni successive. Non sono prerequisiti per questo pilot.
