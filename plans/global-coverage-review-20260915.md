# Coverage delle ultime global YesWiki e Gitea

Analisi offline del 15 settembre 2026. Run ancora attive durante la lettura:

- `yeswiki-global-20260914-214544`
- `gitea-global-20260914-214551`

Fotografia dei transcript/outcome alle 00:17 Europe/Rome; aggiornamento dei conteggi
alle 00:20:44. I file originali continuano a cambiare. Nessun audit, exploit o
benchmark rilanciato; nessuna modifica al runtime. I manifest sono stati consultati
solo da questa analisi, non inviati agli agenti in esecuzione.

## Giudizio

Segnali positivi di funzionamento del processo, ma nessuna evidenza ancora sufficiente
di aumento dei finding validi. La coverage resta debole e in un caso una chiusura di
area nasconde la perdita di una pista concreta già riconosciuta dal Reader.

La priorità è conservare e risolvere le ipotesi, finanziare la discovery nelle categorie
successive e verificare i confini di autorizzazione concreti. Aumentare parallelismo,
token o budget totale non corregge da solo questi problemi.

## Stato osservato alle 00:20:44

| Misura | YesWiki | Gitea |
|---|---:|---:|
| Tempo dalla creazione | circa 2h35 | circa 2h35 |
| Categorie presenti nel report parziale | 2 | 7 |
| Token complessivi | 9.089.367 | 8.547.960 |
| Richieste contabilizzate | 275 | 297 |
| Finding confermati | 0 | 0 |
| Finding sospetti nel report | 3 | 0 |
| Lead chiuse staticamente | 1 | 1 |
| Aree closed / active / queued | 5 / 2 / 15 | 4 / 7 / 69 |

Le aree sono unità della Recon, non percentuali di copertura delle vulnerabilità.
Non confondere una categoria visitata con una categoria completata. In Gitea le
categorie riportano esaurimento economico; in YesWiki A03 è technical_failure e A01
è ancora in lavorazione. `publication_state=partial`, benchmark finale assente e
container agente attivi: `partial_failure` non significa che la run sia già terminata.

I contatori globali non sono tutti coerenti: ad esempio nella fotografia YesWiki delle
00:17 la lista dei sospetti contiene due finding mentre `suspected_findings` vale uno.
Per il numero di finding questa analisi usa le liste del report. Costi provider e
stime divergono, diversi ruoli hanno `cost_complete=false`, e il coordinatore aggiorna
parte dei saldi ai confini di categoria. Nessuna conclusione sul costo finale o sul
recall globale deriva da questi aggregati live.

## Segnali positivi, con i limiti del confronto

1. **Le transizioni di area avvengono davvero.** Gitea esegue un pivot dal motore
   permessi agli scope token e torna poi a completare il motore permessi. YesWiki
   chiude due aree supply chain e tre aree A01. Nelle prime global YesWiki del
   12 settembre, documentate in `yeswiki-global-first-two-analysis-20260912.md`,
   dopo circa 2h10 non risultava chiusa alcuna area.
2. **Un errore non ferma tutte le categorie.** YesWiki subisce ancora un timeout
   Confirmer da 240 secondi su `a03-lead-2`, ma passa ad A01 e continua a cercare.
3. **Il Confirmer discrimina le precondizioni.** YesWiki chiude la pista auto-update
   perché il controllo del repository upstream/configurazione non è disponibile
   all'attaccante HTTP. Gitea chiude la pista package/Actions token ricostruendo
   identità, AccessMode e gate di scrittura. Sono disposizioni motivate dal sorgente,
   non conferme ottenute da questa analisi.
4. **YesWiki arriva al Worker.** La pista di bypass ACL in EntryManager raggiunge
   27 richieste Worker e 11 Judge, con tre invocazioni di tool HTTP nel transcript.
   Nelle prime global del 12 settembre Worker e Judge non partivano.
5. **Compare un'altra ipotesi A01.** Alle 00:20 `a01-lead-2`, relativa al restore di
   revisioni con autorizzazione sul tag corrente e scrittura sul tag della revisione,
   è stata serializzata ed è in refining. È un segnale di diversità della ricerca,
   non una vulnerabilità confermata o dimostrata nuova rispetto a tutta la storia.

