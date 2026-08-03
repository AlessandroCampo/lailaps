# Piano di rientro dei debiti di sicurezza del dual command

## Scopo e confine attuale

Il dual command separa correttamente due contesti:

- `run_workspace_command` analizza il sorgente in un toolbox Linux read-only, senza rete;
- `run_target_command` esegue comandi nel container runtime già selezionato da Laravel;
- `http_call` verifica i finding sul solo URL del target.

Questa separazione è utile, ma l'MVP concede al container dell'agente l'accesso completo a
`/var/run/docker.sock`. Inoltre Laravel può buildare un Dockerfile o avviare un file Compose
presente nel progetto. Queste scelte sono accettabili esclusivamente in locale, con target
fidati e un solo sviluppatore. Non costituiscono un confine di sicurezza per ZIP non fidati o
audit multi-utente.

Il principio guida per la produzione è:

> Il modello propone un'operazione; un componente fidato verifica identità, audit, target,
> capability e limiti; solo quel componente parla con il runtime di sandbox.

Un prompt, una label Docker o la validazione degli argomenti migliorano l'affidabilità, ma non
sono meccanismi di autorizzazione sufficienti.

## Rischi principali, patch e alternative

### P0 — Docker socket montato nel container agente

**Problema.** Il launcher monta il socket Docker nell'agente e il client Python usa direttamente
l'API Docker. Chi controlla quel processo può creare container privilegiati, montare directory
dell'host, leggere altri container e segreti, modificare label o arrestare workload estranei.
La verifica `sandbox.audit_id` evita errori accidentali, ma non protegge da un processo che ha già
il controllo del daemon: quel processo può cambiare o aggirare la verifica.

Il rischio è amplificato dal prompt injection. Il sorgente e le risposte HTTP sono input non
fidati per il modello; istruzioni malevole inserite in un commento o in una pagina potrebbero
spingerlo a usare i tool in modo inatteso. Il codice Python limita gli argomenti esposti al
modello, ma una compromissione dell'agente o di una dipendenza eliminerebbe tale limite.

**Soluzione raccomandata.** Spostare ogni operazione Docker dietro un broker controllato da
Laravel o, preferibilmente, un piccolo servizio sandbox separato:

1. Laravel crea l'audit e registra in database container target, workspace, capability, owner,
   scadenza e limiti.
2. Il broker è l'unico processo autorizzato a parlare con Docker/containerd.
3. L'agente riceve una lease casuale, monouso o a breve durata, non un container ID liberamente
   selezionabile.
4. L'agente chiama endpoint specifici, ad esempio `workspace-exec` e `target-exec`; non riceve
   un proxy generico dell'API Docker.
5. A ogni richiesta il broker verifica lease, audit ancora attivo, ownership, target registrato,
   capability, timeout, quota e stato della sandbox.
6. Il broker costruisce autonomamente i parametri del runtime: container, cwd, utente, env,
   rete, mount e privilegi non arrivano dal modello.
7. Il socket viene rimosso sia dal container agente sia dal toolbox.

La lease dovrebbe contenere almeno 256 bit casuali, essere memorizzata come hash, avere TTL
inferiore a quello dell'audit, essere revocata al termine e non comparire nei log o nella command
line. L'endpoint deve essere disponibile solo sulla rete privata dell'audit, con autenticazione
mTLS o workload identity quando i componenti girano su host diversi.

**Perché è necessario.** Il daemon Docker equivale, nella pratica, ad accesso root sull'host.
Non è possibile accettare codice ostile o isolare utenti diversi lasciando questa autorità nel
processo che interpreta output controllato dall'utente.

**Alternative più semplici.**

- Per una beta single-user: eseguire l'intero audit dentro una VM usa-e-getta. Il socket rimane,
  ma compromette solo la VM, che viene distrutta dopo la run.
- Usare un docker-socket-proxy che esponga soltanto alcuni endpoint. Riduce la superficie, ma non
  è sufficiente come soluzione finale: normalmente filtra verbo/path, non l'audit o il container
  specifico presente nel body della richiesta.
