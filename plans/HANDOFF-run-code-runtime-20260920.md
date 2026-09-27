# Handoff — Rendere run_code operativo per Recon e Reader

Data: 20 settembre 2026. Stato: handoff implementativo; correzione non ancora applicata.
Branch di partenza: `experiment/recon-reader-assignments`, checkpoint `29b286a`.
Il working tree contiene la nuova architettura Recon/Reader e i relativi test: conservarli.

## Obiettivo e indicazione dell'utente

L'utente vuole incoraggiare gli agenti a eseguire codice locale e, quando disponibile,
sull'ambiente autorizzato del target. La chiamata a `run_code` durante Recon e' un
comportamento desiderato. Risolvere l'integrazione e rendere il tool utilizzabile;
nasconderlo o invitare il modello a non usarlo non soddisfa la richiesta.

P0: esecuzione Python isolata funzionante nel benchmark Recon -> Reader, con gestione
recuperabile degli errori, disponibilita' anche al Reader e verifica reale del runtime
senza agent loop. Riutilizzare il runtime PTC esistente. Nessun nuovo agente, backend
generico di esecuzione o cambiamento ai budget.

## Evidenza della run fallita

Outcome e transcript sotto:
`storage/app/runs/yeswiki/recon-reader-global/yeswiki-recon-reader-global-20260920-182627/`.

