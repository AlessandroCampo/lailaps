# P0 Investigator: briefing e topic incrementali

20 ottobre 2026. Implementazione in Lailaps-1, branch `codex/cloudflare-skills-test`.
Il perimetro concordato sostituisce la precedente proposta di importare anche indicazioni di validazione: **solo briefing generale, accesso incrementale e tracciamento delle letture**. Nessuna run con modelli avviata durante il task.

## P0 implementato

- `UNIFIED_LEAD_PROMPT`, decisioni, output, promozione statica, conferma dinamica, budget e sessione rimangono quelli esistenti.
- Nel system prompt stabile entrano una breve guida di accesso e il catalogo di 58 topic, con ID, titolo e indicazione di pertinenza. Non entrano tutti i dettagli tecnici.
- L'Investigator sceglie quando chiamare `read_skill(topic_id)`, in fase statica e dinamica. Il testo arriva nella normale history ed è rileggibile dopo compaction. Nessun routing obbligatorio per area o classe.
- Il briefing Recon frozen entra come `recon_briefing` nel contesto iniziale, insieme alla lead e all'area di origine. È preservato anche nelle versioni compatte dell'incarico e nei checkpoint di compattazione; non viene ripetuto nei delta di ogni turno.
- Nessuna nuova checklist, quota di controprove, soglia di validazione, baseline obbligatoria o lettura necessaria per decidere. La breve guida rinvia al contratto operativo corrente. Non è stato creato `investigator-validation.md`.

La libreria tecnica è condivisa con il Reader. Nei quattro riferimenti, marcatori editoriali espliciti delimitano le sole istruzioni Reader: la vista Reader le include, quella Investigator le omette. Nessuna rimozione basata su keyword e nessuna duplicazione dei 58 documenti. I topic Investigator conservano i concetti tecnici senza importare le esenzioni della discovery. Metodo/catalogo e tutte le risposte Reader sono stati confrontati byte per byte con la versione precedente e restano identici; cambiano invece gli hash dei file sorgente e del manifest, che registrano l'adattamento.

## File effettivamente usati

| File | Funzione |
|---|---|
| [`skills/investigator/SKILL.md`](../../agent/pentest-agent/src/pentest_agent/knowledge/cloudflare/skills/investigator/SKILL.md) nel package | Guida breve di accesso, senza overlay di validazione |
| [`cloudflare_hunting.py`](../../agent/pentest-agent/src/pentest_agent/cloudflare_hunting.py) | Lettura degli asset locali, viste per ruolo, catalogo, topic e hash |
| [`references/`](../../agent/pentest-agent/src/pentest_agent/knowledge/cloudflare/skills/references/) | Quattro domini tecnici: classi generali, protocollo/auth, browser, isolamento/lifecycle |
| [`UPSTREAM.json`](../../agent/pentest-agent/src/pentest_agent/knowledge/cloudflare/UPSTREAM.json) nel package | Commit Cloudflare fissato, fonti, licenza e politica di adattamento |
| [`triple_agent.py`](../../agent/pentest-agent/src/pentest_agent/triple_agent.py) | Catalogo stabile, tool nelle due fasi, briefing iniziale e registrazione delle letture |
| [`compression_manager.py`](../../agent/pentest-agent/src/pentest_agent/tools/compression_manager.py) | Conservazione del briefing nei checkpoint deterministici |
| [`global_pipeline.py`](../../agent/pentest-agent/src/pentest_agent/global_pipeline.py) | Propagazione ai figli Investigator e provenienza nel report replay |
| [`cli.py`](../../agent/pentest-agent/src/pentest_agent/cli.py) | Opt-in del replay frozen e riuso dei sensori senza nuovo scan |
| [`BenchmarkConfirmer.php`](../../app/Console/Commands/BenchmarkConfirmer.php), [`PentestRun.php`](../../app/Console/Commands/PentestRun.php) | Flag e contesto dal wrapper al runtime Python |

Il flag `--cloudflare-hunting` richiede qui `--unified-lead` e un replay batch frozen globale. Non abilita l'integrazione Investigator nello scan ordinario o nei ruoli legacy. I turni di checkpoint e terminalizzazione restano senza tool. Letture e hash vivono nei metadati interni `lead_investigation.knowledge`, con fase e tool call ID; il riepilogo usa `global_lead_replay.knowledge`. Nessun nuovo DTO model-facing o ledger.

## Input congelato

Selezione e provenienza: [`INVESTIGATOR_INPUT.json`](INVESTIGATOR_INPUT.json).

- Fonte: `cacti-recon-reader-global-20261020-173047`, 11 ReaderLead accettate.
- Outcome SHA-256: `a08d9731195b3ce69027b0663f1301f4b3c33a837a1f180fb45e2aead642cc08`.
- Snapshot Cacti: `6482af547c204199e829b7a0df0b7a13db3e0a58`.
- Recon ready: 13 aree, briefing di 4.159 caratteri; 44 associazioni lead/reference verificate contro il sorgente locale.
- Fixture eseguibili già materializzate sotto `storage/framework/lailaps-investigator-p0/cacti-20261020-173047/`: manifest, contesto Recon/sensori e 11 subject nel formato replay esistente.

Si riusano briefing e sensori advisory con i loro limiti originali, inclusi gli errori Semgrep. Nessuna nuova Recon, Reader o scansione sensori. Nessun oracle, verdict storico, cookie o notebook precedente viene importato come evidenza. I subject conservano output di origine, area e source reference; il runtime applica deduplica e ammissione correnti. Undici input non garantiscono undici indagini finanziate o completate.

Il batch proposto usa concorrenza 1: una sessione isolata per lead, stessa storia fra statico e dinamico. Il cap esplicito proposto è 20M EP complessivi, senza modificare il broker; è un tetto, non una stima o garanzia di completamento. I default iniziali sono 500k statici e 1M dinamici per lead, prima delle eventuali estensioni.

## Verifica e lancio

Test offline con modelli fake: contratto unificato invariato, accesso nelle due fasi, tool-free, briefing completo/compatto/checkpoint, viste per ruolo, provenienza nei parziali/finali e flag nei wrapper. Packaging verificato con wheel offline e asset estratti. Il preflight reale ha validato tutte le 11 fixture senza avviare modelli o sandbox.

Comandi e stato dei controlli: [`INVESTIGATOR_RUN.md`](INVESTIGATOR_RUN.md). Il runtime-preflight Docker e la run a pagamento restano manuali. Queste verifiche non dimostrano un miglioramento della qualità investigativa: lo valuteremo dagli esiti sulle 11 lead.

P1+ esclusi: nuove regole di validazione, provisioning LDAP/DNS/poller mirato, altri domini tecnici, A/B controllato con/senza skill, integrazione nello scan ordinario. Non sono prerequisiti del P0.
