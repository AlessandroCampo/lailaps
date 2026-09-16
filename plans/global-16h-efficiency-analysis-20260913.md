# Run globale GLM di 16 ore: rendimento e proposta minima

Analisi del 13 settembre 2026. Run `yeswiki-global-20260912-190529`, artifact sotto `storage/app/runs/yeswiki/global/yeswiki-global-20260912-190529/`. Analisi offline; nessuna modifica al runtime e nessuna nuova chiamata di audit. Integra il piano del 12 settembre con il risultato finale, senza assumere che il working tree corrente coincida con il codice caricato dal processo storico.

## Esito e misure utilizzabili

Creazione 12 settembre 19:05:29, postflight 13 settembre 11:07:25: **16h 01m 56s**, incluso provisioning/finalizzazione. Target valido anche al postflight. Tutti i ruoli usavano GLM 5.3 Flash; Confirmer high e Worker max. Termine `category_sequence_complete`, ma nessuna categoria termina per discovery semanticamente completata: otto terminano per budget economico e due per residuo Reader insufficiente.

| Misura | Valore |
|---|---:|
| Categorie visitate | 10 |
| Aree pianificate / chiuse / ancora queued | 95 / 6 / 79 |
| Richieste LLM | 1.536 |
| Token input cumulativi / output | 39.437.581 / 1.076.934 |
| Reader / Confirmer / Worker richieste | 560 / 597 / 124 |
| Recon / Reviewer / Judge / handoff richieste | 65 / 103 / 50 / 37 |
| Lead registrate / confermate / sospette / chiuse | 50 / 2 / 45 / 3 |
| Episodi Worker avviati | 24 |
| Episodi Worker senza HTTP, secondo lead_usage | 9 |
| A01 richieste / lead / aree chiuse | 1.191 / 40 / 3 su 10 |
| Quota richieste A01 | 77,5% |
| Quota punti consumati A01 | 78,3% |

Categorie e aree sono unità del piano Recon, non un denominatore assoluto di vulnerabilità. Un'area non chiusa può aver ricevuto lavoro utile. Le due conferme sono esposizione anonima delle reazioni e disclosure di dettagli d'errore; sono conferme del report, non exploit rieseguiti da questa analisi.

La durata/richieste è circa **37,6 secondi per richiesta contabilizzata**, includendo tutto il tempo della run. Non è la latenza provider misurata. Mancano timestamp completi per richiesta/ruolo per distinguere prefill, reasoning, attese, retry e tool.

### Limiti della telemetria

Non usare ciecamente gli aggregati: `confirmer_first_pass_validation_rate=6` e `exploration_novel_result_rate≈5,98` non sono probabilità; diversi rapporti sono stati sommati. `lead_usage` include 392 record senza disposition oltre alle 50 lead con disposition, quindi la lunghezza della mappa non è il numero di lead. Il ledger globale contiene 36 transazioni mentre il conteggio per lead ne totalizza 52: occorre riconciliare scope e ID prima di dare un totale HTTP definitivo. Il costo provider globale (~0,568 USD) non coincide con la somma per ruolo (~2,838 USD, comunque incompleta per alcuni ruoli). Le metriche selezionate sopra derivano da contatori additivi, report categoria, ledger finding e transcript, con le limitazioni dichiarate.

## Diagnosi: non soltanto provider lento

Il transcript contiene 38 messaggi `model-retry`: 18 Reviewer, 13 Reader, 3 Worker, 3 Confirmer, 1 handoff; quattro `model-exhausted`. Compaiono errori sui vecchi cap 2.000/5.500 token e cinque occorrenze di `Network connection lost`, ma nessun `ReadTimeout`. Le occorrenze testuali di un errore possono includere ripetizioni dello stesso incidente. Non è dimostrato che gli errori provider spieghino la maggioranza delle 16 ore.

La run attraversa un enorme numero di richieste seriali. Anche eliminando ogni retry resta il costo di oltre mille richieste. Solo rendere il provider due volte più veloce non risolve un processo che avvia 50 pipeline e ne lascia 45 sospette, con gran parte della mappa ancora in coda.

### 1. Ripetizione del controllo, non approfondimento deliberato

Nel transcript finale: 8 chiusure respinte, 21 pivot cross-area ricondotti alla continuazione e 8 fallback di continuazione. Sono gli stessi difetti discussi nel piano precedente, ancora presenti nella run storica. Le decisioni semantiche devono essere applicate, preservando i residui, non sostituite con altre tranche.

Esempio: `a01-lead-35` registra circa 106 minuti di episodio attribuito, 54 richieste complessive. Di queste, 30 Reader e 15 Reviewer, soltanto 9 Confirmer. La durata include discovery/review imputate alla lead: non sono 106 minuti del Confirmer. Questo dato impedisce di diagnosticare semplicemente un Confirmer troppo lento.

