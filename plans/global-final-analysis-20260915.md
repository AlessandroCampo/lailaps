# YesWiki e Gitea — analisi delle global concluse

Analisi offline del 15 settembre 2026. Sostituisce le conclusioni provvisorie della
fotografia delle 00:20 in `global-coverage-review-20260915.md` per queste due run:

- `yeswiki-global-20260914-214544`
- `gitea-global-20260914-214551`

Fonti: outcome finali e transcript nelle rispettive directory
`storage/app/runs/<target>/global/<run>/`; manifest locali del benchmark consultati
soltanto per questa analisi. Nessun audit, test HTTP o agent loop rilanciato.
Nessuna modifica al runtime o al piano P0 precedente.

## Giudizio

**Gitea non fallisce soltanto per budget o errori tecnici.** La distribuzione dei
fondi impedisce una ricerca sufficiente in quasi tutte le categorie; nella categoria
più finanziata si osserva anche una ricerca che enumera middleware senza seguire
sempre il confine attore–risorsa–permesso. Il solo Worker avviato incontra una capacità
di osservazione mancante nell'ambiente. Non abbiamo un campione sufficiente per
giudicare la capacità generale del Worker su Gitea.

**YesWiki produce lavoro utile, ma perde opportunità sia prima sia dopo la lead.**
Ci sono conferme e confutazioni dinamiche motivate, cinque interruzioni fatali,
due continuazioni fermate dopo tre retry, un arresto deciso dal Judge in base al
residuo della tranche e un test sui cookie non esprimibile dagli strumenti usati.
Il caso ActivityPub aggiunge un limite investigativo distinto dalla perdita di history:
una funzione con un finding confermato viene trattata come se tutti i suoi controlli
di sicurezza fossero risolti.

Il P0 di continuità e budget resta giustificato. Prima di cambiare architettura o
modello, aggiungerei istruzioni investigative più precise, disponibilità degli oracle
dinamici e gestione delle interruzioni tecniche a livello della singola lead.

## 1. Risultati finali e significato delle misure

| Misura | YesWiki | Gitea |
|---|---:|---:|
| Publication state | final | final |
| Categorie con coverage completa | 0/10 | 0/10 |
| Categorie terminate per fatal error | 5 | 0 |
| Categorie terminate per budget | 5 | 10 |
| Token contabilizzati | 43.464.596 | 12.228.536 |
| Richieste modello contabilizzate | 1.160 | 407 |
| ReaderLead | 20 | 2 |
| Worker avviati | 12 | 1 |
| Conferme dinamiche nel report | 3 | 0 |
| Conferme dinamiche abbinate al catalogo | 1/12 | 0/6 |
| Score benchmark normalizzato | 18,67% | 6,67% |
| Discovery yield | 33 punti, 9 finding accreditati | 3 punti, 1 finding accreditato |
| EP consumati globalmente | 3.911.085 | 1.900.247 |
| EP globali inutilizzati | 538.915 | 2.549.753 |
| Aree closed / active / queued | 13 / 10 / 82 | 6 / 10 / 98 |

`final` e run conclusa non significano audit completo. Le aree sono unità generate
dalla Recon, non una percentuale di vulnerabilità coperte. Il 6,67% Gitea deriva da
due anchor raggiunti: nessuna CVE del catalogo è diventata una lead abbinata.

YesWiki: 6 finding accreditati staticamente + 3 dinamicamente; questi livelli sono
mutuamente esclusivi nel discovery yield. Otto dei nove accreditati sono fuori
catalogo, con 11 adjudication pendenti nell'aggregato. Gitea ha una adjudication
pendente. **Fuori catalogo non significa automaticamente vero positivo nuovo**:
il credito misura la disposizione della pipeline, non una revisione indipendente.

I tre confirmed YesWiki hanno prove di ampiezza diversa:

- `a04-lead-5`: GET anonimo rivela una chiave privata ActivityPub dopo che l'admin
  ha attivato la normale generazione della chiave tramite HTTP. Buon differenziale
  fra segreto assente e segreto effettivamente esposto.
