# P0 — Deduper specializzato e valutazione semantica dei benchmark

Data: 28 settembre 2026. Handoff implementativo per un nuovo agente.
Repository: `C:/Users/Alessandro/Desktop/latest-projects/lailaps`.

## 1. Obiettivo e decisioni dell'utente

Introdurre un deduper specializzato per lead e aree/assignment, poi automatizzare
la valutazione dei benchmark distinguendo contabilità deterministica e giudizi
semantici. Riutilizzare gli output Reader/Recon già validi; spendere output token
per riscriverli soltanto quando la sovrapposizione è parziale.

**L'ultima decisione dell'utente sostituisce il precedente divieto assoluto di
riscrittura:** il deduper può produrre un nuovo output strutturato nei casi di
deduplica parziale. `pass` e `block` restano minimali. Non introdurre un editor
generalista né obbligare il modello a riserializzare ogni lead.

Il ruolo decide relazioni semantiche; non conferma vulnerabilità, non assegna
severità, non fa discovery e non corregge la strategia investigativa del Reader.
L'orchestratore assegna ID, collega provenienza, applica decisioni e persiste.

Il task consiste nell'implementazione e nella verifica offline. Non lanciare
nuove run Reader, Confirmer, Worker o valutazioni LLM a pagamento come test.
Consegnare i comandi per la successiva calibrazione, senza eseguirli.

## 2. Contesto da preservare

Leggere `AGENTS.md` e le sezioni pertinenti di `ARCHITECTURE.md`. Il checkout ha
numerose modifiche preesistenti: non resettarle, non sostituirle con HEAD e non
attribuirsele. Questo piano non richiede nuovi task Codex o subagenti.

Documenti precedenti:

- `C:/Users/Alessandro/Documents/Codex/2026-09-27/x20-ag/outputs/P0-reader-efficienza-e-confronto-modelli-20260927.md`.
- `plans/reader-256k-delivery-20260927.md`.
- `plans/P0-reader-adaptive-exploration-20260925.md`.
- `C:/Users/Alessandro/Documents/Codex/2026-09-27/x20-ag/outputs/Reader-anchor-e-velocita-20260928.md`.

Conservare checkpoint capaci di emettere prodotti terminali, prefisso stabile,
coalescenza checkpoint/compaction, cognitive window, catalogo e scorecard.
Nel benchmark self-checkpoint mantenere zero richieste al vecchio Reviewer.
Il nuovo deduper ha ruolo, contabilità e limiti propri; non è un alias Reviewer.

Evidenze locali verificate:

| Serie / child | Area | Output enrichment | Stato |
|---|---|---|---|
| MiMo/Xiaomi `yeswiki-reader-area-20260927-225345` | 4/API | `reader-16`, API delle estensioni/Bazar | pending_review |
| DS/InferenceNet `yeswiki-reader-area-20260927-222906-3` | 5/Bazar | `reader-9`, campi e template Twig raw | pending_review |
| DS/InferenceNet `yeswiki-reader-area-20260927-222906-4` | 6/rendering | `reader-7`, preset CSS/ThemeManager | pending_review |

Gli outcome sono in `storage/app/runs/yeswiki/reader-area/<child>/<child>-outcome.json`.
`accepted=false` con `review_status=pending_review` non è un rifiuto. T1 del
25 settembre e snapshot DS/StreamLake del 27 settembre non contengono enrichment.
Alcuni parent sono incompleti: non usare il loro zero come assenza nei figli.

## 3. Scope P0 e sequenza

Implementare nell'ordine, con checkpoint di consegna verificabili:

1. Raccolta riproducibile degli artifact, mantenendo output e contabilità grezzi.
2. Deduper comune per lead e task, gestione dei derivati parziali, budget separato.
3. Integrazione ai confini di acquisizione enrichment e inoltro a Confirmer;
   valutazione offline dello stesso deduper sui benchmark Reader esistenti.
4. Valutatore semantico offline sul risultato deduplicato, scorecard e commento.

