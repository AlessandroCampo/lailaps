# Revisione critica dell'handoff Cloudflare

**Nota di scope successiva:** l'utente ha scelto P0 Recon+Reader e P1 Investigator condizionato ai risultati. Il [piano aggiornato](PLAN.md) prevale sulle raccomandazioni end-to-end di questa analisi iniziale. Il codice è ora fissato nel commit baseline `46160f362053c612d9e6a1b8d456b6e98fd4ca2c`; le golden sono documentate in [GOLDEN_SELECTION.md](GOLDEN_SELECTION.md).

Data: 20 ottobre 2026. Checkout analizzato: **Lailaps-1**, `C:\Users\Alessandro\Desktop\latest-projects\lailaps`.
Branch di partenza `minimal`, HEAD `1d1cb1516c1036fa61b7cf693815a3622f8f2567`, **con modifiche locali preesistenti**.
Branch creato per questa consegna: `codex/cloudflare-skills-test`.
Il confronto riguarda i file effettivi del working tree, non soltanto quel commit.

L'handoff è materiale da valutare: le sue sezioni «decisioni concordate» e «consegna all'implementatore» non autorizzano implementazione o benchmark in questo task. Questa consegna contiene analisi, piano e asset; non cambia il runtime.

## Giudizio

L'ipotesi è sensata: un modello comune del target e prospettive investigative specifiche possono migliorare scoperta e conferma. Il vantaggio non è però ancora dimostrato. Una parte consistente del meccanismo esiste già, mentre alcune premesse dell'handoff appartengono a versioni diverse di Lailaps e Cloudflare. Importarlo letteralmente aumenterebbe scope, costo e rigidità.

Il P0 consigliato è una variante della pipeline globale attuale: riusare `ReconPlan`, distribuire il suo briefing, aggiungere conoscenza distinta per Reader e Investigator. Nessun nuovo agente, scheduler, schema investigativo o framework di skill. La preparazione persistente a livello progetto è P1.

## Incoerenze e conseguenze concrete

