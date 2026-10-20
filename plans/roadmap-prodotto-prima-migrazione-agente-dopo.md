# Lailaps-2 — prima il prodotto, poi la migrazione dell'agente

Data: 17 ottobre 2026. Stato: roadmap proposta, con avanzamento iniziale aggiornato sulle indicazioni dell'utente.

**Ordine concordato: riusare temporaneamente il motore Python di lailaps-1 → completare lailaps-2 come prodotto → migrare il core agentico in TypeScript come ultima fase.**

Questa è la roadmap di riferimento per il nuovo ordine dei lavori. La [roadmap precedente](rewrite-laravel-typescript-ai-sdk.md) resta come analisi del rewrite e delle semplificazioni, ma non determina più la sequenza di implementazione. I [requisiti di prodotto](requisiti-prodotto-lailaps.md) restano il catalogo funzionale; le priorità, gli stati e l'ordine di consegna sono quelli di questo documento.

## 1. Perimetro e regole di lettura

- **Destinazione:** lailaps-2, sviluppato prevalentemente a mano. Il checkout lailaps-1 disponibile qui è un riferimento e il fornitore temporaneo del motore, non il progetto frontend da estendere.
- **Completato su conferma dell'utente:** gestione del progetto necessaria al caricamento, upload/persistenza del codice e preparazione del progetto. Si mantengono nel piano come milestone concluse, senza programmarne la riscrittura.
- **Da fare esplicitamente:** login GitHub e collegamento/import dei repository. La presenza di codice simile in lailaps-1 non rende queste feature complete in lailaps-2.
- **Da fare nel nuovo prodotto:** integrazione dello scanner Python, esperienza di esecuzione, risultati/report, funzionalità di prodotto restanti e accettazione complessiva.
- **Migrazione del core TS:** ultima fase, dopo una release del prodotto funzionante con Python. Le capacità investigative del motore sono una dipendenza già disponibile, non milestone Recon/Reader da riscrivere prima della prima scan.

Gli stati dichiarati qui riguardano lailaps-2. Le milestone M1/M2 sono completate in base all'aggiornamento dell'utente, non a un'ispezione del suo checkout o a test eseguiti in questa sessione. I gate relativi sono criteri di conservazione e integrazione, non richieste di rifare il lavoro. Funzionalità non confermate non vengono promosse a completate per inferenza.

Il piano copre il prodotto completo. **Non è una promessa di completare tutte le milestone entro la demo di fine novembre.** Il primo incremento operativo è M3 → M4 → M5; l'accettazione finale del prodotto precede comunque la migrazione.

## 2. Architettura durante il periodo Python

```text
Vue / Inertia
      ↓
Laravel: utenti, progetti, revisioni, Lab, run, risultati e report
      ↓
Job Laravel: launcher + supervisione + adattatore Python
      ↓
Motore lailaps-1 congelato: scan globale nell'ambiente preparato
      ↓
Artifact strutturati, transcript e journal disponibili
      ↓
Adattatore → acquisizione/normalizzazione Laravel → DB → SSE → UI
```

### Responsabilità

| Componente | Responsabilità |
|---|---|
| Laravel | Autorizzazione, progetti/revisioni, preparazione esistente, queue, provisioning e risorse, lifecycle delle run, persistenza del prodotto, piani/quote, report e SSE |
| Adattatore Python | Costruire l'input del motore, tradurre percorsi/riferimenti, acquisire checkpoint e risultati, correlare identificativi e gestire stop/errori del processo |
| Python lailaps-1 | Recon, Reader, investigazione statica/dinamica, memoria, tool, budget investigativi e decisioni; senza riscrittura del loop in questa fase |
| Runtime TS già presente | Continua a svolgere le funzioni già implementate in lailaps-2, per esempio il Doctor se presente; non diventa obbligatoriamente un intermediario tra Laravel e Python |
| Storage privato | Snapshot sorgenti, ricette/manifest, artifact originali ed evidenze grandi con accesso autorizzato |

Il Lab è legato alla revisione immutabile. La preparazione riutilizzabile e l'istanza effettivamente accesa restano concetti distinti, usando i modelli già realizzati in lailaps-2: non rinominare o migrare lo schema solo per aderire ai nomi del vecchio piano C2.