- `a04-lead-6`: firma con keyId controllato, risposta differenziale e accesso
  server-side a destinazioni scelte; abbinato alla SSRF `CVE-2026-52769`.
- `a02-lead-2`: risposta HTTP conferma la policy CSP di framing permissiva. Il
  report non dimostra un attacco di clickjacking completo su una vittima autenticata;
  il test browser cross-origin era facoltativo nel piano della lead.

Per evitare misure fuorvianti: i contatori HTTP globali del benchmark riportano zero
anche dove il ledger contiene transazioni (86 YesWiki, 4 Gitea); sono usati ledger
ed eventi Worker per analizzare le verifiche. Durata aggregata benchmark zero e costi
provider incompleti non sono adatti a un confronto di efficienza finale. Non ricaviamo
USD effettivi dai soli aggregati disponibili.

Queste run documentano ancora il contratto precedente: discovery globale 1,75M,
Confirmer iniziale 50K, Worker 60K, Judge 15K EP. **Non validano gli importi del
piano successivo né il Reviewer con full history.** Le differenze dal vecchio codice
oggi presente nel working tree non identificano una revisione immutabile del runtime
effettivamente eseguito. Non è un confronto A/B delle modifiche.

## 2. Reader: dove si perde la coverage

### 2.1 Budget: categorie nominalmente visitate, pochissima ricerca effettiva

Discovery calcolata dai consumi category_recon + reader + reviewer + handoff.
Global Recon è lavoro comune separato nei totali sotto.

| Gitea, categoria | Discovery EP | Richieste Reader |
|---|---:|---:|
| A01 | 929.901 | 133 |
| A02 | 79.504 | 6 |
| A07 | 95.079 | 5 |
| A03 | 90.069 | 10 |
| A04 | 90.001 | 14 |
| A05 | 86.480 | 11 |
| A08 | 97.816 | 1 |
| A06 | 86.594 | 13 |
| A09 | 96.519 | 10 |
| A10 | 82.354 | 10 |

A08 è l'esempio più netto: circa 76.941 EP alla Recon, 6.516 al Reader e una sola
richiesta Reader. L'allocazione residua finanzia soprattutto l'ingresso nella categoria.
Gitea lascia il 57,3% del cap globale inutilizzato: aumentare soltanto il cap non basta.

YesWiki Injection/A05 riceve appena 76.295 EP discovery e sei richieste Reader,
senza lead, nonostante il catalogo locale contenga sei casi positivi fra SQLi e XSS.
La ricerca non ha avuto condizioni realistiche per sviluppare quelle superfici.

**Conseguenza:** mantenere discovery indipendente per categoria e verifica finanziata
per lead, come nel piano P0. I 3M regular restano una scelta sperimentale generosa;
nessuna categoria completa in queste run fornisce una misura empirica migliore del
costo necessario a finirla. Non dedurre nuovi massimi dai punti di arresto imposti.

### 2.2 Gitea A01: presenza di guardie scambiata per correttezza del permesso

Transcript Gitea, righe 984–994: il Reader elenca GET label senza guardia locale,
POST/PATCH/DELETE con ownership e conclude «Fine». Non legge l'handler
`routers/api/v1/org/label.go` e non verifica il diritto di un non membro a leggere
le label di un'organizzazione privata.

I due casi raggiunti hanno entrambi quattro intersezioni Reader degli anchor in
`routers/api/v1/api.go`, ma nessuna osservazione dei componenti decisivi:

- `CVE-2026-25038`: `routers/api/v1/org/label.go`;
- `CVE-2026-24451`: `services/repository/merge_upstream.go`.

Il secondo richiede distinguere l'accesso al fork dall'accesso al parent dopo un
cambio di visibilità. Un middleware sul repository della richiesta non risolve
necessariamente il permesso sull'altra risorsa usata dal servizio.

Gli altri quattro casi non sono raggiunti dal Reader negli anchor: Actions approval,
scope del feed, hostmatcher/webhook, trusted proxy. Alcuni hanno osservazioni Recon,
che non equivalgono a indagine del Reader.

