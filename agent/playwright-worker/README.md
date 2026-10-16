# Worker BrowserCode

Il modello usa `browser_code(actor, code)`: `code` e' il corpo di una funzione
JavaScript async, con API Playwright standard. Sono disponibili `page`, `context`,
`target_url` e `actor` (i soli valori privati del profilo scelto). Non deve avviare
Chromium. `return` e' libero e facoltativo; stdout e risultato sono bounded.

```javascript
await page.goto(target_url + '/login');
await page.getByLabel('Email').fill(actor.username);
await page.getByLabel('Password').fill(actor.password);
await page.getByRole('button', {name: 'Log in'}).click();
return {url: page.url()};
```

Il browser conserva cookie e storage tra chiamate della stessa lead/actor. Le
variabili locali dello snippet non persistono. Per verificare un esito usare
locator/assertion Playwright o `inspect_browser`; attendere la condizione
significativa prima di terminare lo snippet. Eventi successivi restano visibili
nell'inspection, ma non sono retroattivamente attribuiti allo script terminato.

## Isolamento e lifecycle

Ogni sessione ha un runner Bubblewrap con namespace user/mount/PID/rete separati,
Chromium proprio e filesystem runtime read-only, senza ambiente gateway, chiavi
LLM, sorgenti, filesystem host o socket Docker. I moduli Node standard sono
utilizzabili, ma Node non dispone di rete diretta. Un socket Unix collega il
browser al proxy autorizzato, che blocca origin diversi anche dopo redirect.
Cambiare proxy o creare context dal codice non apre una via di rete esterna.
Gateway e collector osservano il browser da fuori del compartimento.

Il kernel deve supportare namespace non privilegiati; la readiness verifica questo
requisito prima dell'agente. Il profilo seccomp permette mount/umount/pivot_root nei
namespace, mantenendo tutte le capability esterne droppate salvo SYS_CHROOT e
no-new-privileges. Profilo sidecar predefinito: 2 GiB, 1024 PID/thread, /dev/shm 128 MiB.

Deadline esterna di 60 secondi per script; il client gateway concede 105 secondi
per includere avvio e cleanup. Errori di sintassi, API o serializzazione sono
risultati correggibili. Un timeout/cancellazione termina l'intera sessione,
Chromium compreso, e restituisce `state_lost`: una nuova chiamata ricrea il browser.
Non ripetere automaticamente POST o script dopo un errore: effetti parziali possono
essere gia' avvenuti. Solo ritrasmissioni dello stesso invocation id sono deduplicate.

## Evidenze e letture

L'harness conserva codice redatto, risultato, stdout/stderr, errori, durata, rete,
dialog e osservazioni con action_ref e observation_ref. Un return o console.log Node
non diventa un evento browser. JavaScript evaluate/addScriptTag, cookie e modifiche
DOM del tester sono setup, non prova automatica di XSS: serve causalita' del payload.
Gli artifact storici con step/assertion restano leggibili, senza un tool legacy attivo.

`inspect_browser` offre summary, forms, aria, visible_text, dom, events e network.
Per continuare una lettura passare next_cursor come cursor conservando actor, mode e
selector. Snapshot immutabili paginati; nessuna nuova esecuzione dello script.
Le sessioni HTTP/browser dello stesso actor sono separate; il CSRF va riletto nella
sessione corrente dopo login. Popup e service worker sono disabilitati nel context fornito.

## Test Chromium offline (PowerShell dalla root)

```powershell
docker build -t lailaps-playwright-worker:full-code-test agent/playwright-worker
$browserTests = (Resolve-Path agent/playwright-worker/tests).Path
$browserSeccomp = (Resolve-Path agent/playwright-worker/seccomp_profile.json).Path
docker run --rm --init --network none --read-only `
  --tmpfs /tmp:rw,noexec,nosuid,nodev,size=128m `
  --tmpfs /dev/shm:rw,noexec,nosuid,nodev,size=128m `
  --cap-drop ALL --cap-add SYS_CHROOT --security-opt no-new-privileges `
  --security-opt "seccomp=$browserSeccomp" --memory 2g --pids-limit 1024 `
  --mount "type=bind,source=$browserTests,target=/srv/tests,readonly" `
  lailaps-playwright-worker:full-code-test npm test
```

Le fixture HTTP restano sul loopback esterno al runner e sono raggiungibili soltanto
attraverso il proxy. Nessuna chiamata LLM o servizio esterno durante i test.