### 2. Verifiche avviate senza un percorso finanziato alla conclusione

Lo shared broker storico espone 15.000 punti Confirmer, 10.000 Worker e 7.500 per retry Worker. La singola prima richiesta Worker della lead 3 usa 26.783 input token e 2.343 output, lasciando appena 4.175 punti. Il numero nominale di richieste ammesse non corrisponde ai turni realmente finanziabili con quel contesto.

Ventidue messaggi indicano `Self-checkpoint non finanziabile`. Questo non smentisce il valore del checkpoint Confirmer: mostra che la sua disponibilità non era garantita dal funding effettivo di quella run.

Le lead 1, 2, 3 e altre arrivano al Judge senza HTTP. Nella lead 1, il Worker rilegge source reference, anche una ripetuta e già inclusa nell'assignment, poi esaurisce lo stadio. Nella lead 3 cerca utenti e credenziali e termina prima del login. Il Judge ragiona esplicitamente sul residuo Worker zero come possibile impossibilità del retry, sebbene il sistema preveda funding separato per nuove tranche. Va distinto il residuo corrente dalla possibilità effettiva di estensione autorizzata.

**Implicazione:** aumentare indiscriminatamente tutti gli envelope può prolungare ulteriormente la run. Meglio riusare il contesto acquisito e verificare che ammissione/riserva coprano un episodio utile, checkpoint e giudizio inclusi. Il guadagno atteso è meno lavoro preliminare senza esito, non meno accuratezza nella verifica.

### 3. Molte lead diverse ripagano conoscenza comune

Numerose lead A01 condividono autenticazione, dispatch API, cookie, omissione CSRF e setup attore. Le route restano distinte e possono richiedere prove differenti; non devono essere soppresse automaticamente per famiglia.

Tuttavia non è necessario ricostruire da zero in ogni lead login, utenti, sintassi URL e comportamento dei controlli comuni. Il codice corrente già espone runtime dossier e sessioni actor: prima verificare perché non bastavano nella run ed estendere quei dati esistenti, senza nuovo agente o nuova infrastruttura di memoria.

Il riuso deve essere semantico e condizionato: un fatto sul middleware comune conserva scope, riferimenti e condizioni; una differenza di route, attore o comportamento invalida l'assunzione quando pertinente. Ogni conferma dinamica conserva le prove richieste sul proprio effetto. Un successo HTTP con client artificiale non sostituisce un oracle browser quando il confine di sicurezza dipende dal comportamento del browser.

### 4. La categoria iniziale domina, ma non completa

A01 usa circa quattro quinti delle richieste e dei punti, poi termina col budget. Le altre nove hanno soltanto 27–67 richieste ciascuna. La durata elevata quindi non compra neppure profondità omogenea su tutte le categorie.

Riserve/fair-share migliorano la distribuzione, non riducono automaticamente il lavoro totale. Top 3 in parallelo sovrappone episodi, ma non divide per tre il tempo della categoria dominante. La vera accelerazione richiede eliminare ripetizioni e aumentare le conclusioni per episodio, prima di aspettarsi vantaggi dalla concorrenza.

## Cosa è già cambiato nel workspace

Il codice letto il 13 settembre contiene cap Reviewer 5.000 e default 6.500, modello Reader/Recon differente da questa run, modifiche alla persistenza, fair-share globale e funding separato Judge. Il processo storico dimostra ancora cap 2.000/5.500 e budget precedenti. Non usare questa run come prova che tutte le correzioni odierne abbiano fallito; registrare revisione/configurazione immutabili per il prossimo confronto e verificare quali fix sono effettivamente nell'immagine avviata.

## P0 proposto: un esperimento piccolo sull'efficienza produttiva

Il task corrente è analisi/proposta; le seguenti modifiche non sono state implementate in questa analisi.

1. **Verificare l'effetto delle correzioni già fatte.** Replay offline dei casi di close/pivot respinti e controllo del funding checkpoint/Judge/Worker nel preset reale. Non aggiungere altre policy prima di sapere se quelle già presenti funzionano sul caso concreto. Mantenere la decisione semantica di proseguire al Reviewer e il checkpoint Confirmer attuale.
2. **Rendere riusabile il setup già verificato.** Nel dossier esistente includere route API/login effettivamente funzionanti, actor disponibili e stato sessione, oggetti di test riusabili quando appropriati e precondizioni verificate. Le credenziali restano nei canali privati già previsti. Il Worker deve capire cosa è pronto e quale test discriminante può eseguire subito, con libertà di controllare precondizioni incoerenti/scadute. Non rimuovere tool sorgente né imporre HTTP ciechi.
3. **Chiarire continuità e funding al Judge.** Mostrare se una nuova tranche è ammissibile, distinto dal saldo zero di quella conclusa. La decisione di retry resta semantica. Evitare che un limite contabile si trasformi in astensione per errore di interpretazione. Verificare riserva per checkpoint obbligatorio/terminale senza aumentare globalmente le allowance.
4. **Misurare il punto corretto.** Aggiungere ai dati già persistiti durata delle richieste, provider effettivo e fasi per ruolo; per il confronto riconciliare lead e transazioni e ricalcolare i rapporti dai contatori. Usare precisione degli esiti, numero di episodi conclusi e tempo al test discriminante oltre ai punti di produttività.

