# Valutazione Recon/Reader — YesWiki 20260920-194109

Valutazione del 20 settembre 2026, con lettura indipendente del transcript da parte
di un subagent `gpt-5.6-luna` e verifica del report/codice da parte dell'agente principale.
Nessuna nuova run, modifica degli outcome o chiamata agli agenti dell'audit.

Artifact originali:

- [Outcome](../storage/app/runs/yeswiki/recon-reader-global/yeswiki-recon-reader-global-20260920-194109/yeswiki-recon-reader-global-20260920-194109-outcome.json)
- [Transcript](../storage/app/runs/yeswiki/recon-reader-global/yeswiki-recon-reader-global-20260920-194109/yeswiki-recon-reader-global-20260920-194109-logs.php)

## Giudizio

La pipeline arriva al Reader e produce domande investigative persistite. La mappa
Recon e' piu' ampia del lavoro che la discovery riesce a svolgere entro un'ora.
Il limite osservato non e' l'assenza di incarichi: e' la combinazione di priorita'
seriale, incarichi estesi, molte prosecuzioni e memoria semantica non sempre fedele.
Non basta aumentare il timeout per dimostrare che la strategia sia efficace.

## Stato e misure

Il runner interrompe il processo per timeout a circa 3.604,86 secondi: exit 124,
benchmark `technical_failure`. Il report Python e' l'ultimo snapshot durevole,
`publication_state=partial`, `termination_reason=running`, senza blocker/fatal error
registrati. Non indica che l'agente sia ancora in esecuzione dopo il cutoff.

| Misura nell'ultimo snapshot | Valore |
|---|---:|
| Incarichi Recon | 16 |
| Incarichi chiusi / attivo / in coda | 2 / 1 / 13 |
| Lead Reader acquisite | 9, tutte `suspected` / `unfunded` |
| ReaderReviewRequested persistite | 2 |
| Richieste Recon / Reader / Reviewer | 14 / 167 / 27 |
| Exploration review | 16: 14 continue_current, 2 close_area |
| EP consumati / discovery iniziale | 526.335,68 / 3.000.000 |
| Costo provider registrato | 0,300420322 USD |
| Compaction/epoch transition registrate | 11 |

Sono valori al checkpoint salvato, non una contabilizzazione garantita dell'ultima
richiesta interrotta. Il costo Reviewer registrato e' circa 0,155 USD, oltre meta' del
costo provider totale dello snapshot; in EP pesa circa il 39%. Non tutte le sue
27 richieste sono review esplorative: il ruolo comprende anche novelty review.

Le prime due richieste strutturate di chiusura Reader arrivano ai conteggi cumulativi
52 e 132 richieste Reader. Le aree sono boot/routing e ACL. La terza, autenticazione,
e' ancora attiva. I conteggi non misurano il tempo per area: il log non ha timestamp
per ogni evento. Aree in coda possono aver avuto letture incidentali dai primi incarichi;
non equivalgono a codice mai letto.

La frase finale «I'll yield with ReaderReviewRequested» e' testo di ragionamento nel
transcript, riga 27677. Non compare una terza ReaderReviewRequested acquisita ne' la
chiusura dell'area 3. Il timeout ha tagliato una run ancora attiva; non c'e' evidenza
di un nuovo crash Docker. I nove output gia' acquisiti sono conservati.

## Recon: allineamento buono, profondita' iniziale limitata

Recon produce `ReconPlan` e la sua normalizzazione `CategoryRecon`, con briefing e
note di copertura. Il transcript mostra la versione normalizzata a riga 2701.
Gli incarichi comprendono routing, ACL, autenticazione, API, Bazar/campi, rendering,
upload, modifica contenuti, amministrazione, segreti, setup, DB/CLI, mail/feed,
JavaScript, temi e componenti periferiche. Sono inclusi comportamenti senza un sink
gia' dimostrato e le limitazioni di Semgrep/CBM sono dichiarate.

La Recon registra 4 codebase_architecture, 13 list_dir, 3 run_code, 1 read_file e
1 list_surface_signals. Due locator inesistenti e 36 non osservati sono riportati
in telemetria; un path non osservato dal contatore non prova da solo un'allucinazione,
ma la mappa va trattata come proposta di ricerca e non come conoscenza verificata.
L'ordine parte da tre domini trasversali molto ampi. Bazar, rendering e altri domini
centrali per il catalogo benchmark restano in coda al cutoff.

## Qualita' Reader e memoria: problemi verificabili

1. **Un'ipotesi su un controllo diventa una conclusione.** A riga 16195 il Reader
   considera EraseSpamedCommentsAction admin-only «presumably», dopo averne letto
   il ramo operativo (read a riga 16014). Nel checkpoint a riga 18325 l'azione e'
   riportata come admin-scoped. Il gate di deletepage.php non prova quello di un
   altro caller di PageController::delete. Questa azione e' proprio l'anchor del caso
   CVE-2026-52766 nel catalogo locale: non e' soltanto un'area rimasta in coda, ma una
   pista letta e poi accantonata con una giustificazione da verificare. La seconda
   lettura indipendente dei log non trova un controllo acquisito specifico di questa
   azione: a riga 16197 la presunzione e' gia' trattata come gate verificato.
