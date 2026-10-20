# Implementare il wireframe in lailaps-2

18 ottobre 2026. Piano incrementale per `/home/alessandro/projects/lailaps-2` in Ubuntu/WSL. Il checkout Windows ospita il riferimento, non è il frontend da estendere. [Specifica UX](plan.md), [wireframe](index.html#project).

## Perimetro

**P0 completo:** overview con preparazione nello stato progetto, avvio reale, pagina permanente scan, finding/report persistiti e lead/budget incrementali con overview/dettaglio aggiornati. Prima consegnare ciò che usa i dati esistenti, poi completare il live; il primo incremento non equivale al P0 intero.

Non riscrivere upload, Doctor, Recon, budget, autenticazione o motore. Nessun rewrite TypeScript, nuovo agente, LLM per formattare il report o framework generico di eventi. Stop e mascotte sono P1.

## Partenza verificata

| Area | Già disponibile | Da collegare |
| --- | --- | --- |
| Layout/create | AppHeader, app.css, Index/Create, SourceUpload/Status | Riuso del design attuale |
| Overview | ProjectController::show espone preparation_status e runs.latest/history | Show.vue/types non consumano runs; sostituire Source/Status |
| Avvio | POST projects.sources.audit_runs.store, controller/action/job | Start scan nella pagina richiama ancora refresh |
| Esecuzione | RunAuditAction: prepara, avvia Python, cleanup e import | Nessuna GET pagina run/finding/report né stream prodotto nelle route lette |
| Stato | pending/running/completed/partial/failed; timestamp e Resource compatta | Nessuno Stop o fasi live pubblicabili persistite |
| Risultati | RunOutcome/AreaOutcome/LeadOutcome, outcome DB, aree/lead | Risorse/pagine autorizzate ed export |
| Prove | OutcomeService::presentLead, snippet max 40 righe, estratti HTTP/tool max 2.000 caratteri | Caricamento on demand e UI available/truncated/reason |
| Progresso | ArtifactStore scrive snapshot/telemetry atomici durante la run; MultiCategory aggrega i pass | Laravel aspetta il processo e importa solo terminali |

Filename ZIP originali assenti. Counts null prima dell'import, non zero. Source storiche withTrashed. Projects index restituisce nome/data/sources_count: i badge preparazione del mock richiedono una proiezione aggiuntiva.

## A — overview e avvio reali

File: `resources/js/pages/projects/Show.vue`, `resources/js/types/project.ts`, `ProjectController.php`, `ProjectSourceController.php`, `AuditRunController.php`, componenti progetto esistenti.

1. Tipizzare Resource run, nullable counts/coverage e runs.latest/history. Non caricare prove, log e outcome completi nella lista.
2. Overview senza Source/Status; header, stato, tipo/data e nessun ID interno visibile. Redirect create/retry all'overview. Le vecchie query step possono essere ignorate senza introdurre un layer di compatibilità.
3. latest=null → grande empty state, adattato a preparing/failed/source assente. Errori di preparazione non cambiano i risultati delle run precedenti.
4. Proiettare dal server can_start_scan e causa del blocco con gli stessi prerequisiti di CreateAuditRunAction: source ready, Lab ready, Recon utilizzabile e nessuna run concorrente sul Lab. Il POST resta autorevole. Nessuno stato Project nuovo da sincronizzare manualmente.
5. Collegare Start scan al POST esistente con submitting/errori. Nessun parametro modello/budget: oggi il controller non li accetta. Redirect iniziale overview, poi pagina della nuova run nell'incremento B.
6. ScanSummary/storico da Resource: stato, tempo, disponibilità report/conteggi e link. Dati mancanti → Not available yet; nessun progresso inventato.
7. Upload new version in Dialog con SourceUpload/action esistenti, preservando errori e storico. **Upload di sostituzione non avvia automaticamente la preparazione nel percorso letto:** mostrare Not ready e un'azione di preparazione. Verificare il caso senza Lab e aggiungere solo il collegamento necessario alla creazione/setup, riusando Doctor. Mai Ready dal solo upload riuscito.

Preparazione: usePoll indipendente dalla vecchia tab, attivo solo mentre serve. Per la run, fino a D, ricariche parziali del riepilogo possono aggiornare lo stato; saranno sostituite da SSE. Nessun GET/poll avvia lavoro.

Gate: progetto → preparazione → Ready → POST → queued/running → risultato nello storico, con fixture/processo simulato o run già presenti; doppio click gestito e form/errori conservati. Nessuna chiamata provider.

## B — scan, finding e report persistiti

File: routes/web.php, AuditRunController, nuove pagine `resources/js/pages/projects/scans/`, OutcomeService e Resource/payload DB. Non copiare lo script del mock nel frontend Vue.

| Route proposta | Funzione |
| --- | --- |
| GET /projects/{project}/audit-runs/{run} | Pagina permanente; Activity/Findings come tab/query |
| GET /projects/{project}/audit-runs/{run}/findings/{lead} | Dettaglio lead prodotto |
| GET /projects/{project}/audit-runs/{run}/report | Report HTML |
| GET /projects/{project}/audit-runs/{run}/report/export?format=json o markdown | Export autorizzato |

Ogni ingresso verifica ownership, run del progetto e lead della run. ID prodotto nelle route, identità runtime interne. Wayfinder secondo il flusso attuale; nessun router parallelo.

- Liste/report dal DB; prove on demand. getOutcomeSummary() legge il file originale: non usarlo come normale sorgente di tutte le pagine; outcome DB e lead.payload esistono già.
- Riutilizzare OutcomeService, adattando localmente la presentazione per i payload persistiti. LeadOutcome::fromArray interpreta lead_id/finding_id Python: il payload compatto con id non è lo stesso contratto e non va passato ingenuamente alla factory.
- Finding da title, severity opzionale, status, description, conclusion, reason distinto, recommendation opzionale; classificazione/confidence secondarie. Nessuna generazione di nuovi campi narrativi obbligatori.
- Filtri e conteggi sui quattro esiti esposti. Pending/running e deferred separati; rejected solo conteggio quando mancano righe nella proiezione. Severity nulla non viene indovinata.
- Snippet dalla source fissata dalla run, mai quella attiva corrente. Usare available/start_line/end_line/content/truncated e il confine di path canonico già presente. Shiki on demand, temi e linguaggi necessari, testo semplice se non supportato; codice come dato, senza esecuzione. Nessun editor/explorer.
- HTTP: solo campi/estratti acquisiti, con missing/truncated e verdetto originale conservato. Journal privato non diventa feed HTML.
- Report template da dati persistiti, coverage tri-state, usage/limiti disponibili. Export stessi dati, nessun link pubblico o nuova analisi LLM. Stampa browser; PDF dedicato P2. has_report=false non equivale a report vuoto.
- La sequenza attuale imposta status terminale prima dell'import e finished_at dopo: governare finalizzazione e azioni con timestamp/disponibilità report, senza annunciare un report pronto dal solo completed. Errori d'import non rilanciano l'audit.

Gate: completed/partial/failed, severity nulla, zero risultati, prove mancanti/troncate, source archiviata; export coerenti, report dopo cleanup e ritorno alla lista con filtri conservati.

## C — acquisizione incrementale a DB

File: RunAuditAction, DockerService, OutcomeService, CreateAuditRunArea/LeadAction, DTO attuali; sole estensioni DB necessarie per progresso/versione. Laravel unico writer. Nessun nuovo output model-facing.

Producer verificato: `agent/recon-python/src/pentest_agent/prepared_run.py` fissa LAILAPS_OUTCOME_FILE; ArtifactStore::write_partial_report/write_telemetry usa replace atomico; multi_category.py::publish_pass_checkpoint aggrega snapshot durante la run. Riusare questi file, senza derivare finding da stderr e senza riscrivere il loop.

1. Acquisire con fixture snapshot iniziale, prima lead, cambio verdetto/usage e finale; verificare cosa viene realmente pubblicato a ogni stadio.
2. Nel launcher sorvegliare il processo senza aspettarne soltanto il termine, preservando comando/mount/deadline/cleanup esistenti. Lettura periodica controllata dello snapshot mentre il processo vive; il solo callback stderr non copre i tratti silenziosi. Nessun secondo job che rilancia l'audit e nessun accesso browser ai file privati.
3. **Envelope intermedi diversi dal finale:** ArtifactStore::_load_outcome conserva solo alcune chiavi; snapshot intermedi possono perdere schema_version/source identity/run_status richiesti dalla factory finale. Non basta togliere la guardia terminale. Associare il file alla run e agli input fissati dal server, normalizzando esplicitamente una proiezione running; mantenere la validazione stretta dell'envelope finale. Verificare il formato prima di cambiare DTO.
4. Distinguere acquisizione checkpoint e finalizzazione in OutcomeService, riusando mapping/upsert. Identità run/pass/lead e relazioni area stabili. Assenza in un checkpoint non cancella una lead. Il finale deve riconciliare lead ritirate/scartate senza lasciarle nel totale: usare ledger/lifecycle autorevoli, senza inferenze dalla narrativa né espansione automatica della UI dei rejected.
5. Persistire checkpoint, usage assoluto e versione in una transazione. Replay non duplica record/consumi/eventi. Errore d'acquisizione conserva il valido e ritenta solo l'import, mai la chiamata modello. Snapshot incompleto non svuota il DB.
6. Separare risultati live da disponibilità report finale: oggi la Resource nasconde counts se non c'è outcome importato. Esporre disponibilità checkpoint, ultimo aggiornamento e conteggi noti, mantenendo null prima del primo snapshot. Failed può conservare risultati acquisiti.
7. Investigating solo per lead ammesse realmente attive. Deferred/not_admitted non diventano attive. Titoli/stati dalle strutture autorevoli; se non c'è un'attività corrente pubblicabile mostrare Scan in progress. La fixture del mock non prova disponibilità di quel campo.
8. Budget used = points_used/point_limit solo con limite valido e usage disponibile; valori assoluti, mai somma dei checkpoint. Nessuna ETA/percentuale di audit. Dato non aggiornato conserva la data, costo ignoto resta ignoto.

Gate: sequenza fixture che aggiorna DB prima del finale; replay, cambio verdetto, ritiro, crash/import fallito con stato coerente. Solo dopo collegare il live al frontend. Nessun provider.

## D — SSE e wireframe live completo

Feed piccolo di eventi prodotto: stato run, cambiamenti lead e disponibilità checkpoint. Una sequenza/cursore persistito con pochi eventi significativi; commit prima della notifica. Niente archivio token/tool o reasoning. Nessun LLM per narrare gli eventi.

GET stream autorizzato sotto la run, snapshot iniziale e reconnect dal cursore; se gli eventi non sono più disponibili ricostruire dalla proiezione corrente. Il dettaglio aggiorna la lista, overview riceve solo il riepilogo. Un composable condiviso per stream/reconnect; nessuno store globale preventivo. Chiudere la connessione alla navigazione.

Attività, lead, conteggi e budget nel box e nel dettaglio; aggiornamenti per identità con filtri/focus preservati. Investigating distinto dai conteggi d'esito. Connessione persa non significa run fallita. Chiudere il browser non ferma il job. A fine run stessa URL, risultati/report disponibili.

Gate P0 completo: prima lead leggibile mentre running; refresh/reconnect conservano acquisito; box e dettaglio coerenti; finale/report senza nuova chiamata modello. Prova con produttore simulato, non lunga run a pagamento.

## Rifinitura e verifica

- Riutilizzare Card/Badge/Dialog/Table/Tabs; aggiornare docs/design-system.md per convenzioni condivise. Shiki è l'unica dipendenza UI nuova proposta.
- Projects: proiezione compatta della preparazione con stesse regole overview; se non consegnata mantenere card attuali senza badge inventati o outcome pesanti.
- Browser desktop/mobile e light/dark, nomi lunghi, assenza report/zero finding, Blocked/null, prove mancanti, upload/setup falliti, doppio avvio, reconnect e tastiera. Niente lint globale.
- Riusare controlli e fixture esistenti; non scrivere automaticamente unit/feature test non richiesti. Per C/D prevedere replay offline mirati nel relativo task, senza LLM. Typecheck/build proporzionati alle modifiche.
- Eventuale accettazione live solo su richiesta esplicita. Nessun agent loop per questo piano o il mock.
- All'implementazione di C/D aggiornare ARCHITECTURE.md per acquisizione/persistenza/SSE. A/B UI non richiedono un rewrite architetturale. Se diventano necessarie modifiche al loop/producer, consultare ds-harness-reference prima di progettarle; questo piano riusa snapshot già presenti.

## P1+ non inclusi

P1: Stop con cancellazione/cleanup e lifecycle verificati prima del pulsante; mascotte; GitHub; target/attori configurabili; retest/billing. P2: PDF, sidebar globale, collaborazione, automazioni/trend. Nessun refactoring collaterale di Doctor o motore.

Il task attuale consegna solo wireframe e piani; non applica A–D a lailaps-2 e non modifica il suo design system autorevole o ARCHITECTURE.md.
