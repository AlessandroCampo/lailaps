# Proposta P0: raggiungere il codice sensibile prima del confronto esteso fra modelli

Revisione successiva della stessa giornata: [tracing e task generati dal Reader](P0-reader-adaptive-exploration-20260925.md).
Quella sintesi aggiorna l'ordine proposto qui: Recon prepara una mappa iniziale,
mentre Reader amplia la ricerca e acquisisce nuove domande durante l'esplorazione.
La matrice dei miss e le evidenze di questo documento restano riferimenti diagnostici.

25 settembre 2026. Analisi offline e proposta, senza implementazione o nuove run.
Integra [il P0 Reader del 23 settembre](P0-reader-methodology-cross-model-20260923.md)
proponendo di anticipare gli interventi sulla copertura. Il confronto cross-model
resta necessario, ma viene dopo un primo esperimento mirato sulla reach.

## Evidenza e limiti della diagnosi

Nella run MiMo `20260923-193458`, degli undici miss semantici del catalogo positivo,
tre hanno il codice decisivo nel materiale letto, quattro riguardano comportamenti
semanticamente assegnati ma non seguiti fino al sink, quattro comportamenti fuori
dal focus operativo esplicito della Recon. Queste sono categorie della traiettoria
osservata, non otto dimostrazioni di incapacità del modello e nemmeno una stima del
guadagno garantito. Budget e indisponibilità del modello censurano l'esplorazione.

La distinzione Recon/Reader è sfumata: `tools/bazar/services/` e `handlers/` erano
assegnati genericamente, ma CRUD e validazione campi dominavano la domanda. La stessa
Golden Recon ha permesso ad altre run di trovare widget e SSRF ActivityPub. Quindi
la mappa non rende questi percorsi inaccessibili; ne rende poco affidabile la selezione.
Le due CVE ActivityPub condividono inoltre un sottosistema, pur avendo operazioni
decisive diverse: non sono due lacune di ricognizione indipendenti.

Fonti rilette:

- [Review Recon/Reader 20 settembre](REVIEW-recon-reader-yeswiki-20260920-194109.md).
- [Diagnosi globale 225733](REVIEW-reader-global-yeswiki-20260920-225733.md).
- [Confronto 225733 / 075801](REVIEW-reader-global-comparison-20260921-075801.md).
- [Confronto fra modelli e traiettorie](REVIEW-reader-models-partial-runs-20260923.md).
- [Classificazione completa MiMo](C:/Users/Alessandro/.codex/state/plugins/codex-security/scans/lailaps/artifacts-db5ec484b8e6fd853c7fa47bb4c07b80ae15a88f13e210f9e7378b34e8c2be74/artifacts/validation/mimo-20260923-193458-classificazione.md).
- Fixture effettive in `storage/framework/lailaps-reader-global/yeswiki-reader-global-20260923-193458/fixtures/`.

Il codice del workspace ha modifiche preesistenti. I riferimenti al runtime sotto
descrivono ciò che è disponibile oggi; i comportamenti storici derivano da fixture e
transcript, senza presumere identità fra tutte le versioni dei prompt.

## Cosa dovrebbe cambiare per gli otto miss

I nomi CVE e i locator seguenti appartengono esclusivamente alla valutazione offline.
Non devono essere inseriti nei prompt o nei dossier dei futuri audit.

| Caso | Passaggio perso | Miglioramento generalizzabile |
|---|---|---|
| 52767, verifica firma | Protocollo federato assente dai compiti espliciti; decisione di verifica non letta | Inventariare route anche nelle estensioni; assegnare il comportamento federato; seguire la verifica fino all'uso del suo risultato |
| 52769, fetch keyId | Stessa omissione iniziale; fetch dentro il servizio non raggiunto | Leggere gli effetti del verificatore, inclusi accessi esterni precedenti al controllo finale |
| 52774, widget | Directory handler assegnata a CRUD, widget non nominato | Distinguere ingressi di visualizzazione/embed dai percorsi di modifica e seguire il template realmente selezionato |
| 52763, recentchanges | Action non assegnata esplicitamente; altra action con parametro period investigata | Censire le actions e collegarle alle query chiamate; una conclusione su un caller non copre gli altri |
| 52766, erase comments | Action incontrata come caller del logging; operazione di cancellazione non letta | Risalire anche dai servizi di mutazione ai caller; leggere il ramo di trattamento della richiesta dell'action individuata |
| 52775, reactions | Controller considerato per ACL/CSRF; implementazione e ramo finale SQL non letti | Seguire il callee dell'operazione e confrontare i rami alternativi prima di considerare esplorata la mutazione |
| 52770, numeric filters | SearchManager incontrato per ACL; costruzione condizioni non esplorata | Rappresentare ricerca e filtri come comportamento; separare query building da filtro dei risultati |
| 52772, input templates | Lettura di presentation/templates e fields, senza i template input effettivi | Seguire i riferimenti render/loader e distinguere input, visualizzazione e metadati del form |

