# P0: metodo Reader e confronto cross-model mirato

Priorità aggiornata il 25 settembre: il primo test è T1 (bugfix e tracing insieme)
su API, Bazar e rendering, con MiMo, come definito nell'[handoff corrente](P0-reader-adaptive-exploration-20260925.md).
La matrice cross-model qui sotto resta un esperimento successivo e non deve essere
avviata come primo screening dall'agente implementatore.

Piano del 23 settembre 2026. Nessuna implementazione o run a pagamento eseguita.
Aggiorna la priorita' delle proposte nelle review precedenti: prevenzione degli
errori nel Reader prima di introdurre una review indipendente delle omissioni.

## Obiettivo e distinzione delle cause

La confusione fra checkpoint e lead acquisita e' un problema concreto del contratto
di interazione e dello stato. Interpretare male un controllo, trascurare un ramo o
inferire che admin significhi innocuo sono errori investigativi del modello. Prompt,
contesto e strumenti possono favorirli o ridurli, ma una review aggiuntiva non ne
dimostra la correzione alla fonte. Prima migliorare il metodo, poi misurare il residuo.

## P0 implementativo proposto

Precisazione del 24 settembre: READER_ASSIGNMENTS_PROMPT contiene gia' la soglia
di plausibilita' e delega esplicitamente la conferma al Confirmer. Il P0 deve rendere
coerente questo contratto anche nel checkpoint e verificarne il rispetto, non solo
ripetere la stessa istruzione. Una pista con nesso concreto ma analisi incompleta
va inoltrata con gli unknowns; una barriera positiva che interrompe quel preciso
percorso non va ignorata per aumentare il numero di lead. Anche la chiusura rispetto
all'intero incarico e ai residui e' metodo del Reader e appartiene al P0.

1. Rendere univoco il passaggio checkpoint -> turno operativo -> ReaderLead acquisita.
   Il summary non emette lead; lo stato di acquisizione deriva dal ledger. Nel
   checkpoint una pista pronta determina continue con direttiva di emissione,
   non un'altra ricerca obbligatoria ne' una falsa affermazione di acquisizione.
   Test offline mirato di questo passaggio, senza chiamate provider.
2. Rivedere in modo conciso il prompt assignments e quello checkpoint, eliminando
   istruzioni ambigue o contraddittorie prima di aggiungerne altre. Il prompt corrente
   chiede al checkpoint una prossima lettura statica e al Reader di emettere subito:
   l'emissione deve essere esplicitamente una prossima azione valida.
3. Introdurre un metodo narrativo breve per il Reader, senza nuovi output strutturati:
   - capire ingressi, attori, operazioni e rami indipendenti del comportamento;
   - quando legge per una domanda specifica, notare anche altri confini sensibili
     nello stesso blocco; seguirli subito o conservarli come residui;
   - valutare il controllo sul dato, percorso e contesto effettivo: nome del metodo,
     privilegio applicativo, commento o semplice presenza di un sanitizer non bastano;
   - prima di escludere, distinguere cio' che il controllo dimostra da cio' che lascia
     aperto; admin e write access sono precondizioni, non prove di innocuita';
   - emettere quando operazione, influenza e nesso plausibile sono osservati, con
     unknowns per il Confirmer; non inventare exploitability e non ignorare barriere
     direttamente osservate (ad esempio errore obbligatorio prima del sink);
   - chiudere rispetto all'incarico e ai residui, non solo all'ultima domanda risolta.

Non introdurre lettura esaustiva obbligatoria di tutti i file, quote per CWE, conteggi
di tool call o checklist lunghe da serializzare ad ogni turno. Nessun dettaglio delle
CVE YesWiki deve entrare nel prompt operativo. I casi noti servono solo alla valutazione.

## P0 di testing cross-model

Il test e' parte del P0, non un seguito opzionale. Eseguibile solo con avvio esplicito
dell'utente: questo documento non avvia audit.