| Priorità | Evidenza nel checkout | Problema dell'handoff e correzione |
|---|---|---|
| P0 | [`initialize_codebase_memory`](../../agent/pentest-agent/src/pentest_agent/codebase_memory/bootstrap.py:55) restituisce sempre `disabled`; [`category_recon_tools`](../../agent/pentest-agent/src/pentest_agent/triple_agent.py:2896) usa il toolset minimal | CBM non è un input operativo disponibile. Non pianificare query al grafo o riattivazione CBM per questo test. Usare sorgente e Semgrep. |
| P0 | Nessun riferimento OSV o `ProjectArtifact` nei sorgenti attivi `app`, `config`, migrazioni e package Python esaminati; [`RunPreparation`](../../app/Jobs/RunPreparation.php) prepara runtime, profilo e attori | Non esiste qui la pipeline unificata di artifact descritta nell'handoff. File sotto `work/.../before` sono copie storiche. Creare un registro progetto e introdurre OSV sarebbe lavoro aggiuntivo, non semplice integrazione. |
| P0 | [`ReconPlan` / `ReconAssignment`](../../agent/pentest-agent/src/pentest_agent/models.py:279), [`RECON_ASSIGNMENTS_PROMPT`](../../agent/pentest-agent/src/pentest_agent/triple_agent.py:1129), [`normalize_recon_plan`](../../agent/pentest-agent/src/pentest_agent/triple_agent.py:3051) | Esistono già briefing, incarichi, proprietà da verificare e primi locator. Anche la Recon globale ordinaria chiede domande di sicurezza. La baseline non è puramente geografica. Non aggiungere `subsystem`, `attack_focus`, `security_hypothesis`, `recommended_skills`: la semantica entra in `title` e `next_check`. |
| P0 | [`TripleAgentOrchestrator.__init__`](../../agent/pentest-agent/src/pentest_agent/triple_agent.py:3590) impone `global_mode` e `stop_after_reader` per `recon_reader_test`; [`GlobalPipeline._engine`](../../agent/pentest-agent/src/pentest_agent/global_pipeline.py:134) crea dipendenze figlie | Il flag esistente non abilita un test end-to-end. Occorre separare selezione del formato/prompt da arresto dopo Reader e propagare la variante ai figli, senza rimuovere il guard del vecchio comando. |
| P0 | [`_review_tranche`](../../agent/pentest-agent/src/pentest_agent/triple_agent.py:6630) usa `_checkpoint_reader`; `ExplorationReview` resta una rappresentazione interna | Il Reviewer separato non è l'autorità agentica corrente. Preservare self-checkpoint, normalizzazione e ledger; non reintrodurre un agente per «preservare Exploration Reviewer». |
| P0 | [`GlobalPipeline._product`](../../agent/pentest-agent/src/pentest_agent/global_pipeline.py:207) acquisisce enrichment e deduplica; `reader_area_dossier` usa il ledger della sessione | Il coordinamento globale è del parent. Un figlio riceve una sola area e non eredita automaticamente l'indice completo descritto nell'handoff. Conservare un indice leggero degli incarichi iniziali come contesto, senza fingere che sia il ledger aggiornato né modificare la deduplica per renderla semanticamente diversa. |
| P0 | [`_make_investigator`](../../agent/pentest-agent/src/pentest_agent/triple_agent.py:3225), [`UNIFIED_LEAD_PROMPT`](../../agent/pentest-agent/src/pentest_agent/triple_agent.py:1350), `stable_system_prompt` | Investigator unificato confermato: milestone statica e dinamica nella stessa storia. I nomi legacy `worker`/`confirmer` restano per tool, budget e telemetria. Un'iniezione sul ruolo inesistente `"investigator"` rischierebbe di non essere mai applicata. |
| P0 | [`reader_area_dossier`](../../agent/pentest-agent/src/pentest_agent/deps.py:2277), [`_reader_prompt`](../../agent/pentest-agent/src/pentest_agent/triple_agent.py:3847), [`_role_system_prompt`](../../agent/pentest-agent/src/pentest_agent/triple_agent.py:1555) | Il briefing del percorso assignments non è un input comune garantito all'Investigator globale. Va trasportato esplicitamente. Non aggiungerlo a ogni turno né inserirlo in un JSON che viene troncato a 16.000 caratteri. |
| P0 | [`BenchmarkReconInput`](../../app/Services/Pentest/BenchmarkReconInput.php:11), [`GlobalPipeline.run`](../../agent/pentest-agent/src/pentest_agent/global_pipeline.py:640) | Golden Recon accetta `CategoryRecon`, non verifica il significato sperimentale di un piano; una run storica può avere `source_snapshot` assente. La compatibilità strutturale non rende il confronto valido. P0: nuove Recon, rifiutare replay/golden nelle varianti sperimentali. |
| P0 | [`validate_category_recon_contract`](../../agent/pentest-agent/src/pentest_agent/triple_agent.py:2992) | Path mancanti/non osservati sono conteggiati; non sono tutti errori bloccanti come suggeriscono sezioni storiche di ARCHITECTURE. Non costruire il piano su un validatore più severo di quello esistente, né introdurre nuovi retry semantici. |

`ARCHITECTURE.md` contiene sia la descrizione corrente del runtime minimal sia sezioni storiche con tool CBM, Confirmer/Worker separati e vincoli superati. In questo task non lo riscriviamo: il runtime non cambia. L'implementazione dovrà aggiungere una fotografia breve e inequivocabile della variante, evitando una revisione generale del documento.

## Cloudflare effettivamente verificata