Il P0 include lead duplicate tra aree dello stesso audit, non solo nello stesso
child. Le aree iniziali Recon e gli enrichment devono poter essere confrontati
con lo stesso contratto per task. Non serve eseguire repetitions o creare un nuovo
scheduler per dimostrarlo. Non modificare Golden Recon e manifest storici.

Non aggiungere nuovi agenti per ciascun tipo di oggetto, vector database,
framework di eval, UI, consensus tra judge o un secondo registro indipendente.

## 4. Contratto del deduper

### Input e decisioni

Una decisione riguarda una proposta di cui l'orchestratore conosce già ID e tipo.
L'input include precedenti con ID qualificati per run: `lead-1` da solo non è unico.
Progetto e snapshot sorgente delimitano il confronto; incompatibilità e provenienza
non sono affidate all'inferenza del modello.

| Decisione | Output modello | Applicazione |
|---|---|---|
| pass | Solo discriminante; nessuna copia del payload | Inoltrare l'originale invariato |
| block | Discriminante e ID dei precedenti che coprono interamente la proposta | Conservare l'originale; sopprimere soltanto il dispatch duplicato |
| partial | ID correlati, breve spiegazione e un nuovo prodotto dello schema pertinente | Conservare gli originali e acquisire il derivato con provenienza |

Forme indicative: `{"decision":"pass"}` e
`{"decision":"block","related_ids":["run-A:lead-3"]}`.
Non richiedere motivazioni lunghe per pass/block. Una motivazione breve facoltativa
è ammessa; input, precedenti e decisione restano ispezionabili.

Per `partial` riutilizzare i campi di `ReaderLead`, `AreaEnrichmentLead` o della
forma assignment esistente. Preferire output piatti con il solo discriminante e
i riferimenti aggiuntivi necessari. Esporre solo lo schema del tipo in esame,
non un DTO annidato con tutti i tipi, liste di patch o piani di dispatch.
Il payload derivato, rimosse le informazioni di controllo, deve essere consumabile
dal downstream esistente. Validare il payload serializzato realmente inviato al provider.

L'incertezza non è una prova di unicità. Consentire un pass con indicazione breve
di incertezza (es. `uncertain=true`); l'orchestratore registra deduplica inconclusiva.
Errori tecnici/budget producono lo stesso comportamento conservativo, con motivo
tecnico distinto. Non creare un altro turno solo per ottenere una motivazione.

### Semantica della sovrapposizione

- Lead: confrontare ipotesi, operazione, causa e condizioni; stesso titolo, path,
  anchor, CWE o sink non implica duplicazione. Nuove condizioni, controprove o
  evidenze utili impediscono un block che ne faccia perdere il contributo.
- Task: confrontare domanda e lavoro necessario, non soltanto directory/famiglia.
  Componente assegnata, visitata o genericamente dichiarata chiusa non dimostra
  che una domanda specifica sia stata coperta. Considerare anche lavoro pendente.
- Un block può riferirsi a più antecedenti, ma deve coprire tutta la proposta.
  Non usare la transitività della somiglianza per fondere catene di ipotesi diverse.
- Consultare anche precedenti non ancora confermati: queued/unfunded non significa
  inesistente. Un precedente bloccato per budget non dimostra lavoro eseguito.

### Come applicare un partial senza perdere semantica

Consentire due usi dello stesso risultato partial, distinguibili con un piccolo
campo di controllo: `merge` oppure `residual`.

1. **merge:** stessa ipotesi/task, ma evidenze o dettagli complementari. Il nuovo
   prodotto rappresenta l'unione supportata; diventa una versione derivata del
   prodotto canonico, con tutti gli originali collegati. Conta come una sola
   ipotesi/task; non sovrascrive i documenti originali.
2. **residual:** parte già coperta e domanda/ipotesi nuova indipendente. Il nuovo
   prodotto esprime la parte residua; i precedenti restano invariati. Conservare
   nel derivato il contesto condiviso necessario a comprenderla, senza imporre
   una sottrazione testuale. Conta come lavoro distinto.

