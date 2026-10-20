# Prospettive investigative comuni

Adattamento di `ATTACK-CLASSES.md` e della metodologia di `HUNTING.md` Cloudflare. Applicare solo le prospettive pertinenti al codice osservato. Queste domande non cambiano la soglia del ruolo: al Reader basta una lead concreta e plausibile; l'Investigator verifica le condizioni e l'effetto.

## Input, trasformazioni e contesto

Segui valori, chiavi, nomi di campo, header e metadati verso query, comandi, template, percorsi, redirect, deserializzazione e interpreti. Una protezione adatta al punto d'ingresso può non essere valida al sink. Confronta escaping, encoding, canonicalizzazione, conversioni di tipo e troncamenti.

Cerca usi indiretti: dato salvato correttamente, poi recuperato come espressione, URL, path, frammento HTML o criterio di autorizzazione. Controlla chi può scrivere la copia considerata «fidata» dal lettore successivo. Osserva errori e fallback che saltano una trasformazione.

## Autorità e isolamento

Verifica identità, permesso, risorsa e momento del controllo. Confronta UI e API, operazione singola e bulk, lettura e export, preview e versione pubblica, percorso nuovo e legacy.

Una selezione tenant/owner fornita dal chiamante può sovrascrivere il binding calcolato dal server? Ogni elemento di una lista è verificato? Un oggetto correlato viene caricato con un filtro più debole? Una cache o un indice contiene dati con scope diverso da quello della richiesta?

Non scambiare autenticazione per autorizzazione, né una funzionalità intenzionalmente amministrativa per escalation senza un attore meno privilegiato che possa raggiungerla.

## File, risorse e fetch

Per letture, scritture, estrazioni e upload confronta path validato e path usato: link, normalizzazione, estensione, destinazione temporanea e race check/use. Per fetch remoti segui URL iniziale, redirect, resolver, protocolli ammessi e destinazione effettiva.

Per deserializzazione o parser chiarisci che cosa controlla il chiamante e quale operazione viene attivata. Una funzione pericolosa non implica da sola raggiungibilità. Ogni osservazione dinamica deve restare nei target e nelle primitive autorizzate.

## Stato e regole applicative

Ricostruisci le transizioni consentite: approvazione, pagamento, invito, importazione, ripristino, cancellazione o revoca. Cerca step saltati, replay, rollback incompleto e percorsi che conservano autorità ormai scaduta.

Confronta controlli e aggiornamenti in condizioni concorrenti solo quando il flusso lo rende plausibile. Valori zero, negativi, limite, duplicati o convertiti sono interessanti quando attraversano una regola reale; non provare combinazioni meccanicamente.

## Segreti e crittografia

Individua chi può osservare un segreto e quale capacità ne deriverebbe. Distingui identificatori pubblici da credenziali. Esamina generazione, uso, memorizzazione, verifica, rotazione e revoca di token e chiavi.

Confronta errore e percorso normale: verifica omessa, fallback permissivo, nonce riusato, valore firmato diverso da quello consumato. Un algoritmo o flag sconsigliato senza un effetto concreto può essere una nota di hardening, non una vulnerabilità.

## Composizione e punti trascurati

Segui le garanzie fra componenti: A controlla una proprietà, B assume qualcosa di più forte? Export, backup, ricerca, ordinamento, notifiche e ripristino possono attraversare confini diversi dalle normali operazioni CRUD.

Esamina setup/debug, override, configurazioni mancanti, errori di dipendenza e funzionalità poco rappresentate. Confronta ciò che l'interfaccia mostra con ciò che il backend accetta. Un commento «safe» è un'affermazione da verificare.

Per una catena, ogni risultato intermedio è un prerequisito da distinguere da un'ipotesi. Il Reader può trasferire il passaggio ancora incerto; l'Investigator non può attribuire l'effetto finale a una catena non verificata. Non ampliare l'impatto oltre ciò che serve a dimostrare il confine.