- Disabilitare temporaneamente `run_target_command` e mantenere solo toolbox + HTTP. È la scelta
  più semplice se l'introspezione runtime non è indispensabile nei primi test con ZIP.

### P0 — API Docker TCP non autenticata

**Problema.** La configurazione predefinita usa `http://localhost:2375`. Un daemon Docker esposto
senza TLS concede controllo completo a chiunque riesca a raggiungerlo. Un binding apparentemente
locale può diventare raggiungibile tramite configurazioni Docker Desktop, forwarding, container,
VPN o firewall permissivi.

**Soluzione raccomandata.** Non esporre l'API Docker su TCP in produzione. Il broker deve usare il
socket Unix locale, una named pipe protetta su Windows, oppure un endpoint mTLS con certificati
client e policy di rete. Il processo web Laravel non dovrebbe ereditare automaticamente questa
autorità: separare web application, queue di orchestrazione e broker sandbox.

**Perché è necessario.** Anche rimuovendo il socket dall'agente, una porta `2375` raggiungibile
ricreerebbe lo stesso problema attraverso la rete.

**Alternative più semplici.** Durante lo sviluppo Windows, vincolare l'API a loopback, bloccarla
nel firewall e non usarla su reti condivise. Oppure eseguire Laravel/worker in WSL2 e usare il
socket Unix. È mitigazione locale, non architettura production-ready.

### P0 — Dockerfile e Compose forniti dal progetto

**Problema.** Una ZIP non fidata può includere un Dockerfile con `RUN` arbitrari o un Compose che
richiede `privileged`, socket Docker, device, host network, bind mount dell'host, namespace host,
segreti o immagini malevole. L'override Compose attuale aggiunge limiti e capability, ma Compose
fa merge con il file originale: aggiungere `cap_drop` e `no-new-privileges` non elimina tutte le
opzioni pericolose. Anche la fase di build esegue codice prima che il target sia avviato.

**Soluzione raccomandata.** Separare ingestione, build e runtime:

1. estrarre la ZIP in una directory nuova verificando Zip Slip, symlink/hardlink, device file,
   dimensione compressa/estratta, numero file, profondità e nomi;
2. analizzare Dockerfile e Compose prima di usarli e rifiutare opzioni fuori policy;
3. non eseguire Compose originale direttamente: generare una specifica runtime normalizzata da
   un sottoinsieme consentito;
4. eseguire le build in worker/VM dedicati e usa-e-getta con rootless BuildKit, senza socket host,
   senza secret del servizio e con egress controllato;
5. eseguire il runtime in un nodo sandbox distinto, con user namespace/rootless runtime, profilo
   seccomp/AppArmor, nessun device, capability minime e filesystem read-only dove compatibile;
6. distruggere VM, cache scrivibili e credenziali di build dopo l'audit.

La policy Compose deve negare almeno: `privileged`, `network_mode: host`, `pid: host`, `ipc: host`,
`userns_mode: host`, `devices`, `volumes`/bind fuori dalla directory audit, socket runtime,
`cap_add` non ammessa, security option che disabilitano il confinement, porte fisse pubbliche ed
external network/volume non registrati. I path vanno risolti con `realpath` e confrontati con la
root dell'audit, inclusi symlink e path Windows.

**Perché è necessario.** Il broker dei command tool protegge solo le operazioni chieste
dall'agente. Non protegge Laravel se Laravel stesso esegue una definizione di build ostile sul
daemon principale.

**Alternative più semplici.**

- Nella prima v1 accettare solo target URL già avviati dall'utente; la ZIP viene letta, mai
  eseguita.
- Supportare inizialmente solo stack noti tramite immagini/template mantenuti da Lailaps e
  ignorare Dockerfile/Compose caricati.
- Consentire Dockerfile/Compose soltanto a repository esplicitamente fidati fino a quando il
  builder isolato non è disponibile.

### P0 — Egress, SSRF e perimetro del target

**Problema.** L'agente ha bisogno di raggiungere provider LLM e target, ma una rete generica gli
consente anche di raggiungere servizi interni, metadata cloud e Internet. `--url` può diventare
un primitive SSRF/scanning; una conferma CLI non è un controllo di autorizzazione multi-utente.
DNS rebinding, redirect, hostname che risolvono su più IP e cambi di risoluzione possono spostare
la destinazione dopo il controllo iniziale. Anche un target malevolo può usare il proprio egress
per esfiltrare dati o attaccare la rete.

