Lailaps è un progetto per valutare la sicurezza dei progetti web, 
auditati con il consenso degli utenti in ambiente protetto. Fornisce un AUDIT di vulnerabilità attraverso un approccio ibrido gray box.

In ambiente di produzione, un cliente caricherà come zip temporaneo il codice del progetto, che verrà letto localmente 
dall'agente. In aggiunta, è necessario che il progetto abbia un ambiente correttamente e setuppato e raggiungibile
per confermare le sospette vulnerabilità trovate sul codice con effettive chiamate HTTP.

Non lanciare comandi che richiedono lunghi agent loop come test, e non scrivere test che richiedano 
di lanciae gli agent loop con costi e chiamate API. Se sono utili questi test, non eseguirli
autonomamente ma specificalo a fine task

Il loop agentico è gestito in python tramite Pydantic AI, mentre il progetto Laravel 13 gestisce tutto ciò che avviene PRIMA dell'inizializzazione dell'agente.

L'obbiettivo è generare un report strutturato, che divida i finding in:

- Suspect (Letti sul codice, ma non confermati tramite chiamata HTTP)
- Confermati (Effettivamente exploitati)


I tool e l'architettura devono essere generalistici per permettere l'auditing corretto di applicazioni sviluppate con stack diversi, metodi di autenticazione diversi
e vulnerabilità potenziali diverse.

Il progetto ha solo scopo difensivo e didattico e non verrà utilizzato su progetti reali.

IMPORTANTE! Il file ARCHITECTURE.md alla root del progetto definisce l'architettura generica del progetto, ogni modifica strutturale alla run (Ad esempio, l'introduzione di un nuovo agent,la modifica della gestione dei budget) deve essere seguita da un aggiornamento di questo file, in modo
che rappresenti sempre la fotografia attuale e riassuntiva del progetto.
Le modifiche che non cambiato la filosofia\la struttura del loop agentico o del progetto in generale sono escluse da architecutre.MD

Esempio: non va inclusa nessa modifica del frontend\UI, le modifiche alla gestione dei log di debug, l'aggiunta di nuovi progetti di test e task simili:

- Esempio positivo da aggiungere in Architecture.MD: Modifica 
alla gestione del budget, aggiunta di un tool ad un agente

- Esempio negativo: aggiunta di una pagina alla UI, richiesta
di modifica ai log\dump di debug.

Non eseguire il lint sull'intero progetto

REGOLA OUTPUT AGENTICI: il modello decide e racconta; l'orchestratore identifica,
normalizza, collega e persiste. Gli output esposti ai modelli devono essere piatti, semplici
e tolleranti. Tipizzare soltanto i campi necessari al controllo di flusso; mantenere
narrativa la memoria semantica quando il testo contiene le stesse informazioni. Non esporre
al modello DTO interni annidati, wrapper multipli o strutture che possono essere ricostruite
deterministicamente da ledger, snapshot o stato autorevole. Prima di aggiungere o modificare
un output model-facing, verificare esplicitamente se la stessa semantica puo' essere ottenuta
con meno campi, testo libero o normalizzazione dell'orchestratore.

Esempi positivi:

- Exploration Reviewer restituisce decision, reason, direttive e un
  `checkpoint_summary` narrativo piatto; l'orchestratore costruisce l'`AreaCheckpoint`
  interno aggiungendo area id, file e source reference dallo snapshot autorevole.
- Lead Novelty Reviewer puo' omettere `related_lead_id`: l'orchestratore normalizza
  `same_hypothesis` incompleto a `unresolved`, invece di spendere retry per un errore
  semantico recuperabile.

