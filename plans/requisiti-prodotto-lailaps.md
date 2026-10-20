# Lailaps — Requisiti di prodotto e demo completa

Data: 7 ottobre 2026. Stato: proposta di requisiti; non certifica funzionalità già implementate.

Questo documento descrive ciò che l'utente deve poter fare, i risultati attesi e come riconoscere un prodotto completo. La roadmap di rewrite descrive invece l'ordine di costruzione. Numero di agenti, tool, framework e moduli interni sono scelte di implementazione: non sono feature da riprodurre.

## 1. Promessa del prodotto

**Collego il codice del mio progetto, preparo un ambiente di test realistico e ottengo un audit che distingue problemi sospetti da vulnerabilità confermate, con prove, limiti e indicazioni di correzione comprensibili.**

Lailaps è una webapp per audit autorizzati in un laboratorio protetto. Il perimetro della demo è difensivo e didattico: progetti campione e applicazioni di test. L'utente principale è uno sviluppatore che conosce il proprio progetto ma non vuole ricostruire manualmente ogni ipotesi, ambiente e test di sicurezza.

Il risultato utile non è una lista di alert: è capire **che cosa è vulnerabile, perché, in quali condizioni, come è stato verificato e che cosa resta incerto**. Un audit senza finding deve comunque rendere visibile ciò che è stato esplorato e ciò che non è stato verificato.

## 2. Perimetro e priorità

**P0 — demo molto completa**: intero percorso da browser, ZIP e GitHub, laboratorio, modalità URL/sandbox/ibrida, doctor, autenticazione del target, audit con controllo di durata/costo, risultati persistenti, report/esportazione, errori e cancellazione. Non serve implementare tutto prima di provarlo: la roadmap resta incrementale.

**P1 — prodotto successivo alla demo**: collaborazione, confronto tra revisioni, retest mirato, test di regressione derivati dai finding, più audit contemporanei, maggiore copertura di setup e autenticazione.

**P2 — espansioni**: integrazione nel ciclo di sviluppo, API pubblica, webhook, pianificazione, distribuzione del laboratorio e capacità commerciali. Non sono condizioni per dichiarare completa la demo.

P0 non richiede supporto automatico di ogni stack. Richiede capacità generali, un percorso manuale assistito per setup non riconosciuti e un elenco onesto delle configurazioni effettivamente validate. La demo può usare inizialmente BookStack e YesWiki; questi campioni, entrambi PHP, non dimostrano da soli generalità multi-stack.

## 3. Percorso principale dell'utente

1. Accedo e creo un progetto.
2. Carico uno ZIP oppure collego GitHub e scelgo repository e revisione.
3. Vedo quale codice è stato acquisito e scelgo come raggiungere l'applicazione da testare.
4. Il doctor prepara/verifica il laboratorio e mi chiede solo i dati che mancano.
5. Configuro eventuali utenti del target e scelgo un profilo di audit con limiti visibili.
6. Avvio l'audit e posso chiudere la pagina senza interromperlo.
7. Vedo avanzamento, risultati progressivi e ostacoli; posso interrompere l'esecuzione.
8. Consulto finding e prove, leggo le limitazioni ed esporto il report.
9. Posso avviare un nuovo audit, importare una nuova revisione oppure eliminare i dati del progetto.

La demo deve percorrere questi passaggi con dati reali e persistenza reale. Eventuali fixture o risultati registrati utilizzati durante una presentazione devono essere riconoscibili; non devono apparire come un audit appena eseguito.

## 4. Requisiti funzionali P0

### A. Accesso, progetti e acquisizione del codice

| ID | Feature/requisito | Criterio di accettazione |
|---|---|---|
| RF01 | Account, login/logout, recupero accesso e impostazioni essenziali. | Un utente accede alle proprie risorse; un secondo account non può consultare o modificare progetti, credenziali, run e report del primo. |
| RF02 | Creazione/lista/dettaglio/eliminazione progetto, con revisioni e audit associati. | Dal progetto si risale al codice acquisito, alle preparazioni e a ogni audit; stati vuoti ed errori hanno un'azione utile. |
| RF03 | Upload ZIP con acquisizione asincrona e feedback. | ZIP valido produce una revisione pronta; archivio invalido, troppo grande o con percorsi non consentiti viene rifiutato con motivo comprensibile e pulizia del temporaneo. |
| RF04 | Connessione GitHub, selezione repository autorizzato e import di branch/tag/commit. | Un repository privato autorizzato è importabile; viene registrato il commit realmente acquisito. Revoca, accesso negato e import fallito non lasciano un progetto falsamente pronto. |
| RF05 | Identità stabile della revisione analizzata. | Il report identifica origine, commit quando disponibile e hash del contenuto. Un nuovo import genera una revisione distinta; un audit in corso non cambia codice se il branch avanza. |

