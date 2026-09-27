# Handoff — Esecuzione nel workspace e nel target per Recon e Reader

Data: 20 settembre 2026. Stato: incarico implementativo, non ancora implementato
da questo handoff. Branch: `experiment/recon-reader-assignments`.

## Decisione dell'utente e risultato richiesto

L'utente autorizza e vuole incoraggiare Recon e Reader a eseguire, quando utile,
codice e comandi sia sul workspace sia sul target autorizzato. Un esempio concreto
e' ottenere l'inventario delle rotte con `php artisan route:list` su un target Laravel.
Non limitare l'intervento a Python PTC o al solo workspace.

Questo incarico promuove a P0 il precedente P1 di
[HANDOFF-run-code-runtime](HANDOFF-run-code-runtime-20260920.md). La riparazione PTC
ha gia' modifiche nel working tree: leggerle e conservarle, senza ricrearle.
Nel working tree vi sono anche modifiche agli executor/timeout: verificare lo stato
corrente invece di applicare assunzioni ricavate dal vecchio handoff.

Il risultato deve essere utilizzabile da entrambi i ruoli quando il relativo ambiente
e' disponibile. Il modello decide se un comando aiuta a capire il progetto o risolvere
una domanda; non serve una lead precedente e non occorre un'approvazione per ogni
comando nel perimetro dell'audit. La discovery rimane Recon -> Reader seriale, senza
avviare Confirmer/Worker/Judge e senza introdurre lo streaming Recon in questo task.

## Capacita' esistenti da riusare

| Tool | Ambiente e uso |
|---|---|
| `run_code` | Python effimero isolato, calcoli e composizione dei binding statici |
| `run_workspace_command` | Bash nel toolbox con sorgente sanitizzato montato in `/workspace`; analisi e script locali |
| `run_target_command` | Programma con argv o script Bash/SH in un servizio del target dell'audit; inventario rotte, configurazione, introspezione applicativa |
| `run_target_probe` | Script/file temporanei per un esperimento nel runtime del target, con cleanup |

Non creare un quarto executor o un nuovo tool universale che duplichi questi contratti.
Il comando applicativo `route:list` richiede working directory, dipendenze e bootstrap
reali: scegliere `run_target_command` quando il toolbox non possiede tale ambiente.
YesWiki non e' Laravel: il modello deve scegliere strumenti compatibili con il progetto,
non ricevere `artisan` come procedura universale o un catalogo obbligatorio di comandi.

Punti del codice da leggere:

- `app/Console/Commands/BenchmarkReconReader.php`: oggi materializza soltanto il sorgente.
- `app/Services/Pentest/BenchmarkStageProcessRunner.php`: lancio agente e accesso Docker.
- `app/Console/Commands/PentestRun.php`: preparazione/riuso sandbox, readiness e identita'
  runtime, propagazione `--workspace-host-root`, `--target-audit-id`, `--target-container-id`.
- `agent/pentest-agent/src/pentest_agent/cli.py`: `_build_command_executor`, `recon_only`,
  i percorsi scan/reader e costruzione di `Deps.commands`/`runtime_dossier`.
- `execution.py`: `DockerCommandExecutor`, capability, servizio target, toolbox e probe.
- `runtime_tools.py`: tool, output manager, timeout e accounting.
- `triple_agent.py`: `reader_tools`, `category_recon_tools`, prompt e filtri di contesto
  che oggi iniettano il runtime dossier soltanto nei ruoli downstream.

## P0 — Collegamento completo

1. Inizializzare e chiudere l'executor nei percorsi Recon/Reader pertinenti. Riutilizzare
   il wiring gia' presente negli altri comandi; non far dipendere il workspace dalla
   presenza di un target. Valutare capability reali prima di esporre i tool.
2. Esporre a Recon e Reader `run_workspace_command` e, quando il target e' collegato,
   `run_target_command`/`run_target_probe`. Usare lo stesso executor nei percorsi ordinari
   che gia' ricevono `Deps.commands`; non duplicare una variante per ogni agente.
   Conservare i limiti di tranche/terminalizzazione e l'accounting dei tool.
3. Rendere utilizzabile il target anche dal benchmark Recon/Reader. Il primo percorso
   minimo puo' riusare una sandbox Lailaps pronta tramite il contratto `--reuse-sandbox`
   gia' esistente, con controllo di identita', snapshot compatibile e readiness.
   Propagare al Python l'audit ID della sandbox, distinto dal nuovo run ID dei report.
   Non dedurre il target da un nome Docker arbitrario e non avviare l'intera pipeline
   `pentest:run` per ottenere la sandbox. Riutilizzare i servizi di risoluzione esistenti.
4. Passare `--workspace-host-root` del sorgente sanitizzato come path host risolvibile
   dal daemon Docker. Il `/workspace` interno all'agente non e' il path host da montare
   nel toolbox fratello. Questo collegamento va provato in modalita' containerizzata.