**Soluzione raccomandata.** Usare reti per-audit e un egress proxy:

- l'agente può raggiungere solo API del provider, broker e target assegnato;
- toolbox workspace continua con `network=none`;
- target e database vivono su una rete interna senza accesso alla LAN;
- i target non vengono pubblicati su `0.0.0.0`; health check e HTTP passano dalla rete privata;
- per URL remoti, risolvere e fissare gli IP autorizzati, negare loopback/link-local/metadata e
  range privati salvo policy esplicita, ricontrollare ogni redirect e limitare porte/protocolli;
- registrare consenso, hostname, IP, porta e finestra temporale autorizzati.

**Perché è necessario.** Il servizio non deve trasformarsi in uno scanner della rete interna o
in un punto di uscita controllato dal codice caricato.

**Alternative più semplici.** In beta, consentire solo `localhost`/target Docker creati da
Lailaps e disabilitare URL pubblici. Oppure eseguire audit remoti da worker con rete dedicata e
senza accesso alla rete aziendale.

### P1 — Segreti e dati inviati al provider

**Problema.** Il source può contenere `.env`, chiavi private, token, dump o dati personali. Un
comando di scansione può inserirli nell'output e quindi nel prompt del provider. L'agente riceve
inoltre la chiave provider tramite env file. Read-only impedisce la modifica, non la lettura o
l'esfiltrazione. Troncamento dell'output e istruzioni nel prompt non sono redazione di segreti.

**Soluzione raccomandata.**

- escludere all'ingestione file e directory sensibili con una denylist iniziale e regole
  configurabili; rilevare chiavi, certificati e token anche nei file non esclusi;
- applicare redazione deterministica a sorgente, stdout, stderr, errori, report e log prima di
  inviarli al modello o persisterli;
- non passare l'intero `.env` all'agente: iniettare solo le variabili strettamente necessarie;
- usare secret manager/workload identity e credenziali provider corte, ruotabili, con quota e
  separazione per ambiente;
- definire retention, cifratura e regione del provider; rendere esplicito al cliente quali dati
  possono lasciare l'infrastruttura;
- evitare secret in argv, nomi container, eccezioni e output diagnostico.

**Perché è necessario.** In produzione il codice del cliente è dato sensibile. La compromissione
non richiede RCE: basta che un secret venga letto e incluso nel contesto LLM.

**Alternative più semplici.** Accettare repository già sanificati; usare un modello locale per
la fase statica; oppure permettere solo una lista di estensioni e file selezionati dal cliente.

### P1 — Arbitrary exec dentro il target

**Problema.** `argv` e `script` permettono al modello di eseguire codice con utente, filesystem,
env e connettività del target. Può modificare database e file, leggere credenziali applicative o
rendere indisponibile il servizio. `script` aumenta la superficie perché abilita pipeline e
redirection. Il timeout arresta il container, ma non annulla eventuali effetti già prodotti.

**Soluzione raccomandata.** Considerare ogni target usa-e-getta e ogni database sintetico:

- preferire `argv`; abilitare `script` solo come capability esplicita per stack che la richiedono;
- usare utente non-root dedicato e un cwd fissato dal broker;
- non accettare env, user, cwd, container ID o mount dal modello;
- applicare quote per numero comandi, durata totale, output, processi, CPU e memoria;
- creare snapshot/clone del database e vietare collegamenti a servizi production;
- su timeout, revocare la lease, terminare l'intera sandbox e ricrearla prima di proseguire;
- classificare e, dove possibile, negare comandi chiaramente distruttivi. La denylist è difesa in
  profondità, non il confine principale.

**Perché è necessario.** Un audit deve poter alterare il proprio laboratorio, mai dati reali o
workload condivisi.

**Alternative più semplici.** Disabilitare `script`, offrire tool tipizzati per operazioni comuni
(`artisan route:list`, versioni runtime, migrazioni in read-only) o rimuovere del tutto il target
exec e confermare i finding soltanto via HTTP.