### Contratto piccolo, prima di un framework di motori

Input interno: run ID, identità/hash della revisione, root sorgente, ricetta e istanza del Lab, target e attori autorizzati, profilo modello/budget e directory privata degli artifact. I percorsi dell'host non sono dati forniti liberamente dal browser.

Operazioni necessarie: **avviare, acquisire progresso/checkpoint, richiedere stop, finalizzare e riconciliare una run interrotta**. Il comando CLI principale è l'ingresso operativo; i benchmark non diventano il launcher ordinario di un progetto utente.

Output del prodotto: stato tecnico, fase/progresso quando disponibili, risultati e milestone, prove disponibili, limiti, modello effettivo, usage, tempi ed errori. Non esporre alla UI il JSON Python come schema obbligatorio del frontend. Non costruire subito un'interfaccia astratta per ogni futuro motore: inizialmente bastano il launcher e una funzione/servizio di acquisizione ben delimitati.

### DB, artifact, API interne e SSE

1. **Laravel è l'unico writer del DB del prodotto.** Lo stato tecnico del job/run e le proiezioni consultate dalla UI appartengono a Laravel; Python produce output investigativi, conservati anche come artifact originali per diagnosi e recovery.
2. **Durante il riuso di Python il bridge legge i contratti esistenti.** L'outcome è acquisito ai checkpoint; il transcript è diagnostica, non una sorgente da interpretare semanticamente per inventare finding. Il journal strutturato può fornire tool call e prove quando realmente presente.
3. L'API interna rimane il contratto previsto per i runtime che pubblicano direttamente, compreso il futuro TS. Il bridge nello stesso processo Laravel usa direttamente il medesimo servizio di acquisizione: nessuna chiamata HTTP a sé stesso necessaria. Non introdurre modifiche al loop Python solo per sostituire il trasporto prima della prima scan funzionante.
4. Ogni acquisizione è associata alla run e alla versione dello snapshot; replay dello stesso dato non duplica finding/consumi. Un cursore monotono del prodotto è assegnato da Laravel. Prima si esegue il commit, poi si notifica la UI.
5. **SSE rimane il trasporto UI.** Snapshot iniziale, cursore e recupero degli eventi persi al reconnect. Un controllo periodico degli artifact nel supervisore è ammesso in questa fase; non richiede polling del browser. Non promettere notifiche tool/token che Python non produce.
6. Non salvare ogni token e non duplicare i body grandi in più tabelle. DB per stato, metadati, risultati e riferimenti; storage privato per artifact estesi. Gli artifact originali rimangono leggibili se una proiezione fallisce.
7. In caso di errore di persistenza si ritenta l'acquisizione dello stesso output, non la chiamata LLM né l'intero audit. Uno stop conserva l'ultimo risultato acquisito e arresta anche le risorse del processo/container possedute dalla run.

Il kernel di acquisizione viene verificato con un produttore simulato prima di usare provider reali. Il filesystem condiviso è un vincolo accettato del primo deployment; un protocollo remoto più ampio è P1 quando il deployment lo richiede.

## 3. Avanzamento e sequenza delle milestone

| Milestone | Stato | Risultato | Dipendenze |
|---|---|---|---|
| M0 — Baseline C0 | **COMPLETATO** | Riferimenti Python YesWiki/BookStack congelati | — |
| M1 — Progetto, ZIP e revisione | **COMPLETATO — confermato dall'utente** | Progetto caricato e codice persistito | — |
| M2 — Preparazione del progetto / Lab | **COMPLETATO — confermato dall'utente** | Progetto preparato per il percorso già implementato | M1 |
| M3 — Prima scan Python dal nuovo prodotto | Da fare | Scan globale collegata a revisione e ambiente di lailaps-2 | M1, M2 |
| M4 — Lifecycle, persistenza e progresso SSE | Da fare | Run controllabile e consultabile, anche dopo disconnessione | M3 |
| M5 — Finding, prove e report | Da fare | Risultati persistenti, dettaglio e export | M4 |
| M6 — Login GitHub e repository | **Da fare** | Accesso GitHub, link repo e import su commit fissato | M1; M3–M5 per il percorso completo |
| M7 — Target, attori e gestione del progetto | Da integrare/verificare | Modalità e capacità esistenti utilizzabili dal nuovo prodotto | M2, M4, M5 |
| M8 — Regression testing e retest | Da fare | Confronti riproducibili e primi test di sicurezza riutilizzabili | M5, M7 |
| M9 — Piani utente, quote e abbonamenti | Da fare | Limiti applicati dal server e flusso commerciale di prova | M4, M5 |
| M10 — Chiusura e release del prodotto con Python | Da fare | Percorso completo accettato, operatività e dati gestiti | M3–M9 |
| M11 — Migrazione finale del core in TS | **Ultima fase, non iniziata** | Prodotto invariato nelle sue funzioni, nuovo motore verificato | M10 e suite M8 |

