# Lailaps: importazione ZIP, GitHub e preparazione progressiva dell’ambiente

## Obiettivo e decisioni fissate

Realizzare una demo online privata nella quale un utente possa importare un progetto, rispondere alle informazioni mancanti e avviare un audit su un ambiente verificato.

La consegna procede per milestone indipendenti:

1. **P0.1 — ZIP e sandbox con configurazione esplicita.**
2. **P0.2 — GitHub App con repository, branch e commit.**
3. **P0.3 — Environment Doctor e ambiente auditabile, identici per entrambe le sorgenti.**

Ogni milestone termina con una verifica dimostrabile; la successiva parte soltanto dopo il superamento dei relativi criteri.

Il deployment iniziale utilizza **un server Linux dedicato alla demo**, accesso su invito, progetti concordati e un solo ambiente attivo alla volta. Non comprende provisioning cloud automatico, Runner remoto o registrazione pubblica.

## Contratti comuni e integrazione con Lailaps

- Introdurre `Project`, appartenente a un utente, e `SourceRevision`, immutabile. Una revisione contiene riferimento al progetto, provenienza, impronta del contenuto e posizione privata del sorgente; per GitHub aggiunge repository ID, commit SHA e riferimento selezionato.
- ZIP e GitHub alimentano la stessa materializzazione del sorgente. **Dal confine `SourceRevision` in avanti, preparazione e audit non selezionano comportamenti diversi in base al provider.**
- Separare sorgente originale, copia di esecuzione e artefatti di preparazione. Il Reader legge il sorgente originale; installazioni, build e file generati lavorano nella copia di esecuzione o nell’infrastruttura esterna.
- Introdurre una ricetta versionata dell’ambiente e una sessione di preparazione persistente. La ricetta conserva configurazione, artefatti, riferimenti ai segreti, setup e verifiche; la sessione conserva avanzamento, domande, risposte e diagnosi.
- Ogni nuovo audit collega revisione sorgente e versione della ricetta. I riferimenti sono nullable per gli audit storici.
- Aggiungere operazioni applicative per importare una revisione, preparare un ambiente, leggere lo stato, rispondere alle domande, annullare e avviare l’audit. Il frontend passa identificativi autorizzati, mai percorsi host arbitrari.
- Riutilizzare `SandboxService`, driver Docker/Compose, preparazione, gestione attori e pipeline agentica. La ricetta produce gli input già supportati, inclusi Compose e profilo audit esterni al sorgente.
- Conservare il percorso CLI e benchmark attuale. Le esclusioni specifiche dei benchmark non si applicano ai progetti importati: README e configurazioni CI sono utili al Doctor.
- Configurazioni e sessioni dell’ambiente vivono fuori dagli artefatti della run; rimane il contratto attuale con transcript e outcome.

## Milestone di implementazione

### P0.1 — Importazione ZIP e prima sandbox verificabile

**Esperienza:** creazione progetto → caricamento ZIP → sorgente disponibile → configurazione esplicita → verifica ambiente → avvio audit.

- Realizzare importazione asincrona su storage privato, stato leggibile e ripetizione dopo un errore. Pubblicare la revisione soltanto al termine dell’importazione.
- Estrarre con controlli su traversal, percorsi assoluti, link, collisioni e dimensioni effettive. Default configurabili: 100 MiB compressi, 1 GiB estratti e 50.000 file. Non eseguire script durante l’importazione.
- Normalizzare l’eventuale cartella contenitore dell’archivio. Permettere di scegliere una sottodirectory applicativa, mantenendo disponibile l’intera revisione per dipendenze interne.
- Per questa milestone usare Dockerfile/Compose esistente e configurazione esplicita tramite pannello avanzato: servizio web, endpoint di salute, variabili e profilo di preparazione. La configurazione può essere preparata dall’operatore della demo.
- Conservare i segreti cifrati nel backend e risolverli al momento dell’esecuzione; non inserirli nella ricetta esportabile, negli argomenti CLI o nelle proprietà frontend. Non attivare automaticamente credenziali trovate nel sorgente.
- Introdurre un esecutore locale con operazioni di avvio, stato e annullamento. Applicare un lock globale all’ambiente attivo e cleanup su errore, annullamento e scadenza.
- Validare la configurazione Compose risolta prima dell’avvio: mount confinati al workspace, risorse dedicate, niente socket Docker nel target, modalità privileged, namespace host o risorse esterne condivise.
- Preparare il deployment demo: HTTPS, account creati dall’operatore, worker e scheduler supervisionati, database e Docker non esposti pubblicamente, immagini degli agenti costruite al deployment.