Incarichi congelati dalla Golden Recon 01M309MYBKSARVPA2HWVTYT450:
- 3 autenticazione: acquisizione delle lead e interpretazione dello stato;
- 4 API: percorsi alternativi, proprieta' diverse nello stesso controller;
- 5 Bazar: mitigazioni complesse, memoria dei rami e breadth dell'incarico;
- 6 rendering: conclusioni sui privilegi, contesti di output e sink vicini.

Reader: deepseek/deepseek-v4-flash-0731, deepseek/deepseek-v4.1-flash,
xiaomi/mimo-v2.6-pro. Mantenere identico il Reviewer novelty.

Varianti:
- A: contratto checkpoint corretto + metodo Reader attuale;
- B: stesso contratto corretto + metodo Reader rivisto.

Applicare la correzione del contratto ad entrambe le varianti: confrontare una variante
con bug noto contro una senza confonderebbe il guadagno metodologico con lead recuperate.
Nessun Reviewer di omissioni aggiunto in A o B.

Esecuzione incrementale:
1. Screening su aree 3 e 6: tre modelli x due varianti = 12 assignment. Una sola
   repetition per cella serve a individuare problemi grossi, non a decretare un vincitore.
2. Se non emergono regressioni evidenti, estendere alle aree 4 e 5 (altre 12).
3. Ripetere le configurazioni promettenti almeno una seconda volta; aggiungere una
   terza quando la variabilita' cambia la decisione. Risultati incerti restano incerti.

Riutilizzare il percorso Python global_reader_assignment gia' esistente e le fixture
proiettate. Se serve comodita' CLI, un filtro di aree nel runner Laravel e' sufficiente;
non creare un secondo runner. Ogni prova riceve ID e stato nuovi, senza ereditare
checkpoint o lead delle altre prove.

Controlli: stesso sorgente, fixture, cap EP, strategia, strumenti e runtime; modello
Reader esplicito per ogni figlio; registrare provider, prompt/versione e tokenizer/usage.
Concorrenza identica e nessun confronto di velocita' fra run con contesa diversa.
Il cap EP confronta il valore economico: token e turni differiscono fra modelli e
vanno riportati. Un confronto a pari token sarebbe una domanda diversa, P1.
Assignment interrotti, failure e timeout sono censurati nel confronto qualitativo
e riportati separatamente, non trasformati in zero-recall conclusivo.

Valutazione:
- prima di attribuire i miss al modello, incrociare manifest, snapshot sorgente,
  lead, log integrali (incluse letture via workspace command) e telemetria;
- distinguere mancata lettura del sink, lettura parziale, conclusione errata,
  pista riconosciuta ma non acquisita, esaurimento budget ed errore tecnico;
- separare errori del matcher e oracle benchmark non dimostrati dai veri miss;
- CVE/root cause uniche corrispondenti per semantica, con correzione dei match spuri;
- operazioni raggiunte ma non inoltrate e motivo osservato, separato dai percorsi
  non letti o dalle ricerche interrotte;
- piste pronte rimaste solo nella narrativa;
- lead fuori benchmark plausibili, duplicate, contraddette o troppo deboli;
- EP e tempo, piu' numero di candidate deduplicate da affidare al Confirmer.

Non promuovere il metodo solo per piu' lead o piu' aree complete. Richiedere recupero
di piste utili senza un aumento sproporzionato di rumore/costo downstream e senza
perdita dei risultati gia' solidi. Verificare almeno su incarichi 4/5 non usati nello
screening che il miglioramento non dipenda solo dagli esempi di 3/6.

## P1 rinviato

- Reviewer indipendente alla chiusura, soltanto se dopo il confronto resta un gruppo
  di omissioni per cui una seconda prospettiva abbia un guadagno misurabile.
- Repetition complete Recon+Reader e passaggio con punto di partenza alternativo.
- Cambiamenti alla decomposizione della Recon: tenere la mappa ferma nel primo A/B.

Le review precedenti restano valide come diagnosi; questo piano sostituisce l'ordine
proposto degli esperimenti: contratto -> metodo/prompt cross-model -> eventuale review.
