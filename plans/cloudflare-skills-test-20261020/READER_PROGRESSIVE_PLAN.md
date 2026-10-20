# Reader Cloudflare: conoscenza completa e accesso incrementale

Data: 20 ottobre 2026. Stato: **implementato; benchmark agentico da eseguire manualmente**.
Destinazione: Lailaps-1, branch `codex/cloudflare-skills-test`.

Questo documento definisce il prossimo P0 del Reader e sostituisce, per il caricamento della sua knowledge, il «Passo 2» di [PLAN.md](PLAN.md). La precedente scelta di iniettare tre documenti condensati era una semplificazione del primo pilot. L'Investigator resta completamente fuori da questa implementazione.

## 1. Obiettivo e scope

Fornire ai Reader la conoscenza investigativa pertinente all'incarico, preservando domande e varianti tecniche Cloudflare. Tenere sempre visibili il metodo comune e un catalogo; caricare i dettagli quando il Reader li sceglie. La specializzazione nasce da incarico e letture, senza nuovi tipi di agente. I controesempi possono chiarire il significato di un pattern; la ricerca attiva delle prove che confutano una lead appartiene alla fase statica dell'Investigator.

P0:

- metodo Reader breve nel system prompt;
- catalogo dei topic effettivamente disponibili;
- quattro documenti tecnici adattati, organizzati in blocchi consultabili;
- un tool `read_skill(topic_id: str) -> str`, disponibile solo ai Reader sperimentali;
- provenienza della libreria e registrazione delle letture effettive;
- verifiche offline e aggiornamento della fotografia architetturale.

Conservare Recon e contratti attuali: `ReconPlan`, `ReconAssignment(title, paths, next_check)`, `ReaderLead`, `AreaEnrichmentLead`, self-checkpoint, budget e ledger. Conservare il riuso della Recon Cacti, i quattro Reader concorrenti e il cap aggregato 500k EP. Le letture della knowledge fanno parte del normale lavoro Reader, senza budget aggiuntivi o chiamate LLM ausiliarie.

Fuori scope: prompt, tool e lifecycle Investigator; nuovi Reviewer/Judge; subagenti; modifiche alla Recon o rigenerazione obbligatoria dei suoi artifact; selezione deterministica delle classi dal target; infrastruttura di retrieval, embedding o plugin; correzione di Semgrep o dei comandi `rg`; esecuzione autonoma di benchmark agentici.

## 2. Cosa trasferiamo da Cloudflare