2. **Una lead raccontata viene confusa con una lead acquisita.** Il Reviewer a riga
   21395/21408 descrive come emessa una `lead-8` su login senza CSRF/rate limit e
   risposta con hash password. In quel momento i sette output precedenti non
   comprendono tale lead. Il vero ottavo output, acquisito a riga 22503, riguarda
   session fixation. Il checkpoint durevole mantiene la descrizione precedente;
   anche gli unknowns della nuova lead ereditano il riferimento incoerente. L'ID e
   lo stato di emissione devono derivare dal ledger, non dalla sola narrativa.
3. **Una premessa tecnica falsa entra in una lead.** `lead-2` sostiene che
   `Access-Control-Allow-Origin: *` insieme ad `Allow-Credentials: true` permetta
   letture cross-origin con cookie. Il browser non condivide la risposta in tale
   combinazione con credentials mode `include`, come specifica il
   [Fetch Standard](https://fetch.spec.whatwg.org/#cors-protocol-and-credentials).
   Questo smentisce quel meccanismo di lettura; non decide da solo eventuale CSRF
   o esposizione anonima, che richiedono ipotesi separate.
4. **Nove output non significano nove root cause.** Lead 6 e 7 descrivono la stessa
   assenza di filtro in LoadRecentComments, con sink RSS e JSON differenti. Sono
   percorsi utili, ma vanno collegati quando si misura il numero di problemi distinti.
   Le lead su claim, commenti e autenticazione hanno osservazioni concrete e unknowns;
   in questa valutazione non ne e' stata verificata la sfruttabilita'.

L'evidenza suggerisce un problema di coordinamento e conservazione dei fatti oltre
alla profondita' delle letture. Non autorizza a concludere che tutte le 14 prosecuzioni
fossero inutili: alcune hanno prodotto lead o chiarito controlli reali.

## Uso di run_code

Il problema Docker della run precedente non riappare, ma l'uso del tool e' poco utile.
Recon invia programmi placeholder/no-op (righe 972, 1037, 1059). Reader a riga 17614
chiama `tools.read_file(f)` con argomento posizionale, mentre il binding accetta keyword.
Il programma cattura gli errori e li ritorna come stringhe: a riga 17663 il risultato
globale e' `ok:true`, ma `subcalls=0` e `observations_acquired=0` con quattro errori.
Reader riconosce l'errore a riga 17666 e torna ai tool diretti: non e' un crash e il
recupero e' positivo. Questa run dimostra l'accesso al runtime, non un uso efficace
dei binding. Un esempio valido e risultati interpretati correttamente sono piu'
utili di nuove astrazioni.

## Benchmark: interpretazione prudente

Il catalogo valutato contiene 12 casi vulnerabili e un controllo negativo.
I diagnostici segnano anchor raggiunta per 6/12 casi vulnerabili e 0 corrispondenze
fra finding e casi. Gli altri sei risultano non raggiunti; il controllo negativo
e' file_reached. Sono euristiche, non una misura semantica completa di recall.

Non contare le 9 lead come 9 CVE trovate; non leggere lo zero automatico come prova
che ogni lead sia falsa. Il contenuto prodotto riguarda soprattutto altre ipotesi.
Il caso EraseSpamedComments e' il punto piu' utile da riesaminare per capire una
mancata scoperta entro il lavoro gia' svolto. I casi Bazar ancora in coda richiedono
invece di valutare priorita' e distribuzione della ricerca, non soltanto il Reader.

## Prossimi passi proposti, non applicati

1. Rendere coerenti checkpoint e ledger: emissione e identita' lead autorevoli;
   distinguere controllo osservato, ipotesi e residuo. Non trasformare un nome come
   "azione amministrativa" in una prova di autorizzazione.
2. Rivedere con esempi di questa run le decisioni di prosecuzione: domanda ancora
   discriminante nell'incarico vs domanda downstream o gia' assegnata altrove. Dare
   al Reviewer visibilita' del lavoro in coda per decidere anche sospensioni/pivot,
   senza soglie arbitrarie sul numero di letture e senza introdurre subito streaming.
3. Separare il cutoff esterno dallo stop ordinato: una finalizzazione anticipata
   potrebbe salvare l'ultimo boundary, ma non risolve da sola la scarsa ampiezza.
   Non inferire mai un output strutturato da «sto per emetterlo».

L'accesso workspace/target richiesto dall'utente e' descritto nel nuovo
[handoff esecuzione](HANDOFF-recon-reader-workspace-target-execution-20260920.md).
Puo' chiarire route e configurazione con meno letture, ma non sostituisce la correzione
degli errori di inferenza e memoria osservati qui. Con una sola run parziale non e'
possibile attribuire il risultato al solo Recon o dimostrare un vantaggio della concorrenza.
