# Handoff P0.1 — Associazione esplicita tra esperimenti e prove dinamiche

## Obiettivo e contesto

Implementare il collegamento esplicito delle transazioni HTTP allo specifico esperimento e claim che devono sostenere. Eliminare la selezione per sola recenza delle prove terminali per le nuove run. Questo handoff è autonomo: non richiede P0.2 sugli estratti probatori né P0.3 sul benchmark Worker.

La proposta deriva dalla lettura di ARCHITECTURE.md, senza ispezione dell'implementazione. Prima di modificare leggere istruzioni applicabili, architettura corrente e codice pertinente. Verificare l'effettivo comportamento del gate, riutilizzando recipe, ledger, sessioni actor e identificatori già disponibili.

Il documento descrive una selezione degli evidence ID dall'ordine del ledger: ultima coppia control/test valida o ultima transazione pertinente. Nella stessa lead possono convivere autenticazione, route alternative, payload diversi e prove con precondizioni differenti. L'esistenza delle transazioni nello stesso scope non garantisce che costituiscano il confronto giusto.

Esempio: Alice legge un documento privato proprio; Bob legge lo stesso documento. Una successiva richiesta di login o lettura di un documento pubblico non deve sostituire una delle due prove nel gate solo perché più recente.

## Responsabilità e contratto minimo

- Il Worker decide l'esperimento e racconta claim, variazione introdotta, condizioni da mantenere confrontabili e osservazione discriminante.
- L'orchestratore assegna identità e collega transazioni, actor e response dallo stato autorevole.
- Il Dynamic Judge valuta se il confronto sostiene il claim.
- Il gate controlla scope, esistenza, associazioni e requisiti strutturali dell'oracle; non decide causalità o vulnerabilità tramite euristiche di uguaglianza.

Prima di aggiungere campi model-facing, verificare esplicitamente se bastano la narrativa del piano, i riferimenti correnti e una sola operazione leggera per dichiarare il contesto sperimentale. Preferire una descrizione libera dell'esperimento e handle brevi. Non imporre una Recipe Card annidata, un DTO del ledger o un numero fisso di passaggi.

## P0 da implementare

1. Aggiungere un record interno minimale di esperimento scoped alla lead, con identità assegnata dall'orchestratore e descrizione del Worker. Il meccanismo può essere un tool leggero o un'estensione delle primitive esistenti: scegliere dopo la verifica del codice, mantenendo piccolo il contratto.
2. Collegare le chiamate HTTP all'esperimento dichiarato prima della loro esecuzione. Identificare la funzione delle richieste quando serve distinguere controllo, test e setup/osservazione. Una chiamata accessoria non diventa una prova decisiva soltanto perché appartiene all'esperimento.
3. Consentire esperimenti con più transazioni, ripetizioni temporali, workflow con più passaggi e prove di impatto diretto senza coppia obbligatoria. Non assumere che tutti gli oracle siano confronti di due singole risposte.
4. Fare riferimento esplicito, nella proposta Worker e nel dossier Judge, all'esperimento e alle transazioni che sostengono il claim. Se esistono più controlli o test, selezionare esplicitamente quelli usati dal verdetto invece di prendere gli ultimi.
5. Il gate risolve i riferimenti contro il ledger autorevole e verifica appartenenza alla run/lead/esperimento, disponibilità delle response, distinzione della baseline quando richiesta e requisiti già previsti per actor e oracle temporali. Differenze di route, risorsa o stato restano visibili al Judge: non inventare una regola generale che vieti ogni differenza, perché può essere parte del test.
6. Un collegamento ambiguo o mancante non autorizza una conferma. Applicare i normali percorsi di correzione/retry o conservare suspected se non recuperabile, senza indovinare la coppia dalla recenza e senza produrre una rejection tecnica.
7. Persistenza e checkpoint conservano esperimenti e associazioni nell'outcome/ledger esistente. Compression, cambio epoch, enrichment e retry non perdono le associazioni e non assegnano prove vecchie al nuovo esperimento per effetto dello stato attivo.
8. Conservare lo storico degli esperimenti quando il Worker cambia strategia. Una modifica materiale del claim o delle precondizioni crea un nuovo esperimento; non riscrive retroattivamente il significato delle richieste eseguite.

Una baseline può essere riutilizzata solo attraverso un'associazione esplicita e motivata nello stesso scope autorizzato. Non duplicare richieste per soddisfare lo schema e non assumere automaticamente che una baseline rimanga valida dopo mutazioni di stato.

## Compatibilità e limiti

- Rendere leggibili i report storici privi di associazioni; non fabbricare retroattivamente esperimenti o nuove attestazioni.
- Distinguere tramite la versione del gate la verifica precedente e quella nuova. La lettura di un verdetto storico non equivale a rivalidarlo col nuovo protocollo.
- Per nuove run che caricano candidate storici, il Worker può dichiarare esperimenti a runtime senza richiedere una nuova serializzazione del candidate.
- Non imporre P0.2: usare gli excerpt e gli strumenti correnti, rendendo esplicite le evidenze mancanti.
- Nessun nuovo agente, scheduling parallelo, reset automatico della sandbox o protocollo statistico completo in questo P0.
- Aggiornare ARCHITECTURE.md per documentare ownership, associazione delle prove, comportamento del gate e compatibilità.
- Non eseguire lint sull'intero progetto.

## Verifica e criteri di accettazione

Test mirati ai rischi reali del collegamento:

- Due esperimenti della stessa lead hanno richieste intercalate: il gate usa solo la selezione esplicita del verdetto.
- Una richiesta accessoria successiva non sostituisce control o test.
- Riferimenti a esperimenti/response di un'altra lead o run sono respinti.
- Una baseline assente in un oracle differenziale non viene recuperata dalla semplice recenza; l'esito non diventa confirmed.
- Un oracle di impatto diretto non richiede artificialmente una coppia e un oracle temporale può riferire più campioni.
- Compression, retry tecnico e enrichment preservano associazioni e storico senza ripetere automaticamente le HTTP.
- Cambio di strategia non riscrive le prove precedenti; riuso esplicito di una baseline conserva la provenienza originale.
- I report storici rimangono leggibili, con gate version distinto e senza nuove attestazioni inventate.

Consegnare implementazione, test pertinenti e architettura aggiornata. Mostrare un esempio concreto del dossier Judge prima/dopo con due esperimenti nella stessa lead. La validità strutturale delle associazioni non deve essere presentata come prova automatica di causalità.