Questi interventi si applicano anche in produzione: cercano di togliere riletture, setup ripetuto e interruzioni contabili, mantenendo le verifiche.

## P1: ulteriore efficienza, dopo il confronto P0

- **Riuso semantico dei fatti comuni fra lead.** Breve memoria narrativa, ricavata dai checkpoint esistenti e con provenance: cosa è già verificato, condizioni, differenza specifica da verificare nella lead nuova. Reader/Confirmer decidono se il fatto è trasferibile. Non accorpare automaticamente vulnerabilità per endpoint o keyword.
- **Contesto mirato e tool call raggruppate.** Non reinviare dossier globali irrilevanti a ogni lead; conservare le reference necessarie e rendere disponibili gli approfondimenti. Dove le letture sono indipendenti, sfruttare tool call multiple nello stesso turno. Nessuna compressione più aggressiva indiscriminata, perché perdere fatti può aumentare le ripetizioni.
- **Modello/routing per ruolo, misurato.** Confrontare lo stesso modello su routing prestazionale e, se disponibile, endpoint diretto; confrontare modelli diversi sul numero di turni necessari a ottenere un verdetto corretto. Il Worker max può essere una variante da confrontare con high, non un downgrade da applicare alla cieca. Tenere invariato il Confirmer come baseline.
- **Top 2–3 categorie concorrenti con envelope attuali**, come concordato, coordinando le HTTP stateful. Misurare il percorso critico e il carico provider: nessuna promessa di speedup lineare.

OpenRouter permette sorting `throughput`/`latency`; il default privilegia prezzo, e le soglie preferite non garantiscono un tempo massimo. [Documentazione provider routing](https://openrouter.ai/docs/guides/routing/provider-selection). Questo è un controllo utile sulla latenza, non la spiegazione dimostrata delle 16 ore.

## Testing veloce, esplicitamente separato

- Per regressioni di orchestrazione: transcript/snapshot congelati e risposte simulate; nessuna inferenza e nessuna run lunga.
- Per un cambiamento del Worker: 4–6 candidate congelati nei benchmark già esistenti, includendo accesso anonimo semplice, autenticazione/setup, browser-dependent, caso negativo e verifica articolata. Mantenere gli envelope di produzione per confrontare efficienza reale.
- Per discovery/uscita: un'area API e una seconda area con sorgenti sovrapposti, includendo un caso ricco che deve estendere e uno esaurito che deve chiudere.
- Solo dopo: top 3 categorie end-to-end e infine global completa come controllo periodico. Se si impongono cap ridotti per smoke test, etichettarli e non usarne i risultati per affermare qualità/recall di produzione.

Usare oracle nei benchmark condizionali; scoring produttività global separato, come già pianificato. Non premiare semplicemente più lead o più HTTP: la run attuale mostra che quantità e conclusioni possono divergere.

## Tempi realistici e criterio di promozione

Non si può promettere stesso recall e durata arbitrariamente breve su qualunque repository. Si può però verificare se si ottiene la stessa qualità su workload rappresentativi con meno richieste e meno setup.

Con il rapporto storico di 37,6 secondi per richiesta, 1.536 richieste non entrano in due ore seriali: richiederebbero una media complessiva di circa 4,7 secondi, oppure meno turni utili e concorrenza. Questi sono conti di capacità, non cap suggeriti al modello né previsioni prestazionali.

Prima soglia sperimentale: eliminare dai casi riprodotti chiusure respinte spurie, checkpoint non finanziabili e Worker che ripetono setup già pronto senza arrivare al test. Poi confrontare durata e richieste necessarie per lo stesso esito, includendo i casi negativi. La qualità deve essere almeno confrontabile su più ripetizioni, non dedotta da una sola conferma veloce. Solo allora fissare un SLO per classi di dimensione/complessità; un obiettivo di 1–2 ore può essere sperimentale per questa classe di target, non una garanzia attuale.

L'esecuzione asincrona con risultati progressivi e ripresa evita di tenere aperta una richiesta client per ore, ma non riduce il tempo in cui serve il target. I checkpoint devono conservare le piste incomplete; la conferma può essere ripresa su una sandbox riproducibile, con verifica dello stato. Questo è P2 di prodotto quando necessario, non una soluzione sostitutiva al problema di efficienza.

Ogni implementazione strutturale successiva aggiornerà ARCHITECTURE.md. Questa analisi modifica soltanto documentazione di piano.