L'ordine consente di mostrare un percorso ZIP → scan → report prima di GitHub o abbonamenti. Non sposta la preparazione già completata dentro una nuova milestone di rewrite. M7 completa/verifica la sua integrazione con lo scan e le modalità di prodotto; non riapre M2 per principio.

## 4. Schede delle milestone e gate

### M0 — Baseline C0 · COMPLETATO

Disponibili il [pacchetto congelato](../benchmarks/baselines/python-c0-20261007/README.md) e l'[annotazione manuale BookStack](../benchmarks/baselines/python-c0-20261007-ground-truth.json). I risultati LLM Python sono il riferimento operativo iniziale. Per BookStack l'annotazione aggregata 4/5 resta separata dallo stato suspected persistito; non si promuovono automaticamente finding nel prodotto per copiare la ground truth del benchmark.

**Conservazione:** archivi e hash originali restano invariati. Non sono disponibili tutti gli elementi per ricreare la vecchia run: clock incerti e snapshot storici mancanti rimangono limiti, non dati da inventare.

Il motore attuale è evoluto rispetto a C0, anche nel catalogo minimal e nei journal. M3 ne registra una versione esatta distinta: riusare Python non significa fingere che il checkout odierno coincida con le due run storiche.

### M1 — Progetto, upload ZIP e persistenza · COMPLETATO

**Già consegnato secondo l'utente:** creazione/gestione del progetto necessaria al flusso, caricamento ZIP, acquisizione e persistenza della sorgente/revisione. Nessun nuovo task per riscrivere questi moduli.

**Gate da preservare:** nuova revisione distinta a ogni import; identità/hash stabili; snapshot non alterato dalla copia eseguibile; sorgenti e artifact accessibili al proprietario. Errori e archivi non validi mantengono un esito comprensibile. I test esistenti si riusano nelle verifiche di integrazione.

### M2 — Preparazione del progetto · COMPLETATO

**Già consegnato secondo l'utente:** il flusso di preparazione disponibile in lailaps-2. Si conservano UI, dati, Doctor/servizi e runtime già realizzati, qualunque sia la loro implementazione effettiva.

**Gate da preservare:** la preparazione appartiene alla revisione corretta, conserva configurazione/artifact e rende esplicito ready/blocco/errore. Le domande e risposte, se fanno parte del flusso attuale, restano persistenti e i segreti protetti. La compatibilità di questo ambiente con l'executor Python viene verificata in M3, senza riscrivere la preparazione.

Il [precedente approfondimento C2](c2-lailaps-2-lab-runtime-doctor.md) è un riferimento tecnico per Lab/istanza e confini, non un elenco di attività ancora tutte da implementare.

### M3 — Prima scan globale Python dal progetto preparato

**Lavoro P0:** aggiungere al nuovo prodotto il launcher del motore esistente. Usare una versione del motore fissata e riproducibile; il motore non dipende dal DB di lailaps-1. Riutilizzare le dipendenze operative effettivamente necessarie senza installare tutta la vecchia webapp come backend del nuovo prodotto.

Registrare runtime/package manifest, dipendenze/immagini, configurazione modelli e tool, hash del codice e condizioni del target. Un commit Git con working tree sporco non identifica da solo la versione. Niente aggiornamento automatico del core da una cartella lailaps-1 in evoluzione.

Percorso: progetto/revisione → preparazione accettata → istanza pronta → run queued → job → comando principale globale Python → artifact → prima acquisizione dei risultati a DB. Conservare il contesto Lab e le capacità dell'executor, incluse shell/toolbox/target richieste dal core minimal corrente. Il comando parte su input costruiti dal server, non su stringhe shell arbitrarie ricevute dal browser.