**Diagnosi:** A01 ha ricevuto 133 richieste Reader e circa 930K EP discovery.
Lo scarso finanziamento delle categorie successive non spiega questi due mancati
approfondimenti. È un problema osservabile di ragionamento/priorità; non possiamo
attribuirlo esclusivamente al modello senza confronto controllato.

### 2.3 YesWiki: due modalità diverse di perdere una CVE già vicina

**EraseSpamedComments, CVE-2026-52766.** Il risultato finale conferma la diagnosi
precedente: quattro osservazioni Reader dell'anchor, nessuna lead. Alle righe
2491–2551 il Reader formula una pista concreta, poi il checkpoint riduce il contesto
da circa 38.583 a 4.828 token. Alle righe 2642–2652 l'area viene chiusa senza
risolvere il controllo centrale residuo. Nessuna fase successiva recupera la pista.
Reviewer con full history e Reader continuo sono interventi direttamente pertinenti.

**ActivityPub, CVE-2026-52767.** È distinto dalla SSRF confermata 52769: il manifest
richiede una modifica di stato con firma invalida, per il controllo del risultato
della verifica. La firma valida con chiave servita dall'attaccante non prova quel caso.

Dopo i due confirmed, alle righe 20663–20667 il Reader rilegge integralmente
`HttpSignatureService.php`. Alle righe 20688, 20716 e 20778 considera la selezione
dell'algoritmo già inclusa nella lead confermata; alle righe 20805–20811 il Reviewer
conclude che generazione, storage, firma e verifica sono coperti e chiude l'area.
Non emerge una lead per il controllo della firma invalida; il benchmark resta
`anchor_reached`. Questo è un caso di approfondimento mancato e chiusura troppo ampia,
non prova di una suppression automatica del novelty reviewer.

**Conseguenza:** full history è utile ma insufficiente. La regola deve essere:
stessa funzione o stesso endpoint non significano stessa ipotesi. Non occorre riaprire
la SSRF: occorre distinguere il controllo dell'URL dal controllo della firma e
dall'autorizzazione dell'attività.

### 2.4 La pressione a produrre una lead può indirizzare verso piste deboli

Gitea A04, righe 4972–5073: il Reader discute cifratura legacy e precondizioni come
scrittura del DB; il Reviewer finisce per ordinare una lead sulla derivazione
`sha256(SECRET_KEY)`, senza avere stabilito una capacità concreta dell'attaccante
che renda sfruttabile quel punto nell'istanza.

Gitea A06, righe 6774–6814: il Reader torna ripetutamente al TODO sui codici monouso,
pur riconoscendo che il reset cambia il materiale del codice e che il replay
dell'attivazione email è idempotente. La sola assenza di uno store dedicato continua
a essere trattata come motivo per emettere una lead.

Queste piste non diventano finding finali. Non sono falsi positivi del report, ma
mostrano lavoro che più budget potrebbe prolungare senza aumentare il rendimento.
La soglia Reader deve restare permissiva, senza chiedergli l'intera conferma statica:
operazione sensibile + input plausibile + confine concreto. Se l'analisi ha già
identificato una barriera, serve indicare perché potrebbe essere insufficiente,
anziché ignorarla per soddisfare una voce della checklist.

### 2.5 Differenza di progetto e strumenti

Il source mount censisce 4.033 file Gitea contro 1.591 YesWiki. Gitea presenta più
passaggi fra router, contesto, servizi e modelli; i casi del catalogo includono
permessi fra risorse e cambi di stato. È plausibile che richieda più ricostruzione
verticale; il numero di file, da solo, non misura la difficoltà né la qualità del modello.

Il census Gitea espone 20 nodi Route, con esempi che sono URL di test/dipendenze,
non un inventario affidabile degli endpoint. Semgrep usa local-fallback per checksum
mismatch e restituisce zero segnali conservati con due errori. Nessuna chiamata a
trace_data_flow/trace_code_path in Gitea. In YesWiki compaiono una chiamata
Confirmer a trace_data_flow e due a trace_code_path: troppo poco per attribuire loro
un miglioramento globale.