### P1 — Identità audit, collisioni e isolamento concorrente

**Problema.** L'audit ID corrente è un numero casuale a sei cifre. In concorrenza può collidere e
viene usato in nomi e label. Il toolbox rimuove un container omonimo prima di ricrearlo; senza una
verifica forte dell'ownership, una collisione può interferire con un altro audit. Le label sono
utili per discovery e cleanup, ma non sono credenziali.

**Soluzione raccomandata.** Usare UUIDv7/ULID o 128+ bit casuali con vincolo univoco nel database.
Registrare `tenant_id`, `audit_id`, risorse create e stato in una tabella autorevole. Prima di
exec, stop o delete, verificare database, lease e tutte le label attese. Non rimuovere mai una
risorsa soltanto perché il nome coincide. Assegnare rete, workspace, toolbox, target e volumi a un
solo audit e impedire il riuso mentre esiste una lease attiva.

**Perché è necessario.** L'isolamento deve restare vero anche con audit simultanei, retry, crash
del worker e ID intenzionalmente manipolati.

**Alternative più semplici.** Fino all'introduzione dell'identità forte, serializzare gli audit:
un solo audit attivo per installazione e rifiuto esplicito del secondo.

### P1 — Cleanup, timeout e resource accounting

**Problema.** `finally`, label TTL e reaper sono una buona base, ma crash dell'host, kill del
worker, Compose ostile o risorse prive di label possono lasciare container, immagini, network e
volumi. `--keep` può prolungare l'esposizione. Il reaper ricostruisce parte dello stato dalle
label, che non devono essere l'unica fonte autorevole.

**Soluzione raccomandata.** Salvare in database un inventario append-only delle risorse create e
aggiornarne lo stato idempotentemente. Eseguire reaper periodico indipendente dal processo audit,
con retry e metriche. Applicare TTL anche a `--keep`, quote per tenant e limiti di spazio disco.
Il cleanup deve verificare ownership prima di cancellare e coprire container, exec, network,
volumi, immagini, build cache, directory e lease.

**Perché è necessario.** Risorse residue possono conservare codice/segreti, consumare il nodo o
diventare un ponte tra audit.

**Alternative più semplici.** Distruggere una VM per audit elimina gran parte del cleanup fine.
Per la beta locale, eseguire `sandbox:reap` frequentemente e mostrare sempre le risorse residue.

### P2 — Audit log, redazione e controllo degli abusi

**Problema.** Senza un log strutturato non è possibile ricostruire chi ha autorizzato il target,
quale comando è stato eseguito, quale policy lo ha ammesso e quali risorse sono state toccate.
Salvare output grezzo, però, può creare una seconda fuga di segreti.

**Soluzione raccomandata.** Registrare almeno tenant/user, audit, lease ID, tool, hash e versione
della policy, comando redatto, target logico, timestamp, durata, exit code, timeout, troncamento e
decisione del broker. Separare log operativo e artifact del cliente; cifrare, applicare retention
e access control. Aggiungere rate limit, budget provider e alert per violazioni ripetute.

**Perché è necessario.** Serve sia per incident response sia per dimostrare che il test è rimasto
nel perimetro autorizzato.

**Alternative più semplici.** In beta, JSON Lines locale con permessi restrittivi e redazione
centralizzata, senza salvare stdout/stderr completi.

## Architettura target suggerita

```text
utente -> Laravel web -> coda audit -> orchestratore/broker sandbox -> runtime isolato
                              |                    |
                              |                    +-> builder usa-e-getta
                              |                    +-> toolbox workspace
                              |                    +-> target + DB sintetico
                              |
                              +-> agente (nessun Docker socket)
                                      |
                                      +-> broker API privata, con lease
                                      +-> egress proxy provider
                                      +-> HTTP solo verso il target assegnato
```

Il broker espone operazioni di dominio, non Docker:

- `POST /audits/{id}/workspace-executions` con `script` e timeout bounded;
- `POST /audits/{id}/target-executions` con `argv` oppure `script` se autorizzato;
- `GET /audits/{id}/capabilities`;
- `POST /audits/{id}/terminate` solo per l'orchestratore.