Il deduper può riformulare soltanto quanto sostenuto dagli input. Deve preservare
precondizioni, incertezze e controprove; non inventare nuove evidenze, file letti,
stati di conferma o domande estranee. La validazione deterministica controlla
schema/ID/provenienza, non può certificare la fedeltà semantica della riscrittura.
Questa va misurata nel campione di calibrazione.

Per mantenere il P0 piccolo, un partial restituisce un solo prodotto derivato.
Quando servirebbe una scomposizione multipla non rappresentabile, pass incerto:
non comprimere forzatamente ipotesi indipendenti per ottenere una forma valida.

Non modificare un incarico già in esecuzione né riaprire automaticamente un
verdetto Confirmer terminale. Collegare il materiale nuovo come integrazione
pendente tramite i meccanismi esistenti; in assenza di un percorso sicuro,
conservarlo pending senza inventare uno scheduler di riapertura. La deduplica
pre-Confirmer ordinaria avviene prima del dispatch, così il set può essere consolidato.

## 5. Contesto, strumenti e costo

### Pacchetto iniziale

- Proposta completa; assignment d'origine soltanto se necessario a interpretarla.
- Indice compatto dei precedenti canonici: ipotesi/domanda, locator, stato e ID.
  Riutilizzare le proiezioni esistenti, senza una chiamata LLM per creare ogni scheda.
- Originali dei candidati pertinenti già disponibili nel pacchetto.
- Riferimenti alle evidenze persistite e alla provenienza.

Non fornire lo snapshot completo di Exploration Reviewer. L'ultimo turno Reader
e gli snippet non entrano automaticamente: sono recuperabili per un dubbio concreto.
Il deduper non riceve manifest CVE, punteggi benchmark o valutazioni del judge.

Tool di sola lettura sugli artifact: leggere originali per ID, consultare pagine
del registro, recuperare source reference e passaggi di transcript associati.
Riutilizzare funzioni/registri esistenti. Niente shell, HTTP, Joern, lettura libera
del repository o nuova ricerca di vulnerabilità.

Un block/merge richiede accesso agli originali coinvolti, non solo alle schede.
Le euristiche di path/famiglia possono ordinare i confronti, non dimostrare che
non esistano duplicati. Registrare il perimetro confrontato. Un indice troncato
non deve diventare una deduplica globale certificata; segnalare l'incompletezza.
Nel campione iniziale usare l'indice completo se rientra nel limite.

### Limiti iniziali, da calibrare

- Un'inferenza ordinaria e al massimo una seconda inferenza dopo un recupero
  raggruppato di artifact. Massimo **due richieste provider complessive** per
  decisione, includendo retry; errori tecnici non aprono un loop illimitato.
- Guard iniziale **24.000 token di input completo** per richiesta e **4.096 token
  di output massimo**, inclusi i token di ragionamento nel limite quando il provider
  lo consente. Sono cap sperimentali, non budget da consumare né valori validati.
  Il cap output deve permettere un partial valido: misurare troncamenti e adattare
  la configurazione senza allungare pass/block con testi obbligatori.
- Modello/provider espliciti nella configurazione, prezzi dal catalogo esistente;
  nessuna scelta tacita del modello Reader o fallback economico non registrato.
- Budget EP totale del deduper esplicito e indipendente dall'envelope Reader;
  non moltiplicarlo automaticamente per ogni nuova lead. Usare budget manager,
  preflight di costo e telemetria esistenti; nessun nuovo sistema contabile.
- Verificare il finanziamento prima di ciascuna richiesta, tenendo conto di
  input/output stimati. Il cap economico operativo può avere overshoot dovuto
  alla granularità delle richieste: registrarlo, non promettere un limite esatto.

Obiettivo di calibrazione: deduper intorno o sotto il **5% del costo discovery**,
misurato in USD comparabili quando disponibili e separatamente in EP. Non è una
soglia di qualità dimostrata né una regola per scartare output. Al cap: conservare
le proposte e marcare il lavoro non valutato. Non chiamarle uniche.