Il progetto è una risorsa duratura; ZIP e copie eseguibili del codice sono temporanei. La revisione conserva almeno identità e metadati; la disponibilità del sorgente completo segue una retention esplicita. Se il codice è scaduto, la UI richiede un nuovo import prima del nuovo audit, mantenendo consultabili i report conservati.

### B. Target, laboratorio e preparazione

| ID | Feature/requisito | Criterio di accettazione |
|---|---|---|
| RF06 | Scelta URL diretto, sandbox o combinazione delle due. | L'utente comprende su quale ambiente verranno eseguiti i test e quali accessi sono disponibili. Ogni prova identifica l'ambiente che l'ha prodotta. |
| RF07 | Laboratorio isolato associato all'audit anche in modalità URL. | L'agente può leggere la revisione e utilizzare strumenti locali nel laboratorio; questo accesso non viene presentato come accesso al server remoto. |
| RF08 | Applicazione eseguibile nella sandbox, con servizi e dati di test coerenti. | Con il setup supportato l'app parte, dipendenze/database sono pronti, le fixture necessarie sono presenti e una verifica applicativa dimostra che il percorso funziona. Una porta aperta da sola non basta. |
| RF09 | Environment Doctor: diagnosi, proposta di setup, verifica e richiesta di informazioni mancanti. | Il doctor spiega il blocco, raccoglie una risposta dall'utente, applica il setup autorizzato nel laboratorio e verifica l'esito. Un problema non risolto non viene marcato come successo. |
| RF10 | Configurazione manuale assistita quando il doctor non conosce il setup. | L'utente può fornire configurazione di avvio, variabili e servizi necessari, vedere gli errori e riprovare la preparazione senza reimportare inutilmente il progetto. |
| RF11 | Associazione esplicita fra revisione, preparazione e target. | Prima dell'audit sono visibili codice analizzato e target testato. Per un URL remoto senza verifica della versione, la corrispondenza è dichiarata non verificata. Modifiche al setup invalidano la readiness precedente. |
| RF12 | Gestione di credenziali e attori di test. | Audit anonimo e autenticato; almeno due sessioni indipendenti per test fra utenti/ruoli. Login fallito o permessi mancanti sono ostacoli espliciti, non assenza di vulnerabilità. |

Le modalità hanno una semantica precisa:

| Modalità | Sorgente e strumenti locali | Destinazione dei test applicativi | Limite da mostrare |
|---|---|---|---|
| URL | Revisione importata e laboratorio disponibili. | URL di test autorizzato. | Nessun accesso shell/DB al server remoto salvo connessione fornita esplicitamente; una copia locale non prova la configurazione remota. |
| Sandbox | Revisione importata, toolbox e applicazione preparata. | Applicazione nel laboratorio. | Risultati riferiti a questa configurazione e ai suoi dati/ruoli. |
| Ibrida | Revisione e applicazione locale, più URL di test. | Ambiente indicato per ciascuna prova. | Una conferma locale non viene attribuita automaticamente all'URL remoto. |

Il laboratorio può essere solo un ambiente di strumenti quando l'applicazione non è avviabile localmente. In quel caso i test applicativi avvengono sull'URL e le limitazioni locali restano visibili. Docker, VM e altri meccanismi sono scelte infrastrutturali: il requisito è l'isolamento e la disponibilità delle capacità necessarie.

L'autenticazione P0 copre i flussi validati nella demo: credenziali con form/browser oppure token/header, secondo il progetto. MFA, SSO e CAPTCHA arbitrari non sono promesse universali: richiedono sessione fornita dall'utente, intervento assistito o una limitazione dichiarata.

### C. Avvio, controllo e osservabilità dell'audit