**Test senza LLM:** processo simulato con outcome realista, verifica degli argomenti/mount/path e delle relazioni; consegna duplicata del job non avvia due scan; preparazione errata/scaduta non avvia il modello.

**Accettazione live, solo su richiesta:** una scan budgetata su un target già preparato, avviata dalla UI nuova, produce risultati collegati a quella revisione. Avvio e conclusione non richiedono interventi nel vecchio frontend o nel vecchio DB.

### M4 — Controllo della run, persistenza e SSE

**Lavoro P0:** rendere espliciti queued/preparing/running/finalizing e gli esiti tecnici, conservando anche un risultato investigativo incompleto. Distinguere errore del processo, provider, tool e acquisizione; exit 0 da solo non prova un report finale completo. Chiusura del browser non interrompe il job.

Persistere checkpoint/proiezioni e un feed di eventi significativo. La UI mostra fase quando disponibile, tempo, consumi, risultati parziali e ultimo errore; i log diagnostici sono una vista separata. Cancellazione/timeout hanno un solo percorso di finalizzazione e cleanup. Ripartire significa nuova run con input identificati; non implementare il resume esatto della conversazione.

**Accounting:** tempo queue, preparazione, scan e finalizzazione separati; usage aggregato senza doppio conteggio; EP con regola/versione; costo provider/stimato distinti; missing resta unknown. Lead/minuto e lead/EP dichiarano stadio, denominatore e stato delle lead; non usare il tempo di attesa utente come tempo attivo del Doctor.

**Test senza LLM:** eventi/snapshot duplicati, output parziale, JSON incompleto in scrittura, errore DB, processo fallito, worker interrotto, stop e timeout conservano l'ultimo checkpoint. Reconnect SSE recupera dal cursore e un altro utente non riceve dati della run. Nessun errore di acquisizione causa una nuova chiamata modello.

**Completato quando:** l'utente avvia, chiude la pagina, rientra, vede progresso e risultati salvati e può interrompere l'attività con rilascio delle risorse. Un crash resta distinguibile da un audit senza finding.

### M5 — Finding, evidenze e report di prodotto

**Lavoro P0:** storico run per progetto/revisione; elenco e dettaglio finding; sospetto, confermato, respinto e inconclusivo distinti; milestone statica, decisione originale e stato tecnico conservati. Titolo, impatto, precondizioni, codice, prove e indicazioni di rimedio provengono dagli output disponibili, con provenienza. Non generare una seconda analisi LLM obbligatoria per rendere ogni risultato visualizzabile.

Report HTML persistente ed export JSON/Markdown: revisione, ambiente testato, modello/configurazione, perimetro, risultati, copertura/limiti, tempi e consumi. Prove grandi e journal accessibili on demand tramite autorizzazione; record started con esito ignoto o output troncato restano tali. La retention di una sandbox non determina la leggibilità del report.

**Test senza LLM:** import di outcome congelati con verdetti differenti, mancanza prove e fallimento finale; mapping stabile, segreti redatti, export coerente e consultazione dopo teardown. Annotazioni utente non sovrascrivono il verdetto originale.

**Completato quando:** ZIP → preparazione → scan Python → report consultabile ed esportabile è un percorso intero del nuovo prodotto. Questa è la prima demo verticale utilizzabile.

### M6 — GitHub: login e collegamento repository · DA FARE

Due funzioni distinte:

1. **Accesso all'app con GitHub:** login/registrazione e collegamento all'account locale. Collegare a un account esistente richiede un'associazione autenticata, non un merge silenzioso basato sulla sola email restituita.
2. **Accesso ai repository:** autorizzazione ai repository scelti, selezione repo/ref e acquisizione su commit immutabile. Il login non concede automaticamente permessi di lettura ai repository privati.

Usare il flusso di identità del framework e un'integrazione GitHub con permessi minimi per il percorso repo. Credenziali cifrate/revocabili, branch/tag risolti a SHA e artifact importati attraverso la materializzazione già usata dagli ZIP. Non serve sincronizzazione continua o audit automatico sulle PR.