Upstream fissato: [`c1c8a8c1471069fb0e188eeaff69b8e8db6564a8`](https://github.com/cloudflare/security-audit-skill/tree/c1c8a8c1471069fb0e188eeaff69b8e8db6564a8), licenza MIT. Non scaricare contenuti durante la run.

Cloudflare seleziona unità di copertura per combinazioni pertinenti di superficie, confine, sottosistema e classe; poi inserisce nel prompt Hunter i blocchi scelti. Un'area Lailaps non deve corrispondere a una sola classe: lo stesso incarico può richiedere più topic e restare aperto a lead fuori focus. Non generare un prodotto cartesiano di aree e classi.

Riferimenti: [selezione nella Recon](https://github.com/cloudflare/security-audit-skill/blob/c1c8a8c1471069fb0e188eeaff69b8e8db6564a8/skills/security-audit/RECONNAISSANCE.md), [prompt e metodo Hunter](https://github.com/cloudflare/security-audit-skill/blob/c1c8a8c1471069fb0e188eeaff69b8e8db6564a8/skills/security-audit/HUNTING.md).

Trasferire il metodo dell'invariante, il confronto fra percorsi equivalenti, sad/error paths, trasformazioni e copie persistite, disaccordi fra componenti, lifecycle e autorità. I blocchi tecnici devono conservare le domande che cambiano la prossima lettura. L'accesso tramite tool è un adattamento Lailaps: non è il meccanismo di consegna degli Hunter upstream.

## 3. Asset da preparare nell'implementazione

La fonte runtime autorevole è `agent/pentest-agent/src/pentest_agent/knowledge/cloudflare/`. I file elencati sotto sono gli asset realizzati nell'implementazione; la tabella conserva le operazioni previste dalla specifica. Non mantenere due copie modificabili della nuova libreria fra `plans/` e package.

| Asset sotto la root runtime | Origine | Contenuto richiesto |
|---|---|---|
| `skills/reader/SKILL.md` — aggiornare | `HUNTING.md` + contratto Reader attuale | Metodo comune, soglia soft, scelta della knowledge e regole di utilizzo; nessun catalogo tecnico completo nel corpo |
| `skills/references/attack-classes.md` — nuovo | `ATTACK-CLASSES.md` | Tutti i blocchi generali: injection, access control, risorse/file, crittografia/segreti, business logic, feature abuse/leakage, composizione, wildcard e obvious things, adattati alla discovery |
| `skills/references/web-protocol-and-auth.md` — nuovo | `WEB-PROTOCOL-AND-AUTH.md` | Framing/cache, sessioni/CSRF/cookie, JWT/OAuth/SAML, MFA/passkey/recovery/linking, API key/mTLS e lifecycle |
| `skills/references/client-side.md` — nuovo | `CLIENT-SIDE.md` | DOM, clobbering, prototype pollution, messaggi/CORS/WebSocket, worker/storage, XS-Leaks, navigazione e framing UI |
| `skills/references/data-isolation-and-lifecycle.md` — nuovo | `DATA-ISOLATION-AND-LIFECYCLE.md` | Tenant/owner, chiavi e riferimenti, query/policy, copie derivate, export/import/backup, migrazione, cancellazione, revoca e restore |
| `UPSTREAM.json` — aggiornare | Manifest esistente e quattro sorgenti sopra | Commit, attribuzione, checksum dei sorgenti e mappa dei blocchi adattati, comprese esclusioni motivate |
| `LICENSE.cloudflare` — conservare | Licenza upstream | Testo e attribuzione MIT |

Ogni classe diventa un topic con ID stabile. I companion conservano anche disciplina comune e mosse trasversali: il tool le compone con il blocco richiesto. Usare heading espliciti e uniformi nei file adattati, senza dipendere da numeri di riga upstream.

Gli attuali `attack-perspectives.md` e `web-and-browser.md` vengono sostituiti nel percorso Reader dai documenti più fedeli. Non devono più essere iniettati insieme ai nuovi contenuti. Non modificarli o rimuoverli senza verificare i riferimenti della skill Investigator preparata in precedenza e dei piani storici; possono restare come asset legacy non caricati dal Reader.

### Regola di adattamento

Adattare responsabilità e soglia del ruolo, preservando i dettagli tecnici pertinenti. Per esempio: mantenere i selettori di chiave JWT, i diversi binding OAuth, i confronti origin/source e gli invii `postMessage`, i dettagli di scope/import/lifecycle dei service worker e i percorsi alternativi di restore/revoca. La brevità del system prompt non giustifica la loro eliminazione dai riferimenti.

Le regole di validazione upstream non vanno trasferite al Reader come attività di verifica o condizioni di emissione, neppure sotto forma di checklist preliminare. Conservare le informazioni tecniche utili a formulare un'ipotesi; i passaggi non osservati restano unknowns per l'Investigator. Escludere spawning, critic, schema report, gestione artifact e policy di esecuzione Cloudflare. Preservare i limiti Lailaps: la knowledge non concede HTTP, nuovi permessi o conferme al Reader.

### Vincolo di prodotto: nessun requisito aggiuntivo per emettere una lead

La soglia resta quella del Reader precedente all'integrazione Cloudflare: un'osservazione concreta nel codice e un collegamento plausibile a un problema di sicurezza sono sufficienti per emettere una `ReaderLead`. Le skill devono suggerire dove e cosa leggere, senza aggiungere condizioni da soddisfare prima dell'emissione.

| Responsabilità Reader | Responsabilità della fase statica Investigator, già esistente |
|---|---|
| Individuare un comportamento sospetto e formulare l'ipotesi | Ricostruire e verificare source, propagation, sink e reachability |
| Descrivere osservazioni, controlli già incontrati e passaggi ancora ignoti | Cercare controprove e verificare le barriere che possono confutare l'ipotesi |
| Trasferire le incertezze senza doverle risolvere | Valutare efficacia delle mitigazioni, default del framework e prerequisiti dell'impatto |

Il Reader non deve dimostrare il controllo dell'input, completare il collegamento fino al sink o a un gadget, escludere tutte le mitigazioni, cercare evidenze negative o verificare tutte le condizioni di una classe prima di emettere una lead. La lettura di una skill, la sua checklist e il numero di verifiche svolte non diventano requisiti di accettazione. Non aggiungere campi, validatori o retry per imporre queste prove.

Se un controllo o una smentita sono già evidenti nel codice letto, il Reader può registrarli; non deve avviare un'indagine di confutazione. Non presentare come verificato un passaggio ancora ipotetico: dichiararlo negli unknowns consente di emettere subito la lead con la soglia attuale.

Esempi di riscrittura dei blocchi:

- Prototype pollution: una scrittura ricorsiva sospetta può motivare una lead anche se il consumo della proprietà non è stato ancora ricostruito; il gadget mancante resta un unknown, non una ragione obbligatoria di scarto.
- XSS: il Reader può segnalare un flusso sospetto verso rendering raw con escaping ancora da chiarire; la verifica dell'efficacia delle protezioni non è un prerequisito della lead.
- Autorizzazione: la differenza osservata fra operazioni singole e bulk può motivare una lead anche se middleware e controlli condivisi non sono stati tutti seguiti.

La revisione deve includere anche le formulazioni ambigue degli asset del primo pilot, per esempio «verifica firma ... issuer, audience ...», «cerca ... consumo della proprietà» e «azione sensibile realmente completabile nel frame». Queste descrivono condizioni da accertare nell'indagine successiva; nei topic Reader vanno espresse come piste e domande aperte, senza richiederne il completamento. Un disclaimer generale sulla soglia soft non basta se i singoli blocchi prescrivono verifiche complete.

La revisione degli asset deve verificare che ogni blocco dei quattro sorgenti abbia un adattamento identificabile o un'esclusione motivata nel manifest. È una verifica editoriale della consegna, non un nuovo processo durante le run. Limitarsi ai topic dei quattro documenti; mostrare nel catalogo soltanto quelli implementati.

## 4. Cosa vede il Reader

### System prompt stabile

Preservare `READER_ASSIGNMENTS_PROMPT` e aggiungere:

1. il metodo comune della skill Reader, eliminando duplicazioni con il contratto già presente;
2. un catalogo ordinato: `topic_id`, titolo e breve indicazione di quando consultarlo;
3. istruzioni per scegliere i topic dal comportamento e dai confini osservati, non dalla sola tecnologia o da una categoria OWASP;
4. indicazioni a consultare gli approfondimenti pertinenti per orientare l'esplorazione all'avvio dell'incarico e quando emerge un nuovo confine. Se una lead è già emettibile, non ritardarla per leggere una skill o completarne i suggerimenti. Nessun caricamento obbligatorio per classi non pertinenti, quota di tool call o gate sull'emissione delle lead.

Il catalogo si genera dalla stessa allowlist che risolve le letture: niente elenco parallelo scritto a mano. Misurare dimensione del nucleo e del catalogo alla consegna; non caricare i corpi tecnici nel system prompt. Il limite attuale di 16.000 caratteri non deve diventare un limite all'intera libreria.

### Contesto dell'incarico

Briefing comune, incarico attivo e indice degli altri incarichi continuano a passare dai dossier esistenti, all'inizio e ai cambi di epoch. Nessun nuovo `architecture.md` del target, DTO o campo obbligatorio `attack_class`/`recommended_skills`.

La Recon Cacti già generata è compatibile: titolo e `next_check` bastano al Reader per scegliere gli approfondimenti. Per esempio, l'incarico 7 sulle bulk action può motivare access control e isolamento degli oggetti; l'incarico 8 può motivare gestione file e import/restore. Sono esempi di scelta semantica, non mapping Cacti codificati nel runtime.

## 5. Tool di accesso incrementale

Contratto model-facing:

```text
read_skill(topic_id: str) -> str
```

Esempi di ID da definire negli asset: `core.access-control`, `web-auth.oauth-callback`, `browser.postmessage`, `data-isolation.export-scope`. Lo schema accetta una stringa semplice; il catalogo elenca gli ID validi.

Il modulo esistente `cloudflare_hunting.py` mantiene una piccola allowlist statica: ID, descrizione, file e sezioni da comporre. La selezione semantica spetta al Reader. La risoluzione dell'ID e la lettura dei byte spettano al runtime. Nessun servizio di selezione separato.

La risposta è testo piatto con ID/titolo, disciplina comune pertinente, classe richiesta, mosse trasversali e cautele Reader. Nessun DTO annidato, output model LLM o nuovo formato di finding. Non restituire solo il nome della classe o un link da aprire con `read_file`.

Vincoli necessari:

- accesso ai soli asset del package; nessun path arbitrario, URL o documento fornito dal target;
- ID sconosciuto: risposta breve recuperabile con invito a usare il catalogo, senza retry semantico obbligatorio;
- file o sezione mancanti: errore di packaging rilevato prima dell'inferenza, senza run degradata silenziosamente;
- risposte bounded per topic e restituite integralmente. Se un topic è troppo grande, dividerlo editorialmente in unità sensate prima della run, conservando le premesse condivise; evitare tagli arbitrari;
- risultati della knowledge distinti da source reference ed evidenze del target: leggere un consiglio non dimostra un fatto sul codice.

Registrare il tool attraverso `reader_tools` e `_model_tool`, riusando disponibilità, conteggio delle chiamate, history e guard dei boundary. Il tool è disponibile soltanto con `deps.cloudflare_hunting` sul percorso Reader del pilot; non esporlo a Recon, Investigator o baseline. Nei self-checkpoint/turni terminali resta vietato come gli altri tool.

## 6. History, compaction, costi e provenienza

Le risposte entrano nella history ordinaria e persistono finché il contesto le conserva. Non riscrivere il system prompt dopo una lettura; non reiniettare automaticamente tutta la knowledge a ogni turno.

Mantenere la cache locale dei byte/testi immutabili. Non implementare un blocco globale «topic già letto»: dopo una compaction o in un altro Reader può servire rileggerlo. Il metodo comune deve spiegare che, se il dettaglio non è più nel contesto, il topic è nuovamente consultabile; il checkpoint può conservarne il riferimento narrativo quando utile. Il catalogo rimane nel prompt stabile. Non aggiungere un meccanismo separato di compaction o di memoria delle skill.

Riferimento verificato: [DeepSeek Harness, system prompt e contesti](https://github.com/deepseek-ai/deepseek-harness/blob/687f282e8625c6b8d11ff58211a90524be2e4317/packages/core/system-prompt/src/index.ts). Si trasferisce la separazione fra istruzioni e contesto; non il suo registry/plugin system. Cache dei file e cache del provider sono distinte; il testo in history continua a concorrere al contesto e agli EP secondo l'usage reale.

Nel report sperimentale distinguere:

- versione del metodo, ad esempio `reader_knowledge_mode: progressive_v1`, e hash della libreria disponibile;
- letture riuscite effettive: topic, hash del testo restituito, scope Reader e identificatore della tool call, assegnati dal runtime.

Riutilizzare `Deps.recon_reader_experiment` e la pubblicazione esistente del ledger. Ogni child mantiene le proprie letture; `GlobalPipeline.publish` le raccoglie dagli snapshot correnti con lo scope del child, senza riaccumulare gli stessi snapshot. La modalità seriale pubblica i propri eventi. Conservare i dati nei report parziali e finali. Non occorrono tabelle DB o un nuovo ledger.

Gli hash attuali `knowledge_sha256` descrivono gli asset disponibili: non presentarli come prova che il modello abbia letto ogni documento. Le chiamate ordinarie restano nei log/history esistenti; i metadati aggiuntivi servono a rendere il riepilogo del pool verificabile. Nessuna nuova metrica di stuckness o interpretazione deterministica dell'uso delle skill.

## 7. File e sequenza di implementazione

Percorsi relativi alla root Lailaps-1:

| File | Modifica P0 |
|---|---|
| `agent/pentest-agent/src/pentest_agent/knowledge/cloudflare/` | Preparare i quattro riferimenti e aggiornare skill Reader e manifest, con la politica sopra |
| `agent/pentest-agent/src/pentest_agent/cloudflare_hunting.py` | Conservare il caricamento Recon; aggiungere allowlist, catalogo, lettura composta e validazione degli asset; restituire come knowledge Reader iniziale soltanto metodo e catalogo |
| `agent/pentest-agent/src/pentest_agent/triple_agent.py` | Collegare il nuovo prompt Reader e `read_skill`; rispettare i guard tool-free; registrare letture nei metadati esistenti |
| `agent/pentest-agent/src/pentest_agent/cli.py` | Validare l'intera libreria prima dell'inferenza e dichiarare la modalità progressiva nei metadati; mantenere flag e comandi attuali |
| `agent/pentest-agent/src/pentest_agent/global_pipeline.py` | Portare i metadati necessari ai child Reader-only e aggregare le letture per scope; nessuna modifica allo scheduling |
| `agent/pentest-agent/tests/test_recon_reader_assignments.py` | Adeguare i test di prompt/tool e aggiungere verifiche del loader e delle letture con fake |
| `agent/pentest-agent/tests/test_global_pipeline.py` | Verificare che il pool conservi letture distinte nei report parziali e finali senza duplicarle |
| `ARCHITECTURE.md` | Aggiornare la sezione del pilot: metodo/catalogo, tool Reader, history e provenienza |
| `plans/cloudflare-skills-test-20261020/RUN.md` | Descrivere la nuova variante e mantenere i comandi manuali a quattro Reader |

Ordine: adattare e verificare gli asset; implementare loader/catalogo/tool; collegare prompt e report del pool; eseguire le verifiche offline; aggiornare architettura e avvio manuale. Usare lo stesso flag `--cloudflare-hunting`; distinguere le run dalla modalità e dagli hash, senza un nuovo selettore di varianti.

Nessuna modifica prevista a Laravel, ai contratti dei prodotti agentici o al budget. Se una verifica mostra un ostacolo reale, risolverlo nel punto minimo necessario, senza espandere il piano a Investigator o a una riprogettazione della Recon.

## 8. Verifiche e criteri di completamento

Usare modelli fake e asset locali; nessuna chiamata provider.

1. Con flag spento, prompt e tool baseline restano invariati; con flag acceso il Reader vede metodo/catalogo e può leggere un topic, mentre i corpi degli approfondimenti non sono caricati preventivamente.
2. Il risultato contiene la classe e il suo contesto condiviso. Verificare recupero di dettagli tecnici rappresentativi, non solo presenza di heading o corrispondenza letterale del prompt.
3. ID sconosciuti e tentativi di path/URL non accedono al filesystem del target. Asset mancanti o mapping incoerente falliscono prima del bootstrap agentico.
4. Lo stesso topic è rileggibile dopo `begin_next_epoch`; nessuna cache condivisa fra Reader ne sopprime la risposta. Nei turni tool-free non viene eseguito.
5. Lead soft, enrichment e checkpoint mantengono i contratti; nessun percorso espone il tool all'Investigator o avvia downstream. Con un modello fake verificare che una lead altrimenti valida con trace incompleta e mitigazione non verificata negli unknowns venga acquisita anche senza aver letto skill; l'orchestratore non deve richiedere prove aggiuntive, controprove o checklist. Questa verifica copre il flusso: la revisione editoriale deve controllare separatamente che ogni testo model-facing rispetti la divisione Reader/Investigator.
6. Fake del pool a quattro Reader: letture separate per scope, pubblicazione ripetuta senza duplicazioni e provenienza nei parziali/finali. Conservare i test esistenti di cap aggregato e riuso Recon, estendendoli solo se il nuovo percorso li coinvolge.
7. Costruire una wheel offline con gli strumenti già disponibili e verificare la lettura degli asset dal package installato, senza dipendere dal checkout o dal path della working directory. Controlli sintattici e test mirati; nessun lint globale.

Completamento tecnico: tutti i topic annunciati sono leggibili, gli asset hanno provenienza e copertura editoriale, il prompt iniziale contiene soltanto metodo/catalogo aggiuntivi, i report distinguono disponibilità e letture, i contratti e le verifiche sopra passano. Questo non dimostra ancora un miglioramento della discovery: la qualità va osservata nel benchmark manuale.

## 9. Benchmark manuale successivo

L'implementatore consegna i comandi di [RUN.md](RUN.md), aggiornati e verificati nelle opzioni, senza eseguirli. Prima occorre ricostruire l'immagine con i nuovi asset.

Cacti: riusare `cacti-recon-reader-global-20261020-144734`, con quattro Reader, 500k EP aggregati e Investigator disattivato. L'[estrazione della Recon](CACTI_RECON_REUSED.md) documenta il contenuto. Il riuso evita una nuova Recon e una nuova esecuzione Semgrep, mantenendo esplicito il precedente `failed_open`; non corregge il timeout del sensore.

YesWiki: stessi quattro Reader e cap; conservare Recon fresca finché non esiste un artifact compatibile da riutilizzare secondo i controlli già implementati. Modello, reasoning e snapshot restano quelli fissati nel pilot.

Confrontare lead uniche plausibili/EP, duplicati, incertezze e fallimenti; aggiungere quali topic sono stati letti e alcuni esempi di come hanno orientato verifiche utili. Il numero di letture non è una metrica di successo. Il confronto con le golden storiche rimane indicativo, con i limiti già documentati; una run parziale non va presentata come completata a 500k.

## 10. P1+ non inclusi

- Altri companion su confini pertinenti: protocolli/messaging, risorse/disponibilità, supply chain/plugin, cloud, AI o native. Mantenerli come estensione successiva degli asset; non pubblicare topic inesistenti nel catalogo P0.
- Esperimenti controllati sul preload selettivo per incarico o sulla granularità dei topic, se le run mostrano scarsa consultazione o overhead concreto.

L'integrazione Investigator è una decisione separata, da riprendere dopo il risultato Reader; non è una fase esecutiva di questo piano.
