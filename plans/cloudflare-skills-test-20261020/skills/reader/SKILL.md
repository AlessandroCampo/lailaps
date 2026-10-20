---
name: reader
description: Arricchisce la discovery statica del Reader Lailaps con prospettive investigative Cloudflare mantenendo lead soft, incarichi attraversabili e self-checkpoint. Non conferma exploit.
---

# Reader: cercare differenze che meritano un'indagine

Questa knowledge integra il contratto del Reader Lailaps. Usa [le prospettive comuni](../references/attack-perspectives.md) e [i casi web/browser](../references/web-and-browser.md) quando pertinenti al comportamento letto; non sono checklist da esaurire.

## Parti da un'invariante concreta

Usa briefing e incarico come orientamento. Riconosci chi controlla un input o un'azione, quale risorsa può essere coinvolta e quale controllo dovrebbe mantenere il confine. Verifica nel codice le assunzioni decisive del briefing.

Alterna tracing in avanti e all'indietro: ingresso → trasformazione → controllo → effetto, oppure operazione sensibile → caller → origine del valore. Confronta il percorso con operazioni sorelle e con la garanzia richiesta dal componente successivo. Un controllo presente può verificare la risorsa o l'identità sbagliata.

Scegli la prossima lettura per ridurre una specifica incertezza. Sono utili:

- errori e fallback che evitano un controllo del percorso normale;
- stato mancante, vuoto, duplicato, scaduto o revocato, se ammesso dall'interfaccia;
- differenze fra parser, normalizzazione, unità, chiavi e contesti di rendering;
- ordine check/use, retry, concorrenza e transizioni incomplete;
- dati archiviati in un contesto e riutilizzati con più autorità in un altro;
- configurazioni, override, setup e percorsi alternativi che cambiano il trust boundary.

Segui helper e dipendenze fuori dai locator iniziali quando serve. Non considerare sicuro un componente perché un singolo ramo è protetto, una ricerca è vuota o Semgrep non lo segnala.

## Emetti presto una lead utile

Emetti `ReaderLead` appena hai osservato un'operazione o controllo rilevante, una plausibile influenza dell'attore o violazione di confine e un collegamento concreto da chiarire. Descrivi osservazione, ipotesi, controllo già visibile e domanda discriminante negli output esistenti.

Non aspettare la trace completa, il comportamento esatto del framework o una riproduzione HTTP. Se una barriera è già chiaramente efficace nel percorso letto, puoi risolvere quella domanda; se l'efficacia è incerta, trasferisci l'incertezza all'Investigator. Un header assente o un nome sospetto da soli non costituiscono una lead utile.

I locator descrivono il codice letto. L'orchestratore collega source reference e identità. Non produrre fingerprint Cloudflare, severity obbligatorie, `confirmed`, `needs_validation` o report autonomi.

## Conserva ampiezza e memoria

Una lead conclude il turno di emissione, non l'incarico. Alla ripresa cerca operazioni o percorsi indipendenti senza riemettere la stessa domanda. Segnala una lead promettente anche fuori dal focus iniziale, entro lo scope autorizzato.

Se trovi lavoro non rappresentato che va rinviato, emetti `AreaEnrichmentLead` con osservazione, locator e prossima verifica; non seppellirlo soltanto in una nota di checkpoint. È una proposta al coordinamento esistente, non un nuovo incarico autoassegnato.

Usa `ReaderCheckpoint` per conservare controlli osservati, percorsi residui e ragione semantica per continuare o chiudere. Non chiudere sulla base del conteggio delle letture. Al boundary rispetta l'assenza di tool e i prodotti ammessi dal runtime.

Nessuna richiesta HTTP o conferma dinamica nel ruolo Reader. Eventuali osservazioni dei tool runtime disponibili orientano la lettura e non confermano una vulnerabilità.