**Test:** callback/state validi e invalidi, account/link già esistente, permessi mancanti, token revocato, repo privato autorizzato, import fallito e branch avanzato dopo l'acquisizione. I test automatici simulano GitHub; la prova reale di login/import è un'accettazione manuale separata.

**Completato quando:** un utente entra con GitHub, collega un repo autorizzato, importa una revisione e percorre lo stesso flusso di preparazione/scan/report dello ZIP. La revoca non lascia una connessione falsamente funzionante.

### M7 — Modalità target, attori e ciclo di vita del progetto

**Lavoro P0 della milestone:** integrare nel prodotto le modalità previste: sandbox, URL con toolbox locale e ibrido. Riutilizzare la preparazione già completata; aggiungere soltanto i collegamenti o i percorsi effettivamente mancanti.

Prima della scan mostrare revisione, destinazione dei test e capacità disponibili. Il Lab rimane legato alla sorgente anche con URL remoto; la copia locale non concede shell/DB sul server remoto. Ogni prova identifica la propria istanza/URL; una conferma locale non viene attribuita al remoto. Corrispondenza di versione remota non verificata dichiarata nel report.

Attori: configurazione credenziali/sessioni necessarie, almeno due attori isolati sui target validati, errori di login espliciti. Rilancio su revisione conservata, nuova revisione con nuovo contesto, reset della sandbox e stop/scadenza/cleanup. Completare impostazioni account/progetto, eliminazione e retention senza assumere che il CRUD di upload includa già tutte queste funzioni.

**Test:** corretta destinazione delle richieste, attori senza cookie condivisi, versione/setup incompatibili rifiutati, risorse scadute e cancellazione del progetto senza risorse orfane. Capacità HTTP/browser provate su casi controllati; MFA/SSO arbitrari non sono un requisito universale.

**Completato quando:** i tre percorsi supportati sono selezionabili e mostrano limiti/evidenze corretti, con configurazioni effettivamente validate e dati gestibili dall'utente.

### M8 — Regression testing: motore/prodotto e sicurezza del progetto

Questa milestone distingue due funzioni, evitando di chiamarle entrambe soltanto benchmark.

**M8a — Non regressione del prodotto e del motore:** fixture di outcome/journal, replay dell'adattatore, test di ownership/lifecycle/persistenza e comparatore per stadio/end-to-end. Registrare input, modello/budget, runtime, stato iniziale del Lab ed evaluator. Riutilizzare C0 come riferimento storico; aggiungere output controllati della versione Python effettivamente congelata, senza alterare C0. Valutare ipotesi/milestone/evidenze oltre ai conteggi; tempi, lead/minuto, lead/EP, errori e dati mancanti restano separati.

**M8b — Retest e test di regressione di sicurezza per l'utente:** partire da un finding con prova ripetibile. Conservare recipe/spec con revisione, attori, setup, operazione, comportamento sicuro atteso e prova originaria; permettere di rilanciarla su un ambiente/revisione nuovi e mostrare vulnerabile, proprietà verificata o inconclusivo. Un timeout, login fallito o assenza dati non prova la correzione. Distinguere confronto fra finding di due scan da un retest mirato realmente eseguito.

Iniziare da un caso HTTP o browser supportato e da spec revisionabili. Nessun nuovo agente Tester obbligatorio e nessun generatore universale di test multilingua prima della chiusura del prodotto. L'output di Python è una base, non una promessa che ogni PoC sia già esportabile o contenga segreti riutilizzabili.

**Test:** replay offline stabile, confronto che segnala una lead persa e un falso miglioramento per duplicati; sul caso scelto il test rileva il comportamento vulnerabile e verifica il comportamento sicuro su una variante corretta nota. I controlli runtime usano fixture controllate; le nuove esecuzioni LLM sono separate e richieste esplicitamente.

**Completato quando:** sono disponibili sia una suite interna di non regressione sia almeno un percorso utente finding → test/retest → risultato persistito. Il confronto con TS sarà innestato qui, nell'ultima fase.

### M9 — Piani, limiti e abbonamenti

**Lavoro P0 della milestone:** pochi piani comprensibili, capacità/quote dichiarate e applicate dal backend: dimensione import, profili/budget ammessi, numero di scan o crediti secondo una regola fissata, concorrenza e retention se previste. Mostrare disponibilità e consumi; il costo tecnico LLM non diventa automaticamente l'unità commerciale.