- `termination_reason=recon_unavailable`, benchmark `technical_failure`, exit 1.
- 7 richieste modello e 11 tool call, tutte di `category_recon`; zero turni Reader.
- CBM pronto; Semgrep parziale, ma non causa dello stop.
- Recon ha consumato 4.006,28 EP su una allowance di 640.000: budget non esaurito.
- Il blocker e' `DockerException: Error while fetching server API version:
  ('Connection aborted.', FileNotFoundError(2, 'No such file or directory'))`.
- L'area `ready` nel report e' il fallback dell'orchestratore, non un `ReconPlan` prodotto
  dal modello. La run non misura la qualita' della nuova strategia.

Catena verificata nel codice:

1. `category_recon_tools()` espone `run_code` nella modalita' globale.
2. `runtime_tools.run_code()` chiama `PtcRuntime.run()` in un thread.
3. `ptc.py` inizializza il client con `docker.from_env()` prima del proprio `try`.
4. `BenchmarkStageProcessRunner::containerCommand()` non monta il socket Docker.
5. L'inizializzazione fallisce; l'eccezione esce dal tool, abortisce Recon e il percorso
   sperimentale si ferma correttamente senza avviare un Reader privo del piano.

Anche `validate_code()` precede il `try`: verificare che un errore di sintassi del
programma diventi un risultato correggibile dal modello, senza interrompere la fase.

## Capacita' da tenere distinte

`run_code` esegue gia' un corpo Python async in un container effimero. Puo' calcolare,
trasformare e aggregare dati e chiamare i binding statici `tools.search_source` e
`tools.read_file`. Il sorgente si recupera tramite questi binding; il container non
possiede direttamente `/workspace`. Non e' una shell dentro l'applicazione target.

L'esecuzione nel workspace e nel target ha gia' un'altra implementazione:
`DockerCommandExecutor`, `run_workspace_command`, `run_target_command` e
`run_target_probe`. Il Confirmer li riceve quando `deps.commands` e' disponibile.
Il comando `recon --global --with-reader` non inizializza un target runtime ne' questo
executor. Riparare PTC non abilita automaticamente l'esecuzione sulla VM del target.
L'obiettivo target resta valido; il suo collegamento a Recon/Reader e' il passo P1
esplicito descritto sotto, separato dalla correzione che sblocca questo benchmark.

## P0 — Soluzione minima proposta

### 1. Collegare il processo orchestratore al runtime Docker

Riutilizzare il percorso gia' adottato in `app/Console/Commands/PentestRun.php`:
il container agente riceve `/var/run/docker.sock:/var/run/docker.sock` e puo' creare
il container PTC fratello attraverso il daemon. Allineare il runner degli stage a
questo contratto; verificare il comportamento effettivo su Docker Desktop/Linux
containers e preservare la modalita' locale del runner.

Il socket appartiene al processo orchestratore fidato. Non montarlo nel container
che esegue il codice prodotto dal modello e non trasferirgli credenziali provider,
artifact, manifest privati o il filesystem host. Conservare nel figlio i limiti
esistenti: rete disabilitata, root read-only, tmpfs, memoria/processi e timeout.
Non sostituire l'isolamento con `exec` nel processo agente per aggirare Docker.

Verificare anche l'immagine: `PtcRuntime` usa oggi il default hardcoded
`lailaps-pentest-agent:dev`. Riutilizzare la configurazione immagine/toolbox gia'
esistente se il runner puo' usarne un'altra, senza introdurre un nuovo catalogo
di immagini o download impliciti. La prova deve usare l'immagine aggiornata con
questo branch: modificare il checkout non aggiorna un'immagine gia' costruita.

### 2. Restituire gli errori al modello

Portare inizializzazione del client e validazione del codice sotto la gestione
degli errori recuperabili. Usare `PtcResult` e il payload `run_code` esistenti:
`ok`, `error`, timeout, subcall e osservazioni parziali. Gestire correttamente
client/container non ancora creati e l'ownership dei client iniettati nei test.

Daemon assente, immagine mancante, errore del programma o timeout non devono da soli
diventare `recon_unavailable`. Il modello deve ricevere il motivo concreto e poter
correggere il codice o continuare con i tool diretti, conservando la history.
Non aggiungere retry LLM automatici, un nuovo protocollo di errore o catch globali
che nascondano bug estranei al runtime.

### 3. Allineare toolset e istruzioni

Recon globale e Confirmer hanno gia' `run_code`; Reader attualmente no. Aggiungerlo
al Reader sperimentale Recon/Reader, usando lo stesso accounting, prepare e limiti
degli altri tool. L'estensione ad altri percorsi Reader puo' restare successiva.

Descrivere il contratto nei prompt pertinenti, incoraggiando l'uso quando un piccolo
programma chiarisce una domanda o compone piu' letture. Nessuna quota obbligatoria
di chiamate, e nessuna penalizzazione semantica per aver tentato di eseguire codice.

Esempio valido, adattando il file al sorgente effettivamente osservato:

```python
content = await tools.read_file(path="includes/YesWiki.php", start_line=1, max_lines=40)
return content
```

Nella run fallita il modello aveva inviato:

```python
async def run(): return await tools.list_surface_signals(scope='all', page_size=30)
```

Questo e' un secondo problema, distinto dal crash Docker: definisce una funzione
senza chiamarla e cita un binding non esposto. Chiarire che il parametro e' gia'
il corpo async e che `list_surface_signals` si chiama direttamente come tool.
Non ampliare tutti i binding per accomodare questa singola chiamata errata.

## Verifiche e criteri di completamento

Leggere `AGENTS.md` prima di intervenire. Non avviare benchmark a pagamento o agent
loop; usare test mirati e uno smoke test Docker senza modelli. Non fare lint globale.

- Estendere `tests/test_ptc.py`: fallimento di `docker.from_env()` recuperato;
  sintassi invalida recuperata; timeout/errore runtime con cleanup e conservazione
  delle osservazioni gia' acquisite. Non limitarsi a mockare tutto `PtcRuntime.run`.
- Verificare la costruzione del comando Laravel: socket al parent, sorgente
  sanitizzato invariato, niente socket/credenziali nel figlio PTC.
- Verificare con modelli finti che Recon possa ricevere l'errore del tool e poi
  emettere `ReconPlan`; mantenere il test di fallimento reale di Recon che impedisce
  l'avvio Reader. Verificare disponibilita' del tool al Reader sperimentale.
- Eseguire una chiamata reale a `run_code` dal medesimo tipo di container agente
  usato dal benchmark, senza provider LLM: calcolo locale e lettura di una fixture
  attraverso il broker, risultato corretto, provenienza/accounting conservati,
  nessun container PTC residuo. Un test direttamente dall'host non riproduce il bug.
- Se Docker o l'immagine non sono disponibili, riportare lo smoke test come non
  eseguito: i soli mock non dimostrano che il difetto di integrazione sia risolto.
- Aggiornare `ARCHITECTURE.md` per accesso Docker del runner, disponibilita' Reader
  e semantica recuperabile del tool, mantenendo distinta l'esecuzione sul target.

Nel risultato finale indicare file modificati, test offline, esito dello smoke reale
e comando per rilanciare manualmente YesWiki. Non rilanciare automaticamente la run.
Il P0 e' completo quando il tool funziona nel container benchmark e gli errori
recuperabili tornano al modello: eliminare soltanto l'eccezione non e' sufficiente.

## P1 — Esecuzione nel target autorizzato

Aggiornamento: l'utente ha richiesto esplicitamente questa capacita' per entrambi i
ruoli. Il collegamento workspace e target e' ora P0 nel nuovo
[handoff dedicato](HANDOFF-recon-reader-workspace-target-execution-20260920.md).
Il testo seguente conserva la separazione originaria rispetto al fix PTC.

Collegare Recon/Reader al workspace o alla VM/container del target usando gli executor
esistenti, quando il flusso prepara un target runtime. Specificare identita' del target,
runtime/interpreti disponibili e differenza fra esperimento locale e osservazione
dell'applicazione viva. Non duplicare questi tool dentro PTC ne' introdurre un backend
SSH/VM generico nel fix del socket. Un risultato locale non e' automaticamente una
vulnerabilita' confermata via HTTP.

Questo passo richiede un'estensione esplicita del benchmark oggi statico; non deve
essere promesso come effetto collaterale della riparazione di `run_code`.