Esempi verificati nel sorgente: `tools/bazar/controllers/ApiController.php:127`
espone l'inbox e chiama `HttpSignatureService::verifySignature`; il servizio contiene
sia il fetch a :96 sia la verifica a :130. `ReactionManager::deleteUserReaction`
inizia a :326 e ha un ramo distinto a :356. `TextField.php:58` costruisce il nome
del template input e `TextareaField.php:107` lo passa al renderer. Questi collegamenti
sono recuperabili dagli strumenti già presenti senza conoscere le CVE.

Nei log MiMo, area 4 `193537-4-logs.php:208` discute le reazioni soprattutto come
CSRF/controllo dell'utente; area 7 `200458-logs.php:1286` sceglie fra approfondire il
caller EraseSpamedComments e altre piste. I file sono nelle rispettive directory
`storage/app/runs/yeswiki/reader-area/yeswiki-reader-area-20260923-<suffisso>/`.

## P0 proposto

### 1. Recon: passare dalla mappa delle cartelle alla mappa dei comportamenti osservati

Dopo il primo orientamento architetturale, ispezionare registrazioni di route,
handler/actions, hook e altri ingressi del progetto, comprese le estensioni.
Usare struttura, nomi e letture brevi per distinguere responsabilità indipendenti.
La Recon deve sapere che esiste un comportamento; la verifica della sua sicurezza
rimane al Reader. Non servono lettura integrale del repository o ricostruzione
completa di ogni flusso.

Prima di finalizzare, riconciliare i comportamenti così osservati con gli incarichi.
Una directory ampia può ospitare più responsabilità: cercare esplicitamente quelle
non rappresentate dal titolo e dalla domanda assegnata. Accorpare quelle che
condividono realmente ingressi e contesto; separare quelle con attori, ingressi o
effetti indipendenti. Questo è un giudizio del modello, non una quota di assignment.

Riutilizzare `ReconAssignment(title, paths, next_check)`: in `next_check` indicare i
percorsi indipendenti osservati e da quale partire. I locator comprendono ingressi
e dipendenze effettivamente localizzati; quando un collegamento non è risolto,
conservare il quesito di localizzazione. Evitare titoli che vincolano la ricerca a
una sola debolezza, come il vecchio “Rendering e template (XSS surface)”.

Il prompt corrente già invita alla copertura funzionale. Il cambiamento verificabile
è richiedere che i compiti derivino da ingressi e comportamenti osservati, anziché
considerare sufficiente la lista dei componenti di alto livello.

### 2. Recon: rendere visibile l'ampiezza dei segnali e usarli come seconda prospettiva

Nel transcript della Recon storica `20260920-194109`, a :1253 viene chiamato una sola
volta `list_surface_signals(scope='all', page_size=50)`. La risposta contiene 50 di
513 segnali e `has_more=true`: 5 code-injection, 13 command-injection, 1 cryptographic
e 31 database-injection. L'inventario complessivo registra 21 famiglie. Questo
dimostra una consultazione limitata; non dimostra che gli otto sink mancati fossero
tutti segnalati dal sensore. I template hanno inoltre errori di parsing dichiarati.

Nel percorso assignments, `_role_system_prompt` proietta stato del sensore e istruzioni
di retrieval, senza il riepilogo delle famiglie che il percorso Reader ordinario già
usa. Proposta locale: rendere disponibile anche qui il riepilogo compatto già
calcolato — famiglia, numero di file e segnali — e conservare il retrieval mirato
per famiglia/path. I conteggi descrivono disponibilità di evidenza, non priorità di
sicurezza; i `top_files` e gli esempi restano campioni limitati.