Dimensionare assumendo cache hit zero. Prefisso/toolset stabili e registro ordinato
possono aiutare la cache propria del deduper; non contare sulla cache del Reader
né mantenere una history crescente solo per inseguire cache hit.

## 6. Integrazione e persistenza

Riutilizzare il percorso comune di acquisizione per output ordinari e da checkpoint.

- **Enrichment:** acquisire sempre il grezzo; deduplicare contro aree/task e proposte
  già presenti prima del dispatch. Con `--defer-enrichments`, conservare gli esiti
  senza avviare figli. La deduplica non concede budget di discovery aggiuntivo.
- **Lead:** confrontare il set cross-area prima dell'inoltro a Confirmer. Il Reader
  non deve aspettare una review dopo ogni emissione nel benchmark Reader-only.
  Nel percorso che inoltra immediatamente una lead, applicare lo stesso servizio
  al confine del dispatch con il registro corrente; non duplicare il vecchio novelty
  reviewer e il deduper sulla stessa proposta.
- **Recon:** usare la forma task per normalizzare aree prima della pianificazione
  quando il deduper è abilitato. La Golden Recon resta immutabile: scrivere una
  proiezione derivata e preservare la mappatura degli ID selezionati dall'utente.
  Non dichiarare completezza globale se è stata selezionata solo parte delle aree.

Il coordinatore è proprietario del registro e serializza applicazione e dispatch:
due child concorrenti non devono entrambi passare confrontandosi con lo stesso
registro vuoto. Per P0 basta una coda nel coordinatore e ordinamento riproducibile;
non serve un servizio distribuito. Il primo output senza precedenti passa senza LLM.

Persistenza minima: originali immutabili, decisioni, ID canonici/derivati, relazioni,
stato di deduplica, costi e configurazione. ID qualificati anche per le source ref;
non reinterpretare un `src-*` usando il ledger di un altro child.
Nessuna doppia acquisizione di output ordinario/checkpoint o parent/child.

Ri-esecuzione con gli stessi input, versione prompt/schema/modello e registro
confrontato riutilizza gli esiti persistiti. Registrare hash dei contenuti e del
contesto confrontato: un registro cresciuto può invalidare un vecchio pass.
Non riscrivere gli outcome storici o i loro timestamp per registrare l'eval.

## 7. Benchmark: contabilità e interpretazione separate

### Raccolta deterministica

Partire dai parent e dai fixture che mappano i child. Leggere gli outcome figli
anche se il parent non è finalizzato; non includere directory vicine solo perché
temporalmente prossime. Dichiarare child mancanti e snapshot parziali.
Congelare gli input di ogni valutazione e registrare hash/versione; un artifact
ancora in scrittura deve dare una valutazione provvisoria su una copia coerente.

Conservare metriche già presenti: EP, USD reali/stimati e completezza, richieste,
token/cache, tool error rate, retry, tempi, checkpoint/compaction e output grezzi.
Aggregare rapporti dalle somme. Tool error, provider error e validation error
rimangono distinti; esiti mancanti non sono zero. Non chiamare TPS provider il
rapporto output token/tempo totale child. Nessuna nuova strumentazione TTFT in P0.

Aggiungere pass/block/partial/incerti, originali/derivati, task e lead canoniche,
cost accounting deduper e valutatore separati. Solo la parte adjudicata sostiene
un conteggio di unicità: mostrare insieme copertura e pendenti, non un totale
apparentemente definitivo in presenza di pass incerti.

### Valutatore semantico offline

Lavora sul set deduplicato e sui suoi originali/prove, senza riavviare Reader o
Confirmer. Può leggere manifest, sorgente dello snapshot corretto, artifact e
transcript pertinenti con strumenti di sola lettura. Nessuna conferma dinamica.
Il suo budget/modello sono separati anche da quelli del deduper.

Per ogni ipotesi canonica produrre un giudizio breve con riferimenti verificabili:

- casi manifest corrispondenti, non corrispondenti o incerti;
- qualità della lead: sostenuta/plausibile, contraddetta o non valutabile;
- ragione essenziale, evidenze e principali condizioni/limiti.

Match e qualità sono dimensioni diverse. Una lead può descrivere lo stesso caso
del manifest ma fare affermazioni non sostenute. Fuori catalogo non significa falso
positivo e plausibile non significa vulnerabilità confermata. Un output Reader
non viene promosso a conferma statica/dinamica dal solo giudizio del benchmark.

Valutare anche i match già proposti dal matcher deterministico, non soltanto gli
unmatched. Usare il matcher come candidato diagnostico, mai come verità del judge.
Non imporre una sola CVE per lead o contare più volte la stessa CVE: conservare
la relazione molti-a-molti e aggregare casi unici. Se un prodotto contiene ancora
più ipotesi non separabili, dichiarare il limite del conteggio di root cause.

Per i casi senza hit distinguere: nessun output corrispondente, evidenza di lettura
insufficiente, lettura osservata con decisione errata documentata, problema tecnico,
manifest/snapshot non valutabile. La diagnosi del comportamento può consultare le
tracce grezze: non tutto questo lavoro dipende dalle lead deduplicate. Fare letture
mirate soltanto per i miss, non un secondo audit integrale. Mancanza di evidenza
non autorizza a inventare la causa del miss.

Una query al codice da parte del valutatore può chiarire il match ma non dimostra
che Reader abbia letto quel codice o formulato quell'ipotesi. Registrare la
provenienza delle evidenze. Non attribuire al Reader una discovery ricostruita
interamente dal giudice a partire dal manifest.

Scegliere un contratto piatto, ridotto; ID di cluster e contesto noti si aggiungono
nell'orchestratore. Una decisione per cluster/caso, senza history crescente.
Cap esplicito per richiesta e per fase, al massimo un recupero mirato aggiuntivo;
limiti o failure lasciano `pending/inconclusive`, senza trasformarli in miss/FP.

### Reach e scoring

L'attuale `BenchmarkEvaluator` 3.3.0 implica `anchor_reached` da un match suspected;
anche `BenchmarkAdjudicator` forza file/anchor a true su `match_case`. Separare nei
nuovi risultati **lettura osservata**, **match semantico** e **stadio del finding**.
Un match non genera un evento di lettura. Versionare la nuova semantica, aggiornare
i consumer pertinenti e non reinterpretare silenziosamente le vecchie scorecard.

Riconoscere evidenze da tool diversi quando il risultato persistito le sostiene;
non basta una chiamata shell contenente un path o un intervallo per provare che il
codice sia stato restituito. Gli anchor restano diagnostici, non gate degli hit.
Non serve scrivere un parser universale di shell: usare provenance disponibile e
adjudication mirata con riferimento al risultato. Assenza di lettura registrata
rimane distinta da codice certamente non letto.

Calcolare deterministicamente dopo i giudizi:

- `100000 * U / E_discovery`: ipotesi uniche per 100k EP discovery.
- `100000 * U / (E_discovery + E_dedup)`: produttività includendo normalizzazione.
- Recall dei casi manifest unici, qualità fuori catalogo, tempi/costo di produzione
  utile; USD soltanto completi o con stima e basis dichiarate separatamente.

Mostrare costo evaluator a parte e costo complessivo del benchmark. Specificare se
Recon è riusata (costo incrementale zero) o eseguita. Denominatori nulli/mancanti
rendono il rapporto non disponibile. Dichiarare il perimetro: il catalogo completo
YesWiki non coincide con le sole aree 4/5/6; niente sottoinsiemi scelti dopo i risultati.

Per confrontare modelli, deduplicare ogni serie separatamente. Eventuali relazioni
cross-serie non trasferiscono evidenza o credito da un modello all'altro. Una futura
unione di repetitions sarà una misura esplicita e distinta dal risultato di ciascuna.
Nelle curve temporali una versione consolidata non anticipa le evidenze che la
compongono: usare gli eventi di acquisizione originali e non attribuire il risultato
finale all'istante della prima lead se allora l'evidenza era insufficiente.