| ID | Feature/requisito | Criterio di accettazione |
|---|---|---|
| RF13 | Avvio guidato con perimetro e limiti leggibili. | Prima dell'avvio l'utente vede revisione, target, attori, profilo, tempo massimo e budget. Gli endpoint esterni autorizzati per eventuali callback sono espliciti. |
| RF14 | Esecuzione asincrona con pagina di avanzamento. | Chiudere/riaprire la pagina non interrompe la run; fase, tempo trascorso, consumo e risultati già disponibili restano consultabili. La UI distingue preparazione e audit. |
| RF15 | Interruzione e gestione degli errori. | Lo stop arresta nuove attività, conserva risultati acquisiti e libera le risorse. Timeout, provider indisponibile e crash producono uno stato tecnico chiaro e un report parziale quando possibile. |
| RF16 | Conservazione di decisioni e milestone anche se la finalizzazione fallisce. | Una decisione già acquisita e il relativo contesto non spariscono perché fallisce la serializzazione o la proiezione a DB. La UI distingue decisione del modello, stato pubblicato e errore tecnico; una finalizzazione incompleta resta segnalata. |
| RF17 | Nuova esecuzione con input identificati. | L'utente può rilanciare su revisione/setup ancora disponibili e ottenere una nuova run distinta. Nessun requisito di ripresa esatta dell'intera conversazione dopo crash. |

I profili P0 devono essere pochi: una configurazione standard comprensibile e, se utile per la demo, una più rapida. Configurazioni avanzate dei modelli possono restare nell'area tecnica. L'utente non deve scegliere agenti, deduper o decine di tool per iniziare.

### D. Capacità dell'audit e qualità investigativa

| ID | Feature/requisito | Criterio di accettazione |
|---|---|---|
| RF18 | Ricognizione del progetto e superfici di rischio. | L'audit produce una mappa utile di entrypoint, operazioni sensibili, confini di fiducia, controlli e incognite, collegata alla revisione; è consultabile e alimenta l'esplorazione. |
| RF19 | Ipotesi statiche motivate e non duplicate. | Una lead descrive il flusso sospetto, il controllo atteso e i punti di codice pertinenti. Duplicati della stessa ipotesi non gonfiano i risultati; varianti distinte mantengono le differenze rilevanti. |
| RF20 | Verifica dinamica tramite HTTP e browser quando necessario. | La demo dimostra almeno un test autenticato fra attori e uno che richiede comportamento del browser. La conferma descrive l'effetto osservato e, quando necessario, il confronto di controllo. |
| RF21 | Audit trasversale allo stack e alle famiglie di vulnerabilità. | L'analisi segue codice e comportamento, senza imporre una lista di controlli specifici di Laravel. La demo documenta ciò che è supportato e conserva un percorso generico per sorgenti/setup diversi. |
| RF22 | Copertura e incertezza esplicite. | Aree esplorate, rimaste aperte, inaccessibili o interrotte sono distinguibili. Errori di tool, budget esaurito o login fallito non equivalgono a controllo di sicurezza superato. |

Capacità da dimostrare su casi adatti: autorizzazione/IDOR, isolamento fra utenti, autenticazione, gestione di input e operazioni sensibili, flussi che richiedono browser. Le categorie sono etichette utili nel report; non obbligano il prodotto a eseguire dieci audit separati.

Il modello interpreta il contesto e racconta il risultato; il sistema collega identità, revisione, attori e prove e conserva il lavoro. Sono richiesti ragionamento contestuale, memoria delle prove e affidabilità delle decisioni. Non è richiesto un tool specializzato per ogni vulnerabilità, né un particolare numero di ruoli.

### E. Finding, prove e report

| ID | Feature/requisito | Criterio di accettazione |
|---|---|---|
| RF23 | Finding leggibili con stato e priorità. | Ogni finding mostra titolo, severità motivata, stato, impatto, precondizioni, codice interessato e raccomandazione concreta. Confidence e limiti non sono nascosti nella severità. |
| RF24 | Prove consultabili e collegate. | Dal finding si aprono riferimenti al codice e prove HTTP/browser/target pertinenti, con ambiente, attore, tempo e risultato. Un link a una prova espulsa o non disponibile lo dichiara. |
| RF25 | Report parziale/finale persistente ed esportabile. | Il report contiene sintesi, revisione, ambiente, scope, risultati, copertura, limiti e stato tecnico. È consultabile dopo la fine della run ed esportabile almeno in Markdown e JSON con credenziali redatte. |
| RF26 | Storico degli audit e dettagli tecnici separati. | Il progetto mostra run e risultati; l'utente può consultare il dettaglio di un audit, mentre log, tool call, modelli e budget sono accessibili senza occupare il percorso principale. |
| RF27 | Eliminazione e ciclo di vita dei dati. | L'utente vede la disponibilità dei sorgenti e può eliminare progetto/artifact/credenziali; eventuali risorse attive sono fermate e rimosse. Report conservati non dipendono dal mantenimento della sandbox. |

Gli stati dei risultati devono evitare ambiguità:

- **Sospetto**: problema individuato sul codice, non confermato dinamicamente. Può avere una validazione statica forte, da mostrare come milestone.
- **Confermato**: effetto di sicurezza effettivamente osservato nell'ambiente indicato, con prova disponibile o dichiarazione esplicita della sua disponibilità.
- **Respinto**: ipotesi smentita con motivazione/prove; non è una vulnerabilità da aggiungere al totale.
- **Inconclusivo/bloccato**: mancano condizioni o prove per decidere; motivo e lavoro restante sono visibili.

Lo **stato tecnico della run** è separato da questi stati: una run fallita può avere risultati utili e una run completata può avere sospetti non confermati. Un errore di pubblicazione non deve essere confuso con un cambiamento del verdetto. Questo requisito del prodotto resta distinto dalla ground truth manuale utilizzata per valutare il rewrite.

## 5. Requisiti non funzionali P0

| ID | Requisito | Evidenza di accettazione |
|---|---|---|
| RN01 | Isolamento di utenti, run, sessioni e codice eseguito. | Codice del progetto e comandi generati girano nel laboratorio; non accedono a credenziali della piattaforma, progetti di altri utenti o controllo dell'infrastruttura. |
| RN02 | Perimetro di rete e test rispettato. | HTTP/browser e strumenti operano sui target autorizzati; redirect o comandi non estendono automaticamente il perimetro. Le operazioni che alterano dati sono consentite solo nell'ambiente di test dichiarato. |
| RN03 | Protezione di codice, credenziali e report. | Credenziali accessibili solo quando necessarie, protette in persistenza e redatte nei log/report. Documentata la porzione di sorgente inviata al provider LLM e la retention dei dati. |
| RN04 | Durabilità e consistenza dei risultati. | Aggiornamenti concorrenti o finalizzazione fallita non cancellano milestone già salvate; decisioni, prove e proiezioni restano riconciliabili. Un output incompleto non viene nascosto come successo. |
| RN05 | Prestazioni e costi misurabili e limitati. | Tempi e consumi hanno scope e denominatori espliciti; deadline, budget e limiti del laboratorio sono rispettati. La run distingue queue, preparazione, audit e finalizzazione. |
| RN06 | UX comprensibile, accessibile e operatività ripetibile. | Percorso utilizzabile da browser con tastiera, stati di caricamento/errore e azioni chiare; un altro sviluppatore avvia la demo seguendo istruzioni documentate, senza correzioni nascoste al DB. |

Misure minime: durata complessiva percepita dall'utente, durata della preparazione e dell'audit, tempi per fase/lead, tempo alla prima lead e al primo finding disponibile, request/tool/errori/retry, token/costo, EP, lead/minuto e lead/EP. Una fase concorrente ha sia clock di wall time sia durate locali: non si sommano durate di task paralleli per ottenere il tempo dell'audit.

Le soglie numeriche di latenza e costo non sono inventate in questo documento. Prima dell'accettazione operativa si fissano limiti per configurazione/hardware/campione, usando misure coerenti. Anche un audit senza finding deve avere una conclusione tecnica, consumo e limiti leggibili.

## 6. Quando la demo è completa

La demo è completa quando questi scenari sono percorribili realmente; implementare una pagina non è sufficiente.

| Scenario | Risultato osservabile |
|---|---|
| D01 — ZIP → sandbox → report | Account/progetto, import, doctor, app pronta, audit, finding e report persistente/esportato; ogni risultato collegato alla revisione. |
| D02 — GitHub → revisione | Import di repository privato autorizzato e commit identificato; accesso revocato gestito; stesso percorso successivo dell'import ZIP. |
| D03 — URL con laboratorio | Codice disponibile e target raggiungibile; verifica dinamica sull'URL; assenza di accesso al server esplicitata. |
| D04 — Ibrido | Stessa ipotesi investigata in locale e sull'URL; origine di ciascuna prova chiara; nessuna conferma locale attribuita implicitamente al remoto. |
| D05 — Doctor bloccato | Configurazione/dato mancante richiesto, risposta utente, nuova readiness; fallimento residuo spiegato con azione di recupero. |
| D06 — Due attori | Test fra utenti/ruoli con sessioni indipendenti, baseline e risultato sul confine di autorizzazione. |
| D07 — Browser | Test in cui l'effetto dipende dall'esecuzione nel browser, con osservazione collegata al finding. |
| D08 — Risultati distinti | Casi che producono confermato, sospetto, respinto e inconclusivo; un errore tecnico non viene presentato come esito sicuro. |
| D09 — Stop/crash/finalizzazione fallita | Risultati già acquisiti conservati, stato tecnico visibile, risorse rilasciate; consultazione dopo riapertura della pagina. |
| D10 — Privacy e cleanup | Secondo utente senza accesso, segreti redatti, sorgente scaduto/eliminato gestito e sandbox rimossa. |
| D11 — Parità Python/TS | Confronto con i riferimenti C0, risultati persi/cambiati/extra espliciti, misure separate di qualità e costo/tempo. |