Dopo la mappa funzionale, il modello usa questa seconda vista per cercare componenti
o operazioni non rappresentati. Quando serve, integra con ricerca sorgente su
primitive e wrapper osservati nel progetto, anche dove Semgrep non ha risultati.
Niente obbligo di consumare tutte le pagine o di produrre un incarico per match.

Riferimenti: `triple_agent.py:2152`, `surface_context.py:246` e `:676`.

### 3. Reader: ricognizione dei rami all'inizio e lettura del blocco operativo

All'inizio dell'incarico, costruire una breve mappa locale dei percorsi indipendenti
usando ingressi, metodi, dispatch e riferimenti ai template. Conservarla nella memoria
narrativa già esistente, indicando quali percorsi sono stati seguiti e quali restano
aperti. Se compare subito una lead pronta, emetterla senza aspettare di finire la
mappa; riprendere poi i percorsi residui.

Per il percorso selezionato, seguire input e controllo fino all'operazione concreta
o fino a un confine ancora da risolvere. Quando una ricerca restituisce un frammento,
leggere il blocco pertinente con i rami alternativi e le condizioni che ne decidono
l'esecuzione. Se l'operazione delega a un servizio o a un template, seguirne il
riferimento; il nome del controller o del metodo non descrive tutti gli effetti.

Non è una richiesta di completare sempre un flusso end-to-end prima dell'emissione:
operazione, possibile influenza e nesso plausibile restano sufficienti. Quando questi
elementi mancano, conservare il percorso come residuo invece di trasformarlo in una
lead generica per sola presenza di una funzione sensibile.

Le conclusioni devono restare locali al percorso e alla proprietà letti: avere
esaminato l'ACL di una mutazione lascia aperta la costruzione della query; avere
letto il template di visualizzazione lascia aperto quello del form. Quando emerge
un effetto indipendente nello stesso blocco, seguirlo o registrarlo come residuo.

Usare `read_file`, `search_source`, grafo e `run_code` già disponibili. `read_file`
espone righe effettive, truncation e suggerimento di continuazione: non serve un nuovo
lettore generalista per provare questa strategia. `list_surface_signals(active_area)`
filtra i soli locator, senza includere i callee: per una dipendenza individuata usare
ricerca diretta oppure `scope='all'` con il suo path. Nessun ampliamento automatico
indiscriminato del contesto.

Riferimenti: `triple_agent.py:1450`, `:3366`; `runtime_tools.py:2504`;
`surface_context.py:676`.

### 4. Checkpoint: mantenere l'emissione corretta e i percorsi ancora da esplorare

Resta prerequisito il P0 già previsto: una pista narrativa non è acquisita; il ledger
è autorevole e l'emissione deve essere una prossima azione valida. Nel checkpoint
conservare anche le dipendenze non seguite e i comportamenti indipendenti rimasti
aperti, senza nuovi DTO model-facing o contatori automatici di completezza.

Il codice attuale contiene già `_apply_closure_recall_sweep` (`triple_agent.py:7046`),
che concede una tranche dopo la prima richiesta di chiusura con il budget residuo.
Non propongo un secondo sweep. Una protezione alla chiusura non garantisce che una
run esaurita a budget abbia prima esplorato i rami: la ricognizione deve arrivare
presto. Il dossier dello sweep contiene anche unknowns di lead acquisite; il modello
deve distinguerli dai percorsi non esplorati, perché le verifiche già affidate al
Confirmer non dovrebbero assorbire la restante discovery.

## Ruolo del Confirmer

Nelle run Reader-only analizzate Confirmer, Worker e Judge non vengono eseguiti.
Perciò nessuno degli otto miss è prova di un problema del Confirmer e nessun suo
miglioramento può recuperare direttamente un comportamento che non gli viene passato.

Per le lead ricevute, il contratto attuale gli permette già di leggere in tutta la
codebase, seguire source/propagation/sink, servizi, route e mitigazioni; dispone anche
di `navigate_source`. Il contributo P0 è rendere esplicita nell'handoff l'operazione
osservata e il collegamento irrisolto, usando evidenza e unknowns già previsti. Così il
Reader può riprendere la copertura mentre il Confirmer approfondisce quella pista.
Permessi e dati mancanti nel fixture sono condizioni da descrivere; non dimostrano
da soli che una catena statica sia falsa.