**Gate:** uno ZIP di test viene importato, avviato, verificato e auditato. Una seconda esecuzione non eredita i dati della prima; annullamento e fallimento eliminano le risorse assegnate. Il percorso benchmark continua a funzionare.

### P0.2 — GitHub App e selezione della revisione

**Esperienza:** collega GitHub → autorizza i repository → scegli repository, branch e commit → importa → utilizza la preparazione già disponibile.

- Registrare una GitHub App installabile su account personali e organizzazioni di altri utenti, mantenendo privata la demo Lailaps. Richiedere soltanto lettura dei contenuti e metadati; nessun permesso di scrittura, Checks o Actions nel P0.
- Collegare GitHub all’utente Lailaps esistente tramite autorizzazione della App, senza sostituire il login di Lailaps.
- Usare user access token per selezioni e importazioni richieste dall’utente: l’accesso resta limitato all’intersezione fra permessi dell’utente e della App. Gestire scadenza, refresh e revoca nel backend. [Autenticazione GitHub App](https://docs.github.com/en/apps/creating-github-apps/about-creating-github-apps/best-practices-for-creating-a-github-app).
- Mostrare installazioni accessibili, repository pubblici e privati autorizzati, branch e lista paginata dei commit. Consentire anche la selezione mediante SHA verificato nel repository.
- Risolvere il riferimento a un commit immutabile **prima di accodare l’importazione**. Un avanzamento successivo del branch non cambia il codice acquisito.
- Acquisire lo snapshot tramite archivio GitHub del commit e passarlo all’importatore comune. Il P0 non richiede un clone con history locale: repository ID e SHA preservano la provenienza necessaria per future operazioni Git. [Archivio repository](https://docs.github.com/en/rest/repos/contents#download-a-repository-archive-zip).
- Gestire callback con stato anti-CSRF, controlli di ownership, paginazione, rate limit e autorizzazione nuovamente verificata all’importazione.
- Ricevere webhook firmati per revoca, sospensione e variazioni dell’installazione; deduplicare le consegne. I webhook non avviano audit nel P0. [Verifica delle firme](https://docs.github.com/en/webhooks/using-webhooks/validating-webhook-deliveries).
- Segnalare submodule e puntatori LFS non materializzati: niente download impliciti da ulteriori repository o promozione silenziosa di un sorgente incompleto.

**Gate:** un account invitato importa un repository privato e un commit precedente al branch corrente. Un secondo account non accede alle sue revisioni. Revoca e rimozione del repository impediscono nuove acquisizioni. ZIP e GitHub dello stesso contenuto producono lo stesso manifest normalizzato e utilizzano la medesima preparazione.

### P0.3 — Doctor, wizard e ambiente realmente auditabile

Suddividere questa milestone in tre checkpoint.

**P0.3a — Discovery e domande**

- Aggiungere un Environment Doctor in Python/Pydantic AI; Laravel mantiene stato, autorizzazioni e persistenza.
- Il Doctor legge manifest, lockfile, Dockerfile/Compose, documentazione, esempi di configurazione, CI e setup esistenti. Preferisce configurazioni già presenti prima di generarne di nuove.
- Il wizard mostra ciò che è stato individuato e soltanto le domande irrisolte, tramite testo, scelta o campo segreto.
- Usare output semplici: decisione, spiegazione narrativa, domande e artefatti tramite strumenti dedicati. Identificativi, collegamenti e stato sono costruiti dall’orchestratore.
- Conservare risposte e checkpoint narrativo per riprendere dopo navigazione, riavvio o attesa dell’utente. Non mantenere un processo occupato durante l’attesa.

**Gate:** lo stesso progetto importato via ZIP e GitHub genera requisiti equivalenti; una domanda riceve risposta e la preparazione riprende senza ripeterla.

**P0.3b — Build, diagnosi e riparazione**

- Il Doctor può creare o modificare soltanto artefatti di preparazione e invocare operazioni controllate: build, avvio, lettura log, comandi nei servizi assegnati e verifiche.
- Il Builder valida e applica gli artefatti utilizzando l’esecutore locale. I comandi del progetto vengono eseguiti nei container, mai nella shell del server.
- Il Doctor corregge errori infrastrutturali e di configurazione. Modifiche a codice applicativo, dipendenze bloccate o meccanismi di sicurezza richiedono una decisione esplicita e producono una differenza registrata.
- Separare rete di build e rete di audit: accesso alle dipendenze durante la preparazione; target confinato ai servizi dell’ambiente durante l’audit. Integrazioni esterne necessarie sono configurate esplicitamente con risorse di test.
- Applicare limiti indipendenti dall’audit: massimo tre cicli di riparazione e 30 minuti di lavoro attivo per tentativo, configurabili. Alla soglia, conservare diagnosi e lavoro svolto; l’utente può riprendere.
- Riutilizzare la configurazione modelli esistente con un override dedicato al Doctor.

**Gate:** il Doctor risolve una dipendenza runtime mancante, si arresta correttamente su un requisito esterno irrisolto e non modifica il sorgente originale.

**P0.3c — Identità, strumenti, reset e handoff all’audit**

- Preparare dati sintetici e identità usando seed, comandi o flussi dell’applicazione. Conservare descrizioni narrative di ruoli, tenant e ownership, distinguendo informazioni dichiarate da quelle verificate.
- Verificare login, risorse rappresentative e servizi necessari, compresi eventuali worker di coda. Abilitare browser e strumenti runtime disponibili attraverso i backend esistenti.
- Registrare separatamente capacità funzionanti, mancanti e non applicabili. Evitare una generica etichetta “FULL”.
- Implementare reset mediante ricreazione di container e volumi più setup/seed. Verificarlo con una modifica di prova e successivo controllo del ripristino.
- Salvare la ricetta riuscita. Ogni audit ricrea un ambiente pulito e ripete il preflight; il riuso della ricetta non implica riuso dei dati.
- Bloccare l’avvio quando mancano requisiti essenziali. Per capacità opzionali mancanti, mostrare la limitazione e consentire un avvio consapevole.
- Passare al motore sorgente immutabile, target verificato, attori, capacità e differenze dell’ambiente. Le evidenze della preparazione non diventano finding confermati.

**Gate:** completare il percorso su una fixture PHP con Compose e una Python senza Dockerfile, entrambe via ZIP e GitHub. L’audit deve poter autenticarsi, consultare dati e usare il browser dove applicabile. Una seconda preparazione riusa le risposte compatibili e ripete le verifiche.

## Verifica, compatibilità e osservabilità

- Test mirati per importazione, ownership, selezione commit, refresh/revoca GitHub, firme webhook, sessioni Doctor e lifecycle delle risorse.
- Usare risposte GitHub simulate e un Doctor simulato nei test deterministici; aggiungere prove reali controllate come gate delle milestone.
- Verificare cancellazione, riavvio del worker, scadenza dell’ambiente e consegne duplicate senza duplicare esecuzioni.
- Misurare durata della preparazione, numero di domande, riparazioni e motivo del fallimento, separatamente dalla telemetria dell’audit.
- Conservare sorgenti e workspace temporanei per sette giorni, configurabili, proteggendo le sessioni attive; ricette e report rimangono persistenti. La UI espone la scadenza e la necessità di reimportare.
- Aggiornare `ARCHITECTURE.md` insieme all’introduzione dei nuovi contratti e del Doctor. Eseguire test selettivi e controlli sui file interessati; non usare il lint dell’intero progetto.

## Evoluzioni successive

- **P1:** ampliare le prove su stack e autenticazioni, migliorare cache e supporto a submodule/LFS, consolidare il broker runtime per eliminare il socket Docker dal container agente legacy.
- **P1:** introdurre audit guidati dal diff usando repository e commit già persistiti. Il diff stabilisce la priorità dell’indagine; il sorgente completo rimane disponibile per seguire dipendenze, autorizzazioni e propagazione dei dati.
- **P2:** webhook di push/PR, GitHub Action, pubblicazione dei risultati su GitHub e Runner remoto.
- **P2:** separazione su una VM di esecuzione e successivo provisioning di VM per ambiente.

Il P0 resta una configurazione per demo controllate: introduce confini applicativi riutilizzabili, senza anticipare l’infrastruttura necessaria a un servizio pubblico.