**Approfondimento Semgrep dopo la domanda dell'utente: due guasti verificati.**

1. Il file locale `rules/community/default.yml` ha SHA256
   `9eb2efc8277f8f2c4ee8e2decfe086b834cfa5e4bbefae3266cb6e30df30d4df`, mentre
   `ruleset.lock.json` richiede
   `af036071eddae65eb2014447ab23c56320ff52a5d591244eea316a280a21b264`.
   `_ruleset_manifest` rifiuta quindi il corpus community; restano le 11 regole
   locali. La divergenza è riprodotta nel checkout, oltre che dichiarata dalla run.
   Non è spiegata dalla semplice conversione LF/CRLF; la causa storica della modifica
   del corpus non è ricostruita. Non aggiornare ciecamente l'hash senza verificare
   quale snapshot si intenda distribuire.
2. `_is_security_rule` accetta gli ID locali solo se iniziano con `lailaps.`,
   oppure se i metadata dichiarano security/CWE/OWASP. Semgrep nel container genera
   invece ID come `app.rules.lailaps.surface.sql`. Le regole locali SQL/filesystem
   hanno solo metadata `family`, quindi il filtro le elimina.

Riproduzione offline effettuata nell'immagine locale `lailaps-pentest-agent:dev`,
senza rete, API o agent loop: un solo file Go sintetico con `db.Query(...)` e
`os.ReadFile(...)`; Semgrep esegue sette regole Go, trova due risultati, exit 0,
zero errori. Entrambi i risultati sono scartati dal filtro Lailaps. È una prova
del difetto dell'integrazione corrente, non una riesecuzione della scansione storica.

Le regole dynamic-code hanno invece `category: security`: possono superare il
filtro anche con ID prefissato. Questo spiega in modo coerente perché YesWiki
conservasse segnali di una sola famiglia mentre Gitea ne conservava zero.
`raw_signal_count` viene calcolato dopo il filtro: zero non dimostra che Semgrep
abbia trovato zero risultati grezzi. Il dump conserva solo il numero dei due errori
storici, non il loro dettaglio; non possiamo attribuire loro la perdita di tutti
i risultati. Anche `status=ready` è assegnato dal solo exit code e nasconde il
degrado del ruleset. Questi sono bug dell'integrazione, non incapacità di Semgrep
di analizzare Go.

Gitea A06 registra inoltre otto errori di lettura in una tranche (telemetria e
Reviewer, righe 6848–6918). I source ref suggeriti dal checkpoint non sono tutti
nella lista finale dei riferimenti del checkpoint. La causa esatta non è dimostrata
dal dump, che non conserva tutti i risultati grezzi: verificare risoluzione/autorizzazione
dei ref al boundary con un test offline, senza chiamarlo già bug risolto o diagnosticato.

## 3. Worker: conversione e cause di mancata conferma

### 3.1 YesWiki: 12 Worker, 6 esperimenti con disposizione dinamica

| Esito finale | Lead | Numero |
|---|---|---:|
| Confirmed | a04-5, a04-6, a02-2 | 3 |
| Rejected dinamicamente | a07-1, a04-1, a04-4 | 3 |
| Continuazione richiesta, fermata dopo tre retry | a01-1, a06-1 | 2 |
| Judge rinuncia citando il budget della tranche | a04-7 | 1 |
| Blocco capacità strumenti: cookie | a04-2 | 1 |
| Timeout fatale Judge | a08-2, a09-1 | 2 |

Prefissi abbreviati: `a04-5` significa `a04-lead-5`.

Conversione grezza Worker→confirmed: **3/12 = 25%**. Esperimenti arrivati a una
decisione dinamica confirmed/rejected: **6/12 = 50%**. Il secondo numero separa
la capacità di terminare una verifica dalla qualità delle ipotesi ricevute.
Una rejection motivata è un risultato utile; non va convertita artificialmente
in confirmed per alzare il tasso.