5. Dare ai due ruoli un contesto compatto con servizi logici autorizzati, working
   directory, capability e stato di disponibilita'. Non copiare il dossier interno
   completo o segreti nei prompt. Tool e relativi riferimenti devono poter essere
   usati anche durante Recon, quando non esistono ancora area attiva o lead.
6. Aggiornare i prompt: incoraggiare comandi e piccoli programmi quando producono
   informazione discriminante o sostituiscono molte letture manuali. Esempi: rotte,
   middleware, inventario dei comandi applicativi, trasformazioni/parser e impostazioni
   runtime mirate. Nessun obbligo di chiamare una shell per ciascun incarico o lead.
7. Conservare risultati e provenienza nel meccanismo esistente: ruolo, area quando
   presente, scope workspace/target, comando, servizio, exit code, timeout e output
   bounded recuperabile. Non classificare stdout come source reference verificata;
   non convertire automaticamente un esperimento locale in finding confermato.

## Semantica operativa

Consentire argv e shell ove disponibile, non una allowlist di soli comandi Laravel.
Se manca Bash/SH, l'esecuzione diretta dell'interprete resta utilizzabile. Consentire
script temporanei nel perimetro gia' previsto dagli executor e relativo cleanup.
I comandi di discovery non devono richiedere una lead per poter essere eseguiti.

Il sorgente del workspace rimane l'input sanitizzato; i file scratch appartengono
alle directory temporanee dell'executor. Il target e' l'ambiente di test autorizzato,
con dipendenze e stato applicativo. Le normali scritture di cache/log durante il
bootstrap di un comando non devono renderlo impraticabile per un'assunzione generica
di filesystem interamente read-only. Non includere reset, migrazioni distruttive o
modifiche persistenti ai dati come passo ordinario per elencare le rotte.

Rete, credenziali provider, socket Docker e filesystem host rimangono separati dal
codice prodotto dal modello secondo i confini degli executor. Manifest privati,
dump di seeding e ground truth non devono comparire in workspace o filesystem target
ispezionabile: riusare la preparazione benchmark che rimuove le fixture dopo il setup.

Distinguere sempre osservazione CLI e comportamento HTTP effettivo: per esempio la
configurazione PHP CLI puo' differire da quella del processo web. L'output di una route
inventory orienta la lettura ma non dimostra da solo l'accessibilita' della rotta.
Il contratto lead rimane quello corrente, con fatti osservati e unknowns espliciti.

Un comando inesistente, interprete mancante, input errato o timeout circoscritto deve
tornare come risultato recuperabile e permettere di continuare. Verificare il cleanup
del processo: la discovery non deve abbattere l'applicazione per un comando diagnostico
scaduto, ne' dichiarare terminato un processo che continua a girare. Errori reali del
target o isolamento non verificabile restano distinti. Non cambiare indiscriminatamente
la semantica degli altri tool o introdurre retry agentici automatici.

Se manca il target, workspace/PTC restano operativi e il contesto dichiara la mancanza.
Se l'utente richiede una sandbox esplicita ma questa e' invalida, fallire il collegamento
chiaramente prima delle chiamate al modello, senza simulare un'esecuzione target riuscita.

## Test e consegna

Leggere `AGENTS.md`; nessun agent loop a pagamento e nessun lint globale. Test mirati:

- Toolset e contesto di entrambi i ruoli con workspace-only e con target disponibile;
  il comando e' utilizzabile in Recon senza lead e in Reader prima dell'emissione.
- Propagazione corretta di identita' sandbox/run e path host, senza mount ground truth.
- Comandi/probe nel target corretto, tempfile rimossi e output con provenienza recuperabile.
- Errore recuperabile e timeout: la run puo' continuare e il target resta utilizzabile.
- Smoke reale senza LLM dall'agente containerizzato: uno script workspace e un comando
  di introspezione nel target. Su una fixture Laravel predisposta, usare `php artisan
  route:list`; su YesWiki usare una CLI realmente presente. Non installare un nuovo
  benchmark complesso soltanto per questo test. Se un ambiente manca, dichiarare quale
  scenario reale non e' stato verificato.
- Aggiornare `ARCHITECTURE.md` con tool per ruolo, collegamento runtime, provenance e
  limiti effettivi. Il benchmark con target non va piu' descritto come sola lettura statica.

Documentare il comando concreto per una run Recon/Reader con sandbox collegata e quello
workspace-only, senza lanciarli a pagamento. Registrare nella run se il target e' fresco
o riusato: il riuso e' utile allo sviluppo, ma non equivale a un benchmark pristine.

## P1+ non incluso

- Provisioning automatico di nuovi target nel comando Recon/Reader, se il riuso dei
  servizi esistenti basta per consegnare P0; il collegamento a un target pronto e' P0.
- Backend SSH per VM generiche non gia' gestite dagli executor del progetto.
- Streaming Recon, Reader concorrenti, nuovo protocollo packet o cambiamenti al budget.
