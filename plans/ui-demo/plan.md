# Lailaps — wireframe della demo, revisione 2

18 ottobre 2026. Specifica UX aggiornata dopo la review. [Piano di implementazione](implementation-plan.md), [wireframe](index.html#project).

## Direzione

Progetto → preparazione → scan → finding con prove → report. Conservare Geist, shadcn-vue/Reka, header orizzontale, superfici neutre e token attuali. Inglese nel prodotto, niente sottotitoli generici. Nessuna sidebar o dashboard aggregata nuova.

Source e Environment escono dalla navigazione primaria. L'overview contiene stato del progetto, prossimo passo o ultima scan, storico. I dettagli investigativi appartengono alla singola scan.

## Viste P0

| Vista | Contenuto |
| --- | --- |
| Projects | Card esistenti, nome/data. Stato progetto solo quando esposto dal backend; nessun aggregato inventato tra scan |
| New project | Nome e ZIP; riuso del form attuale, errori e avanzamento upload |
| Project | Stato, metadati minimi della sorgente, Upload new version. Grande empty state senza run; altrimenti riepilogo e storico |
| Scan | Pagina permanente durante/dopo l'esecuzione. Activity, Findings, Report; live dopo l'incremento backend |
| Finding | Description, Investigation conclusion, reason distinto, Evidence e Remediation quando presenti |
| Report | Risultati persistiti, sorgente, copertura/limiti, tempi/usage; Markdown/JSON e stampa |

### Prima scan e preparazione

Un pannello ampio, senza metriche o tabella vuote:

- Ready: **Run your first scan** + Start scan.
- Preparing: **Preparing project** + stato di preparazione.
- Not ready: **Project setup failed**, causa comprensibile e Retry setup.
- Source required: invito a caricare il codice.

Not ready non sostituisce l'errore: deve essere chiara l'azione possibile. Container, Lab, manifest, chiavi e percorsi host non entrano nella UI ordinaria. Un problema sulla nuova sorgente non invalida i report precedenti.

Upload new version apre un dialog con SourceUpload. Il successo dell'upload non implica preparazione completata. Tipo/data bastano: il nome originale dello ZIP non è attualmente persistito.

### Overview con run

Box compatto: Current scan / Latest scan, stato, tempo, attività disponibile, ultimo aggiornamento, budget consumato se disponibile, conteggi acquisiti e **View scan**. Storico sotto; feed completo e tabella lead rimangono nel dettaglio.

Box e dettaglio usano la stessa proiezione. Counts=null non diventa zero. La barra misura consumo budget, non completamento o copertura. Reconnecting conserva l'ultimo dato valido. Il progetto può restare Ready quando una scan è Failed: sono due assi distinti.

### Scan e risultati

Activity mostra eventi pubblicabili. Ipotesi Investigating separate dai finding, senza gonfiare conteggi. Findings: titolo, severity, verdict, evidenza disponibile; ricerca e filtri. Aggiornare righe per identità, mantenendo focus/filtri e senza riordino continuo.

Stati tecnici attuali: pending, running, completed, partial, failed. Report disponibile e successo del processo restano separati. Non fingere Stopped/Timed out già presenti nel contratto.

Verdetti: Confirmed, Suspected, Blocked, Inconclusive. Pending/running sono lavorazione; deferred non sono finding investigati. Rejected conservati solo come conteggio non giustificano una lista filtrabile. Severity nulla = Not assessed. Il totale segue i quattro conteggi della Resource corrente.

### Finding e prove

Partire da LeadOutcome e OutcomeService. Nessun campo Impact/Preconditions obbligatorio e nessuna nuova analisi LLM per riempire sezioni. Conclusione centrale, distinta dall'ipotesi iniziale. Remediation, confidence e categoria/CWE solo se presenti.

Codice: snippet della source fissata dalla run, path relativo, righe reali, punto rilevante, Copy e syntax highlighting. Shiki è proposto nel prodotto, caricato al bisogno; il mock colora soltanto alcune keyword per illustrarlo. Nessun editor o explorer nel P0.

HTTP: metodo/path/status/attore e soli estratti acquisiti. Esplicitare troncamento e indisponibilità; mai ricostruire body. Un Confirmed originale non viene declassato perché un artifact non è più leggibile. Tool journal e credenziali non sono contenuti pubblici.

### Report

Template da risultati persistiti, leggibile dopo cleanup. Nessun executive summary LLM obbligatorio. Coverage true/false/null resta il dato dichiarato, non uno score. Budget e costo stimato/provider distinti, senza quote commerciali inventate. JSON/Markdown stessi dati; stampa browser, nessun link pubblico.

## Design system e riferimenti

Geist Sans/Mono, controlli 36px, radius 8px, max-w-6xl, superfici/bordi esistenti. Success per esito operativo positivo; confirmed neutro con verifica; severity con testo/colore. Su mobile finding impilati con severity/verdict visibili, storico in overflow locale. Focus visibile e movimento ridotto.

Riutilizzare Card, Badge, Dialog, Table e Tabs; ScanSummary è un componente composto giustificato. Aggiornare docs/design-system.md solo quando si implementano convenzioni condivise.

Riferimenti già studiati: [Vercel Projects](https://vercel.com/docs/projects) per gerarchia, [Sentry](https://docs.sentry.io/product/issues/issue-details/?promo_name=hp-banner) per codice/prove, [Trigger.dev](https://trigger.dev/docs/runs) per esecuzioni. Sono riferimenti, non requisiti da copiare integralmente.

## P1+

P1: mascotte animata, cancellazione controllata, GitHub, target/attori configurabili, retest/billing. Stop non è attivo nel wireframe aggiornato: prima servono lifecycle e cleanup cancellabile reali. Se richiesto per la release, consegnare un incremento dedicato. P2: PDF, inbox cross-project, sidebar globale, collaborazione, automazioni/trend.

## Esplorare il wireframe

La barra Prototype esterna al prodotto seleziona First scan, Preparing project, Setup failed, Queued, Running — planned live, Running — current data, Reconnecting, Completed, Partial, Failed con/senza report, No findings, No projects.

**Simulate next update** modifica fixture locali coerenti tra overview/dettaglio; nessun timer simula una vera run. Current data mostra quanto supportato dal riepilogo attuale, senza lead/budget live; planned live mostra il P0 completo da implementare.

Fixture ispirate ai DTO, non risultati reali: Blocked, severity nulla, snippet mancante, HTTP indisponibile/troncato. Gli export scaricano soltanto fixture dichiarate. Create/Upload/Start non chiamano backend. Le due card secondarie illustrano la preparazione.

Verifica browser: 88 combinazioni a 1440/390px nei due temi, senza errori JavaScript o overflow pagina. Controllati navigazione, filtri/reset, avvio simulato, aggiornamento budget/verdetti, arrivo di nuovi finding, prove mancanti/troncate, export fixture, retry, upload locale e apertura da file. Ispezionati visivamente empty state, overview live, finding e lista mobile. Nessun test applicativo, lint globale o agent loop.

Questa revisione modifica soltanto riferimento e piani nel checkout Windows. Nessuna modifica a sorgenti applicativi o ARCHITECTURE.md.
