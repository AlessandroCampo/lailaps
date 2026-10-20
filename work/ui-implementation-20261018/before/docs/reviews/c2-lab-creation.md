Aggiornamento 10 ottobre 2026: lo schema descritto in questa review è stato sostituito da projects.lab_manifest e dal Lab concreto. Le migration originali sono state aggiornate con reset dei dati di sviluppo; nessuna migrazione retroattiva. Il contratto corrente è documentato in ARCHITECTURE.md.

# Review C2: creazione Lab e prima istanza

9 ottobre 2026 — lailaps-2.

Scope: review e correzione della creazione contestuale di progetto, source, Lab
e istanza, con navigazione al passo Lab. Nessun provisioning o loop agentico.

## Errori corretti

- **P1 — creazione bloccata:** il costruttore di `CreateLabAction` lanciava
  sempre `Not implemented`, impedendo la risoluzione del controller.
- **P1 — disco inesistente:** import e deduplicazione usavano `project_sources`,
  rimosso dalla configurazione. Ora usano il disco privato `projects` e i path
  derivati già implementati. La migrazione dei vecchi snapshot usa `local`,
  la cui root privata permette di leggere sia il vecchio sia il nuovo layout.
- **P1 — istanza non creabile:** `LabIstance` era uno scaffold, senza
  configurazione del mass assignment. Aggiunti stato tipizzato e relazioni.
- **P1 — rollback filesystem incompleto:** la transazione esterna del controller
  poteva fallire dopo che `ZipService` aveva già dichiarato concluso il salvataggio.
  La creazione del Lab è ora nella transazione coordinata da `CreateProjectAction`,
  dentro il callback di `withSnapshot`. Un errore annulla tutte le righe e
  rimuove i file del nuovo snapshot.
- **P2 — revisione implicita:** l'istanza selezionava nuovamente `latestSource`,
  ignorando la source passata a `CreateLabAction`. Ora riceve quella source
  esplicitamente; richiede stesso progetto, stato ready e snapshot presente.
- **P2 — riuso improprio dell'istanza:** `firstOrCreate` riutilizzava il record
  per la stessa coppia Lab/source. Una nuova istanza ora ha una propria identità.
- **P2 — persistenza e storia incomplete:** mancavano cast del manifest e
  SoftDeletes, pur essendo previsti dalle migrazioni. Aggiunti ai model;
  `LabIstance.source()` include le source archiviate.
- **P2 — nome derivato troppo lungo:** il suffisso `-lab` poteva superare
  il limite di 255 caratteri. Il nome viene limitato preservando il suffisso.

## Comportamento risultante

- La store crea progetto/source, Lab draft con manifest nullo e istanza pending.
- Il redirect è `/projects/{project}?step=lab`.
- Le schede Source e Lab mantengono il passo nell'URL e funzionano al refresh.
- La UI mostra lo stato autorevole, presenza della ricetta, istanze e hash
  della revisione assegnata, anche quando la source è archiviata.
- I progetti preesistenti hanno un'azione Create lab, autorizzata per proprietario.
- Nessuna copia della source o directory Lab viene creata anticipatamente.
  Gli artifact useranno il percorso derivato nel disco projects alla prima scrittura.
- Aprire il passo Lab o premere Refresh non crea istanze e non avvia processi.

## Verifiche

- Applicate le due migrazioni pendenti Lab/istanze e rigenerati gli helper Wayfinder.
- 27 controlli smoke isolati su SQLite in memoria e storage temporaneo:
  creazione, stati, manifest, nomi lunghi, revisione esplicita, istanze multiple
  sulla stessa source, source archiviata, cambio upload, source estranea o
  snapshot assente, rollback con errore iniettato, pulizia dei file, proiezione
  UI, proprietà, redirect e soft delete.
- Smoke browser autenticato: creazione Lab di BookStack e creazione completa
  di un progetto da ZIP innocuo con redirect al passo Lab. Il progetto fixture
  è stato poi archiviato; BookStack conserva Lab #1 e istanza #1 pending su source #4.
- Layout verificato a 1280 e 390 px nei temi chiaro/scuro, senza overflow orizzontale.
  Verificati stato vuoto, stato popolato e refresh senza duplicazione.
- Typecheck Vue/TypeScript delle pagine modificate e dipendenze: passato.
- Lint frontend limitato a tre file e Pint limitato ai dodici PHP modificati: passati.
- Nessun test unit/feature aggiunto al repository, nessun container o chiamata LLM.

## Limiti dei controlli statici e P1 non inclusi

Il typecheck generale resta bloccato dai componenti demo preesistenti
(dipendenze mancanti e import Vue), oltre alla route generata RedirectController
che include il metodo `query`. Il PHPStan mirato segnala relazioni Eloquent senza
generici PHPDoc, inferenza dei cast e metodi scaffold del ProjectController.
I cast e le relazioni sono stati verificati anche a runtime; non sono stati
aggiunti commenti PHPDoc né modificata la configurazione globale dell'analizzatore.

Nel browser sono presenti warning di hydration del selettore tema condiviso;
il comportamento nei due temi è stato verificato. La correzione del componente
globale resta distinta da questo incremento.

## Prossimo incremento proposto

Prima una sandbox da ricetta fissa, senza Doctor: workspace nuovo dalla source
assegnata, artifact del Lab, Compose isolato, bootstrap, readiness applicativa
e teardown limitato alle risorse dell'istanza. Dopo questa verifica si aggiunge
la generazione/correzione della ricetta. Un futuro job parte dopo il commit;
il parametro step rimane soltanto navigazione e non è un trigger di esecuzione.