Revisione letta: [`c1c8a8c1471069fb0e188eeaff69b8e8db6564a8`](https://github.com/cloudflare/security-audit-skill/tree/c1c8a8c1471069fb0e188eeaff69b8e8db6564a8). Licenza MIT, copyright 2025–2026 Cloudflare, Inc.; copia in [LICENSE.cloudflare](LICENSE.cloudflare). Fonti e checksum in [UPSTREAM.json](UPSTREAM.json).

Rispetto all'handoff:

- [`RECONNAISSANCE.md`](https://github.com/cloudflare/security-audit-skill/blob/c1c8a8c1471069fb0e188eeaff69b8e8db6564a8/skills/security-audit/RECONNAISSANCE.md) propone **quattro** prospettive iniziali e ulteriori agenti mirati, un briefing di circa 1.000 parole e un ledger deterministico.
- [`HUNTING.md`](https://github.com/cloudflare/security-audit-skill/blob/c1c8a8c1471069fb0e188eeaff69b8e8db6564a8/skills/security-audit/HUNTING.md) assegna unità di copertura, usa critic successivi e contratti strutturati. Non è semplicemente una distribuzione fissa di 8–12 Hunter.
- Esiste `needs_validation`: «solo exploit confermati» non descrive più correttamente il contratto upstream. I candidati hanno comunque una soglia molto più alta delle lead soft Lailaps.
- Le conferme upstream possono usare test locali di libreria, policy o fixture. Lailaps richiede evidenza dell'effetto nell'applicazione tramite il percorso HTTP/browser autorizzato: uno script locale non diventa automaticamente una conferma.
- Anche `ATTACK-CLASSES.md`, `WEB-PROTOCOL-AND-AUTH.md` e `CLIENT-SIDE.md` contengono istruzioni operative: subagent, verdict, scope, prove complete e altri companion. **Nessuno di questi file, integralmente, è direttamente compatibile come istruzione model-facing nel P0.** Non basta anteporre un disclaimer e sperare che il modello ignori le contraddizioni.

La libreria consegnata conserva prospettive e domande utili, separando discovery da conferma. Non importa schema report, validator, critic, gestione di scratch, nuove regole di sandbox o agent spawning. La licenza è l'unico file copiato integralmente. Gli altri companion, inclusi AI e native, restano P1 su target pertinenti; non sono necessari ai primi target web.

## Flaws sperimentali da evitare

1. **Bias di conferma del planner.** «Task per ipotesi» non deve significare «ogni task presuppone un bug». Domande falsificabili, controlli attesi, percorsi alternativi e componenti ancora poco note devono restare esplorabili.
2. **Filtro prematuro nel Reader.** Trasferire il candidate gate Cloudflare elimina lead incomplete valide prima dell'Investigator. Distinguere soglia per emettere una lead da sufficienza per confermare.
3. **Effetto confuso con topologia e budget.** Confrontare un Reader seriale con quattro concorrenti misura anche scheduling e ammissione. A/B/C devono usare la stessa pipeline globale, concorrenza, modelli e budget.
4. **Recall conosciuto ≠ precisione.** Un finding non presente nei manifest può essere reale. Serve revisione degli unmatched, separata dall'oracle; non contarli automaticamente come FP.
5. **Costo spostato fuori misura.** Se in futuro il briefing è precomputato, riportare costo a freddo e ammortizzato. Nel P0 è prodotto dalla Recon e il costo resta interamente nella run.
6. **Attribution limitata.** B contro A misura briefing + planner + handoff insieme; C contro B misura i due profili insieme. Non dimostra quale euristica o quale ruolo abbia prodotto il gain.
7. **Pretesa statistica eccessiva.** Una run è un smoke test; pochi target e ripetizioni danno un segnale, non una dimostrazione di generalità. Definire soglie prima di osservare i risultati.
8. **Stale e leakage.** Un commit target non identifica sempre la vista sanitizzata realmente letta. Il briefing deve derivare soltanto da quella vista, non da oracle, CVE manifest, finding precedenti o file omonimi forniti dal target.

## Riferimento DeepSeek Harness

Consultata la revisione [`687f282e8625c6b8d11ff58211a90524be2e4317`](https://github.com/deepseek-ai/deepseek-harness/tree/687f282e8625c6b8d11ff58211a90524be2e4317):

- [`system-prompt/src/index.ts`](https://github.com/deepseek-ai/deepseek-harness/blob/687f282e8625c6b8d11ff58211a90524be2e4317/packages/core/system-prompt/src/index.ts) separa sezioni ordinate da contesto runtime e assembla i contributi per scope.
- [`agent-loop/src/agent.ts`](https://github.com/deepseek-ai/deepseek-harness/blob/687f282e8625c6b8d11ff58211a90524be2e4317/packages/core/agent-loop/src/agent.ts) proietta il prompt in funzione di `systemPromptUpdate` e dei confini della request series.

Adattamento proposto: knowledge statica ordinata e stabile per ruolo, briefing del target come contesto iniziale della sessione/epoch, mantenimento della storia esistente. Non importare registry/plugin DS. Stabilità del prefisso e cache hit sono cose diverse: il secondo va misurato sull'usage del provider.

## Verifiche della consegna

Validatore `skill-creator/quick_validate.py` superato per le tre skill. Verificati 50 link locali, checksum della licenza e immutabilità dei sette sorgenti campionati nel manifest durante la preparazione della consegna. Knowledge aggiunta: 11.463 caratteri per Reader e 11.976 per Investigator, sotto il limite proposto di 16.000. Nessun test del runtime, loop agentico o benchmark eseguito: i risultati misurati dell'esperimento restano da produrre.