Prima applicare quote e assegnazione del piano anche manuale; poi collegare checkout/gestione abbonamento del provider scelto, in ambiente di test. Eventi di pagamento verificati e idempotenti aggiornano i diritti; il redirect di successo da solo non abilita un piano. Una prenotazione di quota evita doppia spesa da due richieste simultanee; cancellazione/fallimento regolano consumo/rilascio secondo una policy esplicita, senza promettere rimborsi automatici di token già consumati.

**Test senza pagamenti reali:** limiti server-side, tentativo di aggiramento, webhook duplicato/fuori ordine, upgrade/downgrade/cancellazione, run fallita e doppio avvio. Il flusso sandbox dimostra il piano e i diritti. Nessun incasso reale richiesto dalla demo.

**Completato quando:** il piano assegnato limita davvero l'uso e il flusso commerciale di prova aggiorna correttamente accessi/quote. Fiscalità, promozioni, ruoli enterprise e ottimizzazioni commerciali restano P1/P2.

### M10 — Chiusura del prodotto con Python

**Lavoro:** consolidare il prodotto, eliminare workaround di integrazione non più necessari e documentare avvio, configurazione, versioni supportate e limiti. Deploy/demo ripetibile con servizi, queue, storage, SSE, segreti e cleanup configurati. Nessun core TS richiesto da questa milestone.

**Gate di chiusura:**

- Login ordinario e GitHub, ZIP e repo collegato, revisione identificata e preparazione già disponibile.
- Scan Python dal nuovo prodotto; progresso, stop/timeout/errori, risultati parziali e report/export persistenti.
- Target/attori supportati e distinzione sandbox/URL/ibrido verificati.
- Test/retest di sicurezza e suite di non regressione disponibili.
- Piani/quote server-side e abbonamento dimostrabile in sandbox.
- Dati di due utenti isolati, segreti redatti, retention/cancellazione e risorse gestite.
- Istruzioni di boot e smoke da ambiente pulito, esito verificato sui target dichiarati. Nessun lancio di lailaps-1 UI/DB necessario per una scan ordinaria.

Lo stato tecnico della run e il contenuto del risultato restano distinti anche nel report finale. Errori noti sono dichiarati; nessun benchmark live è eseguito automaticamente per dichiarare la milestone completa.

**Completato quando:** lailaps-2 è presentabile e utilizzabile come prodotto autonomo con il motore Python versionato. Da qui la sostituzione del motore è un progetto interno con un riferimento stabile.

### M11 — Migrazione del core in TypeScript · ULTIMA FASE

Prerequisito: M10 accettato e nessuna funzionalità essenziale del prodotto lasciata in attesa del port. Il prodotto continua a funzionare con Python durante lo sviluppo TS.

Sotto-milestone progressive, ciascuna con test e confronto prima di procedere:

1. **M11.1 — Specifica del core congelato:** fissare percorso principale, contratti, catalogo minimal realmente operativo, prompt, history, budget e invarianti. Separare varianti/esperimenti dal comportamento da conservare. I vecchi file non definiscono da soli la specifica.
2. **M11.2 — Runtime TS/AI SDK:** provider OpenRouter già scelto, primitive e history, accounting, cancellazione e output verso il servizio/API Laravel. Provider simulato con tool reali; eventi idempotenti e persistenza verificati prima di chiamate live.
3. **M11.3 — Recon:** risultato persistito nello stesso prodotto e confronto con input/ambiente fissati. Il contratto pubblico della UI rimane stabile.
4. **M11.4 — Reader:** checkpoint, lead, deduplica e riferimenti; replay con Recon congelata e confronto delle lead. Nessun requisito di tradurre tutte le vecchie strategie.
5. **M11.5 — Investigatore unificato:** milestone statica prima della dinamica, HTTP/browser/target, evidenze, history/compaction e budget. Replay su lead congelate e controlli positivi/negativi.
6. **M11.6 — Pipeline end-to-end:** isolamento delle sessioni, coordinamento/concorrenza necessari, budget condivisi, drain e cleanup. Confronto completo di qualità, errori, costi e tempi.
7. **M11.7 — Switch:** TS diventa motore ordinario soltanto dopo l'accettazione. Rimuovere il launcher/core Python dal prodotto e le compatibilità temporanee; conservare fuori dal percorso operativo fixture, evaluator e risultati storici necessari. Rollback verificato prima del ritiro.