Non è un A/B: cambiano modelli, funding, ordine e durata; molte modifiche sono nel
working tree e manca una revisione immutabile dell'intero runtime storico. Le global
Gitea precedenti disponibili hanno zero token contabilizzati, quindi non costituiscono
una baseline investigativa. La global GLM YesWiki da 16 ore ha due conferme, ma non è
corretto confrontarne direttamente il totale finale con questa run ancora attiva.

## Problema 1 — YesWiki: una pista riconosciuta scompare al checkpoint

Nel transcript `yeswiki-global-20260914-214544-logs.php`:

- righe 2491–2501: il Reader legge `actions/EraseSpamedCommentsAction.php`, riconosce
  assenza di controllo admin e cancellazione di pagine indicate dal client;
- righe 2515–2533: formula la pista e identifica la domanda residua corretta,
  cioè l'eventuale enforcement centrale delle ACL delle azioni;
- righe 2537–2551: interviene il Reviewer, il contesto passa da circa 38.583 a
  4.828 token e la direttiva successiva torna a una verifica generale delle azioni;
- righe 2642–2652: dopo riletture, il Reviewer chiude l'area su evidenza negativa,
  pur riconoscendo nel ragionamento che la verifica discriminante non è conclusa.

Il checkpoint finale include EraseSpamedComments fra le superfici verificate, con
nessuna lead e nessun ramo aperto. Il manifest A01 associa proprio quel file al caso
`CVE-2026-52766`; quattro osservazioni Reader intersecano l'anchor del manifest.

La verifica statica aggiuntiva chiarisce che `YesWikiAction::checkSecuredACL` è un
helper protetto: la sua esistenza non prova l'invocazione in ogni azione.
`Performer::run` applica `CheckModuleACL`, che va distinto dal controllo admin
dell'helper e risolto con la semantica/configurazione effettiva. Questa analisi non
riesegue la cancellazione e non attribuisce una conferma dinamica.

**Conclusione:** il limite dimostrato è la conservazione e risoluzione dell'ipotesi,
non la mancata lettura del file. Più file letti o più aree closed possono mascherare
un falso negativo. L'assenza di una ReaderLead serializzata non è evidenza negativa.

## Problema 2 — Gitea: enumerare i gate non basta a verificare il confine

Il Reader interseca quattro volte gli anchor in `routers/api/v1/api.go` sia della
disclosure delle label di organizzazioni private sia del merge upstream da fork.
Non risultano osservati i rispettivi handler/service decisivi:

- `routers/api/v1/org/label.go`;
- `services/repository/merge_upstream.go`.

Il caso più chiaro è alle righe 984–994 del transcript Gitea: il Reader riporta il
GET delle label senza guardia per-route, vede guardie di ownership su POST/PATCH/DELETE,
lo considera accettabile e prosegue. Non approfondisce chi può leggere le label di
un'organizzazione privata. L'area delle route API viene poi chiusa senza lead.

L'assenza di guardia per-route non dimostra da sola un bug, perché può esistere un
gate nel gruppo o nell'handler. È proprio quella verifica concreta a mancare.
Il manifest A01 cataloga il caso come `CVE-2026-25038`.

Gli altri tre casi A01 hanno i componenti decisivi non raggiunti dal Reader nella
fotografia; la route SSRF riceve soltanto un'osservazione Recon. Anche il caso A02
dei trusted proxy ha una lettura Recon della configurazione container, senza
approfondimento Reader del controllo rilevante.

**Conclusione:** servono priorità basate su attore, risorsa e confine di sicurezza.
Una ricerca organizzata per superficie può aiutare, ma solo se accompagna il passaggio
route → handler → oggetto/permesso. Cambiare il nome dell'unità di lavoro non basta.

## Problema 3 — Il budget discovery resta sbilanciato

Nella fotografia Gitea delle 00:17:

- A01: 174 richieste complessive, 133 Reader, quattro aree chiuse;
- A02/A07/A03/A04/A05: rispettivamente 6/5/10/14/11 richieste Reader;
- tutte e cinque terminano per budget, senza chiudere aree nel ledger;
- il coordinatore riporta circa 1,428M punti usati su 4,45M, con circa 3,022M residui.

Il transcript dichiara 1,75M punti discovery e una riserva di prima osservazione da
91k per categoria. Il codice corrente in `multi_category.py:335` espone alla discovery
il pool globale meno le sole prime osservazioni future; il fair-share del budget totale
è applicato separatamente. In `budget.py:601`, il lavoro senza lead viene limitato
anche dal residuo di questo pool discovery.