**Continuità insufficiente.** a01-1 consuma circa 234K Worker; a06-1 circa 254K.
Entrambe hanno tre retry concessi e un quarto voto Judge `retry_worker`, ma terminano
`judge_stopped`. In a01-1 non parte la richiesta decisiva all'endpoint; in a06-1
la preparazione di pagine/ACL incontra antispam e problemi del form. Il costo
cumulativo non equivale a una singola tranche ampia e continua.

**Judge che anticipa la negazione economica.** a04-7, righe 21641–21679:
login admin verificato su API, nessun probe della duplicazione ancora eseguito.
Il Worker propone il passo successivo; il Judge cita circa 1.977 EP Worker residui
e decide `keep_suspected`. Non basta correggere l'admission nell'orchestratore:
il Judge deve sapere che autorizzare la continuazione sblocca una nuova envelope,
e che il residuo della vecchia tranche non determina l'utilità dell'esperimento.

**Cookie non modificabile con i canali usati.** a04-2, righe 15139–15157:
il test richiede modificare un byte del token ricevuto. Gli strumenti mostrano
Set-Cookie redatto e non consentono al Worker di ricostruire quel valore casuale.
I tool correnti consentono un override con valore noto; `crypto_artifact` non
risolve l'accesso al valore del cookie già nel jar. Priorità: consentire un'operazione
mirata sul cookie dell'attore gestito dal runtime, conservando la redazione nei log.
Non serve pubblicare tutti i segreti nel contesto del modello.

**Errori fatali.** Tre ReadTimeout Confirmer da 240s (a03-2, a01-3, a07-2)
terminano le rispettive categorie; due ReadTimeout Judge (a08-2, a09-1) avvengono
dopo il lavoro Worker. L'isolamento attuale permette di passare alla categoria
successiva, ma abbandona le altre aree della categoria interrotta. Salvare candidate,
prove e stato e riprendere il solo ruolo fallito; esauriti i retry tecnici, marcare
la lead incompleta e continuare la ricerca ancora finanziata quando possibile.
Non rieseguire automaticamente le operazioni HTTP mutanti già effettuate.

### 3.2 Gitea: il solo Worker ha un oracle non osservabile

`a09-lead-1` richiede confrontare i log di un login fallito con quelli di un
fallimento 2FA. Il runtime usa logging solo su console; il container non può leggere
lo stdout/stderr detenuto dal runtime Docker esterno e non ha un file logger.

Il Worker consuma 72.734 EP, 32 richieste modello, 22 run_target_command e quattro
transazioni HTTP. Il Judge approva `blocked`, non richiede una continuation negata
dal budget (transcript righe 8275–8523). **Per questa lead il blocco documentato è
la mancanza del canale di osservazione**, anche se la tranche è stata superata.

P0: esporre lettura limitata e read-only dei log del target dal lato orchestratore,
oppure predisporre un file logger leggibile nella preparation. Nessun motivo per
far cercare al Worker il docker socket, ampliare accesso generale al Docker daemon
o usare altri 500K EP sullo stesso canale inesistente. La readiness HTTP/fixture
`valid` non garantisce che l'oracle di ogni finding sia disponibile.

### 3.3 Memoria operativa: uso presente, riuso del login ancora debole

YesWiki ha 29 read_notes e sei write_note Worker; Gitea nessuna scrittura Worker.
Le note finali YesWiki riguardano finding o categorie specifiche; manca una procedura
generale di login salvata con scope role=worker. a04-7 ricostruisce ancora il login
dopo che altri Worker avevano autenticato attori.

Quindi la memory non è inutilizzata, ma non è ancora usata sistematicamente per
il costo ricorrente di setup. Conservare la history Worker già esistente, riusare
attori/sessioni e salvare procedure verificate nei notebook esistenti. Una nota
specifica del finding non sostituisce una procedura operativa riusabile.

## 4. Priorità proposte

### P0 — aumentare ricerca utile e verifiche concluse

1. **Applicare continuità e budget del piano precedente.** Full history Reviewer,
   Reader continuo nella stessa area, 3M discovery regular effettivi per categoria,
   envelope indipendenti per lead, estensioni finanziate realmente. Separare nel
   messaggio al Judge residuo di tranche e possibilità di finanziare il prossimo passo.