### Commento finale

Produrre JSON autorevole e report Markdown leggibile con un breve commento sul
lavoro dell'agente: risultati sostenuti, limiti, miss osservabili, economia ed errori.
Il commento deriva da metriche e giudizi persistiti con riferimenti agli artifact;
non inventa conclusioni causali o una classifica generale da run parziali.
Per P0 assemblare giudizi e dati già prodotti; non è necessario un terzo ruolo LLM
dedicato alla scrittura del report.

## 8. Punti di innesto da verificare nel checkout

- `agent/pentest-agent/src/pentest_agent/models.py`: ReaderLead, AreaEnrichmentLead,
  forma delle aree Recon, LeadNoveltyReview. Riutilizzare campi/payload esistenti.
- `triple_agent.py`: `make_lead_novelty_reviewer`, `_review_reader_lead_novelty`,
  acquisizione enrichment e confini Confirmer. Il vecchio snapshot è troppo ampio
  per il nuovo ruolo; non copiare `_exploration_snapshot` nel deduper.
- `deps.py`, `ledger.py`, `budget.py`, `pricing.py`, CLI e tool read-only: stato,
  contabilità e acquisizione. Estendere solo i percorsi necessari.
- `app/Console/Commands/BenchmarkReaderGlobal.php`: raccolta child, enrichment,
  hash payload esistente (non semantico), aggregazione e scorecard.
- `BenchmarkStageProcessRunner`, `BenchmarkStageArtifactRegistry`,
  `BenchmarkStageSubjectSelector`, `BenchmarkConfirmer`: riusare runner e handoff.
- `BenchmarkEvaluator`, `BenchmarkAdjudicator`, `BenchmarkResultAggregator`:
  separare evidenza osservata e match; l'adjudicator attuale è umano e richiede
  User, quindi non spacciare giudizi LLM per human_adjudication.

Preferire un modulo Python mirato per deduper/evaluator e i runner esistenti.
Niente protocollo nuovo fra PHP e Python se gli artifact di stage bastano.

## 9. Interfaccia di consegna

Consegnare un comando di post-processing sugli artifact esistenti, senza discovery.
Nome proposto: `php artisan benchmark:reader-evaluate <target-id>`.
Può essere adattato alle convenzioni esistenti, documentando la forma effettiva.

Requisiti CLI:

- selezione esplicita del parent/artifact e directory di output;
- modalità solo metriche, solo deduplica oppure pipeline completa;
- modello/provider e budget totale distinti per deduper e evaluator;
- riuso di un artifact dedup già completato per rieseguire soltanto la valutazione;
- input/config non validi falliscono prima delle richieste provider;
- report di fasi incomplete e comandi help funzionanti senza chiamate a pagamento.

Non aggiungere flag per ogni parametro interno: limiti tecnici possono essere
impostazioni del ruolo persistite nel manifest. Nuove run Reader devono poter
abilitare la fase esplicitamente; nessuna sorpresa di spesa nei comandi storici.
Il percorso integrato abilitato usa obbligatoriamente l'output deduplicato per il
dispatch. Se la deduplica è incompleta, il downstream riceve tale stato esplicito.

## 10. Verifiche offline e calibrazione successiva

Test mirati con modelli/processi simulati, negli harness esistenti:

1. Pass preserva il payload; block non cancella e non effettua dispatch doppio.
2. Partial merge conserva contributi e originali, produce una sola ipotesi;
   residual conserva la domanda nuova e i precedenti. Stesso anchor con ipotesi
   diverse non è automaticamente duplicato.
3. ID errati, derivato invalido, evidenza non accessibile, budget esaurito e timeout
   preservano la proposta come inconclusiva; nessun loop di riparazione illimitato.
4. Originali da child diversi e source ref locali restano correttamente qualificati;
   output da checkpoint non vengono acquisiti due volte.