Le verifiche ordinarie di import, lifecycle, persistenza e UI possono usare runtime simulati senza LLM. Le capacità investigative e la parità richiedono audit reali, eseguiti separatamente e su richiesta esplicita. Questo documento non avvia tali test.

## 7. Qualità del rewrite come requisito interno

**RQ01 — conservare semanticamente i risultati della baseline Python**, non il codice che li ha prodotti. Ridurre tool e moduli è accettabile se le capacità necessarie e la qualità restano disponibili. Stesso conteggio di finding con ipotesi diverse non dimostra parità.

La baseline operativa è composta da:

- [artifact C0 originali](../benchmarks/baselines/python-c0-20261007/README.md), con stato persistito, output e telemetria storici;
- [annotazione manuale della ground truth](../benchmarks/baselines/python-c0-20261007-ground-truth.json), che per BookStack stabilisce **4 conferme su 5 lead**, su istruzione dell'utente, anche se la persistenza finale riporta suspected.

Il log selezionato non permette al momento di ricostruire autonomamente quel 4/5 né la corrispondenza individuale: l'annotazione è un riferimento aggregato autorizzato, non un dato ricavato dalla persistenza. Il confronto per singola lead dovrà acquisire la mappatura o il log corretto. Questo non altera i dati storici o i loro denominatori di consumo.

Per l'accettazione mostrare separatamente: ipotesi ritrovate/perse, disposizioni e milestone, qualità delle prove, costo, durata, errori e limiti. Una lead nuova non compensa automaticamente una conferma di riferimento persa. Un cambiamento di modello/setup identifica un confronto operativo, non un esperimento che attribuisce l'effetto a un solo modulo rimosso.

La ground truth LLM/manuale misura la conservazione del comportamento in questa prima fase; la successiva verifica della correttezza reale dei finding è un criterio diverso.

## 8. P1 — prodotto successivo alla demo

| Feature | Valore per l'utente |
|---|---|
| Confronto fra revisioni e retest di un finding. | Capire se una modifica corregge il problema e quali risultati sono nuovi, risolti o persistenti. |
| Generazione di test di regressione di sicurezza. | Trasformare una prova ripetibile in un test utilizzabile dal progetto; iniziare dai casi che si prestano senza costruire un generatore universale. |
| Triage manuale, annotazioni e revisione dei verdict. | Conservare valutazioni umane separate dal risultato originale dell'agente, con autore e motivazione. |
| Workspace di team e condivisione controllata dei report. | Consentire collaborazione con permessi comprensibili. |
| Più audit contemporanei e maggiore copertura di setup. | Ridurre l'attesa e supportare nuovi progetti sulla base della domanda e dei benchmark. |
| Import Git generico e provider oltre GitHub. | Collegare repository self-hosted o altri provider senza cambiare il percorso progetto/revisione/audit. |
| Autenticazione assistita più ampia e gestione dei dati di test. | Coprire sessioni complesse, preparazione degli attori e reset di fixture fra esperimenti. |
| Report PDF e strumenti di remediation assistita. | Condividere risultati e facilitare le correzioni; eventuali patch sono revisionabili prima dell'applicazione. |
| Corpus multi-stack e valutazioni ripetute. | Verificare generalità, correttezza e variabilità oltre i due riferimenti iniziali. |

## 9. P2 — espansioni

- Audit su pull request, webhook e integrazioni CI; schedulazione e monitoraggio di regressioni.
- API pubblica e integrazioni con issue tracker.
- Pool di laboratori remoti e distribuzione dell'esecuzione.
- Quote commerciali, fatturazione, policy organizzative e ruoli enterprise, se il contesto del prodotto lo richiederà.
- Revisione assistita delle patch e automazioni più estese, con prove di beneficio rispetto al solo audit.

## 10. Confini della demo

La demo non promette copertura esaustiva, certificazione di sicurezza, setup automatico universale, ripresa esatta dopo crash, autofix autonomo o capacità enterprise. Un URL senza codice è un possibile futuro audit black box, fuori dal flusso gray box P0.

Completezza significa che il percorso previsto funziona, produce risultati utili e lascia leggibili gli ostacoli. Professionalità significa che revisioni, ambienti, prove e stato tecnico sono affidabili e comprensibili; non dipende dal numero di componenti implementati.