Il prompt corrente vieta al Confirmer di aprire lead indipendenti
(`triple_agent.py:1859`). Se durante la validazione incontra una debolezza diversa,
conservarne e instradarne il residuo verso discovery sarebbe un'estensione P1 da
valutare con casi osservati. Non promettere questa capacità come già presente e
non trasformare ogni lead in un secondo audit del componente.

## Esperimento che precede la matrice cross-model

Tenere fisso il modello Reader, reasoning, strumenti, sorgente, fixture, provider per
quanto controllabile, concorrenza e limiti di contesto. Correzione checkpoint/ledger
comune a tutte le configurazioni. Prima screening API/Bazar, poi actions, rendering
e mutazioni di contenuti per verificare che il metodo generalizzi.

1. C0: Golden Recon attuale e metodo Reader attuale, con checkpoint corretto.
2. C1: stessa Recon, Reader con ricognizione iniziale e prosecuzione dei blocchi operativi.
3. C2: Recon rivista e Reader di C1, senza altri cambiamenti.

C0/C1 isola la strategia di esplorazione Reader; C1/C2 esplora l'effetto della nuova
Recon. È uno screening incrementale, non una stima completa delle interazioni.
Se la nuova Recon cambia gli assignment, confrontare lo stesso perimetro funzionale
e la somma dei budget, includendo costo Recon ed enrichment. Aumentare il numero di
aree mantenendo lo stesso cap per area aumenterebbe la spesa e confonderebbe il
risultato. Usare il runner esistente; non serve un nuovo scheduler per questa prova.

Ripetere le configurazioni promettenti prima di attribuire stabilmente il guadagno;
poi applicare il confronto fra modelli del piano precedente alla strategia migliore.
Budget e reasoning rimangono variabili successive da isolare. Le run storiche sono
diagnosi, non repliche controllate di C0.

Metriche offline, senza trasformarle in gate runtime:

- Comportamento esplicitamente assegnato: ingressi e operazioni riconoscibili nel compito.
- Operazione realmente consegnata nel trace del ruolo: distinguere semplice risultato
  di ricerca da blocco decisivo con condizioni, rami e uso del risultato. Controllare
  truncation e letture via workspace command, non solo `source_observations` o anchor.
- Valutazione osservabile: il modello discute l'operazione oppure il testo è solo presente?
- Inoltro: esiste una lead acquisita sulla stessa ipotesi, con match semantico?
- Costo per nuovi percorsi sensibili raggiunti, costo totale e costo downstream;
  lead utili deduplicate e regressioni sulle piste già sostenute.

Il catalogo CVE è una misura chiusa, separata dai prompt. Per controllare l'utilità
fuori catalogo, valutare anche un campione prefissato di operazioni sensibili reali
senza vulnerabilità nota. Non selezionarlo solo fra i nuovi successi. Un audit utile
deve raggiungere e interpretare anche codice sicuro, senza emettere una lead per
ogni operazione. Registrare separatamente budget, failure e timeout.

Il successo cercato è aumentare la consegna di contesto decisivo e l'inoltro di piste
utili a costo comparabile. Più file, righe o match comparsi in un dump non bastano;
neppure più assignment dichiarati complete. La crescita di reach deve poi tradursi
in valutazioni e lead, altrimenti il collo di bottiglia si è spostato all'interpretazione.

## P1 successivi

- Confronto cross-model, reasoning e budget dopo il primo miglioramento di reach.
- Instradamento dei residui indipendenti osservati dal Confirmer, se ne emerge valore.
- Indice compatto degli altri assignment nel fan-out, se si osservano omissioni o
  duplicazioni dovute al contesto isolato: il prompt parla di un indice, ma le fixture
  attuali contengono solo l'area attiva e il briefing. Non introdurre memoria condivisa
  globale per correggere questa sola discrepanza.
- Navigazione aggiuntiva al Reader o nuovi estrattori solo se gli strumenti esistenti
  mostrano un limite ripetuto e concreto. Reviewer indipendente delle omissioni solo
  dopo aver misurato il residuo.

Questa proposta cambia l'ordine raccomandato degli interventi; non autorizza né avvia
test a pagamento. Nessun codice del runtime è stato modificato in questa analisi.