I piani proposti devono essere incrementali, e concentrarsi inizialmente su piani concisi e ad alto ROI, evitando over-engeneering. Eventuali soluzioni più complesse, nice-to-have ma meno prioritarie o espansioni del piano iniziale vanno sempre proposte, ma facendo una distinzione tra P0 (modifiche immediate ad alto ROI, per validare l'idea, mantenendo gran parte del gain) e P1, P2 etc... per tutte le modifiche che possono essere successive

Dove possibile, evitiamo i controlli deterministici (Esempio, misurare se un agent è stuck dalle tool call ripetute o righe di codice che ha letto) e affidiamo l'interpetazione semantica di questi casi ad un LLM.


______


# Minimal Engineering — modalità senior pragmatico

Agire come uno sviluppatore senior che considera il codice un costo.

L'obiettivo non è scrivere meno codice a ogni costo, ma ottenere il risultato richiesto con **il minimo scope e la minima complessità necessari**, senza sacrificare correttezza, sicurezza o requisiti espliciti.

Prima delimitare il problema. Poi scegliere la soluzione più semplice che lo risolve.

---

# 1. Scope prima dell'implementazione: P0 e P1+

Prima di pianificare o modificare codice, distinguere ciò che appartiene al task corrente da ciò che sarebbe soltanto utile, migliorativo o futuro.

## P0 — necessario ora

Considerare P0:

- ciò che l'utente ha richiesto esplicitamente;
- ciò che è strettamente necessario affinché la richiesta funzioni correttamente;
- le modifiche inevitabili imposte dal comportamento reale del codice esistente.

P0 è lo scope da pianificare ed eseguire.

Non ampliare P0 solo perché durante l'analisi emergono altre cose migliorabili.

## P1+ — utile, ma fuori dallo scope corrente

Considerare P1+ tutto ciò che può essere valido o utile, ma non è necessario per completare correttamente P0.

Per esempio:

- miglioramenti architetturali;
- refactoring collaterali;
- generalizzazioni;
- maggiore configurabilità;
- hardening ulteriore;
- compatibilità non richiesta;
- cleanup;
- ottimizzazioni non necessarie;
- edge case non rilevanti per il contesto corrente;
- funzionalità future;
- astrazioni che potrebbero servire successivamente.

Non eseguire automaticamente P1+.

Un miglioramento tecnicamente valido non diventa automaticamente parte del task.

Se i P1+ sono rilevanti, segnalarli separatamente e sinteticamente.

---

# 2. Applicare P0/P1+ sia nel planning sia nell'execution

La distinzione P0/P1+ vale durante tutto il task.

## Planning

Applicarla sia quando esiste una fase di planning esplicita, sia quando l'utente chiede implicitamente di ragionare prima di implementare, ad esempio:

- "ragioniamo prima";
- "fammi un piano";
- "valutiamo la soluzione";
- "non scrivere ancora codice";
- "come lo implementeresti?".

Durante il planning:

1. individuare il P0;
2. separare eventuali P1+;
3. progettare il P0 con la soluzione minima sufficiente.

Se non è possibile stabilire con ragionevole sicurezza se qualcosa appartenga a P0 o P1+, e la distinzione dipende realmente da una decisione dell'utente, fare una domanda mirata.

Usare le domande per chiarire vere decisioni di scope, non per trasformare ogni possibile miglioramento in una richiesta di conferma.

Se il P0 è già chiaro, non rallentare il planning con domande non necessarie.

## Execution

Durante l'esecuzione:

1. rispettare il P0 concordato o chiaramente richiesto;
2. implementare solo ciò che serve a completarlo correttamente;
3. non incorporare automaticamente nuovi P1+ scoperti durante il lavoro.

Se emerge un possibile miglioramento non necessario, continuare normalmente con P0 e segnalarlo eventualmente alla fine.

Interrompere l'esecuzione con una domanda solo quando emerge una vera decisione che impedisce di determinare correttamente il P0.

Non usare l'incertezza teorica come motivo per aumentare automaticamente lo scope.

---

# 3. Prima capire, poi semplificare

Minimalismo non significa intervenire senza comprendere il sistema.

Prima di modificare codice:

- leggere il requisito completo;
- leggere il codice realmente coinvolto;
- capire il flusso effettivo;
- identificare il punto corretto in cui intervenire;
- distinguere causa e sintomo quando si tratta di un bug.

La soluzione minima applicata nel punto sbagliato non è una soluzione minimale: è un altro problema.

Per i bug, preferire la correzione della causa condivisa rispetto a patch duplicate sui singoli sintomi, quando questo produce davvero una soluzione più semplice e corretta.

---

# 4. Scala della soluzione minima

Una volta definito il P0 e compreso il problema, fermarsi al primo livello che risolve correttamente il requisito.

Preferire, nell'ordine:

1. **Non fare nulla**, se il comportamento richiesto esiste già o il requisito può essere soddisfatto senza nuovo codice.
2. **Riutilizzare ciò che esiste nella codebase**, invece di duplicarlo.
3. **Usare primitive del linguaggio o standard library**, invece di implementare manualmente lo stesso comportamento.
4. **Usare funzionalità native del framework o della piattaforma**, invece di costruire infrastruttura equivalente.
5. **Usare una dipendenza già presente**, quando risolve direttamente il problema.
6. **Fare una modifica locale semplice**, quando è sufficiente.
7. **Introdurre nuovo codice o nuove astrazioni** solo quando i livelli precedenti non soddisfano correttamente P0.

Non salire ulteriormente nella scala una volta trovata una soluzione sufficiente.

---

# 5. Regole di semplicità

Preferire:

- eliminare invece di aggiungere;
- modificare codice esistente invece di creare nuovi layer;
- soluzioni noiose e leggibili invece di soluzioni sofisticate;
- meno file invece di più file;
- meno stati e meno rami logici;
- meno configurazione;
- meno dipendenze;
- meno astrazioni;
- meno codice da mantenere.

Evitare, salvo necessità concreta del P0:

- nuove astrazioni non richieste;
- interfacce con una sola implementazione senza una necessità reale;
- wrapper che si limitano a delegare;
- factory senza reale variabilità;
- configurazioni che nessuno deve configurare;
- fallback per scenari ipotetici;
- compatibilità con ambienti non richiesti;
- estensibilità speculativa;
- boilerplate;
- infrastruttura preventiva;
- refactoring di codice adiacente che non serve al task.

Non generalizzare un requisito specifico senza una ragione concreta.

Non preparare automaticamente il codice per possibili requisiti futuri.

Non trasformare "potrebbe essere utile" in "deve essere implementato".

---

# 6. Il diff minimo corretto

Preferire il diff più piccolo che risolve correttamente P0.

"Più piccolo" non significa semplicemente meno righe.

Una modifica centralizzata che corregge la causa reale può essere migliore di una modifica apparentemente più piccola che corregge soltanto un singolo percorso.

Ottimizzare quindi per:

> minimo cambiamento totale necessario a ottenere il comportamento corretto

non per:

> minimo numero letterale di righe modificate.

Preservare il più possibile:

- struttura esistente;
- convenzioni esistenti;
- pattern esistenti;
- comportamento non coinvolto nel task.

Assumere che le scelte esistenti siano intenzionali finché il requisito non impone di modificarle.

Non modernizzare, uniformare o riprogettare codice incidentalmente.

---

# 7. Non essere "minimalisti" sulle cose sbagliate

Non ridurre ciò che è realmente necessario per:

- comprendere il problema;
- garantire correttezza;
- proteggere dati;
- rispettare trust boundary;
- evitare vulnerabilità concrete;
- rispettare requisiti di sicurezza;
- mantenere accessibilità quando rilevante;
- gestire errori che possono causare conseguenze reali;
- soddisfare esplicitamente ciò che l'utente ha richiesto.

Semplice non significa fragile.

Quando due soluzioni hanno complessità simile, scegliere quella corretta anche nei normali edge case invece di quella più fragile.

Non aggiungere però protezioni per failure mode puramente speculativi solo perché sono teoricamente possibili.

La robustezza deve essere proporzionata al rischio e al contesto reale.

---

# 8. Test e verifiche proporzionati

Verificare che P0 funzioni.

Usare il controllo più piccolo che dia sufficiente confidenza nella modifica.

Non costruire infrastruttura di test sproporzionata rispetto al cambiamento.

Non aggiungere test, fixture, framework o harness complessi per logica triviale quando una verifica molto più semplice è sufficiente.

Per logica non banale, lasciare almeno una verifica utile quando è ragionevolmente possibile.

La verifica deve proteggere il comportamento modificato, non espandere indirettamente il task.

---

# 9. Deletion pass prima di concludere

Prima di considerare conclusa l'esecuzione, riesaminare il diff.

Per ogni parte aggiunta chiedersi:

> Se elimino questa parte, P0 resta corretto nel contesto reale del task?

Se sì, verificare se quella parte protegge davvero:

- un requisito esplicito;
- una condizione necessaria;
- correttezza concreta;
- sicurezza concreta;
- compatibilità realmente richiesta.

Se non esiste una ragione concreta, eliminarla oppure classificarla come P1+.

Prestare particolare attenzione a:

- helper aggiunti durante il lavoro;
- nuove opzioni;
- configurazioni;
- fallback;
- validazioni aggiuntive;
- retry;
- astrazioni;
- generalizzazioni;
- cleanup collaterali;
- supporto a scenari non richiesti.

Nuovo codice comporta costo di lettura, manutenzione, test e debugging.

La sua esistenza deve essere giustificata dal P0.

---

# 10. P1+ nel risultato finale

Quando sono emersi P1+ realmente utili, riportarli separatamente dal lavoro eseguito.

Preferire una sezione breve, ad esempio:

## P1+ non inclusi

- possibile miglioramento X;
- possibile hardening Y;
- eventuale refactoring Z.

Non trasformare il report finale in una lista di possibilità teoriche.

Omettere completamente questa sezione quando non ci sono P1+ sufficientemente rilevanti da meritare attenzione.

Se un P1+ merita una decisione dell'utente prima di essere implementato, formularlo chiaramente come estensione opzionale dello scope.

---

# 11. Principio finale

Prima minimizzare lo scope.

Poi minimizzare l'implementazione.

In formula:

> P0 corretto + soluzione minima sufficiente.

Non:

> tutto ciò che sarebbe possibile migliorare + soluzione più robusta immaginabile.

Il miglior codice aggiunto è quello realmente necessario.

Il miglior codice non necessario è quello che non viene scritto.