Il container ID resta un dettaglio server-side. Il broker restituisce lo stesso
`CommandResult` già usato dall'agente, permettendo di sostituire
`DockerCommandExecutor` con `BrokerCommandExecutor` senza cambiare il contratto dei tool.

## Ordine di implementazione

### Fase 0 — Vincoli espliciti dell'MVP

- Mostrare un warning e rifiutare ZIP non fidati in modalità Docker diretta.
- Limitare l'uso a un solo operatore e un audit alla volta.
- Non esporre `2375` fuori loopback; aggiungere una verifica di startup fail-closed.
- Mantenere toolbox read-only/no-network, output bounded e target usa-e-getta.
- Documentare che `--url` deve puntare esclusivamente ad ambienti di test autorizzati.

### Fase 1 — Riduzione rapida del rischio

- Sostituire gli audit ID a sei cifre con UUIDv7/ULID e persistenza univoca.
- Aggiungere inventario risorse, reaper schedulato e test di collisione/cleanup.
- Vincolare le porte target a loopback o rimuovere il publishing.
- Aggiungere policy SSRF/redirect/DNS e redazione centralizzata dei secret.
- Disabilitare Compose/Dockerfile non fidati; usare target URL o template mantenuti.

### Fase 2 — Broker command

- Implementare lease e API privata.
- Spostare creazione toolbox, capability probe, exec, timeout e cleanup nel broker.
- Implementare `BrokerCommandExecutor` Python.
- Rimuovere socket e libreria Docker dall'immagine agente.
- Testare che l'agente non possa elencare, creare, exec o rimuovere risorse fuori audit.

### Fase 3 — Build ed esecuzione di ZIP non fidati

- Pipeline di estrazione sicura e scanning dei secret.
- Builder rootless/VM usa-e-getta con egress policy.
- Parser/policy Compose e specifica runtime normalizzata.
- Rete privata per-audit, database sintetico e nessun accesso ai servizi production.
- Test offensivi di escape, mount host, symlink, ZIP bomb e build exfiltration.

### Fase 4 — Multi-tenancy di produzione

- Isolamento per tenant/nodo o microVM per audit.
- Quote, scheduling, backpressure e billing guardrails.
- Audit log cifrato, retention, metrics e incident response.
- Test concorrenti e chaos test su crash di agente, broker, daemon e host.

## Test di accettazione prima degli ZIP non fidati

- L'immagine agente non contiene né monta Docker socket/named pipe e non raggiunge `2375`.
- Una lease non può operare su un altro audit, tenant, container o workspace.
- ID scaduto, revocato, duplicato o alterato fallisce prima di qualsiasi exec.
- Il modello non può selezionare container, cwd, user, env, mount, rete o privilegi.
- Un Compose con `privileged`, host namespace, device o bind host viene rifiutato.
- Un Dockerfile malevolo non vede filesystem, socket, secret o rete interna dell'host.
- ZIP Slip, symlink escape, hardlink, device file e archive bomb vengono rifiutati.
- Toolbox non scrive nel workspace, non ha rete e viene ricreato dopo timeout.
- Target exec in timeout revoca la lease e distrugge la sandbox.
- Target e agent non raggiungono metadata cloud, LAN o target di altri audit.
- Output e log redigono chiavi provider, token, cookie, password e private key.
- Il reaper elimina tutte le risorse dopo crash e non elimina risorse di altri audit.
- Due audit concorrenti non condividono rete, volumi, processi, cache o credenziali.

## Criterio di uscita dall'MVP

Il sistema può iniziare ad accettare ZIP non fidati soltanto quando sono veri almeno questi
quattro invarianti:

1. l'agente non possiede alcuna autorità generica sul runtime;
2. build e runtime del progetto avvengono fuori dal nodo/daemon applicativo fidato o dentro una
   VM sacrificabile;
3. egress e destinazione HTTP sono vincolati al perimetro autorizzato;
4. secret, risorse e identità sono isolati per audit e ripuliti in modo verificabile.

Fino ad allora, la modalità corrente deve restare marcata **solo MVP locale / target fidati**.
