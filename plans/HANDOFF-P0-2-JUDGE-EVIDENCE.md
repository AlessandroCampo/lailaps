# Handoff P0.2 — Evidenza decisiva disponibile al Dynamic Judge

## Obiettivo e autorizzazione

Implementare la conservazione e la proiezione al Dynamic Judge degli estratti probatori realmente osservati dai tool del Worker. Questo handoff è autonomo: non richiede il benchmark dinamico P0.3 né l'introduzione degli esperimenti P0.1.

La proposta nasce dalla sola lettura di ARCHITECTURE.md, non da una verifica del codice. Prima di modificare, leggere le istruzioni applicabili, l'architettura corrente e l'implementazione pertinente. Verificare il divario effettivo e riusare le capacità già presenti; non duplicarle.

## Problema da verificare

Il Worker può interrogare il body completo delle risposte con inspect_response, search_response, read_response, find_json_records e query_html_response. Il Judge è tool-free e riceve proiezioni bounded. L'evidence_ledger persiste excerpt HTTP, ma il documento non garantisce che includano le porzioni decisive recuperate dopo la risposta iniziale.

Esempio: un record non autorizzato si trova oltre la preview iniziale. Il Worker lo trova con un tool di interrogazione. Il Judge deve ricevere quell'estratto con provenienza verificabile, senza dipendere dalla parafrasi del Worker.

## P0 da implementare

1. Individuare il percorso response store → tool di ispezione → ledger → snapshot Judge, inclusi fitting del prompt e redazione.
2. Registrare gli estratti pertinenti restituiti dai tool come osservazioni derivate della response originale. Riutilizzare gli identificatori e lo scope autorevoli: run, lead e response. L'orchestratore assegna identità e provenienza, senza richiederne la riserializzazione al modello.
3. Conservare il contenuto osservato in forma bounded e redatta insieme al locator usato per ottenerlo, tipo di estrazione, troncamento ed eventuali trasformazioni. Distinguere un estratto testuale da una proiezione JSON/DOM: quest'ultima non è una copia byte-per-byte del body.
4. Consentire di collegare la motivazione del Worker alle osservazioni già registrate soltanto se il contratto esistente non lo permette. Preferire un riferimento semplice e tollerante. Non richiedere DTO annidati, ricopie degli estratti o nuovi campi ricostruibili dal ledger.
5. Includere nel dossier del Judge le osservazioni citate e rilevanti della lead attiva, rispettando il budget del prompt. Rendere esplicite omissioni, redazioni e troncamenti; una prova non disponibile non deve essere presentata come completa. Evitare di riempire il dossier con ogni ispezione ripetuta.
6. Persistere le osservazioni nel report/evidence_ledger esistente e conservarle durante checkpoint, finalizzazione e recovery. Usare il percorso atomico già previsto per le prove, senza nuovi file di run né persistenza generalizzata dei body.

Il Judge resta l'autorità semantica; l'orchestratore verifica provenienza, scope e integrità dei collegamenti. Un estratto valido non implica una vulnerabilità valida. Questo intervento non modifica le regole di scelta delle coppie control/test.

## Vincoli

- Non salvare credenziali, cookie o token che il sistema attuale deve redigere. La redazione deve preservare, dove possibile, le relazioni necessarie alla prova senza esporre i segreti.
- Non effettuare nuove richieste HTTP per costruire gli estratti: usare response già osservate.
- Un risultato vuoto su contenuto parziale non dimostra l'assenza del comportamento.
- Definire deduplica e limiti usando le primitive esistenti. Non introdurre un archivio parallelo o un nuovo agente.
- Mantenere leggibili gli outcome precedenti: assenza di estratti derivati significa evidenza aggiuntiva non disponibile.
- Aggiornare ARCHITECTURE.md per rappresentare il nuovo percorso delle prove.
- Non eseguire lint sull'intero progetto.

## Verifica e criteri di accettazione

Usare test mirati sui percorsi modificati:

- Il contenuto decisivo oltre la preview, recuperato via tool, raggiunge il Judge e resta nell'outcome dopo finalizzazione.
- Provenienza e scope impediscono di usare estratti di altre lead/run o response inesistenti.
- Compression e fitting non lasciano riferimenti apparentemente completi a prove omesse senza segnalarlo.
- Troncamento, redazione e proiezioni DOM/JSON restano espliciti.
- Un estratto che dimostra soltanto riflessione dell'input non viene trasformato deterministicamente in prova di esecuzione.
- Il caricamento di report precedenti e le altre transazioni del ledger non regrediscono.

Consegnare implementazione, test pertinenti, aggiornamento dell'architettura e un riepilogo dei limiti residui. Non dichiarare un miglioramento semantico misurato sulla sola base dei test di integrazione.

## Sviluppi successivi, fuori P0

Selezione più sofisticata delle prove, replay completo, nuovi canali di osservazione e collegamento a esperimenti espliciti. Non sono prerequisiti per questa modifica.