2. **Aggiungere tre regole narrative a Reader/Reviewer**, senza nuovi agenti o DTO:
   - seguire attore → route → handler → risorsa effettiva → permesso, includendo
     oggetti collegati e cambi di stato quando presenti;
   - distinguere ipotesi per controllo di sicurezza, non per nome della funzione:
     un finding confermato non rende tutta la funzione già verificata;
   - non chiudere come negativa una domanda irrisolta e non imporre una lead
     basandosi soltanto su TODO, algoritmo o assenza di un controllo nominale.
3. **Rendere esprimibili gli oracle già falliti:** log target read-only e manipolazione
   mirata dei cookie dell'attore tramite il runtime. Riutilizzare le capacità
   esistenti dove bastano; niente framework universale di preparation.
4. **Isolare gli errori tecnici della singola lead**, preservando prove e proseguendo
   le altre aree finanziate. Verificare offline anche il caso degli otto read_source_ref
   falliti: history completa non deve conservare riferimenti inutilizzabili.
5. **Worker responsabile del setup e del riuso:** procedure di login, route, baseline
   e creazione degli oggetti verificate, annotate con lo scope già previsto dal piano.
6. **Ripristinare l'inventario Semgrep:** riallineare snapshot verificato e manifest;
   correggere il riconoscimento delle regole locali con ID prefissato; riportare
   il ruleset degradato come tale. Una verifica reale sul piccolo file Go protegge
   questo contratto meglio di risultati mock con ID sempre `lailaps.*`.

Questi punti ampliano le raccomandazioni del piano: non sono implementazioni effettuate
da questa analisi. Le correzioni dei timeout devono partire dal percorso reale già
presente, senza assumere che alzare soltanto 240s elimini gli errori provider.

### P1+ — dopo il primo confronto controllato

- Modello Reader/Reviewer più capace o reasoning diverso, sugli stessi episodi con
  tool e budget funzionanti. Il limite è plausibile, ma cambiare modello insieme a
  tutto il resto impedirebbe di capire quale modifica conta.
- Ricerca organizzata globalmente per superficie e concorrenza: non prerequisiti
  per seguire verticalmente una superficie dentro l'area corrente.
- Migliorare l'inventario Route, ampliare le regole Semgrep oltre il ripristino
  dell'integrazione e valutare gli strumenti data-flow quando usati. Il dato attuale
  non giustifica costruire subito un altro agent.

## 5. Come validare il miglioramento

Prima verifiche offline dei boundary, del funding, dei source ref e dei canali di
osservazione. Successivamente, solo con autorizzazione a inferenza reale, pochi
episodi controllati con stessi sorgenti, modelli e condizioni:

- EraseSpamedComments: la domanda di autorizzazione sopravvive al checkpoint?
- Gitea label/fork: il Reader segue il permesso sulla risorsa corretta fino
  all'handler/service, invece di fermarsi alla lista dei middleware?
- ActivityPub: distingue SSRF e validazione della firma nella stessa funzione?
- YesWiki setup→test: dopo login o preparazione riusciti il Worker arriva al probe,
  senza una chiusura dovuta al residuo della tranche?
- Cookie e log: l'osservazione richiesta è ora materialmente eseguibile?

I manifest restano oracle di valutazione offline: non inserire CVE, file decisivi
o soluzione nei prompt dell'esperimento Reader. Per i Worker si può usare una candidate
fissata per misurare soltanto la fase dinamica. Dopo questi episodi, una global verifica
che il risultato si estenda oltre i casi diagnostici.

Misurare separatamente: recall catalogo statico/dinamico; finding fuori catalogo
adjudicati; conversione da anchor a lead; verifiche con test decisivo completato;
confirmed, rejected e incomplete per causa; EP discovery per categoria e per verifica.
Non premiare il solo aumento di lead, file letti, aree chiuse o richieste HTTP.

**Esito atteso del P0:** meno piste perse e meno verifiche interrotte prima del
test. Non è possibile promettere un nuovo recall o attribuire un numero di CVE
recuperabili senza eseguire quel confronto.