5. Due child concorrenti non dispatchano due volte lo stesso lavoro; re-run degli
   stessi input non raddoppia derivati, costi contabilizzati o task.
6. Area assegnata non equivale a domanda risolta; `--defer-enrichments` impedisce
   figli anche con pass/partial. Nessuna modifica di assignment già in esecuzione.
7. Massimo richieste, tool consentiti e cap separati realmente applicati. Zero
   chiamate al vecchio Reviewer nel percorso self-checkpoint coperto dal piano.
8. Parent incompleto recupera child mappati, senza doppio conteggio; missing resta
   missing. USD incompleti e qualità pending non diventano zero.
9. Matcher positivo errato e match semantico senza anchor possono essere adjudicati;
   nessun hit implica automaticamente lettura o conferma. Miss e non valutabile
   restano distinti. Una CVE abbinata a più lead conta una volta.
10. Serie di modelli diverse non si prestano evidenze; valutazione con tool sul
    codice non viene attribuita al Reader. Report e scorecard concordano.

Riusare, quando pertinenti, test Python `test_recon_reader_assignments.py`,
`test_triple_agent.py`, `test_benchmark_v0_metrics.py`, `test_economic_budget.py`
e test PHP BenchmarkReaderGlobal/Contract/StageArtifactRegistry/FinalizationRecovery.
Eseguire selezioni mirate e i nuovi test; non il lint globale.

Preparare un piccolo campione di calibrazione versionato: 12–20 confronti storici
che coprano pass, duplicazione totale, stessa ipotesi con nuova evidenza, residuo,
ambiguità, aree sovrapposte. Includere le tre proposte reali elencate sopra e casi
benchmark noti al report (letture shell perse, SSTI non abbinata automaticamente).
Annotare manualmente esito atteso e prova; non fabbricare una verità di riferimento
se il caso è ambiguo. Il campione non deve essere tutto YesWiki-specifico nei prompt.

Preparare, senza eseguire, un confronto del pacchetto minimo con l'aggiunta mirata
di evidenze/ultimo turno. Misurare: block errati, duplicati residui, perdita/invenzione
di contenuto nei partial, richieste aggiuntive, token incl. reasoning, USD/EP,
latenza e quota sul costo discovery. Pass/block maggioritari sono un'ipotesi da
misurare, non una quota da imporre al prompt.

I test simulati verificano integrazione e contratti, non accuratezza semantica né
risparmio economico reale. La promozione del modello e dei cap resta successiva
alla calibrazione autorizzata; dichiarare esplicitamente questa distinzione.

## 11. Definition of done

- Deduper implementato e collegato ai confini concordati; pass/block minimali,
  nuovi prodotti soltanto per partial; originali e provenienza preservati.
- Budget separati e limiti applicati; nessuna reintroduzione del vecchio Reviewer.
- Post-processing degli artifact esistenti produce set deduplicato, handoff
  consumabile dal Confirmer, scorecard deterministica e valutazione semantica.
- Reach osservato e match semantico separati e versionati; stato incompleto
  correttamente propagato. JSON e report leggibili anche senza giudizi completati.
- Test offline pertinenti superati; limiti/residui dichiarati senza fingere
  accuratezza empirica. Documentare i test non eseguiti e la ragione.
- `ARCHITECTURE.md` aggiornato per ruoli, budget, lifecycle dei derivati e valutazione.
- Consegna di comandi verificati via help/test simulati, directory output,
  configurazione e campione di calibrazione. Nessun audit/provider loop avviato.

## 12. P1+ esclusi

Repetitions Recon/Reader e misurazione del guadagno marginale; scheduling adattivo
degli enrichment; riapertura automatica di task già eseguiti; retrieval semantico
su registri troppo grandi per il P0; ulteriori split/merge multipli; UI di revisione;
multi-judge e calibrazione cross-model estesa; precisione/retention dopo esecuzione
Confirmer; nuova telemetria TTFT/decoding del provider. Segnalare esigenze concrete
emerse, senza implementarle incidentalmente.