Il pattern osservato è coerente: A01 può consumare quasi tutta la discovery libera,
e le categorie seguenti ricevono soprattutto la riserva da 91k appena sbloccata.
Recon e supervisione ne consumano una quota prima che il Reader approfondisca.
Il floor sul budget totale non garantisce quindi da solo una discovery adeguata.

**Conclusione:** verificare e correggere l'allocazione del pool discovery prima di
aumentare il cap USD globale. La run conserva fondi complessivi ma interrompe la ricerca.

## Problema 4 — La verifica dinamica non raggiunge ancora l'esperimento decisivo

La pista YesWiki `a01-lead-1` resta `judge_stopped`. Il Judge documenta che i login
sono stati tentati sulla pagina invece che sull'handler di autenticazione corretto;
non è stato eseguito il POST all'endpoint della vulnerabilità. Chiede un nuovo test,
ma il retry non viene ammesso.

Le tre invocazioni HTTP sono quindi progresso operativo, non tre prove dell'exploit.
`lead_usage` attribuisce alla lead circa 57 minuti comprendendo discovery e review:
non sono 57 minuti del solo Worker. I riferimenti HTTP nell'aggregato richiedono
riconciliazione e non sono stati usati come conteggio di transazioni uniche.

Il P0 utile è un handoff con autenticazione e baseline effettivamente utilizzabili,
seguito dalla verifica del motivo di negazione del retry. Aumentare indiscriminatamente
il budget Worker non è una correzione dimostrata dalla sola traccia.

## Effetto osservabile degli strumenti recenti

- Notebook: YesWiki contiene una nota Confirmer; Gitea nessuna. Non c'è evidenza
  sufficiente che migliorino il riuso fra episodi. Zero letture esplicite non esclude
  la visibilità tramite briefing automatico.
- `trace_data_flow` e `trace_code_path`: nessuna chiamata nelle due fotografie.
  Non è possibile attribuire loro guadagni di coverage.
- Semgrep: il census dichiara `local-fallback` per checksum mismatch; YesWiki ha
  otto segnali di una sola famiglia, Gitea zero segnali e due errori del sensore.
  Il Reader consulta `list_surface_signals` due volte in YesWiki, una in Gitea.
- Tool calling: nel transcript YesWiki compaiono 31 messaggi Reader di
  `validation-retry`, diversi con argomenti mancanti. Sono messaggi, non 31 incidenti
  indipendenti. Gitea ha ancora un esaurimento Reviewer con cap output 5.000.

## Priorità proposte, non implementate

### P0

1. Conservare nel checkpoint narrativo la pista concreta non ancora serializzata,
   la domanda residua e i riferimenti. Il Reviewer deve distinguere area sospesa da
   area risolta negativamente; evitare che la pressione a non ripetere porti a chiudere
   come sicuro ciò che è rimasto irrisolto. Riusare i contratti esistenti dove possibile.
2. Verificare la discovery realmente spendibile per le categorie successive e
   correggerne l'allocazione. Il floor totale non basta nel modello a pool separati.
3. Rafforzare il test discriminante attore–risorsa–permesso sulle superfici già
   raggiunte, senza enumerare soltanto presenza di middleware. Partire dai due
   transcript congelati, con benchmark oracle mantenuto fuori dai prompt produttivi.
4. Per la conversione in conferme: risolvere l'handoff di autenticazione e diagnosticare
   il retry negato del caso EntryManager prima di aprire altre pipeline concorrenti.

### P1

- Confrontare una ricerca per superficie con quella per categoria dopo aver protetto
  la continuità delle piste; nessuna necessità dimostrata di riscrivere subito il loop.
- Valutare concorrenza e provider più veloce sul percorso già affidabile.
- Misurare i nuovi strumenti quando sono effettivamente usati e il sensore fornisce
  l'inventario previsto.

### Verifica successiva consigliata

Prima replay offline dei boundary Reader/Reviewer e delle allowance discovery; poi,
solo con autorizzazione a nuove chiamate API, confronto controllato di poche aree sugli
stessi snapshot e modelli. Misurare ipotesi mantenute dopo il checkpoint, casi raggiunti
che diventano lead, disposizioni corrette e completamento delle verifiche, non solo
token/file/aree closed. Le due global correnti vanno rivalutate al termine, senza
trattare questa fotografia come risultato finale.
