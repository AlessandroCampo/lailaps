Lailaps è un progetto per valutare la sicurezza dei progetti web, 
auditati con il consenso degli utenti in ambiente protetto. Fornisce un AUDIT di vulnerabilità attraverso un approccio ibrido gray box.

In ambiente di produzione, un cliente caricherà come zip temporaneo il codice del progetto, che verrà letto localmente 
dall'agente. In aggiunta, è necessario che il progetto abbia un ambiente correttamente e setuppato e raggiungibile
per confermare le sospette vulnerabilità trovate sul codice con effettive chiamate HTTP.
Alor

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