Gli stadi TS sono verificabili isolatamente; non è obbligatorio introdurre un'orchestrazione mista Python/TS dentro una singola scan. Un solo selettore di motore al lancio è sufficiente quando esistono due percorsi end-to-end eseguibili.

**Gate finale:** capacità protette conservate semanticamente, risultati persi/cambiati/extra espliciti e nessuna regressione non accettata; una lead nuova non compensa automaticamente una conferma persa. Il guadagno di semplicità si misura insieme a qualità/costo/tempo, senza introdurre soglie statistiche ricavate da due sole run storiche.

## 5. Testing e aggiornamento degli stati

Ogni milestone aggiunge soltanto le verifiche pertinenti al proprio cambiamento. Nessun lint dell'intero progetto; nessun test che avvia lunghi loop agentici o chiamate provider eseguito autonomamente.

Tre livelli: **offline** (contratti, fixture, proiezioni, autorizzazione), **integrazione senza LLM** (processi simulati, Docker/HTTP/browser su fixture, SSE, mock GitHub/pagamenti), **accettazione live esplicita** (audit budgetati, login/import reali e benchmark quando richiesti). Un mock valida l'integrazione, non la qualità investigativa.

Per ogni gate registrare versione del prodotto/motore, revisione/ambiente, input/configurazione, verifiche svolte, risultato e limiti. Una feature viene marcata completata con evidenza o conferma esplicita dell'utente; la presenza di codice donatore non basta.

## 6. Confine della demo di novembre e P1/P2

**Primo P0 esecutivo: M3 → M4 → M5**, conservando M1/M2 completati. Questo consegna il primo percorso reale del nuovo prodotto. GitHub, modalità estese, retest e piani vengono poi completati con le rispettive milestone; nessuno di questi richiede prima la migrazione del core.

Per la demo di fine novembre, con poche ore settimanali, fissare un sottoinsieme dichiarato e stabile. M3–M5 su un target preparato sono il primo traguardo; aggiungere feature soltanto dopo che questo percorso è affidabile. Una demo del sottoinsieme non viene etichettata come M10 completata. Nessuna data obbligatoria per M11.

**P1/P2, dopo il percorso richiesto:** team e collaborazione avanzata, PDF, webhook/CI/scheduling, PR audit automatici, provider Git ulteriori, pool Lab remoti, VM distribuite, MFA/SSO arbitrari, generazione universale di test e autofix. Miglioramenti del vecchio loop non necessari all'integrazione restano fuori dal lavoro sul prodotto; se il core viene aggiornato, cambia versione e richiede verifica comparativa.

## 7. Riferimenti e verifiche di questo piano

Letti i piani precedenti, requisiti, ARCHITECTURE.md corrente e contratti artifact/launcher del donatore. Il branch attuale ha un catalogo minimal e journal Investigator oltre all'outcome: il bridge deve usare i contratti della versione fissata, senza reintrodurre tool legacy descritti in sezioni storiche.

Consultato DS Harness al commit `687f282e8625c6b8d11ff58211a90524be2e4317`: [session README](https://github.com/deepseek-ai/deepseek-harness/blob/687f282e8625c6b8d11ff58211a90524be2e4317/packages/core/session/README.md) e [implementazione](https://github.com/deepseek-ai/deepseek-harness/blob/687f282e8625c6b8d11ff58211a90524be2e4317/packages/core/session/src/index.ts) separano il record della sessione dai backend di persistenza e prevedono un checkpoint di durabilità atteso. È un riferimento per tenere separati motore e acquisizione, non una richiesta di importare framework/plugin o riscrivere ora la sessione Python. Il motore attuale pubblica artifact tramite il proprio contratto; l'adattatore Laravel acquisisce quelli invece di pretendere eventi DS.

Creato soltanto questo piano e aggiunto un rinvio nel vecchio piano principale. Nessun codice runtime cambiato, nessun test/agent loop eseguito e nessun dato cancellato. ARCHITECTURE.md rimane la fotografia dell'implementazione attuale; verrà aggiornata quando saranno implementate modifiche strutturali.
